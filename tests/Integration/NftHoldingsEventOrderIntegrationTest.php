<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-65 (part A): ERC-721 / Solana holdings writes respect event order ACROSS
 * batches, against real MySQL / MariaDB.
 *
 * NftHoldingsIndexer::planBatch() orders events within one batch. Batches
 * themselves can arrive out of order — the Helius webhook delivers different
 * Solana txs out of order, and signature dedupe only stops exact replays — so
 * the repository must not let an OLDER event undo a NEWER one:
 *
 *   - a stale transfer-out must not delete a holding a newer transfer-in wrote;
 *   - a stale transfer-in must not overwrite a newer row's balance or status.
 *
 * The upsert guard depends on ON DUPLICATE KEY UPDATE evaluating left to right
 * (the guarded assignments precede `last_seen_block = GREATEST(...)`), which is
 * engine behaviour — hence a real-database test, on both engines.
 *
 * Not covered here, by design: a newer OUT that arrives BEFORE the older IN
 * finds no row to delete, so the older IN still recreates it. That needs a
 * remembered "out" (F-65 part B, a balance = 0 tombstone).
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(NftHoldingsRepository::class)]
final class NftHoldingsEventOrderIntegrationTest extends TestCase
{
    private const CHAIN    = 1;
    private const CONTRACT = '0xdddddddddddddddddddddddddddddddddddddddd';
    private const WALLET   = 10;
    private const TOKEN    = '42';

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `' . NftHoldingsRepository::table() . '`');
    }

    private function in(int $block, int $balance = 1, int $status = NftHoldingsRepository::STATUS_PENDING): void
    {
        NftHoldingsRepository::ingestBatch([[
            'wallet_link_id'   => self::WALLET,
            'chain_id'         => self::CHAIN,
            'contract_address' => self::CONTRACT,
            'token_id'         => self::TOKEN,
            'token_standard'   => 'ERC-721',
            'balance'          => $balance,
            'metadata_status'  => $status,
            'last_seen_block'  => $block,
            'confirmed_at'     => '2026-01-01 00:00:00',
        ]], []);
    }

    private function out(int $block): array
    {
        return NftHoldingsRepository::ingestBatch([], [[
            'wallet_link_id' => self::WALLET,
            'contract'       => self::CONTRACT,
            'token_id'       => self::TOKEN,
            'block'          => $block,
        ]]);
    }

    /** @return array{balance: int, metadata_status: int, last_seen_block: int}|null */
    private function row(): ?array
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            'SELECT balance, metadata_status, last_seen_block FROM `' . NftHoldingsRepository::table() . '`
              WHERE wallet_link_id = %d AND contract_address = %s AND token_id = %s',
            self::WALLET,
            self::CONTRACT,
            self::TOKEN
        ), ARRAY_A);

        return is_array($r)
            ? ['balance' => (int) $r['balance'], 'metadata_status' => (int) $r['metadata_status'], 'last_seen_block' => (int) $r['last_seen_block']]
            : null;
    }

    // ── transfer-out ────────────────────────────────────────────────────

    public function testAStaleOutDoesNotDeleteANewerHolding(): void
    {
        $this->in(200);

        $result = $this->out(100);

        self::assertSame(0, $result['deletes']);
        self::assertNotNull($this->row(), 'the newer holding survives a stale transfer-out');
    }

    public function testANewerOutDeletes(): void
    {
        $this->in(200);

        self::assertSame(1, $this->out(300)['deletes']);
        self::assertNull($this->row());
    }

    public function testAnOutAtTheSameBlockDeletesAsBefore(): void
    {
        $this->in(200);

        self::assertSame(1, $this->out(200)['deletes']);
        self::assertNull($this->row());
    }

    public function testAnOutWithNoBlockIsRefusedRatherThanUnguarded(): void
    {
        $this->in(200);

        $result = NftHoldingsRepository::ingestBatch([], [[
            'wallet_link_id' => self::WALLET,
            'contract'       => self::CONTRACT,
            'token_id'       => self::TOKEN,
        ]]);

        self::assertSame(0, $result['deletes']);
        self::assertNotNull($this->row());
    }

    public function testAnOutWithNoBlockCannotDeleteALegacyRowStoredAtBlockZero(): void
    {
        // Where the explicit refusal matters: `last_seen_block <= 0` WOULD
        // match a row stored without a block, so an unguarded delete with a
        // missing block would remove it.
        $this->in(0);
        self::assertNotNull($this->row(), 'fixture: a block-0 row exists');

        $result = NftHoldingsRepository::ingestBatch([], [[
            'wallet_link_id' => self::WALLET,
            'contract'       => self::CONTRACT,
            'token_id'       => self::TOKEN,
        ]]);

        self::assertSame(0, $result['deletes']);
        self::assertNotNull($this->row());
    }

    // ── transfer-in ─────────────────────────────────────────────────────

    public function testAStaleInDoesNotOverwriteANewerRow(): void
    {
        $this->in(200, 1, NftHoldingsRepository::STATUS_PENDING);

        // Older event, claiming a different balance and a spam verdict.
        $this->in(100, 5, NftHoldingsRepository::STATUS_SPAM);

        self::assertSame(
            ['balance' => 1, 'metadata_status' => NftHoldingsRepository::STATUS_PENDING, 'last_seen_block' => 200],
            $this->row()
        );
    }

    public function testANewerInUpdatesTheRow(): void
    {
        $this->in(200, 1, NftHoldingsRepository::STATUS_PENDING);

        $this->in(300, 3, NftHoldingsRepository::STATUS_SPAM);

        self::assertSame(
            ['balance' => 3, 'metadata_status' => NftHoldingsRepository::STATUS_SPAM, 'last_seen_block' => 300],
            $this->row()
        );
    }

    public function testAnInAtTheSameBlockStillUpdates(): void
    {
        // Equal block is not older: last arrival within a block wins, as before.
        $this->in(200, 1);
        $this->in(200, 2);

        self::assertSame(2, $this->row()['balance'] ?? null);
    }

    public function testAnEnrichedStatusIsNeverReopenedByANewerIn(): void
    {
        // Unchanged behaviour: only a PENDING row takes the incoming status.
        $this->in(200, 1, NftHoldingsRepository::STATUS_OK);

        $this->in(300, 1, NftHoldingsRepository::STATUS_PENDING);

        self::assertSame(NftHoldingsRepository::STATUS_OK, $this->row()['metadata_status'] ?? null);
    }

    public function testStaleThenFreshInterleavingEndsOnTheNewestState(): void
    {
        $this->in(200);          // holds
        $this->out(150);         // stale out: ignored
        $this->in(120, 9);       // stale in: ignored
        self::assertSame(['balance' => 1, 'metadata_status' => NftHoldingsRepository::STATUS_PENDING, 'last_seen_block' => 200], $this->row());

        $this->out(400);         // fresh out: gone
        self::assertNull($this->row());
    }
}
