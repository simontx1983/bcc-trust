<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Pins the PARALLELIZED CW-721 gallery contract on
 * CosmosFetcher::list_holdings (feat/cosmos-gallery-parallel).
 *
 * The refactor replaced the ~30× sequential first-page `tokens{owner}`
 * walk with ONE concurrent same-host batch (Phase A) followed by the
 * cheap sequential metadata/pagination pass (Phase B). This test locks:
 *
 *   - list_holdings assembles the SAME §H1 item shape it always did,
 *     given stubbed batch first-page + per-token metadata results;
 *   - a first page under the page size is treated as the complete set
 *     (no wasteful walk); the item order matches listVerifiedByChain order;
 *   - Phase A writes each first page back to the shared cw721_tokens
 *     cache so the single-path count_holdings reads the SAME rows
 *     (no second network call, and no divergence between gallery + gate);
 *   - a per-URL batch failure (404 / WP_Error) omits ONLY that
 *     collection — the null-vs-empty fail-open distinction survives.
 *
 * Isolation: resolver-stubs pattern (see CosmosFetcherDiscoveryTest).
 */
#[CoversClass(CosmosFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmosFetcherListHoldingsTest extends TestCase
{
    private const CHAIN_ID = 251;
    private const WALLET   = 'inj16naevyffqm33znyf5aky86z8s09zvpyg8u8vtl';
    private const REST     = 'https://lcd.example';

    private const CONTRACT_A = 'inj1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CONTRACT_B = 'inj1bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const CONTRACT_C = 'inj1ccccccccccccccccccccccccccccccccccccccc';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';
        \BCC\Trust\Onchain\Support\ApiRetry::reset();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestObjectCache::reset();
    }

    private function makeFetcher(): CosmosFetcher
    {
        return new CosmosFetcher((object) [
            'id'         => self::CHAIN_ID,
            'slug'       => 'injective',
            'chain_type' => 'cosmos',
            'rest_url'   => self::REST,
            'decimals'   => 18,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function registerCollection(string $contract, string $name, ?string $image = null): void
    {
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$knownByChain[self::CHAIN_ID][] = (object) [
            'contract_address' => $contract,
            'collection_name'  => $name,
            'image_url'        => $image,
            'is_verified'      => 1,
        ];
    }

    /**
     * Reproduce the production encoding so the batch stub can key off the
     * exact URL list_holdings will request for a first page.
     */
    private function firstPageUrl(string $contract, int $limit = 30): string
    {
        $query   = ['tokens' => ['owner' => self::WALLET, 'limit' => $limit]];
        $encoded = strtr(base64_encode((string) json_encode($query)), '+/', '-_');
        return self::REST . '/cosmwasm/wasm/v1/contract/' . rawurlencode($contract) . '/smart/' . $encoded;
    }

    /** Register a successful first-page batch response for a contract. */
    private function registerFirstPage(string $contract, string ...$tokenIds): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$batchResponses[$this->firstPageUrl($contract)] = [
            'code' => 200,
            'body' => (string) json_encode(['data' => ['tokens' => array_values($tokenIds)]]),
        ];
    }

    /** Queue the per-token nft_info metadata response (ApiRetry::get FIFO). */
    private function queueMetadata(string $name, ?string $image, ?string $uri): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = [
            'body' => (string) json_encode([
                'data' => [
                    'token_uri' => $uri,
                    'extension' => ['name' => $name, 'image' => $image],
                ],
            ]),
        ];
    }

    public function testAssemblesCanonicalItemShapeFromBatchAndMetadata(): void
    {
        $this->registerCollection(self::CONTRACT_A, 'Galactic Syndicate', 'https://cdn.example/gs.png');
        $this->registerFirstPage(self::CONTRACT_A, '7', '42');
        // Metadata is consumed in token order; queue matches ['7','42'].
        $this->queueMetadata('Syndicate #7', 'https://cdn.example/7.png', 'ipfs://7');
        $this->queueMetadata('Syndicate #42', null, 'ipfs://42');

        $result = $this->makeFetcher()->list_holdings(self::WALLET);

        self::assertFalse($result['truncated']);
        self::assertNull($result['cursor']);
        self::assertCount(2, $result['items']);

        $first = $result['items'][0];
        self::assertSame(self::CONTRACT_A, $first['contract_address']);
        self::assertSame('7', $first['token_id']);
        self::assertSame(self::CHAIN_ID, $first['chain_id']);
        self::assertSame('Galactic Syndicate', $first['collection_name']);
        self::assertSame('Syndicate #7', $first['name']);
        self::assertSame('https://cdn.example/7.png', $first['image_url']);
        self::assertSame('ipfs://7', $first['metadata_uri']);
        self::assertSame('CW-721', $first['token_standard']);

        // Second token has no per-token image → falls back to the
        // collection image (unchanged pre-existing behaviour).
        $second = $result['items'][1];
        self::assertSame('42', $second['token_id']);
        self::assertSame('https://cdn.example/gs.png', $second['image_url']);
        self::assertSame('ipfs://42', $second['metadata_uri']);

        // Exactly ONE batch call covered all first pages (the whole point).
        self::assertCount(1, \BCC\Trust\Onchain\Support\ApiRetry::$batchCalls);
        self::assertSame(self::CHAIN_ID, \BCC\Trust\Onchain\Support\ApiRetry::$batchCalls[0]['chain_id']);
    }

    public function testPreservesKnownOrderAndOmitsEmptyOrFailedContracts(): void
    {
        // A: owns tokens. B: owns none (empty first page → omit).
        // C: batch 404 (not registered) → failed page → omit.
        $this->registerCollection(self::CONTRACT_A, 'Alpha');
        $this->registerCollection(self::CONTRACT_B, 'Bravo');
        $this->registerCollection(self::CONTRACT_C, 'Charlie');

        $this->registerFirstPage(self::CONTRACT_A, '1');
        $this->registerFirstPage(self::CONTRACT_B); // empty → owns none
        // C intentionally unregistered → stub returns 404 → null page.

        $this->queueMetadata('Alpha #1', null, null);

        $result = $this->makeFetcher()->list_holdings(self::WALLET);

        self::assertCount(1, $result['items']);
        self::assertSame(self::CONTRACT_A, $result['items'][0]['contract_address']);
    }

    public function testFirstPageWrittenToSharedCacheForCountPath(): void
    {
        $this->registerCollection(self::CONTRACT_A, 'Alpha');
        $this->registerFirstPage(self::CONTRACT_A, '1', '2', '3');
        $this->queueMetadata('A1', null, null);
        $this->queueMetadata('A2', null, null);
        $this->queueMetadata('A3', null, null);

        $fetcher = $this->makeFetcher();
        $fetcher->list_holdings(self::WALLET);

        // The single-path count MUST now be served from the cache the
        // gallery warmed — no NEW batch call, no extra ApiRetry::get.
        $batchCallsBefore = count(\BCC\Trust\Onchain\Support\ApiRetry::$batchCalls);
        $count = $fetcher->count_holdings(self::WALLET, self::CONTRACT_A);

        self::assertSame(3, $count);
        self::assertSame(
            $batchCallsBefore,
            count(\BCC\Trust\Onchain\Support\ApiRetry::$batchCalls),
            'count_holdings must reuse the cached first page, not re-batch'
        );
    }

    public function testCacheHitSkipsTheBatchEntirely(): void
    {
        $this->registerCollection(self::CONTRACT_A, 'Alpha');
        // Pre-warm the shared cache directly (simulates a prior warm load).
        $key = sprintf(
            'cw721_tokens_%d_%s_%s_',
            self::CHAIN_ID,
            strtolower(self::CONTRACT_A),
            strtolower(self::WALLET)
        );
        \BccTestObjectCache::$store['bcc_onchain:' . $key] = ['9'];
        $this->queueMetadata('Cached #9', null, null);

        $result = $this->makeFetcher()->list_holdings(self::WALLET);

        self::assertCount(1, $result['items']);
        self::assertSame('9', $result['items'][0]['token_id']);
        // No misses → no batch call at all.
        self::assertSame([], \BCC\Trust\Onchain\Support\ApiRetry::$batchCalls);
    }

    public function testEmptyKnownSetReturnsEmpty(): void
    {
        $result = $this->makeFetcher()->list_holdings(self::WALLET);
        self::assertSame(['items' => [], 'truncated' => false, 'cursor' => null], $result);
        self::assertSame([], \BCC\Trust\Onchain\Support\ApiRetry::$batchCalls);
    }
}
