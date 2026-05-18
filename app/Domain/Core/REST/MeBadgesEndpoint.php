<?php
/**
 * MeBadgesEndpoint — coalesced per-viewer badge payload.
 *
 *   GET /wp-json/bcc/v1/me/badges?open_threads=12,47
 *
 * One endpoint replaces three previously-uncached polling endpoints
 * (/me/messages/unread-count + /me/notifications/unread-count +
 * /me/conversations/{id}/messages polled every 5s). The frontend's
 * `useBadges` hook drives both header badges AND tells open
 * conversation views when their thread has moved forward, so the
 * conversation-detail query only fires when `latest_message_id`
 * advances.
 *
 * Cache posture:
 *   - Cache-Control: private, max-age=10 (per-viewer browser-side
 *     dedupe; safe because the payload is user-specific)
 *   - Server-side: BadgesService caches the composed payload for 15s
 *     via the §5 generation-counter pattern, invalidated on bell-row
 *     creation, mark-read, DM send, and DM view.
 *
 * Auth: required. Anonymous → 401 envelope.
 *
 * @package BCC\Trust\Core\REST
 * @since v2 (2026-05, polling-coalesce)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\BadgesService;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MeBadgesEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const ROUTE_PATH      = '/me/badges';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            self::ROUTE_PATH,
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'get'],
                'permission_callback' => '__return_true',
                'args' => [
                    'open_threads' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    public function get(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = (int) get_current_user_id();
        if ($viewerId <= 0) {
            $resp = ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
            $resp->header('Cache-Control', 'private, no-store');
            return $resp;
        }

        $openThreads = self::parseOpenThreads((string) ($request->get_param('open_threads') ?? ''));

        $payload = Plugin::instance()->badgesService()->getBadges($viewerId, $openThreads);

        $resp = ApiResponse::ok($payload);
        // Browser-side dedupe so a tab that fires two badge requests
        // within 10s (e.g. visibility change + interval tick) gets one
        // network hit. The server-side 15s cache catches the rest.
        $resp->header('Cache-Control', 'private, max-age=10');
        return $resp;
    }

    /**
     * Parse the `open_threads` query param into a bounded list of ints.
     * Format: comma-separated decimal integers; non-numeric tokens are
     * dropped silently. Server-side cap enforced by BadgesService.
     *
     * @return list<int>
     */
    private static function parseOpenThreads(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $tokens = explode(',', $raw);
        $out = [];
        foreach ($tokens as $tok) {
            $tok = trim($tok);
            if ($tok === '' || !ctype_digit($tok)) {
                continue;
            }
            $i = (int) $tok;
            if ($i > 0) {
                $out[] = $i;
            }
            if (count($out) >= BadgesService::OPEN_THREADS_MAX) {
                break;
            }
        }
        return $out;
    }
}
