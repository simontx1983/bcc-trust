<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\AdminDashboardRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The Signals cluster: "we found nothing" and "we could not look" are
 * different facts, and the tabs used to print the first for both.
 *
 * ── WHAT THE TABS DID ───────────────────────────────────────────────────
 * Measured in a disposable authenticated browser, with each table replaced
 * by an invalid view so the read genuinely failed:
 *
 *   Fraud    "✓ No high-risk users detected."
 *            "All users are within acceptable fraud score thresholds."
 *   Devices  "No device records found."
 *   ML       "No patterns detected yet."
 *   Rings    "✓ No vote rings detected."
 *
 * Every row vanished (2 -> 0, 5 -> 0, 4 -> 0), not one page carried the
 * words "could not" or "unavailable", and there was no PHP diagnostic. Two
 * of those sentences carry a TICK.
 *
 * ── ⚠ WHY get_results() IS THE TRAP ─────────────────────────────────────
 * `wpdb::get_results()` returns `last_result`, and `flush()` resets that to
 * `array()` at the start of every query — so a query that never ran gives
 * back exactly what a query matching nothing gives back. `last_error` is
 * the only thing that separates them.
 *
 * Worth stating because the alternative was plausible and WRONG: if
 * get_results() returned null, `count($users)` in the repository would
 * THROW, and these tabs would have been loud white screens rather than
 * quiet lies. Checked against this build's class-wpdb.php, not assumed.
 *
 * ── ⚠⚠ RINGS IS A DIFFERENT FAILURE CLASS ───────────────────────────────
 * `getRingsData()` reads no database at all. Rings are computed hourly by
 * the cron and left in the object cache for this page to read, so an absent
 * snapshot means detection has not run — which is not "no rings exist".
 * Its verdict key is therefore `available`, not `ok`: it is not an error,
 * and on a fresh install "no snapshot yet" is the correct state.
 */
#[Group('integration')]
#[Group('mariadb')]
final class SignalsTabReadFailureIntegrationTest extends TestCase
{
    private const CACHE_GROUP = 'bcc_trust_admin';

    private AdminDashboardRepository $repo;

