<?php
/**
 * RankScheduler — cron wiring for the Rank data planes.
 *
 * DisputeScheduler pattern: idempotent schedule() via wp_next_scheduled,
 * self-healing boot() on every request, heartbeat options split into
 * last_run (entered the body) vs last_success (completed) so health
 * surfaces never mistake "ran" for "succeeded".
 *
 * Hook is registered in includes/cron-hooks.php (the canonical registry
 * the drift detector, deactivation, and uninstall all consume).
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign Phase 1 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

if (!defined('ABSPATH')) {
    exit;
}

final class RankScheduler
{
    public const EVENT_DAILY_TIER_SNAPSHOT = 'bcc_rank_daily_tier_snapshot';

    public static function schedule(): void
    {
        if (!wp_next_scheduled(self::EVENT_DAILY_TIER_SNAPSHOT)) {
            wp_schedule_event(time(), 'daily', self::EVENT_DAILY_TIER_SNAPSHOT);
        }
    }

    public static function unschedule(): void
    {
        wp_clear_scheduled_hook(self::EVENT_DAILY_TIER_SNAPSHOT);
    }

    public static function boot(): void
    {
        add_action(self::EVENT_DAILY_TIER_SNAPSHOT, [__CLASS__, 'runDailyTierSnapshot']);

        // Self-healing: recreate the scheduled event if it was deleted or
        // never registered. schedule() is idempotent via wp_next_scheduled
        // so this is safe on every request (DisputeScheduler pattern).
        self::schedule();
    }

    public static function runDailyTierSnapshot(): void
    {
        update_option('bcc_rank_tier_snapshot_last_run', time(), false);

        try {
            \BCC\Trust\Core\Plugin::instance()->tierSnapshotService()->runDailySnapshot();
            update_option('bcc_rank_tier_snapshot_last_success', time(), false);
        } catch (\Throwable $e) {
            \BCC\Core\Observability\DegradationMetrics::record('rank_scoring', 'tier_snapshot_failed');
            \BCC\Core\Log\Logger::error('[bcc-trust] rank tier snapshot failed', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
            ]);
        }
    }
}
