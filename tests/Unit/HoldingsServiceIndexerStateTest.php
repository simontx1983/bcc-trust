<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\HoldingsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Pins HoldingsService::getIndexerStateForChain after the Bug-C fix
 * (2026-07-06 audit): non-checkpointed chains (Solana event-driven,
 * Cosmos read-time) report `healthy` with an empty label instead of a
 * permanent "Syncing…". EVM (checkpointed walker) keeps its real state
 * derived from the checkpoint row.
 *
 * Isolation: resolver-stub pattern — nft-indexer-stubs.php stubs
 * ChainRepository + ChainCheckpointRepository at their production FQNs.
 */
#[CoversClass(HoldingsService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HoldingsServiceIndexerStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';

        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::reset();
    }

    private function setChain(string $slug, string $chainType): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::$chain = (object) [
            'id'         => 7,
            'slug'       => $slug,
            'chain_type' => $chainType,
        ];
    }

    private function setCheckpoint(?string $state): void
    {
        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::$checkpoint =
            $state === null ? null : (object) ['chain_id' => 7, 'state' => $state];
    }

    public function testSolanaWithoutCheckpointIsHealthyNotSyncing(): void
    {
        $this->setChain('solana', 'solana');
        $this->setCheckpoint(null); // Solana never creates a checkpoint row.

        $result = HoldingsService::getIndexerStateForChain('solana');

        self::assertSame('healthy', $result['indexer_state']['solana']);
        // Empty label → the frontend hides the chip.
        self::assertSame('', $result['indexer_state_label']['solana']);
    }

    public function testCosmosWithoutCheckpointIsHealthy(): void
    {
        $this->setChain('cosmoshub', 'cosmos');
        $this->setCheckpoint(null);

        $result = HoldingsService::getIndexerStateForChain('cosmoshub');

        self::assertSame('healthy', $result['indexer_state']['cosmoshub']);
        self::assertSame('', $result['indexer_state_label']['cosmoshub']);
    }

    public function testEvmWithoutCheckpointStillReportsSyncing(): void
    {
        $this->setChain('optimism', 'evm');
        $this->setCheckpoint(null); // No walker checkpoint yet → genuinely syncing.

        $result = HoldingsService::getIndexerStateForChain('optimism');

        self::assertSame('syncing', $result['indexer_state']['optimism']);
        self::assertSame('Syncing on-chain holdings…', $result['indexer_state_label']['optimism']);
    }

    public function testEvmDegradedCheckpointReportsDegraded(): void
    {
        $this->setChain('optimism', 'evm');
        $this->setCheckpoint(\BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::STATE_DEGRADED);

        $result = HoldingsService::getIndexerStateForChain('optimism');

        self::assertSame('degraded', $result['indexer_state']['optimism']);
    }

    public function testEvmHealthyCheckpointReportsHealthy(): void
    {
        $this->setChain('optimism', 'evm');
        $this->setCheckpoint(\BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::STATE_HEALTHY);

        $result = HoldingsService::getIndexerStateForChain('optimism');

        self::assertSame('healthy', $result['indexer_state']['optimism']);
        self::assertSame('', $result['indexer_state_label']['optimism']);
    }
}
