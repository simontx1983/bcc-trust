<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Repositories\AdminDashboardRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Users ▸ Verified: "No verified users found" must not be printable from a
 * read that did not run, and "Total Verified: 0" must not be a guess.
 *
 * ── WHY A WRAPPER RATHER THAN THREE SIGNATURE CHANGES ───────────────────
 * getVerifiedUsersCount() returns a bare int and getVerifiedUsersFiltered()
 * a bare list; neither can carry a verdict without changing a PUBLIC
 * contract. Tracing callers first found exactly one each — the admin view —
 * so a change would be safe today, but "safe today" is not a reason to
 * narrow a public signature. getVerifiedPanelData() is additive and
 * admin-specific; the three originals are untouched and still mean what
 * they meant. These tests assert BOTH halves of that: the new verdict
 * works, and the old contracts are unchanged.
 *
 * ⚠ getVerifiedSummaryStats() resets the shared flag for its own verdict,
 * so the wrapper calls it FIRST and folds its `ok` in separately. A nested
 * reset silently erasing an earlier failure is a defect this codebase has
 * already produced once, in the Ecosystem rollup.
 */
#[Group('integration')]
#[Group('mariadb')]
final class VerifiedPanelReadFailureIntegrationTest extends TestCase
{
    private AdminDashboardRepository $repo;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        $p = $wpdb->prefix;

