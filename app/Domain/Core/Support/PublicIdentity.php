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
     * The name OTHER people see: displayName(), unless the member turned
     * on "Hide my real name" (§K2 `real_name_hidden`), in which case
     * everyone but the member themselves sees '@handle'. The same rule
     * the profile has always applied (UserViewService), now shared by
     * every surface that renders a member's name (F-28).
     *
     * $viewerId is the person looking (0 = anonymous or unknown). Pass it
     * whenever you have it, so a member still sees their own real name.
     * Without it the hidden name is masked, the safe direction.
     *
     * Moderation and other admin surfaces are deliberately NOT routed
     * through this: staff see the real name.
     */
    public static function visibleName(int $userId, mixed $displayName, mixed $userLogin, string $handle, int $viewerId = 0): string
    {
        if ($userId > 0 && $userId !== $viewerId && PrivacySettings::flag($userId, 'real_name_hidden')) {
            return '@' . ($handle !== '' ? $handle : self::HANDLE_PREFIX . $userId);
        }
        return self::displayName($displayName, $userLogin, $handle);
    }

    /**
     * Convenience: both values for a user in one call (one meta read).
     *
     * Takes any object with ID / display_name / user_login: a WP_User, or
     * the plain row objects some callers (and test harnesses) pass.
     * Missing properties read as empty.
     *
     * `display_name` is the name OTHER people see: it honours "Hide my
     * real name" through visibleName(). Pass the viewer's id so a member
     * sees their own real name.
     *
     * @return array{handle: string, display_name: string}
     */
    public static function forUser(object $user, int $viewerId = 0): array
    {
        $id     = isset($user->ID) && is_numeric($user->ID) ? (int) $user->ID : 0;
        $handle = self::handle($id, $id > 0 ? get_user_meta($id, 'bcc_handle', true) : '');
        return [
            'handle'       => $handle,
            'display_name' => self::visibleName(
                $id,
                $user->display_name ?? '',
                $user->user_login ?? '',
                $handle,
                $viewerId
            ),
        ];
    }
}
