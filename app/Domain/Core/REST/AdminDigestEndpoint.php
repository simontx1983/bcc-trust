<?php
/**
 * AdminDigestEndpoint — admin-triggered manual run of the §I1 weekly
 * digest. Lets admins fire the cron handler from a browser session
 * without WP-CLI access (smoke-test convenience + post-incident
 * recovery when the scheduled run was missed).
 *
 * Route:
 *   - POST /admin/digest/run-now
 *
 * Auth:
 *   - Bearer token required (matches the rest of the admin surface)
 *   - Capability gate `manage_options` — V1 admins only
 *
 * Behaviour:
 *   - Synchronously invokes DigestService::sendWeeklyDigest()
 *   - Returns the count of emails sent
 *   - Cap of one run per 5 minutes (per-instance throttle) so a
 *     refresh-spam doesn't blow up wp_mail
 *
 * NOT a cron replacement — the scheduled `bcc_trust_weekly_digest`
 * hook still fires weekly. This endpoint is for ad-hoc validation
 * and recovery only.
 *
 * @package BCC\Trust\Core\REST
 * @since V1.5 (§I1 admin digest trigger)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminDigestEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Min seconds between manual runs across the whole instance. */
    private const RUN_COOLDOWN_SECONDS = 300;

    /** wp_options key for last-run timestamp. */
    private const COOLDOWN_OPTION = 'bcc_admin_digest_last_run';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/admin/digest/run-now',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'runNow'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function runNow(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $gate = self::adminGate();
        if ($gate !== null) {
            return $gate;
        }

        $cooldownGate = self::cooldownGate();
        if ($cooldownGate !== null) {
            return $cooldownGate;
        }

        $sent = Plugin::instance()->digestService()->sendWeeklyDigest();
        update_option(self::COOLDOWN_OPTION, time(), false);

        $resp = ApiResponse::ok([
            'ok'    => true,
            'sent'  => $sent,
            'note'  => $sent === 0
                ? 'No email-digest opt-ins found, or no unread notifications in the past week.'
                : sprintf('Dispatched %d digest %s.', $sent, $sent === 1 ? 'email' : 'emails'),
        ]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    private static function adminGate(): ?WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }
        if (!current_user_can('manage_options')) {
            return ApiResponse::error('bcc_forbidden', 'Admin access required.', 403);
        }
        return null;
    }

    /**
     * Reject runs within RUN_COOLDOWN_SECONDS of the previous one. The
     * weekly digest is meant to fire weekly — running it 12 times an
     * hour is almost always a stuck refresh, not a real intent.
     */
    private static function cooldownGate(): ?WP_REST_Response
    {
        $lastRunRaw = get_option(self::COOLDOWN_OPTION, 0);
        $lastRun = is_numeric($lastRunRaw) ? (int) $lastRunRaw : 0;
        $elapsed = time() - $lastRun;

        if ($lastRun > 0 && $elapsed < self::RUN_COOLDOWN_SECONDS) {
            $waitSeconds = self::RUN_COOLDOWN_SECONDS - $elapsed;
            return ApiResponse::error(
                'bcc_rate_limited',
                sprintf(
                    'Manual digest was just run. Wait %d seconds before retrying.',
                    $waitSeconds
                ),
                429
            );
        }
        return null;
    }
}
