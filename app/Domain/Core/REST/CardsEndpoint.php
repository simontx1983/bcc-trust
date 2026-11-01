<?php
/**
 * Cards Endpoint — handles /bcc/v1/cards/:type/:id (per contract §L5).
 *
 * Polymorphic: one route shape, four card kinds. The frontend's
 * <CardFactory> reads `card_kind` and dispatches to the right face — but
 * the outer envelope is identical across kinds.
 *
 * Type enum (§N1 + §C1):
 *   - validator
 *   - project
 *   - creator
 *   - member
 *
 * URL :id is:
 *   - For validator/project/creator: numeric post_id OR post_name (slug)
 *   - For member: bcc_handle
 *
 * Cache policy: per-viewer permissions vary the response, so we use a
 * private cache and a short TTL. Anonymous responses could be more
 * aggressive (CDN-cacheable) — that's a V1.5 optimization.
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\BoundedIntParam;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class CardsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const TYPE_PATTERN    = 'validator|project|creator|member';
    // Allows numeric post_id, post_name slugs, and bcc_handle.
    //
    // `%` is admitted because `sanitize_title()` PERCENT-ENCODES every
    // non-ASCII character, so a page whose title carries an emoji is
    // stored with a literal `%f0%9f...` in `post_name` — 123 of the 2,830
    // validator pages. Measured against production: the REST route regex
    // is matched against the UNDECODED request path (requesting
    // `/cards/validator/%7Atake-org-cosmos-54at8c` answers
    // `rest_no_route`, not the `ztake-…` card), so without `%` here those
    // pages have no reachable URL at all — their own stored slug is
    // rejected before any handler runs.
    //
    // Widening the charset is NOT the whole fix and must not be read as
    // one: `id` is also reachable through the query string, which outranks
    // the URL segment in WP_REST_Request::get_parameter_order(), so this
    // pattern bounds only the URL spelling. The binding check lives in
    // CardViewService::canonicalSlugFor(), which refuses any id that is not
    // the stored slug after zero or one url-decode.
    //
    // Capped at 200 chars because `wp_posts.post_name` is varchar(200), so
    // a longer id cannot match a row; the previous 100 left the one
    // 124-char stored slug unreachable.
    private const ID_PATTERN      = '[A-Za-z0-9%][A-Za-z0-9_%-]{0,199}';

    public static function register(): void
    {
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/cards/(?P<type>' . self::TYPE_PATTERN . ')/(?P<id>' . self::ID_PATTERN . ')',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [new self(), 'handle'],
                'permission_callback' => '__return_true',
                'args' => [
                    'type' => [
                        'required'          => true,
                        'type'              => 'string',
                        'enum'              => ['validator', 'project', 'creator', 'member'],
                        'sanitize_callback' => 'sanitize_key',
                    ],
                    'id' => [
                        'required'          => true,
                        'type'              => 'string',
                        // ⚠ NOT `sanitize_text_field`. That sanitizer strips
                        // percent-encoded octets outright — measured on WP
                        // 7.0.2, `sanitize_text_field('bonynode%f0%9f%92%9a-…')`
                        // returns `bonynode-…`, which is a DIFFERENT and
                        // possibly real slug. Leaving it in place would make a
                        // widened ID_PATTERN resolve the wrong entity instead
                        // of 404ing, which is worse than the bug being fixed.
                        //
                        // `keepScalar` is the house sanitizer-that-does-not-
                        // sanitize (#328): it keeps an explicit
                        // `sanitize_callback` — which is what stops WordPress
                        // substituting `rest_parse_request_arg` and answering
                        // `rest_invalid_param` instead of this route's
                        // documented `bcc_not_found` — while handing the
                        // resolver the caller's untouched text to judge.
                        'sanitize_callback' => [BoundedIntParam::class, 'keepScalar'],
                    ],
                ],
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $type = (string) $request->get_param('type');
        $id   = (string) $request->get_param('id');

        if ($type === '' || $id === '') {
            return ApiResponse::error('bcc_invalid_request', 'type and id are required.', 400);
        }

        $viewerId = get_current_user_id();
        $payload  = Plugin::instance()->cardViewService()->getCard($type, $id, $viewerId);

        if ($payload === null) {
            return ApiResponse::error('bcc_not_found', 'Card not found.', 404);
        }

        $response = ApiResponse::ok($payload);
        // Per-viewer permissions vary the response → not shared-cacheable in V1.
        $response->header('Cache-Control', 'private, max-age=30');
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }
}
