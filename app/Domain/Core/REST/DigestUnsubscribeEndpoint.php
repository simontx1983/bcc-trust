<?php
/**
 * DigestUnsubscribeEndpoint — public one-click email-digest opt-out.
 *
 * Route: GET /bcc/v1/digest/unsubscribe?uid=&exp=&sig=
 *
 * Different shape from the rest of the bcc/v1 surface:
 *   - **No auth required.** The signed token IS the auth — verifying
 *     the HMAC proves the link came from a legitimate digest email
 *     we sent to that user. Requiring a session would defeat the
 *     "click from any device, no login needed" UX promise.
 *   - **Returns HTML, not JSON.** This is a one-shot user-facing
 *     surface: the user clicks the link in their email and lands on
 *     a "you're unsubscribed" confirmation page. JSON would force a
 *     companion frontend page just to render the confirmation.
 *   - **Idempotent.** Clicking twice is a no-op — second click
 *     re-flips the flag to false (already false), then renders the
 *     same confirmation.
 *
 * Security:
 *   - HMAC-SHA256 over `${uid}|${exp}` keyed by wp_salt('auth')
 *   - 90-day TTL (per DigestService::UNSUBSCRIBE_TTL_SECONDS)
 *   - Constant-time signature compare via hash_equals()
 *   - Bad-token responses return a generic "couldn't process" page
 *     without revealing which check failed (signature vs expiry)
 *
 * @package BCC\Trust\Core\REST
 * @since V1.5 (§I1 email digest)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Services\DigestService;
use BCC\Trust\Core\Support\NotificationPrefs;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class DigestUnsubscribeEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/digest/unsubscribe',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'handle'],
                // Public: token is the auth.
                'permission_callback' => '__return_true',
            ]
        );
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        $uid = (int) $request->get_param('uid');
        $exp = (int) $request->get_param('exp');
        $sig = (string) $request->get_param('sig');

        $verifiedUserId = DigestService::verifyUnsubscribeToken($uid, $exp, $sig);

        if ($verifiedUserId <= 0) {
            return self::htmlResponse(
                "We couldn't process this unsubscribe link. It may have expired — "
                . "you can also turn off the digest in your settings.",
                400
            );
        }

        // Idempotent: writing false on an already-false flag is a no-op.
        NotificationPrefs::setEmailDigest($verifiedUserId, false);

        return self::htmlResponse(
            "You're unsubscribed from the weekly Floor digest. "
            . "We'll keep your in-app bell notifications on — manage all of "
            . "this in your settings.",
            200
        );
    }

    /**
     * Wrap a short message in a minimal HTML envelope so a click in
     * an email client lands somewhere readable. Intentionally bare —
     * no styles, no app chrome, just the confirmation.
     */
    private static function htmlResponse(string $message, int $status): WP_REST_Response
    {
        $body = '<!doctype html>'
            . '<html lang="en">'
            . '<head><meta charset="utf-8"><title>BCC — Email Digest</title></head>'
            . '<body style="font-family:system-ui,sans-serif;max-width:500px;margin:60px auto;padding:0 16px;line-height:1.5;color:#1a1a1a">'
            . '<h1 style="font-size:18px;font-weight:600;margin:0 0 12px">Floor email digest</h1>'
            . '<p>' . esc_html($message) . '</p>'
            . '</body></html>';

        $resp = new WP_REST_Response($body, $status);
        $resp->header('Content-Type', 'text/html; charset=UTF-8');
        $resp->header('Cache-Control', 'no-store');
        return $resp;
    }
}