    protected function setUp(): void
    {
        $this->ensureTables();

        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        wp_cache_delete('ring_results', self::CACHE_GROUP);

        $p = $wpdb->prefix;

        // One user above the high-fraud threshold, so a later empty list is
        // distinguishable from the truth.
        $wpdb->query("DELETE FROM `{$p}bcc_trust_user_info`");
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$p}bcc_trust_user_info`
                (user_id, user_login, user_email, display_name, fraud_score, updated_at)
             VALUES (%d, %s, %s, %s, %d, NOW())",
            951,
            'sigfixture951',
            'sigfixture951@example.test',
            'Signals Fixture',
            BCC_TRUST_FRAUD_HIGH + 30
        ));

        $wpdb->query("DELETE FROM `{$p}bcc_trust_device_fingerprints`");
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$p}bcc_trust_device_fingerprints` (user_id, fingerprint, automation_score)
             VALUES (%d, %s, %d)",
            951,
            'sig-fp-0001',
            95
        ));

        $wpdb->query("DELETE FROM `{$p}bcc_trust_patterns`");
        $wpdb->query($wpdb->prepare(
            "INSERT INTO `{$p}bcc_trust_patterns` (user_id, pattern_type, pattern_data, confidence, detected_at)
             VALUES (%d, %s, %s, %f, NOW())",
            951,
            'burst_voting',
            '{"n":12}',
            0.9
        ));

        // ⚠ Fixture preconditions, asserted. `$wpdb->query()` reports a failed
        // INSERT only by returning false, and a silently empty fixture would
        // make every discrimination case below pass for the wrong reason.
        self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$p}bcc_trust_user_info`"));
        self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$p}bcc_trust_device_fingerprints`"));
        self::assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$p}bcc_trust_patterns`"));

        $this->repo = new AdminDashboardRepository();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        wp_cache_delete('ring_results', self::CACHE_GROUP);
    }

    /**
     * Minimal fixtures for the tables these four methods read.
     *
     * ⚠ `fraud_analysis` gets `user_id` added if missing. Another suite in
     * this shared database creates that table with only (id, analyzed_at),
     * and `CREATE TABLE IF NOT EXISTS` will not widen it — so without this
     * the fraud subquery fails on an unknown column, every verdict is false,
     * and the discrimination cases pass while proving nothing. The healthy
     * control below is what catches that, and it caught it here.
     */
    private function ensureTables(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $p    = $wpdb->prefix;

        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}bcc_trust_patterns` (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                pattern_type VARCHAR(50) NOT NULL,
                pattern_data TEXT NOT NULL,
                confidence FLOAT NULL,
                detected_at DATETIME NULL,
                expires_at DATETIME NULL
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}bcc_trust_device_fingerprints` (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                fingerprint VARCHAR(64) NOT NULL,
                automation_score INT NOT NULL DEFAULT 0,
                ip_address VARCHAR(45) NULL,
                last_seen DATETIME NULL
            )"
        );
        $wpdb->query(
            "CREATE TABLE IF NOT EXISTS `{$p}bcc_trust_fraud_analysis` (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                analyzed_at DATETIME NULL
            )"
        );
        $hasUserId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'user_id'",
            $p . 'bcc_trust_fraud_analysis'
        ));
        if ($hasUserId === 0) {
            $wpdb->query("ALTER TABLE `{$p}bcc_trust_fraud_analysis` ADD COLUMN user_id BIGINT UNSIGNED NOT NULL DEFAULT 0");
        }
    }

    private function failReadsMatching(string $needle): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        // preg_match, so a regex — a bare table name would inject nothing and
        // leave the anti-vacuity case passing for the wrong reason.
        $GLOBALS['wpdb']->failQueriesMatching = '/' . preg_quote($needle, '/') . '/';
    }

    // ── 0. CONTROLS: the healthy path works, so a later failure means something
    // ═══════════════════════════════════════════════════════════════════════

    public function testHealthyFraudDataIsOkAndFindsTheHighRiskUser(): void
    {
        // ⚠ THE CONTROL THAT GIVES EVERY LATER EMPTY LIST ITS MEANING, and the
        // one that catches a broken fixture rather than reporting a false pass.
        $data = $this->repo->getFraudData();

        self::assertTrue($data['ok'], 'a healthy fraud read must report ok');
        self::assertCount(1, $data['fraud_users'], 'the seeded high-risk user must be found');
    }

    public function testHealthyDevicesDataIsOkAndFindsTheDevice(): void
    {
        $data = $this->repo->getDevicesData();

        self::assertTrue($data['ok']);
        self::assertCount(1, $data['devices']);
    }

    public function testHealthyMlDataIsOkAndFindsThePattern(): void
    {
        $data = $this->repo->getMlData();

        self::assertTrue($data['ok']);
        self::assertCount(1, $data['patterns']);
    }

    public function testAGenuinelyEmptyTableIsEmptyAndStillOk(): void
    {
        // ⚠ The other half of the control: an empty list is a legitimate
        // answer and the fix must not start calling it a failure.
        $p = $GLOBALS['wpdb']->prefix;
        $GLOBALS['wpdb']->query("DELETE FROM `{$p}bcc_trust_patterns`");

        $data = $this->repo->getMlData();

        self::assertTrue($data['ok'], 'no rows is not a read failure');
        self::assertSame([], $data['patterns']);
    }

    // ── 1. ⚠⚠ AN UNREADABLE TABLE IS DISTINGUISHABLE FROM AN EMPTY ONE
    // ═══════════════════════════════════════════════════════════════════════

    public function testAFailedFraudReadIsNotOk(): void
    {
        $this->failReadsMatching('bcc_trust_user_info');

        $data = $this->repo->getFraudData();

        self::assertFalse($data['ok'], '"✓ No high-risk users detected" must not be printable from this');
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the fault must actually have fired');
    }

    public function testAFailedDevicesReadIsNotOk(): void
    {
        $this->failReadsMatching('bcc_trust_device_fingerprints');

        self::assertFalse($this->repo->getDevicesData()['ok']);
    }

    public function testAFailedMlReadIsNotOk(): void
    {
        $this->failReadsMatching('bcc_trust_patterns');

        self::assertFalse($this->repo->getMlData()['ok']);
    }

    public function testTheSameReadThatFailsWhenBrokenIsOkWhenReadable(): void
    {
        // ⚠ The pair that proves the two states are TOLD APART rather than one
        // of them never occurring. Same repository, same method, same rows.
        $this->failReadsMatching('bcc_trust_device_fingerprints');
        self::assertFalse($this->repo->getDevicesData()['ok'], 'broken must be not-ok');

        $GLOBALS['wpdb']->clearFaultInjection();

        $recovered = $this->repo->getDevicesData();
        self::assertTrue($recovered['ok'], 'readable must be ok');
        self::assertCount(1, $recovered['devices'], 'and the row comes back');
    }

    public function testAnEmptyListAndAFailedReadCarryDifferentVerdicts(): void
    {
        $p = $GLOBALS['wpdb']->prefix;
        $GLOBALS['wpdb']->query("DELETE FROM `{$p}bcc_trust_device_fingerprints`");
        $empty = $this->repo->getDevicesData();

        $this->failReadsMatching('bcc_trust_device_fingerprints');
        $broken = $this->repo->getDevicesData();

        self::assertSame([], $empty['devices'], 'a real empty list');
        self::assertSame([], $broken['devices'], 'a fabricated empty list — same value');
        self::assertTrue($empty['ok']);
        self::assertFalse($broken['ok'], 'identical data, different verdicts: that is the fix');
    }

    public function testTheVerdictIsPerMethodRatherThanBlanket(): void
    {
        // ⚠ Anti-vacuity: the flag is not simply set whenever anything is
        // wrong. A fault on the patterns table must leave DEVICES readable.
        $this->failReadsMatching('bcc_trust_patterns');

        self::assertFalse($this->repo->getMlData()['ok'], 'ml reads patterns');
        self::assertTrue($this->repo->getDevicesData()['ok'], 'devices does not');
    }

    public function testAnUnrelatedFaultLeavesEveryVerdictOk(): void
    {
        $this->failReadsMatching('a_table_no_signals_tab_reads');

        self::assertTrue($this->repo->getFraudData()['ok']);
        self::assertTrue($this->repo->getDevicesData()['ok']);
        self::assertTrue($this->repo->getMlData()['ok']);
        self::assertSame(0, $GLOBALS['wpdb']->injectedFailures, 'and nothing should have failed');
    }

    // ── 2. RINGS: AN ABSENT SNAPSHOT IS NOT "NO RINGS"
    // ═══════════════════════════════════════════════════════════════════════

    public function testAnAbsentRingSnapshotIsReportedUnavailable(): void
    {
        wp_cache_delete('ring_results', self::CACHE_GROUP);

        $data = $this->repo->getRingsData();

        self::assertFalse($data['available'], '"✓ No vote rings detected" must not be printable from this');
        self::assertSame([], $data['rings']);
    }

    public function testAnEmptyRingSnapshotIsAvailableAndReallyMeansNoRings(): void
    {
        // ⚠ ANTI-VACUITY, and the case that keeps the tick honest: when
        // detection HAS run and found nothing, the tab must still be able to
        // say so. Without this, `available` could just be hardcoded false.
        wp_cache_set('ring_results', [], self::CACHE_GROUP, 3600);

        $data = $this->repo->getRingsData();

        self::assertTrue($data['available'], 'a snapshot that ran and found nothing IS available');
        self::assertSame([], $data['rings']);
    }

    public function testAPopulatedRingSnapshotIsAvailableAndCarriesItsRings(): void
    {
        wp_cache_set('ring_results', [['members' => [1, 2, 3]]], self::CACHE_GROUP, 3600);

        $data = $this->repo->getRingsData();

        self::assertTrue($data['available']);
        self::assertCount(1, $data['rings']);
    }

    public function testRingsNeverConsultsTheDatabase(): void
    {
        // ⚠ The property the `available` wording depends on: if this method
        // ever grew a query, "detection has not run" would stop being the
        // right explanation for an empty result.
        $this->failReadsMatching('bcc_');
        wp_cache_set('ring_results', [], self::CACHE_GROUP, 3600);

        $data = $this->repo->getRingsData();

        self::assertTrue($data['available'], 'a total database fault cannot affect rings');
        self::assertSame(0, $GLOBALS['wpdb']->injectedFailures, 'because it issues no query at all');
    }
}
