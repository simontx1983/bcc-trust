<?php

namespace BCC\Trust\Disputes\Repositories;

use BCC\Core\DB\DB;
use BCC\Core\DTO\RowAssert;
use BCC\Trust\Disputes\Domain\ReportStatus;
use BCC\Trust\Disputes\DTO\AdminReportRowDTO;
use BCC\Trust\Disputes\DTO\UserReportDTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Every query against the user-reports table (bcc_user_reports):
 * report creation + abuse ceilings, admin list/count shapes, status
 * transitions, and the reported-user / admin email idempotency claims.
 *
 * Extracted verbatim from DisputeRepository (Phase 3.1 split). Distinct
 * from Core's ContentReportRepository (content reports) — this table
 * stores user-against-user reports. Schema creation remains in
 * DisputeRepository::install() (single activation entry point).
 */
class UserReportRepository
{
    /** Cache group for all dispute-related keys. */
    private const CACHE_GROUP = DisputeRepositorySupport::CACHE_GROUP;

    /** TTL for data that changes frequently (counts, active queues). */
    private const TTL_HOT = DisputeRepositorySupport::TTL_HOT;

    /** User reports table columns. */
    private const REPORT_COLUMNS = 'id, reported_id, reporter_id, reason_key, reason_detail, status, created_at, reviewed_at';

    public static function user_reports_table(): string
    {
        return DB::table('user_reports');
    }

