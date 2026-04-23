<?php
/**
 * Audit Log Repository
 *
 * Handles all database operations for the activity (audit log) table.
 *
 * @package BCC\Trust\Core\Repositories
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Exceptions\RepositoryException;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Row shapes returned by bcc_trust_activity behavioural queries.
 *
 * @phpstan-type ActivityBreakdownRow object{
 *   hour: int|numeric-string,
 *   day_of_week: int|numeric-string,
 *   count: int|numeric-string,
 *   date: string
 * }
 * @phpstan-type ActivityActionRow object{
 *   created_at: string,
 *   action: string
 * }
 * @phpstan-type ActivityBrowsingRow object{
 *   action: string,
 *   target_id: int|numeric-string,
 *   created_at: string
 * }
 * @phpstan-type WeeklyActivityRow object{
 *   week_start: string,
 *   action_count: int|numeric-string,
 *   active_days: int|numeric-string
 * }
 */
class AuditLogRepository {

    private string $table;

    public function __construct() {
        $this->table = \BCC\Trust\Core\Database\TableRegistry::activity();
    }

    // -------------------------------------------------------------------------
    // Read methods
    // -------------------------------------------------------------------------

    /**
     * Check if the activity table exists.
     */
    public function tableExists(): bool {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) ) === $this->table;
    }

    /**
     * Get suspicious activity within the last N hours.
     *
     * @param int $hours  Lookback window in hours.
     * @param int $limit  Maximum rows to return.
     * @return object[]
     * @phpstan-return list<object{
     *   id: int|numeric-string,
     *   user_id: int|numeric-string,
     *   action: string,
     *   target_type: string,
     *   target_id: int|numeric-string,
     *   ip_address: string|null,
     *   created_at: string
     * }>
     */
    public function getSuspiciousActivity( int $hours = 24, int $limit = 100 ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT id, user_id, action, target_type, target_id, ip_address, created_at
             FROM {$this->table}
             WHERE (
                action LIKE 'suspicious%%'
                OR action LIKE 'fraud%%'
                OR action LIKE 'flag%%'
                OR action LIKE 'automation%%'
                OR action LIKE 'vote_ring%%'
             )
             AND created_at > (UTC_TIMESTAMP() - INTERVAL %d HOUR)
             ORDER BY created_at DESC
             LIMIT %d",
            $hours,
            $limit
        ) );
    }

    /**
     * Insert a new audit log entry.
     *
     * @param array<string, mixed> $data    Column => value pairs.
     * @param string[]             $formats wpdb format specifiers.
     * @return bool True on success, false on failure.
     */
    public function insertLog( array $data, array $formats ): bool {
        global $wpdb;

        $result = $wpdb->insert( $this->table, $data, $formats );

        return $result !== false;
    }

    /**
     * Get the last error from the underlying wpdb instance.
     */
    public function getLastError(): string {
        global $wpdb;
        return $wpdb->last_error;
    }

    /**
     * Delete activity records older than a retention period.
     *
     * @param int $retentionDays Number of days to retain.
     * @return int Number of deleted rows.
     */
    public function deleteOlderThan( int $retentionDays ): int {
        global $wpdb;

        return (int) $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->table}
             WHERE created_at < UTC_TIMESTAMP() - INTERVAL %d DAY",
            $retentionDays
        ) );
    }

    // -------------------------------------------------------------------------
    // Behavioral analysis queries
    // -------------------------------------------------------------------------

    /**
     * Get hourly/daily activity breakdown for a user over the last N days.
     *
     * @param int $userId
     * @param int $days
     * @return object[]
     * @phpstan-return list<ActivityBreakdownRow>
     */
    public function getActivityBreakdown( int $userId, int $days = 30 ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                HOUR(created_at) as hour,
                DAYOFWEEK(created_at) as day_of_week,
                COUNT(*) as count,
                DATE(created_at) as date
             FROM {$this->table}
             WHERE user_id = %d
               AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY DATE(created_at), HOUR(created_at), DAYOFWEEK(created_at)
             ORDER BY created_at DESC",
            $userId,
            $days
        ) );
    }

    /**
     * Get recent actions for a user within the last N days.
     *
     * @param int $userId
     * @param int $days
     * @return object[]
     * @phpstan-return list<ActivityActionRow>
     */
    public function getRecentActions( int $userId, int $days = 30 ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT created_at, action
             FROM {$this->table}
             WHERE user_id = %d
               AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
             ORDER BY created_at ASC",
            $userId,
            $days
        ) );
    }

    /**
     * Get browsing + voting actions for a user (page_view, vote_up, vote_down, page_visit).
     *
     * @param int $userId
     * @param int $days
     * @param int $limit
     * @return object[]
     * @phpstan-return list<ActivityBrowsingRow>
     */
    public function getBrowsingAndVotingActions( int $userId, int $days = 30, int $limit = 200 ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT action, target_id, created_at
             FROM {$this->table}
             WHERE user_id = %d
               AND action IN ('page_view', 'vote_up', 'vote_down', 'page_visit')
               AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
             ORDER BY created_at DESC
             LIMIT %d",
            $userId,
            $days,
            $limit
        ) );
    }

    /**
     * Get weekly activity summary for a user over the last N days.
     *
     * @param int $userId
     * @param int $days
     * @return object[]
     */
    public function getWeeklyActivitySummary( int $userId, int $days = 90 ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                WEEK(created_at) as week,
                YEAR(created_at) as year,
                COUNT(*) as action_count
             FROM {$this->table}
             WHERE user_id = %d
               AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY YEAR(created_at), WEEK(created_at)
             ORDER BY year DESC, week DESC",
            $userId,
            $days
        ) );
    }

    /**
     * Count vote actions for a user within a time window.
     *
     * @param int $userId
     * @param int $minutes Lookback window in minutes.
     * @return int
     */
    public function countRecentVoteActions( int $userId, int $minutes ): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE user_id = %d
             AND action IN ('vote_up', 'vote_down')
             AND created_at > (UTC_TIMESTAMP() - INTERVAL %d MINUTE)",
            $userId,
            $minutes
        ) );
    }

    /**
     * Count distinct users sharing an IP address (excluding a given user) within a time window.
     *
     * @param string $ipBinary Binary IP address.
     * @param int    $excludeUserId
     * @param int    $days Lookback window in days.
     * @return int
     */
    public function countDistinctUsersForIp( string $ipBinary, int $excludeUserId, int $days ): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT user_id)
             FROM {$this->table}
             WHERE ip_address = %s
             AND user_id != %d
             AND user_id IS NOT NULL
             AND created_at > (UTC_TIMESTAMP() - INTERVAL %d DAY)",
            $ipBinary,
            $excludeUserId,
            $days
        ) );
    }

    /**
     * Get the most recent IP address for a user.
     *
     * @param int $userId
     * @return string|null Binary IP or null.
     */
    public function getLastIpForUser( int $userId ): ?string {
        global $wpdb;

        $ipBinary = $wpdb->get_var( $wpdb->prepare(
            "SELECT ip_address
             FROM {$this->table}
             WHERE user_id = %d
             AND ip_address IS NOT NULL
             ORDER BY created_at DESC
             LIMIT 1",
            $userId
        ) );

        return $ipBinary ?: null;
    }

    /**
     * Count activity records older than a given cutoff date.
     *
     * @param string $cutoff MySQL datetime string.
     * @return int
     */
    public function countOlderThan(string $cutoff): int {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE created_at < %s",
            $cutoff
        ));
    }

    /**
     * Archive old activity records by copying them to the archive table
     * and then deleting the originals.
     *
     * @param int $batchSize Maximum rows to process per call.
     * @return int Number of rows copied to archive.
     */
    public function archiveBatch(int $batchSize = 5000): int {
        global $wpdb;

        $archiveTable = \BCC\Trust\Core\Database\TableRegistry::activityArchive();

        $copied = (int) $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$archiveTable} (id, user_id, action, target_type, target_id, ip_address, created_at)
             SELECT id, user_id, action, target_type, target_id, ip_address, created_at FROM {$this->table}
             WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
             LIMIT %d",
            $batchSize
        ));

        if ($copied > 0) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->table}
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
                 LIMIT %d",
                $batchSize
            ));
        }

        return $copied;
    }

    /**
     * Check whether a specific table exists in the database.
     *
     * Generic helper used by admin repair tools that iterate over
     * TableRegistry entries.
     *
     * @param string $tableName Fully-qualified table name.
     * @return bool
     */
    public static function rawTableExists(string $tableName): bool {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tableName)) === $tableName;
    }
}
