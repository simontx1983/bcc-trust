<?php
/**
 * X (Twitter) OAuth Service
 *
 * Handles X OAuth 2.0 authentication flow with PKCE.
 *
 * @package BCC\Trust\Core
 * @subpackage X
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Services\x;

use BCC\Core\Http\SafeHttpClient;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class XOAuthService {

    private const AUTH_URL  = 'https://twitter.com/i/oauth2/authorize';
    private const TOKEN_URL = 'https://api.x.com/2/oauth2/token';

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct() {
        $this->clientId     = defined('BCC_X_CLIENT_ID') ? BCC_X_CLIENT_ID : '';
        $this->clientSecret = defined('BCC_X_CLIENT_SECRET') ? BCC_X_CLIENT_SECRET : '';
        $this->redirectUri  = rest_url('bcc-trust/v1/x/callback');

        if (defined('WP_DEBUG') && WP_DEBUG) {
            if (!$this->clientId) {
                \BCC\Core\Log\Logger::error('[bcc-trust] ' . 'BCC Trust X: Client ID missing', []);
            }
            if (!$this->clientSecret) {
                \BCC\Core\Log\Logger::error('[bcc-trust] ' . 'BCC Trust X: Client Secret missing', []);
            }
        }
    }

    /**
     * Generate X OAuth 2.0 authorization URL with PKCE.
     */
    public function getAuthUrl(?string $state = null): string {

        $userId = get_current_user_id();

        if (!$userId) {
            throw new Exception('User must be logged in to initiate X OAuth');
        }

        if (!$state) {
            $nonce = wp_create_nonce('bcc_x_oauth_' . $userId);
            $state = "bcc_x_{$userId}_{$nonce}";
        } elseif (preg_match('/^bcc_x_\d+_([a-f0-9]+)$/', $state, $m)) {
            $nonce = $m[1];
        } else {
            $nonce = wp_create_nonce('bcc_x_oauth_' . $userId);
        }

        // Generate PKCE code verifier and challenge
        $codeVerifier  = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        // Store state + PKCE verifier in user meta.
        // Reuse the same $nonce variable — do NOT call wp_create_nonce() again.
        update_user_meta($userId, '_bcc_x_state', $state);
        update_user_meta($userId, '_bcc_x_nonce', $nonce);
        update_user_meta($userId, '_bcc_x_state_expires', time() + 600);
        update_user_meta($userId, '_bcc_x_code_verifier', $codeVerifier);

        $params = [
            'response_type'         => 'code',
            'client_id'             => $this->clientId,
            'redirect_uri'          => $this->redirectUri,
            'scope'                 => 'users.read tweet.read offline.access',
            'state'                 => $state,
            'code_challenge'        => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token using PKCE.
     *
     * @return array<string, mixed>
     */
    public function getAccessToken(string $code, int $userId): array {

        if (!$this->isConfigured()) {
            throw new Exception('X OAuth credentials not configured');
        }

        if (!$code) {
            throw new Exception('Missing authorization code');
        }

        $codeVerifier = get_user_meta($userId, '_bcc_x_code_verifier', true);

        if (!$codeVerifier) {
            throw new Exception('Missing PKCE code verifier');
        }

        $response = SafeHttpClient::post(self::TOKEN_URL, [
            'body' => [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $this->redirectUri,
                'client_id'     => $this->clientId,
                'code_verifier' => $codeVerifier,
            ],
            'headers' => [
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ],
            'timeout' => 30,
        ]);

        // Clean up PKCE verifier
        delete_user_meta($userId, '_bcc_x_code_verifier');

        if (is_wp_error($response)) {
            throw new Exception('X token request failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!empty($data['error'])) {
            $msg = $data['error_description'] ?? $data['error'];
            throw new Exception('X OAuth error: ' . $msg);
        }

        if (empty($data['access_token'])) {
            throw new Exception('No access token returned from X');
        }

        return $data;
    }

    /**
     * Exchange a stored refresh token for a fresh access token (OAuth2
     * `refresh_token` grant). X rotates the refresh token, so the response
     * carries a NEW refresh_token the caller must persist. Requires the
     * `offline.access` scope, which getAuthUrl() always requests.
     *
     * @return array<string, mixed> Raw token response (access_token,
     *                              refresh_token, expires_in, …).
     */
    public function refreshAccessToken(string $refreshToken): array {

        if (!$this->isConfigured()) {
            throw new Exception('X OAuth credentials not configured');
        }

        if ($refreshToken === '') {
            throw new Exception('Missing refresh token');
        }

        $response = SafeHttpClient::post(self::TOKEN_URL, [
            'body' => [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $refreshToken,
                'client_id'     => $this->clientId,
            ],
            'headers' => [
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new Exception('X token refresh failed: ' . $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new Exception('X OAuth refresh: invalid response');
        }
        if (!empty($data['error'])) {
            throw new Exception('X OAuth refresh error: ' . ($data['error_description'] ?? $data['error']));
        }
        if (empty($data['access_token'])) {
            throw new Exception('No access token returned from X refresh');
        }

        return $data;
    }

    /**
     * Validate OAuth state parameter.
     *
     * @return array{user_id: int}|false Returns array with user_id if valid, false otherwise.
     */
    public function validateState(string $state) {

        if (!$state) {
            return false;
        }

        if (!preg_match('/^bcc_x_(\d+)_([a-f0-9]+)$/', $state, $matches)) {
            \BCC\Core\Log\Logger::error('[bcc-trust] ' . 'X OAuth: invalid state format -> ', ['detail' => $state]);
            return false;
        }

        $userId = (int) $matches[1];
        $nonce  = $matches[2];

        $storedState  = get_user_meta($userId, '_bcc_x_state', true);
        $storedNonce  = get_user_meta($userId, '_bcc_x_nonce', true);
        $stateExpires = (int) get_user_meta($userId, '_bcc_x_state_expires', true);

        // Check expiry (10 minute window).
        // Always reject when expiry meta is missing (0) — treat as expired.
        if (!$stateExpires || time() > $stateExpires) {
            $this->cleanupState($userId);
            return false;
        }

        if (hash_equals($storedState, $state) && hash_equals($storedNonce, $nonce)) {
            $this->cleanupState($userId);
            return ['user_id' => $userId];
        }

        $this->cleanupState($userId);
        return false;
    }

    /**
     * Extract user ID from state parameter without validation.
     */
    public function extractUserIdFromState(string $state): int {
        if (preg_match('/^bcc_x_(\d+)_/', $state, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    /**
     * Check if OAuth is configured.
     */
    public function isConfigured(): bool {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }

    /*
    |--------------------------------------------------------------------------
    | PRIVATE HELPERS
    |--------------------------------------------------------------------------
    */

    private function cleanupState(int $userId): void {
        delete_user_meta($userId, '_bcc_x_state');
        delete_user_meta($userId, '_bcc_x_nonce');
        delete_user_meta($userId, '_bcc_x_state_expires');
    }

    /**
     * Generate a cryptographic random PKCE code verifier (43–128 chars).
     */
    private function generateCodeVerifier(): string {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Generate S256 code challenge from verifier.
     */
    private function generateCodeChallenge(string $verifier): string {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}