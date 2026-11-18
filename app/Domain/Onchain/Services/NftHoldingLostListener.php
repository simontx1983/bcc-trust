<?php

declare(strict_types=1);

/**
 * F-278 — a gated-group member who sells the NFT is re-checked within a
 * minute, not at the next twice-daily revoke sweep.
 *
 * NftHoldingsIndexer fires `bcc_nft_holding_lost` (wallet_link_id, chain_id,
 * contract) for every holding a committed batch removed — the Helius webhook
 * (Solana, seconds) and the EVM indexer tick (minutes) both write through it.
 * This listener maps the wallet to its member and the contract to its holder
 * group, and schedules ONE re-check per (member, group) a minute out.
 *
 * The re-check uses the revoke sweep's own rules
 * (NftGroupRevokeService::recheckMember): ELIGIBLE keeps, INELIGIBLE revokes,
 * UNKNOWN keeps — a member is removed only when it is certain they no longer
 * hold. The twice-daily sweep stays as the backstop (Cosmos has no indexer).
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class NftHoldingLostListener
{
    /** Seconds before the re-check runs (lets the batch's other writes land). */
    public const RECHECK_DELAY = 60;

    /** Seconds before retrying a re-check that found a sweep tick running. */
    public const LOCKED_RETRY_DELAY = 120;

    /** Bounded retries when the sweep holds the lock; the sweep is the backstop. */
    public const MAX_LOCKED_RETRIES = 3;

    /** @var (\Closure(int): ?int)|null test seam: wallet_link_id → user_id */
    public static ?\Closure $userForWallet = null;
    /** @var (\Closure(int, string): ?int)|null test seam: (chain, contract) → group */
    public static ?\Closure $groupForCollection = null;
    /** @var (\Closure(int, int): string)|null test seam: recheck runner */
    public static ?\Closure $recheck = null;

    public static function onHoldingLost(int $walletLinkId, int $chainId, string $contract): void
    {
        if ($walletLinkId <= 0 || $chainId <= 0 || $contract === '') {
            return;
        }

        try {
            $groupId = self::$groupForCollection !== null
                ? (self::$groupForCollection)($chainId, $contract)
                : GatedGroupRepository::findGroupForCollection($chainId, $contract);
            if ($groupId === null || $groupId <= 0) {
                return; // Not a holder-gated collection: nothing to re-check.
            }

            $userId = self::$userForWallet !== null
                ? (self::$userForWallet)($walletLinkId)
                : self::walletOwner($walletLinkId);
            if ($userId === null || $userId <= 0) {
                return;
            }

            self::schedule($userId, $groupId, self::RECHECK_DELAY, 0);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] holding-lost re-check could not be scheduled', [
                'chain_id'    => $chainId,
                'error_class' => get_class($e),
            ]);
        }
    }

    /** The scheduled re-check. A held sweep lock retries a few times, then defers to the sweep. */
    public static function runRecheck(int $userId, int $groupId, int $attempt = 0): void
    {
        if ($userId <= 0 || $groupId <= 0) {
            return;
        }

        try {
            $outcome = self::$recheck !== null
                ? (self::$recheck)($userId, $groupId)
                : \BCC\Trust\Onchain\OnchainPlugin::instance()->nftGroupRevokeService()->recheckMember($userId, $groupId);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] holding-lost re-check failed', [
                'user_id'     => $userId,
                'group_id'    => $groupId,
                'error_class' => get_class($e),
            ]);
            return;
        }

        if ($outcome === 'locked' && $attempt < self::MAX_LOCKED_RETRIES) {
            self::schedule($userId, $groupId, self::LOCKED_RETRY_DELAY, $attempt + 1);
        }
    }

    private static function schedule(int $userId, int $groupId, int $delay, int $attempt): void
    {
        $args = $attempt === 0 ? [$userId, $groupId] : [$userId, $groupId, $attempt];
        if (!wp_next_scheduled(NftGroupRevokeService::RECHECK_HOOK, $args)) {
            wp_schedule_single_event(time() + $delay, NftGroupRevokeService::RECHECK_HOOK, $args);
        }
    }

    private static function walletOwner(int $walletLinkId): ?int
    {
        $wallet = WalletRepository::getById($walletLinkId);
        return $wallet !== null ? (int) $wallet->user_id : null;
    }
}
