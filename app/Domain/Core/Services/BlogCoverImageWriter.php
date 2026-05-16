<?php
/**
 * Blog Cover Image Writer — §D6 PR-B.
 *
 * Wraps WordPress's `wp_handle_upload()` + `wp_insert_attachment()`
 * to turn a multipart cover-image upload into an attachment that the
 * blog composer can pin via `set_post_thumbnail()`.
 *
 * Why a dedicated writer (vs. reusing PostsService::createPhotoPost):
 *   - `createPhotoPost` emits a peepso_activities feed item (wrong
 *     shape — a cover image is metadata for a blog post, not a
 *     standalone feed post).
 *   - The cover image needs to be a plain `attachment` post_type with
 *     `post_author = uploader` so PostsService::createBlog's
 *     author-ownership check accepts it.
 *   - `createPhotoPost` routes through PeepSo's add_post hook chain
 *     (S3 staging, multi-size processing, notification fanout). A
 *     cover image bypasses all that — it's just a WP attachment.
 *
 * Author ownership is the security gate: setting `post_author` to the
 * authenticated user is what makes PostsService::createBlog's check
 * (`(int) $attachment->post_author === $authorId`) pass downstream.
 * Cross-uploader pinning is rejected at the blog write boundary.
 *
 * §1 compliance: this is a Service. All persistence flows through
 * WP-native primitives (`wp_handle_upload`, `wp_insert_attachment`,
 * `wp_update_attachment_metadata`) — no raw `$wpdb`. WP itself owns
 * the wp_posts INSERT.
 *
 * @package BCC\Trust\Core\Services
 * @since V1.5 (2026-05, §D6 crypto-blog composer PR-B)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Log\Logger;
use BCC\Core\Security\Throttle;

if (!defined('ABSPATH')) {
    exit;
}

final class BlogCoverImageWriter
{
    /**
     * Hard size cap for a blog cover image (bytes). 8 MiB matches the
     * upper bound modern phone cameras produce after WebP/HEIC
     * compression; anything bigger almost always means an
     * out-of-spec upload that should be rejected loudly rather than
     * silently resized.
     */
    public const MAX_BYTES = 8_388_608; // 8 MiB

    /**
     * MIME allowlist — the four formats the composer renders. We
     * intentionally do NOT include SVG (XSS surface) or HEIC (no
     * browser-native render path).
     *
     * @var list<string>
     */
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /**
     * Validate + persist a single multipart cover-image upload.
     *
     * `$file` is the loose shape `WP_REST_Request::get_file_params()`
     * returns — keys defined by `$_FILES` semantics, values are
     * `mixed` from PHPStan's perspective. We re-validate every key
     * before use so a malformed upload payload can't crash the
     * pipeline.
     *
     * Returns the success shape on commit or an envelope-ready error
     * shape (`{error, message, data?}`) on any failure.
     *
     * The throttle fires BEFORE the disk write so a flood doesn't
     * fill the uploads directory before the seatbelt can clip it.
     *
     * @param array<string, mixed> $file
     * @return array{
     *   ok: true,
     *   attachment_id: int,
     *   url: string,
     *   width: int,
     *   height: int
     * }|array{
     *   error: string,
     *   message: string,
     *   data?: array<string, mixed>
     * }
     */
    public function upload(int $authorId, array $file): array
    {
        if ($authorId <= 0) {
            return ['error' => 'bcc_unauthorized', 'message' => 'Sign in required.'];
        }

        // ── Throttle (fires BEFORE wp_handle_upload) ─────────────────
        //
        // Burst seatbelt — composer use is bursty by nature ("pick,
        // preview, change my mind, pick another") so the limit is
        // permissive within a 60-second window. NOT a primary defense;
        // fires-in-logs is the §K1 signal.
        $burstKey = "blog_cover:{$authorId}:burst";
        if (!Throttle::allow(
            $burstKey,
            BCC_TRUST_RATE_LIMIT_BLOG_COVER_UPLOAD,
            BCC_TRUST_RATE_WINDOW_BLOG_COVER_UPLOAD
        )) {
            Logger::info('[BlogCoverImageWriter] cover-upload burst seatbelt fired', [
                'user_id' => $authorId,
                'limit'   => BCC_TRUST_RATE_LIMIT_BLOG_COVER_UPLOAD,
                'window'  => BCC_TRUST_RATE_WINDOW_BLOG_COVER_UPLOAD,
            ]);
            return [
                'error'   => 'bcc_rate_limited',
                'message' => 'Too fast. Wait a moment before uploading again.',
            ];
        }

        // ── Defensive validation of the $_FILES-shaped struct ────────
        $errorCode = $file['error'] ?? null;
        if (!is_int($errorCode) || $errorCode !== UPLOAD_ERR_OK) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Upload failed. Try a smaller file or check your connection.',
            ];
        }

        $tmpName = $file['tmp_name'] ?? '';
        if (!is_string($tmpName) || $tmpName === '') {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Upload payload was malformed.',
            ];
        }

        $sizeRaw = $file['size'] ?? 0;
        $size    = is_int($sizeRaw) ? $sizeRaw : (int) $sizeRaw;
        if ($size <= 0) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Uploaded file is empty.',
            ];
        }
        if ($size > self::MAX_BYTES) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => sprintf('Cover image exceeds the %d-byte limit.', self::MAX_BYTES),
                'data'    => ['max_bytes' => self::MAX_BYTES],
            ];
        }

        $fileName = $file['name'] ?? '';
        if (!is_string($fileName) || $fileName === '') {
            $fileName = 'cover-image';
        }

        // MIME detection from FILE CONTENTS, not the request header —
        // mirror MyProfileEndpoint::extractFile's pattern. A malicious
        // client can lie in Content-Type; the file-magic check can't
        // be spoofed without a craftable polyglot.
        $detected = wp_check_filetype_and_ext($tmpName, $fileName);
        $mime     = isset($detected['type']) && is_string($detected['type']) ? $detected['type'] : '';
        if ($mime === '' || !in_array($mime, self::ALLOWED_MIMES, true)) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Only JPEG, PNG, WebP, and GIF cover images are allowed.',
                'data'    => ['mime' => $mime],
            ];
        }

        // ── wp_handle_upload (WP-native; handles tmp move + perms) ───
        //
        // Required by wp_handle_upload's contract (it inspects $_POST
        // for nonce-like fields and refuses without `test_form=false`).
        // We've already validated auth and rate-limited above; this
        // flag tells WP to skip its own form-context checks for an
        // REST-handler upload path.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $overrides = [
            'test_form' => false,
            // Restrict to the same allowlist we already validated
            // against — defense in depth if wp_check_filetype_and_ext
            // and wp_handle_upload disagree (different filter chains
            // could let one through and the other catch it).
            'mimes'     => [
                'jpg|jpeg|jpe' => 'image/jpeg',
                'png'          => 'image/png',
                'webp'         => 'image/webp',
                'gif'          => 'image/gif',
            ],
        ];

        // wp_handle_upload reads from $_FILES by reference — pass the
        // file struct in the shape PHP gives us (keys + tmp_name path).
        // We've already type-narrowed every used field.
        $fileForWP = [
            'name'     => $fileName,
            'type'     => $mime,
            'tmp_name' => $tmpName,
            'size'     => $size,
            'error'    => UPLOAD_ERR_OK,
        ];

        $moved = wp_handle_upload($fileForWP, $overrides);
        if (!is_array($moved) || isset($moved['error'])) {
            $err = 'unknown';
            if (is_array($moved) && isset($moved['error']) && is_string($moved['error'])) {
                $err = $moved['error'];
            }
            Logger::warning('[BlogCoverImageWriter] wp_handle_upload failed', [
                'user_id' => $authorId,
                'error'   => $err,
            ]);
            return [
                'error'   => 'bcc_unavailable',
                'message' => 'Could not save your image. Try again.',
            ];
        }

        // Narrow the success-shape fields via runtime checks. wp_handle_upload
        // documents `file` + `url` + `type` on the success branch, but PHPStan
        // can't infer the conditional shape from is_array + isset alone — so
        // we re-check each key explicitly and bail to bcc_unavailable on any
        // missing/malformed entry (no @var suppression).
        if (!isset($moved['file']) || !is_string($moved['file']) || $moved['file'] === ''
            || !isset($moved['url']) || !is_string($moved['url']) || $moved['url'] === ''
        ) {
            Logger::warning('[BlogCoverImageWriter] wp_handle_upload returned malformed success shape', [
                'user_id' => $authorId,
            ]);
            return [
                'error'   => 'bcc_unavailable',
                'message' => 'Could not save your image. Try again.',
            ];
        }
        $savedPath = $moved['file'];
        $savedUrl  = $moved['url'];
        $savedMime = isset($moved['type']) && is_string($moved['type']) && $moved['type'] !== ''
            ? $moved['type']
            : $mime;

        // ── wp_insert_attachment (WP-native; INSERT into wp_posts) ───
        //
        // post_author = $authorId is the security gate that makes
        // PostsService::createBlog's ownership check accept this
        // attachment as a cover image later.
        //
        // post_title: filename without extension. preg_replace returns
        // string|null (null on engine error, never in practice for a
        // pure-string subject), so we fall back to basename() for the
        // type guarantee.
        $baseName  = basename($savedPath);
        $stripped  = preg_replace('/\.[^.]+$/', '', $baseName);
        $postTitle = is_string($stripped) && $stripped !== '' ? $stripped : $baseName;

        $attachmentId = wp_insert_attachment(
            [
                'guid'           => $savedUrl,
                'post_mime_type' => $savedMime,
                'post_title'     => $postTitle,
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_author'    => $authorId,
            ],
            $savedPath,
            0, // parent post id — 0 until the blog cover-pins this attachment
            true // wp_error mode so we can surface real errors as an envelope
        );

        if (is_wp_error($attachmentId) || (int) $attachmentId <= 0) {
            $errMsg = is_wp_error($attachmentId) ? $attachmentId->get_error_message() : 'insert returned non-positive id';
            Logger::warning('[BlogCoverImageWriter] wp_insert_attachment failed', [
                'user_id' => $authorId,
                'error'   => $errMsg,
            ]);
            // Best-effort cleanup of the moved file so a failed insert
            // doesn't litter the uploads directory.
            @unlink($savedPath);
            return [
                'error'   => 'bcc_unavailable',
                'message' => 'Could not save your image. Try again.',
            ];
        }
        $attachmentId = (int) $attachmentId;

        // Generate intermediate sizes + populate _wp_attachment_metadata
        // so the frontend can use the standard WP responsive-image
        // helpers + so width/height are available without re-reading
        // the file. Returns array with width/height keys.
        $meta = wp_generate_attachment_metadata($attachmentId, $savedPath);

        $width  = 0;
        $height = 0;
        if (is_array($meta) && $meta !== []) {
            wp_update_attachment_metadata($attachmentId, $meta);
            if (isset($meta['width']) && is_int($meta['width'])) {
                $width = $meta['width'];
            }
            if (isset($meta['height']) && is_int($meta['height'])) {
                $height = $meta['height'];
            }
        }

        return [
            'ok'            => true,
            'attachment_id' => $attachmentId,
            'url'           => $savedUrl,
            'width'         => $width,
            'height'        => $height,
        ];
    }
}
