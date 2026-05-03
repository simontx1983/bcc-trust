<?php
/**
 * Content Report Repository — read/write access to bcc_content_reports.
 *
 * Phase B: writes a single report row per (reporter, target) pair.
 * Phase C will add reads for the admin queue + threshold-counting.
 *
 * Schema column ref (see schema-content-reports.php):
 *   id, target_kind, target_id, reporter_user_id, reason_code,
 *   comment, status, resolved_by, resolved_at, created_at
 *
 * @package BCC\Trust\Core\Repositories
 * @since V1 (2026-04, §K1 Phase B)
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class ContentReportRepository
{
    /**
     * Insert a new report row. Returns the inserted id, or 0 when the
     * UNIQUE constraint blocked a duplicate (reporter already filed a
     * report for this target).
     *
     * Idempotency: the unique key on (reporter, target_kind, target_id)
     * is what enforces "one report per pair." Callers don't need to
     * SELECT first — the INSERT itself is the check.
     */
    public function create(
        int $reporterUserId,
        string $targetKind,
        int $targetId,
        string $reasonCode,
        string $comment
    ): int {
        if ($reporterUserId <= 0 || $targetId <= 0 || $targetKind === '' || $reasonCode === '') {
            return 0;
        }

        global $wpdb;
        $table = TableRegistry::contentReports();

        $inserted = $wpdb->insert(
            $table,
            [
                'target_kind'      => $targetKind,
                'target_id'        => $targetId,
                'reporter_user_id' => $reporterUserId,
                'reason_code'      => $reasonCode,
                // wp's $wpdb->insert handles null fine via the explicit
                // null-typed value when comment is empty, but storing
                // empty string keeps the comment column simpler to read.
                'comment'          => $comment !== '' ? $comment : null,
                'status'           => 0,
                'created_at'       => current_time('mysql'),
            ],
            ['%s', '%d', '%d', '%s', '%s', '%d', '%s']
        );

        if ($inserted === false) {
            // Most-likely cause: UNIQUE constraint violation. Treat as
            // no-op (idempotent retry) rather than surfacing as error.
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Has this reporter already filed against this target?
     */
    public function exists(int $reporterUserId, string $targetKind, int $targetId): bool
    {
        if ($reporterUserId <= 0 || $targetId <= 0 || $targetKind === '') {
            return false;
        }
        global $wpdb;
        $table = TableRegistry::contentReports();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE reporter_user_id = %d
                AND target_kind      = %s
                AND target_id        = %d
              LIMIT 1",
            $reporterUserId,
            $targetKind,
            $targetId
        ));
        return $count > 0;
    }

    /**
     * Single-row read by id — used by the admin resolve endpoint.
     *
     * @return object{
     *   id: int|numeric-string,
     *   target_kind: string,
     *   target_id: int|numeric-string,
     *   reporter_user_id: int|numeric-string,
     *   reason_code: string,
     *   comment: string|null,
     *   status: int|numeric-string,
     *   resolved_by: int|numeric-string|null,
     *   resolved_at: string|null,
     *   created_at: string
     * }|null
     */
    public function findById(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $table = TableRegistry::contentReports();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, target_kind, target_id, reporter_user_id, reason_code,
                    comment, status, resolved_by, resolved_at, created_at
               FROM {$table}
              WHERE id = %d
              LIMIT 1",
            $id
        ));
        if (!is_object($row)) {
            return null;
        }
        /** @var object{
         *   id: int|numeric-string,
         *   target_kind: string,
         *   target_id: int|numeric-string,
         *   reporter_user_id: int|numeric-string,
         *   reason_code: string,
         *   comment: string|null,
         *   status: int|numeric-string,
         *   resolved_by: int|numeric-string|null,
         *   resolved_at: string|null,
         *   created_at: string
         * } $row
         */
        return $row;
    }

    /**
     * Paginated admin queue read. `$status` filters: 0 = pending,
     * 1 = resolved, 2 = dismissed; null = all. Newest first.
     *
     * @return list<object{
     *   id: int|numeric-string,
     *   target_kind: string,
     *   target_id: int|numeric-string,
     *   reporter_user_id: int|numeric-string,
     *   reason_code: string,
     *   comment: string|null,
     *   status: int|numeric-string,
     *   created_at: string
     * }>
     */
    public function findForAdmin(?int $status, int $limit, int $offset): array
    {
        global $wpdb;
        $table = TableRegistry::contentReports();

        $where  = '';
        $params = [];
        if ($status !== null) {
            $where    = 'WHERE status = %d';
            $params[] = $status;
        }
        $params[] = $limit;
        $params[] = $offset;

        $sql = "SELECT id, target_kind, target_id, reporter_user_id, reason_code,
                       comment, status, created_at
                  FROM {$table}
                  {$where}
                 ORDER BY created_at DESC, id DESC
                 LIMIT %d OFFSET %d";

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        /** @var list<object{
         *   id: int|numeric-string,
         *   target_kind: string,
         *   target_id: int|numeric-string,
         *   reporter_user_id: int|numeric-string,
         *   reason_code: string,
         *   comment: string|null,
         *   status: int|numeric-string,
         *   created_at: string
         * }> $rows
         */
        return $rows ?: [];
    }

    /**
     * Total count for the admin queue's pagination block.
     */
    public function countForAdmin(?int $status): int
    {
        global $wpdb;
        $table = TableRegistry::contentReports();

        if ($status === null) {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE status = %d",
            $status
        ));
    }

    /**
     * Update a report's status + record who resolved it. Returns true
     * when the row existed and was updated.
     */
    public function updateStatus(int $id, int $status, int $resolvedByUserId): bool
    {
        if ($id <= 0) {
            return false;
        }
        global $wpdb;
        $table = TableRegistry::contentReports();

        $updated = $wpdb->update(
            $table,
            [
                'status'      => $status,
                'resolved_by' => $resolvedByUserId > 0 ? $resolvedByUserId : null,
                'resolved_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%d', '%d', '%s'],
            ['%d']
        );

        return $updated !== false && $updated > 0;
    }

    /**
     * Count of distinct reporters who've filed against a target with
     * status=0 (pending). Drives Phase C's auto-hide threshold.
     */
    public function countPendingForTarget(string $targetKind, int $targetId): int
    {
        if ($targetId <= 0 || $targetKind === '') {
            return 0;
        }
        global $wpdb;
        $table = TableRegistry::contentReports();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE target_kind = %s
                AND target_id   = %d
                AND status      = 0",
            $targetKind,
            $targetId
        ));
    }
}
