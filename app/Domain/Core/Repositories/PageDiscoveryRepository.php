<?php

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database queries for the page discovery feed.
 *
 * Extracted from PageDiscoveryService to enforce the repository-only
 * DB access guardrail. Contains the read-model query and legacy
 * fallback query paths.
 */
final class PageDiscoveryRepository
{
    /**
     * Query the denormalized read model table for discovery pages.
     *
     * @param string[] $types
     * @return array{rows: list<object>, total: int, pages: int, page: int}
     * @phpstan-return array{
     *   rows: list<object{
     *     ID: int|numeric-string,
     *     post_title: string,
     *     post_date: string,
     *     post_name: string,
     *     owner_id: int|numeric-string,
     *     trust_score: float|numeric-string,
     *     reputation_tier: string,
     *     confidence_score: float|numeric-string,
     *     onchain_bonus: float|numeric-string,
     *     endorsement_count: int|numeric-string,
     *     unique_voters: int|numeric-string,
     *     follower_count: int|numeric-string,
     *     page_type: string,
     *     is_verified: int|numeric-string,
     *     last_vote_at?: string|null,
     *     ranking_score?: float|numeric-string
     *   }>,
     *   total: int,
     *   pages: int,
     *   page: int
     * }
     */
    public static function queryFromReadModel(
        array $types,
        string $sort,
        bool $verified_only,
        string $tier,
        float $min_confidence,
        bool $new_only,
        int $max_votes,
        int $limit,
        int $page,
        string $search
    ): array {
        global $wpdb;

        $rm_table = TableRegistry::pageReadModel();

        $where  = ["p.post_status = 'publish'", "p.post_type = 'peepso-page'"];
        $params = [];

        if (!empty($types)) {
            $ph       = implode(',', array_fill(0, count($types), '%s'));
            $where[]  = "rm.page_type IN ({$ph})";
            $params   = array_merge($params, $types);
        }
        if ($verified_only) {
            $where[] = 'rm.is_verified = 1';
        }
        if ($tier !== '') {
            $where[]  = 'rm.reputation_tier = %s';
            $params[] = $tier;
        }
        if ($min_confidence > 0) {
            $where[]  = 'rm.confidence_score >= %f';
            $params[] = $min_confidence;
        }
        if ($new_only) {
            $where[] = 'p.post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
        }
        if ($max_votes > 0) {
            $where[]  = 'rm.unique_voters <= %d';
            $params[] = $max_votes;
        }
        if ($search !== '') {
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $where[]  = 'p.post_title LIKE %s';
            $params[] = $like;
        }

        $where_clause = implode(' AND ', $where);

        $ranking_expr = "(
            rm.trust_score
            * rm.confidence_score
            * GREATEST(0.5, 1.0 - (DATEDIFF(NOW(), COALESCE(rm.last_vote_at, '2020-01-01')) / 180.0))
            + rm.onchain_bonus * CASE rm.page_type
                WHEN 'validator' THEN 0.30 WHEN 'nft' THEN 0.10 ELSE 0.15 END
            + LOG2(1 + rm.endorsement_count) * CASE rm.page_type
                WHEN 'nft' THEN 4.0 WHEN 'validator' THEN 1.5 ELSE 2.5 END
            + (rm.unique_voters / GREATEST(1, rm.vote_count)) * 5.0
            + rm.is_verified * 3.0
        )";

        $orderby = match ($sort) {
            'endorsements' => 'rm.endorsement_count DESC',
            'newest'       => 'p.post_date DESC',
            'followers'    => 'rm.follower_count DESC',
            default        => $ranking_expr . ' DESC',
        };

        $count_sql = "SELECT COUNT(*)
            FROM {$wpdb->posts} p
            INNER JOIN {$rm_table} rm ON rm.page_id = p.ID
            WHERE {$where_clause}";

        if (!empty($params)) {
            $count_sql = $wpdb->prepare($count_sql, ...$params);
        }

