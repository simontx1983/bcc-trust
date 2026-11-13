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
    use \BCC\Trust\Onchain\Repositories\GuardsReadFailures;

    /** PeepSo's block table (read-only here; bcc-core owns the writes). */
    private const BLOCKS_TABLE_SUFFIX = 'peepso_blocks';

    /** Per direction; same bound as bcc-core PeepSoBlockRepository::getBlockedIds(). */
    private const BLOCK_READ_LIMIT = 500;

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
     * F-296: the members on the other side of a block with $userId (either
     * direction), as an SQL exclusion on `not_from_user_id`. A block hides a
     * member's old bell rows too: they used to stay in the list, count
     * toward the badge and go out in the digest. Applied in SQL, before
     * LIMIT, for the same reason as the type scope (a post-filter makes
     * short pages and pagination dead-ends). System rows (from user 0) are
     * never excluded.
     *
     * FAIL-CLOSED. The same two bounded reads the feed makes, but through
     * runRead(): bcc-core's getBlockedIds()/getBlockerIds() are get_col()
     * reads that return [] on a failed query, which would read as "no
     * blocks" and drop the filter. A failed read THROWS instead: the bell
     * list and the badge count answer 503, and the digest skips that user
     * this run (DigestService counts it as failed; nothing is mailed).
     *
     * Beyond BLOCK_READ_LIMIT blocks in one direction the rest stay
     * unfiltered (same bound as the feed; >500 blocks is pathological).
     *
     * @return array{0: string, 1: list<int>} [" AND `not_from_user_id` NOT IN (…)" or "", its ids]
     * @throws \BCC\Trust\Onchain\Repositories\RepositoryReadFailure when a block read did not run
     */
    private static function blockedActorClause(int $userId): array
    {
        global $wpdb;
        $blocks = $wpdb->prefix . self::BLOCKS_TABLE_SUFFIX;

        $blocked = self::runRead($wpdb->prepare(
            "SELECT blk_blocked_id AS uid FROM {$blocks} WHERE blk_user_id = %d LIMIT %d",
            $userId,
            self::BLOCK_READ_LIMIT
        ), __FUNCTION__, true);
        $blockers = self::runRead($wpdb->prepare(
            "SELECT blk_user_id AS uid FROM {$blocks} WHERE blk_blocked_id = %d LIMIT %d",
            $userId,
            self::BLOCK_READ_LIMIT
        ), __FUNCTION__, true);

        $ids = [];
        foreach (array_merge($blocked, $blockers) as $row) {
            $id = isset($row->uid) ? (int) $row->uid : 0;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        $ids = array_values($ids);
        if ($ids === []) {
            return ['', []];
        }
        return [' AND `not_from_user_id` NOT IN (' . implode(',', array_fill(0, count($ids), '%d')) . ')', $ids];
    }

    /**
     * List BCC notifications for a user, newest first. Cursor is
     * exclusive (the row matching $beforeId is NOT included; pass 0
     * to start from the top).
     *
     * Scoped to `not_type IN (NotificationType::ALL)` at the SQL
     * boundary — BEFORE LIMIT — so rows of retired types
     * (`bcc_card_pulled`, `bcc_endorse`, …) can't consume page slots.
     * Post-query filtering alone (shapeRow → null) produced short
     * pages, and a page whose raw rows were ALL retired returned
     * `items: []` with `has_more: true` but a null cursor — a
     * pagination dead-end hiding every older valid row. shapeRow's
     * validation stays as defense-in-depth for corrupt rows.
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

        $types        = \BCC\Trust\Core\Support\NotificationType::ALL;
        $placeholders = implode(',', array_fill(0, count($types), '%s'));
        [$blockedSql, $blockedIds] = self::blockedActorClause($userId);

        if ($beforeId > 0) {
            $sql = "SELECT " . self::COLUMNS . " FROM `{$table}`"
                . " WHERE `not_user_id` = %d"
                . " AND `not_module_id` = %d"
                . " AND `not_id` < %d"
                . " AND `not_type` IN ({$placeholders})"
                . $blockedSql
                . " ORDER BY `not_id` DESC"
                . " LIMIT %d";
            /** @var list<object>|null $rows */
            $rows = $wpdb->get_results(
                $wpdb->prepare($sql, $userId, BCC_NOTIFICATION_MODULE_ID, $beforeId, ...array_merge($types, $blockedIds, [$limit]))
            );
        } else {
            $sql = "SELECT " . self::COLUMNS . " FROM `{$table}`"
                . " WHERE `not_user_id` = %d"
                . " AND `not_module_id` = %d"
                . " AND `not_type` IN ({$placeholders})"
                . $blockedSql
                . " ORDER BY `not_id` DESC"
                . " LIMIT %d";
            /** @var list<object>|null $rows */
            $rows = $wpdb->get_results(
                $wpdb->prepare($sql, $userId, BCC_NOTIFICATION_MODULE_ID, ...array_merge($types, $blockedIds, [$limit]))
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
     *
     * Scoped to `not_type IN (NotificationType::ALL)`: rows of retired
     * types (`bcc_card_pulled`, `bcc_endorse`, …) are dropped by the
     * read-side validation in NotificationViewService::shapeRow, so
     * counting them here would show a badge for rows the list never
     * renders — a phantom-unread badge the user can't clear row-by-row.
     */
    public function countUnreadForUser(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        global $wpdb;
        $table = $this->table();

        $types        = \BCC\Trust\Core\Support\NotificationType::ALL;
        $placeholders = implode(',', array_fill(0, count($types), '%s'));

        // F-296: a blocked member's rows are not listed, so not counted.
        [$blockedSql, $blockedIds] = self::blockedActorClause($userId);

        $sql = "SELECT COUNT(*) FROM `{$table}`"
            . " WHERE `not_user_id` = %d"
            . " AND `not_module_id` = %d"
            . " AND `not_read` = 0"
            . " AND `not_type` IN ({$placeholders})"
            . $blockedSql;
        $count = $wpdb->get_var(
            $wpdb->prepare($sql, $userId, BCC_NOTIFICATION_MODULE_ID, ...array_merge($types, $blockedIds))
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

        // Same valid-type scope as countUnreadForUser — the digest must
        // not resurrect rows of retired types the bell list won't show.
        //
        // ⚠ Less the BELL_ONLY types (delegator-group removal, auto-hide
        // notice): they are in-app only by requirement, and every other
        // unread row here goes out in an email.
        $types = array_values(array_diff(
            \BCC\Trust\Core\Support\NotificationType::ALL,
            \BCC\Trust\Core\Support\NotificationType::BELL_ONLY
        ));
        $placeholders = implode(',', array_fill(0, count($types), '%s'));

        // F-296: never email a blocked member's rows either.
        [$blockedSql, $blockedIds] = self::blockedActorClause($userId);

        $sql = "SELECT " . self::COLUMNS . " FROM `{$table}`"
            . " WHERE `not_user_id` = %d"
            . " AND `not_module_id` = %d"
            . " AND `not_read` = 0"
            . " AND `not_type` IN ({$placeholders})"
            . $blockedSql
            . " AND `not_timestamp` >= %s"
            . " ORDER BY `not_timestamp` ASC"
            . " LIMIT %d";

        /** @var list<object>|null $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare($sql, $userId, BCC_NOTIFICATION_MODULE_ID, ...array_merge($types, $blockedIds, [$sinceMysql, $limit]))
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
     * Keyset-paged: pass the last id of the previous page as $afterUserId.
     * The digest used to read ONE page of 5000 and silently stop there.
     *
     * @return list<int>
     */
    public function findUserIdsByMetaFlag(string $metaKey, int $limit, int $afterUserId = 0): array
    {
        if ($metaKey === '' || $limit <= 0) {
            return [];
        }

        global $wpdb;

        /** @var list<numeric-string>|null $rows */
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id FROM {$wpdb->usermeta}
              WHERE meta_key = %s AND meta_value = %s AND user_id > %d
              ORDER BY user_id ASC
              LIMIT %d",
            $metaKey,
            '1',
            max(0, $afterUserId),
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

    /**
     * How many users have $metaKey = '1'. Admin display only. Null when the
     * read fails — a DB error must not display as "0 members".
     */
    public function countUsersByMetaFlag(string $metaKey): ?int
    {
        if ($metaKey === '') {
            return 0;
        }

        global $wpdb;

        $wpdb->last_error = '';
        $n = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s",
            $metaKey,
            '1'
        ));

        $lastError = (string) $wpdb->last_error;
        if (strlen($lastError) > 0 || !is_numeric($n)) {
            return null;
        }
        return (int) $n;
    }
}
