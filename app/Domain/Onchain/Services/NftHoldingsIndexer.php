<?php

namespace BCC\Trust\Onchain\Services;

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

        // Step 3: pre-resolve all upserts/deletes; persist in a transaction.
        $upsertRows = [];
        $deletes    = [];

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

            // OUT leg first — delete from the sender (a transfer-out
            // that races a transfer-in to the same wallet still nets
            // correctly because IN leg upserts the (wallet, contract,
            // token_id) row regardless).
            if ($fromLinkId > 0) {
                $deletes[] = [
                    'wallet_link_id' => $fromLinkId,
                    'contract'       => $contract,
                    'token_id'       => $tokenId,
                ];
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

                $upsertRows[] = [
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

                if ($isSpam) {
                    $result['spam_filtered']++;
                }
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

        return $result;
    }
}
