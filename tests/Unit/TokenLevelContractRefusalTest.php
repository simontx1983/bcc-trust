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
 * F-154. THE INVARIANT: a contract refusing a TOKEN-LEVEL query (no such
 * token, invalid token id) costs ONE request and ZERO breaker charges.
 *
 * ── WHY IT MATTERS ──────────────────────────────────────────────────────
 * The NFT-piece route is anonymous. Before this fix every bogus token id on
 * a valid contract earned ApiRetry's full retry ladder (4 attempts, 8s of
 * backoff, a pinned PHP worker) and one breaker charge. FAILURE_THRESHOLD is
 * 5, so five anonymous requests opened the chain's breaker for 300s, and
 * with it validator indexing, holdings and the delegation gate.
 *
 * ── THE BODIES ARE MEASURED, NOT INVENTED ───────────────────────────────
 * Captured 2026-11-10 from public LCDs (rest.cosmos.directory), `nft_info`
 * for a token id that cannot exist, both HTTP 500:
 *   cosmoshub  `…; key: […] not found: query wasm contract failed`
 *   injective  `Cannot decode UTF8 bytes into string: invalid token_id: query wasm contract failed`
 * Neither matched the pre-existing smart-query predicate: the first
 * classifies NOT_FOUND, the second falls to the >=500 NODE_ERROR fallback.
 *
 * Same real stack as CollectionMetadataApplicationErrorTest: only the WIRE
 * is scripted; ApiRetry and the breaker are real.
 */
#[CoversClass(CosmosFetcher::class)]
#[CoversClass(CosmwasmClassifier::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class TokenLevelContractRefusalTest extends TestCase
{
    private const CHAIN    = 95;
    private const CONTRACT = 'cosmos1tokenlevelrefusaltestcontractaddressvalue';

    private const MSG_COSMOSHUB = 'type: cw721::state::NftInfo<core::option::Option<cosmwasm_std::results::empty::Empty>>; key: [00, 06, 74, 6F, 6B, 65, 6E, 73, 62, 63, 63] not found: query wasm contract failed';
    private const MSG_INJECTIVE = 'Cannot decode UTF8 bytes into string: invalid token_id: query wasm contract failed';

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

    /** @return array{code: int, body: string} */
    private static function lcd500(string $message, int $grpcCode = 2): array
    {
        return [
            'code' => 500,
            'body' => json_encode(['code' => $grpcCode, 'message' => $message, 'details' => []], JSON_THROW_ON_ERROR),
        ];
    }

    // ── 1. the classifier predicate ──────────────────────────────────────

    public function testContractRefusalPredicate(): void
    {
        self::assertTrue(CosmwasmClassifier::isContractRefusal(self::MSG_COSMOSHUB));
        self::assertTrue(CosmwasmClassifier::isContractRefusal(self::MSG_INJECTIVE));
        self::assertTrue(CosmwasmClassifier::isContractRefusal(
            'Error parsing into type cw721::msg::QueryMsg: unknown variant `x`: query wasm contract failed'
        ));

        // A VM / node fault wrapped in the same marker is still a node fault.
        self::assertFalse(CosmwasmClassifier::isContractRefusal('Error calling the VM: Cache error: query wasm contract failed'));
        self::assertFalse(CosmwasmClassifier::isContractRefusal('out of gas: query wasm contract failed'));
        self::assertFalse(CosmwasmClassifier::isContractRefusal('rpc error: code = Unknown desc = not found: query wasm contract failed'));
        // No marker: no evidence the contract ran.
        self::assertFalse(CosmwasmClassifier::isContractRefusal('not found'));
        self::assertFalse(CosmwasmClassifier::isContractRefusal('rpc error: code = Unavailable desc = connection refused'));
        self::assertFalse(CosmwasmClassifier::isContractRefusal(''));
    }

    // ── 2. nft_info refusals: one request, no backoff, no charge ─────────

    public function testMeasuredRefusalsCostOneRequestAndNoCharge(): void
    {
        foreach ([self::MSG_COSMOSHUB, self::MSG_INJECTIVE] as $i => $message) {
            \BccWire::reset();
            \BccBreakerStore::reset();
            \BccWire::$always = self::lcd500($message);

            $meta = $this->fetcher()->fetchTokenMetadata(self::CONTRACT, 'bogus-token-' . $i);

            self::assertNull($meta['name'], $message);
            self::assertSame([], $meta['attributes']);
            self::assertCount(1, \BccWire::$urls, 'a refusal is an answer: exactly one wire call');
            self::assertSame([], \BccWire::$sleeps, 'no retry, so no backoff');
            self::assertSame(0, $this->charges(), 'the contract answering is not the node failing');
        }
    }

    public function testOwnerOfRefusalCostsOneRequestAndNoCharge(): void
    {
        \BccWire::$always = self::lcd500(self::MSG_COSMOSHUB);

        self::assertNull($this->fetcher()->cw721OwnerOf(self::CONTRACT, 'bogus-token'));
        self::assertCount(1, \BccWire::$urls);
        self::assertSame([], \BccWire::$sleeps);
        self::assertSame(0, $this->charges());
    }

    /** The F-154 attack: more bogus requests than the breaker threshold. */
    public function testManyBogusTokenRequestsNeverOpenTheBreaker(): void
    {
        \BccWire::$always = self::lcd500(self::MSG_COSMOSHUB);
        $fetcher = $this->fetcher();

        $n = OnchainCircuitBreaker::FAILURE_THRESHOLD * 2;
        for ($i = 0; $i < $n; $i++) {
            $fetcher->fetchTokenMetadata(self::CONTRACT, 'bogus-' . $i);
            $fetcher->cw721OwnerOf(self::CONTRACT, 'bogus-' . $i);
        }

        self::assertSame(0, $this->charges());
        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(self::CHAIN));
        self::assertCount($n * 2, \BccWire::$urls, 'one call per logical request, none retried');
    }

    // ── 3. controls ──────────────────────────────────────────────────────

    public function testANodeFaultStillRetriesAndCharges(): void
    {
        \BccWire::$always = self::lcd500('rpc error: code = Internal desc = Querier system error: node is not caught up', 13);

        $this->fetcher()->fetchTokenMetadata(self::CONTRACT, 'real-token');

        self::assertGreaterThan(1, count(\BccWire::$urls), 'a node fault keeps its retries');
        self::assertNotSame([], \BccWire::$sleeps);
        self::assertSame(1, $this->charges(), 'and its breaker charge');
    }

    /**
     * ⚠ The control for the fix: the same measured body through the
     * NON-opt-in path still retries and charges, so the improvement comes
     * from the opt-in and not from the wire script.
     */
    public function testTheSameBodyWithoutTheOptInStillCharges(): void
    {
        \BccWire::$always = self::lcd500(self::MSG_COSMOSHUB);

        $legacy = new \ReflectionMethod(CosmosFetcher::class, 'wasmSmartQuery');
        $legacy->setAccessible(true);
        self::assertNull($legacy->invoke($this->fetcher(), self::CONTRACT, ['nft_info' => ['token_id' => 'x']]));

        self::assertGreaterThan(1, count(\BccWire::$urls));
        self::assertSame(1, $this->charges());
    }
}
