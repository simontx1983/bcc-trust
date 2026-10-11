<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\AdminDashboardRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * "Nothing happened" and "we could not look" are different facts.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 * Every aggregate on the Overview tab is `(int) $wpdb->get_var(...)`, and
 * `get_var()` returns null both for "no rows" and for "the query did not
 * run". `(int) null` is 0 — and the tab paints a 0 for New High-Fraud and
 * Suspensions GREEN. So a database the dashboard could not read was shown
 * as an all-clear.
 *
 * ⚠⚠ AND IT WAS CACHED. `getOverviewData()` wrote the zeroed result to the
 * object cache AND to the OVERVIEW_SNAPSHOT_OPTION row with a fresh
 * `computed_at`, so the fabricated all-clear was served for the whole TTL
 * window — including after the database recovered.
 *
 * ── WHY THESE ARE INTEGRATION TESTS ─────────────────────────────────────
 * The distinction only exists against a real driver: it is the difference
 * between a query that returns no rows and a query that never ran, and
 * `last_error` is the only thing that separates them. A stubbed repository
 * returning 0 twice proves nothing.
 *
 * ⚠ The fault is injected through the harness's `failQueriesMatching`
 * rather than by renaming a table, and that choice is load-bearing: a
 * renamed table is RE-CREATED EMPTY by the next schema pass, after which
 * the read SUCCEEDS and returns a genuine 0. A rename therefore tests
 * something else entirely. (Measured in the disposable container: four
 * renamed tables came back as empty tables and no read failure was logged
 * at all.) Matching on the query text also lets a SINGLE aggregate fail
 * while its siblings succeed, which is what proves the verdict is
 * per-read rather than blanket.
 */
#[Group('integration')]
#[Group('mariadb')]
final class AdminDashboardReadFailureIntegrationTest extends TestCase
{
    private const SNAPSHOT_OPTION = 'bcc_trust_admin_overview_snapshot';
    private const CACHE_GROUP     = 'bcc_trust_admin';

    private AdminDashboardRepository $repo;

