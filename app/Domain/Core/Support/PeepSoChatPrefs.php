<?php
/**
 * PeepSoChatPrefs: a member's two DM settings, `peepso_chat_enabled` and
 * `peepso_chat_friends_only`, with PeepSo's own meaning, read so a FAILED
 * read is "unknown" and never a default (H6).
 *
 * ⚠⚠ WHY NOT PeepSoChatModel::chat_enabled(). It reads through
 * get_user_meta(), and on an empty answer it writes the default
 * (update_user_meta(…, 'peepso_chat_enabled', 1), peepso-messages
 * classes/chatmodel.php). A failed meta read also answers '', so BCC
 * neither reads through it nor lets it write. Here:
 *   - both values come from ONE trusted read (bcc-core
 *     UserMetaRepository::firstValuesOrUnknown());
 *   - a read that failed is null, and the caller refuses;
 *   - nothing is ever written.
 *
 * PeepSo's meaning, mirrored exactly (chatmodel.php):
 *   - chat_enabled: false when the site option `messages_chat_enable` is
 *     off. Otherwise no row, or a value that is not 0 or 1 (PHP's loose
 *     `in_array($v, [0, 1])`), is ENABLED; a stored value equal to 0 is
 *     off, equal to 1 is on.
 *   - chat_friends_only: false without PeepSoFriends. Otherwise no row (or
 *     an empty value) means the site option `messages_friends_only`
 *     (PeepSo default 1); a value equal to 1 is on; anything else is off.
 * "Equal to" is PHP's loose `==`: a numeric string ("1", " 1", "1.0") is
 * compared as a number, a non-numeric one never equals 0 or 1.
 *
 * @package BCC\Trust\Core\Support
 */

namespace BCC\Trust\Core\Support;

use BCC\Core\Repositories\UserMetaRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoChatPrefs
{
    public const ENABLED_KEY      = 'peepso_chat_enabled';
    public const FRIENDS_ONLY_KEY = 'peepso_chat_friends_only';

    /**
     * One member's settings, or null when they could not be read.
     *
     * $fresh skips UserMetaRepository's request memo (it reads, and does
     * not remember). The memo is already dropped on every user-meta write.
     *
     * @return array{chat_enabled: bool, chat_friends_only: bool}|null
     */
    public static function forUser(int $userId, bool $fresh = false): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $all = self::forUsers([$userId], $fresh);

        return $all === null ? null : $all[$userId];
    }

    /**
     * Every member's settings from one read (one query per
     * UserMetaRepository chunk), or null when any of them could not be read.
     *
     * @param list<int> $userIds
     * @return array<int, array{chat_enabled: bool, chat_friends_only: bool}>|null keyed by user id
     */
    public static function forUsers(array $userIds, bool $fresh = false): ?array
    {
        $ids = [];
        foreach ($userIds as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $stored = UserMetaRepository::firstValuesOrUnknown(
            array_values($ids),
            [self::ENABLED_KEY, self::FRIENDS_ONLY_KEY],
            $fresh
        );
        if ($stored === null) {
            return null;
        }

        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'chat_enabled'      => self::enabledFrom($stored[$id][self::ENABLED_KEY] ?? null),
                'chat_friends_only' => self::friendsOnlyFrom($stored[$id][self::FRIENDS_ONLY_KEY] ?? null),
            ];
        }

        return $out;
    }

    /**
     * PeepSoChatModel::chat_enabled()'s answer for a stored value (null =
     * no row), without its write.
     */
    public static function enabledFrom(?string $stored): bool
    {
        if (class_exists('PeepSo') && !\PeepSo::get_option('messages_chat_enable', 1)) {
            return false;
        }
        // Off only for a value PHP's `== 0` holds for. No row, '', or a
        // value outside {0, 1} is PeepSo's "default to 1".
        return !self::looselyEquals($stored, 0.0);
    }

    /**
     * PeepSoChatModel::chat_friends_only()'s answer for a stored value
     * (null = no row).
     */
    public static function friendsOnlyFrom(?string $stored): bool
    {
        if (!class_exists('PeepSoFriends')) {
            return false;
        }
        if ($stored === null || $stored === '') {
            return class_exists('PeepSo')
                ? (int) \PeepSo::get_option('messages_friends_only', 1) === 1
                : true;
        }

        return self::looselyEquals($stored, 1.0);
    }

    /**
     * PHP 8's `$stored == 0` / `== 1` for a string: a numeric string is
     * compared as a number; a non-numeric one equals neither.
     */
    private static function looselyEquals(?string $stored, float $number): bool
    {
        return $stored !== null && is_numeric($stored) && (float) $stored === $number;
    }
}
