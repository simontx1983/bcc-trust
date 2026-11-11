<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Services\TrustScoreService;
use BCC\Trust\Core\Services\VoteService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-81(a) + F-110: recalculation of a page whose votes are all gone.
 *
 * ## F-81(a): the phantom score
 * `VoteService::recalculateScore()` had a zero-active-votes fast path that
 * returned `getByPageId() ?? createDefault()` WITHOUT rewriting the row, then
 * cleared `recalculate_required`. A page whose last votes were soft-deleted
 * (a ring invalidation, an admin clear, a user deletion) therefore kept the
 * score and tier its removed votes had produced, forever, and the flag that
 * would have made the cron fix it was cleared.
 *
 * The fix only returns early when NO score row exists. With a row present
 * the normal recompute runs on zero aggregates, so the vote terms go to zero
 * while the bonus columns are carried through by the same code every other
 * recalc uses.
 *
 * The fixture is shaped so the old behaviour CANNOT pass: the seeded vote
 * terms, total and tier are all values a zero-vote recompute would never
 * write, and the expected total/tier are computed from the canonical
 * TrustScoreService formula, not hard-coded.
 *
 * ## F-110: swallowed recalculation failures
 * `recalculateScoreOrRequeue()` must survive a \Throwable that is NOT an
 * \Exception (a TypeError used to escape the `bcc.trust.recalculate_score`
 * handler), log it at ERROR, and re-flag the page for the cron.
 *
 * Uses a member self-page id so owner resolution needs no wp_posts/PeepSo
 * tables (owner = page_id - ID_BASE, zero DB).
 */
#[Group('integration')]
#[CoversClass(VoteService::class)]
final class ZeroVoteRecalculationIntegrationTest extends TestCase
{
    private const OWNER_ID = 9501;

    private int $pageId;

    protected function setUp(): void
    {
        $this->pageId = MemberSelfPageService::ID_BASE + self::OWNER_ID;

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_votes`');
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_page_scores`');
        $wpdb->query('TRUNCATE TABLE `wp_users`');
        $GLOBALS['__bcc_test_object_cache'] = [];
        Logger::reset();
    }

    private function service(?VoteRepository $voteRepo = null): VoteService
    {
        $ref = new \ReflectionClass(VoteService::class);
        /** @var VoteService $svc */
        $svc = $ref->newInstanceWithoutConstructor();

        foreach ([
            'voteRepo'        => $voteRepo ?? new VoteRepository(),
            'scoreRepo'       => new ScoreRepository(),
            'attestationRepo' => new AttestationRepository(),
        ] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($svc, $value);
        }

        return $svc;
    }

    /** The tier the removed votes legitimately earned (the phantom). */
    private static function phantomTier(): string
    {
        $total  = TrustScoreService::compute(40.0, 2.0, 0.0, 1.25, -0.50, 2.75);
        $native = TrustScoreService::computeNative(40.0, 2.0, 1.25, -0.50, 2.75);
        return TrustScoreService::tierFor($total, $native, false);
    }

    /** @param array<string, mixed> $overrides */
    private function seedScoreRow(array $overrides = []): void
    {
        // The seeded total must satisfy the formula for the SEEDED vote terms
        // (PageScore validates it on hydration) — i.e. it is the score those
        // removed votes legitimately produced, which is exactly the phantom.
        $row = array_merge([
            'page_id'              => $this->pageId,
            'category_id'          => 0,
            'page_owner_id'        => self::OWNER_ID,
            'total_score'          => round(TrustScoreService::compute(40.0, 2.0, 0.0, 1.25, -0.50, 2.75), 2),
            'positive_score'       => 40.00,
            'negative_score'       => 2.00,
            'onchain_bonus'        => 0.00,
            'contribution_bonus'   => 1.25,
            'penalty_adjustment'   => -0.50,
            'attestation_bonus'    => 2.75,
            'vote_count'           => 9,
            'unique_voters'        => 7,
            'confidence_score'     => 0.80,
            'reputation_tier'      => self::phantomTier(),
            'endorsement_count'    => 0,
            'recalculate_required' => 1,
            'recalc_failures'      => 0,
            'elite_eligible'       => 0,
        ], $overrides);

        $ok = $GLOBALS['wpdb']->insert($GLOBALS['wpdb']->prefix . 'bcc_trust_page_scores', $row);
        self::assertNotFalse($ok, 'score fixture insert failed: ' . $GLOBALS['wpdb']->last_error);
    }

