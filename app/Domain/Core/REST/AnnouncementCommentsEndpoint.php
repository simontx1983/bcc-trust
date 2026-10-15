<?php

/**
 * A5b — the five §4.32.4 announcement comment routes.
 *
 * ── ⚠⚠ PATH IDENTIFIERS ARE BARE NUMERIC ──────────────────────────────────
 * Every `:id` and `:commentId` below matches `\d+`. The prefixed forms
 * (`ann_4480`, `acmt_9912`) are VIEW-MODEL values and are never path segments
 * — a client holding one strips the prefix. §4.32.4 states the rule normatively
 * after a worked example drifted the other way, and these regexes are what it
 * describes.
 *
 * ── THE TWO PERMISSION-CALLBACK POSTURES, AND WHY THEY DIFFER ─────────────
 * The three member routes use `permission_callback => '__return_true'` and gate
 * inside the handler, which is §4.32.4's documented choice for this feature: a
 * callback denial emits WordPress's OWN 401/403 envelope, which would disclose
 * the surface and bypass the generic-reason rule in §4.32.2.
 *
 * The two `/admin/` routes use `BearerAuth::requireAdmin`, which is what every
 * other `/admin/` route in this plugin uses (`AdminReportsEndpoint`,
 * `AdminDigestEndpoint`). The disclosure argument does not apply there: the
 * path is explicitly administrative, there is no ownership question to leak,
 * and matching the established admin posture is worth more than uniformity with
 * the member routes. The difference is deliberate and is recorded here so it
 * does not read as an oversight.
 *
 * ── THE FEATURE SWITCH IS THE FIRST STATEMENT OF EVERY HANDLER ────────────
 * §4.32.10: while the switch is off, every route answers 404. The check is the
 * first statement, before any authority resolution, so a disabled feature
 * cannot be distinguished from a missing route by timing or by error shape.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Announcements\AnnouncementCommentPolicy as Policy;
use BCC\Trust\Core\Announcements\AnnouncementFeature as Feature;
use BCC\Trust\Core\Services\AnnouncementCommentService;
use BCC\Trust\Core\Support\ApiResponse;
use BCC\Trust\Core\Support\BearerAuth;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class AnnouncementCommentsEndpoint
{
    private const NS = 'bcc/v1';

    /** §4.32.7 — cursor-paginated; the repository owns the band. */
    private const LIMIT_DEFAULT = 20;
    private const LIMIT_MAX     = 50;

    private static ?AnnouncementCommentService $service = null;

    private static function service(): AnnouncementCommentService
    {
        return self::$service ??= new AnnouncementCommentService();
    }

    public static function register(): void
    {
        $idArg = [
            'required'          => true,
            'type'              => 'integer',
            'minimum'           => 1,
            // ⚠ `validate_callback` is declared ALONGSIDE the bounds, and that
            // is load-bearing: `sanitize_params()` substitutes the
            // schema-enforcing `rest_parse_request_arg` only for an argument
            // that declares NO `sanitize_callback`. Bounds without an explicit
            // validator are decorative — the `/members` defect.
            'validate_callback' => 'rest_validate_request_arg',
        ];

        // GET /announcements/:id/comments  — the thread. Auth OPTIONAL.
        register_rest_route(
            self::NS,
            '/announcements/(?P<id>\d+)/comments',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [self::class, 'index'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id'     => $idArg,
                    'limit'  => [
                        'required'          => false,
                        'type'              => 'integer',
                        'minimum'           => 1,
                        'maximum'           => self::LIMIT_MAX,
                        'default'           => self::LIMIT_DEFAULT,
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                    'cursor' => [
                        'required'          => false,
                        'type'              => 'string',
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                ],
            ]
        );

        // POST /announcements/:id/comments  — create. Auth MEMBER.
        register_rest_route(
            self::NS,
            '/announcements/(?P<id>\d+)/comments',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'create'],
                'permission_callback' => '__return_true',
                'args'                => [
                    'id'   => $idArg,
                    // ⚠ NOT declared `required`. A missing body must be
                    // refused by the HANDLER, after the feature gate, or a
                    // schema refusal would answer 400 while the feature is off
                    // — the §4.32.10 leak that `status`/`title` already have on
                    // the A3 write routes. This route does not add a third.
                    'body' => [
                        'required'          => false,
                        'type'              => 'string',
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                    // ⚠ Accepted so the handler can REFUSE it with
                    // `replies_not_supported` (§4.32.9). Rejecting it in the
                    // schema would answer `rest_invalid_param` and lose the
                    // contract's own code.
                    'parent_id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'validate_callback' => 'rest_validate_request_arg',
                    ],
                ],
            ]
        );

        // DELETE /announcements/:id/comments/:commentId  — soft removal.
        register_rest_route(
            self::NS,
            '/announcements/(?P<id>\d+)/comments/(?P<commentId>\d+)',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [self::class, 'remove'],
                'permission_callback' => '__return_true',
                'args'                => ['id' => $idArg, 'commentId' => $idArg],
            ]
        );

        // GET /admin/announcement-comments/:commentId  — the private original.
        register_rest_route(
            self::NS,
            '/admin/announcement-comments/(?P<commentId>\d+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [self::class, 'adminShow'],
                'permission_callback' => [BearerAuth::class, 'requireAdmin'],
                'args'                => ['commentId' => $idArg],
            ]
        );

        // POST /admin/announcement-comments/:commentId/restore
        register_rest_route(
            self::NS,
            '/admin/announcement-comments/(?P<commentId>\d+)/restore',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [self::class, 'adminRestore'],
                'permission_callback' => [BearerAuth::class, 'requireAdmin'],
                'args'                => ['commentId' => $idArg],
            ]
        );
    }

    // ── member routes ────────────────────────────────────────────────────

    public static function index(WP_REST_Request $request): WP_REST_Response
    {
        if (!Feature::isEnabled()) {
            return Feature::notFound();
        }

        $result = self::service()->listThread(
            (int) $request['id'],
            (int) $request['limit'],
            is_string($request['cursor'] ?? null) ? (string) $request['cursor'] : null,
            get_current_user_id()
        );

        // ⚠ `private, no-store` and a Vary on the credential: the thread is
        // viewer-dependent — `can_comment` and each row's `can_remove` differ
        // per viewer — so it must never land in a shared cache. Same posture
        // as the owner list and the detail read.
        return self::render($result, 'private, no-store', 'Authorization, Cookie');
    }

    public static function create(WP_REST_Request $request): WP_REST_Response
    {
        if (!Feature::isEnabled()) {
            return Feature::notFound();
        }

        // ⚠ `has_param`, not a value test: §4.32.7 refuses ANY present
        // `parent_id`, including `0` and `''`. A client that sends the field at
        // all is using a threading model this version does not have, and
        // answering 201 would tell it the reply was threaded.
        $parentSent = $request->has_param('parent_id');

        $result = self::service()->create(
            (int) $request['id'],
            get_current_user_id(),
            $request['body'] ?? null,
            $parentSent
        );

        return self::render($result, 'private, no-store', 'Authorization, Cookie', 201);
    }

    public static function remove(WP_REST_Request $request): WP_REST_Response
    {
        if (!Feature::isEnabled()) {
            return Feature::notFound();
        }

        $result = self::service()->remove(
            (int) $request['commentId'],
            get_current_user_id()
        );

        return self::render($result, 'private, no-store', 'Authorization, Cookie');
    }

    // ── administrator routes ─────────────────────────────────────────────

    public static function adminShow(WP_REST_Request $request): WP_REST_Response
    {
        // ⚠ The feature gate still applies. An administrator surface for a
        // feature that is switched off is still a surface, and §4.32.10 says
        // every route answers 404 while off — it does not carve out admin.
        if (!Feature::isEnabled()) {
            return Feature::notFound();
        }

        return self::render(
            self::service()->adminOriginal((int) $request['commentId']),
            'private, no-store',
            'Authorization, Cookie'
        );
    }

    public static function adminRestore(WP_REST_Request $request): WP_REST_Response
    {
        if (!Feature::isEnabled()) {
            return Feature::notFound();
        }

        return self::render(
            self::service()->adminRestore(
                (int) $request['commentId'],
                get_current_user_id(),
                current_user_can('manage_options')
            ),
            'private, no-store',
            'Authorization, Cookie'
        );
    }

    // ── rendering ────────────────────────────────────────────────────────

    /**
     * ⚠ `reason_code` is forwarded whenever the service supplied one, for ANY
     * status — not only a 403. §4.32.9's codes (`replies_not_supported`,
     * `comments_disabled`, `not_comment_author`, `announcement_archived`) are
     * attached to 400s and 403s alike, and the service is the only place they
     * are chosen.
     *
     * @param array<string, mixed> $result
     */
    private static function render(
        array $result,
        string $cacheControl,
        string $vary,
        int $okStatus = 200
    ): WP_REST_Response {
        if (($result['ok'] ?? false) !== true) {
            $data = isset($result['reason']) && is_string($result['reason'])
                ? ['reason_code' => $result['reason']]
                : null;

            return ApiResponse::error(
                (string) ($result['code'] ?? AnnouncementCommentService::ERR_UNAVAILABLE),
                (string) ($result['message'] ?? 'Unavailable.'),
                (int) ($result['status'] ?? 503),
                $data
            );
        }

        $response = ApiResponse::ok($result['data'] ?? [], $okStatus);
        $response->header('Cache-Control', $cacheControl);
        $response->header('Vary', $vary);

        return $response;
    }
}
