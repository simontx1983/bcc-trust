<?php
/**
 * Suspension Repository
 *
 * Handles database operations for the suspensions table.
 *
 * @package BCC\Trust\Core\Repositories
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Exceptions\RepositoryException;

if (!defined('ABSPATH')) {
    exit;
}

class SuspensionRepository
{
    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::suspensions();
    }

    /**
     * Insert a new suspension record.
     *
     * @return int The inserted row ID, or 0 on failure.
     * @throws RepositoryException on database failure
     */
    public function create(int $userId, int $suspendedBy, string $reason, string $notes = '', int $fraudScoreAtTime = 0): int
    {
        global $wpdb;

        $result = $wpdb->insert(
            $this->table,
            [
                'user_id'             => $userId,
                'suspended_by'        => $suspendedBy,
                'reason'              => $reason,
                'notes'               => $notes,
                'fraud_score_at_time' => $fraudScoreAtTime,
                'suspended_at'        => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s']
        );

        if ($result === false) {
            throw new RepositoryException('SuspensionRepository::create failed: ' . $wpdb->last_error);
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Close active suspension(s) for a user by setting unsuspended_at/unsuspended_by.
     *
     * @return int Number of rows updated.
     * @throws RepositoryException on database failure
     */
    public function closeActive(int $userId, int $unsuspendedBy): int
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->table,
            [
                'unsuspended_at' => current_time('mysql'),
                'unsuspended_by' => $unsuspendedBy,
            ],
            ['user_id' => $userId, 'unsuspended_at' => null],
            ['%s', '%d'],
            ['%d', '%s']
        );

        if ($result === false) {
            throw new RepositoryException('SuspensionRepository::closeActive failed: ' . $wpdb->last_error);
        }

        return (int) $result;
    }

    /**
     * Archive old closed suspensions by appending [ARCHIVED] to notes.
     *
     * @param int $olderThanDays Suspensions closed more than this many days ago.
     * @return int Number of rows updated.
     */
    public function archiveOldClosed(int $olderThanDays): int
    {
        global $wpdb;

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$olderThanDays} days") ?: 0);

        return (int) $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table} SET notes = CONCAT(notes, ' [ARCHIVED]')
                 WHERE unsuspended_at IS NOT NULL
                 AND unsuspended_at < %s",
                $cutoff
            )
        );
    }

    /**
     * Count orphaned suspension records (user no longer exists).
     */
    public function countOrphaned(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE u.ID IS NULL AND %d",
            1
        ));
    }

    /**
     * Delete orphaned suspension records (user no longer exists).
     *
     * @return int Number of rows deleted.
     */
    public function deleteOrphaned(): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE s FROM {$this->table} s
             LEFT JOIN {$wpdb->users} u ON s.user_id = u.ID
             WHERE u.ID IS NULL AND %d",
            1
        ));
    }
}
