<?php
/**
 * Findings Repository — owns `wp_bcc_trust_findings`, the formal
 * misconduct finding store (Rank Phase 8; spec §15).
 *
 * §15.1: rows are created ONLY through FindingsService::issue (wp-admin
 * issuance) — no automated writer exists. Penalty / ceiling values are
 * snapshots taken at issuance; this repository never re-reads config.
 *
 * Reads are bounded; expiry (`penalty_expires_at` / `ceiling_expires_at`)
 * is filtered by CALLERS against gmdate('Y-m-d H:i:s') in PHP so the
 * active-set read stays index-friendly on (subject_user_id, status) and
 * expired-but-active rows remain visible to the own-view findings list.
 *
 * requestAppeal is the once-only §15.5 appeal gate: a single UPDATE
 * guarded on appeal_status='' AND subject ownership — idempotent and
 * race-safe (rows-affected == 1 exactly once).
 *
 * @package BCC\Trust\Rank\Repositories
 * @since Rank redesign Phase 8 (2026-08-04)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type FindingRow object{
 *     id: numeric-string,
 *     subject_user_id: numeric-string,
 *     finding_type: string,
 *     severity: string,
 *     evidence_refs: string|null,
 *     source: string,
 *     issued_by: numeric-string,
 *     created_at: string,
 *     effective_at: string,
 *     score_penalty: numeric-string,
 *     ceiling_rank_slug: string|null,
 *     ceiling_expires_at: string|null,
 *     penalty_expires_at: string,
 *     restriction_note: string|null,
 *     status: string,
 *     appeal_status: string,
 *     reversed_at: string|null,
 *     reversal_reason: string|null
 * }
 */
class FindingsRepository
{
    /** Cache group for per-subject findings generation counters (§5). */
    private const CACHE_GROUP = 'bcc_rank_findings';

    /** Formal findings are rare by construction; hard per-subject read ceiling. */
    private const MAX_ROWS_PER_SUBJECT = 100;

