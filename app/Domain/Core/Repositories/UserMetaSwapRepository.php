<?php
/**
 * User Meta Swap Repository: replace ONE user-meta value only if it is
 * still exactly the value that was read (H7).
 *
 * The read side is bcc-core's UserMetaRepository::firstValuesOrUnknown()
 * with `$fresh = true`: the raw stored string of the first row, '' for an
 * empty or SQL-NULL value, and the key absent when there is no row. This is
 * the write that goes with it, as three guarded statements:
 *
 *   - no row was read, a value is to be written:
 *       INSERT … SELECT … WHERE NOT EXISTS (a row for this user and key)
 *   - a value was read, a new value is to be written:
 *       UPDATE … SET meta_value = new WHERE user_id, meta_key AND <the read>
 *   - a value was read, the key is to be removed:
 *       DELETE … WHERE user_id, meta_key AND <the read>
 *
 * <the read> is `meta_value <=> %s`, or `(meta_value = '' OR meta_value IS
 * NULL)` when the read answered '' (it cannot tell an empty string from a
 * stored NULL, and `= ''` never matches NULL). Each statement changes
 * nothing when the row has moved on since the read, and the caller reads
 * again.
 *
 * ⚠⚠ WHY NOT update_user_meta($id, $key, $new, $prev) / delete_user_meta(…,
 * $prev). WordPress ignores an empty `$prev` ('' or '0' write
 * UNCONDITIONALLY), maybe_serialize()s the raw string it is handed so a
 * stored serialised value can never match, and with no row it ADDS. None
 * of those is a compare-and-swap.
 *
 * A raw write skips WordPress's meta hooks and cache, so a successful swap
 * drops both: UserMetaRepository::forget() and wp_cache_delete(…,
 * 'user_meta'). The statements were checked on MySQL 8 and MariaDB 11.8.
 *
 * @package BCC\Trust\Core\Repositories
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Core\Repositories\UserMetaRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class UserMetaSwapRepository
{
    /**
     * Swap the value of ($userId, $key) from $expected to $next.
     *
     * $expected: the raw value the caller read (null = no row was read).
     * $next:     the raw value to store (null = remove the key).
     *
     * True when the store now holds $next because of this call (or there
     * was nothing to do); false when the row was not as read, or the write
     * failed. False never means "partly written".
     */
    public static function swap(int $userId, string $key, ?string $expected, ?string $next): bool
    {
        global $wpdb;

        if ($userId <= 0 || $key === '') {
            return false;
        }
        if ($expected === null && $next === null) {
            return true;
        }

        if ($expected === null) {
            $sql = $wpdb->prepare(
                "INSERT INTO {$wpdb->usermeta} (user_id, meta_key, meta_value)
                 SELECT %d, %s, %s FROM DUAL
                  WHERE NOT EXISTS (
                        SELECT 1 FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s
                  )",
                $userId,
                $key,
                $next,
                $userId,
                $key
            );
        } elseif ($next === null) {
            $sql = self::guarded(
                "DELETE FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
                [$userId, $key],
                $expected
            );
        } else {
            $sql = self::guarded(
                "UPDATE {$wpdb->usermeta} SET meta_value = %s WHERE user_id = %d AND meta_key = %s",
                [$next, $userId, $key],
                $expected
            );
        }
        if (!is_string($sql) || $sql === '') {
            return false;
        }

        $affected = $wpdb->query($sql);
        if (!is_int($affected) || $affected < 1) {
            return false;
        }

        UserMetaRepository::forget($userId);
        wp_cache_delete($userId, 'user_meta');

        return true;
    }

    /**
     * $head plus the "still as read" condition, prepared.
     *
     * @param list<int|string> $args
     */
    private static function guarded(string $head, array $args, string $expected): ?string
    {
        global $wpdb;

        if ($expected === '') {
            $sql = $wpdb->prepare($head . " AND (meta_value = '' OR meta_value IS NULL)", ...$args);
        } else {
            $sql = $wpdb->prepare($head . ' AND meta_value <=> %s', ...[...$args, $expected]);
        }

        return is_string($sql) ? $sql : null;
    }
}
