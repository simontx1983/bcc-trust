<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\CollectionDemandService as D;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Pins the demand-map contract after the render-time Stargaze fan-out was
 * removed: counts come from BCC's own holdings index ONLY, and every state
 * the page renders is truthful.
 *
 *   - EVM/SOL counts pass through from the index, keyed case-insensitively.
 *   - Cosmos (any non-indexed chain type) is NOT CALCULATED — never zero,
 *     even if the index somehow held a row for it.
 *   - A FAILED index read is UNAVAILABLE for every indexed row.
 *   - A TRUNCATED read is unavailable for every indexed row WITHOUT a count,
 *     while positive counts still show.
 *   - Only a complete, successful read may say NONE INDEXED.
 *   - The service contacts nothing and caches nothing.
 *
 * The external surface is asserted, not assumed: ApiRetry's call log and
 * the object cache are checked empty after every call.
 */
#[CoversClass(D::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CollectionDemandServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-indexer-stubs.php';
        \BCC\Trust\Onchain\Support\ApiRetry::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestObjectCache::reset();
        \BCC\Trust\Onchain\Repositories\NftHoldingsRepository::reset();
    }

    protected function tearDown(): void
    {
        // Every test, whatever it asserts, must leave no outbound trace and
        // nothing cached — INCLUDING the failure paths, where caching an
        // incomplete answer is exactly how "unavailable" would harden into a
        // stale zero.
        self::assertSame([], \BCC\Trust\Onchain\Support\ApiRetry::$calls, 'the demand service must make no request');
        self::assertSame([], \BCC\Trust\Onchain\Support\ApiRetry::$batchCalls, 'nor any batch request');
        self::assertSame([], \BccTestObjectCache::$writes, 'and must cache nothing, whatever the outcome');
        parent::tearDown();
    }

    /** @param list<array{0: int, 1: string, 2: int}> $rows [chain_id, contract, wallets] */
    private function index(array $rows): void
    {
        \BCC\Trust\Onchain\Repositories\NftHoldingsRepository::$walletCounts = array_map(
            static fn(array $r): object => (object) ['chain_id' => (string) $r[0], 'contract_address' => $r[1], 'wallets' => (string) $r[2]],
            $rows
        );
    }

    public function testIndexCountsPassThroughKeyedCaseInsensitively(): void
    {
        $this->index([[1, '0xABC', 4]]);

        $demand = D::linkedHolderCounts();

        self::assertTrue($demand['available']);
        self::assertTrue($demand['complete']);
        self::assertSame(4, $demand['counts'][D::key(1, '0xabc')]);
        self::assertSame(['state' => D::STATE_COUNTED, 'count' => 4], D::rowState($demand, 'evm', 1, '0xAbC'));
    }

    public function testACompleteReadWithNoEntryIsNoneIndexedNotZero(): void
    {
        $this->index([[1, '0xabc', 2]]);

        $cell = D::rowState(D::linkedHolderCounts(), 'evm', 1, '0xdef');

        self::assertSame(D::STATE_NONE_INDEXED, $cell['state']);
        self::assertNull($cell['count'], 'no numeral is produced for "none indexed"');
    }

    /**
     * ⚠ THE FAILURE THIS FILE EXISTS FOR. A failed index read used to become
     * `[]` inside the repository and render as "nobody holds anything".
     */
    public function testAFailedIndexReadIsUnavailableNeverZero(): void
    {
        \BCC\Trust\Onchain\Repositories\NftHoldingsRepository::$readFails = true;

        $demand = D::linkedHolderCounts();

        self::assertFalse($demand['available']);
        self::assertFalse($demand['complete']);
        self::assertSame([], $demand['counts']);
        foreach (['evm', 'solana'] as $type) {
            $cell = D::rowState($demand, $type, 1, '0xabc');
            self::assertSame(D::STATE_UNAVAILABLE, $cell['state'], $type);
            self::assertNull($cell['count'], $type);
        }
    }

    /**
     * One row more than the limit comes back, so the service KNOWS the tail
     * was cut. Counted rows still show; rows past the cut are unknown.
     */
    public function testATruncatedReadMakesMissingRowsUnavailableButKeepsCounts(): void
    {
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = [1, sprintf('0x%040x', $i + 1), 501 - $i];
        }
        $this->index($rows);

        $demand = D::linkedHolderCounts();

        self::assertTrue($demand['available']);
        self::assertFalse($demand['complete'], '501 rows for a 500 limit is a truncated result');
        self::assertCount(500, $demand['counts'], 'the extra probe row is not kept');
        self::assertSame(501, \BCC\Trust\Onchain\Repositories\NftHoldingsRepository::$lastLimit);

        self::assertSame(D::STATE_COUNTED, D::rowState($demand, 'evm', 1, sprintf('0x%040x', 1))['state']);
        self::assertSame(D::STATE_UNAVAILABLE, D::rowState($demand, 'evm', 1, '0xnotinthetop500')['state']);
    }

    public function testExactlyTheLimitIsStillComplete(): void
    {
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = [1, sprintf('0x%040x', $i + 1), 1];
        }
        $this->index($rows);

        self::assertTrue(D::linkedHolderCounts()['complete']);
    }

    /**
     * ⚠ COSMOS IS NOT CALCULATED, WHATEVER THE INDEX SAYS. The index is not
     * written for Cosmos, and a stray row must not quietly turn an unmeasured
     * chain into a measured one.
     */
    public function testANonIndexedChainTypeIsNotCalculatedEvenWithAnIndexRow(): void
    {
        $this->index([[8, 'cosmos1contract', 7]]);

        $cell = D::rowState(D::linkedHolderCounts(), 'cosmos', 8, 'cosmos1contract');

        self::assertSame(['state' => D::STATE_NOT_CALCULATED, 'count' => null], $cell);
    }

    public function testNonIndexedChainTypesAreNotCalculatedEvenWhenTheReadFailed(): void
    {
        \BCC\Trust\Onchain\Repositories\NftHoldingsRepository::$readFails = true;

        foreach (['cosmos', 'thorchain', 'polkadot', 'near', 'utxo', ''] as $type) {
            self::assertSame(D::STATE_NOT_CALCULATED, D::rowState(D::linkedHolderCounts(), $type, 8, 'x')['state'], $type);
        }
    }

    public function testTheIndexedChainTypesArePinned(): void
    {
        // Changing this list changes which chains may show a count at all;
        // it must be a decision with a writer behind it, not a drive-by.
        self::assertSame(['evm', 'solana'], D::INDEXED_CHAIN_TYPES);
    }

    public function testRowsReportingZeroWalletsAreNotCounted(): void
    {
        $this->index([[1, '0xabc', 0]]);

        self::assertSame([], D::linkedHolderCounts()['counts']);
    }

    /**
     * No side effects: nothing is written to the object cache — there is no
     * outbound call left for a cache to amortise, and a cached map is a thing
     * that could go stale behind the page's back.
     */
    public function testTheServiceWritesNoCache(): void
    {
        $this->index([[1, '0xabc', 3]]);

        D::linkedHolderCounts();

        self::assertFalse(wp_cache_get('collection_demand_map_v1', 'bcc_onchain'), 'the old map cache is gone');
        self::assertSame([], \BccTestObjectCache::$writes, 'no object-cache write of any kind');
    }

    /** And every call reads fresh — a changed index is seen immediately. */
    public function testEveryCallReadsFresh(): void
    {
        $this->index([[1, '0xabc', 2]]);
        self::assertSame(2, D::linkedHolderCounts()['counts'][D::key(1, '0xabc')]);

        $this->index([[1, '0xabc', 5]]);
        self::assertSame(5, D::linkedHolderCounts()['counts'][D::key(1, '0xabc')]);
    }
}
