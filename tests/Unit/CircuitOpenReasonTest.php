<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\CosmwasmPassStopReason;
use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * "The provider is resting" must not read as "your chain is broken".
 *
 * ── THE OPERATOR PROBLEM ────────────────────────────────────────────────
 * Runs 5 and 6 both ended as `chain_refused_to_prepare` — the same token a
 * paused chain, an unsupported chain and a missing driver produce. The
 * operator had pressed Continue on a correctly configured chain and was told
 * it "refused to prepare". The available conclusions were all wrong, and the
 * cheapest one — press Continue again — spends a chunk against a pause that
 * has to elapse on its own.
 */
#[CoversClass(CosmwasmPassStopReason::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CircuitOpenReasonTest extends TestCase
{
    private function budget(): ProviderRequestBudget
    {
        return new ProviderRequestBudget(25, CosmwasmDiscoveryGate::MAX_RUNTIME_SECONDS);
    }

    // ── the mapping ─────────────────────────────────────────────────────

    /** A circuit-open pass gets its own token. */
    public function testCircuitOpenMapsToItsOwnStopReason(): void
    {
        self::assertSame(
            CosmwasmPassStopReason::PROVIDER_CIRCUIT_OPEN,
            CosmwasmPassStopReason::forOutcome(CosmwasmDiscoveryWorker::PASS_CIRCUIT_OPEN, $this->budget())
        );
    }

    /** ⚠ It must NOT collapse back into the generic refusal. */
    public function testCircuitOpenIsNotTheGenericRefusal(): void
    {
        $reason = CosmwasmPassStopReason::forOutcome(
            CosmwasmDiscoveryWorker::PASS_CIRCUIT_OPEN,
            $this->budget()
        );

        self::assertNotSame(CosmwasmPassStopReason::CHAIN_REFUSED_TO_PREPARE, $reason);
        self::assertNotSame(
            CosmwasmPassStopReason::PROVIDER_CIRCUIT_OPEN,
            CosmwasmPassStopReason::CHAIN_REFUSED_TO_PREPARE,
            'the two tokens must be distinct values, not aliases'
        );
    }

    /** The other outcomes are untouched. */
    public function testTheOtherOutcomesAreUnchanged(): void
    {
        $b = $this->budget();

        self::assertSame(
            CosmwasmPassStopReason::LOCK_CONTENDED,
            CosmwasmPassStopReason::forOutcome(CosmwasmDiscoveryWorker::PASS_LOCKED, $b)
        );
        self::assertSame(
            CosmwasmPassStopReason::CHAIN_REFUSED_TO_PREPARE,
            CosmwasmPassStopReason::forOutcome(CosmwasmDiscoveryWorker::PASS_SKIPPED, $b)
        );
        self::assertSame(
            CosmwasmPassStopReason::EXECUTION_FAILED,
            CosmwasmPassStopReason::forOutcome(CosmwasmDiscoveryWorker::PASS_FAILED, $b)
        );
    }

    /** A circuit-open session is a PARTIAL session, never a completed one. */
    public function testCircuitOpenIsPartial(): void
    {
        self::assertTrue(
            CosmwasmPassStopReason::isPartial(CosmwasmPassStopReason::PROVIDER_CIRCUIT_OPEN),
            'a session cut short by a provider pause must never read as finished'
        );
        self::assertFalse(CosmwasmPassStopReason::isPartial(CosmwasmPassStopReason::PASS_COMPLETED));
    }

    /** The worker outcome constants stay distinct. */
    public function testPassOutcomeConstantsAreDistinct(): void
    {
        $all = [
            CosmwasmDiscoveryWorker::PASS_RAN,
            CosmwasmDiscoveryWorker::PASS_LOCKED,
            CosmwasmDiscoveryWorker::PASS_SKIPPED,
            CosmwasmDiscoveryWorker::PASS_CIRCUIT_OPEN,
            CosmwasmDiscoveryWorker::PASS_FAILED,
        ];

        self::assertCount(count(array_unique($all)), $all);
    }

    /** The run-level error code exists and is distinct from chain_not_ready. */
    public function testTheRunErrorCodeIsDistinct(): void
    {
        self::assertSame('provider_circuit_open', DiscoveryRunError::PROVIDER_CIRCUIT_OPEN);
        self::assertNotSame(DiscoveryRunError::CHAIN_NOT_READY, DiscoveryRunError::PROVIDER_CIRCUIT_OPEN);
        self::assertNotSame(DiscoveryRunError::CHAIN_UNSUPPORTED, DiscoveryRunError::PROVIDER_CIRCUIT_OPEN);
        self::assertNotSame(DiscoveryRunError::DISCOVERY_DISABLED, DiscoveryRunError::PROVIDER_CIRCUIT_OPEN);
    }

    // ── operator wording ────────────────────────────────────────────────




}
