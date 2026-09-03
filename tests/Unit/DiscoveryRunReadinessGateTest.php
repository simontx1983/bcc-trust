<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Services\DiscoveryRunService;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The REQUEST gate refuses what the executor could never run.
 *
 * ── WHY THESE LIVE IN THEIR OWN CLASS ───────────────────────────────────
 * A PHP constant cannot be undefined, and `DiscoveryRunServiceTest`
 * defines both master switches ON in setUp so its own subjects — the
 * ledger, the audit, the race — are testable. The OFF cases therefore need
 * a class whose setUp defines nothing, with each method defining exactly
 * the environment it is about. Every test runs in its own process, which
 * is what makes that safe; it is the pattern CosmwasmChainEligibilityTest
 * already uses.
 *
 * ⚠ A REFUSAL HERE CREATES NO ROW. That is the whole point: PR 7 accepted
 * these requests and queued a run the executor was guaranteed to refuse,
 * which manufactured a failed run and told the operator their request was
 * accepted when it never could be.
 */
#[CoversClass(DiscoveryRunService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DiscoveryRunReadinessGateTest extends TestCase
{
    private const CHAIN    = 17;
    private const OPERATOR = 7;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/discovery-run-stubs.php';

        \BccDiscoveryTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Core\Security\TransactionManager::reset();
        ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::reset();
        DiscoveryRunRepository::reset();

        \BccDiscoveryTestState::seedAdmin(self::OPERATOR);
    }

    /** Supported, opted in, no checkpoint — so the mode is HISTORICAL. */
    private function seedSupportedChain(): void
    {
        ChainRepository::seed(self::CHAIN, 'dungeon', 'cosmos', 1, 1, 1);
    }

    private function request(): array
    {
        return (new DiscoveryRunService())->request(self::CHAIN, self::OPERATOR);
    }

    private function assertRefusedWithoutARow(array $result, string $reason): void
    {
        self::assertFalse($result['ok']);
        self::assertSame($reason, $result['reason']);
        self::assertSame([], DiscoveryRunRepository::$rows, 'a refusal must create no run row');
        self::assertSame([], \BccDiscoveryTestState::$dispatched, 'a refusal must dispatch nothing');
    }

    // ── the master switches, on the HISTORICAL path ─────────────────────

    /**
     * Undefined means disabled. This is the exact staging state measured on
     * 2026-09-03: both constants absent, so a fresh chain — which always
     * resolves to historical — cannot be scanned.
     */
    public function testHistoricalIsRefusedWhenTheSwitchesAreUndefined(): void
    {
        $this->seedSupportedChain();

        $this->assertRefusedWithoutARow(
            $this->request(),
            DiscoveryRunError::DISCOVERY_GLOBALLY_DISABLED
        );
    }

    public function testHistoricalIsRefusedWhenGlobalDiscoveryIsExplicitlyFalse(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', false);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);
        $this->seedSupportedChain();

        $this->assertRefusedWithoutARow(
            $this->request(),
            DiscoveryRunError::DISCOVERY_GLOBALLY_DISABLED
        );
    }

    /** Discovery armed, backfill not: the refusal names the narrower one. */
    public function testHistoricalIsRefusedWhenOnlyBackfillIsOff(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', false);
        $this->seedSupportedChain();

        $this->assertRefusedWithoutARow(
            $this->request(),
            DiscoveryRunError::HISTORICAL_BACKFILL_DISABLED
        );
    }

    // ── product support, which no switch can rescue ─────────────────────

    public function testProductSupportOffIsRefusedEvenWithEverythingElseArmed(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);
        // Opted in, active, cosmos — and product support OFF.
        ChainRepository::seed(self::CHAIN, 'jackal', 'cosmos', 1, 1, 0);

        $this->assertRefusedWithoutARow(
            $this->request(),
            DiscoveryRunError::NFT_DISCOVERY_UNSUPPORTED
        );
    }

    /**
     * ⚠ A CHAIN WHOSE SUPPORT COLUMN CANNOT BE READ IS NOT SUPPORTED.
     * A pre-migration install must refuse rather than assume.
     */
    public function testAnAbsentSupportColumnIsRefused(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);
        ChainRepository::seed(self::CHAIN, 'dungeon', 'cosmos', 1, 1, null);

        $this->assertRefusedWithoutARow(
            $this->request(),
            DiscoveryRunError::NFT_DISCOVERY_UNSUPPORTED
        );
    }

    // ── the positive control ────────────────────────────────────────────

    /**
     * With everything armed, ONE queued run — and exactly one.
     *
     * Without this, every refusal above would also pass against a service
     * that refused unconditionally.
     */
    public function testAFullyArmedConfigurationCreatesExactlyOneQueuedRun(): void
    {
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);
        $this->seedSupportedChain();

        $result = $this->request();

        self::assertTrue($result['ok']);
        self::assertSame('queued', $result['status']);
        self::assertCount(1, DiscoveryRunRepository::$rows, 'exactly one run');
        self::assertCount(1, \BccDiscoveryTestState::$dispatched, 'dispatched exactly once');

        // And the SERVER chose the mode — the caller passed none.
        self::assertSame(DiscoveryScanMode::HISTORICAL, $result['scan_mode']);
    }

    // ⚠ "The browser cannot choose a scan mode" is NOT re-asserted here.
    // DiscoveryScanAdminActionsTest already pins it, over COMMENT-STRIPPED
    // source and against four banned tokens (`scan_mode`, `HISTORICAL`,
    // `INCREMENTAL`, `forceScanMode`) — strictly stronger than a raw
    // string search, which a first draft of this file used and which
    // failed on the handler's own explanatory comment. One authority per
    // rule applies to tests too.
}
