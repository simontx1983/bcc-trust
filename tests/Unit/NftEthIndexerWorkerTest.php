<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Workers\NftEthIndexerWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Pins the NftEthIndexerWorker tick behaviors added in the zero-wallet /
 * fetch-error hardening:
 *
 *   1. Zero-wallet short-circuit — a chain with no linked wallets follows
 *      the head (checkpoint → safe_head) with ONLY the eth_blockNumber
 *      poll; no getAssetTransfers paging, no CU spend.
 *   2. Fetch-error abort — a failed transfer page (fetcher returns null)
 *      must NOT advance the checkpoint over unread blocks; degraded state
 *      + circuit-breaker failure are recorded.
 *   3. Safe partial advance on error — blocks proven read by SUCCESSFUL
 *      pages before the failure still advance to maxBlockSeen - 1.
 *   4. Happy path regression — full drain still advances to rangeTo.
 *
 * Isolation: resolver-stubs pattern. The REAL worker and REAL EvmFetcher
 * run in the subprocess; transport (ApiRetry), repositories, locks and
 * observability are stubbed at production FQNs by nft-indexer-stubs.php.
 */
#[CoversClass(NftEthIndexerWorker::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftEthIndexerWorkerTest extends TestCase
{
    private const CHAIN_ID = 4;

    /** Head block 1000 → safe head 988 (CONFIRMATIONS = 12). */
    private const HEAD_BLOCK = 1000;
    private const SAFE_HEAD  = 988;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';

        \BCC\Trust\Onchain\Support\ApiRetry::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Core\Observability\DegradationMetrics::reset();
        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\WalletRepository::reset();
        \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::reset();
        \BCC\Trust\Onchain\Services\NftHoldingsIndexer::reset();

        \BCC\Trust\Onchain\Repositories\ChainRepository::$chain = (object) [
            'id'         => self::CHAIN_ID,
            'slug'       => 'optimism',
            'chain_type' => 'evm',
            'rpc_url'    => 'https://opt.example/v2/testkey',
        ];
        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$checkpoint = (object) [
            'chain_id'             => self::CHAIN_ID,
            'state'                => 'healthy',
            'last_processed_block' => 500,
        ];
    }

    private function queueHeadBlock(): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = [
            'body' => (string) json_encode([
                'jsonrpc' => '2.0',
                'id'      => 1,
                'result'  => '0x' . dechex(self::HEAD_BLOCK),
            ]),
        ];
    }

    /**
     * @param list<int> $blockNumbers one transfer per block number
     */
    private function queueTransferPage(array $blockNumbers, ?string $pageKey): void
    {
        $transfers = [];
        foreach ($blockNumbers as $i => $block) {
            $transfers[] = [
                'rawContract' => ['address' => '0xabcdef000000000000000000000000000000000' . ($i % 10)],
                'tokenId'     => '0x' . dechex($i + 1),
                'category'    => 'erc721',
                'blockNum'    => '0x' . dechex($block),
                'from'        => '0x0000000000000000000000000000000000000000',
                'to'          => '0x1111111111111111111111111111111111111111',
                'value'       => 1,
                'metadata'    => ['blockTimestamp' => '2026-07-01T00:00:00.000Z'],
                'asset'       => 'TestNFT',
            ];
        }
        $result = ['transfers' => $transfers];
        if ($pageKey !== null) {
            $result['pageKey'] = $pageKey;
        }
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = [
            'body' => (string) json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result]),
        ];
    }

    public function testZeroWalletChainFollowsHeadWithoutTransferPaging(): void
    {
        \BCC\Trust\Onchain\Repositories\WalletRepository::$hasLinks = false;
        $this->queueHeadBlock();

        NftEthIndexerWorker::runForChain(self::CHAIN_ID);

        // Checkpoint jumped to safe head, marked healthy.
        self::assertSame(
            [['chain_id' => self::CHAIN_ID, 'block' => self::SAFE_HEAD, 'head' => self::HEAD_BLOCK]],
            \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$successes
        );
        self::assertSame([], \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$failures);

        // ONLY the eth_blockNumber poll went out — no getAssetTransfers.
        $calls = \BCC\Trust\Onchain\Support\ApiRetry::$calls;
        self::assertCount(1, $calls);
        self::assertStringContainsString('eth_blockNumber', $calls[0]['body']);

        // No CU charged, breaker success, no stall metric.
        self::assertSame([], \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$cuAdds);
        self::assertSame([self::CHAIN_ID], \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::$successChains);
        self::assertSame([], \BCC\Core\Observability\DegradationMetrics::$records);
    }

    public function testFetchErrorHoldsCheckpointAndRecordsDegraded(): void
    {
        $this->queueHeadBlock();
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = new \WP_Error('cURL error 28');

        NftEthIndexerWorker::runForChain(self::CHAIN_ID);

        // No advance — the failed page proved nothing about the range.
        self::assertSame([], \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$successes);

        $failures = \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$failures;
        self::assertCount(1, $failures);
        self::assertSame('degraded', $failures[0]['state']);
        self::assertSame([self::CHAIN_ID], \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::$failureChains);
        self::assertSame([], \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::$successChains);
    }

    public function testFetchErrorAfterSuccessfulPageAdvancesToWatermarkMinusOne(): void
    {
        $this->queueHeadBlock();
        // Page 1: transfers up to block 600, more pages pending.
        $this->queueTransferPage([501, 550, 600], 'page-2-token');
        // Page 2: transport failure.
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = new \WP_Error('cURL error 28');

        NftEthIndexerWorker::runForChain(self::CHAIN_ID);

        // Safe partial advance: maxBlockSeen 600 → checkpoint 599.
        self::assertSame(
            [['chain_id' => self::CHAIN_ID, 'block' => 599, 'head' => self::HEAD_BLOCK]],
            \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$successes
        );

        // Still ends degraded with a breaker failure — the tick FAILED.
        $failures = \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$failures;
        self::assertCount(1, $failures);
        self::assertSame('degraded', $failures[0]['state']);
        self::assertSame([self::CHAIN_ID], \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::$failureChains);

        // Page-1 transfers were ingested before the failure (idempotent).
        self::assertSame(
            [['chain_id' => self::CHAIN_ID, 'count' => 3]],
            \BCC\Trust\Onchain\Services\NftHoldingsIndexer::$batches
        );
    }

    public function testFullDrainAdvancesToRangeTo(): void
    {
        $this->queueHeadBlock();
        // Single page, no pageKey → range [501, 988] fully drained.
        $this->queueTransferPage([510, 700], null);

        NftEthIndexerWorker::runForChain(self::CHAIN_ID);

        self::assertSame(
            [['chain_id' => self::CHAIN_ID, 'block' => self::SAFE_HEAD, 'head' => self::HEAD_BLOCK]],
            \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$successes
        );
        self::assertSame([], \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$failures);
        self::assertSame([self::CHAIN_ID], \BCC\Trust\Onchain\Support\OnchainCircuitBreaker::$successChains);
    }

    public function testDenseSingleBlockStallHoldsAndEmitsMetricWithWarning(): void
    {
        $this->queueHeadBlock();
        // Every page maxes out inside block 501 (== rangeFrom) with a
        // pending pageKey — the pathological dense-block case. The loop
        // reads MAX_PAGES_PER_TICK pages then gives up without a safe
        // advance target.
        for ($i = 0; $i < NftEthIndexerWorker::MAX_PAGES_PER_TICK; $i++) {
            $this->queueTransferPage([501, 501], 'page-' . ($i + 2));
        }

        NftEthIndexerWorker::runForChain(self::CHAIN_ID);

        self::assertSame([], \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$successes);
        self::assertSame(
            [['subsystem' => 'nft_indexer', 'event' => 'dense_block_stall']],
            \BCC\Core\Observability\DegradationMetrics::$records
        );

        // Defect-3 context log: the operator gets chain + range without
        // DB archaeology.
        $stallWarnings = array_values(array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn (array $l): bool => $l['level'] === 'warning'
                && str_contains($l['message'], 'dense block stall')
        ));
        self::assertCount(1, $stallWarnings);
        self::assertSame(self::CHAIN_ID, $stallWarnings[0]['context']['chain_id']);
        self::assertSame('[501, 988]', $stallWarnings[0]['context']['range']);
        self::assertSame(501, $stallWarnings[0]['context']['max_block_seen']);
    }
}
