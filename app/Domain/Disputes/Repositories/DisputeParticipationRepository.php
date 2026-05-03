<?php

namespace BCC\Trust\Disputes\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository for dispute panel-vote participation records.
 *
 * Owns every read + write against `bcc_dispute_participations`. The
 * service layer orchestrates eligibility / caps; this layer is pure
 * SQL with explicit columns and bounded queries (no `SELECT *`,
 * always parameterised).
 *
 * Idempotency contract:
 *   - INSERT relies on the table's UNIQUE(user_id, dispute_id).
 *     `recordParticipation` reports duplicate-key vs other DB errors
 *     so the service can treat them differently — duplicates are
 *     "already credited" (success-equivalent), other errors fail loud.
 *
 * Counting contract:
 *   - `countCreditedToday` and `countCreditedLifetime` only count rows
 *     with `was_credited = 1`. Skipped rows (caps hit, suspended) are
 *     stored for audit but DO NOT contribute to trust math.
 *   - Time bound: `INTERVAL 24 HOUR` from CURRENT_TIMESTAMP. We
 *     deliberately use a rolling 24h window rather than a calendar
 *     day so timezone games can't reset the cap at midnight.
 */
final class DisputeParticipationRepository
{
    /**
     * Insert a participation row.
     *
     * @param int         $userId        Panelist user id.
     * @param int         $disputeId     Dispute id.
     * @param string      $decision      'accept' or 'reject'.
     * @param bool        $wasCredited   True when the credit counted
     *                                   toward trust; false when caps
     *                                   or eligibility skipped it.
     * @param string|null $skipReason    Required when $wasCredited is
     *                                   false; null when credited.
     *
     * @return array{
     *   inserted: bool,
     *   duplicate: bool,
     *   db_error: string|null
     * } - `duplicate=true` when UNIQUE(user_id,dispute_id) caught a
     *     re-credit attempt; `inserted=false` AND `duplicate=false`
     *     means a real DB error (caller logs + bails).
     */
    public function recordParticipation(
        int $userId,
        int $disputeId,
        string $decision,
        bool $wasCredited,
        ?string $skipReason
    ): array {
        global $wpdb;
        $table = TableRegistry::disputeParticipations();

        // Build the row explicitly so an extra arg can never sneak in.
        $data = [
            'user_id'               => $userId,
            'dispute_id'            => $disputeId,
            'decision'              => $decision,
            'was_credited'          => $wasCredited ? 1 : 0,
            'credit_skipped_reason' => $skipReason,
            // outcome_match is NULL until DisputeResolver backfills it.
            'created_at'            => gmdate('Y-m-d H:i:s'),
        ];
        $formats = ['%d', '%d', '%s', '%d', $skipReason === null ? null : '%s', '%s'];
        // Drop the null-format slot so wpdb->insert handles credit_skipped_reason=NULL correctly.
        if ($skipReason === null) {
            unset($data['credit_skipped_reason'], $formats[4]);
            $formats = array_values($formats);
        }

        $result = $wpdb->insert($table, $data, $formats);

        if ($result === false) {
            $err = (string) $wpdb->last_error;
            // MySQL "Duplicate entry … for key 'uq_user_dispute'" — treat
            // as idempotent success. Any other DB error is real.
            $isDuplicate = stripos($err, 'duplicate entry') !== false
                && stripos($err, 'uq_user_dispute') !== false;
            return [
                'inserted'  => false,
                'duplicate' => $isDuplicate,
                'db_error'  => $isDuplicate ? null : $err,
            ];
        }

        return ['inserted' => true, 'duplicate' => false, 'db_error' => null];
    }

