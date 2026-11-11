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

    /** @var array<string, mixed> $GLOBALS['__f100_cache'] */
    $GLOBALS['__f100_cache'] = [];

    if (!function_exists('wp_cache_get')) {
        /** @return mixed */
        function wp_cache_get(string $key, string $group = '')
        {
            return $GLOBALS['__f100_cache'][$group . ':' . $key] ?? false;
        }
    }
    if (!function_exists('wp_cache_set')) {
        /** @param mixed $value */
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            $GLOBALS['__f100_cache'][$group . ':' . $key] = $value;
            return true;
        }
    }
}

namespace BCC\Trust\Disputes\Tests\Unit {

    use BCC\Core\Log\Logger;
    use BCC\Trust\Disputes\Repositories\DisputeRepository;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-100 — DisputeRepository::getDisputedVoteIds() on a FAILED read.
     *
     * get_col() returns [] on a SQL error (never null), so the old code read a
     * failed query as "none of these votes are disputed" and cached 0 for the
     * whole batch for TTL_HOT. The fix routes the SELECT through
     * GuardsReadFailures::runRead() and, on failure, caches NOTHING and fails
     * CLOSED: every unresolved vote is reported as disputed (the only caller
     * uses it as the page owner's "already disputed" gate).
     *
     * The fake wpdb models both failure paths: an error string, and wpdb's
     * silent early return (false, no error, no flush — last_result keeps the
     * previous query's rows).
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class DisputedVoteIdsReadFailureTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            $GLOBALS['wpdb']         = new DisputedVoteIdsFakeWpdb();
            $GLOBALS['__f100_cache'] = [];
            Logger::$errors          = [];
        }

        private static function wpdb(): DisputedVoteIdsFakeWpdb
        {
            return $GLOBALS['wpdb'];
        }

        /** @return list<string> */
        private static function cachedKeys(): array
        {
            return array_keys($GLOBALS['__f100_cache']);
        }

        public function testASuccessfulReadReportsAndCachesBothAnswers(): void
        {
            self::wpdb()->next = ['ok' => 1, 'error' => '', 'rows' => [(object) ['vote_id' => '11']]];

            self::assertSame([11 => true], DisputeRepository::getDisputedVoteIds([11, 12]));
            self::assertSame(1, $GLOBALS['__f100_cache']['bcc_disputes:disputed_vote:11'] ?? null);
            self::assertSame(0, $GLOBALS['__f100_cache']['bcc_disputes:disputed_vote:12'] ?? null);
        }

        public function testAGenuineEmptyReadIsNotAFailure(): void
        {
            // query() returns the row count: 0 is a real "no rows".
            self::wpdb()->next = ['ok' => 0, 'error' => '', 'rows' => []];

            self::assertSame([], DisputeRepository::getDisputedVoteIds([21, 22]));
            self::assertCount(2, self::cachedKeys());
            self::assertSame([], Logger::$errors);
        }

        public function testAnErroredReadFailsClosedAndCachesNothing(): void
        {
            self::wpdb()->next = ['ok' => false, 'error' => 'Lost connection to MySQL server', 'rows' => []];

            self::assertSame(
                [31 => true, 32 => true],
                DisputeRepository::getDisputedVoteIds([31, 32]),
                'a failed read must not answer "not disputed"'
            );
            self::assertSame([], self::cachedKeys(), 'a failed read must not be cached');
            self::assertNotSame([], Logger::$errors, 'the failure is logged');
        }

        public function testTheSilentEarlyReturnNeverUsesStaleRowsAndCachesNothing(): void
        {
            // The previous query left a row in last_result; this one returns
            // false with NO error and does not flush.
            self::wpdb()->last_result = [(object) ['vote_id' => '999']];
            self::wpdb()->next        = ['ok' => false, 'error' => '', 'rows' => null];

            self::assertSame([41 => true, 42 => true], DisputeRepository::getDisputedVoteIds([41, 42]));
            self::assertSame([], self::cachedKeys());
        }

        public function testCachedAnswersSurviveAFailureForTheRestOfTheBatch(): void
        {
            // 51 is a cached "not disputed"; only 52 needs the DB, which fails.
            $GLOBALS['__f100_cache']['bcc_disputes:disputed_vote:51'] = 0;
            self::wpdb()->next = ['ok' => false, 'error' => 'Deadlock found', 'rows' => []];

            self::assertSame([52 => true], DisputeRepository::getDisputedVoteIds([51, 52]));
            self::assertSame(['bcc_disputes:disputed_vote:51'], self::cachedKeys());
        }
    }

    /**
     * Models wpdb::query(): on success it flushes and fills last_result; on the
     * silent early-return path it returns false and touches NOTHING.
     */
    final class DisputedVoteIdsFakeWpdb
    {
        public string $last_error = '';

        /** @var list<object> */
        public array $last_result = [];

        /** @var array{ok: int|false, error: string, rows: list<object>|null} */
        public array $next = ['ok' => 0, 'error' => '', 'rows' => []];

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            return vsprintf(str_replace('%d', '%d', $sql), $args);
        }

        /** @return int|false */
        public function query(string $sql)
        {
            if ($this->next['ok'] === false && $this->next['error'] === '') {
                return false; // early return: no flush, no error
            }
            $this->last_error  = $this->next['error'];
            $this->last_result = $this->next['rows'] ?? [];
            return $this->next['ok'];
        }

        /** @return list<string> */
        public function get_col(?string $sql = null): array
        {
            if ($sql !== null) {
                $this->query($sql);
            }
            return array_map(static fn (object $r): string => (string) ($r->vote_id ?? ''), $this->last_result);
        }
    }
}
