<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Support\CosmwasmPassReport;
use BCC\Trust\Onchain\Support\CosmwasmTickBudget;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * An OPEN circuit breaker stops provider work. Nothing retries it.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────
 * A PR 7.5 mutation control that disabled the breaker check in
 * `prepareChain()` SURVIVED the whole suite. The breaker had ended a real
 * production session five hours earlier — run 5 on 2026-09-07 stopped at
 * chunk 16 with `chain_refused_to_prepare` because
 * `OnchainCircuitBreaker::isOpen(8)` was true — and nothing anywhere
 * asserted that it does that.
 *
 * That is the worst shape of gap: a safety mechanism everyone believes in,
 * observed working in production, and unconstrained by any test. The
 * mutation found it; these tests close it.
 *
 * ⚠ THE BREAKER IS AUTHORITATIVE AND PR 7.5 DOES NOT TOUCH ITS ALGORITHM.
 * What is pinned here is only the CONSEQUENCE: open breaker → no provider
 * request, no retry, no verdict.
 */
#[CoversClass(CosmwasmDiscoveryWorker::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BreakerStopsProviderWorkTest extends TestCase
{
    private const CHAIN = 42;
    private const REST  = 'https://lcd.example';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-cli-stubs.php';

        ApiRetry::reset();
        CosmwasmCodeFamilyRepository::reset();
        CosmwasmContractRepository::reset();
        CollectionRepository::reset();
        ChainCheckpointRepository::reset();
        ChainRepository::reset();
        NftSpamContractRepository::reset();
        OnchainCircuitBreaker::reset();

        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN);

        ChainRepository::seed(self::CHAIN, 'testchain', self::REST, 'cosmos', 1);
        ChainCheckpointRepository::ensureExists(self::CHAIN);

        // Real, claimable work — so a skip cannot be mistaken for an idle chain.
        for ($codeId = 1; $codeId <= 20; $codeId++) {
            CosmwasmCodeFamilyRepository::seed(self::CHAIN, $codeId, CosmwasmClassifier::INCONCLUSIVE);
        }
    }

    private function pass(): string
    {
        return CosmwasmDiscoveryWorker::runSupervisedSingleChainPass(
            self::CHAIN,
            new CosmwasmTickBudget(),
            new CosmwasmPassReport()
        );
    }

    // ── the consequence of an open breaker ──────────────────────────────

    /** ⚠ ZERO PROVIDER REQUESTS while the breaker is open. */
    public function testAnOpenBreakerStopsThePassBeforeAnyProviderRequest(): void
    {
        OnchainCircuitBreaker::$open = true;

        $outcome = $this->pass();

        self::assertSame(CosmwasmDiscoveryWorker::PASS_SKIPPED, $outcome);
        self::assertSame([], ApiRetry::$calls, 'an open breaker must make no provider request');
    }

    /** The same chain runs normally once the breaker closes — proving the fixture had work. */
    public function testTheSameFixtureDoesRunWhenTheBreakerIsClosed(): void
    {
        OnchainCircuitBreaker::$open = false;
        ApiRetry::$responder = static function (string $url): array {
            if (str_contains($url, '/contracts')) {
                return ['code' => 200, 'body' => '{"contracts":[],"pagination":{"next_key":null}}'];
            }
            if (str_contains($url, '/cosmwasm/wasm/v1/code')) {
                return ['code' => 200, 'body' => '{"code_infos":[],"pagination":{"next_key":null}}'];
            }

            return ['code' => 200, 'body' => '{"data":{}}'];
        };

        $outcome = $this->pass();

        // ⚠ THE POSITIVE CONTROL. Without this, the test above would pass
        // just as happily against a fixture that had nothing to do.
        self::assertNotSame(CosmwasmDiscoveryWorker::PASS_SKIPPED, $outcome);
        self::assertNotSame([], ApiRetry::$calls, 'the closed-breaker fixture really does contact the provider');
    }

    /**
     * ⚠ NO FAMILY IS DEMOTED BECAUSE THE BREAKER OPENED.
     *
     * A provider outage must leave unresolved work UNRESOLVED. Turning it
     * into `not_cw721` would convert an infrastructure failure into a
     * permanent product claim about somebody's contract.
     */
    public function testAnOpenBreakerNeverProducesANegativeVerdict(): void
    {
        OnchainCircuitBreaker::$open = true;

        $before = CosmwasmCodeFamilyRepository::countsByClassification(self::CHAIN);
        $this->pass();
        $after  = CosmwasmCodeFamilyRepository::countsByClassification(self::CHAIN);

        self::assertSame($before, $after, 'no classification may change while the breaker is open');
        self::assertSame(0, $after[CosmwasmClassifier::NOT_CW721] ?? 0, 'nothing became a negative verdict');
        self::assertSame(0, $after[CosmwasmClassifier::CONFIRMED] ?? 0, 'and nothing became confirmed');
    }

    /** ⚠ NO AUTOMATIC RETRY. The pass refuses again, and still contacts nobody. */
    public function testARefusedPassIsNotRetriedAutomatically(): void
    {
        OnchainCircuitBreaker::$open = true;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            self::assertSame(CosmwasmDiscoveryWorker::PASS_SKIPPED, $this->pass(), "attempt {$attempt}");
        }

        self::assertSame([], ApiRetry::$calls, 'three refusals, zero provider requests');
        self::assertSame([], OnchainCircuitBreaker::$failureChains, 'a refusal must not blame the breaker again');
    }
}
