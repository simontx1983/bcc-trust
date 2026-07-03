<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\CollectionDemandService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Pins the demand-map contract: distinct linked wallets per
 * (chain, contract), merged from the holdings index (EVM/SOL) and the
 * Stargaze marketplace rollups (Cosmos Hub).
 *
 * Invariants under test:
 *   - EVM counts pass through from the holdings aggregate.
 *   - Cosmos counts = number of DISTINCT wallets whose rollup contains
 *     the contract; an unreadable wallet contributes nothing (floor
 *     semantics, never an inflated count).
 *   - Composite keys are chain-scoped and case-insensitive on contract.
 *
 * Isolation: resolver-stubs pattern — the REAL CollectionDemandService
 * + StargazeMarketplaceApi run against stubbed repositories/transport.
 */
#[CoversClass(CollectionDemandService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CollectionDemandServiceTest extends TestCase
{
    private const WALLET_A = 'cosmos15y38ehvexp6275ptmm4jj3qdds379nk02heclj';
    private const WALLET_B = 'cosmos15y38ehvexp6275ptmm4jj3qdds379nk02heclk';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';
        \BCC\Trust\Onchain\Support\ApiRetry::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestObjectCache::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\WalletRepository::reset();
        \BCC\Trust\Onchain\Repositories\NftHoldingsRepository::reset();
    }

    private static function contract(int $i): string
    {
        // '1' isn't in the bech32 data alphabet — map it out.
        return 'cosmos1' . str_pad(strtr((string) $i, ['1' => 'z']), 58, 'q', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $payload */
    private function queueJson(array $payload): void
    {
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = [
            'body' => (string) json_encode($payload),
        ];
    }

    private function setUpCosmosChain(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::$chain = (object) [
            'id'         => 8,
            'slug'       => 'cosmos',
            'chain_type' => 'cosmos',
        ];
    }

    public function testHoldingsIndexCountsPassThrough(): void
    {
        \BCC\Trust\Onchain\Repositories\NftHoldingsRepository::$walletCounts = [
            (object) ['chain_id' => '1', 'contract_address' => '0xABC', 'wallets' => '4'],
        ];

        $map = CollectionDemandService::linkedHolderCounts(true);

        self::assertSame(4, $map[CollectionDemandService::key(1, '0xabc')]);
    }

    public function testCosmosRollupsCountDistinctWallets(): void
    {
        $this->setUpCosmosChain();
        \BCC\Trust\Onchain\Repositories\WalletRepository::$chainAddresses = [self::WALLET_A, self::WALLET_B];

        // Wallet A holds contract 1 + 2; wallet B holds contract 1 only.
        $this->queueJson(['total' => 2, 'collections' => [
            ['contractAddress' => self::contract(1), 'ownedTokensCount' => 3],
            ['contractAddress' => self::contract(2), 'ownedTokensCount' => 1],
        ]]);
        $this->queueJson(['total' => 1, 'collections' => [
            ['contractAddress' => self::contract(1), 'ownedTokensCount' => 9],
        ]]);

        $map = CollectionDemandService::linkedHolderCounts(true);

        self::assertSame(2, $map[CollectionDemandService::key(8, self::contract(1))]);
        self::assertSame(1, $map[CollectionDemandService::key(8, self::contract(2))]);
    }

    public function testUnreadableWalletContributesNothing(): void
    {
        $this->setUpCosmosChain();
        \BCC\Trust\Onchain\Repositories\WalletRepository::$chainAddresses = [self::WALLET_A, self::WALLET_B];

        $this->queueJson(['total' => 1, 'collections' => [
            ['contractAddress' => self::contract(1), 'ownedTokensCount' => 1],
        ]]);
        \BCC\Trust\Onchain\Support\ApiRetry::$queue[] = new \WP_Error('down'); // wallet B unreadable

        $map = CollectionDemandService::linkedHolderCounts(true);

        // Floor semantics: count reflects only the readable wallet.
        self::assertSame(1, $map[CollectionDemandService::key(8, self::contract(1))]);
    }

    public function testMapIsCachedBetweenCalls(): void
    {
        \BCC\Trust\Onchain\Repositories\NftHoldingsRepository::$walletCounts = [
            (object) ['chain_id' => '1', 'contract_address' => '0xabc', 'wallets' => '2'],
        ];

        $first = CollectionDemandService::linkedHolderCounts(true);

        // Mutate the source — the cached map must win without $force.
        \BCC\Trust\Onchain\Repositories\NftHoldingsRepository::$walletCounts = [];
        $second = CollectionDemandService::linkedHolderCounts();

        self::assertSame($first, $second);
    }
}
