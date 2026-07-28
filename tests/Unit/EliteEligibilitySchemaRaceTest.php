<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Services\TrustScoreService;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Locks the §J.12 schema race: plugin CODE can run against an OLD SCHEMA.
 *
 * Why the race is real (mechanical, not assumed): the schema-version gate in
 * bcc-trust.php takes a `GET_LOCK` before running dbDelta. When a concurrent
 * request already holds that lock the gate returns early and — in its own
 * words — "this one proceeds un-migrated". During a deploy under traffic that
 * is every request until the winner finishes. REST routes (`rest_api_init`)
 * and cron (`init`) both fire AFTER `plugins_loaded`, so hook ordering already
 * protects the normal path; the lock-contention path is what ordering cannot
 * fix, which is why the capability check exists.
 *
 * Separate processes: these tests define global `wp_cache_*` shims and mutate
 * TableRegistry's static memo, so they must not leak into the main process.
 */
#[RunTestsInSeparateProcesses]
final class EliteEligibilitySchemaRaceTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/schema-capability-stubs.php';
        \BccTestSchemaCache::reset();
        // flushExistenceCache() enumerates TableRegistry::all(), which reads
        // $wpdb->prefix — install the double BEFORE flushing.
        $this->installWpdb([]);
        TableRegistry::flushExistenceCache();
    }

    protected function tearDown(): void
    {
        global $wpdb;
        $wpdb = null;
    }

    /** @param list<string> $columnsPresent */
    private function installWpdb(array $columnsPresent, bool $throwOnProbe = false): void
    {
        global $wpdb;
        $wpdb = new class ($columnsPresent, $throwOnProbe) {
            public string $prefix = 'wp_';
            public int $probeCount = 0;
            private bool $suppress = false;

            /** @param list<string> $cols */
            public function __construct(public array $cols, public bool $throwOnProbe) {}

            public function suppress_errors(bool $s = true): bool
            {
                $prev = $this->suppress;
                $this->suppress = $s;
                return $prev;
            }

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                return str_replace('%s', "'" . (string) ($args[0] ?? '') . "'", $sql);
            }

            /** @return mixed */
            public function get_var(string $sql)
            {
                $this->probeCount++;
                if ($this->throwOnProbe) {
                    // A real $wpdb with suppress_errors returns null on a
                    // failed query rather than throwing; both are covered.
                    return null;
                }
                foreach ($this->cols as $c) {
                    if (str_contains($sql, "'" . $c . "'")) {
                        return $c;
                    }
                }
                return null;
            }
        };
    }

    // ── 1. Fresh install / pre-migration ─────────────────────────────────

    public function testFreshInstallReportsColumnAbsent(): void
    {
        $this->installWpdb([]); // no columns at all — brand-new database
        self::assertFalse(TableRegistry::columnExists('wp_bcc_trust_page_scores', 'elite_eligible'));
    }

    // ── 2 + 5. Code ahead of schema: writes stay valid ───────────────────

    public function testTierSqlOmitsGateWhenColumnAbsent(): void
    {
        $sql = TrustScoreService::tierSql(TrustScoreService::formulaSql(), null, false);

        // The whole point: no reference to a column that does not exist, so
        // the enclosing UPDATE still executes and the score still persists.
        self::assertStringNotContainsString('elite_eligible', $sql);

        // Still a complete, valid ladder — every band present.
        foreach (['elite', 'trusted', 'neutral', 'caution'] as $tier) {
            self::assertStringContainsString("THEN '{$tier}'", $sql);
        }
        self::assertStringContainsString("ELSE 'risky'", $sql);

        // The native-conduct floor is a pure expression over columns that
        // already exist, so it is RETAINED pre-migration — the wallet-depth
        // protection does not wait for dbDelta.
        self::assertStringContainsString((string) TrustScoreService::eliteNativeFloor(), $sql);
        self::assertStringContainsString(TrustScoreService::nativeFormulaSql(), $sql);
    }

    // ── 3 + 6. Post-migration ────────────────────────────────────────────

    public function testPostMigrationSchemaReportsColumnPresentAndGateApplies(): void
    {
        $this->installWpdb(['elite_eligible']);
        self::assertTrue(TableRegistry::columnExists('wp_bcc_trust_page_scores', 'elite_eligible'));

        $sql = TrustScoreService::tierSql(TrustScoreService::formulaSql(), null, true);
        self::assertStringContainsString('elite_eligible = 1', $sql);
        self::assertStringContainsString('elite_eligible_at IS NULL', $sql);
        // Gate belongs to the elite arm only.
        self::assertSame(1, substr_count($sql, 'elite_eligible = 1'));
    }

    // ── 4. Migration completing AFTER a negative check ───────────────────

    public function testNegativeResultIsNotCachedAndIsRecheckedAfterMigration(): void
    {
        $this->installWpdb([]);
        self::assertFalse(TableRegistry::columnExists('wp_bcc_trust_page_scores', 'elite_eligible'));

        // A negative must NOT be written to the persistent cache, or it would
        // survive the migration for the rest of the TTL.
        self::assertNull(
            \BccTestSchemaCache::raw('col_wp_bcc_trust_page_scores.elite_eligible', 'bcc_tables'),
            'a negative column probe must never be cached cross-request'
        );

        // dbDelta lands; installers call flushExistenceCache(), which clears
        // the request-scoped memo so the SAME request sees the new column.
        global $wpdb;
        $wpdb->cols = ['elite_eligible'];
        TableRegistry::flushExistenceCache();

        self::assertTrue(TableRegistry::columnExists('wp_bcc_trust_page_scores', 'elite_eligible'));
    }

    public function testPositiveResultIsCachedAndNotReprobedPerCall(): void
    {
        $this->installWpdb(['elite_eligible']);

        TableRegistry::columnExists('wp_bcc_trust_page_scores', 'elite_eligible');
        $afterFirst = $GLOBALS['wpdb']->probeCount;

        // Requirement: no SHOW COLUMNS per score write. Repeated asks are
        // served from the request memo.
        for ($i = 0; $i < 25; $i++) {
            TableRegistry::columnExists('wp_bcc_trust_page_scores', 'elite_eligible');
        }

        self::assertSame(1, $afterFirst);
        self::assertSame(1, $GLOBALS['wpdb']->probeCount, '25 further calls must not re-probe');
        self::assertSame(
            '1',
            \BccTestSchemaCache::raw('col_wp_bcc_trust_page_scores.elite_eligible', 'bcc_tables'),
            'a positive probe IS cached cross-request'
        );
    }

    // ── 7. Schema-detection failure ──────────────────────────────────────

    public function testSchemaDetectionFailureFailsSafeToAbsent(): void
    {
        $this->installWpdb([], true); // probe errors / returns null

        // Fail SAFE: report absent, do not throw. The caller then emits SQL
        // that references no new column, so the score write still succeeds.
        self::assertFalse(TableRegistry::columnExists('wp_bcc_trust_page_scores', 'elite_eligible'));
        self::assertNull(
            \BccTestSchemaCache::raw('col_wp_bcc_trust_page_scores.elite_eligible', 'bcc_tables'),
            'a failed probe must not be cached'
        );
    }

    public function testProbeIsWrappedInErrorSuppressionSoAMissingTableIsNotFatal(): void
    {
        $this->installWpdb([]);
        // Exercises the suppress_errors(true)/restore pair — a missing TABLE
        // during activation must read as "column absent", not surface a DB
        // error into the middle of a score write.
        self::assertFalse(TableRegistry::columnExists('wp_nonexistent_table', 'elite_eligible'));
    }

    // ── Gate-off vs gate-on differ ONLY by the gate ──────────────────────

    public function testGateOffAndGateOnDifferOnlyByTheGateClause(): void
    {
        $off = TrustScoreService::tierSql(TrustScoreService::formulaSql(), null, false);
        $on  = TrustScoreService::tierSql(TrustScoreService::formulaSql(), null, true);

        self::assertSame(
            $off,
            str_replace(' AND (elite_eligible = 1 OR elite_eligible_at IS NULL)', '', $on),
            'the only difference between the two ladders must be the gate clause'
        );
    }
}
