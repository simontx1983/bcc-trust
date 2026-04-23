<?php
/**
 * Trust Debug Service
 *
 * Aggregates all trust data for a page into a single debug payload.
 * Used exclusively by the admin debug panel — not exposed publicly.
 *
 * Design rules:
 *  - No live FraudDetector calls (expensive). Show stored fraud_score only.
 *  - Live fraud analysis triggered separately via runLiveFraudAnalysis().
 *  - Formula check uses the same constant as ScoreRepository (single source of truth).
 *  - Timeline built from actual data tables, not just audit logs.
 *
 * @package BCC\Trust\Core\Services\Admin
 */

namespace BCC\Trust\Core\Services\Admin;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\FraudDetector;
use BCC\Trust\Core\Services\PeepSoPageResolver;
use BCC\Trust\Core\ValueObjects\PageScore;

if (!defined('ABSPATH')) {
    exit;
}

class TrustDebugService
{
    /**
     * Get full debug data for a page. ~8 queries, no fraud analysis.
     *
     * @return array<string, mixed>|null
     */
    public static function getPageDebugData(int $pageId): ?array
    {
        $p = Plugin::instance();

        // ── Page info ───────────────────────────────────────────────────
        $post = get_post($pageId);
        if (!$post) {
            return null;
        }

        $ownerId   = (int) PeepSoPageResolver::getOwnerId($pageId);
        $ownerUser = $ownerId ? get_userdata($ownerId) : null;

        // ── Score ───────────────────────────────────────────────────────
        $score = $p->scoreRepository()->getByPageId($pageId);

        $scoreData = $score ? $score->toApiResponse() : [
            'total_score' => BCC_TRUST_NEUTRAL_SCORE, 'positive_score' => 0, 'negative_score' => 0,
            'endorsement_bonus' => 0, 'onchain_bonus' => 0, 'confidence_score' => 0,
            'reputation_tier' => 'neutral', 'vote_count' => 0, 'unique_voters' => 0,
            'endorsement_count' => 0,
        ];

        // Formula check — delegated to the canonical PageScore formula so
        // debug output cannot drift from production validation.
        $expectedTotal = PageScore::computeExpectedTotal(
            (float) $scoreData['positive_score'],
            (float) $scoreData['negative_score'],
            (float) $scoreData['endorsement_bonus'],
            (float) $scoreData['onchain_bonus']
        );
        $scoreData['formula_check']    = abs($scoreData['total_score'] - round($expectedTotal, 1)) <= PageScore::SCORE_TOLERANCE;
        $scoreData['formula_expected'] = round($expectedTotal, 1);

        // ── Fraud (stored only — no live analysis) ──────────────────────
        $userInfo = $ownerId ? $p->userInfoRepository()->getByUserId($ownerId) : null;
        $fraudData = [
            'fraud_score'     => $userInfo ? (int) $userInfo->fraud_score : 0,
            'risk_level'      => $userInfo ? FraudDetector::getRiskLevel((int) $userInfo->fraud_score) : 'unknown',
            'is_suspended'    => $userInfo ? (bool) $userInfo->is_suspended : false,
            'behavior_score'  => $userInfo ? (float) ($userInfo->behavior_score ?? 0) : 0,
            'trust_rank'      => $userInfo ? (float) ($userInfo->trust_rank ?? 0) : 0,
            'automation_score' => $userInfo ? (int) ($userInfo->automation_score ?? 0) : 0,
            'is_verified'     => $userInfo ? (bool) $userInfo->is_verified : false,
        ];

        // ── Votes (last 10 by default) ──────────────────────────────────
        $votes = self::getVotes($pageId, 'recent', 10);

        // ── Verifications ───────────────────────────────────────────────
        $verifications = self::getVerifications($ownerId);

        // ── Endorsements ────────────────────────────────────────────────
        $endorsements = self::getEndorsements($pageId);

        // ── On-chain summary ────────────────────────────────────────────
        $onchain = self::getOnchainSummary($pageId);

        // ── Timeline (real score-impact events) ─────────────────────────
        $timeline = self::getTimeline($pageId, $ownerId, 20);

        // ── Public flags (signal only — no score impact) ───────────────
        $flagCount   = \BCC\Trust\Core\Services\FlagService::getFlagCount($pageId);
        $recentFlags = \BCC\Trust\Core\Services\FlagService::getRecentFlags($pageId, 10);

        return [
            'page' => [
                'page_id'    => $pageId,
                'title'      => $post->post_title,
                'owner_id'   => $ownerId,
                'owner_name' => $ownerUser ? $ownerUser->display_name : 'Unknown',
                'post_type'  => $post->post_type,
                'status'     => $post->post_status,
                'created_at' => $post->post_date,
            ],
            'score'         => $scoreData,
            'fraud'         => $fraudData,
            'votes'         => $votes,
            'verifications' => $verifications,
            'endorsements'  => $endorsements,
            'onchain'       => $onchain,
            'flags'         => [
                'count'  => $flagCount,
                'recent' => $recentFlags,
            ],
            'timeline'      => $timeline,
        ];
    }

