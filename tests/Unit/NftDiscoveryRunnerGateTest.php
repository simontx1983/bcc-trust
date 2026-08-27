<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\NftDiscoveryPage;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Support\NftChainCapability;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use BCC\Trust\Onchain\ValueObjects\ChainNftCapabilityOverrides;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE ONE PROVIDER-CONSUMING CONTROL, AND EVERY GATE IN FRONT OF IT.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────
 * When automatic collection discovery was retired, the admin backfill
 * button became the ONLY way a chain-wide scan starts. An entry point that
 * becomes the only way in inherits the responsibility for every gate the
 * removed upstream used to apply — and the first version of that fix had to
 * be made twice, because the button was gating on the environment constants
 * and the pause state alone while opt-in and the allowlist were being
 * enforced somewhere that no longer ran.
 *
 * This PR adds the capability model in front of it. So the claim under test
 * is: FIVE independent conditions, all required, and none of them
 * substitutable for another.
 *
 *   1. product support enabled      `bcc_supports_nft_collections`
 *   2. manual discovery enabled     `manual_collection_discovery_enabled`
 *   3. the enumeration operation registered for this chain
 *   4. that operation's driver not disabled by an override row
 *   5. `cosmwasm_enumeration` itself READY
 *
 * Plus every pre-existing gate, unchanged.
 */
