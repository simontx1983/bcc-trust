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

    // ── Rate limiting (DB-backed via RateLimitRepository) ────────────────
    //
    // Counters live in wp_options as "count|expires" rows keyed by user
    // and UTC date. RateLimitRepository::incrementBucket uses INSERT …
    // ON DUPLICATE KEY UPDATE LAST_INSERT_ID(expr) which is atomic across
    // FPM workers regardless of object-cache backend — the previous
    // wp_cache_incr/wp_cache_add path was non-atomic on LiteSpeed
    // Object Cache and let concurrent flag submissions exceed the cap.

    /**
     * wp_options key for the per-user daily flag bucket. Includes the
     * UTC date so the bucket rolls over at midnight UTC. The `_transient_`
     * prefix lets WP's native transient GC reap expired rows alongside
     * the bcc-core cleanup cron.
     */
    private static function dailyBucketKey(int $userId): string
    {
        return '_transient_bcc_flag_daily_' . $userId . '_' . gmdate('Ymd');
    }

    private static function getDailyFlagCount(int $userId): int
    {
        return (int) (\BCC\Trust\Core\Repositories\RateLimitRepository::getBucketCount(
            self::dailyBucketKey($userId)
        ) ?? 0);
    }

    private static function incrementDailyCount(int $userId): void
    {
        $optionKey  = self::dailyBucketKey($userId);
        // Bucket expires at the start of the *next* UTC day plus a small
        // overlap so a count written just before midnight is still
        // reapable shortly after the day rolls over.
        $expiresAt  = strtotime('tomorrow midnight UTC') ?: (time() + DAY_IN_SECONDS);
        $freshValue = '1|' . $expiresAt;

        \BCC\Trust\Core\Repositories\RateLimitRepository::incrementBucket(
            $optionKey,
            $freshValue
        );
    }

    // ── Cache ───────────────────────────────────────────────────────────

    private static function invalidateCache(int $pageId): void
    {
        wp_cache_delete("flag_count_{$pageId}", self::CACHE_GROUP);
    }
}
