<?php
/**
 * User Rank Repository
 *
 * CRUD for the bcc_user_ranks table per §E2 of the V1 plan.
 *
 * Apprentice + Journeyman are auto-assigned by reputation tier +
 * activity thresholds; Foreman+ are admin-conferred. Revocations
 * preserved as historical rows; current rank = WHERE revoked_at IS
 * NULL.
 *
 * The "single active rank per user" invariant is NOT enforced at the
 * DB level (MySQL has no partial unique indexes). The Service layer
 * MUST wrap award() in a transaction that first revokes the existing
 * active row.
 *
 * Note: column is rank_key, not rank, because RANK is reserved in
 * MySQL 8.0+.
 *
 * @package BCC\Trust\Core\Repositories
 * @since V1 (2026-04)
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type UserRankRow object{
 *     id: numeric-string,
 *     user_id: numeric-string,
 *     rank_key: string,
 *     awarded_by: numeric-string|null,
 *     awarded_at: string,
 *     revoked_at: string|null,
 *     revoke_reason: string|null
 * }
 */
class UserRankRepository
{
    /** Explicit column list — must match schema-user-ranks.php. */
    private const COLUMNS = 'id, user_id, rank_key, awarded_by, awarded_at, revoked_at, revoke_reason';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::userRanks();
    }

    /**
     * Bounded list of user_ids whose currently-active rank matches
     * `$rankKey` (active = `revoked_at IS NULL`). Powers the §G1
     * /members directory's rank filter pill.
     *
     * Note: this is the EXPLICITLY-AWARDED rank only — the auto-derived
     * fallback (apprentice for unranked users) is computed by
     * RankService at view time and is NOT stored here. Filtering by
     * `apprentice` returns only users with an explicit `apprentice`
     * award; unranked users won't surface even though their displayed
     * rank label is `Apprentice`. V1 acceptable: the directory's
     * existing implicit-Apprentice population is overwhelmingly the
     * baseline — surfacing it via the filter would defeat the filter's
     * purpose (find people higher up the ladder).
     *
     * Bounded by LIMIT 5000.
     *
     * @return list<int>
     */
    public function getUserIdsWithRank(string $rankKey): array
    {
        if ($rankKey === '') {
            return [];
        }

        global $wpdb;

        /** @var list<string> $rows */
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id
               FROM {$this->table}
              WHERE rank_key = %s
                AND revoked_at IS NULL
              LIMIT 5000",
            $rankKey
        ));

        return array_map('intval', $rows);
    }

    /**
     * Find the user's currently active rank (WHERE revoked_at IS NULL).
     * Returns null if the user has no active rank row (which means they
     * fall back to the auto-derived rank — that lookup lives in the
     * RankService, not here).
     *
     * If the application invariant is violated and multiple active rows
     * exist, this returns the most recently awarded one. The Service
     * layer is responsible for not creating that situation.
     *
     * @phpstan-return UserRankRow|null
     */
    public function findActive(int $userId): ?object
    {
        global $wpdb;

        /** @var UserRankRow|null */
        return $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
             WHERE user_id = %d AND revoked_at IS NULL
             ORDER BY awarded_at DESC
             LIMIT 1",
            $userId
        ));
    }

    /**
     * Find the user's rank history (active + revoked), most-recent first.
     *
     * @phpstan-return list<UserRankRow>
     */
    public function findHistory(int $userId, int $limit = 50): array
    {
        global $wpdb;

        $limit = max(1, min($limit, 200));

        /** @var list<UserRankRow>|null */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
             WHERE user_id = %d
             ORDER BY awarded_at DESC
             LIMIT %d",
            $userId,
            $limit
        ));

        return $rows ?: [];
    }

    /**
     * Insert a new active rank row for a user. Returns the new row's
     * ID, or 0 on failure.
     *
     * awarded_by is null for auto-derived assignments and the admin
     * user_id for admin-conferred ranks.
     *
     * IMPORTANT: callers MUST first revoke any existing active row
     * inside the same transaction. This Repository does not enforce
     * the single-active-rank invariant — that's the Service's job.
     */
    public function award(int $userId, string $rankKey, ?int $awardedBy): int
    {
        global $wpdb;

        $result = $wpdb->insert($this->table, [
            'user_id'    => $userId,
            'rank_key'   => $rankKey,
            'awarded_by' => $awardedBy,
            'awarded_at' => current_time('mysql', true),
        ], ['%d', '%s', '%d', '%s']);

        return $result === false ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Revoke a specific rank row by its primary key. Sets revoked_at
     * to now and stores the optional reason.
     */
    public function revoke(int $rowId, ?string $reason): bool
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->table,
            [
                'revoked_at'    => current_time('mysql', true),
                'revoke_reason' => $reason,
            ],
            ['id' => $rowId],
            ['%s', '%s'],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Revoke whatever active rank a user currently holds. Returns the
     * number of rows affected (0 if no active rank, 1 normally; > 1
     * indicates the single-active-rank invariant was violated and the
     * caller should investigate).
     */
    public function revokeActiveForUser(int $userId, ?string $reason): int
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET revoked_at = %s, revoke_reason = %s
             WHERE user_id = %d AND revoked_at IS NULL",
            current_time('mysql', true),
            $reason,
            $userId
        ));

        return (int) $result;
    }

    /**
     * Hard-delete every rank-award row (active and historical) for a
     * deleted user. Closes the orphan gap on hard account deletion —
     * bcc_user_ranks keys on user_id.
     *
     * Called from UserLifecycleService::onUserDelete (delete_user).
     */
    public function deleteForUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        global $wpdb;
        $wpdb->delete($this->table, ['user_id' => $userId], ['%d']);
    }
}
