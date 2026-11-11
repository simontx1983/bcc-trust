<?php
/**
 * Rank Pending Repository — owns `wp_bcc_trust_rank_pending`, the
 * 24-hour Apprentice confirmation clock (Rank Phase 5, R1).
 *
 * One row per New Member; INSERT IGNORE on PK(user_id) means the FIRST
 * qualifying contribution starts the clock and later ones are no-ops
 * WHILE it is pending. A voided row is re-armed by the next qualifying
 * contribution (restartIfVoided) — states: pending → confirmed | voided,
 * voided → pending.
 * The 5-minute sweep resolves due rows via the R1 six-condition
 * predicate (ApprenticeReadinessService) — pending reports and
 * auto-hide never touch due_at.
 *
 * @package BCC\Trust\Rank\Repositories
 * @since Rank redesign Phase 5 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type RankPendingRow object{
 *     user_id: numeric-string,
 *     source_type: string,
 *     source_id: numeric-string,
 *     content_act_id: numeric-string,
 *     due_at: string,
 *     status: string
 * }
 */
class RankPendingRepository
{
    private const COLUMNS = 'user_id, source_type, source_id, content_act_id, due_at, status';

    /** Due rows resolved per sweep tick. */
    private const SWEEP_BATCH = 100;

    /**
     * Cool-off before a CONTENT-based void may be re-armed (product
     * decision 2026-11-10): a confirmation voided because the content was
     * removed, unpublished or had a report upheld waits this long after
     * the void before a new contribution restarts the clock. Measured from
     * `resolved_at`, which resolve() stamps (UTC) at the moment of voiding.
     */
    public const CONTENT_VOID_COOLOFF_SECONDS = 7 * 86400;

    /**
     * Void reasons subject to the cool-off. Everything else — notably
     * `suspended` — restarts immediately: the sweep re-checks suspension
     * at due_at, so a still-suspended member just voids again.
     */
    public const COOLOFF_VOID_REASONS = ['content_removed', 'content_unpublished', 'report_upheld'];

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::rankPending();
    }

    /**
     * Start the confirmation clock. INSERT IGNORE — only the first
     * qualifying contribution counts; re-fires are no-ops.
     */
    public function start(int $userId, string $sourceType, int $sourceId, int $contentActId, int $dueInSeconds): bool
    {
        if ($userId <= 0 || $sourceId <= 0) {
            return false;
        }

        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->table}
                (user_id, source_type, source_id, content_act_id, due_at, status)
             VALUES (%d, %s, %d, %d, %s, 'pending')",
            $userId,
            $sourceType,
            $sourceId,
            $contentActId,
            gmdate('Y-m-d H:i:s', time() + max(0, $dueInSeconds))
        ));

        return $result !== false;
    }

    /**
     * Re-arm a VOIDED confirmation with a new qualifying contribution.
     *
     * `start()` is INSERT IGNORE on PK(user_id), so once a row was voided
     * every later contribution was a silent no-op and the member could
     * never reach Apprentice (F-71). A void judges ONE contribution; a
     * later contribution is new evidence and gets its own 24-hour clock.
     *
     * Guarded UPDATE, so it is a no-op unless the row is currently
     * `voided`:
     *   - `status = 'voided'` — a pending clock is never reset or
     *     extended (R1: nothing moves due_at), and a confirmed row is
     *     never reopened;
     *   - `source_id <> %d` — the contribution that was voided cannot
     *     re-arm itself (a re-fire for the same post stays voided);
     *   - cool-off — a CONTENT-based void (COOLOFF_VOID_REASONS) re-arms
     *     only once CONTENT_VOID_COOLOFF_SECONDS have passed since the
     *     void (`resolved_at`). A contribution inside the window is a
     *     no-op; a later one after it re-arms. Other reasons (suspended)
     *     restart immediately. A NULL `resolved_at` (not written by
     *     resolve(), so never expected) counts as past the window rather
     *     than locking the member out forever.
     * Concurrent re-fires settle on one winner: the second UPDATE finds
     * the row already pending and matches nothing.
     *
     * @return bool True when a voided row was restarted.
     */
    public function restartIfVoided(int $userId, string $sourceType, int $sourceId, int $contentActId, int $dueInSeconds): bool
    {
        if ($userId <= 0 || $sourceId <= 0) {
            return false;
        }

        global $wpdb;

        $reasonPlaceholders = implode(',', array_fill(0, count(self::COOLOFF_VOID_REASONS), '%s'));

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
                SET status = 'pending',
                    source_type = %s,
                    source_id = %d,
                    content_act_id = %d,
                    due_at = %s,
                    voided_reason = NULL,
                    resolved_at = NULL
              WHERE user_id = %d AND status = 'voided' AND source_id <> %d
                AND (voided_reason IS NULL
                     OR voided_reason NOT IN ({$reasonPlaceholders})
                     OR resolved_at IS NULL
                     OR resolved_at <= %s)",
            $sourceType,
            $sourceId,
            $contentActId,
            gmdate('Y-m-d H:i:s', time() + max(0, $dueInSeconds)),
            $userId,
            $sourceId,
            ...[...self::COOLOFF_VOID_REASONS, gmdate('Y-m-d H:i:s', time() - self::CONTENT_VOID_COOLOFF_SECONDS)]
        ));

        return $result !== false && (int) $result > 0;
    }

    /**
     * @phpstan-return RankPendingRow|null
     */
    public function getForUser(int $userId): ?object
    {
        if ($userId <= 0) {
            return null;
        }

        global $wpdb;

        /** @var RankPendingRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE user_id = %d",
            $userId
        ));

        return $row;
    }

    /**
     * Due pending rows for the sweep, bounded.
     *
     * @return list<object>
     * @phpstan-return list<RankPendingRow>
     */
    public function listDue(): array
    {
        global $wpdb;

        /** @var list<RankPendingRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE status = 'pending' AND due_at <= %s
              ORDER BY due_at ASC
              LIMIT %d",
            gmdate('Y-m-d H:i:s'),
            self::SWEEP_BATCH
        ));

        return $rows ?: [];
    }

    /**
     * Resolve a pending row. Guarded on status='pending' so concurrent
     * sweep ticks settle exactly once.
     *
     * @param 'confirmed'|'voided' $status
     */
    public function resolve(int $userId, string $status, ?string $voidedReason = null): bool
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
                SET status = %s,
                    voided_reason = " . ($voidedReason !== null ? '%s' : 'NULL') . ",
                    resolved_at = %s
              WHERE user_id = %d AND status = 'pending'",
            ...($voidedReason !== null
                ? [$status, substr($voidedReason, 0, 120), current_time('mysql', true), $userId]
                : [$status, current_time('mysql', true), $userId])
        ));

        return $result !== false && (int) $result > 0;
    }
}
