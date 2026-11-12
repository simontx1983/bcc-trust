<?php
/**
 * PeepSoNameParts — PeepSo's computed member name must never be the login.
 *
 * D1 (staging, 2026-11-12): after display_name_placeholders_v3 rewrote
 * display_name = user_login to the handle, two accounts had it set back
 * to the LOGIN 32 seconds later. Cause: PeepSo's "Use PeepSo name
 * everywhere" option in aggressive mode (peepso_config.use_name_everywhere
 * = 2). PeepSoUser::get_fullname() then calls wp_update_user(display_name
 * => its computed name) on any render, for any user with a first or last
 * name. With the display style 'username' (PeepSo's default) that computed
 * name IS user_login. Admin renders also append '(user_login)'.
 *
 * PeepSo passes its computed name parts through `peepso_get_name_parts`
 * just before using them. This filter:
 *   - replaces any part equal to the login (case-insensitive) with the
 *     member's public handle (PublicIdentity::handle: bcc_handle, else
 *     member-{id});
 *   - drops a '(login)' admin suffix part.
 * Aggressive mode then writes the HANDLE, which is what the display-name
 * hygiene backfill writes too, so the two stop fighting, and PeepSo's own
 * surfaces stop showing logins. Real first/last names are untouched.
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoNameParts
{
    public static function register(): void
    {
        add_filter('peepso_get_name_parts', [self::class, 'filter'], 10, 2);
    }

    /**
     * @param mixed $nameParts PeepSo's name parts (expected list<string>)
     * @param mixed $userId
     * @return mixed the same shape, never containing the login
     */
    public static function filter(mixed $nameParts, mixed $userId = 0): mixed
    {
        $id = is_numeric($userId) ? (int) $userId : 0;
        if ($id <= 0 || !is_array($nameParts) || $nameParts === []) {
            return $nameParts;
        }
        $user = get_userdata($id);
        if ($user === false) {
            return $nameParts;
        }
        $login = trim((string) $user->user_login);
        if ($login === '') {
            return $nameParts;
        }

        $handle = PublicIdentity::handle($id, get_user_meta($id, 'bcc_handle', true));
        $out    = [];
        foreach ($nameParts as $part) {
            $text = trim(html_entity_decode((string) $part, ENT_QUOTES, 'UTF-8'));
            if (strcasecmp($text, '(' . $login . ')') === 0) {
                continue; // the admin '(login)' suffix
            }
            $out[] = strcasecmp($text, $login) === 0 ? $handle : $part;
        }

        return $out === [] ? [$handle] : $out;
    }
}
