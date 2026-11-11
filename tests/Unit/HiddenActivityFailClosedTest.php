<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Core\Log\Logger;
use BCC\Core\Observability\DegradationMetrics;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Tests\Stubs\HiddenIdsWpdb;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-98 — a failed hidden-activity read must FAIL CLOSED, never re-expose
 * moderation-hidden posts. F-77 — past 5000 rows the oldest decisions must
 * not silently drop out.
 *
 * The $wpdb double models REAL WPDB failure semantics (get_col → [] +
 * last_error; get_var → NULL + last_error; last_error reset per query); see
 * tests/Stubs/hidden-activity-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HiddenActivityFailClosedTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/hidden-activity-stubs.php';
        $GLOBALS['__hidden_cache']         = [];
        $GLOBALS['__hidden_options']       = [];
        $GLOBALS['__hidden_option_writes'] = 0;
        DegradationMetrics::$recorded      = [];
        Logger::$errors                    = [];
        global $wpdb;
        $wpdb = new HiddenIdsWpdb();
        $wpdb->hidden = [101, 102, 103];
    }

    // ── F-98: getAllHiddenIds ───────────────────────────────────────────

    public function testAFailedReadIsNeverCachedAsEmpty(): void
    {
        $repo = new HiddenActivityRepository();
        self::assertSame([103, 102, 101], $repo->getAllHiddenIds(), 'a healthy read');

        $this->bust();
        $this->db()->failNext = 1;
        $served = $repo->getAllHiddenIds();
        self::assertSame([103, 102, 101], $served, 'the last-known-good list, never []');

        // The failure was not cached: the next request reads the DB again.
        $before = $this->db()->queries;
        $repo->getAllHiddenIds();
        self::assertGreaterThan($before, $this->db()->queries, 'retried, not served from a cached failure');
        self::assertContains('moderation_hidden_ids:served_last_good', DegradationMetrics::$recorded);
    }

    /**
     * wpdb::query()'s `! $this->ready` path returns false WITHOUT setting
     * last_error and WITHOUT flushing last_result. A last_error-only check
     * read that as "no hidden posts", cached it, and overwrote last-good
     * with []. That's F-98 again (review L1 on #410).
     */
    public function testASilentQueryFailureIsAFailureNotAnEmptyList(): void
    {
        $repo = new HiddenActivityRepository();
        self::assertSame([103, 102, 101], $repo->getAllHiddenIds());

        $this->bust();
        $this->db()->failSilentNext = 1;
        self::assertSame([103, 102, 101], $repo->getAllHiddenIds(), 'last-good served, never []');
        self::assertSame(
            [103, 102, 101],
            $GLOBALS['__hidden_options'][HiddenActivityRepository::LAST_GOOD_OPTION]['ids'],
            'last-good NOT overwritten with []'
        );
        self::assertContains('moderation_hidden_ids:list_read_failed', DegradationMetrics::$recorded);

        // And not cached: the next request reads the DB again.
        $before = $this->db()->queries;
        $repo->getAllHiddenIds();
        self::assertGreaterThan($before, $this->db()->queries);
    }

    public function testASilentFailureCannotServeAStaleUnrelatedResult(): void
    {
        $repo = new HiddenActivityRepository();
        $repo->getAllHiddenIds();
        $repo->exists(555); // leaves last_result = [['0']]

        $this->bust();
        $this->db()->failSilentNext = 1; // the list query never runs; last_result is stale
        self::assertSame([103, 102, 101], $repo->getAllHiddenIds());
    }

    public function testExistsFailsClosedOnASilentFailure(): void
    {
        $repo = new HiddenActivityRepository();
        $repo->exists(101); // last_result = [['1']]… then a silent failure
        $this->db()->failSilentNext = 1;
        self::assertTrue($repo->exists(555), 'silent failure answers "hidden"');

        $repo->exists(555); // a real "not hidden" read: last_result = [['0']]
        $this->db()->failSilentNext = 1;
        self::assertTrue($repo->exists(101), 'and never trusts the stale 0 either');
        self::assertContains('moderation_hidden_ids:exists_read_failed', DegradationMetrics::$recorded);
    }

    public function testAGenuinelyEmptyTableIsAnEmptyListNotAFailure(): void
    {
        // `=== false`, not `!$ok`: a SELECT returning 0 rows is success.
        $this->db()->hidden = [];
        self::assertSame([], (new HiddenActivityRepository())->getAllHiddenIds());
        self::assertSame([], DegradationMetrics::$recorded);
        self::assertFalse((new HiddenActivityRepository())->exists(555));
    }

    public function testServingLastGoodLogsItsAge(): void
    {
        $repo = new HiddenActivityRepository();
        $repo->getAllHiddenIds();
        $GLOBALS['__hidden_options'][HiddenActivityRepository::LAST_GOOD_OPTION]['read_at'] = microtime(true) - 600;

        $this->bust();
        $this->db()->failNext = 1;
        $repo->getAllHiddenIds();

        $served = array_values(array_filter(Logger::$errors, static fn (array $e): bool => str_contains($e[0], 'last-known-good')));
        self::assertCount(1, $served);
        self::assertGreaterThanOrEqual(599, $served[0][1]['last_good_age_seconds']);
        self::assertSame(3, $served[0][1]['last_good_count']);
    }

    public function testWithNoLastGoodListAFailedReadThrowsRatherThanShowingEverything(): void
    {
        $this->db()->failNext = 1;
        $this->expectException(RepositoryException::class);
        (new HiddenActivityRepository())->getAllHiddenIds();
    }

    public function testAFailureMidBatchIsAFailureNotAPartialList(): void
    {
        $repo = new HiddenActivityRepository();
        $repo->getAllHiddenIds(); // last-good = 3 ids

        $this->db()->hidden = range(1, HiddenActivityRepository::READ_BATCH + 10);
        $this->bust();
        $this->db()->failOnQuery = $this->db()->queries + 2; // the SECOND batch fails

        self::assertSame([103, 102, 101], $repo->getAllHiddenIds(), 'a partial first batch must not pass as complete');
    }

    public function testTheLastGoodListIsOnlyRewrittenWhenItChanges(): void
    {
        $repo = new HiddenActivityRepository();
        $repo->getAllHiddenIds();
        self::assertSame(1, $GLOBALS['__hidden_option_writes']);

        $this->bust();
        $repo->getAllHiddenIds();
        self::assertSame(1, $GLOBALS['__hidden_option_writes'], 'unchanged list: no write');

        $this->db()->hidden[] = 104;
        $this->bust();
        $repo->getAllHiddenIds();
        self::assertSame(2, $GLOBALS['__hidden_option_writes']);
    }

    public function testAnOlderReadCannotOverwriteANewerLastGoodList(): void
    {
        $repo = new HiddenActivityRepository();
        // A NEWER read already landed (stamped in the future relative to ours).
        $GLOBALS['__hidden_options'][HiddenActivityRepository::LAST_GOOD_OPTION] = [
            'ids' => [999], 'read_at' => microtime(true) + 60, 'hash' => 'newer',
        ];

        $repo->getAllHiddenIds();

        self::assertSame([999], $GLOBALS['__hidden_options'][HiddenActivityRepository::LAST_GOOD_OPTION]['ids']);
    }

    // ── F-77: past 5000 rows ────────────────────────────────────────────

    public function testMoreThanFiveThousandHiddenRowsAreAllExcluded(): void
    {
        $this->db()->hidden = range(1, 12001);

        $ids = (new HiddenActivityRepository())->getAllHiddenIds();

        self::assertCount(12001, $ids);
        self::assertContains(1, $ids, 'the OLDEST decision is still excluded');
        self::assertNotContains('moderation_hidden_ids:ceiling_reached', DegradationMetrics::$recorded);
    }

    public function testTheCeilingIsLoudAndDropsOnlyTheOldest(): void
    {
        $this->db()->hidden = range(1, HiddenActivityRepository::READ_CEILING + 7);

        $ids = (new HiddenActivityRepository())->getAllHiddenIds();

        self::assertCount(HiddenActivityRepository::READ_CEILING, $ids);
        self::assertContains(HiddenActivityRepository::READ_CEILING + 7, $ids, 'the newest decisions are kept');
        self::assertNotContains(1, $ids);
        self::assertContains('moderation_hidden_ids:ceiling_reached', DegradationMetrics::$recorded);
    }

    // ── F-98: exists ────────────────────────────────────────────────────

    public function testExistsFailsClosed(): void
    {
        $repo = new HiddenActivityRepository();
        self::assertTrue($repo->exists(101));
        self::assertFalse($repo->exists(555));

        $this->db()->failNext = 1;
        self::assertTrue($repo->exists(555), 'a failed read answers "hidden", never "visible"');
        self::assertContains('moderation_hidden_ids:exists_read_failed', DegradationMetrics::$recorded);
    }

    private function db(): HiddenIdsWpdb
    {
        global $wpdb;
        return $wpdb;
    }

    /** Simulate the 5-minute cache expiring. */
    private function bust(): void
    {
        foreach (array_keys($GLOBALS['__hidden_cache']) as $k) {
            if (str_contains((string) $k, 'all_hidden_ids')) {
                unset($GLOBALS['__hidden_cache'][$k]);
            }
        }
    }
}
