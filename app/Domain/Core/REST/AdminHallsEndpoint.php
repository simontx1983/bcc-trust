<?php
/**
 * Admin Halls API (F-597, topic Halls step 3a). Site administrators only.
 *
 *   GET   /bcc/v1/admin/halls       — every published Hall (kind, chain, q filters)
 *   POST  /bcc/v1/admin/halls       — create a topic Hall
 *   PATCH /bcc/v1/admin/halls/{id}  — edit a Hall's name, description or topic label
 *
 * Gated as AdminReportsEndpoint is: BearerAuth::requireAdmin() as the
 * permission_callback, and the same check again in every handler
 * (adminGate()), which also answers in the BCC envelope. Every response is
 * `no-store`. The rules live in AdminHallsService.
 *
 * @package BCC\Trust\Core\REST
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Services\AdminHallsService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\BearerAuth;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class AdminHallsEndpoint
{
    private const ROUTE_NAMESPACE   = 'bcc/v1';
    private const DEFAULT_PAGE_SIZE = 20;
    private const MAX_PAGE_SIZE     = 50;

    /** The fields PATCH accepts. */
    private const EDITABLE = ['name', 'description', 'topic_label'];

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(self::ROUTE_NAMESPACE, '/admin/halls', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'list'],
                'permission_callback' => [BearerAuth::class, 'requireAdmin'],
                'args'                => [
                    'page'      => ['type' => 'integer', 'default' => 1, 'minimum' => 1, 'sanitize_callback' => 'absint'],
                    'page_size' => ['type' => 'integer', 'default' => self::DEFAULT_PAGE_SIZE, 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE, 'sanitize_callback' => 'absint'],
                    // chain | topic; the handler refuses anything else (as GET /halls does).
                    'kind'      => ['sanitize_callback' => 'sanitize_key'],
                    'chain'     => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'q'         => [
                        'type'              => 'string',
                        'maxLength'         => CardsListEndpoint::Q_MAX_LENGTH,
                        'validate_callback' => 'rest_validate_request_arg',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'create'],
                'permission_callback' => [BearerAuth::class, 'requireAdmin'],
                'args'                => [
                    'name'        => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'topic_slug'  => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'topic_label' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'description' => ['type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_textarea_field'],
                ],
            ],
        ]);

        register_rest_route(self::ROUTE_NAMESPACE, '/admin/halls/(?P<id>\d+)', [
            'methods'             => 'PATCH',
            'callback'            => [$instance, 'update'],
            'permission_callback' => [BearerAuth::class, 'requireAdmin'],
            'args'                => [
                'id'          => ['type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint'],
                'name'        => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'topic_label' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                'description' => ['type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field'],
            ],
        ]);
    }

    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $gateError = self::adminGate();
        if ($gateError !== null) {
            return $gateError;
        }

        $kind = HallsEndpoint::hallKind((string) $request->get_param('kind'));
        if ($kind === false) {
            return self::noStore(ApiResponse::error('bcc_invalid_request', 'kind must be one of: ' . implode(', ', HallsEndpoint::HALL_KINDS) . '.', 400));
        }
        $query = (string) $request->get_param('q');
        if (mb_strlen($query) > CardsListEndpoint::Q_MAX_LENGTH) {
            return self::noStore(ApiResponse::error('bcc_invalid_request', sprintf('q must be at most %d characters.', CardsListEndpoint::Q_MAX_LENGTH), 400));
        }
        $chainRaw = $request->get_param('chain');
        $chain    = is_string($chainRaw) && $chainRaw !== '' ? $chainRaw : null;
        $page     = max(1, (int) $request->get_param('page'));
        $pageSize = max(1, min(self::MAX_PAGE_SIZE, (int) ($request->get_param('page_size') ?: self::DEFAULT_PAGE_SIZE)));

        return self::noStore(ApiResponse::ok(self::service()->list($page, $pageSize, $kind, $chain, $query)));
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $gateError = self::adminGate();
        if ($gateError !== null) {
            return $gateError;
        }

        $fields = [];
        foreach (['name', 'topic_slug', 'topic_label', 'description'] as $key) {
            $value = $request->get_param($key);
            if ($value !== null && !is_string($value)) {
                return self::noStore(ApiResponse::error('bcc_invalid_request', "{$key} must be a string.", 400));
            }
            $fields[$key] = (string) $value;
        }

        $result = self::service()->create($fields['name'], $fields['topic_slug'], $fields['topic_label'], $fields['description']);

        [$status, $code] = self::createStatus($result['status'], $result['failure_code']);
        if ($code !== null) {
            $data = $result['status'] === 'exists' ? ['group_id' => $result['group_id']] : [];
            return self::noStore(ApiResponse::error($code, $result['message'], $status, $data));
        }

        return self::noStore(ApiResponse::ok([
            'status'         => $result['status'],
            'message'        => $result['message'],
            'hall'           => $result['hall'],
            'audit_degraded' => $result['audit_degraded'],
        ], $status));
    }

    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $gateError = self::adminGate();
        if ($gateError !== null) {
            return $gateError;
        }

        $changes = [];
        foreach (self::EDITABLE as $key) {
            $value = $request->get_param($key);
            if ($value === null) {
                continue;
            }
            if (!is_string($value)) {
                return self::noStore(ApiResponse::error('bcc_invalid_request', "{$key} must be a string.", 400));
            }
            $changes[$key] = $value;
        }

        $result = self::service()->update((int) $request->get_param('id'), $changes, get_current_user_id());
        if (isset($result['error'])) {
            return self::noStore(ApiResponse::error($result['error'], $result['message'], self::statusForCode($result['error'])));
        }

        return self::noStore(ApiResponse::ok($result));
    }

    /**
     * A create result's HTTP status, and its error code (null on success).
     *
     * @return array{0: int, 1: string|null}
     */
    public static function createStatus(string $status, ?string $failureCode): array
    {
        return match ($status) {
            'created', 'incomplete' => [201, null],
            'exists', 'busy'        => [409, 'bcc_conflict'],
            'skipped'               => $failureCode === \BCC\Trust\Onchain\Services\HallProvisioningService::FAIL_SLUG_TAKEN
                ? [409, 'bcc_conflict']
                : [400, 'bcc_invalid_request'],
            default                 => [503, 'bcc_unavailable'],
        };
    }

    private static function statusForCode(string $code): int
    {
        return match ($code) {
            'bcc_unauthorized'    => 401,
            'bcc_forbidden'       => 403,
            'bcc_not_found'       => 404,
            'bcc_invalid_request' => 400,
            'bcc_conflict'        => 409,
            default               => 503,
        };
    }

    private static function service(): AdminHallsService
    {
        return new AdminHallsService();
    }

    private static function adminGate(): ?WP_REST_Response
    {
        if (get_current_user_id() <= 0) {
            return self::noStore(ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401));
        }
        if (!BearerAuth::requireAdmin()) {
            return self::noStore(ApiResponse::error('bcc_forbidden', 'Admin access required.', 403));
        }

        return null;
    }

    private static function noStore(WP_REST_Response $response): WP_REST_Response
    {
        $response->header('Cache-Control', 'no-store');
        return $response;
    }
}
