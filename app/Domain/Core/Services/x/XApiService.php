<?php
/**
 * X (Twitter) API Service
 *
 * Fetches user data from the X API v2.
 *
 * @package BCC_Trust_Engine
 * @subpackage X
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Services\x;

use BCC\Core\Http\SafeHttpClient;
use Exception;

if (!defined('ABSPATH')) {
    exit;
}

class XApiService {

    private const USER_ME_URL = 'https://api.x.com/2/users/me';

    /**
     * Fetch the authenticated user's data from X API v2.
     *
     * Returns: id, username, name, profile_image_url
     * Email is only available if the app has elevated permissions.
     *
     * @param string $accessToken Bearer token
     * @return array<string, mixed> User data
     */
    public function getUserData(string $accessToken): array {

        $url = self::USER_ME_URL . '?' . http_build_query([
            'user.fields' => 'id,username,name,profile_image_url',
        ]);

        $response = SafeHttpClient::get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new Exception('X API request failed: ' . $response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body       = wp_remote_retrieve_body($response);
        $data       = json_decode($body, true);

        if ($statusCode !== 200) {
            $msg = $data['detail'] ?? $data['title'] ?? 'Unknown X API error';
            throw new Exception('X API error (' . $statusCode . '): ' . $msg);
        }

        if (empty($data['data'])) {
            throw new Exception('Invalid X API response: no user data');
        }

        return $data['data'];
    }

    /**
     * Check if the authenticated user has tweeted a URL recently.
     *
     * Uses GET /2/users/{id}/tweets (requires tweet.read scope).
     * Searches the user's last 20 tweets (within 7 days) for a
     * substring match on the given URL.
     *
     * @param string $accessToken Bearer token for the user.
     * @param string $xUserId     The user's X numeric ID.
     * @param string $urlSubstring URL or substring to search for in tweet text.
     * @return bool True if a matching tweet was found.
     */
    public function hasRecentTweetContaining(
        string $accessToken,
        string $xUserId,
        string $urlSubstring
    ): bool {
        $since = gmdate('Y-m-d\TH:i:s\Z', strtotime('-7 days'));

        $url = "https://api.x.com/2/users/{$xUserId}/tweets?" . http_build_query([
            'max_results'  => 20,
            'start_time'   => $since,
            'tweet.fields' => 'text,created_at',
            'exclude'      => 'retweets,replies',
        ]);

        $response = SafeHttpClient::get($url, [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            return false;
        }

        $body   = json_decode(wp_remote_retrieve_body($response), true);
        $tweets = $body['data'] ?? [];

        $needle = strtolower($urlSubstring);
        foreach ($tweets as $tweet) {
            if (stripos($tweet['text'] ?? '', $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fallback: fetch user data via X API v1.1 (may work on legacy free tier).
     *
     * @param string $accessToken OAuth 2.0 Bearer token.
     * @return array{id: string, username: string, name: string}|null
     */
    public function getUserDataV1Fallback(string $accessToken): ?array {
        $response = SafeHttpClient::get('https://api.twitter.com/1.1/account/verify_credentials.json?' . http_build_query([
            'skip_status'   => 'true',
            'include_email' => 'true',
        ]), [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
            'timeout' => 15,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($data['screen_name'])) {
            return null;
        }

        return [
            'id'                => (string) ($data['id_str'] ?? $data['id'] ?? ''),
            'username'          => $data['screen_name'],
            'name'              => $data['name'] ?? '',
            'profile_image_url' => $data['profile_image_url_https'] ?? '',
            'email'             => $data['email'] ?? null,
        ];
    }

}