    /**
     * Count credited participations within the last 24 hours.
     * Drives the daily-cap gate.
     */
    public function countCreditedToday(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        global $wpdb;
        $table = TableRegistry::disputeParticipations();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE user_id = %d
               AND was_credited = 1
               AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)",
            $userId
        ));
    }

    /**
     * Count credited participations across all time.
     * Drives the lifetime-cap gate AND the trust-score base term.
     */
    public function countCreditedLifetime(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        global $wpdb;
        $table = TableRegistry::disputeParticipations();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE user_id = %d AND was_credited = 1",
            $userId
        ));
    }

    /**
     * Count credited participations whose decision matched the final
     * dispute outcome. Drives the trust-score accuracy term.
     *
     * Rows with outcome_match = NULL (still-reviewing OR timeout disputes)
     * are NOT counted as either correct or incorrect — they simply don't
     * contribute to the accuracy term until/unless backfilled.
     */
    public function countCorrect(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        global $wpdb;
        $table = TableRegistry::disputeParticipations();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE user_id = %d AND was_credited = 1 AND outcome_match = 1",
            $userId
        ));
    }

    /**
     * Count credited+correct rows from the trailing 24h window. Drives
     * the daily trust cap calculation alongside countCreditedToday.
     */
    public function countCorrectToday(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }
        global $wpdb;
        $table = TableRegistry::disputeParticipations();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE user_id = %d
               AND was_credited = 1
               AND outcome_match = 1
               AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)",
            $userId
        ));
    }

    /**
     * Canonical trust-bonus formula. Single source of truth used by
     * both the daily-cap check and the lifetime-cap check:
     *
     *   raw  = (credited * BASE) + (credited >= MIN ? correct * ACCURACY : 0)
     *
     * The clamp at LIFETIME_TRUST_CAP / DAILY_TRUST_CAP is the caller's
     * job — this method returns the unclamped raw bonus so callers can
     * compose it with the right cap.
     *
     * NOTE: MIN_FOR_ACCURACY is checked against LIFETIME credited, even
     * when computing daily bonus. The threshold is "has this user shown
     * up enough overall to earn accuracy bonuses?", not a per-day gate.
     * Daily callers pass `lifetimeCredited` separately for that check.
     */
    public static function computeRawBonus(int $credited, int $correct, int $lifetimeCreditedForGate): float
    {
        $base = $credited * BCC_DISPUTE_PARTICIPATION_BASE_WEIGHT;
        $accuracy = ($lifetimeCreditedForGate >= BCC_DISPUTE_PARTICIPATION_MIN_FOR_ACCURACY)
            ? $correct * BCC_DISPUTE_PARTICIPATION_ACCURACY_WEIGHT
            : 0.0;
        return $base + $accuracy;
    }

    /**
     * Total trust contribution this user has earned (clamped at the
     * lifetime cap). Drives the lifetime-cap gate at insert time and
     * the user-level trust score wiring downstream.
     */
    public function getEarnedLifetimeTrust(int $userId): float
    {
        $credited = $this->countCreditedLifetime($userId);
        $correct  = $this->countCorrect($userId);
        $raw = self::computeRawBonus($credited, $correct, $credited);
        return min($raw, (float) BCC_DISPUTE_PARTICIPATION_LIFETIME_TRUST_CAP);
    }

    /**
     * Trust contribution this user has earned in the last 24h
     * (clamped at the daily cap). Drives the daily-cap gate.
     */
    public function getEarnedDailyTrust(int $userId): float
    {
        $todayCredited = $this->countCreditedToday($userId);
        $todayCorrect  = $this->countCorrectToday($userId);
        $lifetimeCredited = $this->countCreditedLifetime($userId);
        $raw = self::computeRawBonus($todayCredited, $todayCorrect, $lifetimeCredited);
        return min($raw, (float) BCC_DISPUTE_PARTICIPATION_DAILY_TRUST_CAP);
    }

    /**
     * Pre-check (race-safe): has this user already participated on this
     * dispute? Used by the service to short-circuit before doing cap
     * lookups; the UNIQUE index is the authoritative gate.
     */
    public function hasParticipated(int $userId, int $disputeId): bool
    {
        if ($userId <= 0 || $disputeId <= 0) {
            return false;
        }
        global $wpdb;
        $table = TableRegistry::disputeParticipations();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE user_id = %d AND dispute_id = %d
             LIMIT 1",
            $userId, $disputeId
        )) > 0;
    }

    /**
     * Backfill `outcome_match` for every credited row attached to a
     * just-resolved dispute. Called from DisputeResolver after the
     * adjudication completes.
     *
     * `$finalDecision` must be 'accept' or 'reject' — i.e. the
     * panel-vote enum value, NOT the dispute-status enum
     * ('accepted'/'rejected'). The resolver maps before calling.
     *
     * Defensive design (per pushback during V1 build):
     *   - The match comparison happens in PHP, NOT in SQL. A future
     *     "partial outcome" or "invalid dispute" verdict would
     *     silently break a SQL `decision = %s` shorthand; explicit
     *     PHP logic screams instead.
     *   - The UPDATE is per-row with an explicit `WHERE id = ? AND
     *     outcome_match IS NULL` guard, so a re-run (DB retry, admin
     *     replay) never overwrites an already-marked row.
     *   - Skipped rows (was_credited=0) are left untouched — they
     *     didn't contribute to the count, they don't need an accuracy
     *     mark.
     *
     * @return int Rows updated. Caller may log if 0, but it's not
     *             necessarily a bug — a dispute with all panelists
     *             cap-blocked would legitimately update 0 rows.
     */
    public function backfillOutcomeMatch(int $disputeId, string $finalDecision): int
    {
        if ($disputeId <= 0) {
            return 0;
        }
        if (!in_array($finalDecision, ['accept', 'reject'], true)) {
            // Defensive: the resolver should never pass anything else,
            // but if it does we refuse to write rather than silently
            // mis-match every row.
            \BCC\Core\Log\Logger::error('[bcc-trust] backfillOutcomeMatch invalid decision', [
                'dispute_id' => $disputeId,
                'decision'   => $finalDecision,
            ]);
            return 0;
        }
        global $wpdb;
        $table = TableRegistry::disputeParticipations();

        // Pre-fetch the rows that still need a verdict. Bounded query
        // (panel size is at most BCC_DISPUTES_PANEL_SIZE = 5 today, so
        // this returns at most that many rows per dispute).
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, decision FROM {$table}
             WHERE dispute_id = %d
               AND was_credited = 1
               AND outcome_match IS NULL",
            $disputeId
        ));

        if (!is_array($rows)) {
            \BCC\Core\Log\Logger::error('[bcc-trust] backfillOutcomeMatch fetch failed', [
                'dispute_id' => $disputeId,
                'db_error'   => (string) $wpdb->last_error,
            ]);
            return 0;
        }

        $updatedCount = 0;
        foreach ($rows as $row) {
            // Explicit comparison in PHP — future drift (e.g. a new
            // verdict enum, an admin override path that writes
            // 'invalid' as decision) will surface here as a 0-match
            // instead of a SQL miscount.
            $rowDecision = (string) $row->decision;
            $match = ($rowDecision === $finalDecision) ? 1 : 0;

            // Idempotency guard: WHERE outcome_match IS NULL ensures
            // the UPDATE is a no-op on retry, regardless of how the
            // row got marked the first time.
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET outcome_match = %d
                 WHERE id = %d AND outcome_match IS NULL",
                $match,
                (int) $row->id
            ));

            if ($result === false) {
                \BCC\Core\Log\Logger::error('[bcc-trust] backfillOutcomeMatch row UPDATE failed', [
                    'dispute_id' => $disputeId,
                    'row_id'     => (int) $row->id,
                    'db_error'   => (string) $wpdb->last_error,
                ]);
                continue;
            }
            $updatedCount += (int) $result;
        }

        return $updatedCount;
    }
}
