<?php
/**
 * Announcement READ routes — api-contract-v1.md §4.32.4.
 *
 * Four of the fourteen §4.32.4 routes. The ten writes, the comment routes
 * and the admin recovery routes are **not** registered here; see the
 * "deliberately absent" note below, because what a route file does NOT
 * register is as much a contract statement as what it does.
 *
 *   GET /bcc/v1/validators/:pageId/announcements           public list
 *   GET /bcc/v1/validators/:pageId/announcements/rotator   max 5, inert pagination
 *   GET /bcc/v1/validators/:pageId/announcements/:id        detail
 *   GET /bcc/v1/me/validators/:pageId/announcements         owner list
 *
 * ── ⚠⚠ THE THREE REFUSALS, AND WHY THEY ARE INDISTINGUISHABLE ───────────
 * A reader who is told apart gets information. So:
 *
 *   feature off                     -> 404 bcc_not_found
 *   non-public row, non-owner       -> 404 bcc_not_found   (§4.32.4)
 *   ownership denied, any cause     -> 403 bcc_forbidden + reason_code
 *                                      `not_claimer`, ALWAYS (§4.32.2)
 *
 * The first two are byte-identical on the wire, which is the point: an
 * outside reader cannot tell "no such announcement" from "that one is a
 * draft" from "the feature is off". And the third never varies — a
 * distinguishable `claim_ambiguous` would tell an unauthenticated prober
 * which validators are contested, and a distinguishable `claim_absent`
 * would map the unclaimed ones.
 *
 * ── ⚠ THE ENDPOINT DECIDES NOTHING ──────────────────────────────────────
 * It parses, calls, and serialises. Ownership comes from
 * {@see \BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority}, rows
 * from {@see \BCC\Trust\Core\Repositories\AnnouncementRepository},
 * payloads from {@see \BCC\Trust\Core\Services\AnnouncementViewService}.
 * §4.32.2 forbids `post_author`, mirror claim rows, page metadata, PeepSo
 * membership and read models as authorization inputs — so this file names
 * none of them, and a test asserts it.
 *
 * ── ⚠ DELIBERATELY ABSENT ───────────────────────────────────────────────
 * No POST, PATCH, archive, pin, comment or admin route. Writes need the
 * §4.32.1 publication-authorization MARKER to be written, which is A3's
 * job, and §4.32.13's `blocked` state needs reconciler arm C — neither
 * exists. Registering a write route now would mean inventing where the
 * marker comes from and then depending on the invention.
 *
 * ⚠ Registering a route also makes it visible to
 * `contract-parity-guard.php` in BOTH directions, so these four and the
 * four `#### \`METHOD /path\`` sections in the umbrella docs change land as
 * one reviewed pair. The remaining ten stay in §4.32.4's table until their
 * implementation exists.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Announcements\AnnouncementFeature;
use BCC\Trust\Core\Announcements\AnnouncementViewerStanding;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Repositories\AnnouncementRepository;
use BCC\Trust\Core\Services\AnnouncementViewService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\KeysetCursor;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as Authority;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class AnnouncementsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** §4.32.4: default 20, max 50. Clamped again in the handler. */
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 50;

    /** §4.32.4 `?state=` — the only two public collections. */
    private const STATE_ACTIVE   = 'active';
    private const STATE_ARCHIVED = 'archived';

    public static function register(): void
    {
        $instance = new self();

        // ⚠ `absint` here opts this arg out of schema enforcement (see
        // $limitArg below), which is harmless ONLY because the route regex
        // `(?P<pageId>\d+)` already guarantees digits. Real dispatch
        // confirms it: `-1`, `abc` and `1.5` never reach a handler at all —
        // they answer `rest_no_route`. `0` does route, and the handler
        // refuses it.
        $pageArg = [
            'required'          => true,
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ];

        // ⚠⚠ `validate_callback` IS LOAD-BEARING, and its absence is a trap
        // that only REAL WordPress dispatch exposes.
        //
        // `WP_REST_Request::sanitize_params()` defaults an arg to
        // `rest_parse_request_arg` — the function that enforces a schema —
        // only when the arg declares NO `sanitize_callback`. Supplying one
        // therefore opts the argument OUT of schema enforcement, and
        // `minimum`/`maximum` become decorative. Measured on WordPress
        // 6.7.2: before this line, `?limit=51` answered **200 with 50 rows**
        // and `?limit=0` answered 200, while `?state=everything` correctly
        // answered 400 — because `state` declares no sanitizer and so kept
        // WordPress's own validation.
        //
        // ⚠ A unit harness that applies the declared schema ITSELF cannot
        // see this. It is stricter than WordPress and reports green on a
        // bound that does not bind.
        //
        // ⚠ The same shape exists on ~30 other arguments across 15 endpoint
        // files in this plugin (`minimum`/`maximum` beside an explicit
        // `sanitize_callback`). Those are reported, not changed here: each
        // one clamps server-side as `clampLimit()` does, so none is a
        // vulnerability, but none of their declared bounds binds either.
        $limitArg = [
            'required'          => false,
            'type'              => 'integer',
            'default'           => self::DEFAULT_LIMIT,
            'minimum'           => 1,
            'maximum'           => self::MAX_LIMIT,
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => 'absint',
        ];

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/validators/(?P<pageId>\d+)/announcements',
            [
                'methods'  => WP_REST_Server::READABLE,
                'callback' => [$instance, 'publicList'],
                // Auth is OPTIONAL (§4.32.4): the public list is published
                // public data and is identical for every viewer, so there
                // is nothing for a permission callback to decide.
                'permission_callback' => '__return_true',
                'args' => [
                    'pageId' => $pageArg,
                    'state'  => [
                        'required' => false,
                        'type'     => 'string',
                        'default'  => self::STATE_ACTIVE,
                        'enum'     => [self::STATE_ACTIVE, self::STATE_ARCHIVED],
                    ],
                    'limit' => $limitArg,
                    'cursor' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );

        // ⚠ Registered BEFORE the `:id` route. WordPress REST matches in
        // registration order, and `rotator` would otherwise be captured by
        // `(?P<id>\d+)`… it would not, since `rotator` is not digits — but
        // the ordering is made explicit anyway, because a later change to
        // the id pattern (a slug, say) would silently swallow it.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/validators/(?P<pageId>\d+)/announcements/rotator',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'rotator'],
                'permission_callback' => '__return_true',
                'args'                => ['pageId' => $pageArg],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/validators/(?P<pageId>\d+)/announcements/(?P<id>\d+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'detail'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'pageId' => $pageArg,
                    'id'     => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/validators/(?P<pageId>\d+)/announcements',
            [
                'methods'  => WP_REST_Server::READABLE,
                'callback' => [$instance, 'ownerList'],
                // ⚠ Still `__return_true`. Ownership is NOT a WordPress
                // capability question — §4.32.2's authority is a verified
                // operator claim, and `current_user_can` is explicitly not
                // an accepted input. The handler refuses; a permission
                // callback here could only duplicate or contradict it.
                'permission_callback' => '__return_true',
                'args' => [
                    'pageId' => $pageArg,
                    'status' => [
                        'required' => false,
                        'type'     => 'string',
                        // ⚠⚠ ALL FOUR WRITE STATES ARE NOW ACCEPTED, and each
                        // was added only once its filter could mean what it
                        // says. `blocked` was refused while reconciler arm C
                        // did not exist — nothing then distinguished a blocked
                        // row from an ordinary draft, so the parameter would
                        // have returned ordinary drafts. `published` was
                        // refused because "the public list serves it", which is
                        // true of the ROWS and false of the CAPABILITIES:
                        // §4.32.11 forbids the public list from carrying
                        // `capabilities` for any viewer, so an operator had no
                        // way to obtain management capabilities for a set of
                        // published rows. See
                        // AnnouncementRepository::ownerStatuses().
                        'enum'              => ['draft', 'scheduled', 'blocked', 'published'],
                        // ⚠ Explicit validator: without one, `enum` beside a
                        // sanitizer is decorative — the /members defect.
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                    'limit' => $limitArg,
                    'cursor' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                ],
            ]
        );
    }

    // ── Public reads ─────────────────────────────────────────────────────

    /**
     * `GET /validators/:pageId/announcements`
     *
     * ⚠⚠ Takes no viewer into account AT ALL. §4.32.11: the public list is
     * published public data only, for every viewer **including the
     * resolved operator**. `AnnouncementViewService::publicPage()` has no
     * viewer parameter, so there is nothing here to pass even if this
     * handler wanted to.
     *
     * That is what makes the response cacheable without a viewer
     * component — hence `public, max-age=60` rather than the
     * `private, no-store` an authed surface takes.
     */
    public function publicList(WP_REST_Request $request): WP_REST_Response
    {
        if (!AnnouncementFeature::isEnabled()) {
            return AnnouncementFeature::notFound();
        }

        $pageId = (int) $request->get_param('pageId');
        if ($pageId <= 0) {
            return self::notFound();
        }

        $state   = (string) ($request->get_param('state') ?? self::STATE_ACTIVE);
        $limit   = self::clampLimit($request->get_param('limit'));
        $cursor  = self::stringParam($request, 'cursor');
        $archived = $state === self::STATE_ARCHIVED;

        $repo = new AnnouncementRepository();

        try {
            $page = $archived
                ? $repo->listArchived($pageId, $limit, $cursor)
                : $repo->listActive($pageId, $limit, $cursor);
            $counts = $repo->commentCountsFor(self::idsOf($page['rows']));
        } catch (RepositoryException) {
            // ⚠ A read failure is NOT an empty list. §4.32 makes an empty
            // announcement bar entirely normal, so returning [] here would
            // hide the fault behind a plausible page. 503 says "ask again",
            // which is true and is the only honest answer.
            return self::unavailable();
        }

        $payload = AnnouncementViewService::publicPage(
            $page['rows'],
            $page['has_more'],
            $counts,
            $archived
                ? KeysetCursor::SURFACE_ANNOUNCEMENTS_ARCHIVED
                : KeysetCursor::SURFACE_ANNOUNCEMENTS_ACTIVE,
            self::slugFor($pageId)
        );

        $response = ApiResponse::ok($payload);

        // ⚠⚠ NOT publicly cacheable — a deliberate retreat from the
        // `public, max-age=60` an earlier draft of this file carried.
        //
        // §4.32.11 says the public list is "safely cacheable and its cache
        // key needs no viewer component." That is a statement about CACHE
        // KEYS, and it is still honoured here: no `Vary`, because nothing
        // in this response varies by viewer. It is NOT a freshness
        // guarantee, and §1.6's endpoint-kind TTL table has no
        // announcements row — so a shared `max-age` would be an invention,
        // not a contract.
        //
        // Three things an invented TTL would break. An uncached repository
        // prevents none of them: the repository sits BEHIND the HTTP cache,
        // so "we don't cache the query" says nothing about who stores the
        // response.
        //   1. FEATURE-OFF WOULD NOT TAKE EFFECT. A shared cache keeps
        //      serving a 200 after the switch flips, so "off" would mean
        //      "off within a minute". For a surface whose safe state is
        //      disabled, that is the wrong direction to be approximate in.
        //   2. ARCHIVE AND PIN WOULD LAG — INCLUDING FOR THE OPERATOR.
        //      The response is viewer-independent, so the operator shares
        //      the entry with everyone: they archive a notice and keep
        //      seeing it listed as current, with nothing on the page to
        //      explain why.
        //   3. THERE IS NO INVALIDATION HOOK to hang a purge on — no
        //      generation counter, no surrogate key, nothing that fires on
        //      archive, on pin, or on the switch.
        //
        // Claim revocation is deliberately NOT on that list. This response
        // carries no viewer-dependent field, so revoking a claim cannot
        // change it — which is exactly why detail and the owner list carry
        // `Vary` and this does not.
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * `GET /validators/:pageId/announcements/rotator`
     *
     * Max 5, pinned first then newest, `pagination` present and **inert**
     * (§4.32.4/§4.32.12). It mints no cursor and accepts none — expressed
     * by the route declaring no `cursor` or `limit` arg at all, so a
     * caller cannot page it.
     */
    public function rotator(WP_REST_Request $request): WP_REST_Response
    {
        if (!AnnouncementFeature::isEnabled()) {
            return AnnouncementFeature::notFound();
        }

        $pageId = (int) $request->get_param('pageId');
        if ($pageId <= 0) {
            return self::notFound();
        }

        $repo = new AnnouncementRepository();

        try {
            $rows   = $repo->rotator($pageId);
            $counts = $repo->commentCountsFor(self::idsOf($rows));
        } catch (RepositoryException) {
            return self::unavailable();
        }

        $response = ApiResponse::ok(
            AnnouncementViewService::rotatorPage($rows, $counts, self::slugFor($pageId))
        );
        // Same data, same rule, same reasoning as the public list above —
        // including that the ABSENCE of `Vary` is the viewer-independence
        // guarantee and the ABSENCE of a TTL is the freshness one. The
        // rotator is the surface where a stale pin would be most visible:
        // it is at most five rows and pinned-first is its whole ordering.
        $response->header('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * `GET /validators/:pageId/announcements/:id`
     *
     * ⚠⚠ The only read where "not found" and "not yours" must be the same
     * answer. §4.32.4: a not-yet-public announcement is **404 to
     * non-owners, never 403** — a 403 would confirm the row exists, which
     * is the disclosure the rule exists to prevent.
     *
     * So the lookup is done through the PUBLIC collection first. If the row
     * is not publicly visible, the handler resolves ownership and retries
     * through the owner collection; failing that it 404s. An observer
     * cannot distinguish the three outcomes.
     */
    public function detail(WP_REST_Request $request): WP_REST_Response
    {
        if (!AnnouncementFeature::isEnabled()) {
            return AnnouncementFeature::notFound();
        }

        $pageId = (int) $request->get_param('pageId');
        $id     = (int) $request->get_param('id');
        if ($pageId <= 0 || $id <= 0) {
            return self::notFound();
        }

        $viewerId = get_current_user_id();
        $repo     = new AnnouncementRepository();

        try {
            $row = $repo->findPublic($pageId, $id);

            if ($row === null) {
                // Not public. It may still be the operator's own draft —
                // and only an authoritative resolution may answer that.
                $decision = Authority::decide($pageId, $viewerId);
                $row      = $repo->findForOwner($pageId, $decision, $id);

                if ($row === null) {
                    // ⚠ 404 whether the row does not exist, is someone
                    // else's draft, or the viewer is not the operator. One
                    // answer for three states, by design.
                    return self::notFound();
                }
            } else {
                $decision = Authority::decide($pageId, $viewerId);
            }

            $counts = $repo->commentCountsFor([$id]);
        } catch (RepositoryException) {
            return self::unavailable();
        }

        $payload = AnnouncementViewService::detail(
            $row,
            $counts,
            $decision,
            self::viewerStanding($viewerId),
            true,
            self::slugFor($pageId)
        );

        $response = ApiResponse::ok($payload);
        // ⚠ Detail carries row capabilities, which are viewer-dependent, so
        // it is NEVER publicly cacheable even when the row is public.
        //
        // ⚠⚠ `Vary` is the other half of that. `capabilities` changes when a
        // claim is REVOKED between two requests, so a cache keyed without
        // the credential could hand one viewer the permissions resolved for
        // another. `private` alone asks a shared cache not to store it;
        // `Vary` is what makes a cache that stores it anyway still correct.
        $response->header('Cache-Control', 'private, no-store');
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }

    // ── Owner read ───────────────────────────────────────────────────────

    /**
     * `GET /me/validators/:pageId/announcements`
     *
     * The only route that returns unpublished rows.
     *
     * ⚠ Ownership failure is `403 bcc_forbidden` with
     * `reason_code: not_claimer` — the SAME body for no claim, someone
     * else's claim, and competing claims (§4.32.2). The authority retains
     * a finer internal reason and this handler never serialises it.
     *
     * ⚠⚠ ALL FOUR §4.32.13 WRITE STATES ARE ACCEPTED — `draft`, `scheduled`,
     * `blocked` and `published` — but the DEFAULT IS STILL
     * `['draft', 'scheduled']` and deliberately unchanged. The default is
     * what §4.32.4 documents and what every existing caller receives, so
     * widening it would change the meaning of a request nobody edited.
     * `?status=published` is an explicit opt-in.
     *
     * ⚠ `published` was previously refused on the grounds that "the public
     * list serves it". That is true of the ROWS and false of the
     * CAPABILITIES: §4.32.11 forbids the public list from carrying
     * `capabilities` for any viewer, so an operator could obtain management
     * capabilities for published announcements only one row at a time through
     * the detail route — an N+1 over the whole billboard. This list is the
     * only surface permitted to carry them for a set of rows.
     *
     * ⚠ Only ONE status per request, deliberately not a comma list. A
     * multi-status parameter would change the shape of a documented parameter
     * rather than extend its vocabulary, and the panel's tabs are per-state
     * anyway.
     */
    public function ownerList(WP_REST_Request $request): WP_REST_Response
    {
        if (!AnnouncementFeature::isEnabled()) {
            return AnnouncementFeature::notFound();
        }

        $pageId = (int) $request->get_param('pageId');
        if ($pageId <= 0) {
            return self::notFound();
        }

        $viewerId = get_current_user_id();
        $decision = Authority::decide($pageId, $viewerId);

        if ($decision['decision'] !== Authority::DECISION_OPERATOR) {
            return self::forbidden();
        }

        $status   = self::stringParam($request, 'status');
        $statuses = $status === null ? ['draft', 'scheduled'] : [$status];
        $limit    = self::clampLimit($request->get_param('limit'));
        $cursor   = self::stringParam($request, 'cursor');

        $repo = new AnnouncementRepository();

        try {
            $page   = $repo->listForOwner($pageId, $decision, $statuses, $limit, $cursor);
            $counts = $repo->commentCountsFor(self::idsOf($page['rows']));
        } catch (RepositoryException) {
            return self::unavailable();
        }

        $response = ApiResponse::ok(AnnouncementViewService::ownerPage(
            $page['rows'],
            $page['has_more'],
            $counts,
            $decision,
            self::viewerStanding($viewerId),
            true,
            self::slugFor($pageId)
        ));
        // Owner-only by definition, so the same pairing as detail: the
        // whole payload is a consequence of who asked.
        $response->header('Cache-Control', 'private, no-store');
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }

    // ── refusals, each produced in exactly one place ─────────────────────

    /**
     * ⚠ Identical to {@see AnnouncementFeature::notFound()} on the wire.
     * Two call sites, one body — a feature-off refusal and a
     * non-public-row refusal must not be tellable apart.
     */
    private static function notFound(): WP_REST_Response
    {
        return ApiResponse::error('bcc_not_found', 'Not found.', 404);
    }

    /**
     * The ONE ownership denial (§4.32.2). No variation, ever: the wire
     * reason is re-exported from the authority rather than written as a
     * literal, so the two cannot drift.
     */
    private static function forbidden(): WP_REST_Response
    {
        return ApiResponse::error(
            'bcc_forbidden',
            'You do not manage this validator.',
            403,
            ['reason_code' => Authority::PUBLIC_REASON]
        );
    }

    /**
     * ⚠ A read failure is 503, not 200-with-an-empty-list and not 500.
     * 503 is retryable and says nothing about the data; an empty list would
     * be a lie that looks exactly like the normal case.
     */
    private static function unavailable(): WP_REST_Response
    {
        return ApiResponse::error(
            'bcc_unavailable',
            'Announcements are temporarily unavailable.',
            503
        );
    }

    // ── parsing helpers ──────────────────────────────────────────────────

    /**
     * §4.32.4: default 20, max 50. Clamped here as well as in the route
     * args, because `args` validation can be bypassed by an internal
     * `WP_REST_Request` built in code, and the repository clamps again —
     * three layers, none of them load-bearing alone.
     *
     * @param mixed $raw
     */
    private static function clampLimit($raw): int
    {
        $limit = is_numeric($raw) ? (int) $raw : self::DEFAULT_LIMIT;

        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }

        return min($limit, self::MAX_LIMIT);
    }

    /**
     * A string param, or null. ⚠ An empty string becomes null: `?cursor=`
     * means "no cursor", not "a cursor that is the empty string" — and
     * `KeysetCursor::decode` would refuse the latter anyway, silently
     * resetting the page.
     */
    private static function stringParam(WP_REST_Request $request, string $key): ?string
    {
        $raw = $request->get_param($key);

        if (!is_string($raw)) {
            return null;
        }

        $raw = trim($raw);

        return $raw === '' ? null : $raw;
    }

    /**
     * @param list<object> $rows
     * @return list<int>
     */
    private static function idsOf(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $id = isset($row->ID) ? (int) $row->ID : 0;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The viewer's standing, for the comment and report gates.
     *
     * ⚠ This endpoint computes no standing of its own — it reports what it
     * can establish and leaves every absent key to the resolver's
     * fail-closed defaults. §4.32.11 requires absence to fail closed, so a
     * partial array is safe by construction; inventing values here would
     * be a second implementation of member standing.
     *
     * @return array<string, mixed>
     */
    /**
     * ⚠⚠ THE GAP THIS CLOSES, AND WHAT IT COST.
     *
     * This method used to return `['authenticated' => $viewerId > 0]` and
     * nothing else, while `AnnouncementCapabilityResolver::forRow()` reads FOUR
     * keys and — correctly — fails closed on an absent one. So `good_standing`
     * read as FALSE for every viewer, and `can_comment` could never be
     * `allowed` through any live route no matter who asked: the gate fell
     * through to `suspended` for an authenticated member in perfectly good
     * standing.
     *
     * Neither side was wrong on its own. The resolver documented that the
     * caller computes standing "from the existing services"; this caller
     * supplied one key and nothing compared the two. It was measured, not
     * reasoned about — a probe asked for `can_comment` as an eligible member
     * and got `suspended`.
     *
     * Standing now comes from {@see AnnouncementViewerStanding}, which routes
     * every flag through the service that already owns it elsewhere.
     *
     * @return array{authenticated: bool, suspended: bool, fraud_locked: bool, good_standing: bool}
     */
    private static function viewerStanding(int $viewerId): array
    {
        return AnnouncementViewerStanding::forViewer($viewerId);
    }

    /**
     * The validator slug for `links.self`.
     *
     * ⚠ Resolved from the page's own post slug, which is a PRESENTATION
     * lookup and not an authorization input — it only shapes a URL. Falls
     * back to the numeric id so a missing slug produces a usable, if
     * uglier, permalink rather than `/v//a/4471`.
     */
    private static function slugFor(int $pageId): string
    {
        $post = get_post($pageId);

        // ⚠ No `isset()` on `post_name`: `WP_Post::$post_name` is declared
        // non-nullable, so the isset would be dead weight that reads as a
        // real guard. The null check on $post is the only one needed.
        if ($post !== null && is_string($post->post_name) && $post->post_name !== '') {
            return $post->post_name;
        }

        return (string) $pageId;
    }
}