#[CoversClass(NftDiscoveryPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftDiscoveryRunnerGateTest extends TestCase
{
    private const CHAIN_ID = 4;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/chains-cw-operations-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        ChainRepository::reset();
        ChainNftCapabilityRepository::reset();
        ChainCheckpointRepository::reset();
        CosmwasmDiscoveryWorker::reset();
        CosmwasmTickBudget::reset();
        CosmwasmDiscoveryGate::reset();
        \BccNftDiscoveryTransientStore::reset();

        $_POST = [];
        $_GET  = [];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // A chain with everything granted and everything configured. Each
        // test takes exactly ONE thing away, so a failure names the gate.
        ChainRepository::seed(self::CHAIN_ID, 'cosmos', true, true, true);
        ChainCheckpointRepository::seed(self::CHAIN_ID);
    }

    private function drive(): string
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$validNonceAction =
            NftDiscoveryPage::ACTION_CW_BACKFILL . '_' . self::CHAIN_ID;

        try {
            NftDiscoveryPage::handle_cw_backfill();
        } catch (\BccAdminRedirect $r) {
            return (string) ($r->args['bcc_cwo'] ?? '');
        }

        $this->fail('The handler must terminate in a PRG redirect.');
    }

    /** @return list<string> */
    private function audits(): array
    {
        return \BCC\Trust\Core\Security\AuditLogger::actions();
    }

    /** Nothing ran, nothing was written, nothing was contacted. */
    private function assertNothingRan(string $why): void
    {
        $this->assertSame(0, CosmwasmDiscoveryWorker::$passes, $why . ' — no provider work');
        $this->assertSame([], CosmwasmTickBudget::$constructions, $why . ' — no budget even built');
        $this->assertSame([], $this->audits(), $why . ' — no durable row');
        $this->assertSame([], ChainRepository::$discoveryWrites, $why . ' — no settings write');
        $this->assertSame(0, ChainRepository::$cacheBusts, $why . ' — no cache bust');
    }

    // ── The happy path, so the refusals below mean something ────────────

    public function testAFullyPermittedChainRuns(): void
    {
        $this->assertSame('backfill_ran', $this->drive());
        $this->assertSame(1, CosmwasmDiscoveryWorker::$passes);
    }

    // ── Condition 1: product support ────────────────────────────────────

    public function testProductSupportOffRefusesAndNamesItself(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'cosmos', true, false, true);

        $this->assertSame(
            'backfill_' . NftChainCapability::OP_NO_BCC_SUPPORT,
            $this->drive()
        );
        $this->assertNothingRan('product support is off');
    }

    // ── Condition 2: the manual permission ──────────────────────────────

    public function testManualPermissionOffRefusesAndNamesItself(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'cosmos', true, true, false);

        $this->assertSame(
            'backfill_' . NftChainCapability::OP_MANUAL_DISABLED,
            $this->drive()
        );
        $this->assertNothingRan('the manual permission is not granted');
    }

    /**
     * ⚠️ THE SUBSTITUTION THAT MUST NOT BE POSSIBLE.
     *
     * A chain whose provider is perfectly configured, whose driver is
     * registered and enabled, and whose product support is on — but which
     * nobody granted operator-start permission — is still refused. Provider
     * readiness is not a permission and can never stand in for one.
     */
    public function testAPerfectlyConfiguredProviderCannotSubstituteForThePermission(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'cosmos', true, true, false);

        // Prove the driver really IS ready, so the refusal below is about
        // the permission and nothing else.
        $chain = ChainRepository::getById(self::CHAIN_ID);
        self::assertNotNull($chain);
        $op = NftChainCapability::operationMatrix($chain)['operations'][NftDriverRegistry::OP_ENUMERATION];
        $this->assertSame([NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION], $op['ready']);

        $this->assertSame('backfill_' . NftChainCapability::OP_MANUAL_DISABLED, $this->drive());
        $this->assertSame(0, CosmwasmDiscoveryWorker::$passes);
    }

    // ── Condition 3 and 4: the operation and its driver ─────────────────

    public function testEveryDriverDisabledByAnOverrideRefuses(): void
    {
        ChainNftCapabilityRepository::seedLoaded(self::CHAIN_ID, [[
            'operation'  => NftDriverRegistry::OP_ENUMERATION,
            'driver_key' => NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
            'enabled'    => false,
            'priority'   => 0,
        ]]);

        $this->assertSame('backfill_' . NftChainCapability::OP_DISABLED, $this->drive());
        $this->assertNothingRan('the only enumeration driver is switched off');
    }

    // ── Condition 5, and the unreadable store ───────────────────────────

    public function testAnUnconfiguredProviderRefuses(): void
    {
        // No LCD endpoint: the driver is registered and enabled but nothing
        // can run it.
        ChainRepository::$rows[self::CHAIN_ID]->rest_url = '';

        $this->assertSame(
            'backfill_' . NftChainCapability::OP_PROVIDER_UNAVAILABLE,
            $this->drive()
        );
        $this->assertNothingRan('no endpoint is configured');
    }

    /** @return array<string, array{0: string}> */
    public static function unreadableStoreReasons(): array
    {
        return [
            'read failed'   => [ChainNftCapabilityOverrides::REASON_READ_FAILED],
            'overflow'      => [ChainNftCapabilityOverrides::REASON_OVERFLOW],
            'malformed'     => [ChainNftCapabilityOverrides::REASON_MALFORMED],
            'invalid chain' => [ChainNftCapabilityOverrides::REASON_INVALID_CHAIN],
        ];
    }

    /**
     * An unreadable or incomplete override store refuses, and refuses
     * WITHOUT falling back to registry defaults.
     *
     * Falling back would silently restore a driver an operator had disabled
     * — at the exact moment the system had just proved it cannot read what
     * that operator decided.
     */
    #[DataProvider('unreadableStoreReasons')]
    public function testAnUnreadableOverrideStoreRefuses(string $reason): void
    {
        ChainNftCapabilityRepository::seedUnavailable(self::CHAIN_ID, $reason);

        $this->assertSame('backfill_' . NftChainCapability::OP_UNKNOWN, $this->drive());
        $this->assertNothingRan('operator intent could not be read');
    }

    public function testAnAbsentCapabilityColumnRefuses(): void
    {
        ChainRepository::dropColumn(self::CHAIN_ID, 'manual_collection_discovery_enabled');

        $this->assertSame('backfill_' . NftChainCapability::OP_UNKNOWN, $this->drive());
        $this->assertNothingRan('this install cannot store the permission');
    }

    public function testAMeasuredlyUnsupportedChainRefuses(): void
    {
        ChainCheckpointRepository::seed(
            self::CHAIN_ID,
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED
        );

        $this->assertSame(
            'backfill_' . NftChainCapability::OP_CHAIN_UNSUPPORTED,
            $this->drive()
        );
        $this->assertNothingRan('the chain reported no wasm module');
    }

    // ── Every pre-existing gate still applies ───────────────────────────

    public function testTheEnvironmentGatesStillRefuseAfterTheCapabilityGatePasses(): void
    {
        CosmwasmDiscoveryGate::$discovery = false;

        $this->assertSame('backfill_discovery_off', $this->drive());
        $this->assertNothingRan('discovery is switched off for this installation');
    }

    public function testTheBackfillGateStillRefuses(): void
    {
        CosmwasmDiscoveryGate::$backfill = false;

        $this->assertSame('backfill_gate_off', $this->drive());
        $this->assertNothingRan('the historical backfill is switched off');
    }

    public function testAPausedChainStillRefuses(): void
    {
        ChainCheckpointRepository::seed(
            self::CHAIN_ID,
            ChainCheckpointRepository::CW_STATE_PAUSED
        );

        $this->assertSame('backfill_paused', $this->drive());
        $this->assertNothingRan('the chain is paused');
    }

    /**
     * The capability gate runs BEFORE the environment gates.
     *
     * Not arbitrary: when both would refuse, the operator should be told
     * about the thing they can see and act on from this page. "You have not
     * granted this chain permission" is actionable; "a constant in
     * wp-config is off" is the second question, not the first.
     */
    public function testTheCapabilityRefusalIsNamedBeforeTheEnvironmentOne(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'cosmos', true, true, false);
        CosmwasmDiscoveryGate::$discovery = false;

        $this->assertSame('backfill_' . NftChainCapability::OP_MANUAL_DISABLED, $this->drive());
    }

    // ── Reporting: a partial run is never a complete one ────────────────

    /** @return array<string, array{0: string, 1: string}> */
    public static function passOutcomes(): array
    {
        return [
            'ran'     => ['ran', 'backfill_ran'],
            'locked'  => ['locked', 'backfill_locked'],
            'skipped' => ['skipped', 'backfill_skipped'],
            'failed'  => ['failed', 'backfill_failed'],
        ];
    }

    #[DataProvider('passOutcomes')]
    public function testTheOutcomeIsReportedAsTheWorkerStatedIt(string $outcome, string $expected): void
    {
        CosmwasmDiscoveryWorker::$outcome = $outcome;

        $this->assertSame($expected, $this->drive());
        $this->assertSame(['admin_chain_cw_backfill_' . $outcome], $this->audits());
    }

    /**
     * A pass that RAN but stopped on a ceiling is reported as PARTIAL.
     *
     * The whole point of a bounded slice is that it is a slice. An operator
     * who reads a count and stops reading must not come away thinking that
     * was all of them.
     */
    public function testARunCutShortByItsBudgetIsMarkedPartial(): void
    {
        CosmwasmTickBudget::$spent = 20;   // the whole admin allowance

        $this->drive();

        $report = $this->report();
        $this->assertTrue($report['partial'], 'a budget-exhausted slice is not a finished scan');
        $this->assertSame('request_budget_exhausted', $report['stop_reason']);
    }

    public function testARunCutShortByTheClockNamesTheClockNotTheBudget(): void
    {
        CosmwasmTickBudget::$timedOut = true;

        $this->drive();

        $report = $this->report();
        $this->assertTrue($report['partial']);
        $this->assertSame(
            'runtime_deadline_reached',
            $report['stop_reason'],
            'naming the budget here would send an operator to raise the wrong ceiling'
        );
    }

    public function testAPassThatReachedItsOwnConclusionIsNotMarkedPartial(): void
    {
        $this->drive();

        $report = $this->report();
        $this->assertFalse($report['partial']);
        $this->assertSame('pass_completed', $report['stop_reason']);
    }

    /**
     * A pass that aborted on a PROVIDER answer is partial even with its
     * budget barely touched.
     *
     * This is the case the stop reason alone cannot see: an early return on
     * a stale cursor or an unreachable node leaves time and requests on the
     * clock, so `pass_completed` would be the literal answer and the wrong
     * one. The recorded errors are what make it honest.
     */
    public function testAPassThatRecordedAnErrorIsPartialEvenWithBudgetLeft(): void
    {
        CosmwasmDiscoveryWorker::$onRun = static function (?object $report): void {
            if ($report !== null && method_exists($report, 'addError')) {
                $report->addError('code listing unreachable');
            }
        };

        $this->drive();

        $report = $this->report();
        $this->assertTrue($report['partial'], 'a recorded provider failure means the slice is a subset');
        $this->assertNotSame([], $report['errors']);
    }

    /** A provider failure verifies nothing, enables nothing and seeds nothing. */
    public function testAFailedPassChangesNoCapabilityAndVerifiesNothing(): void
    {
        CosmwasmDiscoveryWorker::$outcome = 'failed';

        $this->assertSame('backfill_failed', $this->drive());

        $this->assertSame([], ChainRepository::$discoveryWrites, 'no capability was written');
        $this->assertSame(0, ChainRepository::$cacheBusts);
        $this->assertSame(['admin_chain_cw_backfill_failed'], $this->audits());
    }

    // ── The budget is built once and handed over, untouched ─────────────

    public function testExactlyOneBudgetIsBuiltAtTheDocumentedAdminBound(): void
    {
        $this->drive();

        $this->assertSame(
            [['requests' => 20, 'seconds' => 8]],
            CosmwasmTickBudget::$constructions
        );
        $this->assertSame(0, CosmwasmTickBudget::$reserveCalls, 'the worker owns the reserve sequence');
        $this->assertSame(0, CosmwasmTickBudget::$availableCalls);
    }

    /** The run report never travels in the URL. */
    public function testTheRedirectCarriesNoProviderDataOnlyABoundedCode(): void
    {
        $_POST['chain_id'] = self::CHAIN_ID;
        \BccAdminTestState::$validNonceAction =
            NftDiscoveryPage::ACTION_CW_BACKFILL . '_' . self::CHAIN_ID;

        try {
            NftDiscoveryPage::handle_cw_backfill();
            $this->fail('expected a redirect');
        } catch (\BccAdminRedirect $r) {
            $this->assertSame(
                [],
                array_diff(array_keys($r->args), NftDiscoveryPage::OPERATION_REDIRECT_KEYS)
            );
            $encoded = json_encode($r->args) ?: '';
            $this->assertStringNotContainsString('requests', $encoded);
            $this->assertStringNotContainsString('pages', $encoded);
            $this->assertStringNotContainsString('errors', $encoded);
        }
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        $stored = \BccNftDiscoveryTransientStore::$store;
        $this->assertNotSame([], $stored, 'a finished run parks a report for the redirect landing');

        /** @var array<string, mixed> $report */
        $report = reset($stored);

        return $report;
    }
}
