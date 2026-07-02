<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Application;

use BCC\Core\Contracts\ScoreReadServiceInterface;
use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Read-only score service for cross-plugin consumers.
 *
 * Uses a single batched query (WHERE page_id IN (...)) so callers
 * never trigger N+1 lookups against the scores table.
 *
 * The loose read-model row shape enrichRow() consumes. Scalars arrive from
 * $wpdb as numeric-string|string|null; the method casts every field, so the
 * shape is deliberately permissive (mirrors PageReadModelRepository's row).
 *
 * @phpstan-type PageReadModelRowLike object{
 *   trust_score: float|int|numeric-string,
 *   confidence_score: float|int|numeric-string,
 *   onchain_bonus: float|int|numeric-string,
 *   endorsement_count: int|numeric-string,
 *   unique_voters: int|numeric-string,
 *   vote_count?: int|numeric-string|null,
 *   is_verified: int|numeric-string|bool,
 *   has_verified_claim?: int|numeric-string|bool|null,
 *   page_type?: string|null,
 *   last_vote_at?: string|null,
 *   reputation_tier?: string|null,
 *   follower_count: int|numeric-string
 * }
 */
final class ScoreReadService implements ScoreReadServiceInterface
{
    private const CACHE_GROUP    = 'bcc_trust_scores';
    private const CACHE_TTL      = 60;  // seconds — same staleness as vote stats cache
    private const CACHE_MAX_IDS  = 100; // skip cache for batches larger than this
    private const GEN_KEY        = 'batch_scores_gen';

    /**
     * @param int[] $pageIds
     * @return array<int, array{total_score: float, reputation_tier: string}>
     */
    public function getScoresForPageIds(array $pageIds): array
    {
        $pageIds = array_values(array_unique(array_filter(array_map('intval', $pageIds))));

        if (empty($pageIds)) {
            return [];
        }

        // Only cache small, frequently-repeated sets (search top-12, trending).
        $cacheable = count($pageIds) <= self::CACHE_MAX_IDS;

        if ($cacheable) {
            // Include a generation counter in the key so score mutations
            // automatically invalidate all batch cache entries.
            $gen = (int) wp_cache_get(self::GEN_KEY, self::CACHE_GROUP);
            $sorted = $pageIds;
            sort($sorted);
            $cacheKey = 'batch_scores_' . $gen . '_' . md5(implode(',', $sorted));
            $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);

            if (is_array($cached)) {
                return $cached;
            }
        }

        // Prefer the read model (bcc_page_read_model) for consistency:
        // all blocks and endpoints read trust_score + tier from this table.
        // Falls back to bcc_trust_scores only when read model is empty.
        $result = $this->getFromReadModel($pageIds);

        if (empty($result)) {
            // Fallback for sites where the read model hasn't been synced yet.
            $result = \BCC\Trust\Core\Plugin::instance()->scoreRepository()->getBatchScoreData($pageIds);
        }

        if ($cacheable) {
            wp_cache_set($cacheKey, $result, self::CACHE_GROUP, self::CACHE_TTL);
        }

        return $result;
    }

    /**
     * Read trust_score + reputation_tier from the read model (single source of truth).
     *
     * @param int[] $pageIds
     * @return array<int, array{total_score: float, reputation_tier: string}>
     */
    private function getFromReadModel(array $pageIds): array
    {
        $rmRepo = \BCC\Trust\Core\Plugin::instance()->pageReadModelRepository();

        if (!$rmRepo->hasData()) {
            return [];
        }

        $rows = $rmRepo->getByPageIds($pageIds);

        $result = [];
        foreach ($rows as $pageId => $row) {
            $result[$pageId] = [
                'total_score'     => (float) $row->trust_score,
                'reputation_tier' => $row->reputation_tier ?? 'neutral',
            ];
        }

        return $result;
    }

    /**
     * @param int[] $pageIds
     * @return array<int, array{total_score: float, reputation_tier: string, ranking_score: float, endorsement_count: int, is_verified: bool, is_claim_verified: bool, follower_count: int}>
     */
    public function getEnrichedScoresForPageIds(array $pageIds): array
    {
        $pageIds = array_values(array_unique(array_filter(array_map('intval', $pageIds))));

        if (empty($pageIds)) {
            return [];
        }

        $cacheable = count($pageIds) <= self::CACHE_MAX_IDS;

        if ($cacheable) {
            $gen = (int) wp_cache_get(self::GEN_KEY, self::CACHE_GROUP);
            $sorted = $pageIds;
            sort($sorted);
            $cacheKey = 'batch_enriched_' . $gen . '_' . md5(implode(',', $sorted));
            $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $result = $this->getEnrichedFromReadModel($pageIds);

        if ($cacheable) {
            wp_cache_set($cacheKey, $result, self::CACHE_GROUP, self::CACHE_TTL);
        }

        return $result;
    }

    /**
     * Read enriched data from the read model, computing the same composite
     * ranking score `PageDiscoveryService` uses so /cards/list (and the
     * bcc-search relevance ranking) sort consistently with the read model.
     *
     * @param int[] $pageIds
     * @return array<int, array{total_score: float, reputation_tier: string, ranking_score: float, endorsement_count: int, is_verified: bool, is_claim_verified: bool, follower_count: int}>
     */
    private function getEnrichedFromReadModel(array $pageIds): array
    {
        $rmRepo = \BCC\Trust\Core\Plugin::instance()->pageReadModelRepository();

        if (!$rmRepo->hasData()) {
            // Fall back to basic scores with default enrichment values.
            $basic = \BCC\Trust\Core\Plugin::instance()->scoreRepository()->getBatchScoreData($pageIds);
            $result = [];
            foreach ($basic as $pid => $data) {
                $ts = (float) ($data['total_score'] ?? 50);
                $result[$pid] = [
                    'total_score'       => $ts,
                    'reputation_tier'   => $data['reputation_tier'] ?? 'neutral',
                    'ranking_score'     => $ts * 0.5,
                    'endorsement_count' => 0,
                    'is_verified'       => false,
                    'is_claim_verified' => false,
                    'follower_count'    => 0,
                ];
            }
            return $result;
        }

        $rows = $rmRepo->getByPageIds($pageIds);

        $result = [];
        foreach ($rows as $pageId => $row) {
            $result[$pageId] = self::enrichRow($row);
        }

        return $result;
    }

    /**
     * Shape a single read-model row into the enriched score entry — pure
     * so the composite ranking + the is_claim_verified threading are both
     * unit-testable from a plain row object (no read-model repo / singleton).
     *
     * @phpstan-param PageReadModelRowLike $row
     * @return array{total_score: float, reputation_tier: string, ranking_score: float, endorsement_count: int, is_verified: bool, is_claim_verified: bool, follower_count: int}
     */
    public static function enrichRow(object $row): array
    {
        $trustScore       = (float) $row->trust_score;
        $confidence       = (float) $row->confidence_score;
        $onchainBonus     = (float) $row->onchain_bonus;
        $endorsementCount = (int) $row->endorsement_count;
        $uniqueVoters     = (int) $row->unique_voters;
        $voteCount        = max(1, (int) ($row->vote_count ?? 1));
        $isVerified       = (bool) $row->is_verified;
        $hasVerifiedClaim = (bool) ($row->has_verified_claim ?? 0);
        $pageType         = $row->page_type ?? 'builder';
        $lastVoteAt       = $row->last_vote_at ?? '2020-01-01';

        $rankingScore = self::computeRankingScore(
            $trustScore,
            $confidence,
            $onchainBonus,
            $endorsementCount,
            $uniqueVoters,
            $voteCount,
            $isVerified,
            $hasVerifiedClaim,
            (string) $pageType,
            is_string($lastVoteAt) ? $lastVoteAt : '2020-01-01'
        );

        return [
            'total_score'       => $trustScore,
            'reputation_tier'   => is_string($row->reputation_tier ?? null) ? $row->reputation_tier : 'neutral',
            'ranking_score'     => round($rankingScore, 4),
            'endorsement_count' => $endorsementCount,
            'is_verified'       => $isVerified,
            'is_claim_verified' => $hasVerifiedClaim,
            'follower_count'    => (int) $row->follower_count,
        ];
    }

    /**
     * Composite discovery/search ranking score — pure compute so the
     * formula is unit-testable and the claim-verified bonus is pinned.
     *
     * Mirrors PageDiscoveryRepository::queryFromReadModel() with the added
     * has_verified_claim term. The claim-verified bonus is filterable via
     * `bcc_rank_claim_verified_bonus` (default BCC_RANK_CLAIM_VERIFIED_BONUS,
     * dominant over the +3.0 email-verified term).
     */
    public static function computeRankingScore(
        float $trustScore,
        float $confidence,
        float $onchainBonus,
        int $endorsementCount,
        int $uniqueVoters,
        int $voteCount,
        bool $isVerified,
        bool $hasVerifiedClaim,
        string $pageType,
        string $lastVoteAt
    ): float {
        $voteCount = max(1, $voteCount);

        // Freshness decay: 1.0 → 0.5 over 180 days since last vote.
        $daysSinceVote = (time() - strtotime($lastVoteAt !== '' ? $lastVoteAt : '2020-01-01')) / 86400;
        $freshness     = max(0.5, 1.0 - ($daysSinceVote / 180.0));

        // On-chain bonus weight by page type.
        $onchainWeight = match ($pageType) {
            'validator' => 0.30,
            'nft'       => 0.10,
            default     => 0.15,
        };

        // Endorsement logarithmic boost by page type.
        $endorseWeight = match ($pageType) {
            'nft'       => 4.0,
            'validator' => 1.5,
            default     => 2.5,
        };

        $claimVerifiedBonus = (float) apply_filters(
            'bcc_rank_claim_verified_bonus',
            BCC_RANK_CLAIM_VERIFIED_BONUS
        );

        return $trustScore * $confidence * $freshness
            + $onchainBonus * $onchainWeight
            + log(1 + $endorsementCount, 2) * $endorseWeight
            + ($uniqueVoters / $voteCount) * 5.0
            + ($isVerified ? 3.0 : 0.0)
            + ($hasVerifiedClaim ? $claimVerifiedBonus : 0.0);
    }

    /**
     * Bump the generation counter to invalidate all batch score caches.
     * Called from CacheManager::invalidatePageCaches() on every score mutation.
     */
    public static function bustBatchCache(): void
    {
        $newGen = wp_cache_incr(self::GEN_KEY, 1, self::CACHE_GROUP);
        if ($newGen === false) {
            wp_cache_set(self::GEN_KEY, 1, self::CACHE_GROUP, 3600);
        }
    }
}
