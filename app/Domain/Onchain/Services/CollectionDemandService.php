<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * "How many linked wallets hold this collection?" — the demand signal
 * behind the Verify Collections queue, computed ONLY from data BCC already
 * stores.
 *
 * ── WHAT THIS USED TO DO, AND WHY IT MUST NOT ───────────────────────────
 * The Cosmos Hub half of this map was built at page RENDER time by sending
 * every linked Cosmos Hub wallet address — up to 200 per render, one per
 * request, up to 3 pages each, retried by ApiRetry — to
 * `marketplace-api.cosmos.stargaze-apis.com`, an undocumented third-party
 * endpoint Stargaze does not publish as a supported integration. Merely
 * OPENING an administrator page therefore disclosed which Cosmos addresses
 * belong to BCC members, to a party BCC has no agreement with. Its two
 * caches (`wp_cache` 15 min for the map, 6 h per wallet) only persist on
 * installs with a persistent object cache; without one, every render
 * re-sent every address.
 *
 * It is not moved to cron and not hidden behind a longer cache. Either would
 * keep the disclosure and only change when it happens.
 *
 * ── WHY COSMOS HUB DEMAND IS "NOT CALCULATED", NOT ZERO ─────────────────
 * No stored data can answer it. The holdings index
 * (`wp_bcc_nft_holdings`) is written for EVM (NftEthIndexerWorker) and
 * Solana (Helius → NftHoldingsIndexer) only — Cosmos is read-time by design.
 * `wp_bcc_onchain_collections.wallet_link_id` looks like a holder link but
 * is not: the table is UNIQUE on (chain_id, contract_address) and that
 * column is first-writer-wins, so it records at most ONE wallet per
 * collection and cannot count distinct holders.
 *
 * So a chain without a holdings index reports {@see STATE_NOT_CALCULATED}.
 * The page renders that as words, never as `0` or a dash that reads as
 * "nobody", and nothing refreshes it silently.
 *
 * ── FAILURE IS NEVER A ZERO ─────────────────────────────────────────────
 * A failed index read ({@see NftHoldingsRepository::countDistinctWalletsPerContract()}
 * returns null) makes every indexed row {@see STATE_UNAVAILABLE}. A result
 * that hit the contract limit makes every indexed row WITHOUT a count
 * unavailable too, because a contract past the limit may still have holders.
 * Only a complete, successful read may say {@see STATE_NONE_INDEXED}.
 *
 * ── IF COSMOS HUB DEMAND IS EVER WANTED ─────────────────────────────────
 * It needs a deliberate design, not a restored call: an explicit,
 * administrator-initiated refresh (POST, capability + nonce, never on GET,
 * never on a timer), against a provider with published API terms, after a
 * privacy review of sending member wallet addresses off-platform, with the
 * result persisted and dated so the page can say how old it is. None of
 * that exists, so none of it is built here.
 *
 * ── THIS CLASS HAS NO SIDE EFFECTS ──────────────────────────────────────
 * One bounded read. No HTTP, no option, no transient, no object-cache
 * write — the previous 15-minute `wp_cache` blob existed to amortise the
 * outbound calls, and without them it is a cache of a single GROUP BY.
 * Pinned by `VerifyCollectionsRenderIsolationTest` and
 * `StargazeCallerInventoryTest`.
 */
final class CollectionDemandService
{
    /** At least one linked wallet is indexed as holding it. */
    public const STATE_COUNTED = 'counted';

    /** Indexed chain, complete successful read, no linked holder in the index. */
    public const STATE_NONE_INDEXED = 'none_indexed';

    /** Indexed chain, but the read failed or was truncated — the answer is unknown. */
    public const STATE_UNAVAILABLE = 'unavailable';

    /** BCC keeps no holdings index for this chain, so it does not count. */
    public const STATE_NOT_CALCULATED = 'not_calculated';

    /**
     * Chain types whose holdings BCC persists in `wp_bcc_nft_holdings`.
     *
     * The two writers are NftEthIndexerWorker (EVM) and NftHoldingsIndexer,
     * fed by the Helius webhook (Solana). Cosmos is deliberately absent:
     * its ownership is read-time, and nothing persists it.
     *
     * @var list<string>
     */
    public const INDEXED_CHAIN_TYPES = ['evm', 'solana'];

    /**
     * Distinct contracts read from the index. One more is requested than is
     * kept, so a result that reached the limit is KNOWN to be truncated
     * rather than assumed complete.
     */
    private const CONTRACT_LIMIT = 500;

    /**
     * The demand map from the holdings index, with its own reliability.
     *
     * @return array{counts: array<string, int>, available: bool, complete: bool}
     *         `counts` keyed by {@see key()}; `available` false when the read
     *         failed; `complete` false when the read was truncated or failed
     */
    public static function linkedHolderCounts(): array
    {
        $rows = NftHoldingsRepository::countDistinctWalletsPerContract(self::CONTRACT_LIMIT + 1);

        if ($rows === null) {
            return ['counts' => [], 'available' => false, 'complete' => false];
        }

        $counts = [];
        foreach (array_slice($rows, 0, self::CONTRACT_LIMIT) as $row) {
            $wallets = (int) $row->wallets;
            if ($wallets < 1) {
                continue;
            }
            $counts[self::key((int) $row->chain_id, (string) $row->contract_address)] = $wallets;
        }

        return [
            'counts'    => $counts,
            'available' => true,
            'complete'  => count($rows) <= self::CONTRACT_LIMIT,
        ];
    }

    /** Composite key shared with the Verify Collections page. */
    public static function key(int $chainId, string $contract): string
    {
        return $chainId . '|' . strtolower($contract);
    }

    /** PURE. Does BCC keep a holdings index for this chain type? */
    public static function isIndexedChainType(string $chainType): bool
    {
        return in_array($chainType, self::INDEXED_CHAIN_TYPES, true);
    }

    /**
     * PURE. What one row's Linked holders cell may truthfully say.
     *
     * @param array{counts: array<string, int>, available: bool, complete: bool} $demand
     * @return array{state: string, count: int|null} `count` is set only for STATE_COUNTED
     */
    public static function rowState(array $demand, string $chainType, int $chainId, string $contract): array
    {
        if (!self::isIndexedChainType($chainType)) {
            return ['state' => self::STATE_NOT_CALCULATED, 'count' => null];
        }

        $count = $demand['counts'][self::key($chainId, $contract)] ?? null;
        if ($count !== null && $count > 0) {
            // Positive evidence survives a truncated or partial read: these
            // wallets were indexed as holders whatever else went wrong.
            return ['state' => self::STATE_COUNTED, 'count' => $count];
        }

        if (!$demand['available'] || !$demand['complete']) {
            return ['state' => self::STATE_UNAVAILABLE, 'count' => null];
        }

        return ['state' => self::STATE_NONE_INDEXED, 'count' => null];
    }
}
