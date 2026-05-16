<?php
/**
 * Blog Cover Image Endpoint — §D6 PR-B.
 *
 * Route:
 *   - POST /bcc/v1/blog/cover-image — multipart cover-image upload
 *
 * Body shape (multipart/form-data):
 *   - `cover_image` (file, single) — the image binary
 *
 * Wraps {@see BlogCoverImageWriter::upload()}; this handler's job is
 * to extract the file from $_FILES, narrow the unknown WP_REST_Request
 * shape, and translate the service envelope into ApiResponse.
 *
 * Validation lives in the writer:
 *   - mime ∈ {jpeg, png, webp, gif}
 *   - size ≤ BlogCoverImageWriter::MAX_BYTES (8 MiB)
 *   - PHP upload-error code = UPLOAD_ERR_OK
 *   - throttle = 5 uploads / 60s / user
 *
 * Auth: required (401 anonymously). The author-ownership check that
 * matters happens DOWNSTREAM — PostsService::createBlog refuses a
 * cover image whose post_author ≠ the blog author. So even if a
 * client crafted an attachment_id from another user's upload, the
 * blog write would reject it. We don't need a second check here.
 *
 * Multi-file uploads are rejected at the handler boundary (single
 * file per cover-image request — multi-cover composition is not in
 * V1 scope). Mirrors PostsEndpoint::createPhoto's rejection shape.
 *
 * @package BCC\Trust\Core\REST
 * @since V1.5 (2026-05, §D6 crypto-blog composer PR-B)
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

final class BlogCoverImageEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/blog/cover-image',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'upload'],
                // Service-layer enforces auth + ownership; permission
                // callback returns true so anonymous requests still
                // reach the handler and get a proper 401 envelope (vs.
                // WP's default `rest_forbidden` shape).
                'permission_callback' => '__return_true',
                // No `args` declared — the file rides $_FILES, not
                // get_param. Declaring `cover_image` as a typed arg
                // would cause WP to try to pull it from JSON body /
                // query string and fail.
            ]
        );
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function upload(WP_REST_Request $request): WP_REST_Response
    {
        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Extract the cover-image file. WP_REST_Request::get_file_params
        // returns the $_FILES array for this request.
        $files = $request->get_file_params();
        $file  = is_array($files) && isset($files['cover_image']) ? $files['cover_image'] : null;
        if (!is_array($file)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Cover image is required (multipart field "cover_image").',
                400
            );
        }

        // Multi-file uploads: when PHP gets multiple files under one
        // field name, every value in the $_FILES entry becomes an
        // array. Reject the multi-shape early — single cover per
        // request in V1.5.
        if (isset($file['name']) && is_array($file['name'])) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Single cover image per request.',
                400
            );
        }

        $result = Plugin::instance()->blogCoverImageWriter()->upload($viewerId, $file);

        if (isset($result['error'])) {
            return self::forwardServiceError($result);
        }

        $response = ApiResponse::ok($result);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    private static function statusForCode(string $code): int
    {
        return match ($code) {
            'bcc_unauthorized'    => 401,
            'bcc_forbidden'       => 403,
            'bcc_not_found'       => 404,
            'bcc_invalid_request' => 400,
            'bcc_rate_limited'    => 429,
            'bcc_unavailable'     => 503,
            default               => 400,
        };
    }

    /**
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
