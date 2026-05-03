<?php
/**
 * MyNotificationPrefsEndpoint — handles /bcc/v1/me/notification-prefs.
 *
 * Routes:
 *   - GET   /me/notification-prefs   — read all prefs (defaults applied)
 *   - PATCH /me/notification-prefs   — partial update; missing keys untouched
 *
 * Body shape (PATCH) — every field optional:
 *   {
 *     "email_digest": bool,
 *     "bell": {
 *       "bcc_reaction":    bool,
 *       "bcc_review":      bool,
 *       "bcc_card_pulled": bool,
 *       "bcc_rank_up":     bool,
 *       "bcc_endorse":     bool
 *     },
 *     "push": {
 *       "enabled": bool,
 *       "events": {
 *         "review":            bool,
 *         "endorse":           bool,
 *         "dispute_outcome":   bool,
 *         "panelist_selected": bool
 *       }
 *     }
 *   }
 *
 * Response shape (both verbs): the full prefs tree, with per-flag
 * defaults applied for keys with no usermeta row yet. The frontend
 * receives the same shape on read + write so a successful PATCH
 * doubles as a refetch.
 *
 * Auth: required. Self-only (no admin override surface in V1).
 *
 * Cache: `no-store`. Per-viewer state with no shared-cache value.
 *
 * @package BCC\Trust\Core\REST
 * @since V1.5 (§I1 notification preferences)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\NotificationPrefs;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MyNotificationPrefsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/notification-prefs',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'get'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/notification-prefs',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$instance, 'patch'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function get(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $resp = ApiResponse::ok(NotificationPrefs::readAll($userId));
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    public function patch(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $partial = self::buildPartial($request);

        if ($partial === []) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'No notification keys provided.',
                422
            );
        }

        NotificationPrefs::writePartial($userId, $partial);

        // V2 Phase 1 acceptance criterion #10: flipping push.enabled to
        // false at the server cascades into deleting every registered
        // subscription for this user. The frontend should also revoke
        // the browser-side subscription via PushManager.unsubscribe()
        // — this cascade is the safety net if it doesn't.
        if (
            isset($partial['push'])
            && array_key_exists('enabled', $partial['push'])
            && $partial['push']['enabled'] === false
        ) {
            (new PushSubscriptionRepository())->deleteAllForUser($userId);
        }

        $resp = ApiResponse::ok(NotificationPrefs::readAll($userId));
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    /**
     * Manual nested-object validation. WP REST's schema layer doesn't
     * cleanly handle a nested `bell` / `push` object so we walk the
     * body ourselves: only known keys are forwarded; types are coerced
     * via FILTER_VALIDATE_BOOLEAN; everything else is dropped.
     *
     * @return array{
     *   email_digest?: bool,
     *   bell?: array<string, bool>,
     *   push?: array{enabled?: bool, events?: array<string, bool>}
     * }
     */
    private static function buildPartial(WP_REST_Request $request): array
    {
        $partial = [];

        $emailDigest = $request->get_param('email_digest');
        if ($emailDigest !== null) {
            $partial['email_digest'] = filter_var($emailDigest, FILTER_VALIDATE_BOOLEAN);
        }

        $bellRaw = $request->get_param('bell');
        if (is_array($bellRaw)) {
            $bell = [];
            foreach (NotificationPrefs::BELL_TYPES as $type) {
                if (!array_key_exists($type, $bellRaw)) {
                    continue;
                }
                $bell[$type] = filter_var($bellRaw[$type], FILTER_VALIDATE_BOOLEAN);
            }
            if ($bell !== []) {
                $partial['bell'] = $bell;
            }
        }

        $pushRaw = $request->get_param('push');
        if (is_array($pushRaw)) {
            $push = [];
            if (array_key_exists('enabled', $pushRaw)) {
                $push['enabled'] = filter_var($pushRaw['enabled'], FILTER_VALIDATE_BOOLEAN);
            }
            if (isset($pushRaw['events']) && is_array($pushRaw['events'])) {
                $events = [];
                foreach (NotificationPrefs::PUSH_TYPES as $type) {
                    if (!array_key_exists($type, $pushRaw['events'])) {
                        continue;
                    }
                    $events[$type] = filter_var($pushRaw['events'][$type], FILTER_VALIDATE_BOOLEAN);
                }
                if ($events !== []) {
                    $push['events'] = $events;
                }
            }
            if ($push !== []) {
                $partial['push'] = $push;
            }
        }

        return $partial;
    }
}
