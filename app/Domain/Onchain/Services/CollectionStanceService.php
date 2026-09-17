<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CollectionSignalRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * User stances on discovered collections — the airdrop-proof demand
 * signal.
 *
 * The problem this exists for: passive holding is forgeable — a scammer
 * can airdrop a token into every linked wallet, which poisons any
 * demand metric derived from "N wallets hold this." An explicit
 * user stance cannot be airdropped. Two stances:
 *
 *   waitlist — "activate this community and count me in." Ranks the
 *              admin Verify Collections queue; converts into a go-live
 *              bell when the community provisions.
 *   spam     — "this is airdropped junk." Tallying these soft-hides the
 *              collection from user-facing surfaces at
 *              SPAM_SOFT_HIDE_THRESHOLD and badges it red on the admin
 *              queue. The hard kill stays with the operator
 *              (NftSpamContractRepository RULE_DENY via the admin Hide
 *              button) — user flags alone can be brigaded, so they
 *              never deny anything irreversibly on their own.
 *
 * Both stances are HOLDER-GATED: you can only take a stance on a
 * collection a linked wallet of yours actually holds (for spam, you
 * received the airdrop — you're the witness). One stance per user per
 * collection, switchable.
 */
final class CollectionStanceService
{
    /**
     * User spam flags at/above this soft-hide the collection from
     * user-facing surfaces (stance panel; admin queue keeps showing it
     * with a red count). Override via BCC_COLLECTION_SPAM_SOFT_HIDE.
     */
    private const DEFAULT_SPAM_SOFT_HIDE_THRESHOLD = 3;

    /** Panel row ceiling — response-size bound, largest surfaces first. */
    private const PANEL_ROW_CAP = 60;

    /**
     * ── THE HOLDINGS-STATUS VOCABULARY (closed set) ─────────────────────
     *
     * Rendered as `holdings_status` on `GET /me/collection-stances/panel`.
     * It exists because `items: []` answered two different questions with
     * the same silence, and the interface read that silence as a verdict:
     * "No collections detected in your linked wallets yet."
     *
     *   complete    — every source this user has finished. An empty list is
     *                 TRUSTWORTHY: they hold no verified collections.
     *   partial     — at least one source finished and at least one did not.
     *                 Anything present is real; ABSENCE PROVES NOTHING.
     *   unavailable — no source finished. Nothing can be concluded at all.
     *
     * ⚠ `null` is not a member of this set. A nullable status would smuggle
     * the old ambiguity back in under a new name. The field may be ABSENT
     * only from an older backend mid-deploy, which is a deployment fact,
     * not a state.
     */
    public const STATUS_COMPLETE    = 'complete';
    public const STATUS_PARTIAL     = 'partial';
    public const STATUS_UNAVAILABLE = 'unavailable';

    /** The closed vocabulary, in the order the contract fixture pins it. */
    public const HOLDINGS_STATUSES = [
        self::STATUS_COMPLETE,
        self::STATUS_PARTIAL,
        self::STATUS_UNAVAILABLE,
    ];

    /**
     * Chain types whose holdings BCC has already stored locally, so the
     * panel reads rows instead of asking a provider.
     *
     * Everything else (Cosmos today) is read on demand, over the chain's
     * own LCD, bounded to verified collections — never a marketplace.
     *
     * @var list<string>
     */
    private const STORED_INDEX_CHAIN_TYPES = ['evm', 'solana'];

    /** PURE. Does this chain type answer from the stored holdings index? */
    private static function readsFromStoredIndex(string $chainType): bool
    {
        return in_array($chainType, self::STORED_INDEX_CHAIN_TYPES, true);
    }

    /**
     * PURE. Fold per-source outcomes into the closed vocabulary.
     *
     * A user with NO linked wallets has nothing that could fail, so the
     * empty panel is complete — the honest answer is "no verified
     * collections", not "we couldn't check".
     *
     * ⚠ POSITIVE EVIDENCE FORCES `partial`, NEVER `unavailable`. A walk can
     * come back incomplete having ALREADY resolved some collections: those
     * rows are real and are returned. `unavailable` claims no trustworthy
     * determination was possible at all, which would be false while we are
     * handing the caller collections we just proved. The distinction
     * matters because the frontend renders `unavailable` as a blanket
     * "temporarily unavailable" and would hide rows the user does hold.
     *
     * @param  bool $hasEvidence at least one collection was resolved
     * @return self::STATUS_* the vocabulary is CLOSED. A fourth value must
     *         be added to the constants, the shared contract fixture and
     *         both repositories' drift tests — PHPStan fails here first.
     */
    private static function resolveStatus(int $sourcesOk, int $sourcesFailed, bool $hasEvidence): string
    {
        if ($sourcesFailed === 0) {
            return self::STATUS_COMPLETE;
        }

        return ($sourcesOk > 0 || $hasEvidence) ? self::STATUS_PARTIAL : self::STATUS_UNAVAILABLE;
    }

    public static function spamSoftHideThreshold(): int
    {
        return defined('BCC_COLLECTION_SPAM_SOFT_HIDE')
            ? max(1, (int) BCC_COLLECTION_SPAM_SOFT_HIDE)
            : self::DEFAULT_SPAM_SOFT_HIDE_THRESHOLD;
    }

    /**
     * Set the viewer's stance. Holder-gated.
     *
     * @return array{ok: bool, error?: string}
     */
    public static function setStance(int $userId, int $chainId, string $contract, string $stance): array
    {
        $contract = strtolower(trim($contract));
        if ($userId <= 0 || $chainId <= 0 || $contract === ''
            || !in_array($stance, CollectionSignalRepository::STANCES, true)
        ) {
            return ['ok' => false, 'error' => 'bcc_invalid_request'];
        }

        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return ['ok' => false, 'error' => 'bcc_invalid_request'];
        }

        // Holder gate: a stance is testimony about a collection you hold
        // (waitlist = "I'd join as a holder", spam = "I received this").
        //
        // Primary evidence is BCC's STORED holdings index — the same rows
        // the panel rendered from. If we showed the user the row, we must
        // accept their stance on it.
        //
        // ⚠ Until PR 7.12 this sentence also named a "marketplace rollup",
        // and for Cosmos that is exactly what it consulted: writing a
        // stance sent the member's Hub address to an undocumented API. The
        // rollup is gone. When the stored index is silent, the fallback is
        // `ownsAny()`, which proves the SPECIFIC contract on-chain and
        // already separates the two answers that must never be conflated:
        // null → "could not verify" (503, retryable), 0 → "definitely not
        // held" (403). Neither grants anything without positive proof.
        //
        // PR 7.14: an inactive chain, a driver that cannot count, a failed
        // read or incomplete evidence is now null (503) as well — none of
        // them proves the member does not hold it. Reads are bounded by the
        // stance budget, and a retry resumes with the wallets the previous
        // attempt did not reach.
        if (!self::holdsPerPanelSources($userId, (int) $chain->id, $contract)) {
            $count = HoldingsService::ownsAny(
                $userId,
                (string) $chain->slug,
                $contract,
                HoldingsService::verificationBudget(HoldingsService::SURFACE_STANCE),
                true
            );
            if ($count === null) {
                return ['ok' => false, 'error' => 'bcc_unavailable'];
            }
            if ($count < 1) {
                return ['ok' => false, 'error' => 'bcc_nft_not_owned'];
            }
        }

        $written = CollectionSignalRepository::setStance($userId, $chainId, $contract, $stance);
        if (!$written) {
            return ['ok' => false, 'error' => 'bcc_internal_error'];
        }

        return ['ok' => true];
    }

    /**
     * Clear the viewer's stance (back to neutral). Not holder-gated —
     * you can always retract your own testimony (e.g. after selling).
     *
     * @return array{ok: bool, error?: string}
     */
    public static function clearStance(int $userId, int $chainId, string $contract): array
    {
        $contract = strtolower(trim($contract));
        if ($userId <= 0 || $chainId <= 0 || $contract === '') {
            return ['ok' => false, 'error' => 'bcc_invalid_request'];
        }

        return CollectionSignalRepository::clearStance($userId, $chainId, $contract)
            ? ['ok' => true]
            : ['ok' => false, 'error' => 'bcc_internal_error'];
    }

    /**
     * Does the viewer hold this collection per BCC's STORED evidence?
     * One bounded index read per linked wallet — no provider call.
     *
     * The chain slug is gone from the signature on purpose: it existed
     * only to route `cosmos` into the Stargaze marketplace rollup, and a
     * parameter that no longer decides anything is an invitation to
     * re-add the branch that used it. When stored evidence is silent the
     * caller falls through to `HoldingsService::ownsAny()`, which proves
     * the specific contract on-chain and keeps "definitely not held"
     * (403) distinct from "could not verify" (503).
     */
    private static function holdsPerPanelSources(int $userId, int $chainId, string $contract): bool
    {
        foreach (WalletRepository::getForUser($userId) as $wallet) {
            if ((int) $wallet->chain_id !== $chainId) {
                continue;
            }

            // ⚠ NO CHAIN IS SPECIAL-CASED HERE ANY MORE.
            //
            // Until PR 7.12 the `cosmos` slug short-circuited into the
            // Stargaze marketplace rollup, so writing a stance sent the
            // member's Hub address to an undocumented third party. That
            // branch is gone, and nothing replaces it inside this method:
            // when the stored index has no row, the caller falls through
            // to `HoldingsService::ownsAny()`, which proves ownership of
            // the SPECIFIC contract on-chain and already distinguishes
            // "definitely not held" (403) from "could not verify" (503).
            //
            // That is strictly safer than the old shortcut: a stance write
            // now requires positive proof, and an unreadable provider can
            // no longer be mistaken for either an entitlement or a denial.
            foreach (NftHoldingsRepository::findVisibleForWallet((int) $wallet->id, $chainId) as $row) {
                if (strtolower((string) $row->contract_address) === $contract) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The stance panel: every collection the viewer's linked wallets
     * hold, with the state that decides which button pair renders.
     *
     *   state 'live'     → verified + community exists → [Join community]
     *   state 'waitlist' → not yet activated           → [Join waitlist]
     *
     * Rows the operator RULE_DENY'd or the community soft-hid (spam
     * flags ≥ threshold) are excluded — users aren't asked to take a
     * stance on known junk. The viewer's own current stance rides each
     * row so the UI renders toggles, not one-way buttons.
     *
     * Sources: the stored holdings index for EVM/Solana, and for Cosmos a
     * bounded LCD `tokens{owner}` walk over VERIFIED collections only. The
     * cached Stargaze marketplace rollup this sentence used to name is gone
     * (PR 7.12) — no wallet address leaves BCC for a third party here.
     *
     * Every source reports whether it COMPLETED. `items` alone cannot say
     * WHY it is empty, so the envelope carries `holdings_status`: only
     * `complete` licenses reading `items: []` as "holds nothing".
     *
     * @return array{
     *     items: list<array{
     *         chain_id: int,
     *         chain_slug: string,
     *         contract_address: string,
     *         name: ?string,
     *         image_url: ?string,
     *         collection_verified: bool,
     *         state: string,
     *         group_id: ?int,
     *         waitlist_count: int,
     *         viewer_stance: ?string
     *     }>,
     *     holdings_status: self::STATUS_*
     * }
     */
    public static function panelForUser(int $userId): array
    {
        if ($userId <= 0) {
            return ['items' => [], 'holdings_status' => self::STATUS_COMPLETE];
        }

        // 1. Held collections per chain, from the cheap sources — and, per
        //    source, whether that read actually COMPLETED. An empty panel
        //    means two completely different things depending on this, and
        //    conflating them is what told users "you hold nothing" while a
        //    provider was down.
        /** @var array<int, array<string, array{name: ?string, image_url: ?string}>> $byChain */
        $byChain    = [];
        $chainSlugs = [];
        $sourcesOk     = 0;
        $sourcesFailed = 0;

        foreach (WalletRepository::getForUser($userId) as $wallet) {
            $chain = ChainRepository::getById((int) $wallet->chain_id);
            if ($chain === null) {
                continue;
            }
            $chainId              = (int) $chain->id;
            $chainSlugs[$chainId] = (string) $chain->slug;

            if (!self::readsFromStoredIndex((string) ($chain->chain_type ?? ''))) {
                // Cosmos and anything else without a persistent holdings
                // index: a BOUNDED, MARKETPLACE-FREE on-chain read —
                // `tokens{owner}` over VERIFIED collections only, through
                // the same path the profile gallery uses. It reports its own
                // completeness and never caches an incomplete walk.
                //
                // Until PR 7.12 this branch sent the member's address to the
                // Stargaze marketplace API instead, and a `null` (provider
                // down) was flattened by `?? []` into "holds nothing".
                $read = HoldingsService::walletHoldingsWithCompleteness(
                    (int) $wallet->id,
                    (string) $wallet->wallet_address,
                    $chain
                );

                if ($read === null) {
                    // No driver at all — an unreadable source, not an empty
                    // wallet.
                    $sourcesFailed++;
                    continue;
                }

                foreach ($read['items'] as $item) {
                    $contract = strtolower((string) ($item['contract_address'] ?? ''));
                    if ($contract === '') {
                        continue;
                    }
                    $byChain[$chainId][$contract] ??= [
                        'name'      => is_string($item['collection_name'] ?? null) ? $item['collection_name'] : null,
                        'image_url' => is_string($item['image_url'] ?? null) ? $item['image_url'] : null,
                    ];
                }

                // `truncated` is a BOUNDED SUCCESS (a contract hit the
                // per-contract token cap) — the collection is still in the
                // list, so it does not make the collection set doubtful.
                // Only `complete` does.
                if ($read['complete'] === true) {
                    $sourcesOk++;
                } else {
                    $sourcesFailed++;
                }
                continue;
            }

            // Indexed chains (EVM, Solana): the persistent holdings rows
            // already carry collection names/images from enrichment. This
            // is a local read of stored evidence — unchanged by PR 7.12.
            foreach (NftHoldingsRepository::findVisibleForWallet((int) $wallet->id, $chainId) as $row) {
                $contract = strtolower((string) $row->contract_address);
                if ($contract === '') {
                    continue;
                }
                $byChain[$chainId][$contract] ??= [
                    'name'      => is_string($row->collection_name ?? null) ? $row->collection_name : null,
                    'image_url' => is_string($row->image_url ?? null) ? $row->image_url : null,
                ];
            }
            $sourcesOk++;
        }

        // `$byChain !== []` is the "we resolved something positive" signal:
        // a walk that failed AFTER finding collections still hands the
        // caller real rows, and calling that `unavailable` would hide them.
        $status = self::resolveStatus($sourcesOk, $sourcesFailed, $byChain !== []);

        if ($byChain === []) {
            return ['items' => [], 'holdings_status' => $status];
        }

        // 2. Signal tallies (waitlist counts + soft-hide input), one read.
        $tallies = [];
        foreach (CollectionSignalRepository::countsByCollection() as $t) {
            $tallies[(int) $t->chain_id . '|' . strtolower((string) $t->contract_address)] = [
                'waitlist' => (int) $t->waitlist_count,
                'spam'     => (int) $t->spam_count,
            ];
        }

        $threshold = self::spamSoftHideThreshold();
        $rows      = [];

        foreach ($byChain as $chainId => $contracts) {
            $addresses   = array_keys($contracts);
            $verifiedMap = CollectionRepository::verifiedMapForContracts($chainId, $addresses);
            $stances     = CollectionSignalRepository::getStancesForUser($userId, $chainId, $addresses);

            foreach ($contracts as $contract => $meta) {
                // Operator hard-hide: RULE_DENY rows never surface.
                if (NftSpamContractRepository::getRule($chainId, $contract) === NftSpamContractRepository::RULE_DENY) {
                    continue;
                }

                $tally = $tallies[$chainId . '|' . $contract] ?? ['waitlist' => 0, 'spam' => 0];

                // Community soft-hide — unless this viewer is one of the
                // flaggers (they keep seeing their own flag so they can
                // retract it).
                $viewerStance = $stances[$contract] ?? null;
                if ($tally['spam'] >= $threshold && $viewerStance !== CollectionSignalRepository::STANCE_SPAM) {
                    continue;
                }

                $verified = (bool) ($verifiedMap[$contract] ?? false);
                $groupId  = null;
                if ($verified) {
                    $groupId = GatedGroupRepository::findGroupForCollection($chainId, $contract);
                }

                $rows[] = [
                    'chain_id'            => $chainId,
                    'chain_slug'          => $chainSlugs[$chainId] ?? '',
                    'contract_address'    => $contract,
                    'name'                => $meta['name'],
                    'image_url'           => $meta['image_url'],
                    'collection_verified' => $verified,
                    'state'               => $groupId !== null ? 'live' : 'waitlist',
                    'group_id'            => $groupId,
                    'waitlist_count'      => $tally['waitlist'],
                    'viewer_stance'       => $viewerStance,
                ];
            }
        }

        // Live communities first (one click from joining), then by
        // waitlist momentum so the panel leads with what's closest to
        // happening. Cap for response size.
        usort($rows, static function (array $a, array $b): int {
            if (($a['state'] === 'live') !== ($b['state'] === 'live')) {
                return $a['state'] === 'live' ? -1 : 1;
            }
            if ($a['waitlist_count'] !== $b['waitlist_count']) {
                return $b['waitlist_count'] <=> $a['waitlist_count'];
            }
            return strcmp($a['contract_address'], $b['contract_address']);
        });

        return [
            'items'           => array_slice($rows, 0, self::PANEL_ROW_CAP),
            'holdings_status' => $status,
        ];
    }
}
