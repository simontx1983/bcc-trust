<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
use BCC\Trust\Onchain\Services\NftHoldingsIndexer;
use PHPUnit\Framework\TestCase;

/**
 * Pins NftHoldingsIndexer::planBatch — the pure planner that folds transfer
 * events into (721 upserts, 721 deletes, 1155 balance deltas).
 *
 * The load-bearing fix: an ERC-1155 partial transfer-out must produce a
 * signed DECREMENT (so the repository nets it against the held balance),
 * NOT a full delete — otherwise a holder of 5 who transfers 1 shows 0 and
 * gets evicted from a gated group. ERC-721 must be entirely unchanged
 * (last-operation-wins: OUT → delete, IN → absolute balance-1 upsert).
 */
final class NftHoldingsIndexerPlanTest extends TestCase
{
    private const CHAIN = 1;
    private const A     = '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const B     = '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const ZERO  = '0x0000000000000000000000000000000000000000';
    private const C1155 = '0xcccccccccccccccccccccccccccccccccccccccc';
    private const C721  = '0xdddddddddddddddddddddddddddddddddddddddd';
    private const SPAM  = '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private const LINK_A = 10;
    private const LINK_B = 20;

    /** @param array<string, mixed> $overrides @return array<string, mixed> */
    private function ev(array $overrides): array
    {
        return array_merge([
            'chain_id'         => self::CHAIN,
            'contract_address' => self::C1155,
            'token_id'         => '1',
            'token_standard'   => 'ERC-1155',
            'from_address'     => self::ZERO,
            'to_address'       => self::ZERO,
            'amount'           => 1,
            'block_number'     => 100,
            'confirmed_at'     => '2026-01-01 00:00:00',
            'collection_name'  => null,
        ], $overrides);
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{upserts: list<mixed>, deletes: list<mixed>, deltas: list<mixed>, skipped: int, spam_filtered: int}
     */
    private function plan(array $events, ?callable $isSpam = null): array
    {
        $map = [self::A => self::LINK_A, self::B => self::LINK_B];
        return NftHoldingsIndexer::planBatch(
            self::CHAIN,
            $events,
            $map,
            $isSpam ?? static fn (string $c, ?string $n): bool => false
        );
    }

    public function test1155InflowEmitsPositiveDelta(): void
    {
        $out = $this->plan([$this->ev(['to_address' => self::A, 'amount' => 5])]);

        self::assertSame([], $out['upserts']);
        self::assertSame([], $out['deletes']);
        self::assertCount(1, $out['deltas']);
        self::assertSame(self::LINK_A, $out['deltas'][0]['wallet_link_id']);
        self::assertSame(5, $out['deltas'][0]['delta']);
    }

    public function test1155PartialOutflowDecrementsInsteadOfDeleting(): void
    {
        // The bug scenario: a wallet transfers 1 unit out. This must be a
        // signed -1 delta (repository nets it against the held 5 → 4), NOT a
        // delete row. A delete here is exactly what evicted a still-holder.
        $out = $this->plan([$this->ev(['from_address' => self::A, 'amount' => 1])]);

        self::assertSame([], $out['deletes'], 'a 1155 partial transfer-out must NOT emit a delete');
        self::assertCount(1, $out['deltas']);
        self::assertSame(-1, $out['deltas'][0]['delta']);
        self::assertSame(self::LINK_A, $out['deltas'][0]['wallet_link_id']);
    }

    public function test1155InflowsAccumulateWithinBatch(): void
    {
        // Two separate inbound transfers to the same wallet+token must SUM
        // (3 + 2 = 5), not last-wins (which stored 2 before the fix).
        $out = $this->plan([
            $this->ev(['to_address' => self::A, 'amount' => 3, 'block_number' => 100]),
            $this->ev(['to_address' => self::A, 'amount' => 2, 'block_number' => 105]),
        ]);

        self::assertCount(1, $out['deltas']);
        self::assertSame(5, $out['deltas'][0]['delta']);
        self::assertSame(105, $out['deltas'][0]['last_seen_block'], 'last_seen_block is the max block touched');
    }

    public function test1155InThenPartialOutNetsWithinBatch(): void
    {
        $out = $this->plan([
            $this->ev(['to_address' => self::A, 'amount' => 5, 'block_number' => 100]),
            $this->ev(['from_address' => self::A, 'amount' => 1, 'block_number' => 105]),
        ]);

        self::assertCount(1, $out['deltas']);
        self::assertSame(4, $out['deltas'][0]['delta']);
    }

    public function test1155SelfTransferIsNetZeroAndDropped(): void
    {
        // from == to (same tracked wallet): -amount then +amount → 0 → no-op.
        $out = $this->plan([$this->ev(['from_address' => self::A, 'to_address' => self::A, 'amount' => 3])]);

        self::assertSame([], $out['deltas'], 'a net-zero delta must be dropped');
        self::assertSame([], $out['deletes']);
        self::assertSame([], $out['upserts']);
    }

    public function test721OutflowStillDeletes(): void
    {
        $out = $this->plan([$this->ev([
            'contract_address' => self::C721,
            'token_standard'   => 'ERC-721',
            'from_address'     => self::A,
            'amount'           => 1,
        ])]);

        self::assertSame([], $out['deltas'], '721 must not use the delta path');
        self::assertCount(1, $out['deletes']);
        self::assertSame(self::LINK_A, $out['deletes'][0]['wallet_link_id']);
    }

    public function test721InflowUpsertsAbsoluteBalanceOne(): void
    {
        $out = $this->plan([$this->ev([
            'contract_address' => self::C721,
            'token_standard'   => 'ERC-721',
            'to_address'       => self::A,
            'amount'           => 1,
        ])]);

        self::assertSame([], $out['deltas']);
        self::assertCount(1, $out['upserts']);
        self::assertSame(1, $out['upserts'][0]['balance']);
    }

    public function testSpam1155InflowFlagsStatusAndCounts(): void
    {
        $isSpam = static fn (string $c, ?string $n): bool => $c === self::SPAM;

        $out = $this->plan([$this->ev([
            'contract_address' => self::SPAM,
            'to_address'       => self::A,
            'amount'           => 2,
        ])], $isSpam);

        self::assertSame(1, $out['spam_filtered']);
        self::assertCount(1, $out['deltas']);
        self::assertSame(NftHoldingsRepository::STATUS_SPAM, $out['deltas'][0]['metadata_status']);
    }

    public function testUntrackedAndMalformedEventsAreSkipped(): void
    {
        $out = $this->plan([
            $this->ev(['from_address' => self::ZERO, 'to_address' => self::ZERO]), // neither tracked
            $this->ev(['to_address' => self::A, 'chain_id' => 999]),               // wrong chain
            $this->ev(['to_address' => self::A, 'token_id' => '']),                 // malformed
        ]);

        self::assertSame(3, $out['skipped']);
        self::assertSame([], $out['deltas']);
    }
}
