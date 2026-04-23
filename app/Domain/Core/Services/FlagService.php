<?php
/**
 * Page Flag Service
 *
 * Lightweight public signal system — allows any logged-in user to flag
 * a page as suspicious. Flags are visible community signals only.
 *
 * CRITICAL: Flags MUST NOT affect trust score, fraud_score, or trigger
 * any automatic penalties. This is a signal-only system.
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

if (!defined('ABSPATH')) {
    exit;
}

class FlagService
{
    /** @var string Cache group for flag counts. */
    private const CACHE_GROUP = 'bcc_page_flags';

    /** @var int Cache TTL in seconds (5 minutes). */
    private const CACHE_TTL = 300;

    /** @var int Max flags per user per 24 hours (global, across all pages). */
    private const DAILY_LIMIT = 3;

    /**
     * Flag a page as suspicious.
     *
     * @return array{success: bool, message: string, flag_count: int}
     */
    public static function flagPage(int $pageId, int $userId, ?string $reason = null): array
    {
        if ($pageId <= 0 || $userId <= 0) {
            return ['success' => false, 'message' => 'Invalid parameters.', 'flag_count' => 0];
        }

        // Daily rate limit
        if (self::getDailyFlagCount($userId) >= self::DAILY_LIMIT) {
            return [
                'success'    => false,
                'message'    => 'You have reached the daily flag limit (' . self::DAILY_LIMIT . ' per day).',
                'flag_count' => self::getFlagCount($pageId),
            ];
        }

        // Already flagged?
        if (self::hasUserFlagged($pageId, $userId)) {
            return [
                'success'    => false,
                'message'    => 'You have already flagged this page.',
                'flag_count' => self::getFlagCount($pageId),
            ];
        }

        $repo = new \BCC\Trust\Core\Repositories\PageFlagRepository();

        if (!$repo->create($pageId, $userId, $reason)) {
            return ['success' => false, 'message' => 'Failed to save flag.', 'flag_count' => 0];
        }

        // Increment daily counter
        self::incrementDailyCount($userId);

        // Bust cache
        self::invalidateCache($pageId);

        $count = self::getFlagCount($pageId);

        return ['success' => true, 'message' => 'Page flagged.', 'flag_count' => $count];
    }

    /**
     * Remove a flag from a page.
     *
     * @return array{success: bool, message: string, flag_count: int}
     */
    public static function unflagPage(int $pageId, int $userId): array
    {
        if ($pageId <= 0 || $userId <= 0) {
            return ['success' => false, 'message' => 'Invalid parameters.', 'flag_count' => 0];
        }

        $repo = new \BCC\Trust\Core\Repositories\PageFlagRepository();
        $deleted = $repo->deleteByUser($pageId, $userId);

        if ($deleted === false) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-trust] FlagService::unflagPage DB error', [
                    'page_id' => $pageId,
                    'user_id' => $userId,
                ]);
            }
            return [
                'success'    => false,
                'message'    => 'Failed to remove flag.',
                'flag_count' => self::getFlagCount($pageId),
            ];
        }

        if ($deleted === 0) {
            return [
                'success'    => false,
                'message'    => 'Flag not found.',
                'flag_count' => self::getFlagCount($pageId),
            ];
        }

        self::invalidateCache($pageId);
        $count = self::getFlagCount($pageId);

        return ['success' => true, 'message' => 'Flag removed.', 'flag_count' => $count];
    }

    /**
     * Check if a user has flagged a specific page.
     */
    public static function hasUserFlagged(int $pageId, int $userId): bool
    {
        if ($pageId <= 0 || $userId <= 0) {
            return false;
        }

        return (new \BCC\Trust\Core\Repositories\PageFlagRepository())->hasUserFlagged($pageId, $userId);
    }

    /**
     * Get the total flag count for a page.
     *
     * Cached for 5 minutes — flag counts are low-frequency reads.
     */
    public static function getFlagCount(int $pageId): int
    {
        if ($pageId <= 0) {
            return 0;
        }

        $cacheKey = "flag_count_{$pageId}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return (int) $cached;
        }

        $count = (new \BCC\Trust\Core\Repositories\PageFlagRepository())->countForPage($pageId);

        wp_cache_set($cacheKey, $count, self::CACHE_GROUP, self::CACHE_TTL);

        return $count;
    }

    /**
     * Get recent flags for a page (admin use).
     *
     * @return array<object{user_id: int|numeric-string, reason: ?string, created_at: string, display_name: string|null}>
     */
    public static function getRecentFlags(int $pageId, int $limit = 20): array
    {
        return (new \BCC\Trust\Core\Repositories\PageFlagRepository())->getRecentForPage($pageId, $limit);
    }

    // ── Rate limiting (transient-based) ─────────────────────────────────

    private static function getDailyFlagCount(int $userId): int
    {
        $key = "bcc_flag_daily_{$userId}";

        // Try atomic object cache first.
        $count = wp_cache_get($key, 'bcc_page_flags');
        if ($count !== false) {
            return (int) $count;
        }

        // Fall back to transient for hosts without persistent object cache.
        return (int) get_transient($key);
    }

    private static function incrementDailyCount(int $userId): void
    {
        $key = "bcc_flag_daily_{$userId}";

        // Attempt atomic increment via object cache to prevent race conditions.
        $new = wp_cache_incr($key, 1, 'bcc_page_flags');

        if ($new === false) {
            // Object cache key doesn't exist yet — initialise it atomically.
            // If another process created it in the meantime, retry the increment.
            if (!wp_cache_add($key, 1, 'bcc_page_flags', DAY_IN_SECONDS)) {
                $new = wp_cache_incr($key, 1, 'bcc_page_flags');
            } else {
                $new = 1;
            }
        }

        // Always mirror to transient so hosts without a persistent object cache
        // (default WP installs) still enforce the daily limit across requests.
        // Without this, wp_cache is per-request and the counter resets every
        // REST call, making the 5-per-day cap un-enforceable.
        set_transient($key, (int) ($new ?: 1), DAY_IN_SECONDS);
    }

    // ── Cache ───────────────────────────────────────────────────────────

    private static function invalidateCache(int $pageId): void
    {
        wp_cache_delete("flag_count_{$pageId}", self::CACHE_GROUP);
    }
}
