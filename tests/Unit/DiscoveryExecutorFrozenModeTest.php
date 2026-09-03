<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;
use BCC\Trust\Onchain\Workers\DiscoveryRunExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The executor re-judges a run against the mode FROZEN onto its row.
 *
 * ── THE BUG THIS PINS ───────────────────────────────────────────────────
 * A run's scan mode is chosen once, at request time, and stored. If the
 * executor re-derived the mode from the checkpoint instead, a backfill that
 * completed while the run sat queued would flip it to INCREMENTAL — and
 * INCREMENTAL is deliberately not gated on the environment switches. A run
 * approved as HISTORICAL would then walk past the very switch that governs
 * historical walks.
 *
 * ⚠ A MUTATION CONTROL FOUND THIS GAP. Every other executor test changes
 * product support or the opt-in, which refuse under BOTH modes — so
 * swapping the frozen mode for a literal INCREMENTAL changed nothing they
 * could see, and the control SURVIVED. This file is the answer: it is the
 * only place where the two modes disagree, which is the only place the
 * distinction is observable.
 */
#[CoversClass(DiscoveryRunExecutor::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DiscoveryExecutorFrozenModeTest extends TestCase
{
    private const CHAIN    = 17;
    private const OPERATOR = 2;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-cli-stubs.php';

        define('WP_CLI', true);
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN);

        // ⚠ THE ONE ENVIRONMENT WHERE THE MODES DISAGREE.
        // Discovery armed, the historical walk NOT armed. A HISTORICAL run
        // must be refused; an INCREMENTAL one would sail through.
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', false);

        // Supported, active, opted in — so nothing else can be the reason.
        ChainRepository::seed(self::CHAIN, 'dungeon', 'https://lcd.example', 'cosmos', 1, 1);
    }

    /**
     * Seed a queued run directly, with the mode this test is about.
     *
     * Deliberately NOT via DiscoveryRunService::request(): the request gate
     * would refuse a historical run in this environment, which is correct
     * and is tested elsewhere. The scenario here is the one the gate cannot
     * cover — a run queued when the configuration DID allow it, executed
     * after the configuration changed.
     */
    private function seedQueuedRun(string $scanMode): int
    {
        $created = DiscoveryRunRepository::insertQueued(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            $scanMode,
            self::CHAIN,
            self::OPERATOR
        );

        self::assertIsArray($created, 'precondition: the run must be seeded');
        self::assertSame([], ApiRetry::$calls, 'seeding must contact no provider');

        return (int) $created['id'];
    }

    /**
     * A HISTORICAL run is refused, naming the backfill switch, with no
     * provider call.
     */
    public function testAFrozenHistoricalRunIsJudgedAsHistorical(): void
    {
        $runId = $this->seedQueuedRun(DiscoveryScanMode::HISTORICAL);

        $result = DiscoveryRunExecutor::execute($runId);

        self::assertSame('failed', $result['status']);
        self::assertSame(
            DiscoveryRunError::HISTORICAL_BACKFILL_DISABLED,
            $result['reason'],
            'the run must be judged against the mode it was approved under'
        );
        self::assertSame([], ApiRetry::$calls, 'a refused historical run contacts no provider');

        $row = DiscoveryRunRepository::$rows[$runId];
        self::assertSame('failed', $row['status']);
        self::assertSame(DiscoveryRunError::HISTORICAL_BACKFILL_DISABLED, $row['error_code']);
        self::assertSame(0, (int) $row['collections_emitted']);
    }

    /**
     * THE OTHER HALF, AND THE REASON THE TEST ABOVE MEANS ANYTHING.
     *
     * In the SAME environment an INCREMENTAL run is NOT refused for the
     * backfill switch — it gets past readiness and on to the worker. Without
     * this, the assertion above would also pass against an executor that
     * refused everything.
     */
    public function testAFrozenIncrementalRunIsNotRefusedForTheBackfillSwitch(): void
    {
        $runId = $this->seedQueuedRun(DiscoveryScanMode::INCREMENTAL);

        $result = DiscoveryRunExecutor::execute($runId);

        self::assertNotSame(
            DiscoveryRunError::HISTORICAL_BACKFILL_DISABLED,
            $result['reason'] ?? null,
            'incremental must not be gated on the historical switch'
        );
        self::assertNotSame(
            DiscoveryRunError::DISCOVERY_GLOBALLY_DISABLED,
            $result['reason'] ?? null
        );
    }
}
