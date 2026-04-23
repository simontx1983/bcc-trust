<?php
/**
 * GitHub OAuth Service
 *
 * Handles GitHub OAuth authentication flow
 *
 * @package BCC_Trust_Engine
 * @subpackage GitHub
 * @version 2.3.1
 */

namespace BCC\Trust\Core\Services\github;

use BCC\Core\Http\SafeHttpClient;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class GitHubOAuthService {

    private const AUTH_URL  = 'https://github.com/login/oauth/authorize';
    private const TOKEN_URL = 'https://github.com/login/oauth/access_token';

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    /**
     * Constructor
     */
    public function __construct() {

        $this->clientId     = defined('BCC_GITHUB_CLIENT_ID') ? BCC_GITHUB_CLIENT_ID : '';
        $this->clientSecret = defined('BCC_GITHUB_CLIENT_SECRET') ? BCC_GITHUB_CLIENT_SECRET : '';
        $this->redirectUri  = rest_url('bcc-trust/v1/github/callback');

        if (defined('WP_DEBUG') && WP_DEBUG) {

            if (!$this->clientId) {
                \BCC\Core\Log\Logger::error('[bcc-trust] ' . 'BCC Trust GitHub: Client ID missing', []);
            }

            if (!$this->clientSecret) {
                \BCC\Core\Log\Logger::error('[bcc-trust] ' . 'BCC Trust GitHub: Client Secret missing', []);
            }

        }
    }

    /**
     * Generate GitHub OAuth authorization URL
     */
    public function getAuthUrl(?string $state = null): string {

        if (!$state) {

            $userId = get_current_user_id();

            if (!$userId) {
                throw new Exception('User must be logged in to initiate GitHub OAuth');
            }

            // Create a unique state parameter
            $nonce = wp_create_nonce('bcc_github_oauth_' . $userId);
            $state = "bcc_github_{$userId}_{$nonce}";
            
            // Store in user meta (transients are unreliable with object cache drop-ins)
            update_user_meta($userId, '_bcc_github_state', $state);
            update_user_meta($userId, '_bcc_github_nonce', $nonce);
            update_user_meta($userId, '_bcc_github_state_expires', time() + 600);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'GitHub OAuth: Generated state for user ', ['detail' => $userId]);
            }
        }

        $params = [
            'client_id'     => $this->clientId,
            'redirect_uri'  => $this->redirectUri,
            'scope'         => 'read:user user:email read:org',
            'state'         => $state,
            'allow_signup'  => 'false'
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token
     */
    public function getAccessToken(string $code): string {

        if (!$this->isConfigured()) {
            throw new Exception('GitHub OAuth credentials not configured');
        }

        if (!$code) {
            throw new Exception('Missing authorization code');
        }

        $response = SafeHttpClient::post(self::TOKEN_URL, [
            'body' => [
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'code'          => $code,
                'redirect_uri'  => $this->redirectUri
            ],
            'headers' => [
                'Accept' => 'application/json'
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            throw new Exception(
                'GitHub token request failed: ' . $response->get_error_message()
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (!empty($data['error'])) {
            $msg = $data['error_description'] ?? $data['error'];
            throw new Exception('GitHub OAuth error: ' . $msg);
        }

        if (empty($data['access_token'])) {
            throw new Exception('No access token returned from GitHub');
        }

        return $data['access_token'];
    }

  /**
 * Validate OAuth state parameter
 *
 * @param string $state The state parameter from GitHub callback
 * @return array{user_id: int}|false Returns array with user_id if valid, false otherwise
 */
public function validateState(string $state) {
    \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'GitHub OAuth: Validating state', []);
    
    if (!$state) {
        \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'GitHub OAuth: No state parameter provided', []);
        return false;
    }

    // Extract user + nonce
    if (!preg_match('/^bcc_github_(\d+)_([a-f0-9]+)$/', $state, $matches)) {
        \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'GitHub OAuth: invalid state format', []);
        return false;
    }

    $userId = (int) $matches[1];
    $nonce = $matches[2];

    \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'GitHub OAuth: Extracted user ID: ', ['detail' => $userId]);

    // Get stored state from user meta (transients are unreliable with object cache)
    $storedState   = get_user_meta($userId, '_bcc_github_state', true);
    $storedNonce   = get_user_meta($userId, '_bcc_github_nonce', true);
    $stateExpires  = (int) get_user_meta($userId, '_bcc_github_state_expires', true);

    \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'GitHub OAuth: Stored state check for user ', ['detail' => $userId]);

    // Check expiry (10 minute window).
    // Always reject when expiry meta is missing (0) — treat as expired.
    if (!$stateExpires || time() > $stateExpires) {
        \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'GitHub OAuth: state expired for user ', ['detail' => $userId]);
        delete_user_meta($userId, '_bcc_github_state');
        delete_user_meta($userId, '_bcc_github_nonce');
        delete_user_meta($userId, '_bcc_github_state_expires');
        return false;
    }

    // Verify against stored values
    if (hash_equals($storedState, $state) && hash_equals($storedNonce, $nonce)) {
        \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'GitHub OAuth: State validation successful for user ', ['detail' => $userId]);

        // Clean up used state
        delete_user_meta($userId, '_bcc_github_state');
        delete_user_meta($userId, '_bcc_github_nonce');
        delete_user_meta($userId, '_bcc_github_state_expires');

        return [
            'user_id' => $userId
        ];
    }

    \BCC\Core\Log\Logger::info('[bcc-trust] ' . 'GitHub OAuth: verification failed for user ', ['detail' => $userId]);

    // Clean up failed state
    delete_user_meta($userId, '_bcc_github_state');
    delete_user_meta($userId, '_bcc_github_nonce');
    delete_user_meta($userId, '_bcc_github_state_expires');

    return false;
}

    /**
     * Extract user ID from state parameter without validation
     * 
     * @param string $state The state parameter from GitHub callback
     * @return int User ID or 0 if not found
     */
    public function extractUserIdFromState(string $state): int {
        if (preg_match('/^bcc_github_(\d+)_/', $state, $matches)) {
            return (int) $matches[1];
        }
        return 0;
    }

    /**
     * Check if OAuth is configured
     * 
     * @return bool
     */
    public function isConfigured(): bool {
        return !empty($this->clientId) && !empty($this->clientSecret);
    }
}