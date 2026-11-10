<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Services\CollectionPersistBatch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Complete success, partial failure, total failure — the three outcomes both
 * callers branch on (#212).
 *
 * ⚠ CORRECTED 2026-11-10 (#221). This docblock used to say the caller must
 * advance `markHoldingsRefreshed()` only on complete success. No caller does
 * that: the only withholding of that watermark is in `HoldingsService`, on an
 * INCOMPLETE PROVIDER WALK, which never sees a persist batch. What actually
 * keeps a lost row retryable is narrower — a failed `upsert()` never advances
 * that row's `expires_at`, and the refresh cron selects on
 * `expires_at < NOW()`.
 *
 * The health-surface half of this behaviour lives in CollectionPersistMetricsTest.
 */
#[CoversClass(CollectionPersistBatch::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CollectionPersistBatchTest extends TestCase
{
    private const WALLET = 77;
    private const TTL    = 14400;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/collection-persist-stubs.php';
        CollectionRepository::reset();
    }

    /** @return array<int, array<string, mixed>> */
    private function batch(int $n): array
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['chain_id' => 8, 'contract_address' => 'cosmos1row' . $i];
        }

        return $rows;
    }

    public function testCompleteSuccessIsFullyPersisted(): void
    {
        CollectionRepository::$scriptedStatuses = ['created', 'updated', 'updated'];

        $result = CollectionPersistBatch::persist($this->batch(3), self::WALLET, self::TTL);

        self::assertSame(
            ['total' => 3, 'created' => 1, 'updated' => 2, 'failed' => 0],
            $result
        );
        self::assertTrue(
            CollectionPersistBatch::allPersisted($result),
            'a clean batch is fully persisted; nothing is left for the cron to pick up'
        );
        self::assertCount(3, CollectionRepository::$calls, 'every row is attempted');
    }

    /**
     * THE REGRESSION GUARD. One lost write out of three is still a lost write:
     * the watermark must not advance.
     */
    public function testPartialFailureIsNotFullyPersisted(): void
    {
        CollectionRepository::$scriptedStatuses = ['created', 'failed', 'updated'];

        $result = CollectionPersistBatch::persist($this->batch(3), self::WALLET, self::TTL);

        self::assertSame(
            ['total' => 3, 'created' => 1, 'updated' => 1, 'failed' => 1],
            $result
        );
        self::assertFalse(
            CollectionPersistBatch::allPersisted($result),
            'one lost write must leave that row eligible for retry via its unadvanced expires_at'
        );
        self::assertCount(
            3,
            CollectionRepository::$calls,
            'a failure must not abort the rest of the batch — the other rows still deserve their refresh'
        );
    }

    public function testTotalFailureIsNotFullyPersisted(): void
    {
        CollectionRepository::$scriptedStatuses = ['failed', 'failed'];

        $result = CollectionPersistBatch::persist($this->batch(2), self::WALLET, self::TTL);

        self::assertSame(
            ['total' => 2, 'created' => 0, 'updated' => 0, 'failed' => 2],
            $result
        );
        self::assertFalse(CollectionPersistBatch::allPersisted($result));
    }

    /**
     * An empty batch is complete success, not failure — a wallet that genuinely
     * holds nothing must not be pinned in a permanent retry loop.
     */
    public function testAnEmptyBatchCountsAsFullyPersisted(): void
    {
        $result = CollectionPersistBatch::persist([], self::WALLET, self::TTL);

        self::assertSame(['total' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0], $result);
        self::assertTrue(CollectionPersistBatch::allPersisted($result));
        self::assertSame([], CollectionRepository::$calls, 'nothing to persist means nothing is attempted');
    }

    /** A malformed row is counted as failed rather than fatalling the sweep. */
    public function testAMalformedRowIsCountedNotThrown(): void
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = [['chain_id' => 8, 'contract_address' => 'cosmos1ok'], 'not-an-array'];

        $result = CollectionPersistBatch::persist($rows, self::WALLET, self::TTL);

        self::assertSame(1, $result['failed']);
        self::assertSame(2, $result['total']);
        self::assertFalse(CollectionPersistBatch::allPersisted($result));
    }

    /** The wallet and TTL reach the repository unchanged. */
    public function testWalletAndTtlArePassedThrough(): void
    {
        CollectionPersistBatch::persist($this->batch(1), self::WALLET, 999);

        self::assertSame(self::WALLET, CollectionRepository::$calls[0]['wallet']);
        self::assertSame(999, CollectionRepository::$calls[0]['ttl']);
    }
}
