<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\StargazeMarketplaceApi;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Pins the parse/validate/fail-unknown contract on the Stargaze
 * marketplace client behind Cosmos wallet-link discovery and the
 * demand map.
 *
 * Invariants under test:
 *   - Any unreadable response (transport error, non-200, malformed
 *     body) → null, never []. "Holds nothing" and "couldn't check"
 *     must stay distinguishable — the demand map treats null wallets
 *     as skips, not zeros.
 *   - Only bech32-plausible cosmos1 CONTRACT addresses survive
 *     normalization; junk rows are dropped silently.
 *   - Wallet-address input is validated before any transport call.
 *
 * Isolation: resolver-stubs pattern (see EvmFetcherTransfersTest).
 */
#[CoversClass(StargazeMarketplaceApi::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StargazeMarketplaceApiTest extends TestCase
{
    private const WALLET = 'cosmos15y38ehvexp6275ptmm4jj3qdds379nk02heclj';

    /** 59-char bech32 body — a real Hub CW-721 contract shape. */
    private const CONTRACT = 'cosmos1rm0km0j6c2yzvdan7llgmmklhsmv36qj9gqqxe5msxw5l2xsg8uswlydgq';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';
        \BCC\Trust\Onchain\Support\ApiRetry::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestObjectCache::reset();
    }

    /** @param array<string, mixed> $payload */
    private function queueJson(array $payload, int $code = 200): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = [
            'body' => (string) json_encode($payload),
            'code' => $code,
        ];
    }

    public function testMalformedWalletAddressShortCircuitsWithoutTransport(): void
    {
        self::assertNull(StargazeMarketplaceApi::profileCollections('stars1notahubaddress'));
        self::assertNull(StargazeMarketplaceApi::profileCollections(self::CONTRACT)); // contract, not account
        self::assertSame([], \BCC\Trust\Onchain\Support\ApiRetry::$calls);
    }

    public function testTransportErrorReturnsNullNotEmpty(): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = new \WP_Error('cURL error 28');

        self::assertNull(StargazeMarketplaceApi::profileCollections(self::WALLET));
    }

    public function testNon200ReturnsNull(): void
    {
        $this->queueJson(['error' => 'nope'], 530);

        self::assertNull(StargazeMarketplaceApi::profileCollections(self::WALLET));
    }

    public function testMalformedBodyReturnsNull(): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = ['body' => 'upstream html error page'];

        self::assertNull(StargazeMarketplaceApi::profileCollections(self::WALLET));
    }

    public function testNormalizesRollupAndDropsJunkRows(): void
    {
        $this->queueJson([
            'total'       => 4,
            'collections' => [
                [
                    'contractAddress'  => strtoupper(self::CONTRACT), // case-normalized
                    'name'             => '  Bad Kids  ',
                    'ownedTokensCount' => 3,
                    'totalTokensCount' => 9999,
                    'media'            => ['url' => 'https://cdn.example/img.png'],
                ],
                // Junk: not a contract-shaped address.
                ['contractAddress' => 'cosmos1shortaddr', 'ownedTokensCount' => 5],
                // Junk: zero owned (stale rollup row).
                ['contractAddress' => self::CONTRACT, 'ownedTokensCount' => 0],
                // Junk: not an array.
                'garbage',
            ],
        ]);

        $rollup = StargazeMarketplaceApi::profileCollections(self::WALLET);

        self::assertNotNull($rollup);
        self::assertCount(1, $rollup);
        self::assertSame(strtolower(self::CONTRACT), $rollup[0]['contract_address']);
        self::assertSame('Bad Kids', $rollup[0]['collection_name']);
        self::assertSame(3, $rollup[0]['owned_count']);
        self::assertSame(9999, $rollup[0]['total_supply']);
        self::assertSame('https://cdn.example/img.png', $rollup[0]['image_url']);
    }

    public function testGenuinelyEmptyRollupIsEmptyArrayAndCached(): void
    {
        $this->queueJson(['total' => 0, 'collections' => []]);

        self::assertSame([], StargazeMarketplaceApi::profileCollections(self::WALLET));

        // Second call served from cache — no new transport call.
        $callsAfterFirst = count(\BCC\Trust\Onchain\Support\ApiRetry::$calls);
        self::assertSame([], StargazeMarketplaceApi::profileCollections(self::WALLET));
        self::assertCount($callsAfterFirst, \BCC\Trust\Onchain\Support\ApiRetry::$calls);
    }

    public function testFailureIsNotCached(): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = new \WP_Error('down');
        self::assertNull(StargazeMarketplaceApi::profileCollections(self::WALLET));

        // Recovery on the next call — the failure never poisoned the cache.
        $this->queueJson(['total' => 0, 'collections' => []]);
        self::assertSame([], StargazeMarketplaceApi::profileCollections(self::WALLET));
    }

    public function testStopsPagingOnShortPage(): void
    {
        $row = static fn(string $suffix): array => [
            'contractAddress'  => substr(self::CONTRACT, 0, -1) . $suffix,
            'ownedTokensCount' => 1,
        ];
        // One short page (< 100 rows) — the client must not fetch page 2.
        $this->queueJson(['total' => 2, 'collections' => [$row('a'), $row('c')]]);

        $rollup = StargazeMarketplaceApi::profileCollections(self::WALLET);

        self::assertNotNull($rollup);
        self::assertCount(2, $rollup);
        self::assertCount(1, \BCC\Trust\Onchain\Support\ApiRetry::$calls);
    }
}
