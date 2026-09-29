<?php

/**
 * The five WordPress helpers the Add-a-collection panel calls, and nothing else.
 *
 * ── WHY A DEDICATED FILE AND NOT ONE OF THE BIG STUB SETS ───────────────
 * `onchain-admin-render-stubs.php` supplies four of these but not
 * `wp_create_nonce()`, and the stub files that do supply it also fake
 * `CollectionRepository` and the fetcher factory — collaborators this test
 * must NOT have replaced, because the thing under test is which disclosure
 * the panel prints and that is decided by `NftChainCapability`.
 *
 * Everything here is escaping and URL construction. Nothing decides anything.
 *
 * ⚠ THE ESCAPERS ARE DELIBERATELY REAL-ISH. `esc_html()` returning its input
 * unchanged would let a test assert that copy renders when in production it
 * would render entity-encoded, so the entity behaviour is preserved.
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

namespace {
    if (!function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists('esc_attr')) {
        function esc_attr(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists('esc_url')) {
        function esc_url(string $url): string
        {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }
    }

    if (!function_exists('admin_url')) {
        function admin_url(string $path = ''): string
        {
            return 'https://example.test/wp-admin/' . ltrim($path, '/');
        }
    }

    if (!function_exists('wp_create_nonce')) {
        /**
         * A DETERMINISTIC placeholder. The panel only needs a non-empty token;
         * whether the nonce is verified is a different test's question.
         */
        function wp_create_nonce(string $action = '-1'): string
        {
            return 'nonce-' . substr(md5($action), 0, 10);
        }
    }
}
