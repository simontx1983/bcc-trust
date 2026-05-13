<?php
/**
 * AuthorBadgeResolver — batched composer for the rank-chip fields
 * (reputation_tier, card_tier, tier_label, rank_label) on per-page
 * author surfaces.
 *
 * Used by:
 *   - FeedRankingService::hydrateAuthorRanks (feed + per-author wall)
 *   - CommentService::shapeCommentRow (comments drawer)
 *
 * §A2 / §J.6 contract — every field returned here is server-resolved.
 * The frontend's AuthorBadge MUST NOT manufacture a reputation→
 * card_tier mapping; this resolver IS that mapping.
 *
 * Same field semantics as UserViewService::getUser / getSummary —
 * canonical ReputationTierMap::resolve($tier) for card_tier+tier_label,
 * RankService::deriveRankFromTier($tier) for rank slug, then
 * RankCatalog::getLabel() for the rank_label. Single source of truth
 * shared across surfaces; do not duplicate the mapping logic elsewhere.
 *
 * Bounded: one batched ReputationRepository::getTiersForUsers() call,
 * regardless of input size. Callers pre-cap inputs to their page size
 * (feed: 50, comment drawer: 50).
 *
 * @package BCC\Trust\Core\Services
 * @since v1.6 (2026-05, Sprint 1 Identity Grammar cohesion)
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Support\RankCatalog;
use BCC\Trust\Core\Support\ReputationTierMap;

if (!defined('ABSPATH')) {
    exit;
}

final class AuthorBadgeResolver
{
    public function __construct(
        private readonly ReputationRepository $reputationRepo
    ) {
    }

    /**
     * Resolve rank/tier chip fields for a list of user IDs.
     *
     * Users without a reputation row default to `neutral` tier
     * (mirrors `ReputationRepository::getTier()` single-user
     * fallback), which maps to `uncommon` / "Uncommon" + Apprentice
     * rank. Missing-user fallback semantics are intentional: a
     * brand-new account looks like every other neutral account on
     * the feed rather than a special "unknown" badge.
     *
     * `user_id <= 0` entries are skipped — system actors / sentinel
     * rows don't carry a badge.
     *
     * @param list<int> $userIds
     * @return array<int, array{
     *   reputation_tier: string,
     *   card_tier: string|null,
     *   tier_label: string|null,
     *   rank_label: string,
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

        $tierByUser = $this->reputationRepo->getTiersForUsers($ids);

        $out = [];
        foreach ($ids as $uid) {
            // Match ReputationRepository::getTier() single-user fallback.
            $tier = $tierByUser[$uid] ?? 'neutral';
            $card = ReputationTierMap::resolve($tier);
            $rankKey = RankService::deriveRankFromTier($tier);
            $rankLabel = RankCatalog::getLabel($rankKey) ?? '';

            $out[$uid] = [
                'reputation_tier' => $tier,
                'card_tier'       => $card['key'],
                'tier_label'      => $card['label'],
                'rank_label'      => $rankLabel,
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
     *   card_tier: string|null,
     *   tier_label: string|null,
     *   rank_label: string,
     * }
     */
    public function resolveForUser(int $userId): array
    {
        $map = $this->resolveForUsers([$userId]);
        return $map[$userId] ?? [
            // Falls through for $userId <= 0 — caller's view-model
            // omits the badge when handed an empty payload.
            'reputation_tier' => 'neutral',
            'card_tier'       => ReputationTierMap::toCardTier('neutral'),
            'tier_label'      => ReputationTierMap::toCardTierLabel(
                ReputationTierMap::toCardTier('neutral')
            ),
            'rank_label'      => RankCatalog::getLabel(
                RankService::deriveRankFromTier('neutral')
            ) ?? '',
        ];
    }
}
