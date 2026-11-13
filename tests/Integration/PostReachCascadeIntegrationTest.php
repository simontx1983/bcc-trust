<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\PostReachRepository;
use BCC\Trust\Core\Services\UserLifecycleService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-324 — the reach cascade, and the id discipline around it.
 *
 * The decision under test: analytics are purged on PERMANENT deletion only.
 * `PostsService::deletePost()` calls `wp_trash_post()`, which is
 * recoverable, so trashing a post must KEEP its counters and restoring it
 * must bring them back — cascading on trash would make a reversible action
 * irreversibly destroy the numbers.
 *
 * ⚠ The sharpest case here is `testAWpPostIdPassedAsAnActIdDeletesNothing`.
 * Four of this feature's five reads key on `act_id`; the cascade's only
 * input is a `wp_posts.ID`. A mix-up does not error — it silently matches
 * no rows — so it is pinned by a test rather than trusted to review.
 */
#[Group('integration')]
#[CoversClass(PostReachRepository::class)]
final class PostReachCascadeIntegrationTest extends TestCase
{
    use FeedActivityFixture;

    private const ACT_ID   = 970101;
    private const WP_POST  = 970900;
    private const AUTHOR   = 970500;
    private const DAY      = '2026-11-12';

    public static function setUpBeforeClass(): void
    {
        $base = dirname(__DIR__, 2) . '/includes/database/';
        require_once $base . 'schema-post-reach-daily.php';
        require_once $base . 'schema-post-view-dedupe.php';
        bcc_trust_create_post_reach_daily_table();
        bcc_trust_create_post_view_dedupe_table();
    }

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_post_reach_daily`');
        \BCC\Trust\Core\Database\TableRegistry::flushExistenceCache();

        $this->seedFeedActivity(self::ACT_ID, self::WP_POST, '1', self::AUTHOR);
    }

    protected function tearDown(): void
    {
        $this->removeFeedActivities();
    }

    private function rowCount(int $actId): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bcc_trust_post_reach_daily';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE post_id = %d",
            $actId
        ));
    }

    private function seedReach(int $actId): void
    {
        PostReachRepository::incrementView($actId, self::DAY, 'feed', true);
        PostReachRepository::incrementView($actId, self::DAY, 'tag', false);
    }

    /**
     * The real service, with its two constructor dependencies.
     *
     * ⚠ Neither dependency is used by `onActivityPostDelete()` — it only
     * calls a static repository method — but the handler is deliberately
     * exercised through the real object rather than bypassed, because what
     * this test is for is that the HOOK's entry point passes the wp_post id
     * through correctly. Both are cheap: ScoreRepository takes no arguments,
     * and UserSyncService needs only a UserInfoRepository.
     */
    private function lifecycleService(): UserLifecycleService
    {
        return new UserLifecycleService(
            new \BCC\Trust\Core\Services\UserSyncService(
                new \BCC\Trust\Core\Repositories\UserInfoRepository()
            ),
            new \BCC\Trust\Core\Repositories\ScoreRepository()
        );
    }

    public function testPermanentDeletionRemovesTheReachRows(): void
    {
        $this->seedReach(self::ACT_ID);
        self::assertSame(2, $this->rowCount(self::ACT_ID), 'fixture seeded');

        // What before_delete_post hands the handler is the wp_post id.
        $this->lifecycleService()->onActivityPostDelete(self::WP_POST);

        self::assertSame(0, $this->rowCount(self::ACT_ID), 'permanent deletion purges');
    }

    /**
     * ⭐ The id-mix-up guard. `deleteForPost()` takes an act_id; handing it a
     * wp_post id matches nothing and reports success, which is why the
     * cascade calls `deleteForWpPost()` instead.
     */
    public function testAWpPostIdPassedAsAnActIdDeletesNothing(): void
    {
        $this->seedReach(self::ACT_ID);

        $wrong = PostReachRepository::deleteForPost(self::WP_POST);

        self::assertSame(0, $wrong, 'a wp_post id matches no reach row — no error, just nothing');
        self::assertSame(
            2,
            $this->rowCount(self::ACT_ID),
            'and the real rows survive, which is exactly how this bug would hide'
        );

        // The correctly-named entry point does the translation and works.
        self::assertSame(2, PostReachRepository::deleteForWpPost(self::WP_POST));
        self::assertSame(0, $this->rowCount(self::ACT_ID));
    }

    public function testTrashingAPostKeepsItsCountsAndRestoringLeavesThemIntact(): void
    {
        global $wpdb;
        $this->seedReach(self::ACT_ID);

        // Trash is a post_status change. Nothing in this feature watches
        // status, which is the point: the counters must survive it.
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_status = 'trash' WHERE ID = %d",
            self::WP_POST
        ));
        self::assertSame(2, $this->rowCount(self::ACT_ID), 'trash keeps the counts');

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} SET post_status = 'publish' WHERE ID = %d",
            self::WP_POST
        ));
        self::assertSame(2, $this->rowCount(self::ACT_ID), 'restore leaves them intact');
    }

    public function testDeletingAnUnrelatedPostLeavesTheseRowsAlone(): void
    {
        $this->seedReach(self::ACT_ID);

        // A wp_post id with no activity behind it: a real zero, not a failure.
        self::assertSame(0, PostReachRepository::deleteForWpPost(self::WP_POST + 12345));
        self::assertSame(2, $this->rowCount(self::ACT_ID));
    }

    public function testTheAuthorPurgeRemovesOnlyThatAuthorsRows(): void
    {
        $otherAct  = self::ACT_ID + 1;
        $otherPost = self::WP_POST + 1;
        $this->seedFeedActivity($otherAct, $otherPost, '1', self::AUTHOR + 7);

        $this->seedReach(self::ACT_ID);
        $this->seedReach($otherAct);

        $purged = PostReachRepository::deleteForAuthor(self::AUTHOR);

        self::assertSame(2, $purged, 'only the target author\'s rows');
        self::assertSame(0, $this->rowCount(self::ACT_ID));
        self::assertSame(2, $this->rowCount($otherAct), 'the other author is untouched');
    }

    public function testTheAuthorPurgeReportsZeroWhenThereIsNothingLeft(): void
    {
        self::assertSame(
            0,
            PostReachRepository::deleteForAuthor(self::AUTHOR),
            'zero means done — the caller loops until it sees this'
        );
    }

    public function testReachForPostTotalsAcrossSourcesAndKeepsTheBreakdown(): void
    {
        PostReachRepository::incrementView(self::ACT_ID, self::DAY, 'feed', true);
        PostReachRepository::incrementView(self::ACT_ID, self::DAY, 'feed', false);
        PostReachRepository::incrementView(self::ACT_ID, self::DAY, 'tag', true);
        PostReachRepository::incrementProfileVisit(self::ACT_ID, self::DAY, 'feed');

        $reach = PostReachRepository::reachForPost(self::ACT_ID);

        self::assertNotNull($reach);
        self::assertSame(3, $reach['views']);
        self::assertSame(2, $reach['daily_unique_viewers'], 'summing across sources does not double-count');
        self::assertSame(1, $reach['profile_visits']);
        self::assertSame(['feed', 'tag'], array_keys($reach['by_source']));
        self::assertSame(2, $reach['by_source']['feed']['views']);
    }

    /** A post with no rows is a real zero, distinguishable from a failure. */
    public function testAPostWithNoReachReadsAsZeroNotNull(): void
    {
        $reach = PostReachRepository::reachForPost(self::ACT_ID);

        self::assertNotNull($reach, 'no rows is not a failure');
        self::assertSame(0, $reach['views']);
        self::assertSame([], $reach['by_source']);
    }

    /** ⚠ A failed read must be null — never a zero-filled array. */
    public function testAFailedReachReadIsNullNotZeros(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bcc_trust_post_reach_daily';

        $wpdb->query("DROP TABLE `{$table}`");
        \BCC\Trust\Core\Database\TableRegistry::flushExistenceCache();

        try {
            self::assertNull(
                PostReachRepository::reachForPost(self::ACT_ID),
                'an unreadable table must not read as "no views"'
            );
        } finally {
            bcc_trust_create_post_reach_daily_table();
            \BCC\Trust\Core\Database\TableRegistry::flushExistenceCache();
        }
    }
}
