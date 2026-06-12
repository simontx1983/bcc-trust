<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Write-side orchestrator for the V2 Phase 1 NFT indexer.
 *
 * Pure function over normalized transfer events:
 *   IN  → list of TransferEvent rows (already past confirmation gate)
 *   OUT → row counts (inserts, deletes, spam-filtered, skipped)
 *
 * Per event:
 *   1. If `to_address` matches a tracked wallet on this chain → schedule
 *      UPSERT for the IN leg.
 *   2. If `from_address` matches a tracked wallet on this chain →
 *      schedule DELETE for the OUT leg (transfer-out).
 *   3. If neither matches, skip.
 *
 * Spam decision happens BEFORE persistence — when NftSpamFilter says
 * a contract is spam, the IN-leg row is still inserted but flagged
 * `metadata_status = STATUS_SPAM` so admin recovery tooling can review
 * (false-positive correction). User-facing reads never see status 2/3.
 *
 * Does NOT advance the chain checkpoint — that's the worker's job
 * after a successful tick. The indexer is pure write-side.
 *
 * @phpstan-type TransferEvent array{
 *     chain_id: int,
 *     contract_address: string,
 *     token_id: string,
 *     token_standard?: ?string,
 *     from_address: string,
 *     to_address: string,
 *     amount?: int,
 *     block_number: int,
 *     confirmed_at: string,
 *     collection_name?: ?string
 * }
 *
 * @phpstan-type IngestResult array{
 *     inserts: int,
 *     deletes: int,
 *     spam_filtered: int,
 *     skipped: int
 * }
 *
 * @phpstan-type UpsertRow array{
 *     wallet_link_id: int,
 *     chain_id: int,
 *     contract_address: string,
 *     token_id: string,
 *     token_standard: ?string,
 *     balance: int,
 *     metadata_status: int,
 *     last_seen_block: int,
 *     confirmed_at: string
 * }
 * @phpstan-type DeleteRow array{
 *     wallet_link_id: int,
 *     contract: string,
 *     token_id: string
 * }
 * @phpstan-type NetEntry array{
 *     op: 'upsert',
 *     order: array{int, int},
 *     row: UpsertRow
 * }|array{
 *     op: 'delete',
 *     order: array{int, int},
 *     del: DeleteRow
 * }
 */
final class NftHoldingsIndexer
{
    /** Burn address used by ETH (0x0…0). Solana mint-burns use a different convention; SolanaFetcher normalises. */
    private const ZERO_ADDRESS = '0x0000000000000000000000000000000000000000';