    /**
     * Check whether an active (open) report already exists from reporter to reported user.
     */
    public static function hasActiveReport(int $reporterId, int $reportedId): bool
    {
        global $wpdb;
        $table = self::user_reports_table();

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE reporter_id = %d AND reported_id = %d AND status IN ('open', 'reviewing')
             LIMIT 1",
            $reporterId, $reportedId
        ));

        return (bool) $existing;
    }

    /**
     * Count reports submitted by a user within the last 24 hours.
     */
    public static function countRecentReportsByReporter(int $reporterId): int
    {
        global $wpdb;
        $table = self::user_reports_table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE reporter_id = %d
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)",
            $reporterId
        ));
    }

    /**
     * Insert a user report row.
     *
     * @return int|null  The new report ID, or null on failure.
     */
    public static function createReport(int $reportedId, int $reporterId, string $reasonKey, string $reasonDetail): ?int
    {
        global $wpdb;
        $table = self::user_reports_table();

        if (!DisputeRepositorySupport::beginTx()) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] START TRANSACTION failed in createReport', [
                'db_error' => (string) $wpdb->last_error,
            ]);
            return null;
        }

        // Atomic daily limit + duplicate check under FOR UPDATE lock.
        $recentCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE reporter_id = %d
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)
             FOR UPDATE",
            $reporterId
        ));
        if ($recentCount >= 5) {
            DisputeRepositorySupport::rollbackTx('createReport:daily_limit');
            return null;
        }

        $hasDupe = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE reporter_id = %d AND reported_id = %d AND status IN ('open', 'reviewing')
             FOR UPDATE
             LIMIT 1",
            $reporterId, $reportedId
        ));
        if ($hasDupe) {
            DisputeRepositorySupport::rollbackTx('createReport:duplicate');
            return null;
        }

        // Enforce the per-target ceiling atomically inside the transaction.
        // The controller-level check is pre-transaction and subject to TOCTOU.
        // This FOR UPDATE lock serialises concurrent reporters targeting the
        // same user, preventing coordinated ceiling bypass.
        $targetOpenCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE reported_id = %d AND status = 'open'
             FOR UPDATE",
            $reportedId
        ));
        if ($targetOpenCount >= 10) {
            DisputeRepositorySupport::rollbackTx('createReport:target_ceiling');
            return null;
        }

        $wpdb->insert($table, [
            'reported_id'   => $reportedId,
            'reporter_id'   => $reporterId,
            'reason_key'    => $reasonKey,
            'reason_detail' => $reasonDetail,
            'status'        => 'open',
        ], ['%d', '%d', '%s', '%s', '%s']);

        $id = (int) $wpdb->insert_id;
        if (!$id) {
            DisputeRepositorySupport::rollbackTx('createReport:insert_failed');
            return null;
        }

        if (!DisputeRepositorySupport::commitTx('createReport')) {
            // COMMIT failed → insert was rolled back by MySQL.  Return null
            // so the caller treats this as a write failure and the user can retry.
            return null;
        }

        wp_cache_delete('report_status_counts', self::CACHE_GROUP);

        return $id;
    }

    // ── Admin query methods ──────────────────────────────────────────────────

    /**
     * Get report counts grouped by status.
     *
     * @return array<string, int>
     */
    public static function getReportStatusCounts(): array
    {
        $cacheKey = 'report_status_counts';
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = self::user_reports_table();

        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status LIMIT 10"
        );

        $counts = [];
        foreach ($rows as $r) {
            $counts[$r->status] = (int) $r->cnt;
        }

        wp_cache_set($cacheKey, $counts, self::CACHE_GROUP, self::TTL_HOT);
        return $counts;
    }

    /**
     * Count reports for admin list, optionally filtered by status.
     */
    public static function countReportsForAdminList(?string $status): int
    {
        global $wpdb;
        $table = self::user_reports_table();

        if ($status) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                $status
            ));
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Paginated report list for admin, with joined user display names.
     *
     * @return list<AdminReportRowDTO>
     */
    public static function getReportsForAdminList(?string $status, string $orderBy, string $order, int $limit, int $offset): array
    {
        global $wpdb;
        $table = self::user_reports_table();

        $allowed = ['id', 'status', 'created_at'];
        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'id';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $where  = '1=1';
        $params = [];

        if ($status) {
            $where   .= ' AND r.status = %s';
            $params[] = $status;
        }

        $sql = "SELECT r.id, r.reported_id, r.reporter_id, r.reason_key, r.reason_detail,
                       r.status, r.created_at, r.reviewed_at,
                       reported.display_name AS reported_name,
                       reporter.display_name AS reporter_name
                FROM {$table} r
                LEFT JOIN {$wpdb->users} reported ON r.reported_id = reported.ID
                LEFT JOIN {$wpdb->users} reporter ON r.reporter_id = reporter.ID
                WHERE {$where}
                ORDER BY r.{$orderBy} {$order}
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $dtos = [];
        foreach ($rows as $row) {
            $dtos[] = new AdminReportRowDTO(
                id:            RowAssert::requireDigitInt($row, 'id'),
                reported_id:   RowAssert::requireDigitInt($row, 'reported_id'),
                reporter_id:   RowAssert::requireDigitInt($row, 'reporter_id'),
                reason_key:    RowAssert::requireString($row, 'reason_key'),
                reason_detail: RowAssert::requireString($row, 'reason_detail'),
                status:        ReportStatus::assert(RowAssert::requireString($row, 'status')),
                created_at:    RowAssert::requireString($row, 'created_at'),
                reviewed_at:   RowAssert::optString($row, 'reviewed_at'),
                reported_name: RowAssert::optString($row, 'reported_name'),
                reporter_name: RowAssert::optString($row, 'reporter_name'),
            );
        }
        return $dtos;
    }

    /**
     * Count open reports against a single target user.
     * Used to cap coordinated report campaigns.
     */
    public static function countActiveReportsAgainst(int $reportedId): int
    {
        global $wpdb;
        $table = self::user_reports_table();
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE reported_id = %d AND status = 'open'",
            $reportedId
        ));
    }

    public static function getReportById(int $reportId): ?UserReportDTO
    {
        global $wpdb;
        $table = self::user_reports_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::REPORT_COLUMNS . " FROM {$table} WHERE id = %d LIMIT 1",
            $reportId
        ), ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        return new UserReportDTO(
            id:            RowAssert::requireDigitInt($row, 'id'),
            reported_id:   RowAssert::requireDigitInt($row, 'reported_id'),
            reporter_id:   RowAssert::requireDigitInt($row, 'reporter_id'),
            reason_key:    RowAssert::requireString($row, 'reason_key'),
            reason_detail: RowAssert::requireString($row, 'reason_detail'),
            status:        ReportStatus::assert(RowAssert::requireString($row, 'status')),
            created_at:    RowAssert::requireString($row, 'created_at'),
            reviewed_at:   RowAssert::optString($row, 'reviewed_at'),
        );
    }

    /**
     * Transition a report from 'open' to the given status.
     *
     * The WHERE clause includes `status = 'open'` to prevent invalid
     * state transitions (e.g., dismissed -> reviewed).
     *
     * @return bool True if exactly one row was updated, false otherwise.
     */
    public static function updateReportStatus(int $reportId, string $status): bool
    {
        global $wpdb;
        $table = self::user_reports_table();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, reviewed_at = %s WHERE id = %d AND status = 'open'",
            $status,
            gmdate('Y-m-d H:i:s'),
            $reportId
        ));

        if ($result !== false && $result > 0) {
            wp_cache_delete('report_status_counts', self::CACHE_GROUP);
        }

        return $result !== false && $result > 0;
    }

    // ── Notification idempotency ───────────────────────────────────────────

    /**
     * Atomically claim the right to send the reported-user email.
     * Claim half of claim-before-send; see markResolvedNotified().
     */
    public static function markReportNotified(int $reportId, string $ts): bool
    {
        global $wpdb;
        $table = self::user_reports_table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET notified_at = %s WHERE id = %d AND notified_at IS NULL",
            $ts,
            $reportId
        ));

        return $affected > 0;
    }

    /**
     * Release a tentative reported-user claim when the send failed.
     * Scoped by $ts so only our own claim is cleared.
     */
    public static function clearReportNotified(int $reportId, string $ts): bool
    {
        global $wpdb;
        $table = self::user_reports_table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET notified_at = NULL
             WHERE id = %d AND notified_at = %s",
            $reportId,
            $ts
        ));

        return $affected > 0;
    }

    /**
     * Atomically claim the right to send the admin-report email.
     * Claim half of claim-before-send; see markResolvedNotified().
     * Uses the dedicated admin_notified_at column so admin notifications
     * have their own idempotency slot separate from the user-facing
     * notified_at.
     */
    public static function markAdminReportNotified(int $reportId, string $ts): bool
    {
        global $wpdb;
        $table = self::user_reports_table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET admin_notified_at = %s WHERE id = %d AND admin_notified_at IS NULL",
            $ts,
            $reportId
        ));

        return $affected > 0;
    }

    /**
     * Release a tentative admin-report claim when the send failed.
     * Scoped by $ts so only our own claim is cleared.
     */
    public static function clearAdminReportNotified(int $reportId, string $ts): bool
    {
        global $wpdb;
        $table = self::user_reports_table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET admin_notified_at = NULL
             WHERE id = %d AND admin_notified_at = %s",
            $reportId,
            $ts
        ));

        return $affected > 0;
    }

    /**
     * Reset stuck reported-user notification claims
     * (user_reports.notified_at — the email sent to the user being
     * reported telling them a report was filed).
     *
     * Guard: report is still 'open'.  Reviewed/dismissed reports are
     * terminal and resetting their claim would email a user about a
     * report that is no longer actionable.
     *
     * @return int Number of stuck claims released (0 on driver error).
     */
    public static function resetStuckReportedUserClaims(string $cutoff): int
    {
        global $wpdb;
        $table = self::user_reports_table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET notified_at = NULL
             WHERE notified_at IS NOT NULL
               AND notified_at < %s
               AND status = 'open'",
            $cutoff
        ));

        return $affected === false ? 0 : (int) $affected;
    }

    /**
     * Reset stuck admin-report notification claims
     * (user_reports.admin_notified_at — the email sent to moderators
     * when a new report arrives).
     *
     * Guard: report is still 'open'.  Same reasoning as
     * resetStuckReportedUserClaims.
     *
     * @return int Number of stuck claims released (0 on driver error).
     */
    public static function resetStuckAdminReportClaims(string $cutoff): int
    {
        global $wpdb;
        $table = self::user_reports_table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET admin_notified_at = NULL
             WHERE admin_notified_at IS NOT NULL
               AND admin_notified_at < %s
               AND status = 'open'",
            $cutoff
        ));

        return $affected === false ? 0 : (int) $affected;
    }
}
