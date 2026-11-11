<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Services\Vote\ReviewVisibilityScoreSync;
use BCC\Trust\Core\Services\VoteService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * User decision: a moderation-hidden review stops counting toward the
 * reviewed page's trust score and vote tallies while hidden, and counts
 * again once restored.
 *
 * Pins both halves against real MySQL:
 *  - COUNTING — getVoteAggregatesForPage (score, vote_count, unique voters),
 *    getVoteCountsByType, getPageStats and countActiveForPage exclude a
 *    review whose feed activity is hidden (F-55's hiddenReviewExclusion());
 *  - TRIGGERING — HiddenActivityRepository fires
 *    `bcc_trust_activity_visibility_changed` on every REAL hide / unhide
 *    (never on an idempotent re-hide), and ReviewVisibilityScoreSync
 *    recalculates the review's page on it; non-review activities are
 *    ignored.
 *
 * Fixtures mirror HiddenReviewListsIntegrationTest (F-55): a review is a
 * votes row + its feed activity + the activity's backing wp_post meta. The
 * reviewed page is a member self-page so owner resolution needs no fixture.
 *
 * ── Why separate processes
 * moderation-integration-stubs.php declares a recording do_action() and the
 * hidden-activities table; isolation keeps both out of sibling suites.
 */
#[Group('integration')]
#[Group('mariadb')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(ReviewVisibilityScoreSync::class)]
#[CoversClass(VoteRepository::class)]
final class HiddenReviewScoreIntegrationTest extends TestCase
{
    private const OWNER     = 986100;
    private const VOTER_A   = 986001;
    private const VOTER_B   = 986002;
    private const ACT_BASE  = 2600000500;
    private const POST_BASE = 2600000600;

    private int $page;
    private bool $activitiesWasOurs = false;

    /** @var array<int, int> vote id => act id */
    private array $actByVote = [];

    /** @var list<int> */
    private array $voteIds = [];

    private VoteRepository $votes;
    private HiddenActivityRepository $hidden;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/moderation-integration-stubs.php';
        $GLOBALS['__bcc_test_actions']      = [];
        $GLOBALS['__bcc_test_object_cache'] = [];

        global $wpdb;
        $acts = $wpdb->prefix . 'peepso_activities';
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $acts)) !== $acts) {
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE IF NOT EXISTS `{$acts}` (
                    act_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    act_owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    act_module_id VARCHAR(20) NOT NULL DEFAULT '0',
                    act_external_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                    act_access INT NOT NULL DEFAULT 10,
                    PRIMARY KEY (act_id)
                ) DEFAULT CHARSET=utf8mb4"
            ), $wpdb->last_error);
            $this->activitiesWasOurs = true;
        }

        $this->page   = MemberSelfPageService::ID_BASE + self::OWNER;
        $this->votes  = new VoteRepository();
        $this->hidden = new HiddenActivityRepository();

        $wpdb->delete($wpdb->prefix . 'bcc_trust_page_scores', ['page_id' => $this->page]);
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_page_scores', [
            'page_id'       => $this->page,
            'category_id'   => 0,
            'page_owner_id' => self::OWNER,
        ]), $wpdb->last_error);

        $this->review(self::VOTER_A, 0);
        $this->review(self::VOTER_B, 1);
    }

    protected function tearDown(): void
    {
        global $wpdb;
        foreach ($this->voteIds as $voteId) {
            $wpdb->delete($wpdb->prefix . 'bcc_trust_votes', ['id' => $voteId]);
        }
        for ($i = 0; $i < 4; $i++) {
            $wpdb->delete($wpdb->prefix . 'bcc_hidden_activities', ['act_id' => self::ACT_BASE + $i]);
            $post = self::POST_BASE + $i;
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE post_id = %d", $post));
            $wpdb->delete($wpdb->posts, ['ID' => $post]);
            if (!$this->activitiesWasOurs) {
                $wpdb->delete($wpdb->prefix . 'peepso_activities', ['act_id' => self::ACT_BASE + $i]);
            }
        }
        $wpdb->delete($wpdb->prefix . 'bcc_trust_page_scores', ['page_id' => $this->page]);
        if ($this->activitiesWasOurs) {
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}peepso_activities`");
        }
    }

    private function voteService(): VoteService
    {
        $ref = new \ReflectionClass(VoteService::class);
        $svc = $ref->newInstanceWithoutConstructor();
        foreach ([
            'voteRepo'        => $this->votes,
            'scoreRepo'       => new ScoreRepository(),
            'attestationRepo' => new AttestationRepository(),
        ] as $prop => $value) {
            $p = $ref->getProperty($prop);
            $p->setAccessible(true);
            $p->setValue($svc, $value);
        }
        return $svc;
    }

    private function sync(): ReviewVisibilityScoreSync
    {
        return new ReviewVisibilityScoreSync($this->votes, $this->voteService());
    }

    /** @return array{vote_count: int, positive: float} */
    private function score(): array
    {
        $row = $GLOBALS['wpdb']->get_row($GLOBALS['wpdb']->prepare(
            'SELECT vote_count, positive_score FROM `' . $GLOBALS['wpdb']->prefix . 'bcc_trust_page_scores`
              WHERE page_id = %d AND category_id = 0',
            $this->page
        ));
        self::assertIsObject($row);
        return ['vote_count' => (int) $row->vote_count, 'positive' => (float) $row->positive_score];
    }

    /** @return list<string> */
    private function firedHooks(): array
    {
        return array_map(static fn (array $a): string => (string) $a[0], $GLOBALS['__bcc_test_actions'] ?? []);
    }

    public function testAHiddenReviewStopsCountingAndCountsAgainWhenRestored(): void
    {
        $this->voteService()->recalculateScore($this->page);
        $full = $this->score();
        self::assertSame(2, $full['vote_count'], 'precondition: both reviews count');

        $hiddenVote = $this->voteIds[1];
        $act        = $this->actByVote[$hiddenVote];

        self::assertTrue($this->hidden->add($act, 0, 'autohide_threshold'));
        self::assertTrue($this->sync()->onVisibilityChanged($act), 'a review activity triggers a recalc');

        $hiddenScore = $this->score();
        self::assertSame(1, $hiddenScore['vote_count'], 'the hidden review no longer counts');
        self::assertLessThan($full['positive'], $hiddenScore['positive'], 'its weight left the score');
        self::assertGreaterThan(0.0, $hiddenScore['positive'], 'control: the visible review still counts');

        // The tallies agree with the score.
        self::assertSame(1, $this->votes->getVoteCountsByType($this->page)['upvotes']);
        self::assertSame(1, $this->votes->countActiveForPage($this->page));
        self::assertSame(1, (int) $this->votes->getPageStats($this->page)->total_votes);

        self::assertTrue($this->hidden->remove($act));
        self::assertTrue($this->sync()->onVisibilityChanged($act));

        $restored = $this->score();
        self::assertSame(2, $restored['vote_count'], 'restored: it counts again');
        self::assertEqualsWithDelta($full['positive'], $restored['positive'], 0.01);
        self::assertSame(2, $this->votes->getVoteCountsByType($this->page)['upvotes']);
    }

    public function testTheVisibilityHookFiresOnlyOnRealChanges(): void
    {
        $act = $this->actByVote[$this->voteIds[0]];

        $this->hidden->add($act, 0, 'admin_action');
        $this->hidden->add($act, 0, 'admin_action');   // idempotent re-hide
        $this->hidden->remove($act);
        $this->hidden->remove($act);                   // nothing left to remove

        self::assertSame(
            ['bcc_trust_activity_visibility_changed', 'bcc_trust_activity_visibility_changed'],
            array_values(array_filter($this->firedHooks(), static fn (string $h): bool => $h === 'bcc_trust_activity_visibility_changed')),
            'one signal per real hide and per real unhide'
        );
    }

    public function testANonReviewActivityIsIgnored(): void
    {
        global $wpdb;
        $post = self::POST_BASE + 3;
        $act  = self::ACT_BASE + 3;
        self::assertNotFalse($wpdb->insert($wpdb->posts, ['ID' => $post, 'post_type' => 'peepso-post', 'post_status' => 'publish']));
        self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $post, 'meta_key' => '_bcc_activity_module', 'meta_value' => 'watch_batch']));
        self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $post, 'meta_key' => '_bcc_activity_sidecar_id', 'meta_value' => (string) $this->voteIds[0]]));
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_activities', ['act_id' => $act, 'act_module_id' => '200', 'act_external_id' => $post]));

        self::assertNull($this->votes->findReviewPageIdByActivity($act));
        self::assertFalse($this->sync()->onVisibilityChanged($act), 'not a review: no recalc');
    }

    private function review(int $voter, int $i): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'bcc_trust_votes', [
            'voter_user_id' => $voter,
            'page_id'       => $this->page,
            'vote_type'     => 1,
            'weight'        => 2.0,
            'vested_weight' => 2.0,
            'explanation'   => "review {$i}",
            'status'        => 1,
            'created_at'    => gmdate('Y-m-d H:i:s', time() - 60 * $i),
        ]), $wpdb->last_error);
        $voteId          = (int) $wpdb->insert_id;
        $this->voteIds[] = $voteId;

        $post = self::POST_BASE + $i;
        $act  = self::ACT_BASE + $i;
        foreach ([[$wpdb->posts, 'ID', $post], [$wpdb->prefix . 'peepso_activities', 'act_id', $act]] as [$t, $c, $id]) {
            if ((int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$t} WHERE {$c} = %d", $id)) > 0) {
                self::markTestSkipped("{$t}.{$c}={$id} already exists; refusing to overwrite a real row");
            }
        }
        self::assertNotFalse($wpdb->insert($wpdb->posts, ['ID' => $post, 'post_type' => 'peepso-post', 'post_status' => 'publish']));
        self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $post, 'meta_key' => '_bcc_activity_module', 'meta_value' => 'review']));
        self::assertNotFalse($wpdb->insert($wpdb->postmeta, ['post_id' => $post, 'meta_key' => '_bcc_activity_sidecar_id', 'meta_value' => (string) $voteId]));
        self::assertNotFalse($wpdb->insert($wpdb->prefix . 'peepso_activities', [
            'act_id' => $act, 'act_module_id' => '202', 'act_external_id' => $post, 'act_owner_id' => $voter,
        ]));
        $this->actByVote[$voteId] = $act;
    }
}
