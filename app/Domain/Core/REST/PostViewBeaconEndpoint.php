<?php
/**
 * POST /bcc/v1/posts/{id}/view — the F-324 reach beacon.
 *
 * Fired by the client when a post is actually rendered to a reader, which
 * is why reach is measured here rather than at feed delivery: counting
 * delivery would count posts that scrolled past unseen and make "views" a
 * function of page size. It is also the bot exclusion — a crawler that does
 * not run the beacon never registers a view.
 *
 * ⚠⚠ THIS ENDPOINT ALWAYS ANSWERS 204, whatever happened inside. It is not
 * a query interface and must never become one: a caller that could tell
 * "counted" from "unknown post" from "that's your own post" could use the
 * beacon to probe which post ids exist and who wrote them. The outcome is
 * logged server-side and returned to tests, never to the client.
 *
 * Anonymous is allowed on purpose — signed-out views count, per the product
 * decision — so there is no auth gate, only a throttle.
 *
 * @package BCC\Trust\Core\REST
 * @since F-324 post analytics V1 (2026-11-12)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Repositories\PostReachRepository;
use BCC\Trust\Core\Services\PostViewRecorder;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class PostViewBeaconEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';
    private const ROUTE_PATH      = '/posts/(?P<id>\d+)/view';

    /**
     * Beacon throttle.
     *
     * ⚠ Deliberately generous: one beacon fires per rendered post, so a
     * reader scrolling a long feed legitimately sends dozens in a minute. A
     * tight limit here would silently drop real views — which is
     * indistinguishable from "nobody read it" — so this is sized to stop
     * scripted abuse, not to shape normal reading.
     */
    private const RATE_LIMIT  = 240;
    private const RATE_WINDOW = 300;

    /** The two things a beacon can report. */
    private const KIND_VIEW          = 'view';
    private const KIND_PROFILE_VISIT = 'profile_visit';

    public static function register(): void
    {
        register_rest_route(self::ROUTE_NAMESPACE, self::ROUTE_PATH, [
            'methods'  => WP_REST_Server::CREATABLE,
            'callback' => [new self(), 'handle'],
            // Anonymous permitted: signed-out views count.
            'permission_callback' => '__return_true',
            'args'                => [
                'id' => [
                    'required'          => true,
                    'validate_callback' => static fn ($value): bool => is_numeric($value),
                ],
                'source' => [
                    'required'          => true,
                    'validate_callback' => static fn ($value): bool =>
                        is_string($value) && in_array($value, PostReachRepository::SOURCES, true),
                ],
                'kind' => [
                    'required'          => false,
                    'validate_callback' => static fn ($value): bool =>
                        is_string($value) && in_array($value, [self::KIND_VIEW, self::KIND_PROFILE_VISIT], true),
                ],
            ],
        ]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();

        // Throttle key: the member when signed in, else the coarsened
        // network. Reusing the limiter's own subnet normalisation so a
        // signed-out reader is bucketed the same way everywhere else in the
        // system buckets them.
        $throttleKey = $viewerId > 0
            ? 'post_view_beacon_u' . $viewerId
            : 'post_view_beacon_a' . \BCC\Trust\Core\Security\RateLimiter::normalizeIpToSubnet(
                \BCC\Trust\Core\Security\IpResolver::getClientIp()
            );

        if (!\BCC\Core\Security\Throttle::allow($throttleKey, self::RATE_LIMIT, self::RATE_WINDOW)) {
            // ⚠ Still 204, not 429. A distinguishable response would leak
            // that the caller is being counted at all, and the client has
            // nothing useful to do with a rejection — the view is simply
            // lost, which is the documented fail-open behaviour.
            return $this->noContent();
        }

        $actId  = (int) $request->get_param('id');
        $source = (string) $request->get_param('source');
        $kind   = (string) ($request->get_param('kind') ?? self::KIND_VIEW);

        $outcome = $kind === self::KIND_PROFILE_VISIT
            ? PostViewRecorder::recordProfileVisit($actId, $source, $viewerId)
            : PostViewRecorder::record($actId, $source, $viewerId);

        // Server-side only. Deliberately not in the response.
        if (str_starts_with($outcome, 'ignored_write_failed')) {
            \BCC\Core\Log\Logger::error('[bcc-trust] beacon could not record', [
                'kind'   => $kind,
                'source' => $source,
            ]);
        }

        return $this->noContent();
    }

    /**
     * 204 with no body and no caching.
     *
     * `private, no-store` because a cached beacon response is meaningless
     * and an intermediary must not treat this as a cacheable GET-alike.
     */
    private function noContent(): WP_REST_Response
    {
        $response = new WP_REST_Response(null, 204);
        $response->header('Cache-Control', 'private, no-store');

        return $response;
    }
}
