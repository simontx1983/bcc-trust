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
        $types              = array_filter(array_map('sanitize_key', $args['types'] ?? []));
        $sort               = sanitize_key($args['sort'] ?? 'trust');
        $verified_only      = (bool) ($args['verified_only'] ?? false);
        $tier               = sanitize_key($args['tier'] ?? '');
        $min_confidence     = max(0.0, min(1.0, (float) ($args['min_confidence'] ?? 0)));
        $new_only           = (bool) ($args['new_only'] ?? false);
        $max_votes          = max(0, (int) ($args['max_votes'] ?? 0));
        $limit              = max(1, min(50, (int) ($args['limit'] ?? 12)));
        $page               = max(1, (int) ($args['page'] ?? 1));
        $search             = sanitize_text_field($args['search'] ?? '');
        $good_standing_only = (bool) ($args['good_standing_only'] ?? false);
        $chain_slug         = sanitize_key($args['chain_slug'] ?? '');
        $status             = sanitize_key($args['status'] ?? '');
        $min_self_stake     = isset($args['min_self_stake']) ? max(0.0, (float) $args['min_self_stake']) : null;

        // Validate tier against allowlist.
        if ($tier !== '' && !in_array($tier, self::ALLOWED_TIERS, true)) {
            $tier = '';
        }

        // Build cache key from all parameters.
        $cache_key = $this->buildCacheKey($types, $sort, $verified_only, $tier, $min_confidence, $new_only, $max_votes, $limit, $page, $search, $good_standing_only, $chain_slug, $status, $min_self_stake);
        $cached    = wp_cache_get($cache_key, self::CACHE_GROUP);

        if (false !== $cached) {
            return $cached;
        }

        // Validator-only axes (status / min self-stake / self-stake sort)
        // are served by the read-model path only — same as the chain
        // filter, which the legacy fallback does not implement either.
        $usesReadModel = $this->canUseReadModel();
        $result = $usesReadModel
            ? $this->executeReadModelQuery($types, $sort, $verified_only, $tier, $min_confidence, $new_only, $max_votes, $limit, $page, $search, $good_standing_only, $chain_slug, $status, $min_self_stake)
            : $this->executeQuery($types, $sort, $verified_only, $tier, $min_confidence, $new_only, $max_votes, $limit, $page, $search, $good_standing_only);

        // Whether these results actually reflect `chain_slug` (F-439). The
        // chain filter exists on the read-model path only; the legacy
        // fallback ignores it. A caller that tells someone "validators on
        // Cosmos first" reads this instead of assuming. Cached with the
        // result, so a hit reports the path that filled it.
        $result['chain_applied'] = $chain_slug !== '' && $usesReadModel;

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
        string $search,
        bool $good_standing_only = false,
        string $chain_slug = '',
        string $status = '',
        ?float $min_self_stake = null
    ): array {
        // F-584: the `status` filter matches only validators on a chain that
        // is still answering (any other reads `unknown`). Active chains only:
        // a deactivated chain is not indexed, so its statuses are frozen.
        $statusChainIds = $status !== ''
            ? \BCC\Trust\Onchain\Support\ValidatorStatusProjection::currentAmong(array_map(
                static fn (object $chain): int => (int) $chain->id,
                \BCC\Trust\Onchain\Repositories\ChainRepository::getActive()
            ))
            : [];

        $result = \BCC\Trust\Core\Repositories\PageDiscoveryRepository::queryFromReadModel(
            $types, $sort, $verified_only, $tier, $min_confidence,
            $new_only, $max_votes, $limit, $page, $search, $good_standing_only, $chain_slug, $status, $min_self_stake,
            $statusChainIds
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

        // Avatar and cover. Both resolvers are below.
        $avatar = $this->resolvePageAvatarUrl($post_id);
        $cover  = $this->resolvePageCoverUrl($post_id);

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
        string $search,
        bool $good_standing_only = false
    ): array {
        $result = \BCC\Trust\Core\Repositories\PageDiscoveryRepository::queryFromPostsTable(
            $types, $sort, $verified_only, $tier, $min_confidence,
            $new_only, $max_votes, $limit, $page, $search, $good_standing_only
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
        $avatar = $this->resolvePageAvatarUrl($post_id);
        $cover  = $this->resolvePageCoverUrl($post_id);

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
            // Silent fallback (degrades to $verified = false). Pattern-
            // registry §"Observability — audit_log_swallow": sustained
            // activation = UserRepository read path is unhealthy on a
            // request-bounded discovery hot path; admins see it via
            // /system/health before users notice missing badges.
            \BCC\Core\Observability\DegradationMetrics::record('audit_log_swallow', 'discovery_owner_verified_status');
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
     * A page's avatar URL, or '' when it has none.
     *
     * ⚠⚠ REPLACES A BRANCH THAT NEVER RAN. Both call sites previously
     * read the avatar and cover through `PeepSoPagePhoto`, guarded by
     * `class_exists('PeepSoPagePhoto')` — and NO SUCH CLASS EXISTS.
     * `peepso-pages` is installed and defines `PeepSoPage`; a grep of
     * the whole plugin tree finds that name only in those two calls, and
     * `get_page_avatar_url()` / `get_page_cover_url()` exist on nothing.
     * So the guard was always false, both values stayed '', and page
     * discovery served no avatar and no cover.
     *
     * ⚠ It was latent-fatal as well as dead: anything defining a
     * `PeepSoPagePhoto` WITHOUT those two methods would have turned both
     * lines into `Call to undefined method`. PHPStan could see neither
     * problem, because an unknown class has no methods to check.
     *
     * ── WHY THE ACCESSOR IS GATED BY THE UPLOAD HASH ────────────────
     *
     * `PeepSoPage::get_avatar_url()` never returns empty: with no avatar
     * file it answers `get_default_avatar_url()`. Calling it
     * unconditionally would make the post-thumbnail fallback below
     * unreachable, so pages showing their WP featured image today would
     * switch to PeepSo's generic default — a visible change this fix has
     * no mandate for.
     *
     * It also costs a `file_exists()` per page and calls
     * `set_avatar_custom_flag()`, whose short-circuit compares
     * `0 == $current_val` against an ABSENT meta (`''`). Under PHP 8
     * `0 == ''` is FALSE, so that short-circuit does not fire for exactly
     * the pages that have no avatar — i.e. a write attempt on a read
     * path, in a bounded loop, in the common case.
     *
     * ⇒ `peepso_page_avatar_hash` is written by the UPLOAD path
     * (pageajax), never by the getter, so a non-empty hash means a real
     * uploaded avatar. Only then is the accessor consulted; pages
     * without one keep exactly today's behaviour.
     *
     * ⚠ Known gap, stated rather than hidden: an avatar predating
     * hashing has no hash and is still missed. That is strictly better
     * than today, where every page is missed, and it fails to the same
     * fallback rather than to an error.
     */
    private function resolvePageAvatarUrl(int $postId): string
    {
        $hash = get_post_meta($postId, 'peepso_page_avatar_hash', true);

        if (is_string($hash) && $hash !== '' && class_exists('PeepSoPage')) {
            $page = new \PeepSoPage($postId);
            $url  = $page->get_avatar_url();
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        // Unchanged fallback: the page's WP featured image.
        return get_the_post_thumbnail_url($postId, 'thumbnail') ?: '';
    }

    /**
     * A page's cover URL, or '' when it has none.
     *
     * ⚠⚠ THE META, NOT `PeepSoPage::get_cover_url()`. That accessor
     * starts from `get_cover_default()` and overwrites it only when the
     * meta is non-empty, so it never reports "no cover": every page
     * would get PeepSo's default image and no caller could tell a real
     * cover from a placeholder. `page_cover_photo` is the only signal
     * that separates them, and it is what the accessor itself reads.
     *
     * Fail-safe: anything that is not a non-empty string reads as "no
     * cover", never as an error.
     */
    private function resolvePageCoverUrl(int $postId): string
    {
        $cover = get_post_meta($postId, 'page_cover_photo', true);

        return is_string($cover) ? $cover : '';
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
        string $search,
        bool $good_standing_only = false,
        string $chain_slug = '',
        string $status = '',
        ?float $min_self_stake = null
    ): string {
        sort($types);
        $type_str    = $types ? implode('-', $types) : 'all';
        $verified    = $verified_only ? '1' : '0';
        $tier_str    = $tier ?: '0';
        $conf_str    = $min_confidence > 0 ? (string) $min_confidence : '0';
        $new_str     = $new_only ? '1' : '0';
        $mv_str      = $max_votes > 0 ? (string) $max_votes : '0';
        $search_hash = $search !== '' ? md5($search) : '0';
        $gs_str      = $good_standing_only ? '1' : '0';
        $chain_str   = $chain_slug !== '' ? $chain_slug : '0';
        $status_str  = $status !== '' ? $status : '0';
        $stake_str   = $min_self_stake !== null ? (string) $min_self_stake : '0';

        return "bcc_discovery_{$type_str}_{$sort}_{$verified}_{$tier_str}_{$conf_str}_{$new_str}_{$mv_str}_{$limit}_{$page}_{$search_hash}_gs{$gs_str}_ch{$chain_str}_st{$status_str}_ss{$stake_str}";
    }
}
