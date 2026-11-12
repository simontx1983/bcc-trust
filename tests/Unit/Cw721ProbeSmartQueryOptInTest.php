<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: {@see CosmosFetcher::probeCw721()} — the live CW-721 probe
 * behind manual intake / targeted validation — sends its smart queries
 * through the `smartQuery: true` opt-in of `lcdGetResult()`, so a contract
 * REFUSING a query variant (a 5xx whose message is the QueryMsg parse error)
 * costs one request and ZERO breaker charges, while a node fault on the same
 * probe keeps every retry and charge.
 *
 * ── WHY THIS TEST EXISTS ────────────────────────────────────────────────
 * The opt-in's dedicated tests (SmartQueryOptInWiringTest,
 * CosmwasmSmartQueryRetryTest) were deleted with the scanner in S8, leaving
 * pr76 control #4 ("recognised unknown-variant 500 treated as a provider
 * failure") filtering on test classes that no longer exist. The collection
 * metadata path is pinned by CollectionMetadataApplicationErrorTest; the
 * probe path — three wasmSmartQueryResult() calls per contract — was not.
 * Without the opt-in a non-CW-721 contract costs 3 x 4 = 12 wire attempts
 * and 3 charges per probe, so two probes of one wrong address open the
 * chain's breaker (threshold 5) and with it validator indexing and holdings.
 *
 * Same real stack as TokenLevelContractRefusalTest: only the WIRE is
 * scripted; ApiRetry and the breaker are real.
 */
#[CoversClass(CosmosFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class Cw721ProbeSmartQueryOptInTest extends TestCase
{
    private const CHAIN    = 96;
    private const CONTRACT = 'cosmos1cw721probeoptintestcontractaddressvalue';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/enumeration-real-transport-stubs.php';

        \BccWire::reset();
        \BccBreakerStore::reset();
    }

    private function fetcher(): CosmosFetcher
    {
        return new CosmosFetcher((object) [
            'id'         => self::CHAIN,
            'slug'       => 'testchain',
            'chain_type' => 'cosmos',
            'rest_url'   => 'https://lcd.test',
            'rpc_url'    => '',
            'is_active'  => 1,
            'decimals'   => 6,
        ]);
    }

    private function charges(): int
    {
        return \BccBreakerStore::counter(self::CHAIN) ?? 0;
    }

    /**
     * A contract that is not CW-721 refusing the variant: HTTP 500, grpc code
     * 2, the cw QueryMsg parse error, NO node-error token (those are checked
     * first by CosmwasmClassifier::errorKindFromMessage()).
     *
     * @return array{code: int, body: string}
     */
    private static function unknownVariant(): array
    {
        return [
            'code' => 500,
            'body' => json_encode([
                'code'    => 2,
                'message' => 'Error parsing into type cw20_base::msg::QueryMsg: unknown variant `num_tokens`, '
                    . 'expected one of `balance`, `token_info`, `minter`: query wasm contract failed',
                'details' => [],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array{code: int, body: string} */
    private static function nodeFault(): array
    {
        return [
            'code' => 500,
            'body' => json_encode([
                'code'    => 13,
                'message' => 'rpc error: code = Internal desc = Querier system error: node is not caught up',
                'details' => [],
            ], JSON_THROW_ON_ERROR),
        ];
    }

    public function testRefusedVariantsCostOneRequestEachAndNoCharge(): void
    {
        \BccWire::$always = self::unknownVariant();

        $outcomes = $this->fetcher()->probeCw721(self::CONTRACT);

        self::assertCount(3, $outcomes, 'num_tokens, contract_info, then the modern fallback');
        foreach ($outcomes as $outcome) {
            self::assertFalse($outcome['ok'], $outcome['probe']);
            self::assertSame(CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, $outcome['kind'], $outcome['probe']);
        }
        self::assertCount(3, \BccWire::$urls, 'one wire call per probe, none retried');
        self::assertSame([], \BccWire::$sleeps, 'a refusal is an answer: nothing backs off');
        self::assertSame(0, $this->charges(), 'the contract answering is not the node failing');
        self::assertNull(\BccBreakerStore::counter(self::CHAIN), 'no counter row is created at all');
    }

    /** More probes of a non-CW-721 address than the breaker threshold. */
    public function testRepeatedProbesOfANonCw721ContractNeverOpenTheBreaker(): void
    {
        \BccWire::$always = self::unknownVariant();
        $fetcher = $this->fetcher();

        $n = OnchainCircuitBreaker::FAILURE_THRESHOLD * 2;
        for ($i = 0; $i < $n; $i++) {
            $fetcher->probeCw721(self::CONTRACT);
        }

        self::assertSame(0, $this->charges());
        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(self::CHAIN));
        self::assertCount($n * 3, \BccWire::$urls);
    }

    /** ⚠ The control: the opt-in is narrow — a node fault keeps its retries and charges. */
    public function testANodeFaultOnTheProbeStillRetriesAndCharges(): void
    {
        \BccWire::$always = self::nodeFault();

        $outcomes = $this->fetcher()->probeCw721(self::CONTRACT);

        self::assertCount(3, $outcomes);
        foreach ($outcomes as $outcome) {
            self::assertSame(CosmwasmClassifier::KIND_NODE_ERROR, $outcome['kind'], $outcome['probe']);
        }
        self::assertGreaterThan(3, count(\BccWire::$urls), 'a node fault keeps its retries');
        self::assertNotSame([], \BccWire::$sleeps);
        self::assertSame(3, $this->charges(), 'one charge per logical request');
    }
}
