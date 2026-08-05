<?php
/**
 * AuthorBadgeResolver — batched composer for the rank-chip fields
 * (reputation_tier, reputation_tier_label, rank_label) on per-page
 * author surfaces.
 *
 * Used by:
 *   - FeedRankingService::hydrateAuthorRanks (feed + per-author wall)
 *   - CommentService::shapeCommentRow (comments drawer)
 *
 * §A2 / §J.6 contract — every field returned here is server-resolved.
 * The frontend's AuthorBadge MUST NOT manufacture a tier→label mapping;
 * this resolver IS that mapping.
 *
 * `reputation_tier` is REQUIRED on every author surface (contract v1.57).
 * It used to be optional, which is precisely why RankChip fell back to the
 * retired `card_tier` and, since risky had no card_tier, lost the ability to
 * render a risky author at all. Never make it optional again.
 *
 * Field semantics (canonical, shared across surfaces — do not
 * duplicate the mapping elsewhere):
 *   - reputation_tier + reputation_tier_label → ReputationTierMap::resolveReputation($tier)
 *   - rank_label                              → RankStateService::memberStatesFor
 *                                               (nullable — New Members carry no rank chip)
 *
 * **Rank comes from the earned rank_state row, not the tier** (Rank
 * redesign Phase 5 — the level-derived ladder is gone). Tier supplies
 * the trust chip; the member's rank_state row supplies the rank. Both
 * are batched.
 *
 * Bounded: one batched ReputationRepository::getTiersForUsers() call +
 * one batched RankStateService::memberStatesFor() call, regardless
 * of input size. Callers pre-cap inputs to their page size (feed: 50,
 * comment drawer: 50).
 *
 * @package BCC\Trust\Core\Services
 * @since v1.6 (2026-05, Sprint 1 Identity Grammar cohesion)
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Support\ReputationTierMap;
use BCC\Trust\Rank\Services\RankStateService;

if (!defined('ABSPATH')) {
    exit;
}

final class AuthorBadgeResolver
{
    public function __construct(
        private readonly ReputationRepository $reputationRepo,
        private readonly RankStateService $rankStateService
    ) {
    }

    /**
     * Resolve rank/tier chip fields for a list of user IDs.
     *
     * Users without a reputation row default to `neutral` tier
     * (mirrors `ReputationRepository::getTier()` single-user fallback)
     * → "Neutral" chip. Users without a rank_state row are New Members:
     * `rank_label` is "New Member" (the §D-3 status chip — the rank-chip
     * is never empty) and `member_state` is `new_member`, so the FE can
     * style the New Member chip distinctly from an earned rank.
     *
     * `user_id <= 0` entries are skipped — system actors / sentinel
     * rows don't carry a badge.
     *
     * @param list<int> $userIds
     * @return array<int, array{
     *   reputation_tier: string,
     *   reputation_tier_label: string,
     *   rank_label: string|null,
     *   member_state: string,
     * }>
     */
    public function resolveForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // Dedup positive-only.
        $clean = [];
        foreach ($userIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $clean[$intId] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $ids = array_keys($clean);

        $tierByUser  = $this->reputationRepo->getTiersForUsers($ids);
        $stateByUser = $this->rankStateService->memberStatesFor($ids);

        $out = [];
        foreach ($ids as $uid) {
            // Match ReputationRepository::getTier() single-user fallback.
            $tier  = $tierByUser[$uid] ?? 'neutral';
            $rep   = ReputationTierMap::resolveReputation($tier);

            $out[$uid] = [
                'reputation_tier'       => $rep['reputation_tier'],
                'reputation_tier_label' => $rep['reputation_tier_label'],
                // Rank-chip label: an earned rung's label, or "New Member"
                // for the pre-Apprentice status chip (§D-3) — never empty
                // for a valid user. `member_state` lets the FE style the
                // New Member chip distinctly from an earned rank.
                'rank_label'            => $stateByUser[$uid]['rank_label'] ?? null,
                'member_state'          => $stateByUser[$uid]['member_state'] ?? 'ranked',
            ];
        }
        return $out;
    }

    /**
     * Convenience: single-user resolve. Same shape as the batched
     * path. Used by single-row write responses (POST /comments
     * returns the new comment view-model with the badge fields
     * populated) where batching would be theatre.
     *
     * @return array{
     *   reputation_tier: string,
     *   reputation_tier_label: string,
     *   rank_label: string|null,
     *   member_state: string,
     * }
     */
    public function resolveForUser(int $userId): array
    {
        $map = $this->resolveForUsers([$userId]);
        return $map[$userId] ?? [
            // Falls through for $userId <= 0 — caller's view-model
            // omits the badge when handed an empty payload.
            'reputation_tier'       => 'neutral',
            'reputation_tier_label' => ReputationTierMap::toReputationTierLabel('neutral'),
            'rank_label'            => null,
            'member_state'          => 'ranked',
        ];
    }
}
