<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Core\DB\AdvisoryLock;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\SignalRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Applies on-chain trust bonuses to page scores via the ScoreContributor contract.
 *
 * Two entry points:
 *   - applyBonus()      — raw bonus application (signals only)
 *   - applyClaimBonus() — combined signals + claim bonuses after a claim is verified
 */
final class BonusService
{
    /**
     * Claim bonus values by role.
     */
    private const CLAIM_BONUS_MAP = [
        'operator' => 5.0,
        'creator'  => 5.0,
        'holder'   => 1.0,
    ];

    /**
     * Apply an on-chain bonus to a page's trust score.
     *
     * Delegates to bcc-trust's Core domain via ScoreContributorInterface.
     * If unavailable, queues for retry.
     */
    public static function applyBonus(int $pageId, float $bonus): void
    {
        if (!class_exists('\\BCC\\Core\\ServiceLocator')
            || !\BCC\Core\ServiceLocator::hasRealService(\BCC\Core\Contracts\ScoreContributorInterface::class)
        ) {
            BonusRetryService::queue($pageId, $bonus);
            return;
        }

        $contributor = \BCC\Core\ServiceLocator::resolveScoreContributor();
        $applied     = $contributor->applyBonus($pageId, 'onchain', $bonus);

        if (!$applied) {
            BonusRetryService::queue($pageId, $bonus);
            return;
        }

        BonusRetryService::clear($pageId);
    }

    /**
     * Handle wallet disconnect: revoke claims for the wallet and recalculate bonuses.
     *
     * Extracted from the boot file closure so business logic lives in a service.
     *
     * Atomicity: the claim delete and signal delete MUST commit together.
     * Under DB latency spikes (200-500ms), a PHP timeout between the two
     * deletes would leave claims revoked but signals intact — yielding an
     * inflated onchain_bonus that only self-heals on the next signal refresh
     * (up to 24h later). Both deletes run inside a single transaction;
     * recomputeAndApply runs AFTER commit because it takes an advisory lock
     * and calls the trust-engine, neither of which belong inside a DB txn.
     */
    public static function handleWalletDisconnect(int $userId, string $chainSlug, string $walletAddress): void
    {
        // A blank address is no wallet: there is nothing to revoke or purge,
        // and matching on it would reach claims that store no wallet.
        if (trim($walletAddress) === '') {
            if (class_exists('BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning('[bcc-onchain] handleWalletDisconnect: blank wallet address ignored', [
                    'user_id' => $userId,
                    'chain'   => $chainSlug,
                ]);
            }
            return;
        }

        // Resolve chain_id so deletion is scoped to the specific chain.
        // Prevents collateral deletion of claims on other chains sharing
        // the same address format (e.g., Ethereum + Polygon + Arbitrum).
        $chainId = \BCC\Trust\Onchain\Repositories\ChainRepository::resolveId($chainSlug);

        try {
            // F-521: revoke-and-release FIRST. Each verified claim on this
            // wallet is revoked through the audited path, which frees the page
            // it granted (PeepSo owner and author) and fires
            // bcc_onchain_claim_revoked. A failure throws before the purge, so
            // nothing is removed unaudited.
            ClaimService::revokeAllForUser($userId, 'wallet_unlinked', $walletAddress, $chainId);

            // Both deletes run atomically inside the repository's own
            // transaction. Services-layer code does not touch $wpdb
            // transaction control directly — that rule is enforced by
            // arch-guardrails.
            $deleted = \BCC\Trust\Onchain\Repositories\ClaimRepository::purgeDisconnectedWallet(
                $userId,
                $walletAddress,
                $chainId,
                $chainSlug
            );
        } catch (\Throwable $e) {
            if (class_exists('BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-onchain] handleWalletDisconnect rolled back', [
                    'user_id' => $userId,
                    'chain'   => $chainSlug,
                    'error'   => $e->getMessage(),
                ]);
            }
            throw $e;
        }

