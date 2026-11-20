<?php

declare(strict_types=1);

// ── Collaborator stubs at their production FQNs (guarded — first wins) ───────
namespace BCC\Core\DB {
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

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @var list<string> */
            public static array $errors = [];

            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                self::$errors[] = $m;
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
        }
    }
}

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }
}

namespace BCC\Trust\Rank\Tests\Unit {

    use BCC\Core\Log\Logger;
    use BCC\Trust\Disputes\Repositories\DisputeRepository;
    use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
    use BCC\Trust\Rank\Repositories\BallotRepository;
    use BCC\Trust\Rank\Repositories\ClusterFindingsRepository;
    use BCC\Trust\Rank\Repositories\FindingsRepository;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-464 / F-465 / F-466: the REAL rank-integrity reads, over a fake wpdb.
     *
     * Each of these answers a decision that is WRITTEN (a poll close, a
     * promotion, an elite flag). A failed read must throw, never pass for "no
     * clusters", "no findings", "no ballots" or "no upheld disputes". The
     * fake models both failure paths: an error string, and wpdb's silent
     * early return (false, no error, no flush: last_result keeps the PREVIOUS
     * query's rows).
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class RankIntegrityReadFailureTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['wpdb'] = new RankIntegrityFakeWpdb();
            Logger::$errors  = [];
        }

        private static function wpdb(): RankIntegrityFakeWpdb
        {
            return $GLOBALS['wpdb'];
        }

        /** @return array<string, array{string}> */
        public static function reads(): array
        {
            return [
                'confirmed clusters (F-464)' => ['confirmed'],
                'suspected clusters (F-466)' => ['suspected'],
                'member findings (F-465)'    => ['findings'],
                'close-time ballots'         => ['ballots'],
                'upheld disputes (F-466)'    => ['upheld'],
            ];
        }

        /** Run one read over a fresh repository. */
        private static function read(string $which): mixed
        {
            return match ($which) {
                'confirmed' => (new ClusterFindingsRepository())->listActiveConfirmed(),
                'suspected' => (new ClusterFindingsRepository())->listActiveSuspected(),
                'findings'  => (new FindingsRepository())->getActiveForUser(7),
                'ballots'   => (new BallotRepository())->listActiveForPollOrFail(9),
                'upheld'    => DisputeRepository::countUpheldSince(5, '2026-05-01 00:00:00'),
            };
        }

        #[DataProvider('reads')]
        public function testAnErroredReadThrows(string $read): void
        {
            self::wpdb()->next = ['ok' => false, 'error' => 'Lost connection to MySQL server', 'rows' => []];

            $this->expectException(RepositoryReadFailure::class);
            self::read($read);
        }

        /**
         * The pre-flush trap: query() returns false with no error and no
         * flush, so last_result still holds an earlier query's rows.
         */
        #[DataProvider('reads')]
        public function testASilentEarlyReturnThrowsAndNeverUsesStaleRows(string $read): void
        {
            self::wpdb()->last_result = [(object) ['upheld' => '0', 'id' => '1', 'member_user_ids' => '[1,2]', 'representative_user_id' => '1']];
            self::wpdb()->next        = ['ok' => false, 'error' => '', 'rows' => null];

            $this->expectException(RepositoryReadFailure::class);
            self::read($read);
        }

        #[DataProvider('reads')]
        public function testAnErrorBesideRowsThrows(string $read): void
        {
            self::wpdb()->next = ['ok' => 1, 'error' => 'Warning promoted to error', 'rows' => [(object) ['upheld' => '0']]];

            $this->expectException(RepositoryReadFailure::class);
            self::read($read);
        }

        public function testConfirmedClustersAreReadWhenTheQueryWorks(): void
        {
            $row = (object) ['id' => '3', 'level' => 'confirmed', 'member_user_ids' => '[4,5]', 'representative_user_id' => '4', 'selection_method' => 'auto_oldest_account', 'effective_at' => '2026-11-01 00:00:00'];
            self::wpdb()->next = ['ok' => 1, 'error' => '', 'rows' => [$row]];

            self::assertSame([$row], (new ClusterFindingsRepository())->listActiveConfirmed());
            $sql = (string) preg_replace('/\s+/', ' ', self::wpdb()->queries[0]);
            self::assertStringContainsString("level = 'confirmed'", $sql);
            self::assertStringContainsString('reversed_at IS NULL', $sql);
            self::assertStringContainsString('LIMIT 200', $sql);
            self::assertStringNotContainsString('SELECT *', $sql);
        }

