<?php
/**
 * Notification Repository — read + mark-read for BCC-emitted rows in
 * peepso_notifications.
 *
 * BCC writes to PeepSo's table via PeepSoNotificationWriter (single-
 * graph rule). Reads are scoped to BCC_NOTIFICATION_MODULE_ID — we
 * deliberately bypass PeepSo's own `get_by_user` query because it
 * post_type-filters against `peepso-post`/`peepso-comment`/...
 * which would silently drop BCC rows whose external_id points at a
 * peepso-activity-status post (not in PeepSo's allow-list).
 *
 * Cross-plugin coupling note: the table name (peepso_notifications)
 * is owned by PeepSo. We pin it as a class constant rather than
 * surface it via TableRegistry — TableRegistry is for BCC-trust-owned
 * tables and a peepso entry there would muddy that contract. If
 * PeepSo ever renames the table the rename lands here and only here.
 *
 * Pagination: cursor on not_id (auto-increment, monotonic). The
 * cursor is the last-seen not_id; rows < cursor in DESC order.
 *
 * @package BCC\Trust\Core\Repositories
 * @since V1 (2026-04, §I1)
 */

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type NotificationRow object{
 *   not_id: numeric-string,
 *   not_user_id: numeric-string,
 *   not_from_user_id: numeric-string,
 *   not_module_id: numeric-string,
 *   not_external_id: numeric-string,
 *   not_act_id: numeric-string,
 *   not_type: string,
 *   not_message: string,
 *   not_timestamp: string,
 *   not_read: numeric-string
 * }
 */
final class NotificationRepository
{
    /** Owned by PeepSo — see class docblock. */
    private const TABLE_SUFFIX = 'peepso_notifications';

    /** @phpstan-var literal-string */
    private const COLUMNS = 'not_id, not_user_id, not_from_user_id, not_module_id, not_external_id, not_act_id, not_type, not_message, not_timestamp, not_read';

    private function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    /**
     * List BCC notifications for a user, newest first. Cursor is
     * exclusive (the row matching $beforeId is NOT included; pass 0
     * to start from the top).
     *
     * @return list<object>
     */
    public function findForUser(int $userId, int $limit, int $beforeId): array
    {
        if ($userId <= 0 || $limit <= 0) {
            return [];
        }
        $limit = min(50, $limit);

        global $wpdb;
        $table = $this->table();

        if ($beforeId > 0) {
            $sql = "SELECT " . self::COLUMNS . " FROM `{$table}`"
                . " WHERE `not_user_id` = %d"
                . " AND `not_module_id` = %d"
                . " AND `not_id` < %d"
                . " ORDER BY `not_id` DESC"
                . " LIMIT %d";
            /** @var list<object>|null $rows */
            $rows = $wpdb->get_results(
                $wpdb->prepare($sql, $userId, BCC_NOTIFICATION_MODULE_ID, $beforeId, $limit)
            );
        } else {
            $sql = "SELECT " . self::COLUMNS . " FROM `{$table}`"
                . " WHERE `not_user_id` = %d"
                . " AND `not_module_id` = %d"
                . " ORDER BY `not_id` DESC"
                . " LIMIT %d";
            /** @var list<object>|null $rows */
            $rows = $wpdb->get_results(
                $wpdb->prepare($sql, $userId, BCC_NOTIFICATION_MODULE_ID, $limit)
            );
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Count unread BCC notifications for a user. Drives the bell
     * badge — must stay cheap; the (not_user_id, not_module_id,
     * not_read) covering index would be ideal but we don't own this
     * table's schema. Bounded by the user's notification volume,
     * which is small in practice.
     */
    public function countUnreadForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        global $wpdb;
        $table = $this->table();

        $sql = "SELECT COUNT(*) FROM `{$table}`"
            . " WHERE `not_user_id` = %d"
            . " AND `not_module_id` = %d"
            . " AND `not_read` = 0";
        $count = $wpdb->get_var(
            $wpdb->prepare($sql, $userId, BCC_NOTIFICATION_MODULE_ID)
        );
        return $count !== null ? (int) $count : 0;
    }

    /**
     * Mark a specific BCC notification read. Returns true when one
     * row was updated, false otherwise (already read, wrong owner,
     * non-BCC row). The user_id check is the auth gate — a viewer
     * can only mark their own notifications.
     */
    public function markRead(int $userId, int $notificationId): bool
    {
        if ($userId <= 0 || $notificationId <= 0) {
            return false;
        }
        global $wpdb;
        $table = $this->table();

        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`"
                . " SET `not_read` = 1"
                . " WHERE `not_id` = %d"
                . " AND `not_user_id` = %d"
                . " AND `not_module_id` = %d"
                . " AND `not_read` = 0",
                $notificationId,
                $userId,
                BCC_NOTIFICATION_MODULE_ID
            )
        );
        return is_int($affected) && $affected > 0;
    }

    /**
     * Mark every unread BCC notification for a user as read.
     * Returns the number of rows updated. Single bulk UPDATE — no
     * loop or cursor overhead.
     */
    public function markAllReadForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        global $wpdb;
        $table = $this->table();

        $affected = $wpdb->query(
            $wpdb->prepare(
                "UPDATE `{$table}`"
                . " SET `not_read` = 1"
                . " WHERE `not_user_id` = %d"
                . " AND `not_module_id` = %d"
                . " AND `not_read` = 0",
                $userId,
                BCC_NOTIFICATION_MODULE_ID
            )
        );
        return is_int($affected) ? $affected : 0;
    }

    /**
     * §I1 weekly digest — unread BCC notifications since a cutoff
     * timestamp, oldest first. Used by DigestService to compose the
     * email body. Bounded by `$limit` (50 max) so a single user with
     * thousands of unread rows can't blow up the cron.
     *
     * @param string $sinceMysql 'YYYY-MM-DD HH:MM:SS' UTC, exclusive lower bound
     * @return list<object>
     */
    public function findUnreadSince(int $userId, string $sinceMysql, int $limit = 25): array
    {
        if ($userId <= 0 || $limit <= 0) {
            return [];
        }
        $limit = min(50, $limit);

        global $wpdb;
        $table = $this->table();

        $sql = "SELECT " . self::COLUMNS . " FROM `{$table}`"
            . " WHERE `not_user_id` = %d"
            . " AND `not_module_id` = %d"
            . " AND `not_read` = 0"
            . " AND `not_timestamp` >= %s"
            . " ORDER BY `not_timestamp` ASC"
            . " LIMIT %d";

        /** @var list<object>|null $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $userId, BCC_NOTIFICATION_MODULE_ID, $sinceMysql, $limit)
        );
        return is_array($rows) ? $rows : [];
    }

    /**
     * Enumerate wp_user IDs whose usermeta flag $metaKey is set to '1'.
     *
     * Used by the weekly digest cron to list opt-in recipients without
     * scanning the full users table. Bounded by an explicit LIMIT. The
     * caller passes the already-resolved meta key string so this repository
     * does not depend back on the NotificationPrefs Support class.
     *
     * @return list<int>
     */
    public function findUserIdsByMetaFlag(string $metaKey, int $limit): array
    {
        if ($metaKey === '' || $limit <= 0) {
            return [];
        }

        global $wpdb;

        /** @var list<numeric-string>|null $rows */
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
              WHERE meta_key = %s AND meta_value = %s
              LIMIT %d",
            $metaKey,
            '1',
            $limit
        ));

        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $value) {
            $userId = (int) $value;
            if ($userId > 0) {
                $out[] = $userId;
            }
        }
        return $out;
    }
}
