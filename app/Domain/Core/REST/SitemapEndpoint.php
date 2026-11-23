<?php
/**
 * SitemapEndpoint — the public index the app's /sitemap.xml is built from
 * (F-518).
 *
 *   GET /bcc/v1/sitemap/members?after=&limit=   member profiles   (/u/{handle})
 *   GET /bcc/v1/sitemap/pages?after=&limit=     entity pages      (/v/, /p/, /c/{slug})
 *   GET /bcc/v1/sitemap/groups?after=&limit=    open groups       (/halls/, /communities/{slug})
 *
 * Each answers `{ items: [{ path }], next_after }`, keyset-paged by id.
 * `next_after` is the last id SCANNED, so a page can hold fewer items than
 * `limit` (eligibility is checked after the scan) and still have a next one;
 * it is null when the scan reached the end.
 *
 * ⚠ VIEWER-INDEPENDENT. What is listed is the anonymous answer, decided by
 * {@see \BCC\Trust\Core\Services\PublicProfileIndex}. This class never reads
 * the current user, so a signed-in or admin token cannot widen the list, and
 * the response is the same for every caller (shared-cacheable).
 *
 * ⚠ FAIL CLOSED. When any read behind a page cannot be trusted the answer
 * is 503 `bcc_unavailable` with no items, never a partial list: the sitemap
 * keeps its last good copy rather than listing an unknown member or
 * silently dropping part of the index.
 *
 * Bounds: `limit` 1..1000 (default 500), `after` ≥ 0 (default 0), refused
 * with 400 `bcc_invalid_request` when out of range, never clamped.
 *
 * @package BCC\Trust\Core\REST
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\PublicIndexRepository;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\BoundedIntParam;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class SitemapEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public const DEFAULT_LIMIT = 500;
    public const MAX_LIMIT     = PublicIndexRepository::PAGE_MAX;

    /** Anonymous reads, keyed per caller by Throttle. Generous: a sitemap
     *  rebuild walks a few pages; this only stops a flood. */
    private const RATE_LIMIT  = 120;
    private const RATE_WINDOW = 60;

    /** The anonymous answer is the same for everyone: shared-cacheable. */
    private const CACHE_CONTROL = 'public, max-age=300';

    public static function register(): void
    {
        // Three literal registrations (not a loop) so contract-parity's
        // scan sees every path.
        register_rest_route(self::ROUTE_NAMESPACE, '/sitemap/members', self::route('members'));
        register_rest_route(self::ROUTE_NAMESPACE, '/sitemap/pages', self::route('pages'));
        register_rest_route(self::ROUTE_NAMESPACE, '/sitemap/groups', self::route('groups'));
    }

    /**
     * @param 'members'|'pages'|'groups' $kind
     * @return array<string, mixed>
     */
    private static function route(string $kind): array
    {
        return [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => static fn (WP_REST_Request $request): WP_REST_Response => self::respond($request, $kind),
            // Anonymous by design: this is the public index.
            'permission_callback' => '__return_true',
            'args'                => [
                // ⚠ keepScalar, not absint: absint('-5') is 5, and the
                // handler must see the caller's text to refuse it (see
                // BoundedIntParam). minimum/maximum document the bound;
                // the handler is where it binds.
                'after' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'default'           => 0,
                    'minimum'           => 0,
                    'sanitize_callback' => [BoundedIntParam::class, 'keepScalar'],
                ],
                'limit' => [
                    'required'          => false,
                    'type'              => 'integer',
                    'default'           => self::DEFAULT_LIMIT,
                    'minimum'           => 1,
                    'maximum'           => self::MAX_LIMIT,
                    'sanitize_callback' => [BoundedIntParam::class, 'keepScalar'],
                ],
            ],
        ];
    }

    /**
     * @param 'members'|'pages'|'groups' $kind
     */
    private static function respond(WP_REST_Request $request, string $kind): WP_REST_Response
    {
        $after = BoundedIntParam::parse($request->get_param('after'), 0, null);
        $limit = BoundedIntParam::parse($request->get_param('limit'), 1, self::MAX_LIMIT);
        if ($after === null || $limit === null) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'after must be 0 or more and limit between 1 and ' . self::MAX_LIMIT . '.',
                400
            );
        }

        if (!\BCC\Core\Security\Throttle::allow('sitemap_index', self::RATE_LIMIT, self::RATE_WINDOW)) {
            return ApiResponse::error('bcc_rate_limited', 'Too many requests. Please wait a moment.', 429);
        }

        $index = Plugin::instance()->publicProfileIndex();
        $page  = match ($kind) {
            'members' => $index->membersAfter($after, $limit),
            'pages'   => $index->pagesAfter($after, $limit),
            'groups'  => $index->groupsAfter($after, $limit),
        };
        if ($page === null) {
            $resp = ApiResponse::error('bcc_unavailable', 'The public index is temporarily unavailable.', 503);
            $resp->header('Cache-Control', 'no-store');
            return $resp;
        }

        $resp = ApiResponse::ok($page);
        $resp->header('Cache-Control', self::CACHE_CONTROL);
        $resp->header('Vary', 'Authorization, Cookie');

        return $resp;
    }
}