        // Recalculate the on-chain bonus with the claims AND signals removed.
        // Must include BOTH signal scores AND remaining claim bonuses
        // (from other wallets the user still has connected).
        // Idempotent: if anything below fails, the next refresh recomputes.
        $pageId = \BCC\Core\ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($userId);
        if ($pageId) {
            self::recomputeAndApply($pageId, $userId);
        }

        if ($deleted && class_exists('BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::info('[bcc-onchain] claims revoked on wallet disconnect', [
                'user_id' => $userId, 'wallet' => $walletAddress, 'claims_deleted' => $deleted,
            ]);
        }
    }

    /**
     * Acquire a MySQL named lock to serialize bonus recomputation per page.
     *
     * Returns true if the lock was acquired, false on timeout. The caller
     * MUST call releaseBonusLock() when done (use try/finally).
     */
    private static function acquireBonusLock(int $pageId, int $timeoutSeconds = 5): bool
    {
        return AdvisoryLock::acquire('bcc_onchain_bonus_' . $pageId, $timeoutSeconds);
    }

    private static function releaseBonusLock(int $pageId): void
    {
        AdvisoryLock::release('bcc_onchain_bonus_' . $pageId);
    }

    /**
     * Recompute and apply the full on-chain bonus for a page.
     *
     * Serialized via MySQL named lock to prevent concurrent recomputations
     * from overwriting each other with stale snapshots.
     */
    public static function recomputeAndApply(int $pageId, int $userId): void
    {
        $locked = self::acquireBonusLock($pageId);
        if (!$locked) {
            BonusRetryService::queue($pageId, 0.0);
            return;
        }

        try {
            if (!self::carriesOnchainBonus($pageId, $userId)) {
                // A self-page whose owner runs an entity page: the bonus
                // lives there, so this page's share is 0 (no signal read).
                self::applyBonus($pageId, 0.0);
            } else {
                // F-101: a failed signal read must not be written as a
                // claims-only bonus; retry later, as on lock contention.
                try {
                    $signalRows = SignalRepository::get_for_page($pageId);
                } catch (\BCC\Trust\Onchain\Repositories\RepositoryReadFailure $e) {
                    BonusRetryService::queue($pageId, 0.0);
                    return;
                }
                $signalBonus = array_sum(array_column($signalRows, 'score_contribution'));

                $claimBonus = ClaimRepository::computePageClaimBonus($userId);

                $totalBonus = min($signalBonus + $claimBonus, BCC_ONCHAIN_MAX_TOTAL_BONUS);
                self::applyBonus($pageId, $totalBonus);
            }
        } finally {
            self::releaseBonusLock($pageId);
        }

        // Written to an entity page: the owner's self-page must not carry
        // the same bonus a second time.
        if (!\BCC\Trust\Core\Services\MemberSelfPageService::isSelfPage($pageId)) {
            self::settleSelfPageBonus($userId);
        }
    }

    /**
     * May $pageId carry its owner's on-chain bonus? User decision
     * 2026-11-18 (option a): the bonus is the PERSON's (contract §J.12) and
     * counts once. It lives on the owner's bonus page
     * (ScoreRepository::getPageIdForOwner: oldest owned entity page, else
     * the self-page), so a member self-page carries it only while its owner
     * has no entity page. Entity pages always may (the claim-time write
     * targets the claimed page before it is owned).
     *
     * Both bonus computations apply it — this recompute and
     * BonusRetryService::processAll — so a retry can never write the bonus
     * back onto a self-page it was cleared from.
     */
    public static function carriesOnchainBonus(int $pageId, int $ownerId): bool
    {
        if (!\BCC\Trust\Core\Services\MemberSelfPageService::isSelfPage($pageId)) {
            return true;
        }
        if ($ownerId <= 0) {
            return true;
        }
        $bonusPage = \BCC\Trust\Core\Plugin::instance()->scoreRepository()->getPageIdForOwner($ownerId);

        return $bonusPage <= 0 || \BCC\Trust\Core\Services\MemberSelfPageService::isSelfPage($bonusPage);
    }

    /**
     * After a bonus write to one of $userId's entity pages: recompute their
     * self-page under its own lock, which carriesOnchainBonus() turns into
     * 0 while they own an entity page. Skipped when the self-page has no
     * row or no bonus to clear (no row is ever created here).
     */
    public static function settleSelfPageBonus(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }
        $selfPageId = \BCC\Trust\Core\Services\MemberSelfPageService::selfPageId($userId);
        $row        = \BCC\Trust\Core\Plugin::instance()->scoreRepository()->getByPageId($selfPageId);
        if ($row === null || $row->getOnchainBonus() <= 0.0) {
            return;
        }

        self::recomputeAndApply($selfPageId, $userId);
    }

    /**
     * F-334 owner-0: a released page nobody owns carries no on-chain bonus.
     * An EXPLICIT 0, never a recompute through an owner lookup: two
     * process-static caches (PageOwnerResolver::$ownerCache and bcc-core's
     * PeepSo::$cache behind SignalRepository::get_for_page) can still name
     * the revoked owner for the rest of this request, and a recompute would
     * write their signals back. A busy lock queues a retry, which recomputes
     * from the page's owner in a later request: 0, so it also lands on 0.
     */
    public static function clearForUnownedPage(int $pageId): void
    {
        if ($pageId <= 0) {
            return;
        }
        if (!self::acquireBonusLock($pageId)) {
            BonusRetryService::queue($pageId, 0.0);
            return;
        }
        try {
            self::applyBonus($pageId, 0.0);
        } finally {
            self::releaseBonusLock($pageId);
        }
    }

    /**
     * Apply a trust bonus when an on-chain claim is verified.
     *
     * Recomputes the TOTAL bonus (signals + all verified claims) for the page,
     * then applies via applyBonus(). This avoids overwriting — applyBonus does
     * SET not ADD, so we always pass the full combined value.
     *
     * Serialized per-page via MySQL GET_LOCK to prevent concurrent claim
     * verifications from reading stale snapshots and overwriting each other.
     *
     * Hooked to: bcc_onchain_claim_verified
     */
    public static function applyClaimBonus(int $userId, string $entityType, int $entityId, string $role): void
    {
        $claimBonus = self::CLAIM_BONUS_MAP[$role] ?? 0.0;
        if ($claimBonus <= 0.0) {
            return;
        }

        $entityTable = ($entityType === 'validator')
            ? ValidatorRepository::table()
            : CollectionRepository::table();

        // Resolve page_id: entity → wallet_link → post_id.
        $pageId = \BCC\Trust\Onchain\Repositories\WalletRepository::getPostIdForEntity($entityTable, $entityId);

        // Bulk-indexed entities have wallet_link_id = NULL. A system-minted
        // placeholder page binds to its validator through the
        // `_bcc_onchain_validator_id` post_meta instead — the same second leg
        // ValidatorRepository::findAllByPageId() resolves, read the other way.
        //
        // ⚠ This leg is load-bearing, not belt-and-braces: it is the ONLY one
        // that resolves during a first claim on a placeholder page. The
        // getPageForOwner() fallback below cannot, because
        // `bcc_onchain_claim_verified` fires inside ClaimService::claim()
        // while `post_author` is only transferred later, on
        // `bcc_page_claimed` — so the page is not yet owned, and the scores
        // row it would otherwise be found by is the row we are creating.
        if (!$pageId && $entityType === 'validator') {
            $pageId = ValidatorRepository::findPlaceholderPageId($entityId);
        }

        if (!$pageId) {
            $pageId = \BCC\Core\ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($userId);
        }

        if (!$pageId) {
            return;
        }

        self::recomputeAndApply($pageId, $userId);
    }
}
