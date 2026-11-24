<?php
/**
 * MyMessagesPrefsEndpoint — handles /bcc/v1/me/messages-prefs.
 *
 * Two routes for the user's PeepSo-backed messaging preferences:
 *
 *   GET   /me/messages-prefs   — read current values (with defaults applied)
 *   PATCH /me/messages-prefs   — partial update; missing keys untouched
 *
 * Body shape (PATCH) — every field optional:
 *   {
 *     "chat_enabled":      bool,
 *     "chat_friends_only": bool
 *   }
 *
 * Response shape (both verbs):
 *   {
 *     "chat_enabled":      bool,
 *     "chat_friends_only": bool
 *   }
 *
 * Storage: PeepSo's `peepso_chat_enabled` + `peepso_chat_friends_only`
 * user_meta keys, read by `peepso-messages/classes/chatmodel.php`.
 *
 * - `peepso_chat_enabled` = "1" enables chat; missing meta defaults to
 *   enabled. Storing "0" is the OFF signal.
 * - `peepso_chat_friends_only` = "1" restricts incoming chats to
 *   confirmed PeepSo friends; "0" allows anyone. MISSING meta does NOT
 *   mean "0": chatmodel.php falls back to the site option
 *   `messages_friends_only`, which PeepSo defaults to 1. Reads MUST mirror
 *   PeepSoChatModel's chain — a hardcoded false default here once made the
 *   settings UI show OFF while the MessagesService send gate enforced
 *   friends-only.
 *
 * ⚠⚠ H6: reads go through PeepSoChatPrefs, the SAME reader as the
 * MessagesService gates, and never through PeepSoChatModel::chat_enabled().
 * That one bootstraps a missing row by writing "1", and a failed meta read
 * also looks missing; this endpoint never writes on a read. A read that
 * fails is a retryable `bcc_unavailable` 503, never a default.
 *
 * Auth: required. Self-only — no admin override surface.
 *
 * Cache: `no-store`.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 Phase 2 (Messages settings)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\PeepSoChatPrefs;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MyMessagesPrefsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/messages-prefs',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'get'],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/messages-prefs',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$instance, 'patch'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function get(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        return self::respondWith($userId);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function patch(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Per-ACCOUNT limit on cheap self-scoped writes (F-134).
        if (!\BCC\Core\Security\Throttle::allow('me_write', \BCC\Trust\Core\REST\Auth\AuthSupport::ME_WRITE_RATE_LIMIT, 60, 'me_write:' . $userId)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Please slow down.', 429);
        }

        // F-294: validate BOTH switches before writing either. A value that
        // is not a boolean is a 422; a bare FILTER_VALIDATE_BOOLEAN used to
        // read it as false and switch the setting off with a 200.
        $writes = [];
        foreach (['chat_enabled' => 'peepso_chat_enabled', 'chat_friends_only' => 'peepso_chat_friends_only'] as $param => $metaKey) {
            $raw = $request->get_param($param);
            if ($raw === null) {
                continue;
            }
            $bool = \BCC\Trust\Core\Support\StrictBoolParam::parse($raw);
            if ($bool === null) {
                return ApiResponse::error(
                    'bcc_invalid_request',
                    sprintf('`%s` must be true or false.', $param),
                    422
                );
            }
            $writes[$metaKey] = $bool ? '1' : '0';
        }

        foreach ($writes as $metaKey => $value) {
            update_user_meta($userId, $metaKey, $value);
        }
        $touched = $writes !== [];

        if (!$touched) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'No messages preferences provided.',
                422
            );
        }

        // Fresh: the echo must be the stored values, not a memo of the read
        // before this write (the memo is also dropped on the write's hook).
        return self::respondWith($userId, true);
    }

    /**
     * Both flags exactly as the send gate will enforce them: PeepSoChatPrefs
     * is the one reader behind this endpoint AND the MessagesService gates,
     * with PeepSoChatModel's default chains (chat_enabled: missing is "on";
     * chat_friends_only: missing is the `messages_friends_only` site
     * option, PeepSo default 1). So what this endpoint reports is what
     * sends hit.
     *
     * H6: a read that fails is a retryable 503, never a default. For the
     * PATCH, the write has already run; the member retries and the same
     * values are written again.
     */
    private static function respondWith(int $userId, bool $fresh = false): WP_REST_Response
    {
        $prefs = PeepSoChatPrefs::forUser($userId, $fresh);
        if ($prefs === null) {
            return ApiResponse::error(
                'bcc_unavailable',
                'Could not read your messaging preferences. Please try again.',
                503
            );
        }

        $resp = ApiResponse::ok($prefs);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }
}
