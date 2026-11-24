<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Services\ReviewReplyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/PeepSoActivitiesTable.php';

/**
 * F-531: the review-reply writes answer ONE not-found for every refusal about
 * the review or the reply, and rate-limit BEFORE anything is looked up.
 *
 * The REAL ReviewReplyService, VoteRepository, ReviewReplyRepository and
 * bcc-core Throttle (transients) and suspension check (the real
 * TrustReadService), over real review rows. Rationale: F-531 (private
 * tracker).
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(ReviewReplyService::class)]
final class ReviewReplyRefusalIntegrationTest extends TestCase
{
    private const OWNER    = 953301;   // owns the self-page the review is of
    private const REVIEWER = 953302;
    private const STRANGER = 953303;

    private const NOT_FOUND = ['error' => 'bcc_not_found', 'message' => 'Not found.'];

    /** @var list<int> */
    private array $voteIds = [];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-groups-integration-stubs.php';
        $GLOBALS['__bcc_pg'] = ['viewer' => 0, 'users' => [], 'meta' => []];

        // As in EndorsementsGivenReadFailureIntegrationTest: the REAL
        // TrustReadService answers bcc-core's suspension check, or it fails
        // closed and every caller here would read as suspended (403).
        $locatorCache = new \ReflectionProperty(\BCC\Core\ServiceLocator::class, 'cache');
        $resolved     = (array) $locatorCache->getValue();
        $resolved['bcc.resolve.trust_read_service'] = \BCC\Trust\Core\Plugin::instance()->trustReadService();
        $locatorCache->setValue(null, $resolved);

        global $wpdb;
        $wpdb->clearFaultInjection();
        $GLOBALS['__bcc_test_object_cache'] = [];
        $this->clearThrottle();

        // The reply read joins PeepSo's activities and reads wp_posts'
        // post_author / post_date_gmt, as production has them (the same
        // set-up as ReviewReplyRepositoryIntegrationTest).
        if (PeepSoActivitiesTable::ensure()) {
            $this->activitiesWasOurs = true;
        }
        foreach ([
            'post_author'   => "BIGINT UNSIGNED NOT NULL DEFAULT 0",
            'post_date_gmt' => "DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00'",
        ] as $column => $definition) {
            if ($wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$wpdb->posts}` LIKE %s", $column)) === null) {
                self::assertNotFalse($wpdb->query("ALTER TABLE `{$wpdb->posts}` ADD COLUMN `{$column}` {$definition}"), $wpdb->last_error);
                $this->addedPostColumns[] = $column;
            }
        }
    }

    private bool $activitiesWasOurs = false;

    /** @var list<string> */
    private array $addedPostColumns = [];

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->voteIds as $id) {
            $wpdb->delete($wpdb->prefix . 'bcc_trust_votes', ['id' => $id]);
        }
        $this->clearThrottle();
        if ($this->activitiesWasOurs) {
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}peepso_activities`");
        }
        foreach ($this->addedPostColumns as $column) {
            $wpdb->query("ALTER TABLE `{$wpdb->posts}` DROP COLUMN `{$column}`");
        }
        unset($GLOBALS['__bcc_pg']);
    }

    public function testEveryRefusalToReplyIsTheSameAnswer(): void
    {
        $review  = $this->review(1);
        $removed = $this->review(0, self::STRANGER + 10);
        $missing = $this->missingVoteId();

        $answers = [
            'a missing review'            => $this->service()->create(self::STRANGER, $missing, 'x'),
            'a stranger on a real review' => $this->service()->create(self::STRANGER, $review, 'x'),
            'the reviewer on their own'   => $this->service()->create(self::REVIEWER, $review, 'x'),
            'a removed review'            => $this->service()->create(self::OWNER, $removed, 'x'),
        ];

        foreach ($answers as $case => $answer) {
            self::assertSame(self::NOT_FOUND, $answer, $case);
        }
    }

    public function testEveryRefusalToEditIsTheSameAnswer(): void
    {
        $review = $this->review(1);

        self::assertSame(self::NOT_FOUND, $this->service()->update(self::STRANGER, $this->missingVoteId(), 'x'), 'a missing review');
        self::assertSame(self::NOT_FOUND, $this->service()->update(self::STRANGER, $review, 'x'), 'no reply of theirs on a real review');
    }

    /**
     * Refused attempts count toward the limit: the throttle runs before the
     * review is read, so ten probes use the window up and the eleventh is
     * refused for the rate, not for the review.
     */
    public function testRefusedAttemptsCountTowardTheRateLimit(): void
    {
        $review = $this->review(1);
        $limit  = (int) BCC_TRUST_RATE_LIMIT_REVIEW_REPLY;

        for ($i = 0; $i < $limit; $i++) {
            self::assertSame(self::NOT_FOUND, $this->service()->create(self::STRANGER, $review, 'probe ' . $i), "attempt {$i}");
        }

        self::assertSame('bcc_rate_limited', $this->service()->create(self::STRANGER, $review, 'one more')['error'] ?? null);
        self::assertSame('bcc_rate_limited', $this->service()->update(self::STRANGER, $this->missingVoteId(), 'x')['error'] ?? null, 'edits share the window');
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function service(): ReviewReplyService
    {
        return \BCC\Trust\Core\Plugin::instance()->reviewReplyService();
    }

    /** A review of OWNER's self-page, by REVIEWER unless another voter is given. */
    private function review(int $status, int $voter = self::REVIEWER): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_votes', [
            'voter_user_id' => $voter,
            'page_id'       => MemberSelfPageService::selfPageId(self::OWNER),
            'vote_type'     => 1,
            'weight'        => 1.0,
            'vested_weight' => 1.0,
            'explanation'   => 'F-531 review',
            'status'        => $status,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
        $id              = (int) $wpdb->insert_id;
        $this->voteIds[] = $id;
        return $id;
    }

    private function missingVoteId(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COALESCE(MAX(id), 0) + 100000 FROM `{$wpdb->prefix}bcc_trust_votes`");
    }

    private function clearThrottle(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM `{$wpdb->options}` WHERE option_name LIKE '\\_transient\\_%review\\_reply%' OR option_name LIKE '\\_transient\\_timeout\\_%review\\_reply%' OR option_name LIKE '\\_transient\\_bcc\\_rl\\_%' OR option_name LIKE '\\_transient\\_timeout\\_bcc\\_rl\\_%'");
    }
}
