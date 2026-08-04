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
 *       "bcc_card_watched": bool,
 *       "bcc_rank_up":     bool
 *     },
 *     "push": {
 *       "enabled": bool,
 *       "events": {
 *         "review":            bool,
 *         "dispute_outcome":   bool
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
            // Rollout compatibility: a body whose pref keys are ALL on the
            // explicit retired-key allowlist (an older frontend build
            // toggling the retired `bcc_endorse` row against this backend)
            // is a harmless no-op, not an error — return current prefs so
            // the client's read-back stays consistent. Anything else that
            // yielded an empty partial (no pref fields at all, misspelled
            // or arbitrary unknown keys) stays a 422 so client bugs remain
            // visible instead of silently "succeeding".
            if (self::onlyRetiredKeysSubmitted($request)) {
                $resp = ApiResponse::ok(NotificationPrefs::readAll($userId));
                $resp->header('Cache-Control', 'no-store');
                return $resp;
            }
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
     * Pref keys retired by the v1.50 endorse convergence that an older
     * frontend build may still submit. The no-op-200 compatibility path
     * below accepts EXACTLY these — nothing else.
     */
    private const RETIRED_BELL_KEYS       = ['bcc_endorse'];
    private const RETIRED_PUSH_EVENT_KEYS = ['endorse'];

    /**
     * Only called on the empty-partial path (buildPartial recognized
     * nothing), so every key found here is by definition unrecognized.
     * Returns true when at least one key was submitted AND every
     * submitted key is on the explicit retired-key allowlist. A
     * misspelled live key (`bcc_reviw`), arbitrary garbage, or an
     * unknown `push` field therefore still 422s.
     */
    private static function onlyRetiredKeysSubmitted(WP_REST_Request $request): bool
    {
        $submitted = 0;
        $retired   = 0;

        $bellRaw = $request->get_param('bell');
        if (is_array($bellRaw)) {
            foreach (array_keys($bellRaw) as $key) {
                $submitted++;
                if (in_array((string) $key, self::RETIRED_BELL_KEYS, true)) {
                    $retired++;
                }
            }
        }

        $pushRaw = $request->get_param('push');
        if (is_array($pushRaw)) {
            foreach (array_keys($pushRaw) as $key) {
                if ($key === 'enabled') {
                    continue; // recognized key — can't occur on this path
                }
                if ($key === 'events') {
                    if (is_array($pushRaw['events'])) {
                        foreach (array_keys($pushRaw['events']) as $eventKey) {
                            $submitted++;
                            if (in_array((string) $eventKey, self::RETIRED_PUSH_EVENT_KEYS, true)) {
                                $retired++;
                            }
                        }
                    }
                    continue;
                }
                // Unknown top-level push field — counts as submitted,
                // never as retired.
                $submitted++;
            }
        }

        return $submitted > 0 && $submitted === $retired;
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
