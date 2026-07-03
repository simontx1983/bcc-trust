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
use BCC\Trust\Onchain\Support\StargazeMarketplaceApi;

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
        // Primary evidence = the SAME sources the panel rendered from
        // (marketplace rollup / holdings index) — if we showed the user
        // the row, we must accept their stance on it; some CW-721
        // variants aren't enumerable via the LCD tokens{owner} walk that
        // ownsAny uses, and rejecting those reads as a bug. ownsAny is
        // the fallback for holdings fresher than the cached sources.
        if (!self::holdsPerPanelSources($userId, (int) $chain->id, (string) $chain->slug, $contract)) {
            $count = HoldingsService::ownsAny($userId, (string) $chain->slug, $contract);
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
     * Does the viewer hold this collection per the panel's own sources
     * (cosmos marketplace rollup / EVM holdings index)? Cheap: cached
     * rollups + bounded index reads, no LCD walk.
     */
    private static function holdsPerPanelSources(int $userId, int $chainId, string $chainSlug, string $contract): bool
    {
        foreach (WalletRepository::getForUser($userId) as $wallet) {
            if ((int) $wallet->chain_id !== $chainId) {
                continue;
            }

            if ($chainSlug === 'cosmos') {
                $rollup = StargazeMarketplaceApi::profileCollections((string) $wallet->wallet_address);
                foreach ($rollup ?? [] as $c) {
                    if ($c['contract_address'] === $contract) {
                        return true;
                    }
                }
                continue;
            }

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
     * Sources are the same ones discovery uses (holdings index for
     * EVM/SOL, cached marketplace rollups for the Hub) — no fresh RPC
     * walks; the panel is cheap enough for wallet-link time.
     *
     * @return list<array{
     *     chain_id: int,
     *     chain_slug: string,
     *     contract_address: string,
     *     name: ?string,
     *     image_url: ?string,
     *     collection_verified: bool,
     *     state: string,
     *     group_id: ?int,
     *     waitlist_count: int,
     *     viewer_stance: ?string
     * }>
     */
    public static function panelForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        // 1. Held collections per chain, from the cheap sources.
        /** @var array<int, array<string, array{name: ?string, image_url: ?string}>> $byChain */
        $byChain    = [];
        $chainSlugs = [];

        foreach (WalletRepository::getForUser($userId) as $wallet) {
            $chain = ChainRepository::getById((int) $wallet->chain_id);
            if ($chain === null) {
                continue;
            }
            $chainId              = (int) $chain->id;
            $chainSlugs[$chainId] = (string) $chain->slug;

            if ((string) $chain->slug === 'cosmos') {
                $rollup = StargazeMarketplaceApi::profileCollections((string) $wallet->wallet_address);
                foreach ($rollup ?? [] as $c) {
                    $byChain[$chainId][$c['contract_address']] ??= [
                        'name'      => $c['collection_name'],
                        'image_url' => $c['image_url'],
                    ];
                }
                continue;
            }

            // Indexed chains: the persistent holdings rows already carry
            // collection names/images from enrichment.
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
        }

        if ($byChain === []) {
            return [];
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

        return array_slice($rows, 0, self::PANEL_ROW_CAP);
    }
}
