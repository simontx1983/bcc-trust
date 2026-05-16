<?php
/**
 * Blog Chain Options Endpoint — §D6 PR-B.
 *
 * Route:
 *   - GET /bcc/v1/blog/chain-options — picker source for the composer
 *
 * Anonymous-readable — the active-chains list is public data (it's
 * already surfaced on Locals discovery + the chain-tag rendering on
 * any blog post), and gating it behind auth would force every fresh
 * session to wait for /me before painting the picker.
 *
 * Backed by {@see ChainRepository::getActive()}, which serves from
 * the object cache + transient (5-min TTL) — repeated requests
 * within the TTL window are zero-DB.
 *
 * Response shape:
 *   { data: { items: [{id, slug, name, color, icon_url}, …] } }
 *
 * Only the columns the composer's picker UI actually needs are
 * surfaced. Chain row internals (rpc_url, decimals, bech32_prefix,
 * marketplace_template, etc.) are deliberately omitted — they're
 * irrelevant to the picker and shipping them widens the public
 * attack surface (anything in a response is contract).
 *
 * Cache-Control: `public, max-age=3600`. The chain list changes
 * rarely (rows added when a new chain ships, deactivated when
 * upstream sunsets). One-hour browser/CDN caching is well under
 * the time-to-acceptable-staleness for picker data.
 *
 * @package BCC\Trust\Core\REST
 * @since V1.5 (2026-05, §D6 crypto-blog composer PR-B)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class BlogChainOptionsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/blog/chain-options',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'list'],
                // Public picker data — anonymous-readable so the
                // composer can prefetch without waiting on auth.
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function list(WP_REST_Request $request): WP_REST_Response
    {
        unset($request); // No params — full active-list shape.

        /** @var list<array{id: int, slug: string, name: string, color: string|null, icon_url: string|null}> $items */
        $items = [];
        foreach (ChainRepository::getActive() as $chain) {
            $items[] = [
                'id'       => (int) $chain->id,
                'slug'     => (string) $chain->slug,
                'name'     => (string) $chain->name,
                'color'    => is_string($chain->color)    && $chain->color    !== '' ? $chain->color    : null,
                'icon_url' => is_string($chain->icon_url) && $chain->icon_url !== '' ? $chain->icon_url : null,
            ];
        }

        $response = ApiResponse::ok(['items' => $items]);
        // Public + cacheable — chain rows change rarely (admin
        // adds/deactivates a chain). 1-hour TTL is well below the
        // typical chain-row mutation cadence.
        $response->header('Cache-Control', 'public, max-age=3600');
        return $response;
    }
}