        public function testSuspectedClustersReadTheirOwnLevel(): void
        {
            self::wpdb()->next = ['ok' => 0, 'error' => '', 'rows' => []];

            self::assertSame([], (new ClusterFindingsRepository())->listActiveSuspected(), 'a real "none" is not a failure');
            self::assertStringContainsString("level = 'suspected'", (string) preg_replace('/\s+/', ' ', self::wpdb()->queries[0]));
            self::assertSame([], Logger::$errors);
        }

        public function testMemberFindingsAreReadWhenTheQueryWorks(): void
        {
            $row = (object) ['id' => '8', 'subject_user_id' => '7', 'status' => 'active'];
            self::wpdb()->next = ['ok' => 1, 'error' => '', 'rows' => [$row]];

            self::assertSame([$row], (new FindingsRepository())->getActiveForUser(7));
            $sql = (string) preg_replace('/\s+/', ' ', self::wpdb()->queries[0]);
            self::assertStringContainsString('subject_user_id = 7', $sql);
            self::assertStringContainsString("status = 'active'", $sql);
            self::assertStringContainsString('LIMIT 100', $sql);
        }

        public function testNoMemberIsNoQuery(): void
        {
            self::assertSame([], (new FindingsRepository())->getActiveForUser(0));
            self::assertSame([], self::wpdb()->queries);
        }

        public function testCloseTimeBallotsAreReadWhenTheQueryWorks(): void
        {
            $row = (object) ['id' => '1', 'poll_id' => '9', 'voter_user_id' => '3'];
            self::wpdb()->next = ['ok' => 1, 'error' => '', 'rows' => [$row]];

            self::assertSame([$row], (new BallotRepository())->listActiveForPollOrFail(9));
            $sql = (string) preg_replace('/\s+/', ' ', self::wpdb()->queries[0]);
            self::assertStringContainsString('poll_id = 9', $sql);
            self::assertStringContainsString("status = 'active'", $sql);
            self::assertStringContainsString('LIMIT ' . BallotRepository::MAX_BALLOTS, $sql);
        }

        public function testNoPollIsNoQuery(): void
        {
            self::assertSame([], (new BallotRepository())->listActiveForPollOrFail(0));
            self::assertSame([], self::wpdb()->queries);
        }

        public function testUpheldDisputesAreCountedWhenTheQueryWorks(): void
        {
            self::wpdb()->next = ['ok' => 1, 'error' => '', 'rows' => [(object) ['upheld' => '3']]];
            self::assertSame(3, DisputeRepository::countUpheldSince(5, '2026-05-01 00:00:00'));

            self::wpdb()->next = ['ok' => 1, 'error' => '', 'rows' => [(object) ['upheld' => '0']]];
            self::assertSame(0, DisputeRepository::countUpheldSince(5, '2026-05-01 00:00:00'), 'a real zero');
        }

        /** COUNT(*) always answers one numeric row; no row is not a count. */
        public function testACountWithNoRowThrows(): void
        {
            self::wpdb()->next = ['ok' => 0, 'error' => '', 'rows' => []];

            $this->expectException(RepositoryReadFailure::class);
            DisputeRepository::countUpheldSince(5, '2026-05-01 00:00:00');
        }

        public function testNoPageOrWindowIsZeroWithoutAQuery(): void
        {
            self::assertSame(0, DisputeRepository::countUpheldSince(0, '2026-05-01 00:00:00'));
            self::assertSame(0, DisputeRepository::countUpheldSince(5, ''));
            self::assertSame([], self::wpdb()->queries);
        }
    }

    /**
     * Models wpdb::query(): on success it flushes and fills last_result; on the
     * silent early-return path it returns false and touches NOTHING.
     */
    final class RankIntegrityFakeWpdb
    {
        public string $prefix = 'wp_';
        public string $users = 'wp_users';
        public string $last_error = '';

        /** @var list<object> */
        public array $last_result = [];

        /** @var list<string> */
        public array $queries = [];

        /** @var array{ok: int|false, error: string, rows: list<object>|null} */
        public array $next = ['ok' => 0, 'error' => '', 'rows' => []];

        /** @param mixed ...$args */
        public function prepare(string $query, ...$args): string
        {
            $i = 0;

            return (string) preg_replace_callback('/%[ds]/', static function (array $m) use (&$i, $args): string {
                $v = $args[$i++] ?? '';

                return $m[0] === '%d' ? (string) (int) $v : "'" . addslashes((string) $v) . "'";
            }, $query);
        }

        /** @return int|false */
        public function query(string $sql)
        {
            $this->queries[] = $sql;
            if ($this->next['ok'] === false && $this->next['error'] === '') {
                return false; // early return: no flush, no error
            }
            $this->last_error  = $this->next['error'];
            $this->last_result = $this->next['rows'] ?? [];

            return $this->next['ok'];
        }
    }
}
