<?php
/**
 * PublicIdentity: the one place that decides what PUBLIC handle and
 * display name a member shows when their own data is missing.
 *
 * `wp_users.user_login` is never public. It used to be the fallback
 * everywhere a member had no `bcc_handle` (UserMini, cards, feed
 * authors, comments, mentions, notifications, ...), so accounts created
 * outside BCC signup (wp-admin "Add User", WP-CLI, legacy rows) showed
 * their login as their @handle. WordPress also defaults display_name
 * to user_login for such accounts, so the display name leaked it too.
 *
 * Rules:
 *   - handle:       bcc_handle when set, else 'member-{id}' (the same
 *                   deterministic form the canonical-handle backfill
 *                   assigns as its last resort).
 *   - display name: display_name when set AND not equal to user_login
 *                   (case-insensitive); otherwise the public handle;
 *                   'Member' only if even that is empty.
 *
 * Every user is now assigned a bcc_handle on creation (user_register
 * hook, Plugin.php) and legacy rows by the canonical-handle backfill,
 * so the 'member-{id}' branch is a safety net, not a normal path.
 *
 * @package BCC\Trust\Core\Support
 */

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class PublicIdentity
{
    public const HANDLE_PREFIX = 'member-';
    public const FALLBACK_NAME = 'Member';

    /**
     * Public handle for $userId given its raw bcc_handle meta value
     * (whatever get_user_meta / a joined column returned).
     */
    public static function handle(int $userId, mixed $metaHandle): string
    {
        $handle = is_string($metaHandle) ? trim($metaHandle) : '';
        return $handle !== '' ? $handle : self::HANDLE_PREFIX . $userId;
    }

    /**
     * Public display name. $handle is the already-resolved PUBLIC handle
     * (from handle()), never the login.
     */
    public static function displayName(mixed $displayName, mixed $userLogin, string $handle): string
    {
        $name  = is_string($displayName) ? trim($displayName) : '';
        $login = is_string($userLogin) ? trim($userLogin) : '';
        if ($name !== '' && ($login === '' || strcasecmp($name, $login) !== 0)) {
            return $name;
        }
        return $handle !== '' ? $handle : self::FALLBACK_NAME;
    }

    /**
     * Convenience: both values for a WP_User in one call (one meta read).
     *
     * @return array{handle: string, display_name: string}
     */
    public static function forUser(\WP_User $user): array
    {
        $handle = self::handle((int) $user->ID, get_user_meta((int) $user->ID, 'bcc_handle', true));
        return [
            'handle'       => $handle,
            'display_name' => self::displayName($user->display_name, $user->user_login, $handle),
        ];
    }
}
