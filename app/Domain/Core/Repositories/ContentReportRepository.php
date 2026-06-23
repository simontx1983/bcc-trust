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
     * Count UPHELD (status = 1, resolved) reports filed by a user since a
     * MySQL DATETIME boundary. A contribution-recovery signal ("reporting
     * scams"). Only upheld reports count — dismissed (status = 2) and
     * still-pending (status = 0) reports earn nothing, so spam/false
     * reporting can't farm recovery. Aggregate COUNT — bounded.
     */
    public function countUpheldByReporterSince(int $reporterUserId, string $sinceMysql): int
    {
        if ($reporterUserId <= 0 || $sinceMysql === '') {
            return 0;
        }
        global $wpdb;
        $table = TableRegistry::contentReports();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE reporter_user_id = %d
                AND status           = 1
                AND created_at      >= %s",
            $reporterUserId,
            $sinceMysql
        ));
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
     * Hard ceiling on the reporter_user_ids IN-clause. Mirrors the
     * `number => 50` used by the service when resolving a handle to user
     * ids. Repo refuses to run the query past this ceiling so any
     * accidental unbounded array surfaces immediately rather than
     * generating a runaway IN-list.
     */
    public const REPORTER_IDS_MAX = 50;

    /**
     * Allowed post_kind filter values. Mirrors peepso_activities.act_module_id
     * strings used by the V1 admin UI.
     *
     * @var list<string>
     */
    private const ALLOWED_POST_KINDS = ['status', 'blog', 'review', 'photo', 'gif'];

    /**
     * Allowed reason_code filter values. Mirrors the enum the report
     * endpoint already accepts.
     *
     * @var list<string>
     */
    private const ALLOWED_REASON_CODES = ['spam', 'harassment', 'hate', 'violence', 'misinformation', 'other'];

    /**
     * Paginated admin queue read. `$status` filters: 0 = pending,
     * 1 = resolved, 2 = dismissed; null = all. Newest first.
     *
     * `$filters` is an optional cleaned filter bag (the service is the
     * only producer of this bag — it has already validated the inputs
     * and resolved any reporter handle to a list of user ids):
     *
     *   - reason            string|null   one of ALLOWED_REASON_CODES
     *   - reporter_user_ids list<int>|null bounded at REPORTER_IDS_MAX
     *   - post_kind         string|null   one of ALLOWED_POST_KINDS
     *   - since_mysql       string|null   'Y-m-d H:i:s' UTC
     *   - until_mysql       string|null   'Y-m-d H:i:s' UTC
     *
     * Unknown filter values are ignored defensively (the service is
     * still the canonical validator).
     *
     * @param array{
     *   reason?: string|null,
     *   reporter_user_ids?: list<int>|null,
     *   post_kind?: string|null,
     *   since_mysql?: string|null,
     *   until_mysql?: string|null
     * }|null $filters
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
    public function findForAdmin(?int $status, int $limit, int $offset, ?array $filters = null): array
    {
        global $wpdb;
        $table = TableRegistry::contentReports();

        [$conditions, $params, $joinSql] = self::buildAdminFilterClauses($status, $filters);

        $params[] = $limit;
        $params[] = $offset;

        $whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        $sql = "SELECT r.id, r.target_kind, r.target_id, r.reporter_user_id, r.reason_code,
                       r.comment, r.status, r.created_at
                  FROM {$table} r
                  {$joinSql}
                  {$whereSql}
                 ORDER BY r.created_at DESC, r.id DESC
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
     * Total count for the admin queue's pagination block. Same filter
     * semantics as findForAdmin().
     *
     * @param array{
     *   reason?: string|null,
     *   reporter_user_ids?: list<int>|null,
     *   post_kind?: string|null,
     *   since_mysql?: string|null,
     *   until_mysql?: string|null
     * }|null $filters
     */
    public function countForAdmin(?int $status, ?array $filters = null): int
    {
        global $wpdb;
        $table = TableRegistry::contentReports();

        [$conditions, $params, $joinSql] = self::buildAdminFilterClauses($status, $filters);

        // Fast-path: no filters at all. Avoids prepare() with zero args
        // (which $wpdb tolerates but warns about in some configurations).
        if ($status === null && $conditions === [] && $joinSql === '') {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        }

        $whereSql = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);
        $sql = "SELECT COUNT(*) FROM {$table} r {$joinSql} {$whereSql}";

        if ($params === []) {
            return (int) $wpdb->get_var($sql);
        }
        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    /**
     * Build the WHERE/JOIN/params triple shared by findForAdmin and
     * countForAdmin. Keeps the two queries in lockstep — any future
     * filter is wired in one place.
     *
     * Returns: [conditions, params, joinSql]
     *
     * @param array{
     *   reason?: string|null,
     *   reporter_user_ids?: list<int>|null,
     *   post_kind?: string|null,
     *   since_mysql?: string|null,
     *   until_mysql?: string|null
     * }|null $filters
     * @return array{0: list<string>, 1: list<int|string>, 2: string}
     */
    private static function buildAdminFilterClauses(?int $status, ?array $filters): array
    {
        /** @var list<string> $conditions */
        $conditions = [];
        /** @var list<int|string> $params */
        $params     = [];
        $joinSql    = '';

        if ($status !== null) {
            $conditions[] = 'r.status = %d';
            $params[]     = $status;
        }

        if ($filters === null) {
            return [$conditions, $params, $joinSql];
        }

        $reason = isset($filters['reason']) && is_string($filters['reason']) ? $filters['reason'] : null;
        if ($reason !== null && in_array($reason, self::ALLOWED_REASON_CODES, true)) {
            $conditions[] = 'r.reason_code = %s';
            $params[]     = $reason;
        }

        $reporterIds = $filters['reporter_user_ids'] ?? null;
        if (is_array($reporterIds) && $reporterIds !== []) {
            $count = count($reporterIds);
            if ($count > self::REPORTER_IDS_MAX) {
                // Defense-in-depth: the service is supposed to cap at 50
                // (matches the get_users number). If it didn't, error
                // early rather than fan out a runaway IN-list.
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::warning(
                        '[ContentReportRepository] reporter_user_ids exceeds cap; truncating',
                        ['count' => $count, 'cap' => self::REPORTER_IDS_MAX]
                    );
                }
                $reporterIds = array_slice($reporterIds, 0, self::REPORTER_IDS_MAX);
            }
            $placeholders = implode(',', array_fill(0, count($reporterIds), '%d'));
            $conditions[] = "r.reporter_user_id IN ({$placeholders})";
            foreach ($reporterIds as $rid) {
                $params[] = (int) $rid;
            }
        }

        $postKind = isset($filters['post_kind']) && is_string($filters['post_kind']) ? $filters['post_kind'] : null;
        if ($postKind !== null && in_array($postKind, self::ALLOWED_POST_KINDS, true)) {
            global $wpdb;
            $activities = $wpdb->prefix . 'peepso_activities';
            // LEFT JOIN by design: rows whose target_kind is not
            // feed_item have no matching pa row, but we still want the
            // act_module_id filter below to exclude them. NULL never
            // equals a literal, so the WHERE silently drops non-matches
            // (deleted activities, non-feed_item kinds, mismatched module).
            $joinSql .= " LEFT JOIN {$activities} pa
                            ON pa.act_id = r.target_id
                           AND r.target_kind = 'feed_item' ";
            $conditions[] = 'pa.act_module_id = %s';
            $params[]     = $postKind;
        }

        $sinceMysql = isset($filters['since_mysql']) && is_string($filters['since_mysql']) ? $filters['since_mysql'] : null;
        if ($sinceMysql !== null && $sinceMysql !== '') {
            $conditions[] = 'r.created_at >= %s';
            $params[]     = $sinceMysql;
        }

        $untilMysql = isset($filters['until_mysql']) && is_string($filters['until_mysql']) ? $filters['until_mysql'] : null;
        if ($untilMysql !== null && $untilMysql !== '') {
            $conditions[] = 'r.created_at <= %s';
            $params[]     = $untilMysql;
        }

        return [$conditions, $params, $joinSql];
    }

    /**
     * Update a report's status + record who resolved it. Returns true
     * when the row existed and was updated.
     *
     * Do NOT call this with `$status = STATUS_PENDING` to "un-resolve"
     * a report — that would stamp `resolved_at` with the un-resolution
     * time, which is semantically wrong. Use `setPending()` instead.
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
     * Flip a report back to PENDING and clear the resolution columns.
     *
     * The bounded recovery path (§K1 admin-queue undo affordance —
     * see pattern-registry.md "Moderation recovery affordances")
     * uses this to return a report to the queue after a moderator
     * reverses a hide/dismiss/restore within the 30-second window.
     *
     * `resolved_at` and `resolved_by` are explicitly set to NULL so a
     * future audit / list query reading those columns sees an un-acted
     * report rather than a misleading "resolved at T-5s by admin A"
     * stamp. The audit trail of the round-trip lives in
     * `bcc_trust_audit_log` (`admin_undo_*`), not in column drift.
     */
    public function setPending(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }
        global $wpdb;
        $table = TableRegistry::contentReports();

        $updated = $wpdb->update(
            $table,
            [
                'status'      => 0,    // STATUS_PENDING — kept literal to avoid
                'resolved_by' => null, // a cross-namespace const dep here.
                'resolved_at' => null,
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

    /**
     * Retention sweep: hard-delete CLOSED reports (resolved or dismissed)
     * whose resolution is older than the configured horizon, in bounded
     * batches. PENDING reports (status = 0) are NEVER deleted — the admin
     * queue still needs them — and the delete keys on resolved_at, so a
     * row is only eligible once it has actually been acted on.
     *
     * Mirrors VoteRepository::cleanupOldDeleted (batched, capped, fail-loud).
     * resolved_at is written in site time (current_time('mysql')); NOW() is
     * close enough at a 90-day horizon (a sub-day tz skew is immaterial and
     * never risks deleting an un-resolved row — the status guard does that).
     *
     * @return int rows deleted
     */
    public function cleanupResolved(): int
    {
        global $wpdb;
        $table = TableRegistry::contentReports();

        $days = defined('BCC_TRUST_CLEANUP_CONTENT_REPORTS')
            ? max(1, (int) BCC_TRUST_CLEANUP_CONTENT_REPORTS)
            : 90;

        $batchSize     = 5000;
        $maxIterations = 20;
        $total         = 0;

        for ($i = 0; $i < $maxIterations; $i++) {
            $affected = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table}
                  WHERE status      <> 0
                    AND resolved_at IS NOT NULL
                    AND resolved_at < DATE_SUB(NOW(), INTERVAL %d DAY)
                  LIMIT %d",
                $days,
                $batchSize
            ));

            if ($affected === false) {
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] content_reports cleanup DB error', [
                        'iteration' => $i,
                        'total'     => $total,
                        'db_error'  => $wpdb->last_error,
                    ]);
                }
                break;
            }

            $total += (int) $affected;

            if ((int) $affected < $batchSize) {
                break;
            }
        }

        return $total;
    }
}