    /** A vote that USED to count on the page, now soft-deleted. */
    private function seedRemovedVote(int $voterId, int $status): void
    {
        $ok = $GLOBALS['wpdb']->insert($GLOBALS['wpdb']->prefix . 'bcc_trust_votes', [
            'voter_user_id' => $voterId,
            'page_id'       => $this->pageId,
            'vote_type'     => 1,
            'weight'        => 4.0,
            'vested_weight' => 4.0,
            'status'        => $status,
            'created_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($ok, 'vote fixture insert failed: ' . $GLOBALS['wpdb']->last_error);
    }

    /** @return array<string, mixed> */
    private function scoreRow(): array
    {
        $row = $GLOBALS['wpdb']->get_row($GLOBALS['wpdb']->prepare(
            'SELECT * FROM `' . $GLOBALS['wpdb']->prefix . 'bcc_trust_page_scores`
              WHERE page_id = %d AND category_id = 0',
            $this->pageId
        ));
        self::assertIsObject($row, 'score row must exist');
        return (array) $row;
    }

    public function testAPageWhoseLastVotesWereRemovedLosesItsVoteTermsAndKeepsItsBonuses(): void
    {
        $this->seedScoreRow();
        $this->seedRemovedVote(11, 0); // retracted / admin-cleared
        $this->seedRemovedVote(12, 2); // dispute-removed

        $this->service()->recalculateScore($this->pageId, true);

        $row = $this->scoreRow();

        self::assertSame(0.0, (float) $row['positive_score'], 'removed votes must stop counting');
        self::assertSame(0.0, (float) $row['negative_score']);
        self::assertSame(0, (int) $row['vote_count']);
        self::assertSame(0, (int) $row['unique_voters']);
        self::assertSame(0.0, (float) $row['confidence_score']);

        // Bonus columns are not vote-derived and must survive untouched.
        self::assertSame(1.25, (float) $row['contribution_bonus']);
        self::assertSame(-0.50, (float) $row['penalty_adjustment']);
        self::assertSame(2.75, (float) $row['attestation_bonus']);
        self::assertSame(0.0, (float) $row['onchain_bonus']);

        $expectedTotal  = TrustScoreService::compute(0.0, 0.0, 0.0, 1.25, -0.50, 2.75);
        $expectedNative = TrustScoreService::computeNative(0.0, 0.0, 1.25, -0.50, 2.75);
        $expectedTier   = TrustScoreService::tierFor($expectedTotal, $expectedNative, false);

        $phantomTotal = round(TrustScoreService::compute(40.0, 2.0, 0.0, 1.25, -0.50, 2.75), 2);
        self::assertGreaterThan(1.0, abs($phantomTotal - $expectedTotal), 'precondition: the seeded total is not the zero-vote total');
        self::assertEqualsWithDelta($expectedTotal, (float) $row['total_score'], 0.01, 'total = neutral + bonuses only');
        self::assertNotSame(self::phantomTier(), $expectedTier, 'precondition: the phantom tier is not the zero-vote tier');
        self::assertSame($expectedTier, $row['reputation_tier'], 'tier must be re-derived, not left as the phantom');

        self::assertSame(0, (int) $row['recalculate_required'], 'the cron flag is cleared once the row is truly recomputed');
    }

    public function testAPageWithNoScoreRowStillGetsTheDefaultRow(): void
    {
        $this->service()->recalculateScore($this->pageId, true);

        $row = $this->scoreRow();
        self::assertSame(0, (int) $row['vote_count']);
        self::assertSame(0.0, (float) $row['positive_score']);
        self::assertEqualsWithDelta((float) BCC_TRUST_NEUTRAL_SCORE, (float) $row['total_score'], 0.01);
    }

    public function testRemainingActiveVotesStillCountAfterOthersAreRemoved(): void
    {
        $this->seedScoreRow();
        $this->seedRemovedVote(11, 0);
        $this->seedRemovedVote(12, 1); // still active

        $this->service()->recalculateScore($this->pageId, true);

        $row = $this->scoreRow();
        self::assertSame(1, (int) $row['vote_count'], 'only the active vote counts');
        self::assertGreaterThan(0.0, (float) $row['positive_score']);
    }

    public function testANonExceptionThrowableIsLoggedAtErrorAndThePageIsRequeued(): void
    {
        $this->seedScoreRow(['recalculate_required' => 0]);

        $exploding = new class extends VoteRepository {
            public function getVoteAggregatesForPage(int $pageId): object
            {
                throw new \TypeError('simulated non-Exception failure');
            }
        };

        $ok = $this->service($exploding)->recalculateScoreOrRequeue($this->pageId);

        self::assertFalse($ok, 'a failed recalc must report failure, not success');

        $errors = array_values(array_filter(
            Logger::$lines,
            static fn (array $l): bool => $l['level'] === 'error'
                && $l['message'] === '[bcc-trust] Score recalculation failed'
        ));
        self::assertCount(1, $errors, 'the failure must be logged at ERROR');
        self::assertSame(\TypeError::class, $errors[0]['context']['type']);
        self::assertSame($this->pageId, $errors[0]['context']['page_id']);

        self::assertSame(1, (int) $this->scoreRow()['recalculate_required'], 'the page must be handed back to the cron');
    }

    public function testASuccessfulRecalcReportsTrue(): void
    {
        $this->seedScoreRow();

        self::assertTrue($this->service()->recalculateScoreOrRequeue($this->pageId));
        self::assertSame([], array_values(array_filter(
            Logger::$lines,
            static fn (array $l): bool => $l['level'] === 'error'
        )));
    }
}
