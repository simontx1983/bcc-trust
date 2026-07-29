<?php
/**
 * Rank Service — composes the viewer-block of the /ranks view-model
 * (contract §4.8) and the canonical level→rank mapping.
 *
 * **Rank = the earned capability ladder**, one of two orthogonal
 * identity axes (Rank · Trust Tier; see docs/glossary.md §1). The
 * conferred Role axis was retired in contract v1.36. Rank mirrors the
 * feature-access **level** (§2.6), NOT reputation tier:
 *
 *   level New (1)     → 'apprentice'
 *   level Active (2)  → 'journeyman'
 *   level Veteran (3) → 'veteran'
 *
 * This replaces the V1 reputation-tier derivation (a *caution* user is
 * no longer silently "promoted" by trust score). Activity earns the
 * level; the level names the rank.
 *
 * The top rung's slug and label were `master` / "Master" until contract
 * v1.58. Level 3 tests 5 pulls + 3 votes + 30 days since registration —
 * tenure, not mastery — so the name now matches the threshold, and the
 * mapping is finally self-consistent (`veteran` → level Veteran).
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Support\RankCatalog;

if (!defined('ABSPATH')) {
    exit;
}

final class RankService
{
    /**
     * Feature-access level → earned rank slug. Single source of truth
     * for the mapping, shared by autoDerivedRank() and the static
     * rankForLevel() batched path.
     *
     * @var array<int, string>
     */
    public const LEVEL_TO_RANK = [
        FeatureAccessService::LEVEL_NEW     => RankCatalog::RANK_APPRENTICE,
        FeatureAccessService::LEVEL_ACTIVE  => RankCatalog::RANK_JOURNEYMAN,
        FeatureAccessService::LEVEL_VETERAN => RankCatalog::RANK_VETERAN,
    ];

    /**
     * Pure level → earned rank slug. Public + static so batched
     * view-model assemblers (AuthorBadgeResolver feed/comment author
     * chips) can map a pre-fetched level without re-instantiating
     * RankService or round-tripping per user. Unknown levels fall back
     * to Apprentice — the ladder's floor.
     */
    public static function rankForLevel(int $level): string
    {
        return self::LEVEL_TO_RANK[$level] ?? RankCatalog::RANK_APPRENTICE;
    }

    private FeatureAccessService $featureAccess;

    public function __construct(
        FeatureAccessService $featureAccess
    ) {
        $this->featureAccess = $featureAccess;
    }

    /**
     * The user's earned rank, derived from their feature-access level.
     * "Auto-derived" name kept for caller compatibility — in the level
     * model there is no admin-conferred *rank*, so the current rank is
     * always this value.
     */
    public function autoDerivedRank(int $userId): string
    {
        if ($userId <= 0) {
            return RankCatalog::RANK_APPRENTICE;
        }
        return self::rankForLevel($this->featureAccess->getLevel($userId));
    }

    /**
     * Render the viewer-block of the /ranks response per contract §4.8.
     * Returns null for anonymous viewers (user_id <= 0) so the frontend
     * can render conditional UI based on presence.
     *
     * Field semantics:
     *   - current_rank / auto_derived_rank — the level-derived earned
     *     rank (always equal; no rank demotion path in V1)
     *   - current_rank_label / next_rank / next_rank_label — pre-rendered
     *     §A2 progression labels (next_* null at Master, top of ladder)
     *
     * @return array{
     *   current_rank: string,
     *   current_rank_label: string,
     *   auto_derived_rank: string,
     *   next_rank: string|null,
     *   next_rank_label: string|null
     * }|null
     */
    public function getViewerBlock(int $userId): ?array
    {
        if ($userId <= 0) {
            return null;
        }

        $currentRank = $this->autoDerivedRank($userId);

        $nextRank      = RankCatalog::getNextRank($currentRank);
        $nextRankLabel = $nextRank !== null ? RankCatalog::getLabel($nextRank) : null;

        return [
            'current_rank'       => $currentRank,
            'current_rank_label' => RankCatalog::getLabel($currentRank) ?? '',
            'auto_derived_rank'  => $currentRank,
            'next_rank'          => $nextRank,
            'next_rank_label'    => $nextRankLabel,
        ];
    }
}
