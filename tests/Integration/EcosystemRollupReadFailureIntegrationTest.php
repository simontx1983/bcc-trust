<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\AdminDashboardRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The Ecosystem journal: a quiet section must mean a quiet week, not a read
 * that did not run — and a half-read journal must not be cached.
 *
 * ── WHY THIS PANEL IS THE WORST PLACE TO BE WRONG ───────────────────────
 * It is a JOURNAL. The tab tells the operator in its own words to "read this
 * weekly, notice sustained patterns, ignore single spikes — healthy looks
 * boring." A failed aggregate arrived as an empty section, which is exactly
 * what a boring healthy week looks like. Then the half-empty rollup was
 * CACHED for the TTL, so the quiet week outlived the fault.
 *
 * ── FIVE READS, ONE VERDICT ─────────────────────────────────────────────
 * dispute_flow, watch_pulls, watch_graph, feed_authors and feed_kinds sit
 * across three private helpers. The flag is reset ONCE for the whole rollup,
 * because the operator-facing question is "can I trust this page", not
 * "which of five queries failed" — the specific aggregate is named in the
 * log instead.
 *
 * ⚠ PHPStan had to be told the three helpers are @phpstan-impure. They
 * mutate the flag through noteReadFailure(), and without the tag PHPStan
 * treats them as pure, concludes the flag cannot have changed since the
 * reset, and reports "Negated boolean expression is always true". It was
 * right: untagged, the verdict is structurally incapable of being false,
 * which is worse than having no verdict at all.
 */
#[Group('integration')]
#[Group('mariadb')]
final class EcosystemRollupReadFailureIntegrationTest extends TestCase
{
    private const CACHE_GROUP = 'bcc_trust_admin';
    private const CACHE_KEY   = 'ecosystem_rollup_7d';

    private AdminDashboardRepository $repo;

