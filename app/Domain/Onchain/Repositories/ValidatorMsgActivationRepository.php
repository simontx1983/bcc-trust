<?php
/**
 * Validator Msg Activation Repository
 *
 * Durable first-activation records for validator messaging — one row
 * per validator page, created atomically on the FIRST verified
 * operator claim. Distinguishes the five claim-lifecycle states the
 * messaging surface must not conflate (never-activated / first
 * operator / previously-claimed-now-unclaimed / transferred /
 * ambiguous — the resolver supplies the claim side, this table
 * supplies the history side).
 *
 * State transitions are WHERE-guarded UPDATEs (any other transition
 * is a bug and affects 0 rows): scheduled → running → completed |
 * failed, plus failed → running for sweep recovery.
 *
 * @package BCC\Trust\Onchain\Repositories
 */

namespace BCC\Trust\Onchain\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type ActivationRow object{
 *     id: string,
 *     page_id: string,
 *     entity_id: string,
 *     first_operator_user_id: string,
 *     first_activated_at: string,
 *     backlog_status: string,
 *     backlog_started_at: string|null,
 *     backlog_completed_at: string|null,
 *     last_error_code: string|null,
 *     sweep_attempts: string,
 *     updated_at: string
 * }
 */
class ValidatorMsgActivationRepository {

    /** @var string Explicit column list — must match schema-validator-msg-activation.php. */
    private const COLUMNS = 'id, page_id, entity_id, first_operator_user_id,
                 first_activated_at, backlog_status, backlog_started_at,
                 backlog_completed_at, last_error_code, sweep_attempts, updated_at';

    public static function table(): string {
        return \BCC\Core\DB\DB::table('validator_msg_activation');
    }

    /**
     * Atomically create THE first-activation record for a page.
     *
     * INSERT … ON DUPLICATE KEY UPDATE id = id on uq_page(page_id):
     * rows_affected === 1 ⇒ THIS call performed first activation and
     * the caller may schedule the backlog job; 0 ⇒ the record already
     * existed (repeated hook delivery, claim transfer, replayed
     * event) ⇒ the caller does nothing. Idempotent by construction —
     * duplicate bcc_page_claimed firings cannot create a second
     * activation or restart a completed backlog. Precedent:
     * ClaimRepository::upsert's insert-vs-update discrimination.
     */
    public static function createFirstActivation(int $pageId, int $entityId, int $operatorUserId): bool {
        if ($pageId <= 0 || $entityId <= 0 || $operatorUserId <= 0) {
            return false;
        }
        global $wpdb;
        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (page_id, entity_id, first_operator_user_id,
                 first_activated_at, backlog_status, updated_at)
             VALUES (%d, %d, %d, UTC_TIMESTAMP(), 'scheduled', UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE id = id",
            $pageId,
            $entityId,
            $operatorUserId
        ));

        return $wpdb->rows_affected === 1;
    }

    /**
     * @return ActivationRow|null
     */
    public static function getByPageId(int $pageId): ?object {
        if ($pageId <= 0) {
            return null;
        }
        global $wpdb;
        $table   = self::table();
        $columns = self::COLUMNS;

        /** @var ActivationRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$columns} FROM {$table} WHERE page_id = %d LIMIT 1",
            $pageId
        ));
        return $row;
    }

    /**
     * Batch read for card-list paths (destination-state computation).
     * Bounded: IN-list capped at 100 (list pages cap at 50).
     *
     * @param int[] $pageIds
     * @return array<int, ActivationRow> keyed by page_id
     */
    public static function getByPageIds(array $pageIds): array {
        if ($pageIds === []) {
            return [];
        }
        $unique = [];
        foreach ($pageIds as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $unique[$id] = true;
            }
        }
        if ($unique === []) {
            return [];
        }
        $ids = array_slice(array_keys($unique), 0, 100);

        global $wpdb;
        $table   = self::table();
        $columns = self::COLUMNS;
        $ph      = implode(',', array_fill(0, count($ids), '%d'));

        /** @var list<ActivationRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$columns} FROM {$table} WHERE page_id IN ({$ph})",
            ...$ids
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[(int) $row->page_id] = $row;
        }
        return $out;
    }

    /**
     * scheduled|failed → running (failed re-entry is the sweep's
     * recovery pass). Stamps backlog_started_at once (COALESCE).
     * Returns false when the guard matched no row — the caller must
     * treat that as "someone else owns this state" and stop.
     */
    public static function markBacklogRunning(int $pageId): bool {
        if ($pageId <= 0) {
            return false;
        }
        global $wpdb;
        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET backlog_status = 'running',
                    backlog_started_at = COALESCE(backlog_started_at, UTC_TIMESTAMP()),
                    updated_at = UTC_TIMESTAMP()
              WHERE page_id = %d
                AND backlog_status IN ('scheduled', 'running', 'failed')",
            $pageId
        ));
        return $wpdb->rows_affected > 0;
    }

    /**
     * running → completed. Terminal: a completed backlog is never
     * re-run (claim transfers must not replay it).
     */
    public static function markBacklogCompleted(int $pageId): bool {
        if ($pageId <= 0) {
            return false;
        }
        global $wpdb;
        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET backlog_status = 'completed',
                    backlog_completed_at = UTC_TIMESTAMP(),
                    last_error_code = NULL,
                    updated_at = UTC_TIMESTAMP()
              WHERE page_id = %d
                AND backlog_status = 'running'",
            $pageId
        ));
        return $wpdb->rows_affected > 0;
    }

    /**
     * scheduled|running → failed, with a machine CODE only (≤40 chars,
     * never message bodies / free text). Visible operational failure
     * state — surfaced via /system/health + `wp bcc-trust vmq status`.
     */
    public static function markBacklogFailed(int $pageId, string $errorCode): bool {
        if ($pageId <= 0) {
            return false;
        }
        global $wpdb;
        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET backlog_status = 'failed',
                    last_error_code = %s,
                    updated_at = UTC_TIMESTAMP()
              WHERE page_id = %d
                AND backlog_status IN ('scheduled', 'running')",
            substr($errorCode, 0, 40),
            $pageId
        ));
        return $wpdb->rows_affected > 0;
    }

    /**
     * Sweep bookkeeping — how many recovery passes have touched this
     * activation. Monotonic; no state change.
     */
    public static function bumpSweepAttempts(int $pageId): void {
        if ($pageId <= 0) {
            return;
        }
        global $wpdb;
        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET sweep_attempts = sweep_attempts + 1,
                    updated_at = UTC_TIMESTAMP()
              WHERE page_id = %d",
            $pageId
        ));
    }

    /**
     * Pages whose backlog is not finished — the sweep's work-list.
     * Bounded LIMIT; oldest first so stalled pages recover in order.
     *
     * @return list<ActivationRow>
     */
    public static function getUnfinished(int $limit = 20): array {
        global $wpdb;
        $table   = self::table();
        $columns = self::COLUMNS;
        $limit   = max(1, min($limit, 100));

        /** @var list<ActivationRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$columns} FROM {$table}
              WHERE backlog_status IN ('scheduled', 'running', 'failed')
              ORDER BY first_activated_at ASC
              LIMIT %d",
            $limit
        ));
        return $rows ?: [];
    }
}
