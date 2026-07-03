<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Pins the V1 wallet-link discovery contract on
 * CosmosFetcher::fetch_collections.
 *
 * Invariants under test:
 *   - Hub-only: any cosmos chain other than slug `cosmos` returns []
 *     without a transport call (the Stargaze marketplace indexes the
 *     Hub only).
 *   - API failure → [] (discovery is a best-effort enhancement; the
 *     wallet-link seed path must never see an exception or a lie).
 *   - Rows map to the CollectionRepository::upsert shape with
 *     CW-721 standard and the discovering chain id.
 *   - Per-wallet cap keeps the LARGEST holdings when a hoarder wallet
 *     exceeds it.
 *
 * Isolation: resolver-stubs pattern (see EvmFetcherTransfersTest).
 */
#[CoversClass(CosmosFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmosFetcherDiscoveryTest extends TestCase
{
    private const WALLET = 'cosmos15y38ehvexp6275ptmm4jj3qdds379nk02heclj';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';
        \BCC\Trust\Onchain\Support\ApiRetry::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestObjectCache::reset();
    }

    private function makeFetcher(string $slug = 'cosmos', int $id = 8): CosmosFetcher
    {
        return new CosmosFetcher((object) [
            'id'         => $id,
            'slug'       => $slug,
            'chain_type' => 'cosmos',
            'rest_url'   => 'https://lcd.example',
            'decimals'   => 6,
        ]);
    }

    /** Distinct contract-shaped address per index ('1' isn't bech32 — map it). */
    private static function contract(int $i): string
    {
        return 'cosmos1' . str_pad(strtr((string) $i, ['1' => 'z']), 58, 'q', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $payload */
    private function queueJson(array $payload): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = [
            'body' => (string) json_encode($payload),
        ];
    }

    public function testAdvertisesCollectionFeature(): void
    {
        self::assertTrue($this->makeFetcher()->supports_feature('collection'));
    }

    public function testNonHubCosmosChainReturnsEmptyWithoutTransport(): void
    {
        $result = $this->makeFetcher('osmosis', 9)->fetch_collections(self::WALLET);

        self::assertSame([], $result);
        self::assertSame([], \BCC\Trust\Onchain\Support\ApiRetry::$calls);
    }

    public function testApiFailureReturnsEmptyNotException(): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = new \WP_Error('down');

        self::assertSame([], $this->makeFetcher()->fetch_collections(self::WALLET));
    }

    public function testMapsRollupToUpsertShape(): void
    {
        $this->queueJson([
            'total'       => 1,
            'collections' => [[
                'contractAddress'  => self::contract(1),
                'name'             => 'Bad Kids',
                'ownedTokensCount' => 2,
                'totalTokensCount' => 9999,
                'media'            => ['url' => 'https://cdn.example/bk.png'],
            ]],
        ]);

        $rows = $this->makeFetcher()->fetch_collections(self::WALLET);

        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame(self::contract(1), $row['contract_address']);
        self::assertSame('Bad Kids', $row['collection_name']);
        self::assertSame(8, $row['chain_id']);
        self::assertSame('CW-721', $row['token_standard']);
        self::assertSame(9999, $row['total_supply']);
        self::assertSame('https://cdn.example/bk.png', $row['image_url']);
        self::assertNull($row['unique_holders']);
    }

    public function testCapKeepsLargestHoldings(): void
    {
        // 60 collections, owned counts 1..60 — the cap (50) must keep
        // the TOP 50 by owned count, i.e. counts 11..60.
        $collections = [];
        for ($i = 1; $i <= 60; $i++) {
            $collections[] = [
                'contractAddress'  => self::contract($i),
                'name'             => 'C' . $i,
                'ownedTokensCount' => $i,
            ];
        }
        $this->queueJson(['total' => 60, 'collections' => $collections]);

        $rows = $this->makeFetcher()->fetch_collections(self::WALLET);

        self::assertCount(50, $rows);
        self::assertSame('C60', $rows[0]['collection_name']); // biggest first
        $names = array_column($rows, 'collection_name');
        self::assertNotContains('C10', $names); // smallest fell off
        self::assertContains('C11', $names);
    }
}
