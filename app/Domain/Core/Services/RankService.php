<?php
/**
 * Rank Service — composes the viewer-block of the /ranks view-model
 * (§E2, contract §4.8).
 *
 * Responsibilities:
 *   - Determine the user's CURRENT rank — either the active row in
 *     bcc_user_ranks, or the auto-derived rank if no row exists.
 *   - Compute the AUTO-DERIVED rank — what the user would be without
 *     any admin row. Per §E2, on revocation a user drops to this
 *     value, NOT to flat Apprentice.
 *
 * Phase 1 auto-derive rule (reputation-tier-only):
 *   - reputation tier ∈ {neutral, trusted, elite} → 'journeyman'
 *   - otherwise (caution, risky, unknown)         → 'apprentice'
 *
 * Activity-threshold-driven promotion (e.g., requires N reviews +
 * 30 days for journeyman) is deferred to a later sub-service. The
 * §E2 promotion path in V1 is reputation-tier-driven; activity feeds
 * reputation indirectly through the existing trust-score formula.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\UserRankRepository;
use BCC\Trust\Core\Support\RankCatalog;

if (!defined('ABSPATH')) {
    exit;
}

final class RankService
{
    /**
     * Reputation tiers that promote a user to Journeyman in the
     * auto-derived rank. Caution and risky stay at Apprentice.
     *
     * @var list<string>
     */
    private const PROMOTING_TIERS = ['neutral', 'trusted', 'elite'];

    private UserRankRepository $rankRepository;
    private ReputationRepository $reputationRepository;

    public function __construct(
        UserRankRepository $rankRepository,
        ReputationRepository $reputationRepository
    ) {
        $this->rankRepository       = $rankRepository;
        $this->reputationRepository = $reputationRepository;
    }

    /**
     * Compute the rank a user would have if no admin row existed in
     * bcc_user_ranks. Used as the default for new users AND as the
     * fallback after a Foreman+ rank is revoked (per §E2).
     */
    public function autoDerivedRank(int $userId): string
    {
        if ($userId <= 0) {
            return RankCatalog::RANK_APPRENTICE;
        }

        $tier = $this->reputationRepository->getTier($userId);
        if (in_array($tier, self::PROMOTING_TIERS, true)) {
            return RankCatalog::RANK_JOURNEYMAN;
        }
        return RankCatalog::RANK_APPRENTICE;
    }

    /**
     * Render the viewer-block of the /ranks response per contract §4.8.
     * Returns null for anonymous viewers (user_id <= 0) so the frontend
     * can render conditional UI based on presence.
     *
     * Field semantics:
     *   - current_rank        — what the user IS today (active row OR auto-derived)
     *   - auto_derived_rank   — what the user would be without admin action
     *   - is_admin_conferred  — true iff current rank is in the admin-only set
     *                           (Foreman+); used by the frontend to flag a rank
     *                           as revocable / signaling extra authority
     *
     * @return array{
     *   current_rank: string,
     *   auto_derived_rank: string,
     *   is_admin_conferred: bool
     * }|null
     */
    public function getViewerBlock(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $autoDerived = $this->autoDerivedRank($userId);
        $active      = $this->rankRepository->findActive($userId);

        if ($active === null) {
            return [
                'current_rank'       => $autoDerived,
                'auto_derived_rank'  => $autoDerived,
                'is_admin_conferred' => false,
            ];
        }

        return [
            'current_rank'       => $active->rank_key,
            'auto_derived_rank'  => $autoDerived,
            'is_admin_conferred' => !RankCatalog::isAutoAssigned($active->rank_key),
        ];
    }
}
