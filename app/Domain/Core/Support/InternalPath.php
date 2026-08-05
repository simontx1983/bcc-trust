<?php
/**
 * InternalPath — pure validator for relative in-app hrefs.
 *
 * The headless frontend treats every server-supplied href as a Next.js
 * route. An absolute or protocol-relative URL slipping through renders
 * as a full-page cross-origin navigation OFF the app — a defect class
 * that has bitten the search surfaces three times (v1.46 users
 * vertical, v1.47 projects `page_url`, v1.70 groups `group_url`).
 * Consumers that pass upstream-built hrefs through to the frontend
 * MUST gate them here and drop rows that fail.
 *
 * Scope: this is the INBOUND/emit-side twin of a check the codebase
 * did not have (§11 scan 2026-08-04: zero existing relative-path
 * validators). It is NOT FrontendRedirect::validateReturnTo — that
 * class allowlists ABSOLUTE frontend-origin URLs for auth redirects
 * (opposite polarity); do not merge the two.
 *
 * Deliberately a pure static predicate (no WP dependencies) so unit
 * tests run WP-free and callers can gate at any layer.
 *
 * @package BCC\Trust\Core\Support
 * @since v1.70 (§G1 autocomplete community/member rows)
 */

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class InternalPath
{
    /**
     * True only for a safe relative in-app path: leading '/', not
     * protocol-relative ('//host'), no backslashes (IE/edge-case
     * scheme smuggling + Windows-path confusion), no ASCII control
     * characters, and nothing parse_url() resolves a scheme or host
     * from. Query strings and fragments are allowed.
     *
     * Valid:   /halls/cosmos-hall · /communities/cosmos · /u/simon · /
     * Invalid: //evil.example/x · https://… · /a\b · "foo/bar" ·
     *          embedded \x00-\x1F/\x7F · ''
     */
    public static function isValid(string $path): bool
    {
        if ($path === '' || $path[0] !== '/') {
            return false;
        }
        // '//host/…' is protocol-relative: the browser substitutes the
        // current scheme and navigates off-origin. The leading-slash
        // check above cannot catch it.
        if (isset($path[1]) && $path[1] === '/') {
            return false;
        }
        if (str_contains($path, '\\')) {
            return false;
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return false;
        }
        // Native parse_url (not wp_parse_url) keeps this class pure —
        // identical behaviour on PHP 8, and unit tests need no WP shim.
        $parts = parse_url($path);
        if (!is_array($parts) || isset($parts['scheme']) || isset($parts['host'])) {
            return false;
        }
        return true;
    }
}
