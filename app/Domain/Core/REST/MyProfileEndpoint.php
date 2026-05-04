<?php
/**
 * MyProfileEndpoint — handles /bcc/v1/me/profile.
 *
 * Five routes covering the §3.1 "Profile + account" settings surface:
 *
 *   PATCH  /me/profile           — update bio (text)
 *   POST   /me/profile/avatar    — upload custom avatar (multipart)
 *   DELETE /me/profile/avatar    — remove custom avatar (revert to default)
 *   POST   /me/profile/cover     — upload cover photo (multipart)
 *   DELETE /me/profile/cover     — remove cover photo
 *
 * Auth: required. Self-only — every route operates on `get_current_user_id()`
 * with no admin-override surface in V1. Anonymous requests return 401 with
 * the canonical error envelope (§1.4).
 *
 * Bio storage: `wp_users.description` via `wp_update_user`. Sanitized with
 * `sanitize_textarea_field` (strips ALL tags) and length-capped at 500
 * chars. The User view-model already exposes the bio via
 * `UserViewService::resolveBio` — no read-side change needed.
 *
 * Avatar / cover storage: PeepSo owns the image pipeline (resize, multiple
 * sizes, hash-named filesystem, user_meta `peepso_avatar_hash` /
 * `peepso_cover_hash`). We wrap PeepSo's PUBLIC methods on PeepSoUser
 * rather than reimplementing image processing:
 *   - move_avatar_file($src, $delete) + finalize_move_avatar_file($add_to_stream)
 *   - delete_avatar()
 *   - move_cover_file($src, $add_to_stream)
 *   - delete_cover_photo()
 *
 * If PeepSo is not loaded (defense-in-depth — the headless cleanup left
 * PeepSo as the canonical user/profile data layer), avatar/cover routes
 * return 503 with `bcc_peepso_unavailable`. Bio still works because it's
 * pure WordPress.
 *
 * Response shape (every route on success): the full updated User
 * view-model per §3.1, so the frontend can replace its local cache
 * without a separate refetch.
 *
 * Cache: `no-store` — per-viewer mutation responses with no shared-cache
 * value.
 *
 * @package BCC\Trust\Core\REST
 * @since V2 Phase 2 (Profile + account)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class MyProfileEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    /** Bio max length — matches PeepSo's typical profile-text cap. */
    private const BIO_MAX_LENGTH = 500;

    /** Avatar upload size cap in bytes (2 MB). PeepSo will resize smaller. */
    private const AVATAR_MAX_BYTES = 2 * 1024 * 1024;

    /** Cover photo upload size cap in bytes (5 MB). Covers are larger. */
    private const COVER_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Allowed image MIME types. Server detects actual MIME via
     * `wp_check_filetype_and_ext` rather than trusting the request's
     * Content-Type header.
     *
     * @var list<string>
     */
    private const ALLOWED_IMAGE_TYPES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    public static function register(): void
    {
        $instance = new self();

        // PATCH /me/profile — update bio (text only).
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/profile',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$instance, 'patch'],
                'permission_callback' => '__return_true',
            ]
        );

        // POST /me/profile/avatar — upload avatar.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/profile/avatar',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'uploadAvatar'],
                'permission_callback' => '__return_true',
            ]
        );

        // DELETE /me/profile/avatar — remove avatar (revert to default).
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/profile/avatar',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'deleteAvatar'],
                'permission_callback' => '__return_true',
            ]
        );

        // POST /me/profile/cover — upload cover photo.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/profile/cover',
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$instance, 'uploadCover'],
                'permission_callback' => '__return_true',
            ]
        );

        // DELETE /me/profile/cover — remove cover photo.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/profile/cover',
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$instance, 'deleteCover'],
                'permission_callback' => '__return_true',
            ]
        );

        // PATCH /me/profile/cover/position — drag-to-position cover crop.
        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/me/profile/cover/position',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$instance, 'patchCoverPosition'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    // ──────────────────────────────────────────────────────────────────
    // Bio
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function patch(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        // Bio is the only field this PATCH supports right now. Future
        // text-only profile fields (e.g., display_name) layer in here.
        $bioRaw = $request->get_param('bio');
        if ($bioRaw === null) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'No profile fields provided. Pass at least `bio`.',
                422
            );
        }

        if (!is_string($bioRaw)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Field `bio` must be a string.',
                422
            );
        }

        $bio = sanitize_textarea_field($bioRaw);
        if (strlen($bio) > self::BIO_MAX_LENGTH) {
            return ApiResponse::error(
                'bcc_invalid_request',
                sprintf('Bio must be %d characters or fewer.', self::BIO_MAX_LENGTH),
                422
            );
        }

        $result = wp_update_user([
            'ID'          => $userId,
            'description' => $bio,
        ]);
        if (is_wp_error($result)) {
            return ApiResponse::error(
                'bcc_internal_error',
                'Could not update bio. Please try again.',
                500
            );
        }

        return self::userResponse($userId);
    }

    // ──────────────────────────────────────────────────────────────────
    // Avatar
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function uploadAvatar(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $peepso = self::peepsoUserOrError($userId);
        if ($peepso instanceof WP_REST_Response) {
            return $peepso; // PeepSo not loaded — error envelope already built.
        }

        $file = self::extractFile($request, 'avatar', self::AVATAR_MAX_BYTES);
        if ($file instanceof WP_REST_Response) {
            return $file; // Validation error.
        }

        // PeepSoUser::move_avatar_file() handles resize + multi-size
        // generation; finalize_move_avatar_file() commits with a fresh
        // hash and fires `peepso_user_after_change_avatar`.
        try {
            $peepso->move_avatar_file($file['tmp_name'], true);
            $peepso->finalize_move_avatar_file(true);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[MyProfileEndpoint] avatar upload failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return ApiResponse::error(
                'bcc_upload_failed',
                'Could not save avatar. Please try again.',
                500
            );
        }

        return self::userResponse($userId);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function deleteAvatar(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $peepso = self::peepsoUserOrError($userId);
        if ($peepso instanceof WP_REST_Response) {
            return $peepso;
        }

        try {
            $peepso->delete_avatar();
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::warning('[MyProfileEndpoint] avatar delete failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            // Non-fatal — return success either way (idempotent).
        }

        return self::userResponse($userId);
    }

    // ──────────────────────────────────────────────────────────────────
    // Cover photo
    // ──────────────────────────────────────────────────────────────────

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function uploadCover(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $peepso = self::peepsoUserOrError($userId);
        if ($peepso instanceof WP_REST_Response) {
            return $peepso;
        }

        $file = self::extractFile($request, 'cover', self::COVER_MAX_BYTES);
        if ($file instanceof WP_REST_Response) {
            return $file;
        }

        // move_cover_file() resizes + writes + updates user_meta in one
        // call; no separate finalize step needed.
        try {
            $peepso->move_cover_file($file['tmp_name'], true);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::error('[MyProfileEndpoint] cover upload failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return ApiResponse::error(
                'bcc_upload_failed',
                'Could not save cover photo. Please try again.',
                500
            );
        }

        return self::userResponse($userId);
    }

    /**
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function deleteCover(WP_REST_Request $request): WP_REST_Response
    {
        unset($request);

        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $peepso = self::peepsoUserOrError($userId);
        if ($peepso instanceof WP_REST_Response) {
            return $peepso;
        }

        try {
            $peepso->delete_cover_photo();
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::warning('[MyProfileEndpoint] cover delete failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            // Non-fatal — idempotent.
        }

        return self::userResponse($userId);
    }

    /**
     * Set the user's cover photo crop position. Body shape:
     *   { x: number, y: number }   // both clamped to 0–100 (percent)
     *
     * Storage: peepso_cover_position_x + peepso_cover_position_y user_meta,
     * read by PeepSo's profile.php for cover rendering. The values are
     * percentages (0 = left/top, 100 = right/bottom), stored as integers.
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     */
    public function patchCoverPosition(WP_REST_Request $request): WP_REST_Response
    {
        $userId = get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $xRaw = $request->get_param('x');
        $yRaw = $request->get_param('y');

        if (!is_numeric($xRaw) || !is_numeric($yRaw)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Both `x` and `y` are required (numbers, 0–100 percent).',
                422
            );
        }

        // Clamp to 0–100 — out-of-range values are a client bug we
        // silently correct rather than reject.
        $x = max(0, min(100, (int) $xRaw));
        $y = max(0, min(100, (int) $yRaw));

        update_user_meta($userId, 'peepso_cover_position_x', (string) $x);
        update_user_meta($userId, 'peepso_cover_position_y', (string) $y);

        return self::userResponse($userId);
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /**
     * Build the success response — the full updated User view-model so
     * the frontend can sync its cache without a refetch. Always
     * `Cache-Control: no-store` since this is per-viewer mutation
     * response.
     */
    private static function userResponse(int $userId): WP_REST_Response
    {
        $user = Plugin::instance()->userViewService()->getUser($userId, $userId);
        if ($user === null) {
            return ApiResponse::error(
                'bcc_internal_error',
                'Could not load updated profile.',
                500
            );
        }

        $resp = ApiResponse::ok($user);
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }

    /**
     * Get the PeepSoUser instance for the given user, or return a 503
     * error response if PeepSo is not loaded (defense-in-depth — the
     * headless cleanup left PeepSo as the canonical avatar/cover
     * authority).
     *
     * @return \PeepSoUser|WP_REST_Response PeepSoUser instance or error response
     */
    private static function peepsoUserOrError(int $userId)
    {
        if (!class_exists('\\PeepSoUser')) {
            return ApiResponse::error(
                'bcc_peepso_unavailable',
                'Avatar and cover photo storage requires PeepSo. The administrator should activate the PeepSo plugin.',
                503
            );
        }

        return \PeepSoUser::get_instance($userId);
    }

    /**
     * Extract + validate an uploaded image file from the request.
     *
     * Returns the file array on success or a WP_REST_Response error
     * (caller checks instanceof to distinguish).
     *
     * Validation:
     *   - The named field exists in $_FILES
     *   - Upload error code is OK
     *   - Size ≤ $maxBytes
     *   - MIME (detected from file contents, not Content-Type) is in
     *     ALLOWED_IMAGE_TYPES
     *
     * @param WP_REST_Request<array<string, mixed>> $request
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|WP_REST_Response
     */
    private static function extractFile(WP_REST_Request $request, string $field, int $maxBytes)
    {
        $files = $request->get_file_params();
        if (!isset($files[$field]) || !is_array($files[$field])) {
            return ApiResponse::error(
                'bcc_invalid_request',
                sprintf('Multipart file field `%s` is required.', $field),
                422
            );
        }

        /** @var array{name: string, type: string, tmp_name: string, error: int, size: int} $file */
        $file = $files[$field];

        // PHP upload error codes — UPLOAD_ERR_OK = 0.
        if (!isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
            return ApiResponse::error(
                'bcc_upload_failed',
                'Upload failed. Try a smaller file or check your connection.',
                422
            );
        }

        if (!isset($file['tmp_name']) || !is_string($file['tmp_name']) || $file['tmp_name'] === '') {
            return ApiResponse::error(
                'bcc_upload_failed',
                'Upload payload was malformed.',
                422
            );
        }

        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Uploaded file is empty.',
                422
            );
        }
        if ($size > $maxBytes) {
            return ApiResponse::error(
                'bcc_invalid_request',
                sprintf('File exceeds the %d-byte limit.', $maxBytes),
                422
            );
        }

        // Detect actual MIME from file contents — never trust the
        // request's Content-Type header.
        $detected = wp_check_filetype_and_ext(
            $file['tmp_name'],
            isset($file['name']) && is_string($file['name']) ? $file['name'] : ''
        );
        $mime = isset($detected['type']) && is_string($detected['type']) ? $detected['type'] : '';
        if (!in_array($mime, self::ALLOWED_IMAGE_TYPES, true)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Only JPEG, PNG, and WebP images are allowed.',
                422
            );
        }

        return $file;
    }
}
