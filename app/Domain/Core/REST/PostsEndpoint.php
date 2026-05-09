<?php
/**
 * Posts Endpoint
 *
 * Routes:
 *   - POST   /bcc/v1/posts                  — create a status post / review / blog
 *   - POST   /bcc/v1/posts/photo            — create a photo post (multipart)
 *   - POST   /bcc/v1/posts/gif              — create a GIF post (JSON, Giphy URL)
 *   - DELETE /bcc/v1/me/reviews/(?P<id>\d+) — remove the viewer's review on a page
 *
 * V1 scope: status posts (open to all signed-in viewers), reviews
 * (gated on Level 2 + reputation tier ≥ neutral via FeatureAccessService),
 * §D6 blog posts (open to all signed-in viewers; long-form content
 * with an excerpt that surfaces on the Floor and a full_text that
 * surfaces in the per-user blog tab), v1.5 photo posts (multipart;
 * single photo per post; optional caption), and v1.5 GIF posts (JSON
 * with a Giphy URL — wraps PeepSo's existing giphy plugin which owns
 * the API key + content rating + post_meta storage; BCC owns the
 * picker UI + activity surface). Disputes and post-as-entity remain
 * V1.5/V2 per §P1.
 *
 * Auth: required (401 anonymously). The body shape mirrors the
 * §D1 Composer's contract — `kind` is reserved for future expansion;
 * V1 only accepts 'status'.
 *
 * Response is intentionally minimal: a feed_id pointer + the wp_post
 * ID and act_id. The frontend invalidates its cached feed query and
 * refetches via FeedRankingService — that's the single source of the
 * fully-hydrated FeedItem (avoids duplicating reactions/permissions/
 * social_proof hydration here).
 *
 * @package BCC\Trust\Core\REST
 * @since V1 (2026-04, §D1 status composer)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class PostsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/reviews/(?P<id>\d+)',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'removeReview'],
                'permission_callback' => '__return_true',
                'args' => [
                    'id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/posts',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'create'],
                'permission_callback' => '__return_true',
                'args' => [
                    'kind' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => static function ($value): string {
                            return is_string($value) ? sanitize_key($value) : '';
                        },
                    ],
                    'content' => [
                        'required'          => true,
                        'type'              => 'string',
                        // No wp_kses here — let PeepSo's add_post handle
                        // escaping (it htmlspecialchars's content as part
                        // of its canonical write path). Sanitizing twice
                        // would double-encode user input.
                    ],
                    // Review-only fields. The handler validates per-kind
                    // so these stay optional at the route level.
                    'target_page_id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'grade' => [
                        'required'          => false,
                        'type'              => 'string',
                        'sanitize_callback' => static function ($value): string {
                            return is_string($value) ? sanitize_key($value) : '';
                        },
                    ],
                    // Blog-only field. `content` carries the full_text;
                    // `excerpt` is the Floor-context teaser. The service
                    // validates lengths per §D6.
                    'excerpt' => [
                        'required'          => false,
                        'type'              => 'string',
                    ],
                    // Optional group-wall target. When > 0 the post lands
                    // on the group's wall; the service gates on existence
                    // (404 — defense-in-depth, no existence leak) and
                    // active membership (403). Status / blog kinds both
                    // observe this; review kind ignores it (reviews are
                    // page-scoped, not wall-scoped).
                    'group_id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // v1.5 photo post — multipart (file + optional caption).
        // Separate route from /posts because multipart vs JSON
        // request shapes don't share validation cleanly: the file
        // arrives via $_FILES (read inside the handler), and the
        // caption is a plain text field. Trying to overload /posts
        // would mean two distinct content-type contracts on one
        // route — sludge.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/posts/photo',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'createPhoto'],
                'permission_callback' => '__return_true',
                'args' => [
                    'caption' => [
                        'required'          => false,
                        'type'              => 'string',
                        // PeepSo's add_post handles content escaping;
                        // we only trim/cap in the service layer.
                    ],
                    // Optional group-wall target — same semantics as on
                    // /posts (status). Multipart form field, so the
                    // wire shape is a string-coerced integer; absint
                    // sanitizes empty / non-numeric to 0.
                    'group_id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );

        // v1.5 GIF post — JSON body { url, caption? }. URL is a
        // Giphy CDN URL the BCC frontend's GIF picker selected.
        // PeepSo's existing giphy plugin owns the post_meta storage
        // (peepso/classes/giphy.php:409 after_add_post hook); BCC
        // drives PeepSo's flow via $_POST superglobals from the
        // PeepSoGifWriter. Honors the giphy_posts_enable admin
        // toggle on the integration-config side (see
        // IntegrationsEndpoint) — by the time the request lands here
        // the frontend should already have hidden the GIF button.
        // Server-side we still validate the URL contains 'giphy.com'
        // (mirrors PeepSo's own check); the integration-toggle is
        // not re-checked at write time because PeepSo's hook only
        // fires when its own `giphy_posts_enable` is true OR is not
        // gated on this server-side path (admins disabling GIF posts
        // for the *picker UI* in PeepSo's own postbox don't lock
        // the underlying API).
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/posts/gif',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'createGif'],
                'permission_callback' => '__return_true',
                'args' => [
                    'url' => [
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'esc_url_raw',
                    ],
                    'caption' => [
                        'required'          => false,
                        'type'              => 'string',
                    ],
                    // Optional group-wall target — same semantics as on
                    // /posts (status) and /posts/photo.
                    'group_id' => [
                        'required'          => false,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                ],
            ]
        );
    }

    public function create(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $kind = (string) ($request->get_param('kind') ?? 'status');
        if ($kind === '') {
            $kind = 'status';
        }

        $content = (string) $request->get_param('content');
        $groupId = (int) ($request->get_param('group_id') ?? 0);
        $service = Plugin::instance()->postsService();

        if ($kind === 'status') {
            $result = $service->createStatus($viewerId, $content, $groupId);
        } elseif ($kind === 'review') {
            // Reviews are page-scoped, not wall-scoped — group_id is
            // ignored here on purpose. Surfacing it would create a
            // semantic conflict with target_page_id.
            $targetPageId = (int) $request->get_param('target_page_id');
            $grade        = (string) ($request->get_param('grade') ?? '');
            $result       = $service->createReview($viewerId, $targetPageId, $grade, $content);
        } elseif ($kind === 'blog') {
            // §D6 — `content` carries the full_text; `excerpt` is the
            // Floor-context teaser. Service enforces length bounds.
            // Blog group_id support is V2 — V1 blogs always land on the
            // author's own wall. Surface a clear error if a caller
            // passes group_id with kind=blog so the contract stays
            // honest about the scope mismatch.
            if ($groupId > 0) {
                return ApiResponse::error(
                    'bcc_invalid_request',
                    'Blog posts cannot target a group wall in V1.',
                    400
                );
            }
            $excerpt = (string) ($request->get_param('excerpt') ?? '');
            $result  = $service->createBlog($viewerId, $excerpt, $content);
        } else {
            // Disputes / post-as-entity are explicit V1.5/V2 work —
            // reject unknown kinds with a clear contract.
            return ApiResponse::error(
                'bcc_invalid_request',
                'Unsupported post kind. V1 accepts "status", "review", or "blog".',
                400
            );
        }

        if (isset($result['error'])) {
            return self::forwardServiceError($result);
        }

        $response = ApiResponse::ok($result);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * Multipart handler for /bcc/v1/posts/photo.
     *
     * Accepts a single file under the `photo` field + an optional
     * `caption` text field. File validation (mime, size cap) lives
     * inside `PeepSoPhotoWriter::createSelfPhotoPost` so a future
     * cross-endpoint refactor that extracts a shared multipart
     * validator only has to wire the writer's response codes.
     *
     * The validator pattern + size caps mirror MyProfileEndpoint's
     * extractFile flow (avatar/cover) but inline here rather than
     * lifted to a shared helper: the photo path's failure modes
     * differ enough (mime allowlist, GIF special case, the writer's
     * own staging steps) that a generic helper would have to grow
     * branches anyway. Separate slice if patterns multiply.
     */
    public function createPhoto(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // WP_REST_Request->get_file_params() returns the $_FILES array
        // for this request. Extract the `photo` field; reject early
        // when missing so the writer never sees an empty struct.
        $files = $request->get_file_params();
        $file  = is_array($files) && isset($files['photo']) ? $files['photo'] : null;
        if (!is_array($file)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Photo file is required (multipart field "photo").',
                400
            );
        }

        // Multi-photo posts are V2 — when a request sends multiple
        // files (PHP populates the array fields with arrays) reject
        // early rather than silently dropping the extras.
        if (isset($file['name']) && is_array($file['name'])) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Single photo per post in V1.',
                400
            );
        }

        $caption = (string) ($request->get_param('caption') ?? '');
        $groupId = (int) ($request->get_param('group_id') ?? 0);
        $result  = Plugin::instance()->postsService()->createPhotoPost($viewerId, $file, $caption, $groupId);

        if (isset($result['error'])) {
            return self::forwardServiceError($result);
        }

        $response = ApiResponse::ok($result);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * JSON handler for /bcc/v1/posts/gif.
     *
     * Accepts a Giphy URL + optional caption text. URL validation
     * (must contain `giphy.com`) lives inside `PeepSoGifWriter` so
     * a stricter regex never diverges from PeepSo's own check.
     *
     * No multipart, no file handling — the GIF stays on Giphy's
     * CDN; we persist only the URL via PeepSo's existing
     * `peepso_giphy` post_meta surface.
     */
    public function createGif(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $url     = (string) $request->get_param('url');
        $caption = (string) ($request->get_param('caption') ?? '');
        $groupId = (int) ($request->get_param('group_id') ?? 0);

        $result = Plugin::instance()->postsService()->createGifPost($viewerId, $url, $caption, $groupId);
        if (isset($result['error'])) {
            return self::forwardServiceError($result);
        }

        $response = ApiResponse::ok($result);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    public function removeReview(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $pageId = (int) $request->get_param('id');
        $result = Plugin::instance()->postsService()->removeReview($viewerId, $pageId);

        if (isset($result['error'])) {
            $code   = (string) $result['error'];
            $status = self::statusForCode($code);
            return ApiResponse::error($code, (string) ($result['message'] ?? ''), $status);
        }

        $response = ApiResponse::ok($result);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    private static function statusForCode(string $code): int
    {
        return match ($code) {
            'bcc_unauthorized'           => 401,
            'bcc_forbidden'              => 403,
            'bcc_permission_denied'      => 403,
            'bcc_not_found'              => 404,
            'bcc_invalid_request'        => 400,
            'bcc_invalid_mention_target' => 400,
            'bcc_too_many_mentions'      => 400,
            'bcc_rate_limited'           => 429,
            'bcc_unavailable'            => 503,
            default                      => 400,
        };
    }

    /**
     * Forward an error envelope from PostsService through ApiResponse.
     *
     * Carries the optional `data` block (per §3.3.12 — used by
     * `bcc_invalid_mention_target` to echo `user_id`,
     * `bcc_too_many_mentions` to echo `max`). Other handlers can
     * adopt this helper as more endpoints grow structured error
     * payloads.
     *
     * @param array<string, mixed> $result
     */
    private static function forwardServiceError(array $result): WP_REST_Response
    {
        $code    = (string) ($result['error'] ?? 'bcc_unavailable');
        $message = (string) ($result['message'] ?? '');
        $status  = self::statusForCode($code);
        /** @var array<string, mixed>|null $data */
        $data = isset($result['data']) && is_array($result['data']) ? $result['data'] : null;
        return ApiResponse::error($code, $message, $status, $data);
    }
}
