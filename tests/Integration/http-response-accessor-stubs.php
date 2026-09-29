<?php

declare(strict_types=1);

/**
 * The two WP HTTP response ACCESSORS, for integration tests that drive
 * {@see \BCC\Trust\Onchain\Support\ApiRetry::request()} directly.
 *
 * ── WHY THIS IS SO SMALL, AND WHY IT IS NOT IN THE BOOTSTRAP ────────────
 * `ApiRetry::request()` takes a callable, so a test can hand it a scripted
 * response without any transport at all — no `wp_remote_*`, no
 * `SafeHttpClient`, no network. What it still needs is the two accessors it
 * uses to read that response. The integration bootstrap already supplies
 * `WP_Error`, `is_wp_error()` and `wp_json_encode()`; only these two are
 * missing, and they are added here rather than in the shared bootstrap so
 * that 600-odd unrelated integration tests keep the surface they have today.
 *
 * Response shape is the compact `['code' => int, 'body' => string]` used by
 * the unit-suite wire doubles — the test scripts it and these accessors read
 * it, so the pair is self-consistent. Real WordPress nests the status under
 * `response.code`; nothing here depends on that difference, because the only
 * producer of these arrays is the test that also reads them back.
 *
 * @package BCC\Trust\Tests\Integration
 */

if (!function_exists('wp_remote_retrieve_response_code')) {
    /** @param array{code?: int, body?: string}|\WP_Error $response */
    function wp_remote_retrieve_response_code($response)
    {
        return is_array($response) ? (int) ($response['code'] ?? 0) : '';
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    /** @param array{code?: int, body?: string}|\WP_Error $response */
    function wp_remote_retrieve_body($response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }
}

if (!function_exists('wp_remote_retrieve_header')) {
    /**
     * Only `parseRetryAfter()` needs this, on the 429 branch. Defined anyway so
     * a future integration test that scripts a 429 does not fatal on it.
     *
     * @param array{code?: int, body?: string, headers?: array<string, string>}|\WP_Error $response
     */
    function wp_remote_retrieve_header($response, string $header)
    {
        return is_array($response) ? (string) ($response['headers'][strtolower($header)] ?? '') : '';
    }
}