    /**
     * Run live fraud analysis on demand. Expensive — only on button click.
     *
     * @return array<string, mixed>
     */
    public static function runLiveFraudAnalysis(int $userId): array
    {
        FraudDetector::clearCache($userId);
        return FraudDetector::analyzeFraud($userId);
    }

    // ── Vote queries with filters ───────────────────────────────────────

    /**
     * @param string $filter  'recent' | 'top_weight' | 'suspicious' | 'all'
     * @return array{items: list<object>, total: int, filter: string, limit: int}
     */
    public static function getVotes(int $pageId, string $filter = 'recent', int $limit = 10, ?string $searchVoter = null): array
    {
        return Plugin::instance()->adminDashboardRepository()
            ->getDebugVotes($pageId, $filter, $limit, $searchVoter);
    }

    // ── Verification data ───────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function getVerifications(int $ownerId): array
    {
        if (!$ownerId) {
            return ['email' => false, 'github' => null, 'x' => null, 'wallets' => []];
        }

        $p    = Plugin::instance();
        $rows = $p->adminDashboardRepository()->getDebugVerifications($ownerId);

        $userInfo = $p->userInfoRepository()->getByUserId($ownerId);

        $result = [
            'email'   => $userInfo ? (bool) $userInfo->is_verified : false,
            'github'  => null,
            'x'       => null,
            'wallets' => [],
        ];

        foreach ($rows as $row) {
            $meta = json_decode($row->meta ?: '{}', true) ?: [];

            if ($row->type === 'github') {
                $result['github'] = [
                    'username'    => $row->provider_id,
                    'followers'   => $meta['followers'] ?? 0,
                    'repos'       => $meta['public_repos'] ?? 0,
                    'trust_boost' => (float) $row->trust_boost,
                    'verified_at' => $row->verified_at,
                ];
            } elseif ($row->type === 'x') {
                $result['x'] = [
                    'username'    => $row->provider_id,
                    'trust_boost' => (float) $row->trust_boost,
                    'verified_at' => $row->verified_at,
                ];
            } elseif (strpos($row->type, 'wallet_') === 0) {
                $result['wallets'][] = [
                    'chain'       => str_replace('wallet_', '', $row->type),
                    'address'     => $row->provider_id,
                    'trust_boost' => (float) $row->trust_boost,
                ];
            }
        }

        return $result;
    }

    // ── Endorsements ────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function getEndorsements(int $pageId): array
    {
        $rows = Plugin::instance()->adminDashboardRepository()
            ->getDebugEndorsements($pageId);

        $totalWeight = 0.0;
        foreach ($rows as $row) {
            $totalWeight += (float) $row->weight;
        }

        return [
            'count'        => count($rows),
            'total_weight' => round($totalWeight, 2),
            'items'        => $rows,
        ];
    }

    // ── On-chain summary ────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private static function getOnchainSummary(int $pageId): array
    {
        $onchain = \BCC\Core\ServiceLocator::resolveOnchainDataRead();
        $stats   = $onchain->getValidatorAggregateStats($pageId);

        return [
            'validator_count'  => $stats['active_count'],
            'chains_count'     => $stats['chains_count'],
            'total_stake'      => $stats['total_stake'],
            'collection_count' => 0, // populated below if available
        ];
    }

    // ── Timeline from real data tables ──────────────────────────────────

    /**
     * @return list<object>
     */
    private static function getTimeline(int $pageId, int $ownerId, int $limit): array
    {
        $repo = Plugin::instance()->adminDashboardRepository();

        $events = [];

        // Recent votes on this page
        $votes = $repo->getDebugVoteTimeline($pageId, $limit);
        foreach ($votes as $v) { $events[] = $v; }

        // Recent endorsements
        $endorsements = $repo->getDebugEndorsementTimeline($pageId, $limit);
        foreach ($endorsements as $e) { $events[] = $e; }

        // Sort combined by date, take top $limit
        usort($events, fn($a, $b) => strcmp($b->created_at, $a->created_at));
        return array_slice($events, 0, $limit);
    }
}
