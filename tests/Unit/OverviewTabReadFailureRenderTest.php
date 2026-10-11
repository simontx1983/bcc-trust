<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\OverviewRenderWorld;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * What the Overview tab TELLS AN OPERATOR when a figure could not be read.
 *
 * ── THE CLAIM UNDER TEST ────────────────────────────────────────────────
 * A colour is an assertion. Green on "New High-Fraud: 0" says nobody
 * crossed the fraud threshold today — and the tab used to say it from a
 * failed query, because `(int) null` is 0. These tests pin that no branch
 * can paint an unread number green, and that a number which could not be
 * read is never displayed as a number at all.
 *
 * The repository's own behaviour — which verdict it produces, and that a
 * failed computation is neither cached nor snapshotted — is pinned against
 * a real database in AdminDashboardReadFailureIntegrationTest. Here the
 * verdict is the INPUT and the markup is the subject.
 */
#[Group('unit')]
final class OverviewTabReadFailureRenderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../Stubs/overview-tab-render-stubs.php';
        require_once __DIR__ . '/../../includes/admin/tabs/tab-overview.php';
    }

    protected function setUp(): void
    {
        OverviewRenderWorld::reset();
    }

    private function render(): string
    {
        ob_start();
        \bcc_trust_render_overview_tab();

        return (string) ob_get_clean();
    }

    // ── 0. CONTROLS: the healthy render is unchanged
    // ═══════════════════════════════════════════════════════════════════

    public function testTheHarnessActuallyRendersTheRealTab(): void
    {
        $html = $this->render();

        self::assertStringContainsString("Today's Activity Summary", $html);
        self::assertStringContainsString('User Risk Distribution', $html);
        self::assertSame(1, OverviewRenderWorld::$overviewCalls, 'the tab asked the repository');
        self::assertSame(1, OverviewRenderWorld::$todayCalls);
    }

    public function testAHealthyRenderShowsNumbersAndNoNotice(): void
    {
        // ⚠ THE CONTROL. Without it, every "shows a dash" assertion below
        // could be satisfied by a tab that always shows dashes.
        $html = $this->render();

        self::assertStringContainsString('>3</div>', $html, 'the real user count is rendered');
        self::assertStringNotContainsString('could not be read from the database', $html);
        self::assertStringNotContainsString('last good snapshot', $html);
    }

    public function testAHealthyZeroIsStillGreen(): void
    {
        // The green zero is CORRECT when the read succeeded, and this fix
        // must not take that away — an operator relies on it.
        $html = $this->render();

        self::assertStringContainsString('#4caf50', $html, 'a real zero still reads as all-clear');
    }

    // ── 1. ⚠⚠ AN UNREADABLE FIGURE IS NEVER SHOWN AS A NUMBER
    // ═══════════════════════════════════════════════════════════════════

    public function testAFailedTodayReadShowsDashesRatherThanZeros(): void
    {
        OverviewRenderWorld::$today['ok']          = false;
        OverviewRenderWorld::$today['today_fraud'] = 0;

        $html = $this->render();

        self::assertStringContainsString('—', $html);
        self::assertStringContainsString('could not be read from the database', $html);
    }

    public function testAFailedTodayReadPaintsNothingGreen(): void
    {
        // ⚠ The assertion this whole slice exists for.
        OverviewRenderWorld::$today['ok'] = false;
        // Keep the overview healthy so any green must come from the tiles.
        $html = $this->render();

        $summarySection = substr($html, 0, (int) strpos($html, 'Headline') ?: 4000);

        self::assertStringNotContainsString(
            'color:#4caf50',
            $summarySection,
            'a figure that was never read must not be coloured as an all-clear'
        );
    }

    public function testAFailedOverviewReadDashesTheHeadlineTotals(): void
    {
        OverviewRenderWorld::$overview['ok'] = false;

        $html = $this->render();

        self::assertStringNotContainsString('>3</div>', $html, 'the fabricated 3 is gone');
        self::assertStringContainsString('—', $html);
    }

    public function testAFailedOverviewReadDoesNotDrawAnEmptyRiskDistribution(): void
    {
        // Five bars at 0% read as "no user is at risk" — the same false
        // all-clear in a different shape.
        OverviewRenderWorld::$overview['ok'] = false;

        $html = $this->render();

        self::assertStringContainsString('User Risk Distribution', $html);
        self::assertStringContainsString('—', $html);
    }

    public function testADashedRenderEmitsNoPhpDiagnostics(): void
    {
        // ⚠ Not cosmetic. `'—' / $total` THROWS a TypeError in PHP 8, so a
        // dash substituted without guarding the percentage turns a
        // misleading panel into a white screen. This is the case that
        // catches that regression.
        OverviewRenderWorld::$overview['ok'] = false;
        OverviewRenderWorld::$today['ok']    = false;

        $html = $this->render();

        self::assertStringNotContainsString('Warning', $html);
        self::assertStringNotContainsString('TypeError', $html);
        self::assertStringNotContainsString('Deprecated', $html);
        self::assertNotSame('', $html, 'the tab still rendered');
    }

    // ── 2. A STALE-BUT-REAL SNAPSHOT IS LABELLED, NOT DISGUISED
    // ═══════════════════════════════════════════════════════════════════

    public function testAStaleSnapshotIsLabelledAndStillShowsItsRealNumbers(): void
    {
        OverviewRenderWorld::$overview['stale']     = true;
        OverviewRenderWorld::$overview['stale_age'] = 420;

        $html = $this->render();

        self::assertStringContainsString('last good snapshot', $html);
        self::assertStringContainsString('>3</div>', $html, 'the real number is still shown');
        self::assertStringContainsString('7 mins', $html, 'with an age the operator can judge');
    }

    public function testAFreshRenderMakesNoStaleClaim(): void
    {
        // Anti-vacuity for the test above.
        $html = $this->render();

        self::assertStringNotContainsString('last good snapshot', $html);
    }

    // ── 3. THE DEVICE BUCKETS RECONCILE, OR SAY THEY DO NOT
    // ═══════════════════════════════════════════════════════════════════

    public function testIrreconcilableDeviceBucketsAreReportedRatherThanClampedSilently(): void
    {
        // 'normal' is total − (high + suspicious) in the repository, so a
        // partial read failure drives it negative. The old view clamped it
        // to 0 with max() and said nothing, and the three bars quietly
        // stopped summing to the total.
        OverviewRenderWorld::$overview['device_risk']['normal'] = -4;

        $html = $this->render();

        self::assertStringContainsString('do not reconcile', $html);
    }

    public function testReconcilableDeviceBucketsCarryNoWarning(): void
    {
        // Anti-vacuity: the warning is specific to the inconsistent case.
        $html = $this->render();

        self::assertStringNotContainsString('do not reconcile', $html);
    }

    // ── 4. BACKWARD COMPATIBILITY WITH A PRE-FIX SNAPSHOT
    // ═══════════════════════════════════════════════════════════════════

    public function testASnapshotWithoutAnOkKeyIsTreatedAsReadable(): void
    {
        // ⚠ A snapshot written by the previous build carries no 'ok' key.
        // Treating that absence as a failure would greet every operator
        // with a read error on the first load after deploy.
        unset(OverviewRenderWorld::$overview['ok'], OverviewRenderWorld::$today['ok']);

        $html = $this->render();

        self::assertStringNotContainsString('could not be read from the database', $html);
        self::assertStringContainsString('>3</div>', $html);
    }
}
