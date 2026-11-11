<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\SignalsRenderWorld;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * What the four Signals tabs TELL AN OPERATOR when a read did not run.
 *
 * ── THE SENTENCES UNDER TEST ────────────────────────────────────────────
 * Measured in a disposable authenticated browser against the pre-fix build,
 * with each table replaced by an invalid view:
 *
 *   Fraud    "✓ No high-risk users detected."
 *            "All users are within acceptable fraud score thresholds."
 *   Devices  "No device records found."
 *   ML       "No patterns detected yet."
 *   Rings    "✓ No vote rings detected."
 *
 * Two of them carry a TICK. These tests pin that none can be printed from a
 * read that did not run — and, just as importantly, that all of them are
 * still printed when the read DID run and genuinely found nothing.
 *
 * ⚠ Separate processes are required; see the stub file for why.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SignalsTabReadFailureRenderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../Stubs/signals-tab-render-stubs.php';
        $tabs = __DIR__ . '/../../includes/admin/tabs/';
        require_once $tabs . 'tab-fraud.php';
        require_once $tabs . 'tab-devices.php';
        require_once $tabs . 'tab-rings.php';
        require_once $tabs . 'tab-ml.php';
    }

    protected function setUp(): void
    {
        SignalsRenderWorld::reset();
    }

    /**
     * ⚠ try/finally, not a bare ob_start()/ob_get_clean() pair. A render that
     * throws mid-buffer otherwise leaves the buffer open, PHPUnit marks the
     * test RISKY, and the leaked buffer swallows the next test's output —
     * turning one real error into a cascade that hides its own cause.
     */
    private function render(string $fn): string
    {
        ob_start();
        try {
            $fn();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    // ── 0. CONTROLS: the healthy renders are untouched
    // ═══════════════════════════════════════════════════════════════════

    public function testTheHarnessRendersTheRealTabs(): void
    {
        self::assertStringContainsString('High-Risk Users', $this->render('bcc_trust_render_fraud_tab'));
        self::assertStringContainsString('Device Fingerprints', $this->render('bcc_trust_render_devices_tab'));
        self::assertStringContainsString('Vote Ring Detection', $this->render('bcc_trust_render_rings_tab'));
        self::assertStringContainsString('burst_voting', $this->render('bcc_trust_render_ml_tab'));
    }

    public function testAHealthyFraudRenderShowsTheUserAndNoFailureNotice(): void
    {
        $html = $this->render('bcc_trust_render_fraud_tab');

        self::assertStringContainsString('Signals Fixture', $html);
        self::assertStringNotContainsString('could not be read', $html);
    }

    public function testAGenuineEmptyFraudListStillSaysNoHighRiskUsers(): void
    {
        // ⚠ THE CONTROL THAT KEEPS THE TICK HONEST. The reassuring sentence
        // is CORRECT when the read ran, and this fix must not remove it.
        SignalsRenderWorld::$fraud['fraud_users'] = [];
        SignalsRenderWorld::$fraud['total']       = 0;

        $html = $this->render('bcc_trust_render_fraud_tab');

        self::assertStringContainsString('No high-risk users detected', $html);
        self::assertStringNotContainsString('could not be read', $html);
    }

    public function testAGenuineEmptyDeviceListStillSaysNoDeviceRecords(): void
    {
        SignalsRenderWorld::$devices['devices'] = [];

        $html = $this->render('bcc_trust_render_devices_tab');

        self::assertStringContainsString('No device records found', $html);
        self::assertStringNotContainsString('could not be read', $html);
    }

    public function testAnEmptyButAvailableRingSnapshotStillShowsTheAllClear(): void
    {
        SignalsRenderWorld::$rings['available'] = true;
        SignalsRenderWorld::$rings['rings']     = [];

        $html = $this->render('bcc_trust_render_rings_tab');

        self::assertStringContainsString('No vote rings detected', $html);
        self::assertStringNotContainsString('No ring-detection snapshot yet', $html);
    }

    // ── 1. ⚠⚠ A FAILED READ CANNOT PRINT AN ALL-CLEAR
    // ═══════════════════════════════════════════════════════════════════

    public function testAFailedFraudReadNeverClaimsNoHighRiskUsers(): void
    {
        SignalsRenderWorld::$fraud['ok']          = false;
        SignalsRenderWorld::$fraud['fraud_users'] = [];
        SignalsRenderWorld::$fraud['total']       = 0;

        $html = $this->render('bcc_trust_render_fraud_tab');

        self::assertStringNotContainsString('No high-risk users detected', $html);
        self::assertStringNotContainsString('within acceptable fraud score thresholds', $html);
        self::assertStringContainsString('could not be read', $html);
        self::assertStringContainsString('not a statement that no user is high-risk', $html);
    }

    public function testAFailedFraudReadDashesItsHeadlineCountsRatherThanPrintingZero(): void
    {
        SignalsRenderWorld::$fraud['ok']             = false;
        SignalsRenderWorld::$fraud['critical_count'] = 0;
        SignalsRenderWorld::$fraud['total']          = 0;

        $html = $this->render('bcc_trust_render_fraud_tab');

        // A bold 0 under a red "Critical Risk Users" heading reads as a
        // measurement. The dash cannot.
        self::assertStringContainsString('—', $html);
        self::assertStringNotContainsString('color:#f44336;">0</div>', $html);
    }

    public function testAFailedDeviceReadNeverClaimsNoDeviceRecords(): void
    {
        SignalsRenderWorld::$devices['ok']      = false;
        SignalsRenderWorld::$devices['devices'] = [];

        $html = $this->render('bcc_trust_render_devices_tab');

        self::assertStringNotContainsString('No device records found', $html);
        self::assertStringContainsString('could not be read', $html);
    }

    public function testAFailedPatternReadNeverClaimsNoPatternsDetected(): void
    {
        SignalsRenderWorld::$ml['ok']       = false;
        SignalsRenderWorld::$ml['patterns'] = [];

        $html = $this->render('bcc_trust_render_ml_tab');

        self::assertStringNotContainsString('No patterns detected yet', $html);
        self::assertStringContainsString('could not be read', $html);
    }

    public function testAnAbsentRingSnapshotNeverClaimsNoVoteRings(): void
    {
        SignalsRenderWorld::$rings['available'] = false;
        SignalsRenderWorld::$rings['rings']     = [];

        $html = $this->render('bcc_trust_render_rings_tab');

        self::assertStringNotContainsString('No vote rings detected', $html);
        self::assertStringContainsString('No ring-detection snapshot yet', $html);
        self::assertStringContainsString('not the same as finding no rings', $html);
    }

    public function testAnAbsentRingSnapshotShowsNoClusterCount(): void
    {
        // "0 suspicious cluster(s) detected" is a measurement nobody took.
        SignalsRenderWorld::$rings['available'] = false;

        $html = $this->render('bcc_trust_render_rings_tab');

        self::assertStringNotContainsString('<strong>0</strong> suspicious cluster', $html);
        self::assertStringContainsString('detection has not produced a snapshot yet', $html);
    }

    public function testTheUnavailableRingStateIsNotStyledAsAnError(): void
    {
        // ⚠ Deliberate: on a fresh install, or within the first hour, "no
        // snapshot yet" is the CORRECT state. Dressing it as a failure would
        // train operators to ignore it.
        SignalsRenderWorld::$rings['available'] = false;

        $html = $this->render('bcc_trust_render_rings_tab');

        self::assertStringNotContainsString('notice-error', $html);
    }

    // ── 2. NO PHP DIAGNOSTICS IN ANY DEGRADED STATE
    // ═══════════════════════════════════════════════════════════════════

    public function testEveryDegradedRenderIsFreeOfPhpDiagnostics(): void
    {
        SignalsRenderWorld::$fraud['ok']        = false;
        SignalsRenderWorld::$fraud['fraud_users'] = [];
        SignalsRenderWorld::$devices['ok']      = false;
        SignalsRenderWorld::$devices['devices'] = [];
        SignalsRenderWorld::$ml['ok']           = false;
        SignalsRenderWorld::$ml['patterns']     = [];
        SignalsRenderWorld::$rings['available'] = false;

        foreach ([
            'bcc_trust_render_fraud_tab',
            'bcc_trust_render_devices_tab',
            'bcc_trust_render_rings_tab',
            'bcc_trust_render_ml_tab',
        ] as $fn) {
            $html = $this->render($fn);
            self::assertNotSame('', $html, "{$fn} still rendered");
            foreach (['Warning', 'TypeError', 'Deprecated', 'Fatal'] as $shape) {
                self::assertStringNotContainsString($shape, $html, "{$fn} emitted {$shape}");
            }
        }
    }

    public function testAPreVerdictPayloadRendersExactlyAsBefore(): void
    {
        // ⚠ A cached payload written by the previous build carries no verdict
        // key. Treating that absence as a failure would show every operator a
        // read error on the first load after deploy.
        unset(
            SignalsRenderWorld::$fraud['ok'],
            SignalsRenderWorld::$devices['ok'],
            SignalsRenderWorld::$ml['ok'],
            SignalsRenderWorld::$rings['available']
        );

        self::assertStringNotContainsString('could not be read', $this->render('bcc_trust_render_fraud_tab'));
        self::assertStringNotContainsString('could not be read', $this->render('bcc_trust_render_devices_tab'));
        self::assertStringNotContainsString('could not be read', $this->render('bcc_trust_render_ml_tab'));
        self::assertStringNotContainsString('No ring-detection snapshot yet', $this->render('bcc_trust_render_rings_tab'));
    }
}
