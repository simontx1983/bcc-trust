<?php
/**
 * Reputation Event Repository — owns the bcc_reputation_events table.
 *
 * One row per actual score change for a user (no row when a recalc
 * is a no-op). Powers `progression.trust_score_recent_changes` on
 * the user view-model (§N11).
 *
 * Reasons stored as opaque short strings; the read-side may translate
 * them to display text. Current vocabulary:
 *   - 'vote_recalc'        — recompute triggered by a vote on the user's pages
 *   - 'endorsement_recalc' — triggered by an endorsement event
 *   - 'manual_recalc'      — admin-triggered or batch-triggered recalc
 *
 * @package BCC\Trust\Core\Repositories
 * @since V1 (2026-04, §N11)
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type ReputationEventRow object{
 *     id: int|numeric-string,
 *     user_id: int|numeric-string,
 *     score_before: float|numeric-string,
 *     score_after: float|numeric-string,
 *     delta: float|numeric-string,
 *     reason: string,
 *     created_at: string
 * }
 */
final class ReputationEventRepository
{
    private const COLUMNS = 'id, user_id, score_before, score_after, delta, reason, created_at';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::reputationEvents();
    }

    /**
     * Insert a reputation-change event row. Returns the new id, or
     * 0 on DB failure.
     *
     * Caller is responsible for the no-op short-circuit; this method
     * always inserts the row it's given.
     */
    public function record(int $userId, float $scoreBefore, float $scoreAfter, string $reason): int
    {
        if ($userId <= 0 || $reason === '') {
            return 0;
        }

        global $wpdb;

        $delta = $scoreAfter - $scoreBefore;
        $result = $wpdb->insert(
            $this->table,
            [
                'user_id'      => $userId,
                'score_before' => round($scoreBefore, 2),
                'score_after'  => round($scoreAfter, 2),
                'delta'        => round($delta, 2),
                'reason'       => $reason,
                'created_at'   => current_time('mysql', true),
            ],
            ['%d', '%f', '%f', '%f', '%s', '%s']
        );

        if ($result === false) {
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Recent score changes for a user, newest first. Used by the
     * user view-model's progression.trust_score_recent_changes.
     *
     * Bounded by LIMIT — the contract surfaces "the last few"
     * (default 5); higher caps don't make sense for the UI.
     *
     * @phpstan-return list<ReputationEventRow>
     */
    public function getRecentForUser(int $userId, int $limit = 5): array
    {
        if ($userId <= 0) {
            return [];
        }
        $limit = max(1, min(20, $limit));

        global $wpdb;
        /** @var list<ReputationEventRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE user_id = %d
              ORDER BY created_at DESC, id DESC
              LIMIT %d",
            $userId,
            $limit
        ));
        return $rows ?: [];
    }

    /**
     * Hard-delete every reputation-event row for a deleted user. Distinct
     * from ReputationRepository::delete (which clears the bcc_trust_reputation
     * snapshot row) — this clears the bcc_reputation_events ledger that keys
     * on user_id and was previously left behind on hard account deletion.
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
