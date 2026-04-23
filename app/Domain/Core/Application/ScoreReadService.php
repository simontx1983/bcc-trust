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
     * @return array<int, array{total_score: float, reputation_tier: string, ranking_score: float, endorsement_count: int, is_verified: bool, follower_count: int}>
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
     * ranking score used by GET /bcc/v1/discover for consistent ordering.
     *
     * @param int[] $pageIds
     * @return array<int, array{total_score: float, reputation_tier: string, ranking_score: float, endorsement_count: int, is_verified: bool, follower_count: int}>
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
                    'follower_count'    => 0,
                ];
            }
            return $result;
        }

        $rows = $rmRepo->getByPageIds($pageIds);

        $result = [];
        foreach ($rows as $pageId => $row) {
            $trustScore      = (float) $row->trust_score;
            $confidence      = (float) $row->confidence_score;
            $onchainBonus    = (float) $row->onchain_bonus;
            $endorsementCount = (int) $row->endorsement_count;
            $uniqueVoters    = (int) $row->unique_voters;
            $voteCount       = max(1, (int) ($row->vote_count ?? 1));
            $isVerified      = (bool) $row->is_verified;
            $pageType        = $row->page_type ?? 'builder';
            $lastVoteAt      = $row->last_vote_at ?? '2020-01-01';

            // Freshness decay: 1.0 → 0.5 over 180 days since last vote.
            $daysSinceVote = (time() - strtotime($lastVoteAt ?: '2020-01-01')) / 86400;
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

            // Composite ranking — identical to PageDiscoveryRepository::queryFromReadModel().
            $rankingScore = $trustScore * $confidence * $freshness
                + $onchainBonus * $onchainWeight
                + log(1 + $endorsementCount, 2) * $endorseWeight
                + ($uniqueVoters / $voteCount) * 5.0
                + ($isVerified ? 3.0 : 0.0);

            $result[$pageId] = [
                'total_score'       => $trustScore,
                'reputation_tier'   => $row->reputation_tier ?? 'neutral',
                'ranking_score'     => round($rankingScore, 4),
                'endorsement_count' => $endorsementCount,
                'is_verified'       => $isVerified,
                'follower_count'    => (int) $row->follower_count,
            ];
        }

        return $result;
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
