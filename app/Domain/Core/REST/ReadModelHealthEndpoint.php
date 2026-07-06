<?php
/**
 * Read Model Health Endpoint
 *
 * Exposes read model health metrics for monitoring dashboards.
 * Admin-only. Returns coverage %, fallback count, staleness, sync status.
 *
 * Route: GET /bcc-trust/v1/health/read-model
 *
 * @package BCC\Trust\Core\REST
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class ReadModelHealthEndpoint
{
    /**
     * Register REST route.
     */
    public static function register(): void
    {
        register_rest_route('bcc-trust/v1', '/health/read-model', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [self::class, 'handle'],
            'permission_callback' => function () {
                return current_user_can('manage_options');
            },
        ]);
    }

    /**
     * Handle GET /bcc-trust/v1/health/read-model
     */
    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        $repo = Plugin::instance()->readModelHealthRepository();

        // 1. Total published pages (peepso-page post type).
        $total_pages = $repo->countPublishedPages();

        // 2. Pages with read model rows.
        $rm_count = $repo->countReadModelRows();

        // 3. Coverage percentage.
        $coverage_pct = $total_pages > 0
            ? round(($rm_count / $total_pages) * 100, 1)
            : 0.0;

        // 4. Pages with scores but NO read model row (gap).
        $gap_count = $repo->countGapPages(1000);

        // 5. Staleness: oldest updated_at in the read model.
        $oldest_update = $repo->getOldestUpdate();
        $newest_update = $repo->getNewestUpdate();

        // 5b. Live lag: seconds since the oldest pending dirty-queue row and
        // the number of pages currently waiting to sync. Answers "how stale
        // could any read-model value be RIGHT NOW?"
        $lag_seconds     = $repo->getDirtyQueueLagSeconds();
        $dirty_queue_len = $repo->getDirtyQueueSize();

        // 6. Read-model fallback counter from object cache. Retained for
        // back-compat; the former PageDataAggregator writer was removed with
        // the FSE read path, so this reads 0 unless another path sets it.
        $fallback_count = (int) \wp_cache_get('rm_fallback_count', 'bcc_page_rm');

        // 7. Pages where read model trust_score diverges from scores table by > 1 point.
        $drift_count = $repo->countDriftedPages(100);

        // 8. Sample divergent pages (top 5 by drift).
        $drift_samples = $repo->getDriftSamples(5);

        // Lag thresholds (seconds). Filterable so ops can tune.
        $lag_warn_threshold     = (int) \apply_filters('bcc_trust_rm_lag_warn_seconds', 60);
        $lag_critical_threshold = (int) \apply_filters('bcc_trust_rm_lag_critical_seconds', 300);

        // Compute overall status.
        $status = 'healthy';
        if ($coverage_pct < 80 || $drift_count > 0 || $lag_seconds >= $lag_critical_threshold) {
            $status = 'critical';
        } elseif ($coverage_pct < 95 || $gap_count > 0 || $lag_seconds >= $lag_warn_threshold) {
            $status = 'degraded';
        }

        $response = \rest_ensure_response([
            'status'    => $status,
            'coverage'  => [
                'total_pages'    => $total_pages,
                'rm_rows'        => $rm_count,
                'coverage_pct'   => $coverage_pct,
                'gap_count'      => $gap_count,
            ],
            'freshness' => [
                'oldest_update'    => $oldest_update,
                'newest_update'    => $newest_update,
                'lag_seconds'      => $lag_seconds,
                'dirty_queue_size' => $dirty_queue_len,
                'thresholds'       => [
                    'warn_seconds'     => $lag_warn_threshold,
                    'critical_seconds' => $lag_critical_threshold,
                ],
            ],
            'fallbacks' => [
                'count_last_hour' => $fallback_count,
            ],
            'drift'     => [
                'divergent_pages' => $drift_count,
                'samples'         => $drift_samples,
            ],
        ]);

        // Let external monitors alert on HTTP status directly.
        if ($status === 'critical') {
            $response->set_status(503);
        }

        return $response;
    }
}
