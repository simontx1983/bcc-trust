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
 *   enabled (chatmodel.php bootstraps the row to "1" on first read).
 *   Storing "0" is the OFF signal.
 * - `peepso_chat_friends_only` = "1" restricts incoming chats to
 *   confirmed PeepSo friends; "0" allows anyone. MISSING meta does NOT
 *   mean "0": chatmodel.php falls back to the site option
 *   `messages_friends_only`, which PeepSo defaults to 1. Reads MUST go
 *   through PeepSoChatModel (or mirror its chain) — a hardcoded false
 *   default here once made the settings UI show OFF while the
 *   MessagesService send gate enforced friends-only.
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
     * Read both flags exactly as the send gate will enforce them.
     *
     * PeepSoChatModel is the single source of truth — it owns the
     * missing-meta default chains (chat_enabled bootstraps to "1";
     * chat_friends_only falls back to the `messages_friends_only`
     * site option, PeepSo default 1). MessagesService gates read the
     * same model, so what this endpoint reports is what sends hit.
     * The class_exists fallbacks mirror those chains for contexts
     * where peepso-messages hasn't registered its autoload dir
     * (WP-CLI; peepso_init doesn't fire there).
     *
     * @return array{chat_enabled: bool, chat_friends_only: bool}
     */
    private static function readAll(int $userId): array
    {
        return [
            'chat_enabled'      => self::chatEnabled($userId),
            'chat_friends_only' => self::chatFriendsOnly($userId),
        ];
    }

    private static function chatEnabled(int $userId): bool
    {
        if (class_exists('PeepSoChatModel')) {
            return (bool) \PeepSoChatModel::chat_enabled($userId);
        }
        $raw = get_user_meta($userId, 'peepso_chat_enabled', true);
        if (!is_string($raw) || $raw === '') {
            return true;
        }
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }

    private static function chatFriendsOnly(int $userId): bool
    {
        if (class_exists('PeepSoChatModel')) {
            return (bool) \PeepSoChatModel::chat_friends_only($userId);
        }
        $raw = get_user_meta($userId, 'peepso_chat_friends_only', true);
        if (!is_string($raw) || $raw === '') {
            return class_exists('PeepSo')
                ? (int) \PeepSo::get_option('messages_friends_only', 1) === 1
                : true;
        }
        return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
    }
}
