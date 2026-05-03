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
     * Paginated dispute list for the §V1.5 /users/:handle/disputes tab.
     *
     * Joins flags → votes → posts so the service can compose `subject`
     * (page title), `scope_label` (page type), and `body` (the flag
     * reason) without a per-row follow-up query.
     *
     * `flag.status` values: 0=open, 1=resolved, 2=dismissed (TINYINT).
     *
     * @return list<object{
     *   id: int|numeric-string,
     *   vote_id: int|numeric-string,
     *   reason: string,
     *   status: int|numeric-string,
     *   created_at: string,
     *   page_id: int|numeric-string|null,
     *   page_title: string|null,
     *   page_name: string|null,
     *   page_type: string|null
     * }>
     */
    public function findByFlagger(int $userId, int $limit, int $offset): array
    {
        if ($userId <= 0) {
            return [];
        }
        global $wpdb;
        $flagsTable = TableRegistry::flags();
        $votesTable = TableRegistry::votes();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT f.id,
                    f.vote_id,
                    f.reason,
                    f.status,
                    f.created_at,
                    v.page_id,
                    p.post_title AS page_title,
                    p.post_name  AS page_name,
                    p.post_type  AS page_type
             FROM {$flagsTable} f
             LEFT JOIN {$votesTable} v ON f.vote_id = v.id
             LEFT JOIN {$wpdb->posts} p ON v.page_id = p.ID
             WHERE f.flagger_user_id = %d
             ORDER BY f.created_at DESC
             LIMIT %d OFFSET %d",
            $userId,
            $limit,
            $offset
        ));

        /** @var list<object{
         *   id: int|numeric-string,
         *   vote_id: int|numeric-string,
         *   reason: string,
         *   status: int|numeric-string,
         *   created_at: string,
         *   page_id: int|numeric-string|null,
         *   page_title: string|null,
         *   page_name: string|null,
         *   page_type: string|null
         * }> $rows
         */
        return $rows ?: [];
    }

    /**
     * Lifetime count of flags signed by this user.
     *
     * Per §D2 / §B5, flagging a vote is the V1 dispute-signing
     * primitive — `bcc_trust_flags.flagger_user_id` is the user
     * who signed onto the dispute. Powers `counts.disputes_signed`
     * on the user view-model.
     */
    public function countByFlagger(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        global $wpdb;
        $table = TableRegistry::flags();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE flagger_user_id = %d",
            $userId
        ));
    }

    /**
     * Count flags signed by this user since a MySQL DATETIME boundary.
     * Used by the §O3 living header to surface today's dispute-signing
     * activity ("today: 1 dispute signed").
     */
    public function countByFlaggerSince(int $userId, string $sinceMysql): int
    {
        if ($userId <= 0 || $sinceMysql === '') {
            return 0;
        }

        global $wpdb;
        $table = TableRegistry::flags();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE flagger_user_id = %d
                AND created_at >= %s",
            $userId,
            $sinceMysql
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
