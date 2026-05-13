<?php
/**
 * EntityAttestationsEndpoint — handles
 *   GET /bcc/v1/entities/{target_kind}/{target_id}/attestations  (§4.20 §J.4)
 *
 * THE primary read surface for the V2 Trust Attestation Layer roster.
 * Returns paged + sorted attestation rows for an entity (user_profile,
 * validator_card, project_card, creator_card) with the locked §J.4
 * shape: items[] + summary + pagination.
 *
 * Auth-OPTIONAL: anonymous viewers may read entity rosters. The view-
 * model carries no per-viewer state per §J.4 (third-party rows surface
 * the same fields for everyone). Caching follows the GroupMembersEndpoint
 * convention: anon → public/max-age=30 (matching the §J.4 30-second
 * generation-counter invalidation window); authed → private/no-store
 * so a future per-viewer field addition can't silently leak through a
 * stale shared cache.
 *
 * §J.4.1 synthesis invisibility: per-attestation `weight_at_time` and
 * `decayed_weight` are stripped at the service layer (AttestationService::
 * getForTarget). The server uses them for ORDER BY; the wire never sees
 * the number. Validated in api-contract guard.
 *
 * §J.3.2 asymmetric display: row attestors carry only the categorical
 * `reliability_standing` enum + the positive-only `badges[]` catalogue.
 * No numeric reliability surfaces, no negative-tier badges.
 *
 * Pagination: page + per_page envelope per §J.4. PER_PAGE_MAX=50,
 * PAGE_MAX=20 match CardsListEndpoint's invariants.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 Trust Attestation Layer Slice D (2026-05-13)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class EntityAttestationsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    private const PER_PAGE_MAX     = 50;
    private const PER_PAGE_DEFAULT = 24;
    private const PAGE_MAX         = 20;

    /** @var list<string> */
    private const ALLOWED_TARGET_KINDS = [
        'user_profile',
        'validator_card',
        'project_card',
        'creator_card',
    ];

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/entities/(?P<target_kind>[a-z_]+)/(?P<target_id>\d+)/attestations',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'list'],
                'permission_callback' => '__return_true',
                'args' => [
                    'target_kind' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'target_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'kind' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'sort' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'include_revoked' => [
                        'required' => false,
                    ],
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
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function list(WP_REST_Request $request): WP_REST_Response
    {
        $targetKind = (string) $request->get_param('target_kind');
        if (!in_array($targetKind, self::ALLOWED_TARGET_KINDS, true)) {
            return self::noStore(ApiResponse::error(
                'bcc_invalid_request',
                'Invalid target_kind.',
                400
            ));
        }

        $targetId = (int) $request->get_param('target_id');
        if ($targetId <= 0) {
            return self::noStore(ApiResponse::error(
                'bcc_invalid_request',
                'Invalid target_id.',
                400
            ));
        }

        $kindParam = $request->get_param('kind');
        $kind      = is_string($kindParam) && $kindParam !== '' ? $kindParam : 'all';
        if (!in_array($kind, AttestationRepository::LIST_KIND_FILTERS, true)) {
            return self::noStore(ApiResponse::error(
                'bcc_invalid_request',
                'Invalid kind filter.',
                400
            ));
        }

        $sortParam = $request->get_param('sort');
        $sort      = is_string($sortParam) && $sortParam !== '' ? $sortParam : 'decayed_weight';
        if (!in_array($sort, AttestationRepository::LIST_SORT_MODES, true)) {
            return self::noStore(ApiResponse::error(
                'bcc_invalid_request',
                'Invalid sort mode.',
                400
            ));
        }

        $includeRevoked = self::parseBoolish($request->get_param('include_revoked'));

        $page = (int) $request->get_param('page');
        if ($page < 1) {
            $page = 1;
        }
        if ($page > self::PAGE_MAX) {
            $page = self::PAGE_MAX;
        }

        $perPage = (int) $request->get_param('per_page');
        if ($perPage < 1) {
            $perPage = self::PER_PAGE_DEFAULT;
        }
        if ($perPage > self::PER_PAGE_MAX) {
            $perPage = self::PER_PAGE_MAX;
        }

        $data = Plugin::instance()->attestationService()->getForTarget(
            $targetKind,
            $targetId,
            [
                'kind'            => $kind,
                'sort'            => $sort,
                'include_revoked' => $includeRevoked,
                'page'            => $page,
                'per_page'        => $perPage,
            ]
        );

        $response = ApiResponse::ok($data);

        // §J.4 cache: private, max-age=30 — generation-counter invalidated
        // by POST/DELETE against the same target. Anon viewers get the
        // public variant since the row shape carries no per-viewer state.
        $viewerId = get_current_user_id();
        if ($viewerId > 0) {
            $response->header('Cache-Control', 'private, max-age=30');
            $response->header('Vary', 'Authorization, Cookie');
        } else {
            $response->header('Cache-Control', 'public, max-age=30');
        }

        return $response;
    }

    /**
     * Parse boolish query params — `1` / `true` / `yes` count as true;
     * everything else (including missing) as false. Matches the
     * `good_standing_only` parser in CardsListEndpoint.
     */
    private static function parseBoolish(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            return in_array($lower, ['1', 'true', 'yes', 'on'], true);
        }
        return false;
    }

    private static function noStore(WP_REST_Response $response): WP_REST_Response
    {
        $response->header('Cache-Control', 'private, no-store');
        return $response;
    }
}
