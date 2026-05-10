<?php
/**
 * Integrations Endpoint — surfaces PeepSo admin-configured
 * integration toggles + keys to the BCC frontend.
 *
 * The architectural premise (per the v1.5 PeepSo-as-infrastructure
 * principle): PeepSo owns operational state — API keys, content
 * ratings, per-surface enable toggles, display limits — admin-set
 * once, honored everywhere. BCC's frontend is a different
 * presentation layer that needs the same config to render the same
 * features consistently.
 *
 * Routes:
 *   - GET /bcc/v1/integrations/giphy — Giphy integration config
 *
 * V1 scope: Giphy only. Future surfaces (e.g. Tenor, custom emoji,
 * stickers) live behind the same `/bcc/v1/integrations/*` namespace
 * with the same shape: `{enabled: bool, ...config}`. Each integration
 * is opt-in — `enabled: false` instructs the frontend to hide the
 * relevant UI affordance entirely.
 *
 * Auth posture: required. The Giphy API key is admin-configured but
 * not strictly secret (Giphy's "browser SDK" keys are designed for
 * client-side use and rate-limited per-IP), but exposing them only
 * to authed viewers limits scrape surface and aligns with the
 * "logged-in viewers compose; anonymous viewers consume" pattern.
 *
 * Cache: `private, max-age=300`. Config doesn't change mid-session;
 * a 5-minute cache lets the frontend skip refetching across page
 * navigations without staleness concerns.
 *
 * @package BCC\Trust\Core\REST
 * @since v1.5 (2026-05, Phase 1c GIF picker)
 * @status alive — frontend-consumed via
 *                 `bcc-frontend/src/lib/api/integrations-endpoints.ts`
 *                 (`getGiphyIntegration` adapter feeding the
 *                 `useGiphyIntegration` React Query hook). V-25
 *                 verification 2026-05-09 confirmed the endpoint and the
 *                 typed adapter are both wired and contract-aligned.
 *                 See docs/pattern-registry.md "Phase B inventory addendum".
 */

namespace BCC\Trust\Core\REST;

use BCC\Trust\Core\Support\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if (!defined('ABSPATH')) {
    exit;
}

final class IntegrationsEndpoint
{
    private const ROUTE_NAMESPACE = 'bcc/v1';

    public static function register(): void
    {
        $instance = new self();

        register_rest_route(
            self::ROUTE_NAMESPACE,
            '/integrations/giphy',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$instance, 'giphy'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    /**
     * GET /bcc/v1/integrations/giphy
     *
     * Returns:
     *   {
     *     "enabled":       bool,    // false when admin disabled OR no key
     *     "api_key":       string,  // empty when not enabled
     *     "rating":        string,  // 'g' | 'pg' | 'pg-13' | 'r' (Giphy's content-rating values)
     *     "display_limit": int      // max GIFs per page in the picker grid
     *   }
     *
     * `enabled` honors PeepSo admin's per-surface toggle —
     * `giphy_posts_enable` controls whether Giphy is allowed for posts
     * (separate from comments + chat which have their own toggles).
     * The BCC composer surfaces the GIF button only when `enabled: true`.
     *
     * When PeepSo isn't installed at all, `enabled: false` and the
     * default empty/safe config returns. The frontend never errors;
     * the GIF button just doesn't appear.
     */
    public function giphy(WP_REST_Request $request): WP_REST_Response
    {
        unset($request); // no params; auth is handled below

        $viewerId = get_current_user_id();
        if ($viewerId <= 0) {
            return ApiResponse::error('bcc_unauthorized', 'Sign in required.', 401);
        }

        if (!class_exists('PeepSo')) {
            $response = ApiResponse::ok(self::disabledConfig());
            $response->header('Cache-Control', 'private, max-age=300');
            return $response;
        }

        $apiKey       = (string) \PeepSo::get_option('giphy_api_key', '');
        $postsEnabled = (bool)   \PeepSo::get_option_new('giphy_posts_enable');
        $rating       = (string) \PeepSo::get_option('giphy_rating', 'pg-13');
        $displayLimit = (int)    \PeepSo::get_option('giphy_display_limit', 25);

        $enabled = $postsEnabled && $apiKey !== '';

        $response = ApiResponse::ok([
            'enabled'       => $enabled,
            'api_key'       => $enabled ? $apiKey : '',
            'rating'        => $rating,
            'display_limit' => $displayLimit > 0 ? $displayLimit : 25,
        ]);
        $response->header('Cache-Control', 'private, max-age=300');
        return $response;
    }

    /**
     * @return array{enabled: false, api_key: '', rating: string, display_limit: int}
     */
    private static function disabledConfig(): array
    {
        return [
            'enabled'       => false,
            'api_key'       => '',
            'rating'        => 'pg-13',
            'display_limit' => 25,
        ];
    }
}
