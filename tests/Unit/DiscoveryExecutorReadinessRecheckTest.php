<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Services\DiscoveryRunService;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\Workers\DiscoveryRunExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Configuration is NOT frozen onto a run, so the executor re-asks.
 *
 * ── THE GAP THIS CLOSES ─────────────────────────────────────────────────
 * A run is queued when an administrator presses the button, and executed
 * later by cron. Between those moments a deploy can change wp-config, an
 * administrator can withdraw product support or the per-chain opt-in, and
 * the canary allowlist can narrow. PR 7 checked none of that at execution
 * time; the worker's own `backfillEnabled()` guard returned PASS_SKIPPED,
 * which arrived as the generic CHAIN_NOT_READY / `chain_refused_to_prepare`
 * pair — the same pair a pause, an open breaker and a missing driver
 * produce.
 *
 * ⚠ THE DEFECT WAS NEVER "A DISABLED ENGINE REPORTED SUCCESS". PR 7A's
 * status split already refused to call a non-PASS_RAN outcome a success.
 * The defect is that the refusal could not be ATTRIBUTED, and an
 * unattributable refusal on a chain the operator just enabled is
 * indistinguishable from "this chain has no NFTs". These tests pin the
 * attribution, and that no provider is contacted on the way to it.
 */
#[CoversClass(DiscoveryRunExecutor::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DiscoveryExecutorReadinessRecheckTest extends TestCase
{
    private const CHAIN    = 17;
    /** The one administrator the CLI stubs authorize. */
    private const OPERATOR = 2;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-cli-stubs.php';

        define('WP_CLI', true);
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN);
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);
    }

    /**
     * Queue a real run through the real service, then hand back its id.
     * The run is genuinely requested and genuinely authorised — which is
     * what makes the refusal below attributable to the LATER change.
     */
    private function queueRun(): int
    {
        ChainRepository::seed(self::CHAIN, 'dungeon', 'https://lcd.example', 'cosmos', 1, 1);

        $result = (new DiscoveryRunService())->request(self::CHAIN, self::OPERATOR);

        self::assertTrue($result['ok'], 'precondition: the run must be genuinely queued');

        // The harness is healthy: a run exists and nothing has been fetched.
        self::assertCount(1, DiscoveryRunRepository::$rows);
        self::assertSame([], ApiRetry::$calls, 'queueing must contact no provider');

        return (int) $result['run_id'];
    }

    /** @return array<string, mixed> the single run row */
    private function theRun(): array
    {
        $rows = DiscoveryRunRepository::$rows;
        self::assertCount(1, $rows, 'exactly one run, always');

        return (array) reset($rows);
    }

    // ── product support withdrawn after queueing ────────────────────────

    public function testSupportWithdrawnAfterQueueingRefusesWithZeroProviderCalls(): void
    {
        $runId = $this->queueRun();

        // The administrator turns product support off while the run waits.
        ChainRepository::seed(self::CHAIN, 'dungeon', 'https://lcd.example', 'cosmos', 1, 0);

        $result = DiscoveryRunExecutor::execute($runId);

        self::assertSame('failed', $result['status'], 'a scan that never ran did not succeed');
        self::assertSame(DiscoveryRunError::NFT_DISCOVERY_UNSUPPORTED, $result['reason']);

        // ⚠ THE ASSERTION THAT MATTERS: nothing was asked of any provider,
        // so "zero collections" is not even expressible here.
        self::assertSame([], ApiRetry::$calls, 'no provider may be contacted on a refusal');

        $run = $this->theRun();
        self::assertSame('failed', $run['status']);
        self::assertSame(DiscoveryRunError::NFT_DISCOVERY_UNSUPPORTED, $run['error_code']);
        self::assertSame(0, (int) ($run['collections_emitted'] ?? 0));
    }

    // ── the per-chain opt-in withdrawn after queueing ───────────────────

    public function testOptInWithdrawnAfterQueueingRefusesWithItsOwnCode(): void
    {
        $runId = $this->queueRun();

        ChainRepository::seed(self::CHAIN, 'dungeon', 'https://lcd.example', 'cosmos', 0, 1);

        $result = DiscoveryRunExecutor::execute($runId);

        self::assertSame('failed', $result['status']);
        self::assertSame(
            \BCC\Trust\Onchain\Support\CosmwasmScanEligibility::NOT_OPTED_IN,
            $result['reason'],
            'the refusal names the switch that changed'
        );
        self::assertSame([], ApiRetry::$calls);
    }

    // ── the run is never silently reported as an empty scan ─────────────

    /**
     * A refused execution and an empty scan must never look alike.
     *
     * `succeeded` is reserved for a pass that actually ran; a refusal is
     * `failed` with a code that names the blocker. Asserting BOTH halves
     * here is deliberate — asserting only "not succeeded" would pass for a
     * run stuck in `queued`, which is a different bug.
     */
    public function testARefusedExecutionIsNeverASuccessfulZero(): void
    {
        $runId = $this->queueRun();
        ChainRepository::seed(self::CHAIN, 'dungeon', 'https://lcd.example', 'cosmos', 1, 0);

        DiscoveryRunExecutor::execute($runId);

        $run = $this->theRun();
        self::assertNotSame('succeeded', $run['status']);
        self::assertSame('failed', $run['status']);
        self::assertNotSame(
            \BCC\Trust\Onchain\Support\CosmwasmPassStopReason::PASS_COMPLETED,
            $run['stop_reason'] ?? null,
            'a refusal must not borrow the completed-pass stop reason'
        );
    }

    // ── and no second run is invented ───────────────────────────────────

    /**
     * A refusal does not queue a replacement. Retry is an administrator
     * action; an executor that re-queued on refusal would turn one
     * authorised request into an unbounded loop against a chain whose
     * configuration says no.
     */
    public function testARefusalDoesNotCreateASecondRun(): void
    {
        $runId = $this->queueRun();
        ChainRepository::seed(self::CHAIN, 'dungeon', 'https://lcd.example', 'cosmos', 1, 0);

        DiscoveryRunExecutor::execute($runId);
        DiscoveryRunExecutor::execute($runId);

        self::assertCount(1, DiscoveryRunRepository::$rows, 'no second run may appear');
        self::assertSame([], ApiRetry::$calls);
    }

    // ── nothing else moves ──────────────────────────────────────────────

    /** A readiness refusal touches no collection, checkpoint or lock. */
    public function testARefusalChangesNoDiscoveredState(): void
    {
        $runId = $this->queueRun();
        ChainRepository::seed(self::CHAIN, 'dungeon', 'https://lcd.example', 'cosmos', 1, 0);

        DiscoveryRunExecutor::execute($runId);

        self::assertSame([], \BCC\Trust\Onchain\Repositories\CollectionRepository::$upserted);
        self::assertSame([], \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$discoveryTouches);
        self::assertSame([], \BCC\Core\DB\AdvisoryLock::$acquired, 'the chain lock is never taken');
    }
}
