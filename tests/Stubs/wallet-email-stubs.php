<?php
/**
 * Stubs for WalletPlaceholderEmailTest (subprocess-only).
 *
 * placeholderEmailForWallet() + the backfill token helper key on
 * `wp_salt('auth')`. Provide a controllable global stub so a test can
 * fix the salt, vary it (to prove the token is keyed), and empty it (to
 * exercise the fail-closed random branch).
 *
 * Fixture: $GLOBALS['__bcc_wp_email_salt'] — string returned by wp_salt().
 */

declare(strict_types=1);

namespace {
    if (!function_exists('wp_salt')) {
        function wp_salt(string $scheme = 'auth'): string
        {
            $salt = $GLOBALS['__bcc_wp_email_salt'] ?? 'unit-test-fixed-salt';
            return is_string($salt) ? $salt : '';
        }
    }
}