        $total      = (int) $wpdb->get_var($count_sql);
        $totalPages = $total > 0 ? (int) ceil($total / $limit) : 0;

        if ($total === 0) {
            return ['rows' => [], 'total' => 0, 'pages' => 0, 'page' => $page];
        }

        $offset   = ($page - 1) * $limit;
        $ranking_select = ($sort === 'trust' || $sort === '')
            ? ", {$ranking_expr} AS ranking_score"
            : '';

        $main_sql = "SELECT p.ID, p.post_title, p.post_date, p.post_name,
                rm.owner_id, rm.trust_score, rm.reputation_tier,
                rm.confidence_score, rm.onchain_bonus,
                rm.endorsement_count, rm.unique_voters,
                rm.follower_count, rm.page_type, rm.is_verified
                {$ranking_select}
            FROM {$wpdb->posts} p
            INNER JOIN {$rm_table} rm ON rm.page_id = p.ID
            WHERE {$where_clause}
            ORDER BY {$orderby}
            LIMIT %d OFFSET %d";

        $all_params = array_merge($params, [$limit, $offset]);
        $rows       = $wpdb->get_results($wpdb->prepare($main_sql, ...$all_params));

        return [
            'rows'  => $rows,
            'total' => $total,
            'pages' => $totalPages,
            'page'  => $page,
        ];
    }

    /**
     * Fallback query against wp_posts + scores table (no read model).
     *
     * Projects the joined `bcc_trust_page_scores` columns under friendlier
     * aliases (`trust_score` for `total_score`, `endorsements` for
     * `endorsement_count`) so the consuming buildCard() method can use
     * UI-facing names directly. `follower_count` comes from a correlated
     * subquery against wp_usermeta.
     *
     * @param string[] $types
     * @return array{rows: list<object>, total: int, pages: int, page: int}
     * @phpstan-return array{
     *   rows: list<object{
     *     ID: int|numeric-string,
     *     post_title: string,
     *     post_author: int|numeric-string,
     *     post_date: string,
     *     post_name: string,
     *     trust_score: float|numeric-string,
     *     reputation_tier: string,
     *     endorsements: int|numeric-string,
     *     unique_voters: int|numeric-string,
     *     follower_count: int|numeric-string|null
     *   }>,
     *   total: int,
     *   pages: int,
     *   page: int
     * }
     */
    public static function queryFromPostsTable(
        array $types,
        string $sort,
        bool $verified_only,
        string $tier,
        float $min_confidence,
        bool $new_only,
        int $max_votes,
        int $limit,
        int $page,
        string $search
    ): array {
        global $wpdb;

        $scores_table    = TableRegistry::scores();
        $user_info_table = TableRegistry::userInfo();

        $where   = ["p.post_status = 'publish'", "p.post_type = 'peepso-page'"];
        $join    = '';
        $params  = [];

        if (!empty($types)) {
            $placeholders = implode(',', array_fill(0, count($types), '%s'));
            $where[]      = "pm_type.meta_value IN ($placeholders)";
            $join        .= " LEFT JOIN {$wpdb->postmeta} pm_type ON p.ID = pm_type.post_id AND pm_type.meta_key = '_bcc_page_type'";
            $params       = array_merge($params, $types);
        }
        if ($verified_only) {
            $join   .= " INNER JOIN {$user_info_table} ui ON ui.user_id = COALESCE(pm_owner.meta_value, p.post_author) AND ui.is_verified = 1";
            $join   .= " LEFT JOIN {$wpdb->postmeta} pm_owner ON p.ID = pm_owner.post_id AND pm_owner.meta_key = '_bcc_page_owner'";
        }
        if ($search !== '') {
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $where[]  = 'p.post_title LIKE %s';
            $params[] = $like;
        }

        $join .= " LEFT JOIN {$scores_table} ps ON ps.page_id = p.ID AND ps.category_id = 0";

        if ($tier !== '') {
            $where[]  = 'ps.reputation_tier = %s';
            $params[] = $tier;
        }
        if ($min_confidence > 0) {
            $where[]  = 'ps.confidence_score >= %f';
            $params[] = $min_confidence;
        }
        if ($new_only) {
            $where[] = 'p.post_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)';
        }
        if ($max_votes > 0) {
            $where[]  = 'COALESCE(ps.unique_voters, 0) <= %d';
            $params[] = $max_votes;
        }

        $fallback_ranking = "(
            COALESCE(ps.total_score, 50)
            * COALESCE(ps.confidence_score, 0)
            * GREATEST(0.5, 1.0 - (DATEDIFF(NOW(), COALESCE(ps.last_vote_at, '2020-01-01')) / 180.0))
            + COALESCE(ps.onchain_bonus, 0) * 0.15
            + LOG2(1 + COALESCE(ps.endorsement_count, 0)) * 2.5
            + (COALESCE(ps.unique_voters, 0) / GREATEST(1, COALESCE(ps.vote_count, 1))) * 5.0
        )";

        $orderby = match ($sort) {
            'endorsements' => 'COALESCE(ps.endorsement_count, 0) DESC',
            'newest'       => 'p.post_date DESC',
            'followers'    => 'follower_count DESC',
            default        => $fallback_ranking . ' DESC',
        };

        $follower_select = "(SELECT CAST(um.meta_value AS UNSIGNED)
            FROM {$wpdb->usermeta} um
            WHERE um.user_id = COALESCE(p.post_author)
            AND um.meta_key = 'peepso_followers_count'
            LIMIT 1) AS follower_count";

        $where_clause = implode(' AND ', $where);

        $count_sql = "SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            {$join}
            WHERE {$where_clause}";

        if (!empty($params)) {
            $count_sql = $wpdb->prepare($count_sql, ...$params);
        }

        $total = (int) $wpdb->get_var($count_sql);
        $pages = $total > 0 ? (int) ceil($total / $limit) : 0;

        if ($total === 0) {
            return ['rows' => [], 'total' => 0, 'pages' => 0, 'page' => $page];
        }

        $offset  = ($page - 1) * $limit;
        $main_sql = "SELECT DISTINCT p.ID, p.post_title, p.post_date, p.post_author, p.post_name,
                COALESCE(ps.total_score, 50) AS trust_score,
                COALESCE(ps.reputation_tier, 'neutral') AS reputation_tier,
                COALESCE(ps.endorsement_count, 0) AS endorsements,
                COALESCE(ps.unique_voters, 0) AS unique_voters,
                {$follower_select}
            FROM {$wpdb->posts} p
            {$join}
            WHERE {$where_clause}
            ORDER BY {$orderby}
            LIMIT %d OFFSET %d";

        $all_params = array_merge($params, [$limit, $offset]);
        $rows       = $wpdb->get_results($wpdb->prepare($main_sql, ...$all_params));

        return [
            'rows'  => $rows,
            'total' => $total,
            'pages' => $pages,
            'page'  => $page,
        ];
    }

    /**
     * Batch-load primary claims (operator/creator) for a set of page IDs.
     *
     * @param int[] $pageIds
     * @return array<int, string> page_id => claimer display_name
     */
    public static function batchLoadClaims(array $pageIds): array
    {
        if (empty($pageIds)
            || !class_exists('\\BCC\\Onchain\\Repositories\\ClaimRepository')
            || !function_exists('bcc_onchain_claims_table')
        ) {
            return [];
        }

        global $wpdb;
        $suppress = $wpdb->suppress_errors(true);
        $result   = \BCC\Onchain\Repositories\ClaimRepository::getPrimaryClaimsByPageIds($pageIds);
        $wpdb->suppress_errors($suppress);

        return $wpdb->last_error ? [] : $result;
    }
}
