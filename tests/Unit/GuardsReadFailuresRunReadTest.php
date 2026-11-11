<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit {

    use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * GuardsReadFailures::runRead() — the list-read form of bcc-core's
     * PeepSoBlockRepository::runProbe() (F-64 review).
     *
     * `wpdb::query()` has failure paths — the `! $this->ready` guard and an
     * empty `query` filter — that return false WITHOUT setting `last_error`
     * and BEFORE `flush()`, so `last_result` still holds the PREVIOUS query's
     * rows. A `get_results()` + `last_error` check hands those stale rows back
     * as an answer. The fake below reproduces exactly that path, which the
     * integration harness's mysqli shim does not model.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class GuardsReadFailuresRunReadTest extends TestCase
    {
        protected function setUp(): void
        {
            parent::setUp();
            if (!defined('ABSPATH')) {
                define('ABSPATH', sys_get_temp_dir() . '/');
            }
            if (!class_exists('BCC\\Core\\Log\\Logger', false)) {
                eval('namespace BCC\\Core\\Log; final class Logger { public static array $lines = []; public static function error(string $m, array $c = []): void { self::$lines[] = $m; } public static function warning(string $m, array $c = []): void {} public static function info(string $m, array $c = []): void {} }');
            }
            $GLOBALS['wpdb'] = new RunReadFakeWpdb();
        }

        private static function wpdb(): RunReadFakeWpdb
        {
            return $GLOBALS['wpdb'];
        }

        public function testRowsOfASuccessfulReadAreReturned(): void
        {
            self::wpdb()->next = ['ok' => 2, 'error' => '', 'rows' => [(object) ['id' => 1], (object) ['id' => 2]]];

            self::assertSame([1, 2], array_map(static fn (object $r): int => $r->id, RunReadProbe::read(true)));
        }

        public function testAGenuineEmptyReadIsEmptyAndNeverAFailure(): void
        {
            // query() returns the row count: 0 is a real "no rows", and a
            // falsy test (`!$ok`) would wrongly call it a failure.
            self::wpdb()->next = ['ok' => 0, 'error' => '', 'rows' => []];

            self::assertSame([], RunReadProbe::read(true));
        }

        public function testTheSilentEarlyReturnNeverYieldsTheStaleRowsAndThrowsWhenFailClosed(): void
        {
            // The previous query left a row in last_result; this one returns
            // false with NO error and does not flush.
            self::wpdb()->last_result = [(object) ['id' => 'STALE-FROM-ANOTHER-QUERY']];
            self::wpdb()->next        = ['ok' => false, 'error' => '', 'rows' => null];

            try {
                RunReadProbe::read(true);
                self::fail('a read that did not run must throw when fail-closed');
            } catch (RepositoryReadFailure $e) {
                self::assertSame('probe', $e->repositoryMethod());
                self::assertStringContainsString('early return', $e->dbError());
            }
        }

        public function testTheSilentEarlyReturnIsEmptyWhenFailSafe(): void
        {
            self::wpdb()->last_result = [(object) ['id' => 'STALE-FROM-ANOTHER-QUERY']];
            self::wpdb()->next        = ['ok' => false, 'error' => '', 'rows' => null];

            self::assertSame([], RunReadProbe::read(false), 'the stale row is never returned');
        }

        public function testAnErrorStringThrowsEvenIfQueryReportedRows(): void
        {
            self::wpdb()->next = ['ok' => 1, 'error' => 'Lost connection', 'rows' => [(object) ['id' => 1]]];

            $this->expectException(RepositoryReadFailure::class);
            RunReadProbe::read(true);
        }

        public function testAnEarlierQuerysErrorDoesNotFailASuccessfulRead(): void
        {
            // A successful read after an earlier failure is not misreported.
            // (Real wpdb's flush() resets last_error on the success path, so
            // runRead's own clear is belt-and-braces, kept for parity with
            // runProbe; mutation testing confirms it is not load-bearing.)
            self::wpdb()->last_error = 'old failure';
            self::wpdb()->next       = ['ok' => 1, 'error' => '', 'rows' => [(object) ['id' => 7]]];

            self::assertCount(1, RunReadProbe::read(true));
        }
    }

    /** Uses the trait under test. */
    final class RunReadProbe
    {
        use \BCC\Trust\Onchain\Repositories\GuardsReadFailures;

        /** @return list<object> */
        public static function read(bool $failClosed): array
        {
            return self::runRead('SELECT 1', 'probe', $failClosed);
        }
    }

    /**
     * Models wpdb's query(): on success it flushes and fills last_result; on
     * the silent early-return path it returns false and touches NOTHING —
     * last_error stays as it was and last_result keeps the old rows.
     */
    final class RunReadFakeWpdb
    {
        public string $last_error = '';

        /** @var list<object> */
        public array $last_result = [];

        /** @var array{ok: int|false, error: string, rows: list<object>|null} */
        public array $next = ['ok' => 0, 'error' => '', 'rows' => []];

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
    }
}
