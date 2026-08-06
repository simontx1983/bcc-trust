<?php

declare(strict_types=1);

namespace BCC\Core\DB {
    // bcc-core is not autoloaded in the unit context (see tests/bootstrap.php),
    // so stub the table-name resolver the repository reaches for. Guarded so
    // the first definition in a shared process wins (each test here runs in
    // its own subprocess anyway).
    if (!class_exists(__NAMESPACE__ . '\\DB', false)) {
        final class DB
        {
            public static function table(string $name): string
            {
                return 'wp_bcc_' . $name;
            }
        }
    }
}

namespace {
    if (!function_exists('current_time')) {
        /** Minimal WP shim for BlogChainTagRepository::replace's created_at. */
        function current_time(string $type): string
        {
            return gmdate('Y-m-d H:i:s');
        }
    }
}

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Trust\Core\Repositories\BlogChainTagRepository;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * Pins the §1–§5 shape of BlogChainTagRepository::findByPostIds (the
     * bulk read the cold-/feed/hot N+1 fix batches through) and the
     * per-request prefetch memo seam (prefetchForPostIds → findByPostId
     * becomes an in-memory lookup; replace() evicts the written post).
     *
     * A real $wpdb is replaced with a recorder so the exact query and
     * bound args can be asserted without a database. Runs in subprocesses
     * so the global wp_cache_* shims (tests/Stubs/object-cache-stubs.php)
     * and the repository's STATIC prefetch memo never leak across tests.
     */
    #[CoversClass(BlogChainTagRepository::class)]
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class BlogChainTagPrefetchSqlTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            require_once __DIR__ . '/../Stubs/object-cache-stubs.php';
            \BccTestPersistentCache::reset();

            global $wpdb;
            $wpdb = new class {
                public string $prefix = 'wp_';
                public string $last_error = '';
                /** @var list<array{sql: string, args: array<int, mixed>}> */
                public array $prepared = [];
                /** @var list<list<object>> FIFO — one entry per get_results call. */
                public array $resultsQueue = [];
                /** @var list<array<string, mixed>> */
                public array $deletes = [];
                /** @var list<array<string, mixed>> */
                public array $inserts = [];

                /** @param mixed ...$args */
                public function prepare(string $sql, ...$args): string
                {
                    $this->prepared[] = ['sql' => $sql, 'args' => $args];
                    return $sql;
                }

                /** @return list<object> */
                public function get_results(string $sql): array
                {
                    $next = array_shift($this->resultsQueue);
                    return $next ?? [];
                }

                /**
                 * @param array<string, mixed> $where
                 * @param list<string> $formats
                 */
                public function delete(string $table, array $where, array $formats): int
                {
                    $this->deletes[] = $where;
                    return 1;
                }

                /**
                 * @param array<string, mixed> $data
                 * @param list<string> $formats
                 */
                public function insert(string $table, array $data, array $formats): int
                {
                    $this->inserts[] = $data;
                    return 1;
                }
            };
        }

        protected function tearDown(): void
        {
            global $wpdb;
            $wpdb = null;
            parent::tearDown();
        }

        private static function fakeWpdb(): object
        {
            global $wpdb;
            \assert(\is_object($wpdb));
            return $wpdb;
        }

        /** Collapse whitespace so multi-line SQL can be matched by structure. */
        private static function norm(string $sql): string
        {
            return (string) preg_replace('/\s+/', ' ', trim($sql));
        }

        public function testFindByPostIdsSqlShapeDedupAndBound(): void
        {
            self::fakeWpdb()->resultsQueue[] = [
                (object) ['post_id' => '7', 'chain_id' => '3'],
                (object) ['post_id' => '7', 'chain_id' => '5'],
            ];

            // Duplicates + non-positive ids must be discarded before the IN().
            $map = (new BlogChainTagRepository())->findByPostIds([7, 7, 0, -3, 9]);

            $prepared = self::fakeWpdb()->prepared;
            self::assertCount(1, $prepared, 'one bounded bulk query for the whole page');
            $sql = self::norm($prepared[0]['sql']);

            // §2 — explicit columns, never SELECT *.
            self::assertStringContainsString('SELECT post_id, chain_id', $sql);
            self::assertStringNotContainsString('SELECT *', $sql);

            // §4 — bounded IN() with one %d per cleaned id + a LIMIT.
            self::assertStringContainsString('WHERE post_id IN (%d,%d)', $sql);
            self::assertStringContainsString('LIMIT %d', $sql);

            // Args: the two cleaned ids then the row cap
            // (2 ids × MAX_TAGS_PER_POST(3) = 6, under the 200 bulk cap).
            self::assertSame([7, 9, 6], $prepared[0]['args']);

            // Map shape: tagged post keyed with its chains in insert order;
            // untagged post ABSENT (callers treat absence as "no tags").
            self::assertSame([7 => [3, 5]], $map);
        }

        public function testFindByPostIdsRowCapClampsAtBulkMax(): void
        {
            // 100 ids × 3 tags = 300 worst-case rows → clamped to 200.
            $ids = range(1, 100);
            (new BlogChainTagRepository())->findByPostIds($ids);

            $prepared = self::fakeWpdb()->prepared;
            self::assertCount(1, $prepared);
            $args = $prepared[0]['args'];
            self::assertSame(200, end($args), 'LIMIT arg clamps at MAX_BULK_ROWS');
        }

        public function testPrefetchMakesPerRowLookupsQueryFree(): void
        {
            self::fakeWpdb()->resultsQueue[] = [
                (object) ['post_id' => '11', 'chain_id' => '2'],
            ];

            $repo = new BlogChainTagRepository();
            $repo->prefetchForPostIds([11, 12]);

            self::assertCount(1, self::fakeWpdb()->prepared, 'prefetch = ONE bulk query');

            // Per-row reads now resolve from the memo — tagged and untagged
            // alike (12 memoizes [] so it can't fall through to a query).
            self::assertSame([2], $repo->findByPostId(11));
            self::assertSame([], $repo->findByPostId(12));
            self::assertCount(
                1,
                self::fakeWpdb()->prepared,
                'findByPostId after prefetch must not issue per-row queries'
            );

            // Re-prefetching already-memoized ids is a no-op.
            $repo->prefetchForPostIds([11, 12]);
            self::assertCount(1, self::fakeWpdb()->prepared);
        }

        public function testReplaceEvictsThePrefetchMemoForTheWrittenPost(): void
        {
            $repo = new BlogChainTagRepository();

            // Memoize post 21 as untagged.
            $repo->prefetchForPostIds([21]);
            self::assertSame([], $repo->findByPostId(21));

            // Write a tag set — the memo entry must not survive.
            $repo->replace(21, [4]);

            // The next read falls through to cache/DB and sees the write.
            self::fakeWpdb()->resultsQueue[] = [
                (object) ['chain_id' => '4'],
            ];
            self::assertSame(
                [4],
                $repo->findByPostId(21),
                'a same-request read after replace() must not serve the stale memo'
            );
        }
    }
}
