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
     * Max total pixels (width × height) before the image reaches GD/Imagick.
     * MIME + byte-size checks don't stop a decompression bomb — a small file
     * that decodes to a huge bitmap OOMs the worker. 24 MP is well above any
     * legitimate cover image. See MyProfileEndpoint for the sibling guard.
     */
    private const MAX_IMAGE_PIXELS = 24_000_000;

    /** Max single-side length — guards extreme-aspect-ratio bombs. */
    private const MAX_IMAGE_SIDE = 10_000;

    /**
     * Prefix for the STORED filename. The uploader's own filename is never
     * used on disk.
     *
     * ⚠⚠ THE ORIGINAL FILENAME IS PUBLISHED TWICE, AND BOTH ARE PUBLIC.
     * `wp_handle_upload()` keeps the caller's base name, so it lands in the
     * uploads path — i.e. in the attachment's `source_url` — and
     * `wp_insert_attachment()` below derives `post_title` from that same
     * base name. Nothing in the plugins restricts `/wp/v2/media`, so core's
     * defaults expose both. A name like `jane-doe-headshot-final.jpg` is
     * then a published fact about the uploader.
     *
     * Neutralising the name HERE fixes both at once: the title is derived
     * from the stored basename, so it inherits the neutral name for free
     * rather than needing its own rule that could drift from this one.
     */
    private const STORED_NAME_PREFIX = 'cover-';

    /**
     * Random BYTES in the stored filename. bin2hex doubles it, so the name
     * carries 16 hex characters.
     *
     * ⚠ Declared as bytes, not as hex characters halved at the call site:
     * `random_bytes(intdiv(16, 2))` is plain `int`, and random_bytes()
     * wants `int<1, max>`, so the division form needed a cast to pass
     * PHPStan level 8. The type system was right — the constant should
     * mean what the call actually consumes.
     */
    private const STORED_NAME_BYTES = 8;

    /**
     * Canonical extension per accepted MIME. Taken from the DETECTED mime
     * (file magic), never from the caller's filename — the whole point is
     * that no caller-supplied byte reaches the stored name.
     *
     * @var array<string, string>
     */
    private const EXTENSION_BY_MIME = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    /**
     * MIMEs whose stored bytes are left alone. Everything else is
     * stripped.
     *
     * ⚠⚠ A SKIP LIST, NOT A STRIP LIST, SO AN UNSEEN MIME FAILS CLOSED.
     * Spelling it the other way round — listing the mimes to strip — reads
     * identically today, because ALLOWED_MIMES gates upload() to these
     * four. But it fails OPEN: widen ALLOWED_MIMES later (HEIC is the
     * obvious candidate, and HEIC is full of EXIF) and the new format
     * would be stored unstripped, silently, with no test failing. This
     * direction makes the omission refuse the upload instead.
     *
     * ⚠ GIF IS THE ONE DELIBERATE EXCLUSION, and not because GIF is
     * trusted. Re-encoding an animated GIF through either image editor
     * FLATTENS IT TO ONE FRAME, so stripping would silently destroy the
     * upload. GIF also has no EXIF chunk, so the GPS this control exists
     * for cannot be present. A GIF *can* hold a Comment/XMP extension,
     * which is NOT stripped — stated here rather than left to be found.
     *
     * @var list<string>
     */
    private const METADATA_STRIP_SKIP_MIMES = [
        'image/gif',
    ];

    /**
     * The stored filename: a neutral name with no caller-supplied byte.
     *
     * Pure, so it is asserted BY VALUE rather than by reading the source.
     * An unknown mime cannot reach here (upload() checks ALLOWED_MIMES
     * first) but falls back to `bin` rather than to the caller's
     * extension, so a future caller cannot reintroduce the leak by
     * widening the allowlist and forgetting this map.
     */
    public static function neutralStoredName(string $mime): string
    {
        $ext = self::EXTENSION_BY_MIME[$mime] ?? 'bin';

        return self::STORED_NAME_PREFIX
            . bin2hex(random_bytes(self::STORED_NAME_BYTES))
            . '.' . $ext;
    }

    /**
     * Whether this MIME's stored bytes must be re-written to drop
     * embedded metadata. Pure + public so the decision table is asserted
     * by value; see METADATA_STRIP_MIMES for why GIF is excluded.
     */
    public static function metadataStripApplies(string $mime): bool
    {
        return !in_array($mime, self::METADATA_STRIP_SKIP_MIMES, true);
    }

    /**
     * Rewrite $path so it carries no embedded metadata. Returns false only
     * when a strip was REQUIRED and could not be completed.
     *
     * ⚠⚠ A PLAIN `$editor->save()` IS NOT A STRIP ON IMAGICK. Measured
     * against WordPress 7.0.2's own editor: `strip_meta()` is called from
     * exactly one place, `thumbnail_image()` (line 443, gated on the
     * `image_strip_meta` filter), and `_save()` only calls
     * `setImageFormat()`. `resize()` additionally returns EARLY when the
     * target equals the current size, so a no-op resize cannot be used to
     * reach the strip either. So on an Imagick host a re-save preserves
     * EXIF — which is why Imagick is driven directly below rather than
     * through the editor.
     *
     * GD has no metadata model at all: decoding to a resource and
     * re-encoding drops everything, so the editor path is sufficient
     * there and is the fallback.
     *
     * ⚠ The two IO branches are NOT exercised by the unit harness, which
     * has neither extension loaded. metadataStripApplies() and
     * neutralStoredName() are; the branch SELECTION is asserted through a
     * stubbed `wp_get_image_editor`. The Imagick branch needs a staging
     * check before release — named in the PR rather than implied.
     */
    private static function stripEmbeddedMetadata(string $path, string $mime): bool
    {
        if (!self::metadataStripApplies($mime)) {
            return true;
        }

        // Imagick: the only definitive strip available, and the only one
        // that covers a file already small enough that no resize happens.
        if (class_exists('\Imagick')) {
            try {
                $image = new \Imagick($path);
                $image->stripImage();
                $image->writeImage($path);
                $image->clear();

                return true;
            } catch (\Throwable $e) {
                Logger::warning('[BlogCoverImageWriter] Imagick strip threw', [
                    'mime'  => $mime,
                    'error' => $e->getMessage(),
                ]);
                // Fall through to the editor rather than failing outright:
                // a GD re-encode still drops the metadata.
            }
        }

        $editor = wp_get_image_editor($path);
        if (is_wp_error($editor) || !is_object($editor)) {
            return false;
        }

        $saved = $editor->save($path, $mime);
        if (is_wp_error($saved) || !is_array($saved)) {
            return false;
        }

        // ⚠⚠ save() MAY WRITE SOMEWHERE ELSE, AND THEN REPORT SUCCESS.
        // _save() routes its target through
        // wp_get_image_editor_output_format() — a FILTERABLE mime→extension
        // map — and falls back to generate_filename() when that comes back
        // empty. So on a host that maps, say, JPEG to WebP, the re-encode
        // lands on a NEW file and the unstripped original stays exactly
        // where we put it. Accepting that as a strip would be a silent
        // fail-open, which is the one failure mode this control cannot
        // have. Verified against WordPress's own editor: _save() returns
        // 'path' => $filename, so the destination is observable.
        $writtenTo = isset($saved['path']) && is_string($saved['path'])
            ? $saved['path']
            : '';

        return $writtenTo === $path;
    }

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

        // Pixel-dimension guard BEFORE wp_handle_upload →
        // wp_generate_attachment_metadata decodes via GD/Imagick. getimagesize
        // reads only the header, so it runs ahead of any decompression bomb's
        // allocation.
        $dimensions = @getimagesize($tmpName);
        if (!is_array($dimensions) || $dimensions[0] < 1 || $dimensions[1] < 1) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Could not read the image. Please upload a valid image file.',
            ];
        }
        $width  = $dimensions[0];
        $height = $dimensions[1];
        if ($width > self::MAX_IMAGE_SIDE
            || $height > self::MAX_IMAGE_SIDE
            || $width * $height > self::MAX_IMAGE_PIXELS
        ) {
            return [
                'error'   => 'bcc_invalid_request',
                'message' => 'Cover image dimensions are too large.',
                'data'    => ['max_pixels' => self::MAX_IMAGE_PIXELS],
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
        // ⚠ NOT $fileName — see STORED_NAME_PREFIX. $fileName is the
        // caller's own name and is needed ABOVE, because
        // wp_check_filetype_and_ext() matches the extension against the
        // bytes; it must not survive past that check.
        $storedName = self::neutralStoredName($mime);

        $fileForWP = [
            'name'     => $storedName,
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

        // ── Strip embedded metadata BEFORE the attachment exists ─────
        //
        // ⚠⚠ THE ORDER IS LOAD-BEARING, NOT TIDINESS.
        // wp_generate_attachment_metadata() below calls
        // wp_read_image_metadata(), which copies what it finds into
        // `_wp_attachment_metadata.image_meta`. Stripping first means
        // there is nothing to copy, so the file AND the postmeta are both
        // clean from one call. Stripping afterwards would leave the
        // postmeta copy behind.
        //
        // ⚠ WP's image_meta never carried GPS, so the exposure this closes
        // is the STORED FILE, which is served publicly from uploads/ and
        // keeps every EXIF tag the camera wrote, GPS included.
        //
        // ⛔ FAIL CLOSED. A privacy control that silently no-ops is worse
        // than one that refuses: the operator would have no way to know
        // their location rode along. The upload is rejected and the moved
        // file is removed.
        if (!self::stripEmbeddedMetadata($savedPath, $savedMime)) {
            Logger::warning('[BlogCoverImageWriter] metadata strip failed — refusing the upload', [
                'user_id' => $authorId,
                'mime'    => $savedMime,
            ]);
            @unlink($savedPath);
            return [
                'error'   => 'bcc_unavailable',
                'message' => 'Could not process your image. Try again.',
            ];
        }

        // ── wp_insert_attachment (WP-native; INSERT into wp_posts) ───
        //
        // post_author = $authorId is the security gate that makes
        // PostsService::createBlog's ownership check accept this
        // attachment as a cover image later. UNCHANGED.
        //
        // post_title: stored filename without extension. That name is now
        // minted by neutralStoredName(), so this carries no caller-supplied
        // byte — deriving the title from the stored basename keeps the two
        // in step by construction instead of by a second rule.
        // preg_replace returns string|null (null on engine error, never in
        // practice for a pure-string subject), so we fall back to
        // basename() for the type guarantee.
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