    protected function setUp(): void
    {
        $this->ensureTables();
        $GLOBALS['wpdb']->clearFaultInjection();
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);
        $this->repo = new AdminDashboardRepository();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);
    }

    /**
     * Minimal fixtures for the tables the five aggregates read.
     *
     * ⚠ Created only when absent, and never widened destructively: these
     * suites share one database, and a hand-rolled stand-in that shadows a
     * richer schema has already broken another suite once in this series.
     */
    private function ensureTables(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $p    = $wpdb->prefix;

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}bcc_disputes` (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                status VARCHAR(32) NOT NULL DEFAULT 'reviewing',
                created_at DATETIME NULL
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}bcc_watch_meta` (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                follow_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                watched_at DATETIME NULL
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}peepso_user_followers` (
                uf_id BIGINT AUTO_INCREMENT PRIMARY KEY,
                uf_passive_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                uf_follow TINYINT(1) NOT NULL DEFAULT 1
            )"
        );

        // ⚠ ecosystemTierMovement() borrows getOverviewData(), whose ELEVEN
        // reads touch tables this panel never mentions. Without them the
        // nested verdict is false, the rollup is false, and the healthy
        // control fails — which made this suite ORDER-DEPENDENT on whichever
        // other suite happened to create them first. Self-contained now:
        // created only when absent, so nothing richer is shadowed.
        foreach ([
            'bcc_trust_attestations' =>
                "id BIGINT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(32) NOT NULL DEFAULT 'vouch', revoked_at DATETIME NULL",
            'bcc_trust_device_fingerprints' =>
                'id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, fingerprint VARCHAR(64) NOT NULL, automation_score INT NOT NULL DEFAULT 0',
            'bcc_trust_fraud_analysis' =>
                'id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL DEFAULT 0, analyzed_at DATETIME NULL',
        ] as $suffix => $columns) {
            $wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}{$suffix}` ({$columns})");
        }

        // The read model is installed by the plugin's own installer, never a
        // hand-rolled stand-in: ecosystemTierMovement() borrows the Overview
        // aggregates, which read it, and a stunted copy would shadow the real
        // schema for every suite sharing this database.
        $readModel = \BCC\Trust\Core\Database\TableRegistry::pageReadModel();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $readModel)) !== $readModel) {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-project.php';
            \bcc_trust_create_page_tables();
        }
        self::assertSame(
            $readModel,
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $readModel)),
            'the read model must be installed by the plugin, not by this fixture'
        );

        // ⚠ wp_posts is created by the SHARED bootstrap with five columns
        // (ID, post_title, post_name, post_type, post_status). feed_dominance
        // also reads post_author and post_date_gmt, and without them the read
        // genuinely FAILS — "Unknown column 'post_date_gmt' in 'WHERE'" — so
        // every verdict is false and the discrimination cases pass while
        // proving nothing. The healthy control is what surfaced it.
        //
        // Columns are ADDED, never replaced: widening a shared table cannot
        // break a suite that does not select the new columns, whereas
        // redefining it could.
        foreach ([
            'post_author'   => 'BIGINT UNSIGNED NOT NULL DEFAULT 0',
            'post_date'     => 'DATETIME NULL',
            'post_date_gmt' => 'DATETIME NULL',
        ] as $column => $definition) {
            $exists = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
                $p . 'posts',
                $column
            ));
            if ($exists === 0) {
                $wpdb->query("ALTER TABLE `{$p}posts` ADD COLUMN `{$column}` {$definition}");
            }
        }

        // ⚠ ASSERT THE FIXTURES EXIST. `$wpdb->query()` reports a failed
        // CREATE only by returning false, so a fixture that never landed
        // would make the healthy control fail and every discrimination case
        // pass for the wrong reason. The same omission cost a debugging cycle
        // in the list-tab suite; the assertion is what names the cause.
        foreach (['bcc_disputes', 'bcc_watch_meta', 'peepso_user_followers'] as $suffix) {
            $table = $p . $suffix;
            self::assertSame(
                $table,
                $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)),
                "fixture precondition: {$table} must exist (last_error: {$wpdb->last_error})"
            );
        }
    }

    private function failReadsMatching(string $needle): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        // preg_match, so a regex — a bare table name injects nothing and
        // leaves the anti-vacuity case passing for the wrong reason.
        $GLOBALS['wpdb']->failQueriesMatching = '/' . preg_quote($needle, '/') . '/';
    }

    // ── 0. CONTROLS
    // ═══════════════════════════════════════════════════════════════════

    public function testAHealthyRollupReportsOk(): void
    {
        // ⚠ THE CONTROL THAT GIVES EVERY LATER false ITS MEANING. If the
        // fixtures were incomplete this would fail, which is how the
        // equivalent control caught a too-narrow fixture in the list-tab
        // suite rather than reporting a silent pass.
        $rollup = $this->repo->getEcosystemRollup(7);

        self::assertTrue($rollup['ok'], 'a healthy rollup must report ok');
        self::assertSame(7, $rollup['window_days']);
    }

    public function testAHealthyRollupCarriesEverySection(): void
    {
        $rollup = $this->repo->getEcosystemRollup(7);

        foreach (['slow_rings', 'dispute_flow', 'tier_movement', 'watch_graph', 'feed_dominance'] as $section) {
            self::assertArrayHasKey($section, $rollup, "section {$section} must be present");
        }
    }

    public function testEmptySourceTablesAreStillOk(): void
    {
        // ⚠ The other half of the control: a genuinely quiet week is not a
        // failure, and the fix must not start calling it one.
        $p = $GLOBALS['wpdb']->prefix;
        $GLOBALS['wpdb']->query("DELETE FROM `{$p}bcc_disputes`");
        $GLOBALS['wpdb']->query("DELETE FROM `{$p}bcc_watch_meta`");

        self::assertTrue($this->repo->getEcosystemRollup(7)['ok'], 'quiet is not broken');
    }

    // ── 1. ⚠⚠ ANY ONE FAILED AGGREGATE MAKES THE JOURNAL NOT OK
    // ═══════════════════════════════════════════════════════════════════

    public function testAFailedDisputeReadIsNotOk(): void
    {
        $this->failReadsMatching('bcc_disputes');

        $rollup = $this->repo->getEcosystemRollup(7);

        self::assertFalse($rollup['ok']);
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the fault must have fired');
    }

    public function testAFailedWatchReadIsNotOk(): void
    {
        $this->failReadsMatching('bcc_watch_meta');

        self::assertFalse($this->repo->getEcosystemRollup(7)['ok']);
    }

    public function testAFailedFeedReadIsNotOk(): void
    {
        // feed_dominance reads wp_posts.
        $this->failReadsMatching('posts');

        self::assertFalse($this->repo->getEcosystemRollup(7)['ok']);
    }

    public function testTheSameRollupThatFailsWhenBrokenIsOkWhenReadable(): void
    {
        // ⚠ The pair proving the two states are TOLD APART rather than one of
        // them never occurring. Same method, same window, same rows.
        $this->failReadsMatching('bcc_watch_meta');
        self::assertFalse($this->repo->getEcosystemRollup(7)['ok'], 'broken must be not-ok');

        $GLOBALS['wpdb']->clearFaultInjection();
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);

        self::assertTrue($this->repo->getEcosystemRollup(7)['ok'], 'readable must be ok');
    }

    public function testAnUnrelatedFaultLeavesTheRollupOk(): void
    {
        // ⚠ Anti-vacuity: the verdict is not simply false whenever anything
        // anywhere is wrong.
        $this->failReadsMatching('a_table_no_ecosystem_aggregate_reads');

        self::assertTrue($this->repo->getEcosystemRollup(7)['ok']);
        self::assertSame(0, $GLOBALS['wpdb']->injectedFailures, 'and nothing should have failed');
    }

    public function testTheVerdictIsNotStructurallyFixed(): void
    {
        // ⚠ THE CASE THAT PHPSTAN'S COMPLAINT WAS ABOUT. Without
        // @phpstan-impure on the helpers the verdict could only ever be true;
        // this asserts both values are actually reachable from the same
        // method, which no amount of reading the code would establish.
        $healthy = $this->repo->getEcosystemRollup(7)['ok'];
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);
        $this->failReadsMatching('bcc_watch_meta');
        $broken = $this->repo->getEcosystemRollup(7)['ok'];

        self::assertTrue($healthy);
        self::assertFalse($broken);
        self::assertNotSame($healthy, $broken, 'both verdicts must be reachable');
    }

    public function testAnEarlierFailureSurvivesTheNestedOverviewCall(): void
    {
        // ⚠⚠ THE DEFECT THIS SUITE WAS WRITTEN AND THEN REWRITTEN FOR.
        //
        // tier_movement delegates to getOverviewData(), which on a CACHE MISS
        // runs computeOverviewData() and resets $readFailed for its own
        // verdict. dispute_flow runs BEFORE it, so its failure was erased —
        // and only when the overview cache happened to be cold, which made
        // the rollup's verdict depend on unrelated cache state.
        //
        // Both caches are cleared here deliberately so the nested call MUST
        // take the compute path. Without that, the mutant "drop the
        // capture/restore" SURVIVED every other case in this file — the hole
        // was found by running it, not by reading the code.
        delete_option('bcc_trust_admin_overview_snapshot');
        wp_cache_delete('overview_stats', self::CACHE_GROUP);
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);

        $this->failReadsMatching('bcc_disputes');
        $rollup = $this->repo->getEcosystemRollup(7);

        self::assertFalse(
            $rollup['ok'],
            'a dispute-read failure must survive the nested overview call that resets the flag'
        );
    }

    public function testTheNestedOverviewVerdictIsFoldedIn(): void
    {
        // The other half: when the NESTED read is the one that fails, the
        // rollup must still report not-ok even though its own direct reads
        // all succeeded. tier_movement reads nothing itself.
        delete_option('bcc_trust_admin_overview_snapshot');
        wp_cache_delete('overview_stats', self::CACHE_GROUP);
        wp_cache_delete(self::CACHE_KEY, self::CACHE_GROUP);

        // user_info is read by the overview aggregates and by no ecosystem
        // aggregate, so this isolates the nested path.
        $this->failReadsMatching('bcc_trust_user_info');
        $rollup = $this->repo->getEcosystemRollup(7);

        self::assertFalse($rollup['ok'], 'the nested verdict must count');
    }

    // ── 2. ⚠⚠ A FAILED ROLLUP IS NEVER CACHED
    // ═══════════════════════════════════════════════════════════════════

    public function testAFailedRollupIsNotCached(): void
    {
        // The amplification the overview snapshot had (#300): caching a
        // half-empty journal freezes the quiet week in for the TTL and keeps
        // serving it after the database recovers.
        $this->failReadsMatching('bcc_watch_meta');

        $this->repo->getEcosystemRollup(7);

        self::assertFalse(
            wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP),
            'an incomplete journal must leave no cache entry'
        );
    }

    public function testASuccessfulRollupIsStillCached(): void
    {
        // ⚠ ANTI-VACUITY for the test above: caching is not simply disabled,
        // so the no-cache rule is specific to failures.
        $this->repo->getEcosystemRollup(7);

        $cached = wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP);

        self::assertIsArray($cached, 'a complete journal is still cached');
        self::assertTrue($cached['ok'], 'and what is cached carries a true verdict');
    }

    public function testACachedRollupIsServedWithoutReReading(): void
    {
        // Establishes that the cache is load-bearing, which is what makes
        // "not cached on failure" a meaningful property rather than a no-op.
        $this->repo->getEcosystemRollup(7);
        self::assertIsArray(wp_cache_get(self::CACHE_KEY, self::CACHE_GROUP));

        // With every read broken, a cache hit must still answer.
        $this->failReadsMatching('bcc_');
        $second = $this->repo->getEcosystemRollup(7);

        self::assertTrue($second['ok'], 'served from cache, so unaffected by the fault');
        self::assertSame(0, $GLOBALS['wpdb']->injectedFailures, 'and it issued no query at all');
    }
}
