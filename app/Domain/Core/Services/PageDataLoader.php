<?php
/**
 * Shared Page Data Loader
 *
 * Single entry-point for all server-side consumers (blocks, REST endpoint,
 * shortcodes) to fetch aggregated page data.  Backed by wp_cache (Redis
 * when available) so a page with five trust blocks still only runs the
 * aggregation query once.
 *
 * Cache stampede protection: uses MySQL GET_LOCK (via AdvisoryLock) so
 * only one request rebuilds the payload; others get stale-while-revalidate.
 *
 * Usage:
 *   $data = \BCC\Trust\Core\Services\PageDataLoader::get( $page_id );
 *   // $data matches PageDataSchema::SCHEMA — every key guaranteed.
 *
 * Cache invalidation:
 *   \BCC\Trust\Core\Services\PageDataLoader::bust( $page_id );
 *   \BCC\Trust\Core\Services\PageDataLoader::bustForUser( $user_id );
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PageDataLoader {

    /** @var string Object-cache group. */
    private const CACHE_GROUP = 'bcc_page_data';

    /** @var int Default TTL in seconds (5 minutes). */
    private const DEFAULT_TTL = 300;

    /** @var PageDataAggregator|null Lazy-initialised singleton. */
    private static ?PageDataAggregator $aggregator = null;

    /**
     * Get the full aggregated page payload (viewer-agnostic).
     *
     * Section filtering uses "prefetch full + slice" strategy:
     * the full payload is always fetched/cached, then sliced to the
     * requested sections.  This means 5 blocks on the same page still
     * only trigger one aggregation — no duplicate work, no cache
     * poisoning, no partial-payload cache entries.
     *
     * @param int          $page_id
     * @param list<string> $sections Optional section filter (e.g. ['trust','verification']).
     *                               Empty array = all sections (default).
     * @return array<string, mixed>|null  Null when the post does not exist.
     */
    public static function get( int $page_id, array $sections = [] ): ?array {
        if ( $page_id <= 0 ) {
            return null;
        }

        // Always fetch/cache the FULL payload — never build partial.
        $full = self::getFullPayload( $page_id );
        if ( null === $full ) {
            return null;
        }

        // No filter → return everything.
        if ( empty( $sections ) ) {
            return $full;
        }

        // Slice: return only the requested sections.
        return array_intersect_key( $full, array_flip( $sections ) );
    }

    /**
     * Internal: fetch or build the full cached payload with stampede protection.
     *
     * Stampede protection: when the cache is cold, only one request acquires
     * the build lock and runs the aggregator.  Concurrent requests that lose
     * the lock race return null (the post-exists check is cheap, and blocks
     * already handle null gracefully).  This prevents 50 simultaneous requests
     * from all hitting the DB at once.
     *
     * @param int $page_id
     * @return array<string, mixed>|null
     */
    private static function getFullPayload( int $page_id ): ?array {
        $cache_key = self::cacheKey( $page_id );
        $cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

        if ( false !== $cached ) {
            return $cached;
        }

        // ── Stampede protection ──────────────────────────────
        // MySQL GET_LOCK is atomic across all DB sessions and auto-releases
        // on connection close, so a crashed worker cannot leave a stale
        // lock. Winner builds; losers serve stale data while waiting.
        $lock_key  = 'bcc_pdl_' . $page_id;
        $stale_key = 'stale_' . $page_id;
        $got_lock  = \BCC\Core\DB\AdvisoryLock::acquire( $lock_key, 0 );

        if ( ! $got_lock ) {
            // Another request is building. Serve stale data if available.
            $just_set = wp_cache_get( $cache_key, self::CACHE_GROUP );
            if ( false !== $just_set ) {
                return $just_set;
            }
            // No fresh value yet — try stale backup (kept for 2x TTL).
            $stale = wp_cache_get( $stale_key, self::CACHE_GROUP );
            if ( false !== $stale ) {
                return $stale;
            }
            // Truly cold cache, no stale data.  Audit MED-6: returning
            // null here (instead of falling through to build) stops the
            // thundering-herd amplification where a flood of first-view
            // requests for a newly-published or previously-unseen page
            // each run the full PageDataAggregator::build.  The caller's
            // UI should render a placeholder / loading state.  The lock
            // holder will publish shortly and subsequent reads hit cache.
            return null;
        }

        try {
            $post = get_post( $page_id );
            if ( ! $post || 'publish' !== $post->post_status ) {
                return null;
            }

            $raw = self::aggregator()->build( $post, 0 );

            // Strip viewer section — user-specific, never cached.
            unset( $raw['viewer'] );

            $data = PageDataSchema::applyDefaults( $raw );

            /** @var int $ttl Seconds. Default 300 (5 min). */
            $ttl = (int) apply_filters( 'bcc_page_data_cache_ttl', self::DEFAULT_TTL, $page_id );

            wp_cache_set( $cache_key, $data, self::CACHE_GROUP, $ttl );

            // Keep a stale backup at 2x TTL for stale-while-revalidate.
            wp_cache_set( $stale_key, $data, self::CACHE_GROUP, $ttl * 2 );

            return $data;
        } finally {
            \BCC\Core\DB\AdvisoryLock::release( $lock_key );
        }
    }

    /**
     * Get the viewer-specific section (always fresh, never cached).
     *
     * @param int $viewer_id  Current user ID (0 = logged-out).
     * @param int $page_id
     * @return array<string, mixed>|null
     */
    public static function getViewer( int $viewer_id, int $page_id ): ?array {
        if ( ! $viewer_id || $page_id <= 0 ) {
            return null;
        }

        $post = get_post( $page_id );
        if ( ! $post ) {
            return null;
        }

        $owner_id = (int) PeepSoPageResolver::getOwnerId( $page_id );

        return self::aggregator()->buildViewerSection( $viewer_id, $page_id, $owner_id );
    }

    /**
     * Get per-chain wallet detail for a page (address, role, trust boost, NFTs).
     *
     * Now a thin wrapper: reads from the main cached payload's wallet_detail
     * section. No separate cache key, no duplicate DB load.
     *
     * @param int $page_id
     * @return array<string, mixed> Keyed by chain name.
     */
    public static function getWalletDetail( int $page_id ): array {
        $data = self::get( $page_id );
        return $data['wallet_detail'] ?? [];
    }

    /* ── Cache invalidation ──────────────────────────────── */

    /**
     * Bust the cached data for a single page.
     */
    public static function bust( int $page_id ): void {
        wp_cache_delete( self::cacheKey( $page_id ), self::CACHE_GROUP );
        // CRITICAL: stale_ MUST be deleted on write-triggered bust — otherwise
        // a fresh vote/endorsement commits, the main cache is cleared, and the
        // next concurrent reader hits the build lock, falls through to stale_
        // (unexpired for up to 2×TTL = 600s), and serves the pre-write payload.
        // The cost of dropping stale_ here is accepting a brief stampede on the
        // very first post-write read; the benefit is that we never silently
        // serve known-stale trust data after a write.
        wp_cache_delete( 'stale_' . $page_id, self::CACHE_GROUP );

        // Also clear legacy transient if present (migration path).
        delete_transient( 'bcc_page_data_' . $page_id );
    }

    /**
     * Bust cached data for every page owned by a user.
     */
    public static function bustForUser( int $user_id ): void {
        if ( ! $user_id ) {
            return;
        }

        if ( function_exists( 'bcc_trust_get_user_pages' ) ) {
            $page_ids = bcc_trust_get_user_pages( $user_id );
        } else {
            $page_ids = get_posts( [
                'author'         => $user_id,
                'post_status'    => 'publish',
                'posts_per_page' => 100,
                'fields'         => 'ids',
            ] );
        }

        foreach ( $page_ids as $pid ) {
            self::bust( (int) $pid );
        }
    }

    /**
     * Bust cached data for a pre-resolved list of page IDs.
     *
     * Callers that already hold a batch of page IDs (e.g. from a single
     * IN() query in a cron loop) should use this instead of calling
     * bustForUser() per user, which issues one DB query per caller.
     *
     * @param int[] $page_ids
     */
    public static function bustMany( array $page_ids ): void {
        foreach ( $page_ids as $pid ) {
            $pid = (int) $pid;
            if ( $pid > 0 ) {
                self::bust( $pid );
            }
        }
    }

    /* ── Internal ────────────────────────────────────────── */

    private static function cacheKey( int $page_id ): string {
        return 'page_' . $page_id;
    }

    private static function aggregator(): PageDataAggregator {
        if ( null === self::$aggregator ) {
            self::$aggregator = new PageDataAggregator(
                new ScoreRepository(),
                new UserInfoRepository()
            );
        }
        return self::$aggregator;
    }
}
