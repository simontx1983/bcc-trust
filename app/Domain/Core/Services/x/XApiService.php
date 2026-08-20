<?php
/**
 * X (Twitter) API Service
 *
 * Fetches user data from the X API v2.
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

class XApiService {

    private const USER_ME_URL = 'https://api.x.com/2/users/me';

    /**
     * Tweet fields requested when scanning a user's timeline.
     *
     * `entities` is load-bearing, not decoration. X rewrites every link in
     * the `text` field to a t.co shortlink, so a tweet that visibly reads
     * "… bluecollarcrypto.io/u/phillip" comes back from the API as
     * "… https://t.co/aB3xY9". Matching a host against `text` alone
     * therefore NEVER fires for a shared link — which is exactly what the
     * share_x quest asks people to post. The original URL survives only in
     * `entities.urls[]`.
     *
     * Before this field was requested, every correct share was reported to
     * the user as "No matching tweet found yet."
     */
    private const TIMELINE_TWEET_FIELDS = 'text,created_at,entities';

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
     * Searches the user's last 20 tweets (within 7 days) for a substring
     * match on the given URL — across BOTH the rendered text and the
     * expanded form of every link, since X shortens links in `text`.
     * See {@see self::TIMELINE_TWEET_FIELDS} and {@see self::matchableStrings()}.
     *
     * @param string $accessToken Bearer token for the user.
     * @param string $xUserId     The user's X numeric ID.
     * @param string $urlSubstring URL or substring to search for.
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
            'tweet.fields' => self::TIMELINE_TWEET_FIELDS,
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
        if ($needle === '' || !is_array($tweets)) {
            return false;
        }

        foreach ($tweets as $tweet) {
            if (is_array($tweet) && self::tweetMatches($tweet, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does this tweet reference the thing we are looking for?
     *
     * Two different tests, deliberately — because the two sources have
     * different trust properties:
     *
     *   - `text` is free-form prose, so a plain SUBSTRING match is right. It
     *     is how a hashtag or a typed-out mention is found.
     *   - link entities are matched on HOST, parsed, never by substring.
     *
     * That asymmetry is the security-relevant part. A substring test against
     * an expanded URL would accept `https://bluecollarcrypto.io.evil.com/x`
     * — an attacker-controlled domain that merely CONTAINS ours — and hand
     * out the share_x trust bonus for a link to somebody else's site. Host
     * parsing rejects it, while still accepting real subdomains.
     *
     * @param array<string, mixed> $tweet
     */
    private static function tweetMatches(array $tweet, string $needle): bool {

        $text = $tweet['text'] ?? null;
        if (is_string($text) && stripos($text, $needle) !== false) {
            return true;
        }

        $entities = $tweet['entities'] ?? null;
        $urls     = is_array($entities) ? ($entities['urls'] ?? null) : null;
        if (!is_array($urls)) {
            return false;
        }

        // X exposes up to three progressively-resolved forms per link and
        // guarantees none of them individually: expanded_url (as posted),
        // display_url (truncated for rendering, still carries the host), and
        // unwound_url (present when X followed a redirect chain). The t.co
        // value itself is skipped — it never carries the original host, so it
        // can only produce false negatives.
        foreach ($urls as $url) {
            if (!is_array($url)) {
                continue;
            }
            foreach (['expanded_url', 'display_url', 'unwound_url'] as $key) {
                $value = $url[$key] ?? null;
                if (is_string($value) && self::urlHasHost($value, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * True when $candidate is a URL whose host is $host, or a subdomain of it.
     *
     * `display_url` arrives without a scheme ("example.com/a/b"), which
     * parse_url would read as a path, so a scheme is prepended when absent.
     */
    private static function urlHasHost(string $candidate, string $host): bool {

        if ($candidate === '' || $host === '') {
            return false;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $candidate) !== 1) {
            $candidate = 'https://' . $candidate;
        }

        $parsed = wp_parse_url($candidate, PHP_URL_HOST);
        if (!is_string($parsed) || $parsed === '') {
            return false;
        }

        $parsed = strtolower($parsed);
        $host   = strtolower($host);

        return $parsed === $host || str_ends_with($parsed, '.' . $host);
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