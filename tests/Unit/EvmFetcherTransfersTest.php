<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\EvmFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Pins the error-vs-empty distinction on EvmFetcher::fetch_transfers_since.
 *
 * The invariant under test: any path where the fetcher CANNOT prove the
 * range was read (transport error, malformed body, JSON-RPC error,
 * missing result) returns null — never the empty-success shape. The
 * NftEthIndexerWorker advances its checkpoint on empty-success, so a
 * failure disguised as success permanently skips unread blocks.
 *
 * Isolation: every test runs in its own subprocess (resolver-stubs
 * pattern). setUp() loads tests/Stubs/nft-indexer-stubs.php, which
 * defines ApiRetry / Logger / WP shims at production FQNs before the
 * REAL EvmFetcher autoloads.
 */
#[CoversClass(EvmFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EvmFetcherTransfersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';
        \BCC\Trust\Onchain\Support\ApiRetry::reset();
        \BCC\Core\Log\Logger::reset();
    }

    private function makeFetcher(string $rpcUrl = 'https://eth.example/v2/testkey'): EvmFetcher
    {
        return new EvmFetcher((object) [
            'id'         => 1,
            'slug'       => 'ethereum',
            'chain_type' => 'evm',
            'rpc_url'    => $rpcUrl,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function queueJson(array $payload): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = [
            'body' => (string) json_encode($payload),
        ];
    }

    public function testTransportErrorReturnsNullNotEmptySuccess(): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = new \WP_Error('cURL error 28');

        self::assertNull($this->makeFetcher()->fetch_transfers_since(100, 200));
    }

    public function testNonJsonBodyReturnsNull(): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = ['body' => 'ETH_MAINNET is not enabled for this app.'];

        self::assertNull($this->makeFetcher()->fetch_transfers_since(100, 200));
    }

    public function testJsonRpcErrorMemberReturnsNull(): void
    {
        // Public RPCs that don't implement the Alchemy-proprietary method
        // answer exactly like this — historically read as "range drained".
        $this->queueJson([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'error'   => ['code' => -32601, 'message' => 'the method alchemy_getAssetTransfers does not exist'],
        ]);

        self::assertNull($this->makeFetcher()->fetch_transfers_since(100, 200));
    }

    public function testMissingResultFieldReturnsNull(): void
    {
        $this->queueJson(['jsonrpc' => '2.0', 'id' => 1]);

        self::assertNull($this->makeFetcher()->fetch_transfers_since(100, 200));
    }

    public function testPlaceholderRpcUrlReturnsNullWithoutTransportCall(): void
    {
        $result = $this->makeFetcher('https://eth.example/v2/')->fetch_transfers_since(100, 200);

        self::assertNull($result);
        self::assertSame([], \BCC\Trust\Onchain\Support\ApiRetry::$calls);
    }

    public function testInvalidRangeReturnsNullWithoutTransportCall(): void
    {
        $result = $this->makeFetcher()->fetch_transfers_since(200, 100);

        self::assertNull($result);
        self::assertSame([], \BCC\Trust\Onchain\Support\ApiRetry::$calls);
    }

    public function testGenuinelyEmptyRangeStaysEmptySuccessShape(): void
    {
        $this->queueJson([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'result'  => ['transfers' => []],
        ]);

        $result = $this->makeFetcher()->fetch_transfers_since(100, 200);

        self::assertSame(['transfers' => [], 'page_key' => null], $result);
    }

    public function testErc1155MetadataExpandsToPerTokenTransfers(): void
    {
        // Alchemy returns top-level tokenId: null for erc1155 — the ids
        // live in erc1155Metadata[] (one entry per token in the batch).
        // The pre-2026-07 reader keyed on top-level tokenId only and
        // dropped 100% of 1155 transfers (never-indexed 1155 holdings +
        // the Polygon dense_block_stall false positive).
        $this->queueJson([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'result'  => [
                'transfers' => [
                    [
                        'rawContract'     => ['address' => '0xABCDEF0000000000000000000000000000000002'],
                        'tokenId'         => null,
                        'category'        => 'erc1155',
                        'blockNum'        => '0x97', // 151
                        'from'            => '0x2222222222222222222222222222222222222222',
                        'to'              => '0x3333333333333333333333333333333333333333',
                        'value'           => null,
                        'erc1155Metadata' => [
                            ['tokenId' => '0x1', 'value' => '0x3'],
                            ['tokenId' => '0x2a', 'value' => '0x1'],
                        ],
                        'metadata'        => ['blockTimestamp' => '2026-07-01T00:00:00.000Z'],
                        'asset'           => 'Test1155',
                    ],
                ],
            ],
        ]);

        $result = $this->makeFetcher()->fetch_transfers_since(100, 200);

        self::assertNotNull($result);
        self::assertCount(2, $result['transfers']);
        [$a, $b] = $result['transfers'];
        self::assertSame('1', $a['token_id']);
        self::assertSame(3, $a['amount']);
        self::assertSame('42', $b['token_id']);
        self::assertSame(1, $b['amount']);
        foreach ([$a, $b] as $t) {
            self::assertSame('ERC-1155', $t['token_standard']);
            self::assertSame(151, $t['block_number']);
            self::assertSame('0x3333333333333333333333333333333333333333', $t['to_address']);
        }
    }

    public function testErc1155AmountClampsToBalanceColumnCeiling(): void
    {
        // Fungible-style 1155s mint astronomically large amounts; the
        // holdings `balance` column is INT UNSIGNED, so an unclamped
        // amount would error the whole strict-mode upsert batch.
        $this->queueJson([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'result'  => [
                'transfers' => [
                    [
                        'rawContract'     => ['address' => '0xABCDEF0000000000000000000000000000000003'],
                        'tokenId'         => null,
                        'category'        => 'erc1155',
                        'blockNum'        => '0x98',
                        'erc1155Metadata' => [
                            // 2^64 — far past the 4294967295 ceiling.
                            ['tokenId' => '0x5', 'value' => '0x10000000000000000'],
                        ],
                        'metadata'        => ['blockTimestamp' => '2026-07-01T00:00:00.000Z'],
                    ],
                ],
            ],
        ]);

        $result = $this->makeFetcher()->fetch_transfers_since(100, 200);

        self::assertNotNull($result);
        self::assertCount(1, $result['transfers']);
        self::assertSame(4294967295, $result['transfers'][0]['amount']);
    }

    public function testErc1155WithoutTokenIdAnywhereIsSkipped(): void
    {
        $this->queueJson([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'result'  => [
                'transfers' => [
                    [
                        'rawContract'     => ['address' => '0xABCDEF0000000000000000000000000000000004'],
                        'tokenId'         => null,
                        'category'        => 'erc1155',
                        'blockNum'        => '0x99',
                        'erc1155Metadata' => [],
                        'metadata'        => ['blockTimestamp' => '2026-07-01T00:00:00.000Z'],
                    ],
                ],
            ],
        ]);

        $result = $this->makeFetcher()->fetch_transfers_since(100, 200);

        self::assertNotNull($result);
        self::assertSame([], $result['transfers']);
    }

    public function testSuccessfulPageParsesTransfersAndPageKey(): void
    {
        $this->queueJson([
            'jsonrpc' => '2.0',
            'id'      => 1,
            'result'  => [
                'transfers' => [
                    [
                        'rawContract' => ['address' => '0xABCDEF0000000000000000000000000000000001'],
                        'tokenId'     => '0x2a',
                        'category'    => 'erc721',
                        'blockNum'    => '0x96', // 150
                        'from'        => '0x0000000000000000000000000000000000000000',
                        'to'          => '0x1111111111111111111111111111111111111111',
                        'value'       => 1,
                        'metadata'    => ['blockTimestamp' => '2026-07-01T00:00:00.000Z'],
                        'asset'       => 'TestNFT',
                    ],
                ],
                'pageKey' => 'next-page-token',
            ],
        ]);

        $result = $this->makeFetcher()->fetch_transfers_since(100, 200);

        self::assertNotNull($result);
        self::assertSame('next-page-token', $result['page_key']);
        self::assertCount(1, $result['transfers']);
        $t = $result['transfers'][0];
        self::assertSame('0xabcdef0000000000000000000000000000000001', $t['contract_address']);
        self::assertSame('42', $t['token_id']); // hex 0x2a → decimal string
        self::assertSame(150, $t['block_number']);
        self::assertSame('ERC-721', $t['token_standard']);
    }
}
