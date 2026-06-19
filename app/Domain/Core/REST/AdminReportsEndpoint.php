<?php
/**
 * AdminReportsEndpoint — handles /bcc/v1/admin/reports routes (§K1 Phase C).
 *
 * Routes:
 *   - GET  /admin/reports                — paginated queue (status filter)
 *   - POST /admin/reports/:id/resolve    — apply hide / dismiss / restore
 *
 * Auth:
 *   - Bearer token required (any auth pipeline) — same as the rest of
 *     the BCC API surface.
 *   - Capability gate `manage_options` (V1 = admins only). Elite-tier
 *     community-mod role is V1.5 work.
 *
 * Cache: `no-store` on both routes — admin queue mutates frequently.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §K1 Phase C)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\ModerationQueueService;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminReportsEndpoint
{
    private const ROUTE_NAMESPACE   = 'bcc/v1';
    private const DEFAULT_PER_PAGE  = 20;
    private const MAX_PER_PAGE      = 50;

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/admin/reports',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'list'],
                'permission_callback' => [\BCC\Trust\Core\Support\BearerAuth::class, 'requireAdmin'],
                'args' => [
                    'status' => [
                        'required'          => false,
                        'type'              => 'string',
                        // 'pending'|'resolved'|'dismissed'|'all' — service
                        // maps to the int status. 'all' is the default.
                        'enum'              => ['pending', 'resolved', 'dismissed', 'all'],
                        'default'           => 'pending',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'page' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => 1,
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'per_page' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => self::DEFAULT_PER_PAGE,
                        'minimum'           => 1,
                        'maximum'           => self::MAX_PER_PAGE,
                        'sanitize_callback' => 'absint',
                    ],
                    // ── Filter args (contract extension; response shape unchanged) ──
                    'reason' => [
                        'required'          => false,
                        'type'              => 'string',
                        'enum'              => ['spam', 'harassment', 'hate', 'violence', 'misinformation', 'other'],
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'reporter_handle' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'post_kind' => [
                        'required'          => false,
                        'type'              => 'string',
                        'enum'              => ['status', 'blog', 'review', 'photo', 'gif'],
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'since' => [
                        'required'          => false,
                        'type'              => 'string',
                        'format'            => 'date-time',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'until' => [
                        'required'          => false,
                        'type'              => 'string',
                        'format'            => 'date-time',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/admin/reports/(?P<id>\d+)/resolve',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'resolve'],
                'permission_callback' => [\BCC\Trust\Core\Support\BearerAuth::class, 'requireAdmin'],
                'args' => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'sanitize_callback' => 'absint',
                    ],
                    'action' => [
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => [
                            ModerationQueueService::ACTION_HIDE,
                            ModerationQueueService::ACTION_DISMISS,
                            ModerationQueueService::ACTION_RESTORE,
                        ],
                        'sanitize_callback' => 'sanitize_key',
                    ],
                ],
            ]
        );

        // §K1 moderation recovery affordance — see pattern-registry.md
        // "Moderation recovery affordances". Single 30-second window
        // tied to a server-issued token. NOT a generalised /admin/undo
        // surface: belongs to this endpoint specifically because only
        // the resolve flow issues tokens.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/admin/reports/undo',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'undo'],
                'permission_callback' => [\BCC\Trust\Core\Support\BearerAuth::class, 'requireAdmin'],
                'args' => [
                    'token' => [
                        'required'          => true,
                        'type'              => 'string',
                        // 32-char hex from bin2hex(random_bytes(16)).
                        // Bounded validation here is belt-and-suspenders
                        // — the service rejects anything not matching
                        // the same shape.
                        'pattern'           => '^[a-f0-9]{32}$',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $gateError = self::adminGate();
        if ($gateError !== null) {
            return $gateError;
        }

        $statusParam = (string) ($request->get_param('status') ?? 'pending');
        $statusInt   = self::statusParamToInt($statusParam);

        $page    = (int) $request->get_param('page');
        $perPage = (int) $request->get_param('per_page');
        if ($page < 1) {
            $page = 1;
        }
        if ($perPage < 1) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        $filters = [
            'reason'          => self::optionalString($request->get_param('reason')),
            'reporter_handle' => self::optionalString($request->get_param('reporter_handle')),
            'post_kind'       => self::optionalString($request->get_param('post_kind')),
            'since'           => self::optionalString($request->get_param('since')),
            'until'           => self::optionalString($request->get_param('until')),
        ];

        $payload = Plugin::instance()->moderationQueueService()->getQueue($statusInt, $page, $perPage, $filters);

        $resp = ApiResponse::ok($payload);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    public function resolve(WP_REST_Request $request): WP_REST_Response
    {
        $gateError = self::adminGate();
        if ($gateError !== null) {
            return $gateError;
        }

        $reportId = (int) $request->get_param('id');
        $action   = (string) $request->get_param('action');
        $viewerId = get_current_user_id();

        $result = Plugin::instance()->moderationQueueService()->resolve($reportId, $action, $viewerId);
        if (isset($result['error'])) {
            $code   = (string) $result['error'];
            $status = self::statusForCode($code);
            return ApiResponse::error($code, (string) ($result['message'] ?? ''), $status);
        }

        $resp = ApiResponse::ok($result);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    /**
     * Reverse the moderator's most recent resolve action within the
     * 30-second window. The token returned by the prior `resolve()`
     * call is the entire authorisation envelope — see service docs
     * and pattern-registry.md "Moderation recovery affordances".
     *
     * Fails closed on missing/expired/forbidden/stale-state.
     */
    public function undo(WP_REST_Request $request): WP_REST_Response
    {
        $gateError = self::adminGate();
        if ($gateError !== null) {
            return $gateError;
        }

        $token    = (string) $request->get_param('token');
        $viewerId = get_current_user_id();

        $result = Plugin::instance()->moderationQueueService()->undo($token, $viewerId);
        if (isset($result['error'])) {
            $code   = (string) $result['error'];
            $status = self::statusForCode($code);
            return ApiResponse::error($code, (string) ($result['message'] ?? ''), $status);
        }

        $resp = ApiResponse::ok($result);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    /**
     * Capability gate. Returns an error response when the viewer can't
     * access the admin surface; null when they can proceed.
     *
     * V1 = `manage_options` (admins). V1.5 will widen this to the
     * elite-tier mod role per §K1.
     */
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
     * Convert the URL-friendly status string to the int the service +
     * repo speak. 'all' returns null (no filter).
     */
    private static function statusParamToInt(string $param): ?int
    {
        return match ($param) {
            'pending'   => ModerationQueueService::STATUS_PENDING,
            'resolved'  => ModerationQueueService::STATUS_RESOLVED,
            'dismissed' => ModerationQueueService::STATUS_DISMISSED,
            default     => null, // 'all' or any unknown -> no filter
        };
    }

    /**
     * Coerce an optional REST query param to either a non-empty string
     * or null. Treats empty/whitespace strings as "absent" so the
     * service doesn't need to second-guess `?reason=` (no value).
     */
    private static function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private static function statusForCode(string $code): int
    {
        return match ($code) {
            'bcc_unauthorized'      => 401,
            'bcc_forbidden'         => 403,
            'bcc_undo_forbidden'    => 403,
            'bcc_not_found'         => 404,
            'bcc_invalid_request'   => 400,
            'bcc_undo_expired'      => 410, // Gone — accurately describes a consumed/expired token
            'bcc_undo_already_used' => 410, // (Currently folded into _expired by the service for parity.)
            'bcc_undo_stale_state'  => 409, // Conflict — another moderator's action invalidated the token
            'bcc_unavailable'       => 503,
            default                 => 400,
        };
    }
}
