<?php
/**
 * MyPushSubscriptionEndpoint — V2 Phase 1 web-push subscription routes.
 *
 * Routes:
 *   - GET    /me/push-subscriptions/vapid-public-key  → returns the
 *            VAPID public key the service worker needs to call
 *            PushManager.subscribe(). Public to authed users only;
 *            keys are non-secret by design but auth gates spam.
 *   - POST   /me/push-subscriptions                   → register a new
 *            subscription. Body matches the standard browser
 *            PushSubscription JSON shape: { endpoint, keys: { p256dh,
 *            auth } } plus an optional `user_agent` string. Side effect:
 *            flips push_master = true on first successful registration.
 *   - DELETE /me/push-subscriptions/{id}              → revoke a single
 *            device. Auth: subscription must belong to current user.
 *
 * Auth: required. Self-only — no admin override surface in V1.
 *
 * VAPID config: read from the wp-config.php constants
 *   - BCC_PUSH_VAPID_PUBLIC_KEY
 *   - BCC_PUSH_VAPID_PRIVATE_KEY
 *   - BCC_PUSH_VAPID_SUBJECT
 *
 * If any are missing, every route returns 503 with a clear error
 * code so the frontend can surface "push isn't configured yet" UX
 * instead of looking like a generic auth/permission failure.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 Phase 1
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Core\Http\SafeHttpClient;
use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\NotificationPrefs;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MyPushSubscriptionEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/push-subscriptions/vapid-public-key',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'getVapidPublicKey'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/push-subscriptions',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'register_'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/push-subscriptions/(?P<id>\d+)',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'revoke'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id' => [
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => static fn($v) => (int) $v,
                    ],
                ],
            ]
        );
    }

    public function getVapidPublicKey(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $publicKey = self::vapidPublicKey();
        if ($publicKey === null) {
            return self::vapidNotConfigured();
        }

        $resp = ApiResponse::ok([
            'public_key' => $publicKey,
        ]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    public function register_(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (self::vapidPublicKey() === null) {
            return self::vapidNotConfigured();
        }

        $endpoint  = self::stringParam($request, 'endpoint');
        $userAgent = self::nullableStringParam($request, 'user_agent');

        $keysRaw = $request->get_param('keys');
        if (!is_array($keysRaw)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Push subscription `keys` object is required.',
                422
            );
        }
        $p256dh = isset($keysRaw['p256dh']) && is_string($keysRaw['p256dh'])
            ? $keysRaw['p256dh']
            : '';
        $auth = isset($keysRaw['auth']) && is_string($keysRaw['auth'])
            ? $keysRaw['auth']
            : '';

        if ($endpoint === '' || $p256dh === '' || $auth === '') {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Push subscription must include `endpoint`, `keys.p256dh`, and `keys.auth`.',
                422
            );
        }

        // Defensive — endpoints come from the browser PushManager and
        // are always https URLs in practice. Reject anything that
        // doesn't parse to a host so the dispatcher never tries to POST
        // to a malformed URL.
        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Push subscription endpoint is not a valid URL.',
                422
            );
        }

        // Length guard — schema column is VARCHAR(500). Realistic
        // endpoints sit at 200–400 chars; anything longer is suspect
        // and would silently truncate at insert.
        if (strlen($endpoint) > 500) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Push subscription endpoint exceeds 500 characters.',
                422
            );
        }

        // SSRF gate (audit HIGH #3). This URL is attacker-controlled and
        // is later POSTed server-side by the push worker (PushDispatcher)
        // through a library HTTP client that bypasses SafeHttpClient.
        // Browser PushManager endpoints are ALWAYS https to a public push
        // service, so require https and reject anything that resolves to a
        // private/reserved/metadata IP. The private-IP definition is reused
        // from SafeHttpClient::validatePublicUrl so it stays in one place
        // (no IP-range logic duplicated here). PushDispatcher re-checks at
        // send time as defence-in-depth for rows stored before this gate.
        if (strtolower((string) wp_parse_url($endpoint, PHP_URL_SCHEME)) !== 'https') {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Push subscription endpoint must be an https URL.',
                422
            );
        }
        if (SafeHttpClient::validatePublicUrl($endpoint) instanceof \WP_Error) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Push subscription endpoint is not an allowed push-service URL.',
                422
            );
        }

        $repo = new PushSubscriptionRepository();
        $id   = $repo->upsert($userId, $endpoint, $p256dh, $auth, $userAgent);

        if ($id <= 0) {
            return ApiResponse::error(
                'bcc_internal_error',
                'Could not save push subscription. Please try again.',
                500
            );
        }

        // First successful registration flips master ON so the
        // dispatcher's isPushEnabled gate passes for this user.
        // Subsequent device registrations are no-ops on the master flag.
        if (!NotificationPrefs::isPushMasterEnabled($userId)) {
            NotificationPrefs::setPushMaster($userId, true);
        }

        $resp = ApiResponse::ok([
            'id'              => $id,
            'master_enabled'  => true,
        ]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    public function revoke(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $idRaw = $request->get_param('id');
        $id    = is_numeric($idRaw) ? (int) $idRaw : 0;
        if ($id <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'Subscription id is required.', 422);
        }

        $repo = new PushSubscriptionRepository();
        $row  = $repo->find($id);
        if ($row === null) {
            // Idempotent — already gone. Treat as success.
            $resp = ApiResponse::ok(['ok' => true]);
            $resp->header('Cache-Control', 'no-store');
            return $resp;
        }

        if ((int) $row->user_id !== $userId) {
            return ApiResponse::error('bcc_forbidden', 'Not your subscription.', 403);
        }

        $repo->delete($id);

        $resp = ApiResponse::ok(['ok' => true]);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    /**
     * Read the VAPID public key from the wp-config.php constant.
     * Returns null when missing — caller decides whether to 503.
     */
    private static function vapidPublicKey(): ?string
    {
        if (!defined('BCC_PUSH_VAPID_PUBLIC_KEY')) {
            return null;
        }
        $value = constant('BCC_PUSH_VAPID_PUBLIC_KEY');
        if (!is_string($value) || $value === '') {
            return null;
        }
        return $value;
    }

    private static function vapidNotConfigured(): WP_REST_Response
    {
        return ApiResponse::error(
            'bcc_push_not_configured',
            'Push notifications are not yet configured on this site. The administrator needs to generate VAPID keys.',
            503
        );
    }

    private static function stringParam(WP_REST_Request $request, string $name): string
    {
        $value = $request->get_param($name);
        if (!is_string($value)) {
            return '';
        }
        return trim($value);
    }

    private static function nullableStringParam(WP_REST_Request $request, string $name): ?string
    {
        $value = $request->get_param($name);
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
