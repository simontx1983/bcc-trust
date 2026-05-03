<?php
/**
 * GitHub Controller
 *
 * Thin HTTP adapter — validates requests, delegates all domain logic to
 * GitHubVerificationService, and formats responses.
 *
 * @package BCC\Trust\Core
 * @subpackage Controllers
 * @version 2.4.0
 */

namespace BCC\Trust\Core\Controllers;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Exception;

use BCC\Trust\Core\Security\RateLimiter;
use BCC\Trust\Core\Services\github\GitHubOAuthService;
use BCC\Trust\Core\Services\github\GitHubVerificationService;
use BCC\Trust\Core\Support\FrontendRedirect;

if (!defined('ABSPATH')) {
    exit;
}

class GitHubController {

    /**
     * Register REST routes
     */
    public static function register_routes(): void {

        register_rest_route('bcc-trust/v1', '/github/auth', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'getAuthUrl'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);

        register_rest_route('bcc-trust/v1', '/github/callback', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'handleCallback'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('bcc-trust/v1', '/github/status', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'getStatus'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);

        register_rest_route('bcc-trust/v1', '/github/disconnect', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'disconnect'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);

        register_rest_route('bcc-trust/v1', '/github/refresh', [
            'methods'             => 'POST',
            'callback'            => [self::class, 'refreshData'],
            'permission_callback' => [self::class, 'permission_check'],
        ]);
    }

    public static function permission_check(): bool {
        return \BCC\Core\Permissions\Permissions::restCallback();
    }

    /**
     * Return GitHub OAuth authorisation URL.
     *
     * Mirrors XController::getAuthUrl — accepts an optional `return_to`
     * query parameter on the BCC_FRONTEND_ORIGIN allowlist, persisted
     * in user meta for the callback to read after the cross-origin
     * round-trip from github.com.
     */
    public static function getAuthUrl(WP_REST_Request $request): WP_REST_Response|WP_Error {
        try {
            $oauth = new GitHubOAuthService();
            if (!$oauth->isConfigured()) {
                return new WP_Error('github_not_configured', 'GitHub OAuth not configured', ['status' => 500]);
            }

            $uid = get_current_user_id();
            if ($uid) {
                $returnToRaw = (string) $request->get_param('return_to');
                if ($returnToRaw !== '') {
                    $validated = FrontendRedirect::validateReturnTo($returnToRaw);
                    if ($validated !== null) {
                        update_user_meta($uid, '_bcc_github_return_to', $validated);
                    } else {
                        delete_user_meta($uid, '_bcc_github_return_to');
                    }
                } else {
                    delete_user_meta($uid, '_bcc_github_return_to');
                }
            }

            $authUrl = (new GitHubVerificationService())->getAuthUrl();

            return new WP_REST_Response(['success' => true, 'data' => ['auth_url' => $authUrl]], 200);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'GitHub API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return new WP_Error('github_error', 'An unexpected error occurred. Please try again.', ['status' => 500]);
        }
    }

    /**
     * Handle GitHub OAuth callback — validates state, then delegates to service
     */
    public static function handleCallback(WP_REST_Request $request): void {
        // Rate-limit callback endpoint: 10 attempts per minute per IP.
        // Use IP-based key instead of get_current_user_id() because OAuth
        // callbacks arrive unauthenticated — all would share user ID 0.
        if (!RateLimiter::allowByKey('github_callback_' . self::getClientIp(), 10, 60)) {
            wp_safe_redirect(add_query_arg('github_verified', 'error', home_url('/')));
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
            $oauth      = new GitHubOAuthService();
            $validation = $oauth->validateState($state);

            if (!$validation) {
                throw new Exception('OAuth state verification failed');
            }

            $userId = $validation['user_id'];

            // Require the callback to arrive with an authenticated WP session
            // matching the user_id embedded in the OAuth state. The previous
            // code allowed unauthenticated callbacks (current_user_id = 0),
            // which let an attacker who captured another user's state token
            // (via referer leak, shared-device access, or leaked debug log)
            // complete the OAuth dance while logged out and link an
            // attacker-controlled GitHub account to the target's profile.
            // Requiring auth forces the attacker to already control the
            // victim's WP session, which defeats the hijack path.
            $currentUser = get_current_user_id();
            if (!$currentUser || $currentUser !== $userId) {
                throw new Exception('Authenticated session required to complete GitHub verification');
            }

            (new GitHubVerificationService())->connect($userId, $code);

            $returnUrl = (string) get_user_meta($userId, '_bcc_github_return_to', true);
            if ($returnUrl !== '') {
                delete_user_meta($userId, '_bcc_github_return_to');
            } else {
                $returnUrl = FrontendRedirect::defaultReturn('/settings/identity');
            }
            wp_safe_redirect(add_query_arg('github_verified', 'success', $returnUrl));
            exit;

        } catch (Exception $e) {
            // Log unconditionally — silent OAuth failures in production lose
            // all triage data for attacker probing or provider outages.
            \BCC\Core\Log\Logger::error('[bcc-trust] GitHub callback error', [
                'detail'  => $e->getMessage(),
                'user_id' => $userId ?? 0,
            ]);

            $errorReturnUrl = '';
            if ($userId) {
                $errorReturnUrl = (string) get_user_meta($userId, '_bcc_github_return_to', true);
                delete_user_meta($userId, '_bcc_github_state');
                delete_user_meta($userId, '_bcc_github_nonce');
                delete_user_meta($userId, '_bcc_github_state_expires');
                delete_user_meta($userId, '_bcc_github_return_to');
            }
            if ($errorReturnUrl === '') {
                $errorReturnUrl = FrontendRedirect::defaultReturn('/settings/identity');
            }

            wp_safe_redirect(add_query_arg('github_verified', 'error', $errorReturnUrl));
            exit;
        }
    }

    /**
     * Return GitHub connection status for the current user
     */
    public static function getStatus(): WP_REST_Response|WP_Error {
        try {
            $userId     = get_current_user_id();
            $connection = (new GitHubVerificationService())->getStatus($userId);

            if ($connection === null || $connection->github_username === null || $connection->github_username === '') {
                return new WP_REST_Response([
                    'success' => true,
                    'data'    => ['connected' => false],
                ], 200);
            }

            return new WP_REST_Response([
                'success' => true,
                'data'    => [
                    'connected'   => true,
                    'username'    => $connection->github_username,
                    'verified_at' => $connection->github_verified_at,
                    'last_synced' => $connection->github_last_synced,
                    'followers'   => $connection->github_followers,
                    'repos'       => $connection->github_public_repos,
                    'orgs'        => $connection->github_org_count,
                ],
            ], 200);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'GitHub API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return new WP_Error('github_error', 'An unexpected error occurred. Please try again.', ['status' => 500]);
        }
    }

    /**
     * Disconnect GitHub for the current user.
     *
     * Bearer JWT auth via permission_check is the CSRF guard — the
     * previous wp_rest nonce check was incompatible with headless
     * bearer-only callers (they have no way to mint a wp_rest nonce).
     */
    public static function disconnect(WP_REST_Request $request): WP_REST_Response|WP_Error {
        unset($request);
        try {
            $result = (new GitHubVerificationService())->disconnect(get_current_user_id());

            return new WP_REST_Response([
                'success' => true,
                'data'    => ['disconnected' => true, 'username' => $result['username']],
            ], 200);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'GitHub API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return new WP_Error('github_error', 'An unexpected error occurred. Please try again.', ['status' => 500]);
        }
    }

    /**
     * Refresh GitHub data for the current user. See ::disconnect for the
     * nonce-check-removal rationale.
     */
    public static function refreshData(WP_REST_Request $request): WP_REST_Response|WP_Error {
        unset($request);
        try {
            $result = (new GitHubVerificationService())->refresh(get_current_user_id());

            return new WP_REST_Response([
                'success' => true,
                'data'    => array_merge(['refreshed' => true], $result),
            ], 200);

        } catch (Exception $e) {
            \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'GitHub API error', ['endpoint' => __FUNCTION__, 'error' => $e->getMessage()]);
            return new WP_Error('github_error', 'An unexpected error occurred. Please try again.', ['status' => 500]);
        }
    }

    /**
     * Get client IP for rate limiting.
     */
    private static function getClientIp(): string {
        return \BCC\Trust\Core\Security\IpResolver::getClientIp();
    }
}