    protected function setUp(): void
    {
        $this->ensureTables();
        $this->resetCaches();

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();

        // Three users, one of them above the high-fraud threshold, so a
        // later 0 is distinguishable from the real figure.
        $userInfo = $wpdb->prefix . 'bcc_trust_user_info';
        $wpdb->query("DELETE FROM `{$userInfo}`");
        // ⚠ user_login / user_email / display_name are NOT NULL with no
        // default. Omitting them makes the INSERT fail — and `$wpdb->query()`
        // reports that by returning false, which nothing here would notice.
        // The seeded count is asserted below for exactly that reason: a
        // silently empty fixture would otherwise look like a read failure
        // and every discrimination case would pass for the wrong reason.
        foreach ([[901, 95], [902, 10], [903, 0]] as [$uid, $score]) {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO `{$userInfo}`
                    (user_id, user_login, user_email, display_name, fraud_score, updated_at)
                 VALUES (%d, %s, %s, %s, %d, NOW())",
                $uid,
                'fixture' . $uid,
                'fixture' . $uid . '@example.test',
                'Fixture ' . $uid,
                $score
            ));
        }

        $seeded = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$userInfo}`");
        self::assertSame(3, $seeded, 'fixture precondition: three users must actually be seeded');

        $this->repo = new AdminDashboardRepository();
    }

    protected function tearDown(): void
    {
        // A failed test must not leave a fault armed for the next one.
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->resetCaches();
    }

    private function resetCaches(): void
    {
        delete_option(self::SNAPSHOT_OPTION);
        wp_cache_delete('overview_stats', self::CACHE_GROUP);
        wp_cache_delete('overview_refresh_lock', self::CACHE_GROUP);
    }

    /**
     * The integration bootstrap creates `user_info` but not the other seven
     * tables these aggregates read. Minimal fixtures, only the columns the
     * queries actually name — enough for the healthy control to be a real
     * measurement rather than a missing-table failure in disguise.
     */
    private function ensureTables(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $p    = $wpdb->prefix;

        $ddl = [
            "{$p}bcc_trust_votes" =>
                'id BIGINT AUTO_INCREMENT PRIMARY KEY, status TINYINT NOT NULL DEFAULT 1, created_at DATETIME NULL',
            "{$p}bcc_trust_attestations" =>
                'id BIGINT AUTO_INCREMENT PRIMARY KEY, kind VARCHAR(32) NOT NULL DEFAULT \'vouch\', revoked_at DATETIME NULL',
            "{$p}bcc_trust_device_fingerprints" =>
                'id BIGINT AUTO_INCREMENT PRIMARY KEY, fingerprint VARCHAR(64) NOT NULL, automation_score INT NOT NULL DEFAULT 0',
            "{$p}bcc_trust_fraud_analysis" =>
                'id BIGINT AUTO_INCREMENT PRIMARY KEY, analyzed_at DATETIME NULL',
            "{$p}bcc_page_read_model" =>
                'page_id BIGINT PRIMARY KEY, reputation_tier VARCHAR(16) NOT NULL DEFAULT \'neutral\'',
            "{$p}bcc_trust_suspensions" =>
                'id BIGINT AUTO_INCREMENT PRIMARY KEY, suspended_at DATETIME NULL, unsuspended_at DATETIME NULL',
            "{$p}bcc_trust_activity" =>
                'id BIGINT AUTO_INCREMENT PRIMARY KEY, action VARCHAR(64) NOT NULL DEFAULT \'\', created_at DATETIME NULL',
        ];

        foreach ($ddl as $table => $cols) {
            $wpdb->query("CREATE TABLE IF NOT EXISTS `{$table}` ({$cols})");
        }
    }

    /**
     * Arm a fault on every query whose text contains $needle.
     *
     * ⚠ `failQueriesMatching` is handed to preg_match(), so it is a REGEX
     * and not a substring. Passing a bare table name would make the match
     * fail silently, inject nothing, and leave the anti-vacuity case below
     * passing for entirely the wrong reason. The delimiters and the quoting
     * are therefore not cosmetic.
     */
    private function failReadsMatching(string $needle): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $GLOBALS['wpdb']->failQueriesMatching = '/' . preg_quote($needle, '/') . '/';
    }

    // ── 0. CONTROLS: the healthy path works, so a later failure means something
    // ═══════════════════════════════════════════════════════════════════════

    public function testAHealthyComputationReportsOk(): void
    {
        $data = $this->repo->getOverviewData();

        self::assertTrue($data['ok'], 'a healthy computation must report ok');
        self::assertArrayNotHasKey('stale', $data, 'a fresh computation is not stale');
    }

    public function testAHealthyComputationReturnsTheRealUserCount(): void
    {
        // ⚠ THE CONTROL THAT GIVES EVERY LATER ZERO ITS MEANING. Without a
        // case where the read succeeds and the answer is 3, a 0 elsewhere
        // could be either a fabrication or the truth.
        $data = $this->repo->getOverviewData();

        self::assertSame(3, $data['counts']['total_users']);
    }

    public function testAGenuinelyEmptyTableIsZeroAndStillOk(): void
    {
        // ⚠ The second half of the control: 0 is a legitimate answer, and
        // the fix must not start calling it a failure. `votes` is empty.
        $data = $this->repo->getOverviewData();

        self::assertTrue($data['ok']);
        self::assertSame(0, $data['counts']['total_votes'], 'an empty table really is zero');
    }

    public function testAHealthyTodaySummaryReportsOk(): void
    {
        $today = $this->repo->getTodaySummary();

        self::assertTrue($today['ok']);
        self::assertSame(0, $today['today_suspensions']);
    }

    // ── 1. ⚠⚠ A FAILED READ IS DISTINGUISHABLE FROM A ZERO
    // ═══════════════════════════════════════════════════════════════════════

    public function testAFailedAggregateMakesTheComputationNotOk(): void
    {
        $this->failReadsMatching('bcc_trust_user_info');

        $data = $this->repo->getOverviewData();

        self::assertFalse($data['ok'], 'a failed read must not be reported as ok');
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the fault must actually have fired');
    }

    public function testTheSameCallThatFailsWhenBrokenReportsOkWhenReadable(): void
    {
        // ⚠ The pair that proves the two states are TOLD APART rather than
        // one of them simply never occurring. Same repository, same method,
        // same database — only the injected fault differs.
        $this->failReadsMatching('bcc_trust_user_info');
        self::assertFalse($this->repo->getOverviewData()['ok'], 'broken must report not-ok');

        $GLOBALS['wpdb']->clearFaultInjection();
        $this->resetCaches();

        $recovered = $this->repo->getOverviewData();
        self::assertTrue($recovered['ok'], 'readable must report ok');
        self::assertSame(3, $recovered['counts']['total_users'], 'and the real number comes back');
    }

    public function testAFailedUserCountAndAnEmptyTableDoNotLookAlike(): void
    {
        // Both render as the integer 0 in the old code. The verdict is the
        // only thing that separates "three users we could not count" from
        // "no users".
        $healthy = $this->repo->getOverviewData();
        $this->resetCaches();

        $this->failReadsMatching('bcc_trust_user_info');
        $broken = $this->repo->getOverviewData();

        self::assertTrue($healthy['ok']);
        self::assertFalse($broken['ok']);
        self::assertSame(0, $healthy['counts']['total_votes'], 'a real zero');
        self::assertSame(0, $broken['counts']['total_users'], 'a fabricated zero — same value');
        self::assertNotSame(
            $healthy['ok'],
            $broken['ok'],
            'identical numbers, different verdicts: that is the whole fix'
        );
    }

    public function testTheVerdictIsPerReadRatherThanBlanket(): void
    {
        // ⚠ Anti-vacuity for the failure cases: the flag is not simply set
        // whenever anything at all goes wrong. A fault scoped to a table
        // THIS computation never reads must leave it ok.
        $this->failReadsMatching('a_table_this_dashboard_never_reads');

        $data = $this->repo->getOverviewData();

        self::assertTrue($data['ok'], 'an unrelated fault must not mark the dashboard unreadable');
        self::assertSame(0, $GLOBALS['wpdb']->injectedFailures, 'and nothing should have failed');
    }

    public function testAFailedTodayReadIsReportedSeparately(): void
    {
        $this->failReadsMatching('bcc_trust_suspensions');

        $today = $this->repo->getTodaySummary();

        self::assertFalse($today['ok']);
        self::assertSame(0, $today['today_suspensions'], 'still zero — the verdict is what changed');
    }

    // ── 2. ⚠⚠ A FAILED COMPUTATION IS NEVER CACHED OR PERSISTED
    // ═══════════════════════════════════════════════════════════════════════

    public function testAFailedComputationIsNotWrittenToTheSnapshotOption(): void
    {
        // The amplification: the old code persisted the zeroed result with a
        // fresh computed_at, so it outlived the fault.
        $this->failReadsMatching('bcc_trust_user_info');

        $this->repo->getOverviewData();

        self::assertFalse(
            get_option(self::SNAPSHOT_OPTION),
            'a failed computation must leave no snapshot behind'
        );
    }

    public function testAFailedComputationIsNotWrittenToTheObjectCache(): void
    {
        $this->failReadsMatching('bcc_trust_user_info');

        $this->repo->getOverviewData();

        self::assertFalse(
            wp_cache_get('overview_stats', self::CACHE_GROUP),
            'a failed computation must not be served to the next caller from cache'
        );
    }

    public function testASuccessfulComputationIsStillCachedAndSnapshotted(): void
    {
        // ⚠ ANTI-VACUITY for the two tests above: caching is not simply
        // disabled, so the no-cache rule is specific to failures.
        $this->repo->getOverviewData();

        $snapshot = get_option(self::SNAPSHOT_OPTION);

        self::assertIsArray($snapshot, 'a good computation is still persisted');
        self::assertSame(3, $snapshot['data']['counts']['total_users']);
        self::assertIsArray(
            wp_cache_get('overview_stats', self::CACHE_GROUP),
            'and still cached'
        );
    }

    // ── 3. A STALE-BUT-REAL SNAPSHOT BEATS A FABRICATED ZERO
    // ═══════════════════════════════════════════════════════════════════════

    public function testAFailedLiveReadFallsBackToTheStaleSnapshotRatherThanZeros(): void
    {
        // Compute a good snapshot, age it past its TTL, then break the live
        // read. A number the operator can date is worth more than a wrong one.
        $this->repo->getOverviewData();
        $snapshot = get_option(self::SNAPSHOT_OPTION);
        self::assertIsArray($snapshot, 'precondition: a good snapshot exists');

        $snapshot['computed_at'] = time() - 400;   // TTL is 300
        update_option(self::SNAPSHOT_OPTION, $snapshot, false);
        wp_cache_delete('overview_stats', self::CACHE_GROUP);

        $this->failReadsMatching('bcc_trust_user_info');
        $data = $this->repo->getOverviewData();

        self::assertSame(3, $data['counts']['total_users'], 'the real, stale number — not 0');
        self::assertTrue($data['stale'], 'and it is labelled stale');
        self::assertGreaterThanOrEqual(400, $data['stale_age'], 'with an age the operator can judge');
    }

    public function testWithNoSnapshotAFailedReadStaysNotOkRatherThanInventingAFallback(): void
    {
        // ⚠ Anti-vacuity for the stale path: the fallback is a real snapshot
        // or nothing. It must never manufacture a plausible-looking answer.
        $this->resetCaches();
        self::assertFalse(get_option(self::SNAPSHOT_OPTION), 'precondition: no snapshot');

        $this->failReadsMatching('bcc_trust_user_info');
        $data = $this->repo->getOverviewData();

        self::assertFalse($data['ok']);
        self::assertArrayNotHasKey('stale', $data, 'nothing stale to show, so nothing is claimed');
    }

    public function testTheStaleFallbackDoesNotOverwriteTheSnapshotItServed(): void
    {
        // Serving a stale snapshot must not re-stamp its computed_at, or the
        // snapshot would appear fresh forever while the database stayed down.
        $this->repo->getOverviewData();
        $snapshot = get_option(self::SNAPSHOT_OPTION);
        $snapshot['computed_at'] = time() - 400;
        update_option(self::SNAPSHOT_OPTION, $snapshot, false);
        wp_cache_delete('overview_stats', self::CACHE_GROUP);

        $this->failReadsMatching('bcc_trust_user_info');
        $this->repo->getOverviewData();

        $after = get_option(self::SNAPSHOT_OPTION);
        self::assertSame(
            $snapshot['computed_at'],
            $after['computed_at'],
            'the served snapshot keeps its original age'
        );
    }
}
