<?php
/**
 * PhotoAltEndpoint — handles /bcc/v1/photos/:pho_id/alt routes.
 *
 * Routes registered:
 *   - PATCH /photos/:pho_id/alt  — set or clear the author-supplied
 *                                   alt text for one of the viewer's
 *                                   own photos
 *
 * Body shape:
 *   { "alt": string }   // length-capped at 500; "" deletes any prior row
 *
 * Auth: required. Self-only — only the photo owner can set its alt
 * (verified against `peepso_photos.pho_owner_id`). Errors return the
 * canonical envelope.
 *
 * Cache: `no-store`. The body mutates per-photo state and the alt
 * surfaces immediately on the next feed read; no shared-cache value.
 *
 * Sanitisation: HTML stripped (`wp_strip_all_tags`), whitespace
 * trimmed and collapsed to single spaces. The cap is enforced
 * server-side AFTER stripping so a payload that would exceed 500
 * after-strip is rejected even if it looked shorter pre-strip.
 *
 * @package BCC\Trust\Core\REST
 * @since v1.5 a11y (2026-05, photo alt text per §3.3.9)
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\PhotoAltRepository;
use BCC\Trust\Core\Repositories\PhotoRepository;
use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class PhotoAltEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/photos/(?P<pho_id>\d+)/alt',
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$instance, 'patch'],
                'permission_callback' => '__return_true',
                'args' => [
                    'pho_id' => [
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ],
                    'alt' => [
                        'required' => true,
                        'type'     => 'string',
                        // No sanitize_callback — we sanitise ourselves
                        // below so the cap + strip + collapse rules
                        // apply uniformly regardless of body shape.
                    ],
                ],
            ]
        );
    }

    public function patch(WP_REST_Request $request): WP_REST_Response
    {
        $userId = (int) get_current_user_id();
        if ($userId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        $phoId = (int) $request->get_param('pho_id');
        if ($phoId <= 0) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Invalid photo id.',
                400
            );
        }

        // Photo must exist (404 before ownership check so we don't
        // confuse callers with a 403 on an already-deleted photo).
        $ownerId = $this->photoRepo()->findOwnerByPhotoId($phoId);
        if ($ownerId === null) {
            return ApiResponse::error(
                'bcc_not_found',
                'Photo not found.',
                404
            );
        }

        // AuthZ: only the photo owner can set its alt.
        if ($ownerId !== $userId) {
            return ApiResponse::error(
                'bcc_forbidden',
                'You can only edit alt text on photos you uploaded.',
                403
            );
        }

        $rawAlt = $request->get_param('alt');
        if (!is_string($rawAlt)) {
            return ApiResponse::error(
                'bcc_invalid_request',
                'Alt text must be a string.',
                400
            );
        }

        $cleanAlt = self::sanitise($rawAlt);

        // Server-side length cap matches the schema VARCHAR(500). We
        // measure the post-sanitise length so a 1000-char HTML blob
        // that strips down to 200 chars is accepted.
        if (mb_strlen($cleanAlt) > PhotoAltRepository::ALT_TEXT_MAX_LENGTH) {
            return ApiResponse::error(
                'bcc_invalid_request',
                sprintf(
                    'Alt text is too long (max %d characters).',
                    PhotoAltRepository::ALT_TEXT_MAX_LENGTH
                ),
                400
            );
        }

        $altRepo = $this->photoAltRepo();

        if ($cleanAlt === '') {
            // Empty alt means "clear it" — delete any existing row so
            // the field reads back as null on subsequent feed loads.
            $altRepo->delete($phoId);
            $stored = null;
        } else {
            $ok = $altRepo->upsert($phoId, $userId, $cleanAlt);
            if (!$ok) {
                return ApiResponse::error(
                    'bcc_unavailable',
                    'Could not save alt text.',
                    503
                );
            }
            $stored = $cleanAlt;
        }

        $response = ApiResponse::ok([
            'pho_id' => $phoId,
            'alt'    => $stored,
        ]);
        $response->header('Cache-Control', 'no-store');
        return $response;
    }

    /**
     * Strip HTML, trim, and collapse internal whitespace to single
     * spaces. `wp_strip_all_tags` removes tags + their contents (per
     * its `$remove_breaks=true` second-arg behaviour we add below),
     * which prevents both XSS and inline-script smuggling. The
     * collapse keeps multi-line input from blowing past the visual
     * cap a screen reader actually announces.
     */
    private static function sanitise(string $alt): string
    {
        $stripped = wp_strip_all_tags($alt, true);
        $stripped = trim($stripped);
        // Collapse runs of whitespace (incl. tabs/newlines that survive
        // wp_strip_all_tags's $remove_breaks) into single spaces.
        return (string) preg_replace('/\s+/u', ' ', $stripped);
    }

    private function photoRepo(): PhotoRepository
    {
        return Plugin::instance()->photoRepository();
    }

    private function photoAltRepo(): PhotoAltRepository
    {
        return Plugin::instance()->photoAltRepository();
    }
}
