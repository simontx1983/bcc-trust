<?php
/**
 * Groups Detail Endpoint — handles
 *   - GET /bcc/v1/groups/{slug}     (cross-kind single-group view-model)
 *   - GET /bcc/v1/groups/{id}/feed  (group-scoped feed, cursor paginated)
 *
 * Both routes are auth-OPTIONAL: anonymous viewers get the public slice,
 * authed viewers get viewer_membership + permissions populated. Privacy
 * gates are server-enforced (defense-in-depth) — secret groups never
 * leak existence; closed/NFT-gated non-members get 403 with a
 * server-rendered unlock_hint per §A4 / §N7.
 *
 * Cache:
 *   - /groups/{slug}     anon → public, max-age=60; authed → private, no-store
 *   - /groups/{id}/feed  always private, no-store (per-viewer scope filtering)
 *
 * Pagination convention: the feed sub-route uses cursor pagination
 * (next_cursor / has_more) per FeedEndpoint, not offset.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 (group-detail page; 2026-05-08)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupsDetailEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Slug pattern — matches LocalsEndpoint's bound. PeepSo post_name. */
    private const SLUG_PATTERN = '[a-z0-9][a-z0-9-]{0,99}';

    private const DEFAULT_FEED_LIMIT = 20;
    private const MAX_FEED_LIMIT     = 50;

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/groups/(?P<slug>' . self::SLUG_PATTERN . ')',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'show'],
                'permission_callback' => '__return_true',
                'args' => [
                    'slug' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/groups/(?P<id>\d+)/feed',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'feed'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'cursor' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'limit' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'default'           => self::DEFAULT_FEED_LIMIT,
                        'minimum'           => 1,
                        'maximum'           => self::MAX_FEED_LIMIT,
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    public function show(WP_REST_Request $request): WP_REST_Response
    {
        $slugRaw = $request->get_param('slug');
        $slug    = is_string($slugRaw) ? $slugRaw : '';
        if ($slug === '') {
            return ApiResponse::error('bcc_invalid_request', 'Slug is required.', 400);
        }

        $viewerId = get_current_user_id();
        $payload  = Plugin::instance()->groupsService()->getGroup($viewerId, $slug);

        if ($payload === null) {
            // Per §S defense-in-depth: secret-non-member returns 404 too,
            // identical to "missing slug" — never leaks existence.
            return ApiResponse::error('bcc_not_found', 'Group not found.', 404);
        }

        $response = ApiResponse::ok($payload);
        // Per-viewer membership + permissions block must not be cached
        // by shared caches when authed. Anon response is identical
        // across viewers so a 60s public cache is safe.
        $cacheCtl = $viewerId > 0 ? 'private, no-store' : 'public, max-age=60';
        $response->header('Cache-Control', $cacheCtl);

        return $response;
    }

    public function feed(WP_REST_Request $request): WP_REST_Response
    {
        $groupId = (int) $request->get_param('id');
        if ($groupId <= 0) {
            return ApiResponse::error('bcc_invalid_request', 'Invalid group id.', 400);
        }

        $viewerId = get_current_user_id();

        $gate = Plugin::instance()->groupsService()->gateGroupFeed($viewerId, $groupId);

        if ($gate['kind'] === 'not_found') {
            return ApiResponse::error('bcc_not_found', 'Group not found.', 404);
        }

        // Phase 2: gateGroupFeed no longer returns `forbidden` — non-members
        // of nft/closed/open groups are `allowed` with `public_only = true`
        // (PUBLIC-only teaser), and secret + non-member is already mapped to
        // `not_found` above. The only remaining kind here is `allowed`.

        // gate.kind === 'allowed'.
        // Non-members get the PUBLIC-only teaser; members see everything.
        // The flag drives the visibility INNER JOIN downstream so
        // members_only / absent-meta posts can never reach a non-member.
        $publicOnly = (bool) ($gate['public_only'] ?? false);

        $cursorRaw = $request->get_param('cursor');
        $cursor    = is_string($cursorRaw) && $cursorRaw !== '' ? $cursorRaw : null;

        $limit = (int) $request->get_param('limit');
        if ($limit < 1) {
            $limit = self::DEFAULT_FEED_LIMIT;
        }
        if ($limit > self::MAX_FEED_LIMIT) {
            $limit = self::MAX_FEED_LIMIT;
        }

        $payload = Plugin::instance()->feedRankingService()->getGroupFeed(
            $viewerId,
            $groupId,
            $cursor,
            $limit,
            $publicOnly
        );

        $response = ApiResponse::ok($payload);
        // Group-scoped feed mirrors /feed cache policy — per-viewer
        // exclusions (block lists, hidden moderation) make shared
        // caching unsafe.
        $response->header('Cache-Control', 'private, no-store');
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }
}
