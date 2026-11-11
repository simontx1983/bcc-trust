<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * NftHoldingsRepository ERC-1155 balance deltas against a real MySQL.
 *
 * Proves the fix for the eviction bug at the DB layer: a partial 1155
 * transfer-out DECREMENTS the held balance (row survives) instead of
 * deleting the row, so a holder of 5 who transfers 1 still reads as 4 and
 * is NOT evicted from a gated group. Also proves the block-guarded upsert is
 * idempotent (the indexer re-processes a range on retry) and that the row is
 * cleaned up when the balance reaches zero.
 */
#[Group('integration')]
#[CoversClass(NftHoldingsRepository::class)]
final class NftHolders1155DeltaIntegrationTest extends TestCase
{
    private const CHAIN    = 1;
    private const CONTRACT = '0xcccccccccccccccccccccccccccccccccccccccc';
    private const WALLET   = 10;

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `' . NftHoldingsRepository::table() . '`');
    }

    /** Apply one signed 1155 delta for (WALLET, CONTRACT, $tokenId) at $block. */
    private function delta(int $delta, int $block, string $tokenId = '1'): void
    {
        NftHoldingsRepository::ingestBatch([], [], [[
            'wallet_link_id'   => self::WALLET,
            'chain_id'         => self::CHAIN,
            'contract_address' => self::CONTRACT,
            'token_id'         => $tokenId,
            'token_standard'   => 'ERC-1155',
            'delta'            => $delta,
            'metadata_status'  => NftHoldingsRepository::STATUS_PENDING,
            'last_seen_block'  => $block,
            'confirmed_at'     => '2026-01-01 00:00:00',
        ]]);
    }

    /** Gate-visible SUM(balance) for the wallet under the contract, or null if no row. */
    private function heldTotal(): ?int
    {
        $map = NftHoldingsRepository::countVisibleByContract(self::CHAIN, self::CONTRACT, [self::WALLET]);
        return $map[self::WALLET] ?? null;
    }

    public function testInflowSeedsBalance(): void
    {
        $this->delta(5, 100);
        self::assertSame(5, $this->heldTotal());
    }

    public function testPartialOutflowDecrementsInsteadOfEvicting(): void
    {
        $this->delta(5, 100);   // acquire 5
        $this->delta(-1, 105);  // transfer 1 out

        self::assertSame(4, $this->heldTotal(), 'holder of 5 who sent 1 must still read 4 — not 0');
    }

    public function testFullOutflowLeavesNoHolding(): void
    {
        $this->delta(5, 100);
        $this->delta(-5, 105);

        // F-65 part B: the row is KEPT as a balance-0 tombstone (so an older
        // positive delta arriving later is refused), but no reader sees it.
        self::assertNull($this->heldTotal(), 'balance zero → not a holding to any reader');
        self::assertSame(0, $this->storedBalance(), 'kept as a tombstone');
    }

    public function testATombstoneRefusesAnOlderPositiveDelta(): void
    {
        // F-65 part B, the out-first case: the OUT (block 105) is ingested
        // BEFORE the older acquiring IN (block 100). The tombstone remembers
        // block 105, so the strict guard refuses the older +5 and the wallet
        // never shows a token it no longer holds.
        $this->delta(-5, 105);
        $this->delta(5, 100);

        self::assertNull($this->heldTotal());

        // …while a genuinely NEWER inflow brings it back.
        $this->delta(2, 110);
        self::assertSame(2, $this->heldTotal());
    }

    /** The raw stored balance for (WALLET, CONTRACT, '1'), or null if no row. */
    private function storedBalance(): ?int
    {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare(
            'SELECT balance FROM `' . NftHoldingsRepository::table() . '` WHERE wallet_link_id = %d AND contract_address = %s AND token_id = %s',
            self::WALLET,
            self::CONTRACT,
            '1'
        ));
        return $v === null ? null : (int) $v;
    }

    public function testReprocessedRangeIsIdempotent(): void
    {
        $this->delta(5, 100);
        $this->delta(-1, 105);
        self::assertSame(4, $this->heldTotal());

        // The worker re-runs a range on retry: the SAME delta at the SAME
        // block must be a no-op (block-guard), not another decrement.
        $this->delta(-1, 105);
        self::assertSame(4, $this->heldTotal(), 're-processing the same block must not double-decrement');
    }

    public function testNegativeOnlySeedIsCleanedUp(): void
    {
        // Forward-history gap: we never saw the acquiring IN, only an OUT.
        // Seeds a balance-0 tombstone (F-65 part B), which no reader sees.
        $this->delta(-3, 100);
        self::assertNull($this->heldTotal());
        self::assertSame(0, $this->storedBalance());
    }

    public function testGateSumsAcrossTokenIds(): void
    {
        // "Any token under the contract" gate: distinct 1155 token_ids
        // aggregate via SUM(balance).
        $this->delta(5, 100, '1');
        $this->delta(2, 100, '2');

        self::assertSame(7, $this->heldTotal());
    }

    /**
     * DB-layer contract that the worker's whole-block carry buffer exists
     * to satisfy (Bug A, 2026-07-06 audit). The `> last_seen_block` guard
     * means a SECOND delta for the same (key, block) is DROPPED — so if a
     * single block's 1155 events are ever ingested in two separate batches
     * (an Alchemy page boundary cutting mid-block), the second batch is
     * silently lost and the balance under-counts. This test pins that
     * failure so the invariant "never hand applyDeltas a partial block"
     * can never be quietly regressed at the repository layer.
     */
    public function testSecondDeltaAtSameBlockIsDroppedByGuard(): void
    {
        // Airdrop mints 5 units in block 100, but split across two ingest
        // calls the way a mid-block page boundary would: +2 then +3.
        $this->delta(2, 100);
        $this->delta(3, 100);

        // The second +3 is dropped by the block guard → balance sticks at 2,
        // NOT 5. A min_balance=3 gate would reject this real holder. The
        // worker MUST fold the whole block into a single delta (+5) so this
        // path is never exercised in production.
        self::assertSame(2, $this->heldTotal());
    }
}
