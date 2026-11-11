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
use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\BoundedIntParam;
use BCC\Trust\Core\Support\ProfileAccess;
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
    // ⚠ The cap here bounds the URL SPELLING, not the slug, and the two are
    // not the same length. `post_name` is varchar(200), but the frontend
    // escapes every `%` as `%25`, so a 200-character slug can arrive as up to
    // 600 characters — and a real page already needs 215 (`𝐥𝐞𝐬𝐧𝐢𝐤 | 𝐔𝐓𝐒𝐀`,
    // a 135-character stored slug). A 200 cap here let the resolver accept
    // that page while the ROUTE still refused it, so it stayed unreachable.
    // The binding 200-character check is applied to the resolved candidate in
    // CardViewService::canonicalSlugFor(); this is only the outer bound.
    private const ID_PATTERN      = '[A-Za-z0-9%][A-Za-z0-9_%-]{0,599}';

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

        // §F-02 profile-wide gate for the MEMBER card.
        //
        // ⚠ THIS ROUTE WAS MISSED BY THE FIRST SWEEP, and the reason is
        // worth keeping: that sweep enumerated callers of
        // `UserSlugResolver::resolve`, but this path resolves its handle
        // INSIDE CardViewService via a private resolver of its own. So a
        // per-user route hid behind a generic `/cards/{type}/{id}`
        // dispatcher whose other three types are pages, not people. The
        // member card returns bio, stats, chains, trust_score, rank and
        // operator_profile — the same substance as `GET /users/:handle`,
        // which was gated — so leaving it open would have made the rest
        // of F-02 cosmetic for anyone who knew this URL.
        //
        // ⚠ Gated on `CardViewService::resolveMemberCardHandle()`, NOT on
        // UserSlugResolver, deliberately: the two resolve different sets
        // (see that method's docblock), and gating on the other one would
        // judge one user's privacy while rendering another's card.
        if ($type === 'member') {
            $cardUserId = CardViewService::resolveMemberCardHandle($id);

            // ⚠ UNRESOLVED ⇒ ANSWER 404 HERE, do not fall through.
            //
            // The first version let a 0 fall through to the service,
            // reasoning that the service's own 404 would catch it and
            // that the gate must not turn an unknown handle into a 403
            // (which would newly disclose which handles exist). The
            // second half of that is still right — this is a 404, not a
            // 403, and it is deliberately indistinguishable from the
            // service's.
            //
            // What was wrong was falling through, because it meant the
            // handle got resolved TWICE and only the FIRST read was
            // gated. The resolver is a database read, so the two can
            // disagree: a transient failure here returns 0, the gate is
            // skipped as "nothing to gate", and then the service's own
            // resolve succeeds and renders an UNGATED card. Rare, and
            // exactly the shape of bug that never reproduces. One read,
            // one decision.
            if ($cardUserId === 0) {
                return ApiResponse::error('bcc_not_found', 'Card not found.', 404);
            }

            if (!ProfileAccess::canViewProfile($cardUserId)) {
                return ProfileAccess::forbidden();
            }
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
