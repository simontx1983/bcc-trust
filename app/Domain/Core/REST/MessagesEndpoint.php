<?php
/**
 * MessagesEndpoint — handles the §4.X DM REST surface.
 *
 * Routes registered under `/wp-json/bcc/v1/`:
 *
 *   GET    /me/conversations                       — paginated inbox
 *   POST   /me/conversations                       — start new 1-on-1 with {recipient_id, body}
 *   GET    /me/conversations/{id}/messages         — paginated thread (also marks viewed)
 *   POST   /me/conversations/{id}/messages         — append message {body}
 *   POST   /me/conversations/{id}/read             — mark conversation viewed
 *   GET    /me/messages/unread-count               — global header badge
 *
 * Auth: required on every route (anonymous → 401). All gating logic
 * lives inside MessagesService — endpoints are pure REST glue.
 *
 * Cache: `private, no-store` everywhere. DM state is per-viewer and
 * write-heavy; nothing is shared-cache-safe.
 *
 * @package BCC\Trust\Core\REST
 * @since v1.5 (2026-05, BCC messages adapter)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\MessagesService;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MessagesEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/conversations',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$instance, 'listInbox'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'page' => [
                            'required'          => false,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'per_page' => [
                            'required'          => false,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$instance, 'startConversation'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'recipient_id' => [
                            'required'          => true,
                            'type'              => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                        'body' => [
                            'required' => true,
                            'type'     => 'string',
                            // Body sanitisation lives in the service —
                            // we want the same length/strip rules
                            // applied uniformly on every send path.
                        ],
                    ],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/conversations/(?P<id>\d+)/messages',
            [
                [
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => [$instance, 'getThread'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'id'       => self::idArg(),
                        'page'     => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                        'per_page' => ['required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint'],
                    ],
                ],
                [
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => [$instance, 'reply'],
                    'permission_callback' => '__return_true',
                    'args' => [
                        'id'   => self::idArg(),
                        'body' => ['required' => true, 'type' => 'string'],
                    ],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/conversations/(?P<id>\d+)/read',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'markRead'],
                'permission_callback' => '__return_true',
                'args' => ['id' => self::idArg()],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/messages/unread-count',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'unreadCount'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function listInbox(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = (int) get_current_user_id();
        if ($viewerId <= 0) {
            return self::unauth();
        }

        $page    = (int) ($request->get_param('page') ?? 1);
        $perPage = (int) ($request->get_param('per_page') ?? MessagesService::INBOX_PER_PAGE_DEFAULT);

        $result = $this->service()->listInbox($viewerId, $page, $perPage);
        if (isset($result['error'])) {
            return self::errorFromResult($result);
        }

        return self::ok([
            'items'      => $result['items'],
            'pagination' => $result['pagination'],
        ]);
    }

    public function startConversation(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = (int) get_current_user_id();
        if ($viewerId <= 0) {
            return self::unauth();
        }

        $recipientId = (int) ($request->get_param('recipient_id') ?? 0);
        $body        = (string) ($request->get_param('body') ?? '');

        $result = $this->service()->sendMessage($viewerId, $recipientId, null, $body);
        if (isset($result['error'])) {
            return self::errorFromResult($result);
        }
        return self::ok($result);
    }

    public function getThread(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = (int) get_current_user_id();
        if ($viewerId <= 0) {
            return self::unauth();
        }

        $rootMsgId = (int) $request->get_param('id');
        $page      = (int) ($request->get_param('page') ?? 1);
        $perPage   = (int) ($request->get_param('per_page') ?? MessagesService::THREAD_PER_PAGE_DEFAULT);

        $result = $this->service()->getThread($viewerId, $rootMsgId, $page, $perPage);
        if (isset($result['error'])) {
            return self::errorFromResult($result);
        }

        return self::ok([
            'conversation' => $result['conversation'],
            'items'        => $result['items'],
            'pagination'   => $result['pagination'],
        ]);
    }

    public function reply(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = (int) get_current_user_id();
        if ($viewerId <= 0) {
            return self::unauth();
        }

        $rootMsgId = (int) $request->get_param('id');
        $body      = (string) ($request->get_param('body') ?? '');

        $result = $this->service()->sendMessage($viewerId, null, $rootMsgId, $body);
        if (isset($result['error'])) {
            return self::errorFromResult($result);
        }
        return self::ok($result);
    }

    public function markRead(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = (int) get_current_user_id();
        if ($viewerId <= 0) {
            return self::unauth();
        }

        $rootMsgId = (int) $request->get_param('id');
        $result = $this->service()->markRead($viewerId, $rootMsgId);
        if (isset($result['error'])) {
            return self::errorFromResult($result);
        }
        return self::ok($result);
    }

    public function unreadCount(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);
        $viewerId = (int) get_current_user_id();
        if ($viewerId <= 0) {
            return self::unauth();
        }

        $count = $this->service()->getUnreadCount($viewerId);
        return self::ok(['count' => $count]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function service(): MessagesService
    {
        return Plugin::instance()->messagesService();
    }

    /** @return array<string, mixed> */
    private static function idArg(): array
    {
        return [
            'required'          => true,
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ];
    }

    private static function unauth(): WP_REST_Response
    {
        $resp = ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        $resp->header('Cache-Control', 'private, no-store');
        return $resp;
    }

    /**
     * @param mixed $payload
     */
    private static function ok($payload): WP_REST_Response
    {
        $resp = ApiResponse::ok($payload);
        $resp->header('Cache-Control', 'private, no-store');
        return $resp;
    }

    /**
     * Map a service result `['error' => 'bcc_*', 'message' => '...']`
     * to a canonical Envelope error with the right HTTP status.
     *
     * @param array<string, mixed> $result
     */
    private static function errorFromResult(array $result): WP_REST_Response
    {
        /** @var string $code */
        $code    = (string) ($result['error'] ?? 'bcc_internal_error');
        /** @var string $message */
        $message = (string) ($result['message'] ?? 'Something went wrong.');
        $resp = ApiResponse::error($code, $message, self::statusFor($code));
        $resp->header('Cache-Control', 'private, no-store');
        return $resp;
    }

    private static function statusFor(string $code): int
    {
        return match ($code) {
            'bcc_unauthorized'    => 401,
            'bcc_forbidden'       => 403,
            'bcc_not_found'       => 404,
            'bcc_invalid_request' => 400,
            'bcc_rate_limited'    => 429,
            'bcc_unavailable'     => 503,
            'bcc_internal_error'  => 500,
            default               => 500,
        };
    }
}