    private const COLUMNS = 'id, subject_user_id, finding_type, severity, evidence_refs, source, issued_by, created_at, effective_at, score_penalty, ceiling_rank_slug, ceiling_expires_at, penalty_expires_at, restriction_note, status, appeal_status, reversed_at, reversal_reason';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::findings();
    }

    /**
     * Insert one finding (values already snapshot by FindingsService).
     *
     * @param array{
     *     subject_user_id: int,
     *     finding_type: string,
     *     severity: string,
     *     evidence_refs: string|null,
     *     source: string,
     *     issued_by: int,
     *     created_at: string,
     *     effective_at: string,
     *     score_penalty: float,
     *     ceiling_rank_slug: string|null,
     *     ceiling_expires_at: string|null,
     *     penalty_expires_at: string,
     *     restriction_note: string|null
     * } $data
     * @return int Finding id, or 0 on failure.
     */
    public function create(array $data): int
    {
        global $wpdb;

        $row = [
            'subject_user_id'    => $data['subject_user_id'],
            'finding_type'       => $data['finding_type'],
            'severity'           => $data['severity'],
            'evidence_refs'      => $data['evidence_refs'],
            'source'             => $data['source'],
            'issued_by'          => $data['issued_by'],
            'created_at'         => $data['created_at'],
            'effective_at'       => $data['effective_at'],
            'score_penalty'      => $data['score_penalty'],
            'ceiling_rank_slug'  => $data['ceiling_rank_slug'],
            'ceiling_expires_at' => $data['ceiling_expires_at'],
            'penalty_expires_at' => $data['penalty_expires_at'],
            'restriction_note'   => $data['restriction_note'],
            'status'             => 'active',
            'appeal_status'      => '',
        ];
        $formats = ['%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s'];

        $result = $wpdb->insert($this->table, $row, $formats);
        if ($result === false) {
            return 0;
        }

        $this->invalidateCache($data['subject_user_id']);

        return (int) $wpdb->insert_id;
    }

    /**
     * @phpstan-return FindingRow|null
     */
    public function getById(int $findingId): ?object
    {
        if ($findingId <= 0) {
            return null;
        }

        global $wpdb;

        /** @var FindingRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE id = %d",
            $findingId
        ));

        return $row;
    }

    /**
     * All ACTIVE (non-reversed) findings for one subject, bounded.
     * Includes rows whose penalty/ceiling has expired — callers filter
     * expiry against gmdate('Y-m-d H:i:s') in PHP (UTC).
     *
     * @return list<object>
     * @phpstan-return list<FindingRow>
     */
    public function getActiveForUser(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        global $wpdb;

        /** @var list<FindingRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE subject_user_id = %d AND status = 'active'
              ORDER BY id ASC
              LIMIT %d",
            $userId,
            self::MAX_ROWS_PER_SUBJECT
        ));

        return $rows ?: [];
    }

    /**
     * Reversed findings for one subject whose reversal happened at or
     * after $since (MySQL UTC datetime) — the own-view "recently
     * reversed" progression read.
     *
     * @return list<object>
     * @phpstan-return list<FindingRow>
     */
    public function getRecentlyReversedForUser(int $userId, string $since): array
    {
        if ($userId <= 0) {
            return [];
        }

        global $wpdb;

        /** @var list<FindingRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE subject_user_id = %d AND status = 'reversed' AND reversed_at >= %s
              ORDER BY id ASC
              LIMIT %d",
            $userId,
            $since,
            self::MAX_ROWS_PER_SUBJECT
        ));

        return $rows ?: [];
    }

    /**
     * Paginated admin list with optional status / appeal-status filters.
     *
     * @return list<object>
     * @phpstan-return list<FindingRow>
     */
    public function listForAdmin(?string $status, ?string $appealStatus, int $limit, int $offset): array
    {
        global $wpdb;

        [$where, $params] = $this->adminFilterClause($status, $appealStatus);
        $params[] = max(1, min(100, $limit));
        $params[] = max(0, $offset);

        /** @var list<FindingRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              {$where}
              ORDER BY id DESC
              LIMIT %d OFFSET %d",
            ...$params
        ));

        return $rows ?: [];
    }

    /** Row count matching the admin filters (pagination companion). */
    public function countForAdmin(?string $status, ?string $appealStatus): int
    {
        global $wpdb;

        [$where, $params] = $this->adminFilterClause($status, $appealStatus);

        if ($params === []) {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
        }

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} {$where}",
            ...$params
        ));
    }

    /**
     * The once-only §15.5 member appeal gate. A single guarded UPDATE:
     * only the finding's own subject, and only while appeal_status is
     * still '' (never requested, never adjudicated). Exactly one caller
     * ever sees true — replays and races see rows-affected 0.
     */
    public function requestAppeal(int $findingId, int $subjectUserId): bool
    {
        if ($findingId <= 0 || $subjectUserId <= 0) {
            return false;
        }

        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
                SET appeal_status = 'requested'
              WHERE id = %d AND subject_user_id = %d AND appeal_status = ''",
            $findingId,
            $subjectUserId
        ));

        $updated = $result !== false && (int) $result > 0;
        if ($updated) {
            $this->invalidateCache($subjectUserId);
        }
        return $updated;
    }

    /**
     * Persist a reconsideration outcome onto the finding row. The
     * append-only decision history row is written separately by
     * FindingDecisionsRepository — this only moves the CURRENT state
     * (§15.5: the original snapshot lives in the 'issued' decision row).
     *
     * Reductions and reversals are ATOMIC status claims: the UPDATE is
     * guarded on status='active' and requires rows-affected > 0, so two
     * concurrent admins cannot both reverse (or reduce) one finding.
     *
     * @param string      $appealStatus 'upheld'|'reduced'|'reversed'|'remanded'
     * @param float|null  $newPenalty   Only for 'reduced'.
     * @param bool        $reverse      True flips status to 'reversed' + stamps reversed_at.
     * @param string|null $reversalReason Required when $reverse.
     */
    public function applyReconsideration(
        int $findingId,
        int $subjectUserId,
        string $appealStatus,
        ?float $newPenalty,
        bool $reverse,
        ?string $reversalReason
    ): bool {
        if ($findingId <= 0) {
            return false;
        }

        global $wpdb;

        $sets   = ['appeal_status = %s'];
        $params = [$appealStatus];

        if ($newPenalty !== null) {
            $sets[]   = 'score_penalty = %f';
            $params[] = $newPenalty;
        }
        if ($reverse) {
            $sets[]   = "status = 'reversed'";
            $sets[]   = 'reversed_at = %s';
            $params[] = gmdate('Y-m-d H:i:s');
            $sets[]   = 'reversal_reason = %s';
            $params[] = substr((string) $reversalReason, 0, 160);
        }

        $mutating = $reverse || $newPenalty !== null;

        $params[] = $findingId;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET " . implode(', ', $sets)
                . ' WHERE id = %d'
                . ($mutating ? " AND status = 'active'" : ''),
            ...$params
        ));

        if ($result === false) {
            return false;
        }
        if ($mutating && (int) $result < 1) {
            return false; // lost the atomic claim (already reversed/reduced)
        }

        $this->invalidateCache($subjectUserId);
        return true;
    }

    /**
     * @return array{0: string, 1: list<string|int>} WHERE clause + params.
     */
    private function adminFilterClause(?string $status, ?string $appealStatus): array
    {
        $clauses = [];
        $params  = [];
        if ($status !== null && $status !== '') {
            $clauses[] = 'status = %s';
            $params[]  = $status;
        }
        if ($appealStatus !== null && $appealStatus !== '') {
            $clauses[] = 'appeal_status = %s';
            $params[]  = $appealStatus;
        }
        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
        return [$where, $params];
    }

    /** §5 generation-counter bump for per-subject findings read caches. */
    private function invalidateCache(int $userId): void
    {
        $genKey = "findings_gen:{$userId}";
        $result = wp_cache_incr($genKey, 1, self::CACHE_GROUP);
        if ($result === false) {
            wp_cache_set($genKey, 2, self::CACHE_GROUP, 0);
        }
    }
}
