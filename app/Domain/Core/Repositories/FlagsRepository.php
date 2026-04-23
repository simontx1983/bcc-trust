<?php

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository for vote flag operations.
 *
 * Created to extract $wpdb from TrustRestController::report_vote().
 */
class FlagsRepository
{
    /**
     * Check if a user has already flagged a specific vote.
     */
    public function hasUserFlaggedVote(int $voteId, int $userId): bool
    {
        global $wpdb;
        $table = TableRegistry::flags();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE vote_id = %d AND flagger_user_id = %d",
            $voteId, $userId
        )) > 0;
    }

    /**
     * Count flags created by a user in the last 24 hours.
     */
    public function countUserFlagsToday(int $userId): int
    {
        global $wpdb;
        $table = TableRegistry::flags();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE flagger_user_id = %d
               AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
            $userId
        ));
    }

    /**
     * Insert a new flag record.
     *
     * Race-safe: the underlying table has UNIQUE(vote_id, flagger_user_id) so
     * parallel requests from the same user cannot double-insert. On duplicate
     * key, $wpdb->insert returns false and this method returns 0, letting the
     * caller short-circuit without advancing flag_count state.
     *
     * @return int The inserted flag ID, or 0 on duplicate/failure.
     */
    public function create(int $voteId, int $userId, string $reason): int
    {
        global $wpdb;
        $table = TableRegistry::flags();

        $inserted = $wpdb->insert($table, [
            'vote_id'         => $voteId,
            'flagger_user_id' => $userId,
            'reason'          => $reason,
            'status'          => 0,
            'created_at'      => current_time('mysql'),
        ], ['%d', '%d', '%s', '%d', '%s']);

        if ($inserted === false) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Count total flags on a vote.
     */
    public function countForVote(int $voteId): int
    {
        global $wpdb;
        $table = TableRegistry::flags();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE vote_id = %d",
            $voteId
        ));
    }
}
