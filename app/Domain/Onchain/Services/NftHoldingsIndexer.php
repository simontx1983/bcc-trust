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
 * Per (wallet, contract, token) key, the persistence model depends on the
 * token standard:
 *
 *   - ERC-721 / unknown → LAST-operation-wins. A 721 token lives in exactly
 *     one wallet, so its last transfer decides presence: OUT → delete,
 *     IN → absolute upsert (balance 1). Idempotent + overlap-safe.
 *
 *   - ERC-1155 → RUNNING BALANCE. A wallet holds a QUANTITY of a token; a
 *     partial transfer-out must DECREMENT the balance, not delete the row.
 *     We accumulate a signed net delta (Σ in − Σ out) per key and let the
 *     repository apply it as `balance += delta` (clamped at 0), block-guarded
 *     so a re-processed range is idempotent, deleting the row only when the
 *     balance reaches zero. This fixes the eviction of a still-holding 1155
 *     owner from a gated group after a partial transfer-out.
 *
 * Spam decision happens BEFORE persistence — when NftSpamFilter says
 * a contract is spam, the IN-leg row is still written but flagged
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
 * @phpstan-type DeltaRow array{
 *     wallet_link_id: int,
 *     chain_id: int,
 *     contract_address: string,
 *     token_id: string,
 *     token_standard: ?string,
 *     delta: int,
 *     metadata_status: int,
 *     last_seen_block: int,
 *     confirmed_at: string,
 *     collection_name: ?string
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
 * @phpstan-type BatchPlan array{
 *     upserts: list<UpsertRow>,
 *     deletes: list<DeleteRow>,
 *     deltas: list<DeltaRow>,
 *     skipped: int,
 *     spam_filtered: int
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

        // Step 2: plan the batch (pure). Spam decisions are resolved lazily
        // via the injected resolver, memoized per contract, so we only touch
        // NftSpamFilter for contracts that actually land on a tracked wallet.
        $spamCache = [];
        $isSpam = static function (string $contract, ?string $name) use ($chainId, &$spamCache): bool {
            if (!array_key_exists($contract, $spamCache)) {
                $spamCache[$contract] = NftSpamFilter::isSpam($chainId, $contract, $name);
            }
            return $spamCache[$contract];
        };

        $plan = self::planBatch($chainId, $events, $walletIdMap, $isSpam);
        $result['skipped']       = $plan['skipped'];
        $result['spam_filtered'] = $plan['spam_filtered'];

        // Step 3: persist atomically. The repository wraps 721 upserts,
        // 1155 balance deltas, and 721 deletes in one transaction so a
        // mid-batch hiccup cannot leave the chain checkpoint out of sync
        // with row state. A thrown exception bubbles up to the worker,
        // which then degrades the chain state instead of advancing.
        $persisted          = NftHoldingsRepository::ingestBatch($plan['upserts'], $plan['deletes'], $plan['deltas']);
        $result['inserts']  = $persisted['inserts'];
        $result['deletes']  = $persisted['deletes'];

        // Step 4: discovery bridge. Every distinct (chain, contract) we
        // established or increased a holding in should have a matching stub
        // row in wp_bcc_onchain_collections so the admin Verify page sees
        // the collection — both 721 upserts AND 1155 positive-net deltas.
        // Runs OUTSIDE the holdings transaction — collections-table writes
        // are best-effort: a failure here must not roll back persisted
        // holdings. NftEnrichmentService backfills name / image / floor /
        // supply on a separate cron tick.
        $discovery = $plan['upserts'];
        foreach ($plan['deltas'] as $d) {
            if ($d['delta'] > 0) {
                $discovery[] = $d;
            }
        }
        if ($discovery !== []) {
            try {
                CollectionRepository::ensureExistsBatch($discovery);
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
     * Pure planner: fold a batch of transfer events into the three
     * persistence lists. No DB access — unit-testable in isolation.
     *
     * The 721/unknown net map collapses each key to its LAST operation
     * (events arrive ascending by (block, log-index); a per-event monotonic
     * $seq keeps equal block numbers deterministic). The 1155 delta map
     * accumulates a signed net quantity per key. The two maps never share a
     * key (a contract is a single standard), so 721 behavior is unchanged.
     *
     * @param list<array<string, mixed>>      $events
     * @param array<string, int>              $walletIdMap lowercased address → wallet_link_id
     * @param callable(string, ?string): bool $isSpam contract, collection-name → spam?
     * @return BatchPlan
     */
    public static function planBatch(int $chainId, array $events, array $walletIdMap, callable $isSpam): array
    {
        $net          = self::emptyNetMap();
        /** @var array<string, array{wallet_link_id:int, chain_id:int, contract_address:string, token_id:string, token_standard:?string, delta:int, metadata_status:int, last_seen_block:int, confirmed_at:string, collection_name:?string, order:array{int,int}}> $deltaMap */
        $deltaMap     = [];
        $skipped      = 0;
        $spamFiltered = 0;
        $seq          = 0;

        foreach ($events as $e) {
            if (!is_array($e) || (int) ($e['chain_id'] ?? 0) !== $chainId) {
                $skipped++;
                continue;
            }

            $contract = strtolower((string) ($e['contract_address'] ?? ''));
            $tokenId  = (string) ($e['token_id'] ?? '');
            $confAt   = (string) ($e['confirmed_at'] ?? '');
            $blkNum   = (int) ($e['block_number'] ?? 0);

            if ($contract === '' || $tokenId === '' || $confAt === '' || $blkNum <= 0) {
                $skipped++;
                continue;
            }

            $to   = strtolower((string) ($e['to_address'] ?? ''));
            $from = strtolower((string) ($e['from_address'] ?? ''));

            $toLinkId   = ($to !== '' && $to !== self::ZERO_ADDRESS) ? ($walletIdMap[$to] ?? 0) : 0;
            $fromLinkId = ($from !== '' && $from !== self::ZERO_ADDRESS) ? ($walletIdMap[$from] ?? 0) : 0;

            // No tracked wallet on either leg → not our business.
            if ($toLinkId === 0 && $fromLinkId === 0) {
                $skipped++;
                continue;
            }

            // Monotonic per-event sequence — incremented ONCE per processed
            // event, BEFORE both legs, so the OUT and IN legs of the same
            // event share an order tuple. Combined with $blkNum it gives a
            // total order even when multiple events land in one block.
            $seq++;
            $order = [$blkNum, $seq];

            $tokenStd = isset($e['token_standard']) && is_string($e['token_standard']) ? $e['token_standard'] : null;
            $amount   = max(1, (int) ($e['amount'] ?? 1));
            $collName = isset($e['collection_name']) && is_string($e['collection_name']) ? $e['collection_name'] : null;
            $is1155   = $tokenStd !== null && stripos($tokenStd, '1155') !== false;

            if ($is1155) {
                // ── ERC-1155: accumulate a signed running-balance delta ──
                // OUT leg decrements the sender; IN leg increments the
                // receiver. A partial transfer-out therefore nets to a
                // positive residual instead of deleting the whole holding.
                if ($fromLinkId > 0) {
                    self::accumulateDelta(
                        $deltaMap, $fromLinkId, $chainId, $contract, $tokenId,
                        $tokenStd, -$amount, NftHoldingsRepository::STATUS_PENDING,
                        $blkNum, $confAt, $order, $collName, false
                    );
                }
                if ($toLinkId > 0) {
                    $spam   = $isSpam($contract, $collName);
                    $status = $spam ? NftHoldingsRepository::STATUS_SPAM : NftHoldingsRepository::STATUS_PENDING;
                    self::accumulateDelta(
                        $deltaMap, $toLinkId, $chainId, $contract, $tokenId,
                        $tokenStd, $amount, $status,
                        $blkNum, $confAt, $order, $collName, true
                    );
                    if ($spam) {
                        $spamFiltered++;
                    }
                }
                continue;
            }

            // ── ERC-721 / unknown standard: last-operation-wins ──
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

            if ($toLinkId > 0) {
                $spam   = $isSpam($contract, $collName);
                $status = $spam ? NftHoldingsRepository::STATUS_SPAM : NftHoldingsRepository::STATUS_PENDING;

                $upsertRow = [
                    'wallet_link_id'   => $toLinkId,
                    'chain_id'         => $chainId,
                    'contract_address' => $contract,
                    'token_id'         => $tokenId,
                    'token_standard'   => $tokenStd,
                    'balance'          => $amount,
                    'metadata_status'  => $status,
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

                if ($spam) {
                    $spamFiltered++;
                }
            }
        }

        // Split the netted 721 map back into the two flat lists. Each key
        // resolved to exactly one op, so a key can no longer appear in both.
        $upserts = [];
        $deletes = [];
        foreach ($net as $entry) {
            if ($entry['op'] === 'upsert') {
                $upserts[] = $entry['row'];
            } else {
                $deletes[] = $entry['del'];
            }
        }

        // Emit the 1155 deltas. A net-zero delta (e.g. a self-transfer, or an
        // in-then-out that cancels within the batch) is a no-op — drop it.
        $deltas = [];
        foreach ($deltaMap as $entry) {
            if ($entry['delta'] === 0) {
                continue;
            }
            $deltas[] = [
                'wallet_link_id'   => $entry['wallet_link_id'],
                'chain_id'         => $entry['chain_id'],
                'contract_address' => $entry['contract_address'],
                'token_id'         => $entry['token_id'],
                'token_standard'   => $entry['token_standard'],
                'delta'            => $entry['delta'],
                'metadata_status'  => $entry['metadata_status'],
                'last_seen_block'  => $entry['last_seen_block'],
                'confirmed_at'     => $entry['confirmed_at'],
                'collection_name'  => $entry['collection_name'],
            ];
        }

        return [
            'upserts'       => $upserts,
            'deletes'       => $deletes,
            'deltas'        => $deltas,
            'skipped'       => $skipped,
            'spam_filtered' => $spamFiltered,
        ];
    }

    /**
     * Accumulate a signed ERC-1155 delta into the running-balance map,
     * tracking the latest event's block/confirmed_at (the repository's
     * idempotency guard keys on last_seen_block). The IN leg additionally
     * sets metadata_status from the collection's spam classification.
     *
     * @param array<string, array{wallet_link_id:int, chain_id:int, contract_address:string, token_id:string, token_standard:?string, delta:int, metadata_status:int, last_seen_block:int, confirmed_at:string, collection_name:?string, order:array{int,int}}> $deltaMap
     * @param array{int, int} $order
     */
    private static function accumulateDelta(
        array &$deltaMap,
        int $walletLinkId,
        int $chainId,
        string $contract,
        string $tokenId,
        ?string $tokenStd,
        int $signedAmount,
        int $status,
        int $blkNum,
        string $confAt,
        array $order,
        ?string $collName,
        bool $isInLeg
    ): void {
        $key = $walletLinkId . '|' . $contract . '|' . $tokenId;

        if (!isset($deltaMap[$key])) {
            $deltaMap[$key] = [
                'wallet_link_id'   => $walletLinkId,
                'chain_id'         => $chainId,
                'contract_address' => $contract,
                'token_id'         => $tokenId,
                'token_standard'   => $tokenStd,
                'delta'            => 0,
                'metadata_status'  => $status,
                'last_seen_block'  => $blkNum,
                'confirmed_at'     => $confAt,
                'collection_name'  => $collName,
                'order'            => $order,
            ];
        }

        $deltaMap[$key]['delta'] += $signedAmount;

        // Latest event wins the block/confirmed_at/collection_name fields.
        if (self::orderLte($deltaMap[$key]['order'], $order)) {
            $deltaMap[$key]['order']           = $order;
            $deltaMap[$key]['last_seen_block'] = $blkNum;
            $deltaMap[$key]['confirmed_at']    = $confAt;
            if ($collName !== null) {
                $deltaMap[$key]['collection_name'] = $collName;
            }
        }

        // Spam / status is a property of the collection, decided on the IN
        // leg. OUT legs never change it (an existing row keeps its status).
        if ($isInLeg) {
            $deltaMap[$key]['metadata_status'] = $status;
        }
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
