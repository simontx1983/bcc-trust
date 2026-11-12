<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\PostReachRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-324 REACH persistence, against a real MySQL/MariaDB.
 *
 * Four things only a real engine can settle, and each has a way of looking
 * fine in a double:
 *   1. the ODKU accumulate really adds rather than replacing, and only on a
 *      genuine key collision;
 *   2. the dedupe PK collision is what answers "already seen", in one
 *      round-trip, with no read-then-write race;
 *   3. the sweep's age TTL and row cap actually delete the rows they claim;
 *   4. a failed read returns null rather than a confident 0 — the thing that
 *      would silently switch the overflow cap off (F-101).
 *
 * Tables are installed from the real schema functions, never hand-rolled
 * DDL, so the test cannot drift from what production installs.
 */
#[Group('integration')]
#[CoversClass(PostReachRepository::class)]
final class PostReachRepositoryIntegrationTest extends TestCase
{
    private const POST = 910001;
    private const DAY  = '2026-11-12';

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
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_trust_post_view_dedupe`');
        \BCC\Trust\Core\Database\TableRegistry::flushExistenceCache();
    }

    /** @return array<string, int> */
    private function row(int $postId, string $day, string $source): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bcc_trust_post_reach_daily';
        $r = $wpdb->get_row($wpdb->prepare(
            "SELECT views, unique_viewers, profile_visits FROM {$table}
              WHERE post_id = %d AND day = %s AND source = %s",
            $postId,
            $day,
            $source
        ));

        return $r === null ? [] : [
            'views'          => (int) $r->views,
            'unique_viewers' => (int) $r->unique_viewers,
            'profile_visits' => (int) $r->profile_visits,
        ];
    }

    private function hash(string $seed): string
    {
        return substr(hash('sha256', $seed, true), 0, 16);
    }

    public function testViewsAccumulateRatherThanOverwrite(): void
    {
        self::assertTrue(PostReachRepository::incrementView(self::POST, self::DAY, 'feed', true));
        self::assertSame(
            ['views' => 1, 'unique_viewers' => 1, 'profile_visits' => 0],
            $this->row(self::POST, self::DAY, 'feed'),
            'first view creates the row'
        );

        // Two more, neither a unique viewer.
        PostReachRepository::incrementView(self::POST, self::DAY, 'feed', false);
        PostReachRepository::incrementView(self::POST, self::DAY, 'feed', false);

        self::assertSame(
            ['views' => 3, 'unique_viewers' => 1, 'profile_visits' => 0],
            $this->row(self::POST, self::DAY, 'feed'),
            'ODKU must ADD — a replace would leave views at 1, and a bad '
            . 'unique increment would leave it at 3'
        );
    }

    public function testEachSourceKeepsItsOwnRow(): void
    {
        PostReachRepository::incrementView(self::POST, self::DAY, 'feed', true);
        PostReachRepository::incrementView(self::POST, self::DAY, 'tag', true);

        self::assertSame(1, $this->row(self::POST, self::DAY, 'feed')['views']);
        self::assertSame(1, $this->row(self::POST, self::DAY, 'tag')['views']);
    }

    public function testProfileVisitsNeverInflateViews(): void
    {
        PostReachRepository::incrementView(self::POST, self::DAY, 'feed', true);
        PostReachRepository::incrementProfileVisit(self::POST, self::DAY, 'feed');

        self::assertSame(
            ['views' => 1, 'unique_viewers' => 1, 'profile_visits' => 1],
            $this->row(self::POST, self::DAY, 'feed'),
            'a profile visit is not a view'
        );
    }

    /**
     * ⭐ Review condition: the writer must whitelist `source`, and must NOT
     * accept 'cold-start'.
     */
    public function testUnknownAndColdStartSourcesAreRejected(): void
    {
        foreach (['cold-start', 'coldstart', 'email', '', 'FEED'] as $bad) {
            self::assertFalse(
                PostReachRepository::incrementView(self::POST, self::DAY, $bad, true),
                "source '{$bad}' must be rejected"
            );
            self::assertSame([], $this->row(self::POST, self::DAY, $bad), 'and must write nothing');
        }

        // Anti-vacuity: the whitelist is not rejecting everything.
        foreach (PostReachRepository::SOURCES as $good) {
            self::assertTrue(
                PostReachRepository::incrementView(self::POST, self::DAY, $good, false),
                "source '{$good}' must be accepted"
            );
        }
    }

    public function testFirstClaimWinsAndTheSecondIsRefusedForTheSameDay(): void
    {
        $h = $this->hash('viewer-a');

        self::assertTrue(
            PostReachRepository::claimDailyViewer(self::POST, self::DAY, $h),
            'first sighting today counts as a unique viewer'
        );
        self::assertFalse(
            PostReachRepository::claimDailyViewer(self::POST, self::DAY, $h),
            'the same viewer must not count twice on the same day'
        );
    }

    public function testTheSameViewerCountsAgainOnADifferentDay(): void
    {
        $h = $this->hash('viewer-a');

        self::assertTrue(PostReachRepository::claimDailyViewer(self::POST, self::DAY, $h));
        self::assertTrue(
            PostReachRepository::claimDailyViewer(self::POST, '2026-11-13', $h),
            'uniqueness is per DAY by design — a new day is a new claim'
        );
    }

    public function testDifferentViewersBothClaimTheSameDay(): void
    {
        self::assertTrue(PostReachRepository::claimDailyViewer(self::POST, self::DAY, $this->hash('a')));
        self::assertTrue(PostReachRepository::claimDailyViewer(self::POST, self::DAY, $this->hash('b')));
        self::assertSame(2, PostReachRepository::countDedupeRows());
    }

    public function testAMisSizedHashIsRefusedRatherThanStored(): void
    {
        foreach (['', 'short', str_repeat('x', 17)] as $bad) {
            self::assertNull(
                PostReachRepository::claimDailyViewer(self::POST, self::DAY, $bad),
                'a hash that is not exactly 16 bytes is a bug, not a viewer'
            );
        }
        self::assertSame(0, PostReachRepository::countDedupeRows());
    }

    public function testSweepDeletesRowsPastTheTtlAndKeepsFreshOnes(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bcc_trust_post_view_dedupe';

        // One fresh row, one three days old.
        PostReachRepository::claimDailyViewer(self::POST, self::DAY, $this->hash('fresh'));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (post_id, day, viewer_hash, seen_at) VALUES (%d, %s, %s, %s)",
            self::POST,
            '2026-11-09',
            $this->hash('stale'),
            gmdate('Y-m-d H:i:s', time() - (3 * 86400))
        ));
        self::assertSame(2, PostReachRepository::countDedupeRows());

        $result = PostReachRepository::sweepDedupe();

        self::assertSame(1, $result['deleted_age'], 'exactly the stale row');
        self::assertSame(1, $result['remaining']);
        self::assertSame(1, PostReachRepository::countDedupeRows(), 'the fresh row survives');
    }

    /**
     * The overflow trim, exercised for real.
     *
     * ⚠ `sweepDedupe()` floors `maxRows` at 1000, so a toy cap proves
     * nothing — my first version of this test inserted five rows, asked for
     * a cap of 1000, and asserted that nothing was trimmed, which would pass
     * even if the trim were deleted outright. This inserts 1005 fresh rows
     * (all inside the TTL, so only the cap can remove them) and asserts the
     * five OLDEST go and the newest survive.
     */
    public function testSweepTrimsOldestFirstWhenOverTheRowCap(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bcc_trust_post_view_dedupe';
        $base  = time() - 3600; // comfortably inside the 48h TTL

        // One multi-row INSERT: 1005 rows, ascending seen_at, so row i is
        // older than row i+1.
        $values = [];
        for ($i = 0; $i < 1005; $i++) {
            $values[] = $wpdb->prepare(
                '(%d, %s, %s, %s)',
                self::POST,
                self::DAY,
                $this->hash('cap' . $i),
                gmdate('Y-m-d H:i:s', $base + $i)
            );
        }
        $wpdb->query(
            "INSERT INTO {$table} (post_id, day, viewer_hash, seen_at) VALUES "
            . implode(',', $values)
        );
        self::assertSame(1005, PostReachRepository::countDedupeRows(), 'fixture seeded');

        $result = PostReachRepository::sweepDedupe(PostReachRepository::DEDUPE_MAX_AGE_SECONDS, 1000);

        self::assertSame(0, $result['deleted_age'], 'every row is inside the TTL');
        self::assertSame(5, $result['deleted_overflow'], 'exactly the excess over the cap');
        self::assertSame(1000, $result['remaining']);
        self::assertSame(1000, PostReachRepository::countDedupeRows());

        // Oldest-first: cap0..cap4 are gone, cap5 and the newest remain.
        foreach (['cap0', 'cap4'] as $gone) {
            self::assertSame(
                '0',
                $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE viewer_hash = %s",
                    $this->hash($gone)
                )),
                "{$gone} was among the oldest and must have been trimmed"
            );
        }
        foreach (['cap5', 'cap1004'] as $kept) {
            self::assertSame(
                '1',
                $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE viewer_hash = %s",
                    $this->hash($kept)
                )),
                "{$kept} is newer than the cap boundary and must survive"
            );
        }
    }

    public function testDeleteForPostRemovesReachRowsForThatPostOnly(): void
    {
        PostReachRepository::incrementView(self::POST, self::DAY, 'feed', true);
        PostReachRepository::incrementView(self::POST + 1, self::DAY, 'feed', true);

        self::assertSame(1, PostReachRepository::deleteForPost(self::POST));

        self::assertSame([], $this->row(self::POST, self::DAY, 'feed'), 'target post gone');
        self::assertSame(1, $this->row(self::POST + 1, self::DAY, 'feed')['views'], 'neighbour untouched');
    }

    /**
     * ⚠ The F-101 property: a failed count must be null, never 0. A 0 here
     * would make the sweep believe the table is empty and switch the
     * overflow cap off.
     */
    public function testAFailedCountIsNullNotZero(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bcc_trust_post_view_dedupe';

        $wpdb->query("DROP TABLE `{$table}`");
        \BCC\Trust\Core\Database\TableRegistry::flushExistenceCache();

        try {
            self::assertNull(
                PostReachRepository::countDedupeRows(),
                'an unreadable table must report "unknown", never "empty"'
            );
        } finally {
            bcc_trust_create_post_view_dedupe_table();
            \BCC\Trust\Core\Database\TableRegistry::flushExistenceCache();
        }
    }

    /**
     * Writes fail OPEN: a missing table must make the writer return false
     * quietly, never throw into a feed render.
     */
    public function testWritesFailOpenWhenATableIsMissing(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bcc_trust_post_reach_daily';

        $wpdb->query("DROP TABLE `{$table}`");
        \BCC\Trust\Core\Database\TableRegistry::flushExistenceCache();

        try {
            self::assertFalse(
                PostReachRepository::incrementView(self::POST, self::DAY, 'feed', true),
                'no table => false, and no exception'
            );
            self::assertNull(PostReachRepository::claimDailyViewer(self::POST, self::DAY, $this->hash('x')));
        } finally {
            bcc_trust_create_post_reach_daily_table();
            \BCC\Trust\Core\Database\TableRegistry::flushExistenceCache();
        }
    }
}
