<?php
/**
 * Page Discovery Service
 *
 * Queries pages by type, applies sorting/filtering, and returns
 * normalized card data for the Discovery Hub block.  All results are
 * cached in wp_cache (Redis when available) with parameter-aware keys.
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\UserInfoRepository;

if (!defined('ABSPATH')) {
    exit;
}

class PageDiscoveryService {

    /** @var string Object-cache group. */
    private const CACHE_GROUP = 'bcc_discovery';

    /** @var int Default TTL in seconds (5 minutes). */
    private const DEFAULT_TTL = 300;

    /** @var UserInfoRepository */
    private UserInfoRepository $users;

    /** @var bool|null Lazy-checked flag for read model availability. */
    private ?bool $useReadModel = null;

    public function __construct() {
        $this->users = new UserInfoRepository();
    }

    /** @var string[] Allowed tier filter values. */
    private const ALLOWED_TIERS = ['elite', 'trusted', 'neutral', 'caution', 'risky'];

    /**
     * Query pages matching the given filters.
     *
     * @param array<string, mixed> $args {
     *     @type string[] $types          Page types to include (empty = all).
     *     @type string   $sort           Sort mode: trust|endorsements|newest|followers.
     *     @type bool     $verified_only  Only return verified pages.
     *     @type string   $tier           Reputation tier filter (elite|trusted|…). Empty = all.
     *     @type float    $min_confidence Minimum confidence score (0.0–1.0). 0 = disabled.
     *     @type bool     $new_only       Only show pages created in the last 30 days.
     *     @type int      $limit          Results per page.
     *     @type int      $page           Page number (1-based).
     *     @type string   $search         Search term.
     * }
     * @return array<string, mixed>
     */
    public function query(array $args): array {
        $types          = array_filter(array_map('sanitize_key', $args['types'] ?? []));
        $sort           = sanitize_key($args['sort'] ?? 'trust');
        $verified_only  = (bool) ($args['verified_only'] ?? false);
        $tier           = sanitize_key($args['tier'] ?? '');
        $min_confidence = max(0.0, min(1.0, (float) ($args['min_confidence'] ?? 0)));
        $new_only       = (bool) ($args['new_only'] ?? false);
        $max_votes      = max(0, (int) ($args['max_votes'] ?? 0));
        $limit          = max(1, min(50, (int) ($args['limit'] ?? 12)));
        $page           = max(1, (int) ($args['page'] ?? 1));
        $search         = sanitize_text_field($args['search'] ?? '');

        // Validate tier against allowlist.
        if ($tier !== '' && !in_array($tier, self::ALLOWED_TIERS, true)) {
            $tier = '';
        }

        // Build cache key from all parameters.
        $cache_key = $this->buildCacheKey($types, $sort, $verified_only, $tier, $min_confidence, $new_only, $max_votes, $limit, $page, $search);
        $cached    = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (false !== $cached) {
            return $cached;
        }

        $result = $this->canUseReadModel()
            ? $this->executeReadModelQuery($types, $sort, $verified_only, $tier, $min_confidence, $new_only, $max_votes, $limit, $page, $search)
            : $this->executeQuery($types, $sort, $verified_only, $tier, $min_confidence, $new_only, $max_votes, $limit, $page, $search);

        /** @var int $ttl Filterable cache TTL. */
        $ttl = (int) apply_filters('bcc_discovery_cache_ttl', self::DEFAULT_TTL);

        wp_cache_set($cache_key, $result, self::CACHE_GROUP, $ttl);

        return $result;
    }

    // ──────────────────────────────────────────────────────────
    //  Read-model path (fast — single table, no JOINs)
    // ──────────────────────────────────────────────────────────

    /**
     * Check whether the read model table has been populated.
     */
    private function canUseReadModel(): bool
    {
        if ($this->useReadModel === null) {
            $this->useReadModel = Plugin::instance()->pageReadModelRepository()->hasData();
        }
        if ($this->useReadModel === false) {
            // Observability counter: legacy-aggregation fallback active.
            // Expected immediately after fresh install (read model not
            // yet populated), or transiently after a bulk delete/cache
            // bust. Sustained activation = a sync cron is broken.
            \BCC\Core\Observability\DegradationMetrics::record('read_model_fallback', 'legacy_aggregation');
        }
        return $this->useReadModel;
    }

    /**
     * Query the denormalized read model table.
     *
     * Same signature and return shape as executeQuery() so the caller
     * is agnostic to which path was taken.
     *
     * @param string[] $types
     * @return array<string, mixed>
     */
    private function executeReadModelQuery(
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
        $result = \BCC\Trust\Core\Repositories\PageDiscoveryRepository::queryFromReadModel(
            $types, $sort, $verified_only, $tier, $min_confidence,
            $new_only, $max_votes, $limit, $page, $search
        );

        $rows = $result['rows'];
        if (empty($rows)) {
            return ['results' => [], 'total' => $result['total'], 'pages' => $result['pages'], 'page' => $page];
        }

        $post_ids = array_map(fn($r) => (int) $r->ID, $rows);
        _prime_post_caches($post_ids, true, true);

        $claimsMap = \BCC\Trust\Core\Repositories\PageDiscoveryRepository::batchLoadClaims($post_ids);

        $pages_list = [];
        foreach ($rows as $row) {
            $pages_list[] = $this->buildCardFromReadModel($row, $claimsMap);
        }

        return [
            'results' => $pages_list,
            'total'   => $result['total'],
            'pages'   => $result['pages'],
            'page'    => $page,
        ];
    }

    /**
     * Build a card from a read-model row.
     *
     * Most data is already denormalized — only avatar/cover/permalink
     * need post-level lookups (primed in batch above).
     *
     * @param array<int, string> $claimsMap
     * @return array<string, mixed>
     *
     * @phpstan-param object{
     *   ID: int|numeric-string,
     *   post_title: string,
     *   page_type: string,
     *   trust_score: float|numeric-string,
     *   reputation_tier: string,
     *   endorsement_count: int|numeric-string,
     *   is_verified: int|numeric-string,
     *   follower_count: int|numeric-string,
     *   owner_id: int|numeric-string
     * } $row
     */
    private function buildCardFromReadModel(object $row, array $claimsMap = []): array {
        $post_id  = (int) $row->ID;
        $owner_id = (int) $row->owner_id;

        // Avatar and cover (still need PeepSo API).
        $avatar = '';
        $cover  = '';

        if (class_exists('PeepSoPagePhoto')) {
            $photo = \PeepSoPagePhoto::get_instance();
            if ($photo) {
                $avatar = $photo->get_page_avatar_url($post_id) ?: '';
                $cover  = $photo->get_page_cover_url($post_id) ?: '';
            }
        }

        if (!$avatar) {
            $avatar = get_the_post_thumbnail_url($post_id, 'thumbnail') ?: '';
        }

        $claimer = $claimsMap[$post_id] ?? null;

        return [
            'page_id'      => $post_id,
            'page_name'    => sanitize_text_field($row->post_title),
            'page_type'    => sanitize_key($row->page_type),
            'page_url'     => esc_url_raw(get_permalink($post_id) ?: ''),
            'avatar_url'   => esc_url_raw($avatar),
            'cover_url'    => esc_url_raw($cover),
            'trust_score'  => (int) $row->trust_score,
            'tier'         => sanitize_key($row->reputation_tier),
            'endorsements' => (int) $row->endorsement_count,
            'verified'     => (bool) $row->is_verified,
            'followers'    => (int) $row->follower_count,
            'claimed'      => $claimer !== null,
            'claimed_by'   => $claimer,
        ];
    }

    // ──────────────────────────────────────────────────────────
    //  Legacy path (fallback — multi-table JOINs + N+1 priming)
    // ──────────────────────────────────────────────────────────

    /**
     * Execute the discovery query against the database.
     *
     * @param string[] $types
     * @return array<string, mixed>
     */
    private function executeQuery(
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
        $result = \BCC\Trust\Core\Repositories\PageDiscoveryRepository::queryFromPostsTable(
            $types, $sort, $verified_only, $tier, $min_confidence,
            $new_only, $max_votes, $limit, $page, $search
        );

        $rows = $result['rows'];
        if (empty($rows)) {
            return ['results' => [], 'total' => $result['total'], 'pages' => $result['pages'], 'page' => $page];
        }

        $this->primeCaches($rows);

        $post_ids  = array_map(fn($r) => (int) $r->ID, $rows);
        $claimsMap = \BCC\Trust\Core\Repositories\PageDiscoveryRepository::batchLoadClaims($post_ids);

        $pages_list = [];
        foreach ($rows as $row) {
            $pages_list[] = $this->buildCard($row, $claimsMap);
        }

        return [
            'results' => $pages_list,
            'total'   => $result['total'],
            'pages'   => $result['pages'],
            'page'    => $page,
        ];
    }

    /**
     * Batch-prime all caches needed by buildCard() so the loop runs
     * with zero additional DB queries.
     *
     * @param object[] $rows Raw query result rows.
     * @phpstan-param list<object{ID: int|numeric-string, post_author: int|numeric-string}> $rows
     */
    private function primeCaches(array $rows): void
    {
        if (empty($rows)) {
            return;
        }

        $post_ids   = array_map(fn($r) => (int) $r->ID, $rows);
        $author_ids = array_map(fn($r) => (int) $r->post_author, $rows);

        // 1. Prime WP post objects + post meta (covers get_post_meta,
        //    get_the_post_thumbnail_url, and get_permalink).
        _prime_post_caches($post_ids, true, true);

        // 2. Batch-resolve page owners via PageOwnerResolver.
        $resolver   = Plugin::instance()->pageOwnerResolver();
        $resolver->primeOwnerCache($post_ids);

        // Collect resolved owner IDs so we can prime user caches for them too.
        $owner_ids = [];
        foreach ($post_ids as $pid) {
            $owner_ids[] = $resolver->getPageOwner($pid); // reads from static cache, no DB
        }
        $all_user_ids = array_unique(array_filter(array_merge($author_ids, $owner_ids)));

        // 3. Prime WP user objects + user meta (covers get_user_meta for followers etc.).
        if (!empty($all_user_ids)) {
            cache_users($all_user_ids);
        }

        // 4. Bulk-fetch user_info rows and prime wp_cache so
        //    UserInfoRepository::getByUserId() hits cache.
        $user_infos = $this->users->getBulkByUserIds($all_user_ids);
        foreach ($user_infos as $uid => $info) {
            wp_cache_set('user_info_' . $uid, $info, 'bcc_trust', BCC_TRUST_CACHE_USER);
        }
        // Prime cache misses too (users with no user_info row).
        foreach ($all_user_ids as $uid) {
            if (!isset($user_infos[$uid])) {
                wp_cache_set('user_info_' . $uid, '', 'bcc_trust', BCC_TRUST_CACHE_USER);
            }
        }
    }

    /**
     * Build a single page card from a query result row.
     *
     * The row shape matches PageDiscoveryRepository::queryFromPostsTable()'s
     * projection — note the SQL aliases `trust_score` (from `ps.total_score`)
     * and `endorsements` (from `ps.endorsement_count`).
     *
     * @param array<int, string> $claimsMap
     * @return array<string, mixed>
     *
     * @phpstan-param object{
     *   ID: int|numeric-string,
     *   post_title: string,
     *   post_author: int|numeric-string,
     *   trust_score: float|numeric-string,
     *   reputation_tier: string,
     *   endorsements: int|numeric-string,
     *   follower_count: int|numeric-string|null
     * } $row
     */
    private function buildCard(object $row, array $claimsMap = []): array {
        $post_id  = (int) $row->ID;
        $owner_id = (int) $row->post_author;

        // Resolve actual page owner from PeepSo.
        $resolved_owner = PeepSoPageResolver::getOwnerId($post_id);
        if ($resolved_owner) {
            $owner_id = (int) $resolved_owner;
        }

        // Avatar and cover.
        $avatar = '';
        $cover  = '';

        if (class_exists('PeepSoPagePhoto')) {
            $photo = \PeepSoPagePhoto::get_instance();
            if ($photo) {
                $avatar = $photo->get_page_avatar_url($post_id) ?: '';
                $cover  = $photo->get_page_cover_url($post_id) ?: '';
            }
        }

        if (!$avatar) {
            $avatar = get_the_post_thumbnail_url($post_id, 'thumbnail') ?: '';
        }

        // Page type from meta.
        $page_type = get_post_meta($post_id, '_bcc_page_type', true);
        if (!$page_type) {
            $page_type = 'builder';
        }

        // Verified status.
        $verified = false;
        try {
            $owner_info = $this->users->getByUserId($owner_id);
            $verified   = $owner_info ? (bool) $owner_info->is_verified : false;
        } catch (\Exception $e) {
            // Silent.
        }

        // Followers.
        $followers = 0;
        if (function_exists('peepso') && $owner_id) {
            $followers = (int) get_user_meta($owner_id, 'peepso_followers_count', true);
        }

        $claimer = $claimsMap[$post_id] ?? null;

        return [
            'page_id'      => $post_id,
            'page_name'    => sanitize_text_field($row->post_title),
            'page_type'    => sanitize_key($page_type),
            'page_url'     => esc_url_raw(get_permalink($post_id) ?: ''),
            'avatar_url'   => esc_url_raw($avatar),
            'cover_url'    => esc_url_raw($cover),
            'trust_score'  => (int) $row->trust_score,
            'tier'         => sanitize_key($row->reputation_tier),
            'endorsements' => (int) $row->endorsements,
            'verified'     => $verified,
            'followers'    => $followers,
            'claimed'      => $claimer !== null,
            'claimed_by'   => $claimer,
        ];
    }

    /**
     * Build a deterministic cache key from query parameters.
     *
     * @param string[] $types
     */
    private function buildCacheKey(
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
    ): string {
        sort($types);
        $type_str    = $types ? implode('-', $types) : 'all';
        $verified    = $verified_only ? '1' : '0';
        $tier_str    = $tier ?: '0';
        $conf_str    = $min_confidence > 0 ? (string) $min_confidence : '0';
        $new_str     = $new_only ? '1' : '0';
        $mv_str      = $max_votes > 0 ? (string) $max_votes : '0';
        $search_hash = $search !== '' ? md5($search) : '0';

        return "bcc_discovery_{$type_str}_{$sort}_{$verified}_{$tier_str}_{$conf_str}_{$new_str}_{$mv_str}_{$limit}_{$page}_{$search_hash}";
    }
}
