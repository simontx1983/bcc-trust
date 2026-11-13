<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-65: ERC-721 / Solana holdings writes respect event order ACROSS batches,
 * against real MySQL / MariaDB.
 *
 * NftHoldingsIndexer::planBatch() orders events within one batch. Batches
 * themselves can arrive out of order — the Helius webhook delivers different
 * Solana txs out of order, and signature dedupe only stops exact replays — so
 * the repository must not let an OLDER event undo a NEWER one:
 *
 *   - (part A) a stale transfer-out must not remove a holding a newer
 *     transfer-in wrote, and a stale transfer-in must not overwrite a newer
 *     row's balance or status;
 *   - (part B) a newer transfer-out that lands BEFORE the older transfer-in
 *     must still win. A delete could not do that — there was no row yet — so
 *     the OUT now leaves a balance-0 TOMBSTONE carrying its block, which the
 *     upsert's block guard uses to refuse the older IN. Every reader filters
 *     `balance > 0`, and an old tombstone is pruned after 30 days.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(NftHoldingsRepository::class)]
final class NftHoldingsEventOrderIntegrationTest extends TestCase
{
    private const CHAIN    = 1;
    private const CONTRACT = '0xdddddddddddddddddddddddddddddddddddddddd';
    private const OTHER    = '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
    private const WALLET   = 10;
    private const WALLET2  = 11;
    private const TOKEN    = '42';

    protected function setUp(): void
    {
        $GLOBALS['wpdb']->query('TRUNCATE TABLE `' . NftHoldingsRepository::table() . '`');
    }

    private function in(int $block, int $balance = 1, int $status = NftHoldingsRepository::STATUS_PENDING, int $wallet = self::WALLET, string $contract = self::CONTRACT, string $token = self::TOKEN): void
    {
        NftHoldingsRepository::ingestBatch([[
            'wallet_link_id'   => $wallet,
            'chain_id'         => self::CHAIN,
            'contract_address' => $contract,
            'token_id'         => $token,
            'token_standard'   => 'ERC-721',
            'balance'          => $balance,
            'metadata_status'  => $status,
            'last_seen_block'  => $block,
            'confirmed_at'     => '2026-01-01 00:00:00',
        ]], []);
    }

    /** @return array{inserts: int, deletes: int} */
    private function out(int $block, int $wallet = self::WALLET, string $contract = self::CONTRACT, string $token = self::TOKEN): array
    {
        return NftHoldingsRepository::ingestBatch([], [[
            'wallet_link_id' => $wallet,
            'contract'       => $contract,
            'token_id'       => $token,
            'block'          => $block,
            'chain_id'       => self::CHAIN,
            'confirmed_at'   => '2026-01-02 00:00:00',
            'token_standard' => 'ERC-721',
        ]]);
    }

