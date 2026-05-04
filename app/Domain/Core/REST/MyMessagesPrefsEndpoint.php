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
 * - `peepso_chat_enabled` = "1" enables chat; missing or anything else
 *   means PeepSo defaults the user to chat-enabled (bootstraps to "1"
 *   on first read per chatmodel.php). Storing "0" is the OFF signal.
 * - `peepso_chat_friends_only` = "1" restricts incoming chats to
 *   confirmed PeepSo friends. Missing or "0" allows anyone.
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
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MyMessagesPrefsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Default for `peepso_chat_enabled` when no row exists. */
    private const DEFAULT_CHAT_ENABLED = true;

    /** Default for `peepso_chat_friends_only` when no row exists. */
    private const DEFAULT_CHAT_FRIENDS_ONLY = false;

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

        $resp = ApiResponse::ok(self::readAll($userId));
        $resp->header('Cache-Control', 'no-store');
        return $resp;
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

        $touched = false;

        $chatEnabled = $request->get_param('chat_enabled');
        if ($chatEnabled !== null) {
            $value = filter_var($chatEnabled, FILTER_VALIDATE_BOOLEAN);
            update_user_meta($userId, 'peepso_chat_enabled', $value ? '1' : '0');
            $touched = true;
        }

        $chatFriendsOnly = $request->get_param('chat_friends_only');
        if ($chatFriendsOnly !== null) {
            $value = filter_var($chatFriendsOnly, FILTER_VALIDATE_BOOLEAN);
            update_user_meta($userId, 'peepso_chat_friends_only', $value ? '1' : '0');
            $touched = true;
        }

        if (!$touched) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'No messages preferences provided.',
                422
            );
        }

        $resp = ApiResponse::ok(self::readAll($userId));
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    /**
     * Read both flags + apply per-flag defaults for missing user_meta rows.
     *
     * @return array{chat_enabled: bool, chat_friends_only: bool}
     */
    private static function readAll(int $userId): array
    {
        return [
            'chat_enabled'      => self::flag($userId, 'peepso_chat_enabled', self::DEFAULT_CHAT_ENABLED),
            'chat_friends_only' => self::flag($userId, 'peepso_chat_friends_only', self::DEFAULT_CHAT_FRIENDS_ONLY),
        ];
    }

    private static function flag(int $userId, string $key, bool $default): bool
    {
        $raw = get_user_meta($userId, $key, true);
        if (!is_string($raw) || $raw === '') {
            return $default;
        }
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}
