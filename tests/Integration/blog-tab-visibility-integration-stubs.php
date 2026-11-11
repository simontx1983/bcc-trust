<?php

/**
 * The only doubles BlogTabGroupVisibilityIntegrationTest needs.
 *
 * Deliberately TINY. Everything that decides visibility — the group rows,
 * the membership rows, the per-post markers, the deny list, the SQL — is
 * real and in MySQL. What is doubled here is only the session identity and
 * the author display block, neither of which the gate consults.
 *
 * `get_current_user_id()` follows the house convention
 * (`$GLOBALS['__bcc_current_user']`) used by the other integration suites.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_current_user'] ?? 0);
        }
    }

    // hydrateAuthor() takes its early exit on false, so the author block is
    // a constant and no user plumbing is pulled into a visibility test.
    if (!function_exists('get_userdata')) {
        function get_userdata(int $userId)
        {
            return false;
        }
    }

    if (!function_exists('get_avatar_url')) {
        function get_avatar_url($idOrEmail, array $args = []): string
        {
            return '';
        }
    }

    if (!function_exists('get_user_meta')) {
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            return $single ? '' : [];
        }
    }
}
