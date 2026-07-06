<?php
/**
 * Page Data cache invalidation.
 *
 * Cache-busting entry point for the `bcc_page_data` object-cache group.
 * Callers invalidate a page (or all of a user's pages) after a write so the
 * next read rebuilds fresh.
 *
 *   \BCC\Trust\Core\Services\PageDataLoader::bust( $page_id );
 *   \BCC\Trust\Core\Services\PageDataLoader::bustForUser( $user_id );
 *   \BCC\Trust\Core\Services\PageDataLoader::bustMany( $page_ids );
 *
 * NOTE: the aggregation/read path (get/getViewer/getWalletDetail, backed by
 * the former PageDataAggregator/PageDataSchema) was FSE-block-era machinery
 * that no consumer reached after the headless migration and was removed. The
 * cache keys still exist because live write paths (VoteService, CronService,
 * CacheManager, PageReadModelRepository) bust them on every score mutation.
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class PageDataLoader {

    /** @var string Object-cache group. */
    private const CACHE_GROUP = 'bcc_page_data';

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

    private static function cacheKey( int $page_id ): string {
        return 'page_' . $page_id;
    }
}