    /**
     * Ingest a batch of confirmed transfer events.
     *
     * @param int                 $chainId The chain these events belong to. Used as a
     *                                     defensive filter — events with a different
     *                                     chain_id are dropped (never assume the worker
     *                                     handed us a clean batch).
     * @param list<array<string, mixed>> $events
     * @return array{inserts: int, deletes: int, spam_filtered: int, skipped: int}
     */
    public static function ingest(int $chainId, array $events): array
    {
        $result = ['inserts' => 0, 'deletes' => 0, 'spam_filtered' => 0, 'skipped' => 0];

        if ($chainId <= 0 || $events === []) {
            return $result;
        }

        // Step 1: collect every distinct address that needs a wallet_link_id lookup.
        $addresses = [];
        foreach ($events as $e) {
            if (!is_array($e) || (int) ($e['chain_id'] ?? 0) !== $chainId) {
                $result['skipped']++;
                continue;
            }
            $to   = strtolower((string) ($e['to_address'] ?? ''));
            $from = strtolower((string) ($e['from_address'] ?? ''));
            if ($to !== '' && $to !== self::ZERO_ADDRESS) {
                $addresses[$to] = true;
            }
            if ($from !== '' && $from !== self::ZERO_ADDRESS) {
                $addresses[$from] = true;
            }
        }

        $walletIdMap = WalletRepository::findIdsByChainAddresses($chainId, array_keys($addresses));

        // Step 2: per-event spam-decision cache keyed by lowercased contract.
        // Each contract lookup is one cached repo call + a regex per default
        // pattern; cache prevents re-running for the same contract across the batch.
        $spamCache = [];

        // Step 3: net every (wallet_link_id, contract, token_id) key down to
        // its LAST operation, then persist in a transaction.
        //
        // The flat-list approach (collect all OUTs, all INs, then apply
        // upserts-then-deletes in ingestBatch) is WRONG for intra-batch
        // churn. For a token that leaves and returns to the same wallet
        // within one batch — events [B→C] then [C→B] — B lands in BOTH the
        // upserts (the C→B inbound leg) AND the deletes (the B→C outbound
        // leg). ingestBatch applies all upserts then all deletes, so B's
        // upserted row gets DELETED → B falsely shows NOT holding a token
        // whose last transfer was inbound to B.
        //
        // Fix: collapse to one operation per key, keyed by the LAST event
        // that touched it. Events arrive ascending by (block_number,
        // log-index) from the fetcher (Alchemy order=asc), and we assign a
        // per-event monotonic $seq so equal block_numbers still order
        // deterministically. The highest (block, seq) wins the key. After
        // the loop each key is in EXACTLY ONE of $upsertRows / $deletes, so
        // ingestBatch's apply order can no longer clobber a net-held key.
        $net = self::emptyNetMap();
        $seq = 0;

        foreach ($events as $e) {
            if (!is_array($e) || (int) ($e['chain_id'] ?? 0) !== $chainId) {
                // Already counted as skipped above when chain_id mismatched.
                continue;
            }

            $contract = strtolower((string) ($e['contract_address'] ?? ''));
            $tokenId  = (string) ($e['token_id'] ?? '');
            $confAt   = (string) ($e['confirmed_at'] ?? '');
            $blkNum   = (int) ($e['block_number'] ?? 0);

            if ($contract === '' || $tokenId === '' || $confAt === '' || $blkNum <= 0) {
                $result['skipped']++;
                continue;
            }

            $to   = strtolower((string) ($e['to_address'] ?? ''));
            $from = strtolower((string) ($e['from_address'] ?? ''));

            $toLinkId   = ($to !== '' && $to !== self::ZERO_ADDRESS) ? ($walletIdMap[$to] ?? 0) : 0;
            $fromLinkId = ($from !== '' && $from !== self::ZERO_ADDRESS) ? ($walletIdMap[$from] ?? 0) : 0;

            // No tracked wallet on either leg → not our business.
            if ($toLinkId === 0 && $fromLinkId === 0) {
                $result['skipped']++;
                continue;
            }

            // Monotonic per-event sequence — incremented ONCE per processed
            // event, BEFORE both legs, so the OUT and IN legs of the same
            // event share an order tuple. Combined with $blkNum it gives a
            // total order even when multiple events land in one block.
            $seq++;
            $order = [$blkNum, $seq];

            // OUT leg — the sender no longer holds this token. Record a
            // delete only if no later event has already claimed this key.
            // "<=" (not "<") means a self-transfer's IN leg, which shares
            // this exact order tuple but runs LATER in code, overwrites the
            // delete → a from==to self-transfer correctly keeps the token.
            if ($fromLinkId > 0) {
                $key = $fromLinkId . '|' . $contract . '|' . $tokenId;
                if (!isset($net[$key]) || self::orderLte($net[$key]['order'], $order)) {
                    $net[$key] = [
                        'op'    => 'delete',
                        'order' => $order,
                        'del'   => [
                            'wallet_link_id' => $fromLinkId,
                            'contract'       => $contract,
                            'token_id'       => $tokenId,
                        ],
                    ];
                }
            }

            // IN leg — upsert into the receiver, with spam decision baked in.
            if ($toLinkId > 0) {
                if (!array_key_exists($contract, $spamCache)) {
                    $spamCache[$contract] = NftSpamFilter::isSpam(
                        $chainId,
                        $contract,
                        isset($e['collection_name']) && is_string($e['collection_name']) ? $e['collection_name'] : null
                    );
                }
                $isSpam = $spamCache[$contract];

                $tokenStd = isset($e['token_standard']) && is_string($e['token_standard'])
                    ? $e['token_standard']
                    : null;

                $upsertRow = [
                    'wallet_link_id'   => $toLinkId,
                    'chain_id'         => $chainId,
                    'contract_address' => $contract,
                    'token_id'         => $tokenId,
                    'token_standard'   => $tokenStd,
                    'balance'          => max(1, (int) ($e['amount'] ?? 1)),
                    'metadata_status'  => $isSpam
                        ? NftHoldingsRepository::STATUS_SPAM
                        : NftHoldingsRepository::STATUS_PENDING,
                    'last_seen_block'  => $blkNum,
                    'confirmed_at'     => $confAt,
                ];

                $key = $toLinkId . '|' . $contract . '|' . $tokenId;
                if (!isset($net[$key]) || self::orderLte($net[$key]['order'], $order)) {
                    $net[$key] = [
                        'op'    => 'upsert',
                        'order' => $order,
                        'row'   => $upsertRow,
                    ];
                }

                if ($isSpam) {
                    $result['spam_filtered']++;
                }
            }
        }

        // Split the netted map back into the two flat lists ingestBatch
        // expects. Each key resolved to exactly one op, so a key can no
        // longer appear in both lists. For the B→C→B churn case this nets
        // to: upsert(B,tok) [last event C→B was inbound to B] +
        // delete(C,tok) [C's last event was the C→B outbound] — B holds the
        // token, C does not. Correct.
        $upsertRows = [];
        $deletes    = [];
        foreach ($net as $entry) {
            if ($entry['op'] === 'upsert') {
                $upsertRows[] = $entry['row'];
            } else {
                $deletes[] = $entry['del'];
            }
        }

        // Step 4: persist atomically. The repository wraps upserts +
        // deletes in one transaction so a mid-batch hiccup cannot leave
        // the chain checkpoint out of sync with row state. A thrown
        // exception bubbles up to the worker, which then degrades the
        // chain state instead of advancing as if the tick succeeded.
        $persisted          = NftHoldingsRepository::ingestBatch($upsertRows, $deletes);
        $result['inserts']  = $persisted['inserts'];
        $result['deletes']  = $persisted['deletes'];

        // Step 5: discovery bridge. Every distinct (chain, contract) we
        // upserted into wp_bcc_nft_holdings should have a matching stub
        // row in wp_bcc_onchain_collections so the admin Verify page sees
        // the collection. Runs OUTSIDE the holdings transaction —
        // collections-table writes are best-effort: a failure here must
        // not roll back successfully-persisted holdings. NftEnrichmentService
        // backfills name / image / floor / supply on a separate cron tick.
        if ($upsertRows !== []) {
            try {
                CollectionRepository::ensureExistsBatch($upsertRows);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::warning('[NftHoldingsIndexer] collection discovery bridge failed', [
                    'chain_id' => $chainId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    /**
     * Typed empty seed for the net map. Exists purely so PHPStan infers
     * `array<string, NetEntry>` for `$net` from the first assignment
     * (rather than `array{}`), keeping the per-entry access type-clean
     * without a `@var` suppression.
     *
     * @return array<string, NetEntry>
     */
    private static function emptyNetMap(): array
    {
        return [];
    }

    /**
     * True when order tuple $a is less-than-or-equal-to $b under the
     * (block_number, seq) total order. Used so a later event — or a
     * same-event IN leg that runs after the OUT leg — wins the key.
     *
     * @param array{int, int} $a
     * @param array{int, int} $b
     */
    private static function orderLte(array $a, array $b): bool
    {
        if ($a[0] !== $b[0]) {
            return $a[0] < $b[0];
        }
        return $a[1] <= $b[1];
    }
}
