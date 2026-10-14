<?php

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Announcements\AnnouncementCapabilityResolver as Caps;
use BCC\Trust\Core\Announcements\AnnouncementFeature as Feature;
use BCC\Trust\Core\Announcements\AnnouncementTransition as Verb;
use BCC\Trust\Core\Services\AnnouncementWriteService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Onchain\Services\ValidatorPageOperatorAuthority as Authority;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * A3 — the announcement write routes.
 *
 * Five routes, exactly the shapes announcements-crud-transition.md §3 names. No
 * route is added here that the design document did not already specify, because
 * a contract section for an unregistered route is itself a guard failure and the
 * reverse — a registered route with no §4 section — turns umbrella CI red.
 *
 * ── ⚠⚠ WHY permission_callback IS __return_true ─────────────────────────────
 * It looks wrong on a mutating route and it is deliberate. A permission_callback
 * denial emits WordPress's OWN 401/403 envelope, which would tell an anonymous
 * caller that this page has an announcements surface — exactly the disclosure
 * §4.32.11 forbids. The real gate is inside each handler: the feature switch,
 * then a FRESH operator-authority decision, and every refusal is rendered as a
 * 404 in the house envelope so it is indistinguishable from a missing row.
 * The house convention agrees: 29 mutating bcc routes use __return_true and gate
 * inside the handler; only three admin routes gate in the callback.
 *
 * ── THE ORDER, IN EVERY HANDLER, WITHOUT EXCEPTION ──────────────────────────
 *   1. feature switch   -> 404 before anything else is touched
 *   2. schema           -> enforced by the route args, with an explicit
 *                          validate_callback (see below)
 *   3. operator authority -> resolved FRESH, per request, per action
 *   4. the service       -> transition, marker, read-back
 *
 * ⚠⚠ EVERY BOUNDED ARG DECLARES validate_callback EXPLICITLY. WordPress
 * substitutes the schema-enforcing rest_parse_request_arg only for an argument
 * that declares NO sanitize_callback, so `minimum`/`maximum` sitting beside a
 * sanitizer are DECORATIVE. That defect shipped on /bcc/v1/members and served
 * a real LIMIT 100000; it is not repeated here.
 */
final class AnnouncementWriteEndpoint
{
    private const NS = 'bcc/v1';

    public static function register(): void
    {
        $pageArg = [
            'required'          => true,
            'type'              => 'integer',
            'minimum'           => 1,
            'validate_callback' => 'rest_validate_request_arg',
            'sanitize_callback' => 'absint',
        ];
        $idArg = $pageArg;

        // POST /validators/:pageId/announcements  — create
        register_rest_route(
            self::NS,
            '/validators/(?P<pageId>\d+)/announcements',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'create'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'pageId' => $pageArg,
                    'status' => [
                        'required'          => true,
                        'type'              => 'string',
                        // ⚠ The enum is the THREE WRITE VERBS and nothing else.
                        // `blocked` is a READ state produced by reconciler arm C,
                        // which does not exist; it is rejected by the schema
                        // rather than mapped, so a refusal cannot look like a
                        // successful write.
                        'enum'              => Verb::verbs(),
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                    'title'  => self::textArg(true),
                    'body'   => self::textArg(false),
                    'publish_at' => self::instantArg(),
                ],
            ]
        );