        // The verifications table is installed by the plugin's own installer
        // where one exists; a hand-rolled stand-in would shadow the real
        // schema for every suite sharing this database.
        $table = \BCC\Trust\Core\Database\TableRegistry::userVerifications();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            require_once dirname(__DIR__, 2) . '/includes/database/schema-user-verifications.php';
            \bcc_trust_create_user_verifications_table();
        }
        self::assertSame(
            $table,
            $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)),
            'the verifications table must be installed by the plugin'
        );

        $wpdb->query("CREATE TABLE IF NOT EXISTS `{$p}bcc_trust_user_info` (
            id BIGINT AUTO_INCREMENT PRIMARY KEY, user_id BIGINT UNSIGNED NOT NULL,
            user_login VARCHAR(60) NOT NULL DEFAULT '', user_email VARCHAR(100) NOT NULL DEFAULT '',
            display_name VARCHAR(250) NOT NULL DEFAULT '', fraud_score INT NULL DEFAULT 0,
            updated_at DATETIME NULL)");

        $this->repo = new AdminDashboardRepository();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
    }

    private function failReadsMatching(string $needle): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        // preg_match, so a regex — a bare table name injects nothing.
        $GLOBALS['wpdb']->failQueriesMatching = '/' . preg_quote($needle, '/') . '/';
    }

    /** @return array{ok: bool, total: int, users: list<object>, summary: array<string, mixed>} */
    private function panel(): array
    {
        return $this->repo->getVerifiedPanelData('1=1', [], 'verified_at', 'DESC', 20, 0);
    }

    // ── 0. CONTROLS
    // ═══════════════════════════════════════════════════════════════════

    public function testAHealthyPanelIsOk(): void
    {
        // ⚠ THE CONTROL. Without it every later false could be a broken
        // fixture rather than a detected failure — which is how the
        // equivalent control caught three fixture faults in this series.
        self::assertTrue($this->panel()['ok']);
    }

    public function testAnEmptyTableIsZeroAndStillOk(): void
    {
        // ⚠ The other half: zero verified users is a legitimate answer and
        // the fix must not start calling it a failure. "No verified users
        // found" is CORRECT here and must stay printable.
        $p = $GLOBALS['wpdb']->prefix;
        $GLOBALS['wpdb']->query('DELETE FROM `' . \BCC\Trust\Core\Database\TableRegistry::userVerifications() . '`');

        $panel = $this->panel();

        self::assertTrue($panel['ok'], 'no rows is not a read failure');
        self::assertSame(0, $panel['total']);
        self::assertSame([], $panel['users']);
    }

    public function testTheSummaryCarriesItsOwnVerdict(): void
    {
        $summary = $this->repo->getVerifiedSummaryStats();

        self::assertArrayHasKey('ok', $summary);
        self::assertTrue($summary['ok']);
    }

    // ── 1. ⚠⚠ DISCRIMINATION
    // ═══════════════════════════════════════════════════════════════════

    public function testAFailedVerificationsReadIsNotOk(): void
    {
        $this->failReadsMatching('user_verifications');

        $panel = $this->panel();

        self::assertFalse($panel['ok'], '"No verified users found" must not be printable from this');
        self::assertGreaterThan(0, $GLOBALS['wpdb']->injectedFailures, 'the fault must have fired');
    }

    public function testTheSameCallThatFailsWhenBrokenIsOkWhenReadable(): void
    {
        // ⚠ The pair proving the two states are TOLD APART rather than one
        // never occurring.
        $this->failReadsMatching('user_verifications');
        self::assertFalse($this->panel()['ok'], 'broken must be not-ok');

        $GLOBALS['wpdb']->clearFaultInjection();
        self::assertTrue($this->panel()['ok'], 'readable must be ok');
    }

    public function testAnEmptyResultAndAFailedReadCarryDifferentVerdicts(): void
    {
        $GLOBALS['wpdb']->query('DELETE FROM `' . \BCC\Trust\Core\Database\TableRegistry::userVerifications() . '`');
        $empty = $this->panel();

        $this->failReadsMatching('user_verifications');
        $broken = $this->panel();

        self::assertSame(0, $empty['total']);
        self::assertSame(0, $broken['total'], 'same number');
        self::assertTrue($empty['ok']);
        self::assertFalse($broken['ok'], 'different verdicts: that is the fix');
    }

    public function testAnUnrelatedFaultLeavesThePanelOk(): void
    {
        // ⚠ Anti-vacuity: not simply false whenever anything is wrong.
        $this->failReadsMatching('a_table_the_verified_panel_never_reads');

        self::assertTrue($this->panel()['ok']);
        self::assertSame(0, $GLOBALS['wpdb']->injectedFailures);
    }

    public function testASummaryFailureAloneMakesThePanelNotOk(): void
    {
        // ⚠ THE NESTED-RESET CASE. getVerifiedSummaryStats() resets the
        // shared flag for its own verdict, so if the wrapper consulted only
        // the flag afterwards a summary-only failure would be invisible.
        // Both its verdict and the flag have to count.
        $this->failReadsMatching('AVG(');   // AVG( appears ONLY in the summary queries

        $panel = $this->panel();

        self::assertFalse($panel['ok'], 'a summary-only failure must reach the panel verdict');
    }

    // ── 2. THE THREE PUBLIC CONTRACTS ARE UNCHANGED
    // ═══════════════════════════════════════════════════════════════════

    public function testGetVerifiedUsersCountStillReturnsABareInt(): void
    {
        $value = $this->repo->getVerifiedUsersCount('1=1', []);

        self::assertIsInt($value, 'the public contract is a bare int');
    }

    public function testGetVerifiedUsersFilteredStillReturnsABareList(): void
    {
        $value = $this->repo->getVerifiedUsersFiltered('1=1', [], 'verified_at', 'DESC', 20, 0);

        self::assertIsArray($value, 'the public contract is a bare list');
        self::assertArrayNotHasKey('ok', $value, 'and it carries no verdict key');
    }

    public function testTheBareContractsAreUnchangedEvenWhenTheReadFails(): void
    {
        // ⚠ The property that makes the wrapper the right shape: a failing
        // read must not change what these two RETURN, only what the panel
        // says about it. Anything else would be a contract change in
        // disguise.
        $this->failReadsMatching('user_verifications');

        self::assertIsInt($this->repo->getVerifiedUsersCount('1=1', []));
        self::assertSame([], $this->repo->getVerifiedUsersFiltered('1=1', [], 'verified_at', 'DESC', 20, 0));
    }
}
