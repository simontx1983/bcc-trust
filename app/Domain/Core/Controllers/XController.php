<?php
/**
 * X (Twitter) Controller
 *
 * Thin HTTP adapter — validates requests, delegates all domain logic to
 * XVerificationService, and formats responses.
 *
 * @package BCC\Trust\Core
 * @subpackage Controllers
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Exception;

use BCC\Trust\Core\Security\RateLimiter;
use BCC\Trust\Core\Services\x\XOAuthService;
use BCC\Trust\Core\Services\x\XVerificationService;
use BCC\Trust\Core\Support\FrontendRedirect;

if (!defined('ABSPATH')) {
    exit;
}

class XController {

    /**
     * Register REST routes
     */
    public static function register_routes(): void {

        register_rest_route('bcc-trust/v1', '/x/auth', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'getAuthUrl'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);

        register_rest_route('bcc-trust/v1', '/x/callback', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleCallback'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('bcc-trust/v1', '/x/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'getStatus'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);

        register_rest_route('bcc-trust/v1', '/x/disconnect', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'disconnect'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);

        register_rest_route('bcc-trust/v1', '/x/verify-share', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'verifyShare'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);
    }

    public static function permission_check(): bool {
        return \BCC\Core\Permissions\Permissions::restCallback();
    }

    /**
     * Return X OAuth authorisation URL.
     *
     * Accepts an optional `return_to` query parameter — a fully-qualified
     * URL on the BCC_FRONTEND_ORIGIN allowlist. When supplied, the OAuth
     * callback redirects the user back to that URL with the verification
     * outcome appended; without it, the callback falls back to a default
     * frontend path. Persisted in user meta so the callback (which arrives
     * cross-origin from twitter.com) can read it without depending on a
     * third-party cookie.
     */
    public static function getAuthUrl(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $oauth = new XOAuthService();
            if (!$oauth->isConfigured()) {
                return new WP_Error('x_not_configured', 'X OAuth not configured', ['status' => 500]);
            }

            // Clear any stale OAuth state from previous failed attempts.
            $uid = get_current_user_id();
            if ($uid) {
                delete_user_meta($uid, '_bcc_x_state');
                delete_user_meta($uid, '_bcc_x_nonce');
                delete_user_meta($uid, '_bcc_x_state_expires');
                delete_user_meta($uid, '_bcc_x_code_verifier');

                // Persist a validated return URL for the callback. Reject
                // anything off-allowlist silently — the callback will fall
                // back to the default frontend path. Never echo the raw
                // input back, even on reject.
                $returnToRaw = (string) $request->get_param('return_to');
                if ($returnToRaw !== '') {
                    $validated = FrontendRedirect::validateReturnTo($returnToRaw);
                    if ($validated !== null) {
                        update_user_meta($uid, '_bcc_x_return_to', $validated);
                    } else {
                        delete_user_meta($uid, '_bcc_x_return_to');
                    }
                } else {
                    delete_user_meta($uid, '_bcc_x_return_to');
                }
            }

            $authUrl = (new XVerificationService())->getAuthUrl();

            return new WP_REST_Response(['success' => true, 'data' => ['auth_url' => $authUrl]], 200);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'X API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return new WP_Error('bcc_internal', 'An unexpected error occurred. Please try again.', ['status' => 500]);
        }
    }

    /**
     * Handle X OAuth callback — validates state, then delegates to service
     */
    public static function handleCallback(WP_REST_Request $request): void {
        // Rate-limit callback endpoint: 10 attempts per minute per IP.
        // Use IP-based key instead of get_current_user_id() because OAuth
        // callbacks arrive unauthenticated — all would share user ID 0.
        if (!RateLimiter::allowByKey('x_callback_' . self::getClientIp(), 10, 60)) {
            wp_safe_redirect(add_query_arg('x_verified', 'error', home_url('/')));
            exit;
        }

        $userId = null;
        try {
            $code  = $request->get_param('code');
            $state = $request->get_param('state');

            if (!$code || !$state) {
                throw new Exception('Missing OAuth parameters');
            }

            // Validate CSRF state token — resolves the WordPress user ID
            $oauth      = new XOAuthService();
            $validation = $oauth->validateState($state);

            if (!$validation) {
                throw new Exception('OAuth state verification failed');
            }

            $userId = $validation['user_id'];

            // Require the callback to arrive with an authenticated WP session
            // matching the user_id embedded in the OAuth state. Allowing an
            // unauthenticated callback lets an attacker who captured another
            // user's state token (via Referer leak, shared device, or debug
            // log) complete the OAuth dance while logged out and link an
            // attacker-controlled X account to the target's profile — then
            // inherit the verify_x quest and any downstream unlocks. Mirrors
            // the guard already present in GitHubController::handleCallback.
            $currentUser = get_current_user_id();
            if (!$currentUser || $currentUser !== $userId) {
                throw new Exception('Authenticated session required to complete X verification');
            }

            (new XVerificationService())->connect($userId, $code);

            // Return URL resolution order:
            //   1. user meta `_bcc_x_return_to` (set by getAuthUrl on this user)
            //   2. legacy cookie `bcc_x_return` (only fires for same-origin
            //      WP-side callers — the headless frontend can't set this
            //      cross-origin)
            //   3. BCC_FRONTEND_ORIGIN + /settings/identity (default fallback)
            //   4. home_url('/profile/') (when frontend origin is unconfigured)
            $returnUrl = (string) get_user_meta($userId, '_bcc_x_return_to', true);
            if ($returnUrl !== '') {
                delete_user_meta($userId, '_bcc_x_return_to');
            }
            if ($returnUrl === '') {
                $cookieValue = $_COOKIE['bcc_x_return'] ?? '';
                if (is_string($cookieValue) && $cookieValue !== '' && wp_validate_redirect($cookieValue, '')) {
                    $returnUrl = $cookieValue;
                    setcookie('bcc_x_return', '', time() - 3600, '/');
                }
            }
            if ($returnUrl === '') {
                $returnUrl = FrontendRedirect::defaultReturn('/settings/identity');
            }
            wp_safe_redirect(add_query_arg('x_verified', 'success', $returnUrl));
            exit;

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] X callback error', ['detail' => $e->getMessage()]);

            $errorReturnUrl = '';
            if ($userId) {
                $errorReturnUrl = (string) get_user_meta($userId, '_bcc_x_return_to', true);
                delete_user_meta($userId, '_bcc_x_state');
                delete_user_meta($userId, '_bcc_x_nonce');
                delete_user_meta($userId, '_bcc_x_state_expires');
                delete_user_meta($userId, '_bcc_x_code_verifier');
                delete_user_meta($userId, '_bcc_x_return_to');
            }

            if ($errorReturnUrl === '') {
                $cookieValue = $_COOKIE['bcc_x_return'] ?? '';
                if (is_string($cookieValue) && $cookieValue !== '' && wp_validate_redirect($cookieValue, '')) {
                    $errorReturnUrl = $cookieValue;
                }
            }
            if ($errorReturnUrl === '') {
                $errorReturnUrl = FrontendRedirect::defaultReturn('/settings/identity');
            }
            $errorBase = $errorReturnUrl;
            setcookie('bcc_x_return', '', time() - 3600, '/');

            // Never reflect raw exception messages into the redirect URL —
            // X API / WP_Error / token-endpoint details would otherwise leak
            // into browser history, server logs, Referer headers, and any
            // analytics beacons the landing page fires. Emit a fixed token
            // the frontend can map to user-facing copy; keep details in the
            // server log above.
            wp_safe_redirect(add_query_arg([
                'x_verified' => 'error',
                'bcc_internal'    => 'oauth_failed',
            ], $errorBase));
            exit;
        }
    }

    /**
     * Return X connection status for the current user
     */
    public static function getStatus(): WP_REST_Response|WP_Error {
        try {
            $userId     = get_current_user_id();
            $connection = (new XVerificationService())->getStatus($userId);

            if ($connection === null || $connection->x_username === null || $connection->x_username === '') {
                return new WP_REST_Response([
                    'success' => true,
                    'data'    => ['connected' => false],
                ], 200);
            }

            return new WP_REST_Response([
                'success' => true,
                'data'    => [
                    'connected'   => true,
                    'username'    => $connection->x_username,
                    'verified_at' => $connection->x_verified_at,
                    'last_synced' => $connection->x_last_synced,
                ],
            ], 200);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'X API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return new WP_Error('bcc_internal', 'An unexpected error occurred. Please try again.', ['status' => 500]);
        }
    }

    /**
     * Verify that the user shared their BCC profile on X.
     *
     * Uses the stored access token to search the user's recent tweets
     * for the site URL. On success, fires the share_x quest signal.
     */
    public static function verifyShare(): WP_REST_Response|WP_Error {
        try {
            if (!RateLimiter::allow('x_verify_share', 5, 60)) {
                return new WP_Error('bcc_rate_limited', 'Too many attempts. Please wait a minute.', ['status' => 429]);
            }

            $userId = get_current_user_id();

            // Check if quest is already completed
            $questService = \BCC\Trust\Core\Plugin::instance()->questProgressService();
            $progress     = $questService->getProgress($userId);
            if (!empty($progress['quests']['share_x']['done'])) {
                return new WP_REST_Response([
                    'success' => true,
                    'data'    => ['verified' => true, 'already_done' => true],
                ], 200);
            }

            // Fire the quest signal — QuestValidator::validateShareX performs the
            // authoritative X-API tweet-search and only persists the success meta
            // flag after a real match. Do NOT pre-set the meta here; doing so
            // short-circuits validation and lets any authenticated user claim
            // the share_x bonus without tweeting.
            do_action('bcc_trust_quest_signal', $userId, 'share_x');

            // Re-read progress to see whether the validator actually awarded it.
            $progress = $questService->getProgress($userId);
            $verified = !empty($progress['quests']['share_x']['done']);

            if (!$verified) {
                return new WP_Error(
                    'share_not_found',
                    'We could not find a recent tweet linking to this site from your connected X account. Please tweet and try again.',
                    ['status' => 400]
                );
            }

            return new WP_REST_Response([
                'success' => true,
                'data'    => ['verified' => true, 'message' => 'Thanks for sharing! Quest complete.'],
            ], 200);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] verifyShare() error', ['error' => $e->getMessage()]);
            return new WP_Error('bcc_internal', 'Verification failed. Please try again.', ['status' => 500]);
        }
    }

    /**
     * Disconnect X for the current user.
     *
     * CSRF protection: bearer JWT auth (the BearerAuth middleware that
     * runs before permission_check) is sufficient. The previous
     * `wp_verify_nonce('wp_rest')` check was incompatible with headless
     * bearer-only callers because they have no way to mint a wp_rest
     * nonce. permission_check enforces logged-in + not-suspended.
     */
    public static function disconnect(WP_REST_Request $request): WP_REST_Response|WP_Error {
        unset($request);
        try {
            $result = (new XVerificationService())->disconnect(get_current_user_id());

            return new WP_REST_Response([
                'success' => true,
                'data'    => ['disconnected' => true, 'username' => $result['username']],
            ], 200);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'X API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return new WP_Error('bcc_internal', 'An unexpected error occurred. Please try again.', ['status' => 500]);
        }
    }

    /**
     * Get client IP for rate limiting.
     */
    private static function getClientIp(): string {
        return \BCC\Trust\Core\Security\IpResolver::getClientIp();
    }
}