        // PATCH /validators/:pageId/announcements/:id  — edit / re-schedule
        register_rest_route(
            self::NS,
            '/validators/(?P<pageId>\d+)/announcements/(?P<id>\d+)',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [self::class, 'edit'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'pageId' => $pageArg,
                    'id'     => $idArg,
                    'title'  => self::textArg(false),
                    'body'   => self::textArg(false),
                    'publish_at' => self::instantArg(),
                ],
            ]
        );

        // POST /validators/:pageId/announcements/:id/publish
        register_rest_route(
            self::NS,
            '/validators/(?P<pageId>\d+)/announcements/(?P<id>\d+)/publish',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'publish'],
                'permission_callback' => '__return_true',
                'args'                => ['pageId' => $pageArg, 'id' => $idArg],
            ]
        );

        // POST /validators/:pageId/announcements/:id/archive
        register_rest_route(
            self::NS,
            '/validators/(?P<pageId>\d+)/announcements/(?P<id>\d+)/archive',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'archive'],
                'permission_callback' => '__return_true',
                'args'                => ['pageId' => $pageArg, 'id' => $idArg],
            ]
        );

        // POST /validators/:pageId/announcements/:id/pin
        register_rest_route(
            self::NS,
            '/validators/(?P<pageId>\d+)/announcements/(?P<id>\d+)/pin',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'pin'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'pageId' => $pageArg,
                    'id'     => $idArg,
                    'pinned' => [
                        'required'          => true,
                        'type'              => 'boolean',
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                ],
            ]
        );
    }

    public static function create(WP_REST_Request $request): WP_REST_Response
    {
        if (!Feature::isEnabled()) {
            return Feature::notFound();
        }

        $pageId = (int) $request['pageId'];
        [$decision, $actorId] = self::authorize($pageId);

        $result = self::service()->create(
            $pageId,
            $decision,
            $actorId,
            (string) $request['status'],
            [
                'title' => (string) ($request['title'] ?? ''),
                'body'  => (string) ($request['body'] ?? ''),
                'publish_at' => (string) ($request['publish_at'] ?? ''),
            ]
        );

        return self::render($result, $decision, 201);
    }

    public static function edit(WP_REST_Request $request): WP_REST_Response
    {
        if (!Feature::isEnabled()) {
            return Feature::notFound();
        }

        $pageId = (int) $request['pageId'];
        [$decision] = self::authorize($pageId);

        // ⚠ Only keys the caller actually sent are forwarded, so an omitted
        // field is "leave alone" rather than "set to empty".
        $input = [];
        foreach (['title', 'body', 'publish_at'] as $k) {
            $v = $request->get_param($k);
            if ($v !== null) {
                $input[$k] = (string) $v;
            }
        }

        $result = self::service()->edit($pageId, (int) $request['id'], $decision, $input);

        return self::render($result, $decision);
    }

    public static function publish(WP_REST_Request $request): WP_REST_Response
    {
        if (!Feature::isEnabled()) {
            return Feature::notFound();
        }

        $pageId = (int) $request['pageId'];
        [$decision, $actorId] = self::authorize($pageId);

        $result = self::service()->publish($pageId, (int) $request['id'], $decision, $actorId);

        return self::render($result, $decision);
    }

    public static function archive(WP_REST_Request $request): WP_REST_Response
    {
        if (!Feature::isEnabled()) {
            return Feature::notFound();
        }

        $pageId = (int) $request['pageId'];
        [$decision] = self::authorize($pageId);

        $result = self::service()->archive($pageId, (int) $request['id'], $decision);

        return self::render($result, $decision);
    }

    public static function pin(WP_REST_Request $request): WP_REST_Response
    {
        if (!Feature::isEnabled()) {
            return Feature::notFound();
        }

        $pageId = (int) $request['pageId'];
        [$decision] = self::authorize($pageId);

        $result = self::service()->pin(
            $pageId,
            (int) $request['id'],
            $decision,
            (bool) $request['pinned']
        );

        return self::render($result, $decision);
    }

    // ── internals ───────────────────────────────────────────────────────────

    /**
     * Resolve operator authority FRESH, for this request and this action.
     *
     * ⚠⚠ No caching, no memoisation, no passing a decision between actions. A
     * claim can be revoked or become ambiguous between two requests, and a
     * cached decision would let the second action run on the first one's
     * authority. `AnnouncementWriteGuaranteesTest` asserts there is no static
     * cache here, because a cache is the obvious "optimisation" a later reader
     * would add.
     *
     * @return array{0: array{decision: string, reason: string|null, operator_user_id: int|null}, 1: int}
     */
    private static function authorize(int $pageId): array
    {
        $actorId = (int) get_current_user_id();

        return [Authority::decide($pageId, $actorId), $actorId];
    }

    /**
     * @param array<string,mixed> $result
     * @param array{decision: string, reason: string|null, operator_user_id: int|null} $decision
     */
    private static function render(array $result, array $decision, int $okStatus = 200): WP_REST_Response
    {
        if (($result['ok'] ?? false) !== true) {
            return ApiResponse::error(
                (string) ($result['code'] ?? AnnouncementWriteService::ERR_UNAVAILABLE),
                (string) ($result['message'] ?? 'Unavailable.'),
                (int) ($result['status'] ?? 503)
            );
        }

        // ⚠ Capabilities come from the OWNER-SCOPE producer, never hand-built.
        // There is no public capability producer at all, which is how the
        // public/owner split is enforced structurally rather than by a flag.
        // ⚠ forOwnerScope() returns null when the feature is off. That cannot
        // happen here — every handler returns 404 before reaching render() —
        // but the null is handled rather than assumed away, because an
        // unchecked index would be the kind of defect PHPStan catches and a
        // reader would not.
        $scope        = Caps::forOwnerScope($decision, Feature::isEnabled());
        $capabilities = is_array($scope) && isset($scope['capabilities']) && is_array($scope['capabilities'])
            ? $scope['capabilities']
            : [];

        $response = ApiResponse::ok([
            'id'           => (int) ($result['id'] ?? 0),
            'state'        => (string) ($result['state'] ?? ''),
            'capabilities' => $capabilities,
        ], $okStatus);

        // ⚠ A write response is viewer-dependent by construction and must never
        // be cached by anything in front of it.
        $response->header('Cache-Control', 'private, no-store');
        $response->header('Vary', 'Authorization, Cookie');

        return $response;
    }

    private static function service(): AnnouncementWriteService
    {
        return new AnnouncementWriteService();
    }

    /** @return array<string,mixed> */
    private static function textArg(bool $required): array
    {
        return [
            'required'          => $required,
            'type'              => 'string',
            // ⚠ No sanitize_callback. The service does the cleaning, because it
            // must be able to REFUSE an unacceptable value rather than hand the
            // handler a silently corrected one.
            'validate_callback' => 'rest_validate_request_arg',
        ];
    }

    /** @return array<string,mixed> */
    private static function instantArg(): array
    {
        return [
            'required'          => false,
            'type'              => 'string',
            'validate_callback' => 'rest_validate_request_arg',
        ];
    }
}
