<?php
/**
 * Rank Pending Repository — owns `wp_bcc_trust_rank_pending`, the
 * 24-hour Apprentice confirmation clock (Rank Phase 5, R1).
 *
 * One row per New Member; INSERT IGNORE on PK(user_id) means the FIRST
 * qualifying contribution starts the clock and later ones are no-ops.
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