    /** @return array{balance: int, metadata_status: int, last_seen_block: int}|null */
    private function row(int $wallet = self::WALLET, string $contract = self::CONTRACT, string $token = self::TOKEN): ?array
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare(
            'SELECT balance, metadata_status, last_seen_block FROM `' . NftHoldingsRepository::table() . '`
              WHERE wallet_link_id = %d AND contract_address = %s AND token_id = %s',
            $wallet,
            $contract,
            $token
        ), ARRAY_A);

        return is_array($r)
            ? ['balance' => (int) $r['balance'], 'metadata_status' => (int) $r['metadata_status'], 'last_seen_block' => (int) $r['last_seen_block']]
            : null;
    }

    /** Is the token a holding as the gallery / gate read it? */
    private function visible(int $wallet = self::WALLET): bool
    {
        return NftHoldingsRepository::countVisibleByContract(self::CHAIN, self::CONTRACT, [$wallet]) !== [];
    }

    // ── transfer-out ────────────────────────────────────────────────────

    public function testAStaleOutDoesNotRemoveANewerHolding(): void
    {
        $this->in(200);

        $result = $this->out(100);

        self::assertSame(0, $result['deletes']);
        self::assertSame(1, $this->row()['balance'] ?? null, 'the newer holding survives a stale transfer-out');
        self::assertTrue($this->visible());
    }

    public function testANewerOutLeavesAnInvisibleTombstone(): void
    {
        $this->in(200);

        self::assertSame(1, $this->out(300)['deletes']);
        self::assertSame(['balance' => 0, 'metadata_status' => NftHoldingsRepository::STATUS_PENDING, 'last_seen_block' => 300], $this->row());
        self::assertFalse($this->visible());
    }

    public function testAnOutAtTheSameBlockZeroesAsBefore(): void
    {
        $this->in(200);

        self::assertSame(1, $this->out(200)['deletes']);
        self::assertSame(0, $this->row()['balance'] ?? null);
        self::assertFalse($this->visible());
    }

    public function testAnOutWithNoBlockIsRefused(): void
    {
        $this->in(200);

        $result = NftHoldingsRepository::ingestBatch([], [[
            'wallet_link_id' => self::WALLET,
            'contract'       => self::CONTRACT,
            'token_id'       => self::TOKEN,
            'chain_id'       => self::CHAIN,
            'confirmed_at'   => '2026-01-02 00:00:00',
        ]]);

        self::assertSame(0, $result['deletes']);
        self::assertSame(1, $this->row()['balance'] ?? null);
    }

    public function testAnOutWithNoBlockCannotTouchALegacyRowStoredAtBlockZero(): void
    {
        $this->in(0);
        self::assertSame(1, $this->row()['balance'] ?? null, 'fixture: a block-0 holding exists');

        NftHoldingsRepository::ingestBatch([], [[
            'wallet_link_id' => self::WALLET,
            'contract'       => self::CONTRACT,
            'token_id'       => self::TOKEN,
            'chain_id'       => self::CHAIN,
            'confirmed_at'   => '2026-01-02 00:00:00',
        ]]);

        self::assertSame(1, $this->row()['balance'] ?? null);
    }

    public function testAnOutWithoutTheTombstoneColumnsIsRefusedNotDeleted(): void
    {
        // A tombstone INSERT needs chain_id and confirmed_at; an entry
        // without them is refused, never turned into an unremembered delete.
        $this->in(200);

        $result = NftHoldingsRepository::ingestBatch([], [[
            'wallet_link_id' => self::WALLET,
            'contract'       => self::CONTRACT,
            'token_id'       => self::TOKEN,
            'block'          => 300,
        ]]);

        self::assertSame(0, $result['deletes']);
        self::assertSame(1, $this->row()['balance'] ?? null);
    }

    // ── transfer-in ─────────────────────────────────────────────────────

    public function testAStaleInDoesNotOverwriteANewerRow(): void
    {
        $this->in(200, 1, NftHoldingsRepository::STATUS_PENDING);
        $this->in(100, 5, NftHoldingsRepository::STATUS_SPAM);

        self::assertSame(['balance' => 1, 'metadata_status' => NftHoldingsRepository::STATUS_PENDING, 'last_seen_block' => 200], $this->row());
    }

    public function testANewerInUpdatesTheRow(): void
    {
        $this->in(200, 1, NftHoldingsRepository::STATUS_PENDING);
        $this->in(300, 3, NftHoldingsRepository::STATUS_SPAM);

        self::assertSame(['balance' => 3, 'metadata_status' => NftHoldingsRepository::STATUS_SPAM, 'last_seen_block' => 300], $this->row());
    }

    public function testAnInAtTheSameBlockStillUpdates(): void
    {
        $this->in(200, 1);
        $this->in(200, 2);

        self::assertSame(2, $this->row()['balance'] ?? null);
    }

    public function testAnEnrichedStatusIsNeverReopenedByANewerIn(): void
    {
        $this->in(200, 1, NftHoldingsRepository::STATUS_OK);
        $this->in(300, 1, NftHoldingsRepository::STATUS_PENDING);

        self::assertSame(NftHoldingsRepository::STATUS_OK, $this->row()['metadata_status'] ?? null);
    }

    // ── part B: the out-first case ──────────────────────────────────────

    public function testANewerOutThatArrivesFirstStopsTheOlderInFromResurrectingTheToken(): void
    {
        // THE case part A could not cover. The OUT at 300 is delivered before
        // the IN at 200 it supersedes. With a delete, the OUT found no row and
        // the IN then created one: a token the wallet no longer holds.
        $result = $this->out(300);
        self::assertSame(0, $result['deletes'], 'no holding was visible, so nothing was "deleted"');
        self::assertSame(0, $this->row()['balance'] ?? null, 'a tombstone remembers the OUT');

        $this->in(200);

        self::assertSame(['balance' => 0, 'metadata_status' => NftHoldingsRepository::STATUS_PENDING, 'last_seen_block' => 300], $this->row());
        self::assertFalse($this->visible(), 'the older IN did not bring the token back');
    }

    public function testANewerInAfterATombstoneBringsTheTokenBack(): void
    {
        $this->in(200);
        $this->out(300);
        self::assertFalse($this->visible());

        $this->in(400);

        self::assertSame(1, $this->row()['balance'] ?? null);
        self::assertTrue($this->visible());
    }

    public function testStaleAndFreshInterleavingEndsOnTheNewestState(): void
    {
        $this->in(200);          // holds
        $this->out(150);         // stale out: ignored
        $this->in(120, 9);       // stale in: ignored
        self::assertSame(['balance' => 1, 'metadata_status' => NftHoldingsRepository::STATUS_PENDING, 'last_seen_block' => 200], $this->row());

        $this->out(400);         // fresh out: tombstone
        $this->in(350);          // stale in after it: refused
        self::assertSame(0, $this->row()['balance'] ?? null);
        self::assertFalse($this->visible());
    }

    // ── part B: no reader sees a tombstone ──────────────────────────────

    public function testEveryReaderIgnoresTombstones(): void
    {
        global $wpdb;
        $table = NftHoldingsRepository::table();

        // WALLET holds token 1 (live) and a tombstone for token 2 under
        // CONTRACT. WALLET2 has only a tombstone, under OTHER.
        $this->in(100, 1, NftHoldingsRepository::STATUS_PENDING, self::WALLET, self::CONTRACT, '1');
        $this->in(100, 1, NftHoldingsRepository::STATUS_PENDING, self::WALLET, self::CONTRACT, '2');
        $this->out(200, self::WALLET, self::CONTRACT, '2');
        $this->in(100, 1, NftHoldingsRepository::STATUS_PENDING, self::WALLET2, self::OTHER, '9');
        $this->out(200, self::WALLET2, self::OTHER, '9');
        self::assertSame(0, $this->row(self::WALLET, self::CONTRACT, '2')['balance'] ?? null, 'fixture: tombstone');

        $tokens = static fn (array $rows): array => array_values(array_map(static fn (object $r): string => (string) $r->token_id, $rows));

        // Visible / gallery reads.
        self::assertSame(['1'], $tokens(NftHoldingsRepository::findVisibleForWallet(self::WALLET, self::CHAIN)), 'findVisibleForWallet');
        self::assertFalse(NftHoldingsRepository::walletHasAnyForChain(self::WALLET2, self::CHAIN), 'walletHasAnyForChain');

        // Gate read: the tombstone-only wallet has no key at all.
        self::assertSame([], NftHoldingsRepository::countVisibleByContract(self::CHAIN, self::OTHER, [self::WALLET2]), 'countVisibleByContract (tombstone-only)');
        self::assertSame([self::WALLET => 1], NftHoldingsRepository::countVisibleByContract(self::CHAIN, self::CONTRACT, [self::WALLET]), 'countVisibleByContract (live + tombstone)');

        // Stats.
        $perContract = [];
        foreach ((array) NftHoldingsRepository::countDistinctWalletsPerContract() as $r) {
            $perContract[strtolower((string) $r->contract_address)] = (int) $r->wallets;
        }
        self::assertSame([self::CONTRACT => 1], $perContract, 'countDistinctWalletsPerContract');

        // Admin / audit reads.
        self::assertSame(['1'], $tokens(NftHoldingsRepository::findAllIncludingSpam(self::WALLET)), 'findAllIncludingSpam');
        self::assertSame(['1'], $tokens(NftHoldingsRepository::findByStatus(self::CHAIN, NftHoldingsRepository::STATUS_PENDING)), 'findByStatus');
        self::assertSame(['1'], $tokens(NftHoldingsRepository::findByStatusAcrossChains([self::CHAIN], NftHoldingsRepository::STATUS_PENDING)), 'findByStatusAcrossChains');

        // Enrichment queue: never enrich a token nobody holds.
        self::assertSame(['1'], $tokens(NftHoldingsRepository::findPendingEnrichment(self::CHAIN)), 'findPendingEnrichment');

        // Enriched reads: mark every row enriched + OK, then read.
        self::assertNotFalse($wpdb->query("UPDATE `{$table}` SET enriched_at = '2026-01-03 00:00:00', metadata_status = " . NftHoldingsRepository::STATUS_OK));
        self::assertSame(['1'], $tokens(NftHoldingsRepository::findVisibleEnrichedForWallet(self::WALLET, self::CHAIN)), 'findVisibleEnrichedForWallet');
        self::assertFalse(NftHoldingsRepository::walletHasAnyEnriched(self::WALLET2, self::CHAIN), 'walletHasAnyEnriched');
        self::assertTrue(NftHoldingsRepository::walletHasAnyEnriched(self::WALLET, self::CHAIN), 'control: a live enriched row is still seen');
    }

    // ── part B: pruning old tombstones ──────────────────────────────────

    private function age(int $wallet, string $contract, string $token, int $days): void
    {
        global $wpdb;
        $ok = $wpdb->query($wpdb->prepare(
            'UPDATE `' . NftHoldingsRepository::table() . '` SET indexed_at = %s
              WHERE wallet_link_id = %d AND contract_address = %s AND token_id = %s',
            gmdate('Y-m-d H:i:s', time() - $days * 86400),
            $wallet,
            $contract,
            $token
        ));
        self::assertNotFalse($ok);
    }

    public function testPruneRemovesOnlyOldTombstones(): void
    {
        $this->in(100, 1, NftHoldingsRepository::STATUS_PENDING, self::WALLET, self::CONTRACT, 'old-tomb');
        $this->out(200, self::WALLET, self::CONTRACT, 'old-tomb');
        $this->in(100, 1, NftHoldingsRepository::STATUS_PENDING, self::WALLET, self::CONTRACT, 'new-tomb');
        $this->out(200, self::WALLET, self::CONTRACT, 'new-tomb');
        $this->in(100, 1, NftHoldingsRepository::STATUS_PENDING, self::WALLET, self::CONTRACT, 'old-live');

        $this->age(self::WALLET, self::CONTRACT, 'old-tomb', 31);
        $this->age(self::WALLET, self::CONTRACT, 'new-tomb', 29);
        $this->age(self::WALLET, self::CONTRACT, 'old-live', 400);

        self::assertSame(1, NftHoldingsRepository::pruneTombstones());

        self::assertNull($this->row(self::WALLET, self::CONTRACT, 'old-tomb'), 'an old tombstone is pruned');
        self::assertSame(0, $this->row(self::WALLET, self::CONTRACT, 'new-tomb')['balance'] ?? null, 'a recent tombstone is kept');
        self::assertSame(1, $this->row(self::WALLET, self::CONTRACT, 'old-live')['balance'] ?? null, 'a holding is never pruned, however old');
    }

    public function testAFailedPruneThrowsRatherThanReportingZero(): void
    {
        // The daily hook logs the count it gets back; a failed DELETE that
        // returned 0 would look like a healthy run with nothing to prune.
        $GLOBALS['wpdb']->failQueriesMatching = '/DELETE FROM `?' . preg_quote(NftHoldingsRepository::table(), '/') . '/';
        try {
            $this->expectException(\RuntimeException::class);
            NftHoldingsRepository::pruneTombstones();
        } finally {
            $GLOBALS['wpdb']->clearFaultInjection();
        }
    }

    public function testAFailedCommitThrowsAndLeavesTheHoldingInPlace(): void
    {
        // F-278: the indexer announces lost holdings only after ingestBatch
        // returns. A COMMIT that failed must not return as if it persisted.
        $this->in(100);

        $GLOBALS['wpdb']->failQueriesMatching = '/^COMMIT$/';
        try {
            $this->out(200);
            self::fail('a failed COMMIT must throw');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('COMMIT failed', $e->getMessage());
        } finally {
            $GLOBALS['wpdb']->clearFaultInjection();
        }

        self::assertSame(1, $this->row()['balance'] ?? null, 'the transfer-out was rolled back');
    }

    public function testTheLatestLossIsTheNewestTransferOutOfThatWalletAndContract(): void
    {
        // F-278: the revoke sweep distrusts a cached "owns" observed before this.
        $this->in(100);
        self::assertNull(NftHoldingsRepository::latestLossAtOrThrow(self::WALLET, self::CONTRACT), 'a holding is not a loss');

        $this->out(200);
        $lostAt = NftHoldingsRepository::latestLossAtOrThrow(self::WALLET, self::CONTRACT);
        self::assertIsInt($lostAt);
        self::assertEqualsWithDelta(time(), $lostAt, 120, 'indexed_at is UTC and read back as UTC');

        self::assertNull(NftHoldingsRepository::latestLossAtOrThrow(self::WALLET, self::OTHER), 'another collection');
        self::assertNull(NftHoldingsRepository::latestLossAtOrThrow(self::WALLET2, self::CONTRACT), 'another wallet');
        self::assertSame($lostAt, NftHoldingsRepository::latestLossAtOrThrow(self::WALLET, strtoupper(self::CONTRACT)), 'a folded needle matches');

        $this->in(300);
        self::assertNull(NftHoldingsRepository::latestLossAtOrThrow(self::WALLET, self::CONTRACT), 'bought back: no balance-0 row remains');
    }

    public function testAFailedLossReadThrowsRatherThanAnsweringNeverLost(): void
    {
        $GLOBALS['wpdb']->failQueriesMatching = '/MAX\(indexed_at\)/';
        try {
            $this->expectException(\BCC\Trust\Onchain\Repositories\RepositoryReadFailure::class);
            NftHoldingsRepository::latestLossAtOrThrow(self::WALLET, self::CONTRACT);
        } finally {
            $GLOBALS['wpdb']->clearFaultInjection();
        }
    }

    public function testPruneIsBounded(): void
    {
        foreach (['a', 'b', 'c'] as $t) {
            $this->in(100, 1, NftHoldingsRepository::STATUS_PENDING, self::WALLET, self::CONTRACT, $t);
            $this->out(200, self::WALLET, self::CONTRACT, $t);
            $this->age(self::WALLET, self::CONTRACT, $t, 60);
        }

        self::assertSame(2, NftHoldingsRepository::pruneTombstones(2));
        self::assertSame(1, NftHoldingsRepository::pruneTombstones(2));
        self::assertSame(0, NftHoldingsRepository::pruneTombstones(2));
    }
}
