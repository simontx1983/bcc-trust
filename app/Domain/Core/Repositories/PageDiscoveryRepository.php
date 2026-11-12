<?php

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Services\UserViewService;

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
        string $search,
        bool $good_standing_only = false,
        string $chain_slug = '',
        string $status = '',
        ?float $min_self_stake = null
    ): array {
        global $wpdb;

        $rm_table        = TableRegistry::pageReadModel();
        $validators_table = \BCC\Core\DB\DB::table('onchain_validators');
        $chains_table     = \BCC\Core\DB\DB::table('chains');

        $where  = ["p.post_status = 'publish'", "p.post_type = 'peepso-page'"];
        $params = [];

        // Validator on-chain JOIN — shared by the chain filter and the
        // validator-only axes (status / min self-stake / self-stake sort).
        // Joins through `_bcc_onchain_validator_id` post_meta (written by
        // ValidatorPageMinter and preserved across claim) →
        // bcc_onchain_validators; the bcc_onchain_chains slug JOIN is
        // appended only when a chain slug is supplied. INNER JOINs because
        // these axes only apply to rows backed by the indexer; any
        // pre-backfill validator page without the meta key is intentionally
        // excluded once a validator axis is in play. The post_meta value is
        // single-valued, so the join yields one validator row per page — no
        // fan-out, no GROUP BY needed.
        $needs_validator_join = $chain_slug !== ''
            || $status !== ''
            || $min_self_stake !== null
            || $sort === 'self_stake';

        $chain_joins = '';
        if ($needs_validator_join) {
            $chain_joins = "
                INNER JOIN {$wpdb->postmeta} pm_validator
                        ON pm_validator.post_id   = p.ID
                       AND pm_validator.meta_key  = '_bcc_onchain_validator_id'
                INNER JOIN {$validators_table} v_chain
                        ON v_chain.id = CAST(pm_validator.meta_value AS UNSIGNED)
            ";
            if ($chain_slug !== '') {
                $chain_joins .= "
                INNER JOIN {$chains_table} c_chain
                        ON c_chain.id   = v_chain.chain_id
                       AND c_chain.slug = %s
                ";
                $params[] = $chain_slug;
            }
        }

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
        if ($good_standing_only) {
            // §G2 — sources the tier list from UserViewService so the
            // chip on /directory, the per-row `is_in_good_standing`
            // stamp, and this WHERE clause can never drift.
            $ph       = implode(',', array_fill(0, count(UserViewService::GOOD_STANDING_TIERS), '%s'));
            $where[]  = "rm.reputation_tier IN ({$ph})";
            $params   = array_merge($params, UserViewService::GOOD_STANDING_TIERS);
        }
        if ($status !== '') {
            // Validator-only — references the v_chain alias guaranteed
            // present by $needs_validator_join above.
            $where[]  = 'v_chain.status = %s';
            $params[] = $status;
        }
        if ($min_self_stake !== null) {
            $where[]  = 'v_chain.self_stake >= %f';
            $params[] = $min_self_stake;
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
            // Validator-only — v_chain present via $needs_validator_join.
            // MySQL sorts NULL last under DESC, so validators with no stake
            // reading fall to the bottom.
            'self_stake'   => 'v_chain.self_stake DESC',
            default        => $ranking_expr . ' DESC',
        };

        $count_sql = "SELECT COUNT(*)
            FROM {$wpdb->posts} p
            INNER JOIN {$rm_table} rm ON rm.page_id = p.ID
            {$chain_joins}
            WHERE {$where_clause}";

        if (!empty($params)) {
            $count_sql = $wpdb->prepare($count_sql, ...$params);
        }

        self::clearLastError();
        $total = (int) $wpdb->get_var($count_sql);

        // ⚠⚠ F-197: THE SAME BLIND SPOT AS THE FALLBACK, ON THE PATH THAT
        // ACTUALLY RUNS. `get_var()` answers null on failure, `(int) null` is
        // 0, and the `$total === 0` branch below returns an empty page — so
        // an unreadable read model reported "no page matches this filter"
        // rather than failing. Identical to F-144, one method down.
        //
        // ⚠ This is the PRIMARY discovery path: `PageDiscoveryService` picks
        // it whenever `PageReadModelRepository::hasData()` is true, which is
        // the normal state. The posts-table method below is the legacy
        // fallback.
        //
        // ⚠ Logs and still returns empty, deliberately: "empty on an
        // unreadable table" is the RATIFIED behaviour for discovery, and
        // changing it is a route-contract decision, not a repository one.
        // What changes is that the failure is no longer silent.
        if (self::readFailed()) {
            self::logReadFailure('read-model count', $verified_only);

            return ['rows' => [], 'total' => 0, 'pages' => 0, 'page' => $page];
        }

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
            {$chain_joins}
            WHERE {$where_clause}
            ORDER BY {$orderby}, p.ID DESC
            LIMIT %d OFFSET %d";
        // F-143: `p.ID DESC` is the unique tiebreak. Every sort key above
        // (counts, post_date, stake, the ranking expression) can tie, and
        // MySQL returns tied rows in no fixed order, so LIMIT/OFFSET pages
        // skipped or repeated pages that tied across a page boundary.

        $all_params = array_merge($params, [$limit, $offset]);

        self::clearLastError();
        $rows = $wpdb->get_results($wpdb->prepare($main_sql, ...$all_params));

        // ⚠ The COUNT can succeed while this fails — they are two
        // statements, and only this one carries the ORDER BY, the LIMIT and
        // the ranking expression. That combination is exactly how F-144's
        // second defect hid: an honest non-zero total beside an empty row
        // set. Checked separately for that reason, not as a formality.
        if (self::readFailed()) {
            self::logReadFailure('read-model rows', $verified_only);
            $rows = [];
        }

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
        string $search,
        bool $good_standing_only = false
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
            // ⚠⚠ F-144: `pm_owner` IS JOINED FIRST, AND THE ORDER IS THE BUG.
            // The INNER JOIN below reads `pm_owner.meta_value` in its ON
            // clause, and SQL only lets a join reference aliases introduced
            // BEFORE it. With these two the other way round MySQL raised
            // "Unknown column 'pm_owner.meta_value' in 'on clause'" and the
            // whole query failed.
            //
            // ⚠ Which was INVISIBLE, because the failure arrived as emptiness:
            // the COUNT below is read with `get_var()`, a failed read gives
            // null, `(int) null` is 0, and the `$total === 0` branch returns
            // an empty page. So "verified only" discovery answered "there are
            // no verified pages" on every request that reached this fallback,
            // with nothing logged and nothing thrown.
            $join   .= " LEFT JOIN {$wpdb->postmeta} pm_owner ON p.ID = pm_owner.post_id AND pm_owner.meta_key = '_bcc_page_owner'";
            $join   .= " INNER JOIN {$user_info_table} ui ON ui.user_id = COALESCE(pm_owner.meta_value, p.post_author) AND ui.is_verified = 1";
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
        if ($good_standing_only) {
            // §G2 — same canonical tier list as the read-model path.
            $ph       = implode(',', array_fill(0, count(UserViewService::GOOD_STANDING_TIERS), '%s'));
            $where[]  = "ps.reputation_tier IN ({$ph})";
            $params   = array_merge($params, UserViewService::GOOD_STANDING_TIERS);
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

        // ⚠⚠ THE RANKING EXPRESSION HAS TO BE SELECTED, NOT MERELY ORDERED
        // BY. The main statement below is a `SELECT DISTINCT`, and MySQL 8
        // REFUSES to order a DISTINCT result by an expression over columns
        // the statement does not carry:
        //
        //   Expression #1 of ORDER BY clause is not in SELECT list,
        //   references column 'ps.total_score' which is not in SELECT list;
        //   this is incompatible with DISTINCT
        //
        // The DEFAULT sort was exactly such an expression, so the main read
        // failed for every caller that did not ask for another sort — and it
        // failed INVISIBLY, because an empty row set is indistinguishable
        // from "no page matched". F-144's join fix alone still returned
        // nothing on MySQL 8 for this reason. MariaDB accepts the statement,
        // which is why only one of CI's two engines ever objected.
        //
        // ⭐ Every branch now names something the statement actually selects,
        // which removes the class rather than this instance: a sort added
        // later cannot reintroduce the fault without first adding a column.
        // The pair is produced by ONE match so the ORDER BY and the SELECT
        // cannot drift apart.
        [$orderby, $ranking_select] = match ($sort) {
            'endorsements' => ['endorsements DESC', ''],
            'newest'       => ['p.post_date DESC', ''],
            'followers'    => ['follower_count DESC', ''],
            default        => ['ranking_score DESC', ", {$fallback_ranking} AS ranking_score"],
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

        self::clearLastError();
        $total = (int) $wpdb->get_var($count_sql);

        // ⚠⚠ A FAILED READ IS NOT AN EMPTY RESULT, and telling them apart is
        // the other half of F-144. `get_var()` answers null on failure and
        // `(int) null` is 0, which is indistinguishable from "nothing
        // matched" — that is how a broken join went unnoticed.
        //
        // ⚠ This logs and still returns empty, which is deliberately NOT a
        // behaviour change: whether a discovery list should answer
        // "unavailable" rather than "empty" when the database is unreadable
        // is a contract decision for the route, not something to slip into a
        // repository fix. What changes here is that the next occurrence is
        // VISIBLE instead of silent.
        if (self::readFailed()) {
            self::logReadFailure('discovery count', $verified_only);

            return ['rows' => [], 'total' => 0, 'pages' => 0, 'page' => $page];
        }

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
                {$ranking_select}
            FROM {$wpdb->posts} p
            {$join}
            WHERE {$where_clause}
            ORDER BY {$orderby}, p.ID DESC
            LIMIT %d OFFSET %d";
        // F-143: unique `p.ID DESC` tiebreak — same reason as queryFromReadModel().

        $all_params = array_merge($params, [$limit, $offset]);

        self::clearLastError();
        $rows = $wpdb->get_results($wpdb->prepare($main_sql, ...$all_params));

        // ⚠ Same reasoning as the COUNT above. `get_results()` answers null
        // on failure in some wpdb paths and `[]` in others, and neither is
        // distinguishable from "no rows" to a caller — so the failure is
        // logged rather than inferred from the shape of the result.
        if (self::readFailed()) {
            self::logReadFailure('discovery rows', $verified_only);
            $rows = [];
        }

        return [
            'rows'  => $rows,
            'total' => $total,
            'pages' => $pages,
            'page'  => $page,
        ];
    }

    /**
     * Clear `wpdb::$last_error` so the next read's own failure is what is
     * observed.
     *
     * ⚠ A STALE ERROR FROM AN EARLIER QUERY WOULD OTHERWISE READ AS THIS
     * ONE'S. `last_error` persists until something overwrites it, so a check
     * without a preceding clear reports whatever failed most recently
     * anywhere in the request.
     */
    private static function clearLastError(): void
    {
        global $wpdb;

        $wpdb->last_error = '';
    }

    /**
     * Did the read just performed fail?
     *
     * ⚠ FACTORED OUT RATHER THAN INLINED. Written inline as
     * `$wpdb->last_error = ''; ... if ($wpdb->last_error !== '')`, PHPStan
     * narrows the property to the empty string at the assignment and reports
     * the comparison as always-false — so the check reads as dead code and
     * invites deletion. Through a method call the narrowing does not carry.
     */
    private static function readFailed(): bool
    {
        global $wpdb;

        return (string) $wpdb->last_error !== '';
    }

    /**
     * Record a failed discovery read.
     *
     * ⚠⚠ THE STATEMENT IS DELIBERATELY NOT LOGGED. By the time either read
     * fails, `$count_sql` / `$main_sql` have been through
     * `wpdb::prepare()`, which SUBSTITUTES the values rather than leaving
     * placeholders — so the string carries the caller's `$search` term
     * verbatim, which is free-text member input. `Logger` scrubs context
     * values by KEY (`token`, `secret`, `wallet`, `address`, …); a key
     * named `sql` matches none of them, so the term would reach the log
     * file intact. (An earlier revision of this method logged it, and the
     * docblock justifying that was simply wrong about `prepare()`.)
     *
     * ⭐ `$what` and `$verifiedOnly` identify the query without carrying any
     * of its values. This method assembles its joins from exactly those two
     * facts, and `$verified_only` is the branch F-144 broke — so the pair is
     * the whole diagnostic, and the one that matters is a bool.
     */
    private static function logReadFailure(string $what, bool $verifiedOnly): void
    {
        global $wpdb;

        if (!class_exists('\\BCC\\Core\\Log\\Logger')) {
            return;
        }

        \BCC\Core\Log\Logger::error('[bcc-trust] page-discovery read failed', [
            'what'          => $what,
            'error'         => (string) $wpdb->last_error,
            'verified_only' => $verifiedOnly,
        ]);
    }

    /**
     * Batch-load primary claims (operator/creator) for a set of page IDs.
     *
     * @param int[] $pageIds
     * @return array<int, string> page_id => claimer display_name
     */
    public static function batchLoadClaims(array $pageIds): array
    {
        if (empty($pageIds)) {
            return [];
        }

        return \BCC\Trust\Onchain\Repositories\ClaimRepository::getPrimaryClaimsByPageIds($pageIds);
    }

    /**
     * The published peepso-pages among the given ids, keyed by id. An id that
     * was trashed, drafted, deleted or is not a page is simply absent.
     *
     * Single bulk SELECT, bounded by the caller's IN-list (one feed page).
     *
     * @param  list<int> $pageIds
     * @return array<int, object{ID: int|numeric-string, post_title: string, post_name: string}>
     */
    public static function findPublishedByIds(array $pageIds): array
    {
        if ($pageIds === []) {
            return [];
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($pageIds), '%d'));

        /** @var list<object{ID: int|numeric-string, post_title: string, post_name: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_title, post_name
               FROM {$wpdb->posts}
              WHERE ID IN ({$placeholders})
                AND post_type = 'peepso-page'
                AND post_status = 'publish'
              LIMIT %d",
            ...[...$pageIds, count($pageIds)]
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[(int) $row->ID] = $row;
        }

        return $out;
    }

    /**
     * Of the given post IDs, return those whose chain meta resolves to
     * $chainId. Matches EITHER `_bcc_gate_chain_id` (NFT holder groups)
     * OR `_bcc_chain_tag` (user-created plain groups); meta_value is a
     * stringified chain id, CAST to UNSIGNED for the comparison.
     *
     * Bounded by the caller-supplied IN-list (the candidate group set).
     * Single bulk SELECT — no N+1.
     *
     * @param  list<int> $postIds
     * @return list<int> The subset of $postIds gated to $chainId.
     */
    public static function findPostIdsGatedByChain(array $postIds, int $chainId): array
    {
        if ($postIds === [] || $chainId <= 0) {
            return [];
        }

        global $wpdb;

        $ph       = implode(',', array_fill(0, count($postIds), '%d'));
        $params   = $postIds;
        $params[] = $chainId;

        /** @var list<object{post_id: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT post_id
               FROM {$wpdb->postmeta}
              WHERE meta_key IN ('_bcc_gate_chain_id', '_bcc_chain_tag')
                AND post_id IN ({$ph})
                AND CAST(meta_value AS UNSIGNED) = %d",
            ...$params
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[] = (int) $row->post_id;
        }
        return $out;
    }
}
