<?php

namespace BCC\Trust\Disputes\Repositories;

use BCC\Core\DB\DB;
use BCC\Core\DTO\RowAssert;
use BCC\Trust\Disputes\Domain\DisputeStatus;
use BCC\Trust\Disputes\Domain\PanelDecision;
use BCC\Trust\Disputes\Domain\ReportStatus;
use BCC\Trust\Disputes\DTO\AdminDisputeRowDTO;
use BCC\Trust\Disputes\DTO\AdminReportRowDTO;
use BCC\Trust\Disputes\DTO\DisputeCoreDTO;
use BCC\Trust\Disputes\DTO\DisputeDetailDTO;
use BCC\Trust\Disputes\DTO\DisputeResolutionCandidateDTO;
use BCC\Trust\Disputes\DTO\OrphanedDisputeDTO;
use BCC\Trust\Disputes\DTO\PanelEntryFullDTO;
use BCC\Trust\Disputes\DTO\PanelEntrySlimDTO;
use BCC\Trust\Disputes\DTO\PanelistQueueItemDTO;
use BCC\Trust\Disputes\DTO\UserReportDTO;

if (!defined('ABSPATH')) {
    exit;
}

class DisputeRepository
{
    /** Cache group for all dispute-related keys. */
    private const CACHE_GROUP = 'bcc_disputes';

    /** TTL for data that changes frequently (counts, active queues). */
    private const TTL_HOT  = 60;

    /** TTL for data that changes less often (individual dispute lookups). */
    private const TTL_WARM = 300;

    /** User reports table columns. */
    private const REPORT_COLUMNS = 'id, reported_id, reporter_id, reason_key, reason_detail, status, created_at, reviewed_at';

    public static function disputes_table(): string
    {
        return DB::table('disputes');
    }

    public static function panel_table(): string
    {
        return DB::table('dispute_panel');
    }

    public static function user_reports_table(): string
    {
        return DB::table('user_reports');
    }

    // ── Raw-transaction guards ──────────────────────────────────────────────
    //
    // Every raw `$wpdb->query('START TRANSACTION')` site MUST route through
    // these helpers.  `$wpdb->query()` returns false on driver error
    // (connection reset, managed-MySQL failover, implicit-commit at DDL),
    // and a silent false return leaves the session in autocommit — which
    // turns every FOR UPDATE into a plain read and every subsequent
    // ROLLBACK into a no-op.  The dispute-create path in particular relies
    // on gap locks for the per-page limit, per-reporter limit, and
    // duplicate-vote protection; losing the transaction silently bypasses
    // all three.
    //
    // These helpers are intentionally minimal — they log and surface
    // failure to the caller, leaving retry/return-shape decisions where
    // they belong (per-method).

    /**
     * Begin a transaction.  Returns false on driver error so the caller
     * can return a structured failure to its API layer instead of
     * proceeding under autocommit.
     */
    private static function beginTx(): bool
    {
        global $wpdb;
        return $wpdb->query('START TRANSACTION') !== false;
    }

    /**
     * Commit.  A false return means MySQL rolled the transaction back on
     * its own (commit-time deadlock, serialization failure, connection
     * drop).  Callers MUST NOT treat a failed COMMIT as a successful
     * write — the persisted row may have been auto-incremented and then
     * immediately rolled back, yielding a dead insert_id.
     */
    private static function commitTx(string $op): bool
    {
        global $wpdb;
        if ($wpdb->query('COMMIT') !== false) {
            return true;
        }
        \BCC\Core\Log\Logger::error('[bcc-disputes] COMMIT failed', [
            'op'       => $op,
            'db_error' => (string) $wpdb->last_error,
        ]);
        return false;
    }

    /**
     * Rollback.  Never throws — rollback always runs on the error path
     * and secondary failures there must not mask the primary cause.
     * Logs on driver error so the forensic trail survives.
     */
    private static function rollbackTx(string $op): void
    {
        global $wpdb;
        if ($wpdb->query('ROLLBACK') === false) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] ROLLBACK failed', [
                'op'       => $op,
                'db_error' => (string) $wpdb->last_error,
            ]);
        }
    }

    // ── Verdict calculation (shared, single source of truth) ──────────────

    /**
     * Determine whether a dispute should resolve and what the outcome is.
     *
     * Rules:
     * - Quorum: at least min(3, panel_size) votes must be cast.
     * - Majority: accepts or rejects must reach floor(panel_size/2)+1.
     * - If quorum is met and accepts >= majority → 'accepted'.
     * - Otherwise → 'rejected' (protects the original voter's decision).
     *
     * @return array{should_resolve: bool, outcome: string}
     */
    public static function computeVerdict(int $accepts, int $rejects, int $panelSize): array
    {
        $totalVoted = $accepts + $rejects;
        $majority   = (int) floor($panelSize / 2) + 1;
        $quorum     = self::quorumFor($panelSize);

        $shouldResolve = $totalVoted >= $quorum && ($accepts >= $majority || $rejects >= $majority);

        if ($totalVoted < $quorum) {
            // Quorum never reached. Used only at TTL (scheduler ignores
            // should_resolve). Keeps adjudicator uninvoked and suppresses
            // the reporter penalty — panelist silence must not be treated
            // as proof of a bad-faith report.
            $outcome = 'timeout_no_quorum';
        } elseif ($accepts >= $majority) {
            $outcome = 'accepted';
        } else {
            // Majority rejected (or tied with rejects reaching majority).
            // Genuine reject verdict — penalty fires in DisputeResolver.
            $outcome = 'rejected';
        }

        return ['should_resolve' => $shouldResolve, 'outcome' => $outcome];
    }

    /**
     * Quorum threshold used by computeVerdict(). Exposed as a helper so the
     * reporter-penalty gate in DisputeResolver cannot drift from the
     * verdict rule here.
     */
    public static function quorumFor(int $panelSize): int
    {
        return min(3, max(0, $panelSize));
    }

    /**
     * Re-derive whether quorum was actually reached for a dispute using the
     * authoritative panel rows (not the denormalised panel_accepts/rejects
     * columns, which can drift under panelist deletion). Used to gate the
     * reporter-penalty hook: panelist inactivity must never count as proof
     * of a fraudulent dispute.
     */
    public static function wasQuorumMetForDispute(int $disputeId): bool
    {
        global $wpdb;
        $disputeTable = self::disputes_table();
        $panelTable   = self::panel_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT d.panel_size,
                    COALESCE(SUM(CASE WHEN p.decision IN ('accept','reject') THEN 1 ELSE 0 END), 0) AS votes_cast
             FROM {$disputeTable} d
             LEFT JOIN {$panelTable} p ON p.dispute_id = d.id
             WHERE d.id = %d
             GROUP BY d.id, d.panel_size",
            $disputeId
        ), ARRAY_A);

        if (!is_array($row)) {
            // Dispute vanished — treat as no-quorum so the penalty is skipped
            // (fail-closed on the penalty side).
            return false;
        }

        $panelSize = (int) ($row['panel_size'] ?? 0);
        $votesCast = (int) ($row['votes_cast'] ?? 0);

        return $votesCast >= self::quorumFor($panelSize);
    }

    // ── Cache helpers ────────────────────────────────────────────────────────

    /**
     * Get the current generation counter for a cache namespace.
     *
     * Generation counters solve the wildcard-deletion problem: instead of
     * deleting panel_queue:{userId}:* (impossible with wp_cache), we
     * increment the generation and all old keys become unreachable,
     * expiring naturally via TTL.
     */
    private static function getGeneration(string $genKey): int
    {
        $gen = wp_cache_get($genKey, self::CACHE_GROUP);
        if ($gen === false) {
            $gen = 1;
            wp_cache_set($genKey, $gen, self::CACHE_GROUP, 0);
        }
        return (int) $gen;
    }

    /**
     * Atomically increment a generation counter, invalidating all keys that embed it.
     *
     * Uses wp_cache_incr() which maps to Redis INCR — a single atomic operation
     * when a persistent object cache is available. The fallback (no object cache)
     * uses set-if-missing which is not fully atomic but acceptable for cache
     * invalidation (worst case: one extra DB query).
     */
    private static function bumpGeneration(string $genKey): void
    {
        $result = wp_cache_incr($genKey, 1, self::CACHE_GROUP);
        if ($result === false) {
            // incr failed — key may not exist. Verify before overwriting.
            $current = wp_cache_get($genKey, self::CACHE_GROUP);
            if ($current === false) {
                // Key truly doesn't exist — set to 2 (first bump from implicit gen=1).
                wp_cache_set($genKey, 2, self::CACHE_GROUP, 0);
            } else {
                // Key exists but incr failed (transient backend issue) — force set incremented value.
                wp_cache_set($genKey, (int) $current + 1, self::CACHE_GROUP, 0);
            }
        }
    }

    // ── Advisory locks ────────────────────────────────────────────────────────

    /**
     * Acquire a MySQL advisory lock for auto-resolve cron.
     *
     * GET_LOCK returns 1 if acquired, 0 if already held by another connection.
     * Timeout of 0 means non-blocking (return immediately if unavailable).
     */
    public static function acquireAutoResolveLock(): bool
    {
        // Route through AdvisoryLock so NULL returns (driver/permission
        // errors) are distinguished from 0 (held elsewhere) — the prior
        // raw (int)-cast collapsed both to false, silently disabling the
        // auto-resolve cron whenever MySQL errored on GET_LOCK.
        if (class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            return \BCC\Core\DB\AdvisoryLock::acquire('bcc_disputes_auto_resolve', 0);
        }
        global $wpdb;
        return (int) $wpdb->get_var("SELECT GET_LOCK('bcc_disputes_auto_resolve', 0)") === 1;
    }

    /**
     * Release the auto-resolve advisory lock.
     */
    public static function releaseAutoResolveLock(): void
    {
        global $wpdb;
        $result = $wpdb->query("SELECT RELEASE_LOCK('bcc_disputes_auto_resolve')");
        if ($result === false && class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] Failed to release auto-resolve lock', [
                'db_error' => $wpdb->last_error,
            ]);
        }
    }

    /**
     * Acquire a MySQL advisory lock for the emergency-resolve code path
     * triggered from REST /disputes/panel when cron is stale.
     *
     * Non-blocking (timeout=0). Prevents a thundering herd of concurrent
     * authenticated requests all racing past get_transient() before the
     * first set_transient() lands — without this lock, N REST workers
     * all execute getExpiredDisputes() JOIN + enqueue loop before the
     * 10-minute transient backstop suppresses them.
     */
    public static function acquireEmergencyResolveLock(): bool
    {
        if (class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            return \BCC\Core\DB\AdvisoryLock::acquire('bcc_disputes_emergency_resolve', 0);
        }
        global $wpdb;
        return (int) $wpdb->get_var("SELECT GET_LOCK('bcc_disputes_emergency_resolve', 0)") === 1;
    }

    /**
     * Release the emergency-resolve advisory lock.
     */
    public static function releaseEmergencyResolveLock(): void
    {
        global $wpdb;
        $result = $wpdb->query("SELECT RELEASE_LOCK('bcc_disputes_emergency_resolve')");
        if ($result === false && class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] Failed to release emergency-resolve lock', [
                'db_error' => $wpdb->last_error,
            ]);
        }
    }

    /**
     * Acquire the reconciliation advisory lock (separate from auto-resolve
     * so the two cron events do not block each other).
     */
    public static function acquireReconcileLock(): bool
    {
        if (class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            return \BCC\Core\DB\AdvisoryLock::acquire('bcc_disputes_reconcile', 0);
        }
        global $wpdb;
        return (int) $wpdb->get_var("SELECT GET_LOCK('bcc_disputes_reconcile', 0)") === 1;
    }

    /**
     * Release the reconciliation advisory lock.
     */
    public static function releaseReconcileLock(): void
    {
        global $wpdb;
        $result = $wpdb->query("SELECT RELEASE_LOCK('bcc_disputes_reconcile')");
        if ($result === false && class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] Failed to release reconcile lock', [
                'db_error' => $wpdb->last_error,
            ]);
        }
    }

    // ── Health-check queries ────────────────────────────────────────────────

    /**
     * Count disputes that resolved >1 hour ago but still have pending/failed
     * adjudication.  Used by DisputeScheduler::checkAdjudicationHealth() to
     * detect prolonged trust-engine unavailability.
     */
    public static function countStaleAdjudications(): int
    {
        global $wpdb;
        $table = self::disputes_table();

        return (int) $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$table}
             WHERE status IN ('accepted', 'rejected')
               AND adjudication_status IN ('pending', 'failed')
               AND resolved_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)"
        );
    }

    // ── Query methods ────────────────────────────────────────────────────────

    /**
     * Check whether an active (reviewing) dispute already exists for a vote.
     */
    public static function hasActiveDisputeForVote(int $voteId): bool
    {
        global $wpdb;
        $table = self::disputes_table();

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE vote_id = %d AND status = 'reviewing' LIMIT 1",
            $voteId
        ));

        return (bool) $existing;
    }

    /**
     * Atomically create a dispute row and its panel assignments.
     *
     * @param array<string, mixed> $disputeData  Dispute column values.
     * @param int[]  $panelistIds  User IDs to assign as panelists.
     * @return array{id: ?int, failed_panelist: ?int, db_error: ?string}
     */
    public static function createDisputeWithPanel(array $disputeData, array $panelistIds): array
    {
        global $wpdb;
        $disputeTable = self::disputes_table();
        $panelTable   = self::panel_table();

        if (!self::beginTx()) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] START TRANSACTION failed in createDisputeWithPanel', [
                'db_error' => (string) $wpdb->last_error,
            ]);
            return ['id' => null, 'failed_panelist' => null, 'db_error' => 'tx_begin_failed'];
        }

        // Atomic dispute limit check: count recent disputes FOR UPDATE to
        // prevent race where two concurrent requests both read count=2.
        $pageId = (int) $disputeData['page_id'];
        $recentCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$disputeTable}
             WHERE page_id = %d
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             FOR UPDATE",
            $pageId
        ));
        if ($recentCount >= BCC_DISPUTES_MAX_PER_PAGE) {
            self::rollbackTx('createDisputeWithPanel:dispute_limit_reached');
            return ['id' => null, 'failed_panelist' => null, 'db_error' => 'dispute_limit_reached'];
        }

        // Per-reporter global limit: max active disputes at any time.
        // Prevents panelist pool exhaustion by users with many pages.
        $reporterId = (int) $disputeData['reporter_id'];
        $activeReporterCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$disputeTable}
             WHERE reporter_id = %d AND status = 'reviewing'
             FOR UPDATE",
            $reporterId
        ));
        if ($activeReporterCount >= BCC_DISPUTES_REPORTER_MAX_ACTIVE) {
            self::rollbackTx('createDisputeWithPanel:reporter_limit_reached');
            return ['id' => null, 'failed_panelist' => null, 'db_error' => 'reporter_limit_reached'];
        }

        // Atomic duplicate check: verify no active dispute exists for this
        // vote_id while holding a row-level lock. FOR UPDATE ensures that a
        // concurrent transaction inserting for the same vote_id will block
        // until this transaction commits or rolls back, preventing duplicate
        // disputes from being created via race condition.
        $voteId = (int) $disputeData['vote_id'];
        $existingForVote = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$disputeTable}
             WHERE vote_id = %d AND status = 'reviewing'
             FOR UPDATE
             LIMIT 1",
            $voteId
        ));
        if ($existingForVote) {
            self::rollbackTx('createDisputeWithPanel:already_disputed');
            return ['id' => null, 'failed_panelist' => null, 'db_error' => 'already_disputed'];
        }

        // Verify the vote is still active via the TrustReadService contract.
        // Cross-plugin coupling MUST go through the interface — a direct query
        // against Trust\Database\TableRegistry::votes() would break silently
        // when the trust-engine schema changes. lockActiveVoteForDispute also
        // holds a FOR UPDATE lock on the vote row for the remainder of this
        // transaction, preventing the trust engine from soft-deleting the
        // vote underneath us. NullTrustReadService returns false here, so
        // disputes cannot be created while the trust engine is inactive
        // (fail-closed).
        $trustRead = \BCC\Core\ServiceLocator::resolveTrustReadService();
        if (!$trustRead->lockActiveVoteForDispute($voteId)) {
            self::rollbackTx('createDisputeWithPanel:vote_no_longer_active');
            return ['id' => null, 'failed_panelist' => null, 'db_error' => 'vote_no_longer_active'];
        }

        // Re-verify panelist load counts inside the transaction with FOR UPDATE
        // to prevent TOCTOU race where selectPanelists() ran outside the transaction.
        // This ensures load caps cannot be bypassed by concurrent dispute creation.
        $maxActivePanels = (int) apply_filters('bcc_disputes_max_active_panels_per_user', 10);
        if (!empty($panelistIds)) {
            $panelistPlaceholders = implode(',', array_fill(0, count($panelistIds), '%d'));
            $loadRows = $wpdb->get_results($wpdb->prepare(
                "SELECT p.panelist_user_id, COUNT(*) AS active_count
                 FROM {$panelTable} p
                 INNER JOIN {$disputeTable} d ON d.id = p.dispute_id
                 WHERE p.panelist_user_id IN ({$panelistPlaceholders})
                   AND d.status = 'reviewing'
                   AND p.decision IS NULL
                 GROUP BY p.panelist_user_id
                 FOR UPDATE",
                ...$panelistIds
            ));

            $loadMap = [];
            foreach ($loadRows as $row) {
                $loadMap[(int) $row->panelist_user_id] = (int) $row->active_count;
            }

            // Filter out any panelists that have exceeded their load cap
            // since the pre-transaction check.
            $panelistIds = array_values(array_filter($panelistIds, function (int $uid) use ($loadMap, $maxActivePanels) {
                return ($loadMap[$uid] ?? 0) < $maxActivePanels;
            }));

            if (count($panelistIds) < BCC_DISPUTES_PANEL_SIZE) {
                self::rollbackTx('createDisputeWithPanel:insufficient_panelists');
                return ['id' => null, 'failed_panelist' => null, 'db_error' => 'insufficient_panelists'];
            }
        }

        $wpdb->insert($disputeTable, [
            'vote_id'      => $disputeData['vote_id'],
            'page_id'      => $disputeData['page_id'],
            'reporter_id'  => $disputeData['reporter_id'],
            'voter_id'     => $disputeData['voter_id'],
            'reason'       => $disputeData['reason'],
            'evidence_url' => $disputeData['evidence_url'],
            'status'       => $disputeData['status'],
            'panel_size'   => $disputeData['panel_size'],
        ], ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d']);

        $dispute_id = $wpdb->insert_id;

        if (!$dispute_id) {
            // Capture last_error BEFORE the ROLLBACK — a subsequent $wpdb->query()
            // clears it, which previously meant production logs contained empty
            // db_error strings. Preserve the original INSERT failure reason.
            $insertError = (string) $wpdb->last_error;
            if ($insertError !== '' && stripos($insertError, 'Duplicate entry') !== false) {
                self::rollbackTx('createDisputeWithPanel:duplicate_entry');
                return ['id' => null, 'failed_panelist' => null, 'db_error' => 'already_disputed'];
            }
            self::rollbackTx('createDisputeWithPanel:insert_failed');
            return ['id' => null, 'failed_panelist' => null, 'db_error' => $insertError];
        }

        foreach ($panelistIds as $uid) {
            $wpdb->insert($panelTable, [
                'dispute_id'       => $dispute_id,
                'panelist_user_id' => $uid,
            ], ['%d', '%d']);

            if ($wpdb->last_error) {
                $error = $wpdb->last_error;
                self::rollbackTx('createDisputeWithPanel:panel_insert_failed');
                return ['id' => null, 'failed_panelist' => $uid, 'db_error' => $error];
            }
        }

        if (!self::commitTx('createDisputeWithPanel')) {
            // COMMIT failed → MySQL rolled back.  Returning here with
            // db_error='commit_failed' lets the controller surface a 5xx and
            // the reporter retry, instead of returning a dead insert_id.
            return ['id' => null, 'failed_panelist' => null, 'db_error' => 'commit_failed'];
        }

        // Invalidate: each panelist's queue cache, reporter's dispute list, status counts.
        foreach ($panelistIds as $uid) {
            self::bumpGeneration("panel_q_gen:{$uid}");
        }
        self::bumpGeneration("reporter_gen:{$disputeData['reporter_id']}");
        wp_cache_delete('dispute_status_counts', self::CACHE_GROUP);

        // Invalidate the "is this vote disputed?" cache so the newly-disputed
        // vote is immediately recognized (prevents stale "not disputed" reads).
        if (!empty($disputeData['vote_id'])) {
            wp_cache_delete("disputed_vote:{$disputeData['vote_id']}", self::CACHE_GROUP);
        }

        return ['id' => $dispute_id, 'failed_panelist' => null, 'db_error' => null];
    }

    /**
     * Return vote IDs (from a given set) that have an active or accepted dispute.
     *
     * @param int[] $voteIds
     * @return array<int, true>  vote_id => true for disputed votes.
     */
    public static function getDisputedVoteIds(array $voteIds): array
    {
        if (empty($voteIds)) {
            return [];
        }

        // Cache per individual vote ID rather than per-set to avoid
        // unbounded unique cache keys from different combinations.
        $result = [];
        $uncached = [];
        foreach ($voteIds as $vid) {
            $cached = wp_cache_get("disputed_vote:{$vid}", self::CACHE_GROUP);
            // Accept both int and string forms — some object-cache backends
            // round-trip integers as strings. Anything outside {0,1,"0","1"}
            // (including false for miss) is treated as a miss and re-fetched,
            // so UX cannot lie about dispute state from a type-corrupt entry.
            if ($cached === 1 || $cached === '1') {
                $result[$vid] = true;
            } elseif ($cached === 0 || $cached === '0') {
                // Confirmed not-disputed — nothing to add.
            } else {
                $uncached[] = $vid;
            }
        }
        if (empty($uncached)) {
            return $result;
        }

        global $wpdb;
        $table = self::disputes_table();

        $placeholders = implode(',', array_fill(0, count($uncached), '%d'));
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT vote_id
             FROM {$table}
             WHERE vote_id IN ({$placeholders})
               AND status IN ('reviewing','accepted')",
            ...$uncached
        ));

        $disputed = array_fill_keys(array_map('intval', $rows), true);

        // Cache each vote ID individually (disputed=1, not disputed=0).
        foreach ($uncached as $vid) {
            $isDisputed = isset($disputed[$vid]);
            wp_cache_set("disputed_vote:{$vid}", $isDisputed ? 1 : 0, self::CACHE_GROUP, self::TTL_HOT);
            if ($isDisputed) {
                $result[$vid] = true;
            }
        }

        return $result;
    }

    /**
     * Count disputes filed by a user.
     */
    public static function countByReporter(int $userId, ?int $pageId = null): int
    {
        $pageSuffix = $pageId !== null ? ":{$pageId}" : '';
        $gen      = self::getGeneration("reporter_gen:{$userId}");
        $cacheKey = "reporter_count:{$userId}:{$gen}{$pageSuffix}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $table = self::disputes_table();

        $where  = "reporter_id = %d";
        $params = [$userId];

        if ($pageId !== null) {
            $where   .= " AND page_id = %d";
            $params[] = $pageId;
        }

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$where}",
            ...$params
        ));

        wp_cache_set($cacheKey, $count, self::CACHE_GROUP, self::TTL_HOT);
        return $count;
    }

    /**
     * Paginated disputes filed by a user, with post/user display names.
     *
     * @return list<DisputeDetailDTO>
     */
    public static function getByReporterPaginated(int $userId, int $limit, int $offset, ?int $pageId = null): array
    {
        $pageSuffix = $pageId !== null ? ":{$pageId}" : '';
        $gen      = self::getGeneration("reporter_gen:{$userId}");
        $cacheKey = "reporter:{$userId}:{$gen}:{$limit}:{$offset}{$pageSuffix}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        $validated = self::validateCachedDtoList($cached, DisputeDetailDTO::class);
        if ($validated !== null) {
            return $validated;
        }

        global $wpdb;
        $table = self::disputes_table();

        $where  = "d.reporter_id = %d";
        $params = [$userId];

        if ($pageId !== null) {
            $where   .= " AND d.page_id = %d";
            $params[] = $pageId;
        }

        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.id, d.vote_id, d.page_id, d.reason, d.evidence_url, d.status,
                    d.panel_accepts, d.panel_rejects, d.panel_size,
                    d.voter_id, d.reporter_id, d.created_at, d.resolved_at,
                    p.post_title AS page_title,
                    u.display_name AS voter_name,
                    r.display_name AS reporter_name
             FROM {$table} d
             LEFT JOIN {$wpdb->posts} p ON d.page_id = p.ID
             LEFT JOIN {$wpdb->users} u ON d.voter_id = u.ID
             LEFT JOIN {$wpdb->users} r ON d.reporter_id = r.ID
             WHERE {$where}
             ORDER BY d.created_at DESC
             LIMIT %d OFFSET %d",
            ...$params
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $dtos = [];
        foreach ($rows as $row) {
            // Fail-soft at the list edge: a single corrupt legacy row must not
            // take down the whole reporter view. Single-entity reads
            // (getDisputeById, castPanelVoteAtomic) remain fail-fast.
            try {
                $dtos[] = self::hydrateDisputeDetail($row);
            } catch (\LogicException $e) {
                \BCC\Core\Log\Logger::error('[bcc-disputes] invalid_dispute_row_skipped', [
                    'source'     => 'getByReporterPaginated',
                    'dispute_id' => $row['id'] ?? null,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        wp_cache_set($cacheKey, $dtos, self::CACHE_GROUP, self::TTL_HOT);
        return $dtos;
    }

    /**
     * Strict hydration into DisputeDetailDTO. Shared by getByReporterPaginated
     * and getDisputeDetailForAdmin (both SELECT the same 16 columns).
     *
     * @param array<string, scalar|null> $row
     */
    private static function hydrateDisputeDetail(array $row): DisputeDetailDTO
    {
        return new DisputeDetailDTO(
            id:            RowAssert::requireDigitInt($row, 'id'),
            vote_id:       RowAssert::requireDigitInt($row, 'vote_id'),
            page_id:       RowAssert::requireDigitInt($row, 'page_id'),
            voter_id:      RowAssert::requireDigitInt($row, 'voter_id'),
            reporter_id:   RowAssert::requireDigitInt($row, 'reporter_id'),
            reason:        RowAssert::requireString($row, 'reason'),
            evidence_url:  RowAssert::optString($row, 'evidence_url'),
            status:        DisputeStatus::assert(RowAssert::requireString($row, 'status')),
            panel_accepts: RowAssert::requireDigitInt($row, 'panel_accepts'),
            panel_rejects: RowAssert::requireDigitInt($row, 'panel_rejects'),
            panel_size:    RowAssert::requireDigitInt($row, 'panel_size'),
            created_at:    RowAssert::requireString($row, 'created_at'),
            resolved_at:   RowAssert::optString($row, 'resolved_at'),
            page_title:    RowAssert::optString($row, 'page_title'),
            reporter_name: RowAssert::optString($row, 'reporter_name'),
            voter_name:    RowAssert::optString($row, 'voter_name'),
        );
    }

    /**
     * Count active disputes assigned to a panelist.
     */
    public static function countPanelQueueForUser(int $userId): int
    {
        $gen      = self::getGeneration("panel_q_gen:{$userId}");
        $cacheKey = "panel_q_count:{$userId}:{$gen}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $disputeTable = self::disputes_table();
        $panelTable   = self::panel_table();

        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$panelTable} pan
             JOIN {$disputeTable} d ON d.id = pan.dispute_id
             WHERE pan.panelist_user_id = %d AND d.status = 'reviewing'",
            $userId
        ));

        wp_cache_set($cacheKey, $count, self::CACHE_GROUP, self::TTL_HOT);
        return $count;
    }

    /**
     * Paginated active disputes assigned to a panelist, with display names.
     *
     * @return list<PanelistQueueItemDTO>
     */
    public static function getPanelQueueForUser(int $userId, int $limit, int $offset): array
    {
        $gen      = self::getGeneration("panel_q_gen:{$userId}");
        $cacheKey = "panel_q:{$userId}:{$gen}:{$limit}:{$offset}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        $validated = self::validateCachedDtoList($cached, PanelistQueueItemDTO::class);
        if ($validated !== null) {
            return $validated;
        }

        global $wpdb;
        $disputeTable = self::disputes_table();
        $panelTable   = self::panel_table();

        // Bounded: WHERE pan.panelist_user_id = %d AND status='reviewing'
        // plus LIMIT %d OFFSET %d at the tail of the prepared SQL.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.id, d.vote_id, d.page_id, d.reason, d.evidence_url, d.status,
                    d.panel_accepts, d.panel_rejects, d.panel_size,
                    d.voter_id, d.reporter_id, d.created_at, d.resolved_at,
                    pan.decision AS my_decision,
                    p.post_title AS page_title,
                    u.display_name AS voter_name,
                    r.display_name AS reporter_name
             FROM {$panelTable} pan
             JOIN {$disputeTable} d ON d.id = pan.dispute_id
             LEFT JOIN {$wpdb->posts} p ON d.page_id = p.ID
             LEFT JOIN {$wpdb->users} u ON d.voter_id = u.ID
             LEFT JOIN {$wpdb->users} r ON d.reporter_id = r.ID
             WHERE pan.panelist_user_id = %d
               AND d.status = 'reviewing'
             ORDER BY d.created_at ASC
             LIMIT %d OFFSET %d",
            $userId, $limit, $offset
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $dtos = [];
        foreach ($rows as $row) {
            // Fail-soft at the list edge: a single corrupt row must not take
            // down the panelist's whole queue. castPanelVoteAtomic remains
            // fail-fast for the single-dispute path.
            try {
                $myDecision = RowAssert::optString($row, 'my_decision');
                $dtos[] = new PanelistQueueItemDTO(
                    id:            RowAssert::requireDigitInt($row, 'id'),
                    vote_id:       RowAssert::requireDigitInt($row, 'vote_id'),
                    page_id:       RowAssert::requireDigitInt($row, 'page_id'),
                    voter_id:      RowAssert::requireDigitInt($row, 'voter_id'),
                    reporter_id:   RowAssert::requireDigitInt($row, 'reporter_id'),
                    reason:        RowAssert::requireString($row, 'reason'),
                    evidence_url:  RowAssert::optString($row, 'evidence_url'),
                    status:        DisputeStatus::assert(RowAssert::requireString($row, 'status')),
                    panel_accepts: RowAssert::requireDigitInt($row, 'panel_accepts'),
                    panel_rejects: RowAssert::requireDigitInt($row, 'panel_rejects'),
                    panel_size:    RowAssert::requireDigitInt($row, 'panel_size'),
                    created_at:    RowAssert::requireString($row, 'created_at'),
                    resolved_at:   RowAssert::optString($row, 'resolved_at'),
                    page_title:    RowAssert::optString($row, 'page_title'),
                    reporter_name: RowAssert::optString($row, 'reporter_name'),
                    voter_name:    RowAssert::optString($row, 'voter_name'),
                    my_decision:   $myDecision,
                );
            } catch (\LogicException $e) {
                \BCC\Core\Log\Logger::error('[bcc-disputes] invalid_dispute_row_skipped', [
                    'source'     => 'getPanelQueueForUser',
                    'dispute_id' => $row['id'] ?? null,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        wp_cache_set($cacheKey, $dtos, self::CACHE_GROUP, self::TTL_HOT);
        return $dtos;
    }

    /**
     * Get a panelist's assignment for a dispute.
     *
     * @return PanelEntrySlimDTO|null  Entry id + current decision, or null if no assignment.
     */
    public static function getPanelAssignment(int $disputeId, int $userId): ?PanelEntrySlimDTO
    {
        global $wpdb;
        $table = self::panel_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, decision FROM {$table} WHERE dispute_id = %d AND panelist_user_id = %d LIMIT 1",
            $disputeId, $userId
        ), ARRAY_A);

        if (!is_array($row)) {
            return null;
        }

        $decisionRaw = $row['decision'] ?? null;
        if ($decisionRaw !== null && !is_string($decisionRaw)) {
            throw new \LogicException('PanelAssignment: decision has non-null, non-string value');
        }

        return new PanelEntrySlimDTO(
            id:       RowAssert::requireDigitInt($row, 'id'),
            decision: $decisionRaw,
        );
    }

    /**
     * Get a dispute by ID (selected columns for controller use).
     *
     * @return DisputeCoreDTO|null  Core dispute row, or null if not found.
     */
    public static function getDisputeById(int $disputeId): ?DisputeCoreDTO
    {
        $cacheKey = "dispute:{$disputeId}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            if ($cached === 'NULL') {
                return null;
            }
            if ($cached instanceof DisputeCoreDTO) {
                return $cached;
            }
            // Cache poisoning (stale DTO shape across a deploy, cross-plugin
            // key collision, etc.). Delete the bad entry, log, and fall through
            // to the DB re-fetch below — the single source of truth is the
            // disputes table and hydrateDisputeCore still enforces every
            // trust-critical invariant. No recursion: we continue on to the
            // existing SELECT path rather than re-calling getDisputeById.
            wp_cache_delete($cacheKey, self::CACHE_GROUP);
            \BCC\Core\Log\Logger::error('[bcc-disputes] cache_poisoning_recovered', [
                'dispute_id' => $disputeId,
                'got'        => get_debug_type($cached),
            ]);
        }

        global $wpdb;
        $table = self::disputes_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, vote_id, page_id, voter_id, reporter_id,
                    panel_accepts, panel_rejects, panel_size
             FROM {$table} WHERE id = %d LIMIT 1",
            $disputeId
        ), ARRAY_A);

        if (!is_array($row)) {
            wp_cache_set($cacheKey, 'NULL', self::CACHE_GROUP, self::TTL_WARM);
            return null;
        }

        $dto = self::hydrateDisputeCore($row);
        wp_cache_set($cacheKey, $dto, self::CACHE_GROUP, self::TTL_WARM);
        return $dto;
    }

    /**
     * Strict hydration of an ARRAY_A row from the disputes table into a
     * DisputeCoreDTO. Fails-fast on any missing or wrong-typed trust-critical
     * field; the DTO constructor additionally enforces cross-field invariants
     * (IDs positive, panel counts non-negative, accepts+rejects ≤ size).
     *
     * @param array<string, scalar|null> $row
     */
    private static function hydrateDisputeCore(array $row): DisputeCoreDTO
    {
        $id           = RowAssert::requireDigitInt($row, 'id');
        $voteId       = RowAssert::requireDigitInt($row, 'vote_id');
        $pageId       = RowAssert::requireDigitInt($row, 'page_id');
        $voterId      = RowAssert::requireDigitInt($row, 'voter_id');
        $reporterId   = RowAssert::requireDigitInt($row, 'reporter_id');
        $panelAccepts = RowAssert::requireDigitInt($row, 'panel_accepts');
        $panelRejects = RowAssert::requireDigitInt($row, 'panel_rejects');
        $panelSize    = RowAssert::requireDigitInt($row, 'panel_size');

        if (!isset($row['status']) || !is_string($row['status'])) {
            throw new \LogicException('DisputeCore hydration: status missing or not string');
        }
        $status = DisputeStatus::assert($row['status']);

        return new DisputeCoreDTO(
            id:            $id,
            status:        $status,
            vote_id:       $voteId,
            page_id:       $pageId,
            voter_id:      $voterId,
            reporter_id:   $reporterId,
            panel_accepts: $panelAccepts,
            panel_rejects: $panelRejects,
            panel_size:    $panelSize,
        );
    }

    /**
     * Strict hydration into a DisputeResolutionCandidateDTO for scheduler paths.
     *
     * @param array<string, scalar|null> $row
     */
    private static function hydrateResolutionCandidate(array $row): DisputeResolutionCandidateDTO
    {
        return new DisputeResolutionCandidateDTO(
            id:            RowAssert::requireDigitInt($row, 'id'),
            vote_id:       RowAssert::requireDigitInt($row, 'vote_id'),
            page_id:       RowAssert::requireDigitInt($row, 'page_id'),
            voter_id:      RowAssert::requireDigitInt($row, 'voter_id'),
            reporter_id:   RowAssert::requireDigitInt($row, 'reporter_id'),
            panel_accepts: RowAssert::requireDigitInt($row, 'panel_accepts'),
            panel_rejects: RowAssert::requireDigitInt($row, 'panel_rejects'),
            panel_size:    RowAssert::requireDigitInt($row, 'panel_size'),
        );
    }

    /**
     * Validate a cached value as list<$dtoClass>. Returns the typed list when
     * valid (even when empty), null otherwise. For display-tier list caches
     * only — callers treat null as a miss and re-fetch. Trust-critical
     * single-entry caches (see getDisputeById) fail-fast on poisoning instead.
     *
     * @template T of object
     * @param mixed $cached
     * @param class-string<T> $dtoClass
     * @return list<T>|null
     */
    private static function validateCachedDtoList($cached, string $dtoClass): ?array
    {
        if (!is_array($cached) || !array_is_list($cached)) {
            return null;
        }
        foreach ($cached as $entry) {
            if (!$entry instanceof $dtoClass) {
                return null;
            }
        }
        return $cached;
    }

    /**
     * Atomically cast a panel vote: lock dispute, record decision, update tally, re-read.
     *
     * This method encapsulates the entire cast_vote transaction:
     * 1. SELECT FOR UPDATE on dispute row (serialises concurrent voters)
     * 2. UPDATE panel row WHERE decision IS NULL (prevents double-voting)
     * 3. Increment tally column
     * 4. Re-read dispute inside the lock
     * 5. COMMIT
     *
     * @return array{status: string, code: string, message: string, dispute: ?DisputeCoreDTO, accepts: int, rejects: int}
     */
    public static function castPanelVoteAtomic(int $disputeId, int $userId, string $decision, string $note): array
    {
        global $wpdb;
        $disputeTable = self::disputes_table();
        $panelTable   = self::panel_table();

        if (!self::beginTx()) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] START TRANSACTION failed in castPanelVoteAtomic', [
                'dispute_id' => $disputeId,
                'db_error'   => (string) $wpdb->last_error,
            ]);
            return ['status' => 'error', 'code' => 'db_error', 'message' => 'Transaction failed.', 'http' => 500, 'dispute' => null, 'accepts' => 0, 'rejects' => 0, 'db_error' => (string) $wpdb->last_error];
        }

        // Lock the dispute row — concurrent voters block here.
        $lockedRow = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, vote_id, page_id, voter_id, reporter_id,
                    panel_accepts, panel_rejects, panel_size
             FROM {$disputeTable} WHERE id = %d FOR UPDATE",
            $disputeId
        ), ARRAY_A);

        if (!is_array($lockedRow)) {
            self::rollbackTx('castPanelVoteAtomic:dispute_closed');
            return ['status' => 'error', 'code' => 'dispute_closed', 'message' => 'This dispute is no longer open.', 'http' => 410, 'dispute' => null, 'accepts' => 0, 'rejects' => 0];
        }

        // Hydrate + validate inside the transaction. If DB corruption is present,
        // hydrateDisputeCore throws — ROLLBACK keeps the transaction safe.
        try {
            $dispute = self::hydrateDisputeCore($lockedRow);
        } catch (\LogicException $e) {
            self::rollbackTx('castPanelVoteAtomic:hydrate_failed');
            throw $e;
        }

        if ($dispute->status !== DisputeStatus::REVIEWING) {
            self::rollbackTx('castPanelVoteAtomic:dispute_closed');
            return ['status' => 'error', 'code' => 'dispute_closed', 'message' => 'This dispute is no longer open.', 'http' => 410, 'dispute' => null, 'accepts' => 0, 'rejects' => 0];
        }

        // Atomic vote recording: UPDATE … WHERE decision IS NULL prevents double-voting.
        $voted = $wpdb->query($wpdb->prepare(
            "UPDATE {$panelTable} SET decision = %s, note = %s, voted_at = %s
             WHERE dispute_id = %d AND panelist_user_id = %d AND decision IS NULL",
            $decision, $note, gmdate('Y-m-d H:i:s'), $disputeId, $userId
        ));

        if ($voted === false) {
            $voteError = (string) $wpdb->last_error; // capture before ROLLBACK clears it
            self::rollbackTx('castPanelVoteAtomic:vote_update_failed');
            return ['status' => 'error', 'code' => 'db_error', 'message' => 'Failed to record vote.', 'http' => 500, 'step' => 'panel_vote_update', 'dispute' => null, 'accepts' => 0, 'rejects' => 0, 'db_error' => $voteError];
        }
        if ($voted === 0) {
            self::rollbackTx('castPanelVoteAtomic:already_voted');
            return ['status' => 'error', 'code' => 'already_voted', 'message' => 'You have already voted on this dispute.', 'http' => 409, 'dispute' => null, 'accepts' => 0, 'rejects' => 0];
        }

        // Atomic tally update — uses parameterised CASE to avoid
        // interpolating a column name into the SQL string.
        $tally_ok = $wpdb->query($wpdb->prepare(
            "UPDATE {$disputeTable}
             SET panel_accepts = panel_accepts + IF(%s = 'accept', 1, 0),
                 panel_rejects = panel_rejects + IF(%s = 'reject', 1, 0)
             WHERE id = %d",
            $decision,
            $decision,
            $disputeId
        ));

        if ($tally_ok === false) {
            $tallyError = (string) $wpdb->last_error; // capture before ROLLBACK clears it
            self::rollbackTx('castPanelVoteAtomic:tally_update_failed');
            return ['status' => 'error', 'code' => 'db_error', 'message' => 'Failed to update tally.', 'http' => 500, 'step' => 'tally_increment', 'dispute' => null, 'accepts' => 0, 'rejects' => 0, 'db_error' => $tallyError];
        }

        // Re-read tallies (still inside transaction / row lock)
        $updatedRow = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status, panel_accepts, panel_rejects, panel_size,
                    vote_id, page_id, voter_id, reporter_id
             FROM {$disputeTable} WHERE id = %d",
            $disputeId
        ), ARRAY_A);

        if (!is_array($updatedRow)) {
            // Dispute row disappeared between FOR UPDATE lock and re-read —
            // this should be impossible under normal MySQL isolation.
            self::rollbackTx('castPanelVoteAtomic:row_vanished');
            throw new \LogicException("Dispute {$disputeId} vanished inside its own transaction");
        }

        try {
            $dispute = self::hydrateDisputeCore($updatedRow);
        } catch (\LogicException $e) {
            self::rollbackTx('castPanelVoteAtomic:rehydrate_failed');
            throw $e;
        }

        // Derive authoritative accepts/rejects from the panel table directly.
        // The denormalized panel_accepts/panel_rejects columns on bcc_disputes
        // can drift when panel rows are deleted (via user deletion) without a
        // matching tally decrement, so they must NOT be trusted for verdict
        // math. Panel rows are the single source of truth; the denormalized
        // columns remain as a display/cache convenience only.
        $derived = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN decision = 'accept' THEN 1 ELSE 0 END), 0) AS accepts,
                COALESCE(SUM(CASE WHEN decision = 'reject' THEN 1 ELSE 0 END), 0) AS rejects
             FROM {$panelTable}
             WHERE dispute_id = %d",
            $disputeId
        ), ARRAY_A);

        $liveAccepts = is_array($derived) ? (int) $derived['accepts'] : 0;
        $liveRejects = is_array($derived) ? (int) $derived['rejects'] : 0;

        if (!self::commitTx('castPanelVoteAtomic')) {
            // COMMIT failed → vote was rolled back by MySQL.  Do NOT
            // invalidate caches or return success — let the panelist retry.
            return ['status' => 'error', 'code' => 'db_error', 'message' => 'Failed to finalize vote.', 'http' => 500, 'step' => 'commit', 'dispute' => null, 'accepts' => 0, 'rejects' => 0, 'db_error' => (string) $wpdb->last_error];
        }

        // Invalidate all caches affected by this vote (tally changed,
        // queue state changed for ALL panelists, reporter sees updated tally).
        self::invalidateDispute($disputeId);

        return [
            'status'  => 'success',
            'code'    => 'ok',
            'message' => 'Vote recorded.',
            'http'    => 200,
            'dispute' => $dispute,
            'accepts' => $liveAccepts,
            'rejects' => $liveRejects,
        ];
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

        if (!self::beginTx()) {
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
            self::rollbackTx('createReport:daily_limit');
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
            self::rollbackTx('createReport:duplicate');
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
            self::rollbackTx('createReport:target_ceiling');
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
            self::rollbackTx('createReport:insert_failed');
            return null;
        }

        if (!self::commitTx('createReport')) {
            // COMMIT failed → insert was rolled back by MySQL.  Return null
            // so the caller treats this as a write failure and the user can retry.
            return null;
        }

        wp_cache_delete('report_status_counts', self::CACHE_GROUP);

        return $id;
    }

    // ── Admin query methods ──────────────────────────────────────────────────

    /**
     * Get a dispute with joined page title and user display names for admin detail view.
     */
    public static function getDisputeDetailForAdmin(int $disputeId): ?DisputeDetailDTO
    {
        global $wpdb;
        $table = self::disputes_table();

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT d.id, d.vote_id, d.page_id, d.reporter_id, d.voter_id,
                    d.reason, d.evidence_url, d.status,
                    d.panel_accepts, d.panel_rejects, d.panel_size,
                    d.created_at, d.resolved_at,
                    p.post_title   AS page_title,
                    reporter.display_name AS reporter_name,
                    voter.display_name    AS voter_name
             FROM {$table} d
             LEFT JOIN {$wpdb->posts} p         ON d.page_id     = p.ID
             LEFT JOIN {$wpdb->users} reporter  ON d.reporter_id = reporter.ID
             LEFT JOIN {$wpdb->users} voter     ON d.voter_id    = voter.ID
             WHERE d.id = %d
             LIMIT 1",
            $disputeId
        ), ARRAY_A);

        if (!is_array($row)) {
            return null;
        }
        return self::hydrateDisputeDetail($row);
    }

    /**
     * Get all panelists for a dispute with display names.
     *
     * @return list<PanelEntryFullDTO>
     */
    public static function getPanelistsForDispute(int $disputeId): array
    {
        global $wpdb;
        $table = self::panel_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pan.id, pan.dispute_id, pan.panelist_user_id,
                    pan.decision, pan.note, pan.assigned_at, pan.voted_at,
                    u.display_name
             FROM {$table} pan
             LEFT JOIN {$wpdb->users} u ON pan.panelist_user_id = u.ID
             WHERE pan.dispute_id = %d
             ORDER BY pan.assigned_at ASC
             LIMIT %d",
            $disputeId, BCC_DISPUTES_PANEL_SIZE
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $dtos = [];
        foreach ($rows as $row) {
            $decision = RowAssert::optString($row, 'decision');
            if ($decision !== null) {
                PanelDecision::assert($decision);
            }
            $dtos[] = new PanelEntryFullDTO(
                id:               RowAssert::requireDigitInt($row, 'id'),
                dispute_id:       RowAssert::requireDigitInt($row, 'dispute_id'),
                panelist_user_id: RowAssert::requireDigitInt($row, 'panelist_user_id'),
                decision:         $decision,
                note:             RowAssert::optString($row, 'note'),
                assigned_at:      RowAssert::requireString($row, 'assigned_at'),
                voted_at:         RowAssert::optString($row, 'voted_at'),
                display_name:     RowAssert::optString($row, 'display_name'),
            );
        }
        return $dtos;
    }

    /**
     * Get dispute counts grouped by status.
     *
     * @return array<string, int>
     */
    public static function getDisputeStatusCounts(): array
    {
        $cacheKey = 'dispute_status_counts';
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;
        $table = self::disputes_table();

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
     * Count disputes for admin list, optionally filtered by status.
     */
    public static function countDisputesForAdminList(?string $status): int
    {
        global $wpdb;
        $table = self::disputes_table();

        if ($status) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE status = %s",
                $status
            ));
        }

        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    }

    /**
     * Paginated dispute list for admin, with joined page title and user names.
     *
     * @return list<AdminDisputeRowDTO>
     */
    public static function getDisputesForAdminList(?string $status, string $orderBy, string $order, int $limit, int $offset): array
    {
        global $wpdb;
        $table = self::disputes_table();

        $allowed = ['id', 'status', 'created_at'];
        if (!in_array($orderBy, $allowed, true)) {
            $orderBy = 'id';
        }
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';

        $where  = '1=1';
        $params = [];

        if ($status) {
            $where   .= ' AND d.status = %s';
            $params[] = $status;
        }

        $sql = "SELECT d.id, d.vote_id, d.page_id, d.reporter_id, d.voter_id,
                       d.reason, d.status,
                       d.panel_accepts, d.panel_rejects, d.panel_size,
                       d.created_at, d.resolved_at,
                       p.post_title   AS page_title,
                       reporter.display_name AS reporter_name,
                       voter.display_name    AS voter_name
                FROM {$table} d
                LEFT JOIN {$wpdb->posts} p         ON d.page_id     = p.ID
                LEFT JOIN {$wpdb->users} reporter  ON d.reporter_id = reporter.ID
                LEFT JOIN {$wpdb->users} voter     ON d.voter_id    = voter.ID
                WHERE {$where}
                ORDER BY d.{$orderBy} {$order}
                LIMIT %d OFFSET %d";

        $params[] = $limit;
        $params[] = $offset;

        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        if (!is_array($rows)) {
            return [];
        }

        $dtos = [];
        foreach ($rows as $row) {
            // Fail-soft at the admin-list edge: one corrupt legacy row must
            // not 500 the whole admin screen. Per-entity admin reads
            // (getDisputeDetailForAdmin) remain fail-fast.
            try {
                $dtos[] = new AdminDisputeRowDTO(
                    id:            RowAssert::requireDigitInt($row, 'id'),
                    vote_id:       RowAssert::requireDigitInt($row, 'vote_id'),
                    page_id:       RowAssert::requireDigitInt($row, 'page_id'),
                    reporter_id:   RowAssert::requireDigitInt($row, 'reporter_id'),
                    voter_id:      RowAssert::requireDigitInt($row, 'voter_id'),
                    reason:        RowAssert::requireString($row, 'reason'),
                    status:        DisputeStatus::assert(RowAssert::requireString($row, 'status')),
                    panel_accepts: RowAssert::requireDigitInt($row, 'panel_accepts'),
                    panel_rejects: RowAssert::requireDigitInt($row, 'panel_rejects'),
                    panel_size:    RowAssert::requireDigitInt($row, 'panel_size'),
                    created_at:    RowAssert::requireString($row, 'created_at'),
                    resolved_at:   RowAssert::optString($row, 'resolved_at'),
                    page_title:    RowAssert::optString($row, 'page_title'),
                    reporter_name: RowAssert::optString($row, 'reporter_name'),
                    voter_name:    RowAssert::optString($row, 'voter_name'),
                );
            } catch (\LogicException $e) {
                \BCC\Core\Log\Logger::error('[bcc-disputes] invalid_dispute_row_skipped', [
                    'source'     => 'getDisputesForAdminList',
                    'dispute_id' => $row['id'] ?? null,
                    'error'      => $e->getMessage(),
                ]);
            }
        }
        return $dtos;
    }

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

    /**
     * Atomically claim the right to send the reporter-result email.
     *
     * Returns true only when this call transitions `resolved_notified_at` from
     * NULL → $ts. The caller treats false as "someone else already claimed /
     * sent; bail out." $ts MUST be caller-generated and stored in a local var
     * so clearResolvedNotified() can scope the rollback to *this* claim and
     * not blow away a concurrent successful send's marker.
     *
     * This is the claim half of the claim-before-send pattern. Do NOT call
     * this AFTER wp_mail() — the whole point is to serialise two concurrent
     * AS workers before either one sends.
     */
    public static function markResolvedNotified(int $disputeId, string $ts): bool
    {
        global $wpdb;
        $table = self::disputes_table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET resolved_notified_at = %s WHERE id = %d AND resolved_notified_at IS NULL",
            $ts,
            $disputeId
        ));

        return $affected > 0;
    }

    /**
     * Release a tentative reporter-result claim when the send failed.
     *
     * Scoped by $ts so we can only clear OUR OWN claim — a concurrent worker
     * that successfully sent with a different timestamp is untouched.
     */
    public static function clearResolvedNotified(int $disputeId, string $ts): bool
    {
        global $wpdb;
        $table = self::disputes_table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET resolved_notified_at = NULL
             WHERE id = %d AND resolved_notified_at = %s",
            $disputeId,
            $ts
        ));

        return $affected > 0;
    }

    /**
     * Read-only check: has the resolved-dispute email already been marked as sent?
     * Used by the async handler to short-circuit idempotently before calling
     * wp_mail a second time after an Action Scheduler retry.
     */
    public static function isResolvedNotified(int $disputeId): bool
    {
        global $wpdb;
        $table = self::disputes_table();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT resolved_notified_at IS NOT NULL FROM {$table} WHERE id = %d LIMIT 1",
            $disputeId
        ));
    }

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
     * Read-only check: has the reported-user email already been sent?
     */
    public static function isReportNotified(int $reportId): bool
    {
        global $wpdb;
        $table = self::user_reports_table();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT notified_at IS NOT NULL FROM {$table} WHERE id = %d LIMIT 1",
            $reportId
        ));
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
     * Read-only check: has the admin-report email already been sent?
     */
    public static function isAdminReportNotified(int $reportId): bool
    {
        global $wpdb;
        $table = self::user_reports_table();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT admin_notified_at IS NOT NULL FROM {$table} WHERE id = %d LIMIT 1",
            $reportId
        ));
    }

    /**
     * Read-only check: has this panelist already been marked as notified?
     * Lets the async notification handler short-circuit AS retries that ran
     * AFTER a successful wp_mail but BEFORE AS committed its own completion
     * log (crash window).
     */
    public static function isPanelistNotified(int $disputeId, int $panelistUserId): bool
    {
        global $wpdb;
        $table = self::panel_table();

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT notified_at IS NOT NULL
             FROM {$table}
             WHERE dispute_id = %d AND panelist_user_id = %d
             LIMIT 1",
            $disputeId,
            $panelistUserId
        ));
    }

    // ── Scheduler query methods ─────────────────────────────────────────────

    /**
     * Get expired disputes past the cutoff date, limited batch.
     *
     * SQL unchanged — only post-query mapping added. Ordering, filtering, and
     * limit semantics are preserved exactly.
     *
     * @return list<DisputeResolutionCandidateDTO>
     */
    public static function getExpiredDisputes(string $cutoff, int $limit = 50): array
    {
        global $wpdb;
        $table      = self::disputes_table();
        $panelTable = self::panel_table();

        // Derive tallies from panel rows instead of trusting the denormalised
        // panel_accepts / panel_rejects columns.  If the counts diverge (admin
        // edit, partial-failure on a future code path), the verdict is still
        // computed from the authoritative panel decisions.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.id, d.panel_size, d.vote_id, d.page_id, d.voter_id, d.reporter_id,
                    COALESCE(pt.accepts, 0) AS panel_accepts,
                    COALESCE(pt.rejects, 0) AS panel_rejects
             FROM {$table} d
             LEFT JOIN (
                 SELECT dispute_id,
                        SUM(decision = 'accept') AS accepts,
                        SUM(decision = 'reject') AS rejects
                 FROM {$panelTable}
                 WHERE decision IS NOT NULL
                 GROUP BY dispute_id
             ) pt ON pt.dispute_id = d.id
             WHERE d.status = 'reviewing'
               AND d.created_at <= %s
             ORDER BY d.created_at ASC
             LIMIT %d",
            $cutoff, $limit
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $dtos = [];
        foreach ($rows as $row) {
            $dtos[] = self::hydrateResolutionCandidate($row);
        }
        return $dtos;
    }

    // ── Async-resolve enqueue guard ─────────────────────────────────────────

    /**
     * Atomically claim the right to enqueue an async resolution for a dispute.
     *
     * Returns true ONLY when this call is the first to flip
     * `resolution_enqueued_at` from NULL to NOW() on a still-reviewing
     * dispute. Subsequent callers (concurrent panelists, reconciliation
     * sweeps) see the row already claimed and return false — preventing
     * duplicate jobs in the Action Scheduler queue.
     *
     * The async handler remains idempotent on its own (UPDATE status
     * ... WHERE status='reviewing' in beginResolveTransaction), so this
     * guard is a backlog-reducer, not a correctness boundary.
     */
    public static function claimResolutionEnqueue(int $disputeId): bool
    {
        global $wpdb;
        $table = self::disputes_table();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET resolution_enqueued_at = %s
              WHERE id = %d
                AND status = 'reviewing'
                AND resolution_enqueued_at IS NULL",
            gmdate('Y-m-d H:i:s'),
            $disputeId
        ));

        if ($result === false) {
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-disputes] claim_resolution_enqueue_db_error', [
                    'dispute_id' => $disputeId,
                    'db_error'   => $wpdb->last_error,
                ]);
            }
            return false;
        }

        return $result > 0;
    }

    /**
     * Count disputes stuck between enqueue-claim and resolution.
     *
     * A dispute matches when:
     *   - status = 'reviewing'                   (never left the voting phase)
     *   - resolution_enqueued_at IS NOT NULL     (claimResolutionEnqueue fired)
     *   - resolution_enqueued_at older than $thresholdSeconds
     *
     * Non-zero means the async handler never advanced the status — either
     * Action Scheduler/wp-cron never ran the job or the handler crashed
     * before beginResolveTransaction. Reconciliation Phase A sweeps these,
     * but the metric surfaces the backlog directly so operators can alert
     * before the 5-minute reconcile tick.
     */
    public static function countStuckAsyncResolutions(int $thresholdSeconds): int
    {
        global $wpdb;
        $table = self::disputes_table();

        $thresholdSeconds = max(0, $thresholdSeconds);

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE status = 'reviewing'
               AND resolution_enqueued_at IS NOT NULL
               AND resolution_enqueued_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)",
            $thresholdSeconds
        ));
    }

    // ── Transaction methods (for DisputeResolver) ─────────────────────

    /**
     * Begin an atomic dispute resolution: START TRANSACTION, UPDATE status.
     *
     * The transaction is left OPEN on success — caller must call
     * commitTransaction() or rollbackTransaction().
     *
     * @return array{success: bool, affected_rows: int, db_error: ?string, race: bool}
     */
    public static function beginResolveTransaction(int $disputeId, string $outcome): array
    {
        // Fail-fast at the DB boundary on any outcome that is not a valid
        // terminal DisputeStatus. Every current caller validates upstream
        // (REST enum, handleAsyncResolve whitelist, DisputeAdmin action
        // check), but nothing prevented a future caller from persisting
        // garbage. This assertion mirrors the hydration-side
        // DisputeStatus::assert() calls and enforces the state-machine
        // invariant at write time. 'reviewing' is the entry state — we
        // only transition OUT of it here — so it is deliberately excluded.
        DisputeStatus::assert($outcome);
        if ($outcome === DisputeStatus::REVIEWING) {
            throw new \LogicException(
                "beginResolveTransaction: cannot transition to 'reviewing' (entry state only)"
            );
        }

        global $wpdb;
        $table = self::disputes_table();

        if (!self::beginTx()) {
            $startError = (string) $wpdb->last_error;
            \BCC\Core\Log\Logger::error('[bcc-disputes] START TRANSACTION failed in beginResolveTransaction', [
                'dispute_id' => $disputeId,
                'db_error'   => $startError,
            ]);
            return ['success' => false, 'affected_rows' => 0, 'db_error' => $startError !== '' ? $startError : 'tx_begin_failed', 'race' => false];
        }

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = %s, resolved_at = %s, adjudication_status = 'pending'
             WHERE id = %d AND status = 'reviewing'",
            $outcome,
            gmdate('Y-m-d H:i:s'),
            $disputeId
        ));

        if ($result === false) {
            $updateError = (string) $wpdb->last_error; // capture before ROLLBACK clears it
            self::rollbackTx('beginResolveTransaction:update_failed');
            return ['success' => false, 'affected_rows' => 0, 'db_error' => $updateError, 'race' => false];
        }

        if ($result === 0) {
            self::rollbackTx('beginResolveTransaction:race');
            return ['success' => false, 'affected_rows' => 0, 'db_error' => null, 'race' => true];
        }

        // Transaction still OPEN — caller must commit or rollback.
        return ['success' => true, 'affected_rows' => $result, 'db_error' => null, 'race' => false];
    }

    /**
     * Commit the current open transaction.
     *
     * Returns false when MySQL rolled the transaction back at commit time
     * (commit-time deadlock, serialization failure, connection drop).
     * Callers MUST treat false as write failure — the status UPDATE in
     * beginResolveTransaction will have been silently reverted, so the
     * dispute is still 'reviewing' on disk despite the pre-commit logic
     * having "resolved" it.
     */
    public static function commitTransaction(): bool
    {
        return self::commitTx('resolve_commit');
    }

    /**
     * Rollback the current open transaction.
     */
    public static function rollbackTransaction(): void
    {
        self::rollbackTx('resolve_rollback');
    }

    // ── Adjudication status tracking ──────────────────────────────────────

    /**
     * Mark the adjudication status for a dispute.
     *
     * Values: 'pending' (committed, awaiting adjudication),
     *         'completed' (adjudication succeeded),
     *         'failed' (adjudication failed, requires reconciliation).
     *
     * Returns true only when the UPDATE executed successfully against the DB.
     * Callers that gate irreversible side-effects (penalty hook, reporter
     * email) on this write MUST check the return value and treat false as
     * "do not proceed — let reconciliation retry."
     */
    public static function setAdjudicationStatus(int $disputeId, string $status): bool
    {
        global $wpdb;
        $table = self::disputes_table();
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET adjudication_status = %s WHERE id = %d",
            $status,
            $disputeId
        ));

        if ($result === false) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] setAdjudicationStatus DB error', [
                'dispute_id' => $disputeId,
                'status'     => $status,
                'db_error'   => $wpdb->last_error,
            ]);
            return false;
        }

        // Invalidate cached dispute so subsequent reads see the new status.
        self::invalidateDispute($disputeId);

        /**
         * Fires when a dispute's adjudication status changes.
         *
         * Cross-plugin consumers can hook here to react to dispute
         * lifecycle events (e.g. clearing derived caches, updating
         * admin dashboards, sending notifications).
         *
         * ## Semantics (READ BEFORE HOOKING)
         *
         * This action fires on EVERY setAdjudicationStatus() write — not
         * just on terminal transitions. Under reconciliation retries a
         * single dispute can legitimately cycle:
         *   pending → failed → pending → failed → completed
         * firing this hook five times. Listeners MUST therefore be:
         *
         *   1. **Idempotent**: receiving the same (disputeId, status)
         *      tuple multiple times must not double-apply side-effects.
         *      Use an internal claim/marker per (disputeId, status) if
         *      you mutate state.
         *   2. **Non-blocking**: no slow network calls or heavy DB writes
         *      on the synchronous hook fire. Queue work for async workers.
         *   3. **Order-tolerant**: a 'failed' observation may arrive
         *      after 'completed' due to retry interleaving. Treat the
         *      current on-disk adjudication_status as authoritative, not
         *      the sequence of hook fires.
         *
         * @param int    $disputeId  The dispute that changed.
         * @param string $status     New status: 'pending', 'completed', or 'failed'.
         */
        do_action('bcc_dispute_status_changed', $disputeId, $status);

        return true;
    }

    /**
     * Read the adjudication_status directly from DB, bypassing cache.
     *
     * Use this when you need post-write consistency — e.g. verifying
     * that a setAdjudicationStatus() write actually persisted before
     * firing irreversible side-effects.
     *
     * getDisputeById() must NOT be used for this purpose because:
     * (1) it returns cached data (stale after recent writes), and
     * (2) its SELECT does not include the adjudication_status column.
     *
     * @param int $disputeId
     * @return string|null The status, or null if dispute not found.
     */
    public static function getAdjudicationStatus(int $disputeId): ?string
    {
        global $wpdb;
        $table = self::disputes_table();
        return $wpdb->get_var($wpdb->prepare(
            "SELECT adjudication_status FROM {$table} WHERE id = %d",
            $disputeId
        ));
    }

    /**
     * Find disputes that are resolved but adjudication never completed.
     * These are split-brain orphans that need reconciliation.
     *
     * @param int $limit Max disputes to return per batch.
     * @return list<object>
     */
    /**
     * SQL unchanged — only post-query mapping added.
     *
     * @return list<OrphanedDisputeDTO>
     */
    public static function getOrphanedDisputes(int $limit = 10): array
    {
        global $wpdb;
        $table = self::disputes_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, vote_id, page_id, reporter_id, voter_id, status, reopen_count
             FROM {$table}
             WHERE status IN ('accepted', 'rejected')
               AND adjudication_status IN ('pending', 'failed')
               AND reopen_count < %d
               AND resolved_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)
             ORDER BY resolved_at ASC
             LIMIT %d",
            (int) BCC_DISPUTES_MAX_REOPEN_ATTEMPTS,
            $limit
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $dtos = [];
        foreach ($rows as $row) {
            if (!isset($row['status']) || !is_string($row['status'])) {
                throw new \LogicException('getOrphanedDisputes: status missing or not string');
            }
            $dtos[] = new OrphanedDisputeDTO(
                id:           RowAssert::requireDigitInt($row, 'id'),
                vote_id:      RowAssert::requireDigitInt($row, 'vote_id'),
                page_id:      RowAssert::requireDigitInt($row, 'page_id'),
                voter_id:     RowAssert::requireDigitInt($row, 'voter_id'),
                reporter_id: RowAssert::requireDigitInt($row, 'reporter_id'),
                status:       DisputeStatus::assert($row['status']),
                reopen_count: RowAssert::requireDigitInt($row, 'reopen_count'),
            );
        }
        return $dtos;
    }

    /**
     * Find disputes stuck in "reviewing" where all panel votes are in
     * but resolution was never executed (trust engine unavailable at
     * the moment of the deciding vote).
     *
     * SQL unchanged — only post-query mapping added.
     *
     * @return list<DisputeResolutionCandidateDTO>
     */
    public static function getStuckReviewingDisputes(string $cutoff, int $limit = 10): array
    {
        global $wpdb;
        $table      = self::disputes_table();
        $panelTable = self::panel_table();

        // Derive tallies from panel rows (authoritative) instead of the
        // denormalised dispute columns.  Also uses the latest panel vote
        // timestamp to avoid re-triggering on disputes where the deciding
        // vote just came in seconds ago.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT d.id, d.vote_id, d.page_id, d.voter_id, d.reporter_id,
                    d.panel_size,
                    COALESCE(pv.accepts, 0) AS panel_accepts,
                    COALESCE(pv.rejects, 0) AS panel_rejects
             FROM {$table} d
             INNER JOIN (
                 SELECT dispute_id,
                        MAX(voted_at) AS last_voted_at,
                        SUM(decision = 'accept') AS accepts,
                        SUM(decision = 'reject') AS rejects
                 FROM {$panelTable}
                 WHERE decision IS NOT NULL
                 GROUP BY dispute_id
             ) pv ON pv.dispute_id = d.id
             WHERE d.status = 'reviewing'
               AND (
                   pv.accepts >= FLOOR(d.panel_size / 2) + 1
                   OR pv.rejects >= FLOOR(d.panel_size / 2) + 1
               )
               AND pv.last_voted_at < %s
             ORDER BY pv.last_voted_at ASC
             LIMIT %d",
            $cutoff,
            $limit
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $dtos = [];
        foreach ($rows as $row) {
            $dtos[] = self::hydrateResolutionCandidate($row);
        }
        return $dtos;
    }

    /**
     * Count orphaned disputes (committed but adjudication pending/failed).
     * Uses COUNT(*) instead of hydrating full rows.
     */
    public static function countOrphanedDisputes(): int
    {
        global $wpdb;
        $table = self::disputes_table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE status IN ('accepted', 'rejected')
               AND adjudication_status IN ('pending', 'failed')
               AND reopen_count < %d
               AND resolved_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 2 MINUTE)",
            (int) BCC_DISPUTES_MAX_REOPEN_ATTEMPTS
        ));
    }

    /**
     * Count disputes that exhausted all reconciliation retries
     * (reopen_count >= BCC_DISPUTES_MAX_REOPEN_ATTEMPTS) and will never
     * be automatically retried. These require manual admin intervention.
     */
    public static function countPermanentOrphans(): int
    {
        global $wpdb;
        $table = self::disputes_table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE status IN ('accepted', 'rejected')
               AND adjudication_status IN ('pending', 'failed')
               AND reopen_count >= %d",
            (int) BCC_DISPUTES_MAX_REOPEN_ATTEMPTS
        ));
    }

    /**
     * Get permanently orphaned disputes for admin review.
     *
     * @return list<object>
     */
    public static function getPermanentOrphans(int $limit = 20): array
    {
        global $wpdb;
        $table = self::disputes_table();

        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, vote_id, page_id, reporter_id, voter_id, status,
                    adjudication_status, reopen_count, resolved_at
             FROM {$table}
             WHERE status IN ('accepted', 'rejected')
               AND adjudication_status IN ('pending', 'failed')
               AND reopen_count >= %d
             ORDER BY resolved_at ASC
             LIMIT %d",
            (int) BCC_DISPUTES_MAX_REOPEN_ATTEMPTS,
            $limit
        )) ?: [];
    }

    /**
     * Reset reopen_count for a dispute, allowing reconciliation to retry.
     * Used by admin "force re-adjudicate" action.
     */
    public static function resetReopenCount(int $disputeId): bool
    {
        global $wpdb;
        $table = self::disputes_table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET reopen_count = 0, adjudication_status = 'pending'
             WHERE id = %d AND reopen_count >= %d",
            $disputeId,
            (int) BCC_DISPUTES_MAX_REOPEN_ATTEMPTS
        ));

        if ($affected > 0) {
            self::invalidateDispute($disputeId);
        }

        return $affected > 0;
    }

    // ── Cache invalidation ─────────────────────────────────────────────────

    /**
     * Invalidate all caches affected by a dispute status/tally change.
     *
     * Must be called by any code that writes to the disputes or panel tables
     * outside of this repository (e.g. DisputeResolver).
     *
     * Invalidates:
     * - dispute:{disputeId} (direct delete)
     * - disputed_vote:{voteId} (direct delete — UI re-dispute gate after rejection)
     * - panel_q_gen:{panelistId} for ALL panelists on the dispute (generation bump)
     * - reporter_gen:{reporterId} (generation bump)
     */
    public static function invalidateDispute(int $disputeId): void
    {
        wp_cache_delete("dispute:{$disputeId}", self::CACHE_GROUP);
        wp_cache_delete('dispute_status_counts', self::CACHE_GROUP);

        global $wpdb;
        $disputeTable = self::disputes_table();
        $panelTable   = self::panel_table();

        // Fetch reporter_id AND vote_id in one round-trip so we can invalidate
        // the per-vote "is this vote disputed?" cache too. Previously only the
        // create path deleted this key; resolve paths left a ≤60s stale entry
        // that blocked re-dispute in the UI after a rejected outcome.
        $dispute = $wpdb->get_row($wpdb->prepare(
            "SELECT reporter_id, vote_id FROM {$disputeTable} WHERE id = %d LIMIT 1",
            $disputeId
        ));

        if ($dispute) {
            self::bumpGeneration("reporter_gen:{$dispute->reporter_id}");
            if (!empty($dispute->vote_id)) {
                wp_cache_delete("disputed_vote:{$dispute->vote_id}", self::CACHE_GROUP);
            }
        }

        $panelistIds = $wpdb->get_col($wpdb->prepare(
            "SELECT panelist_user_id FROM {$panelTable} WHERE dispute_id = %d LIMIT %d",
            $disputeId, BCC_DISPUTES_PANEL_SIZE
        ));

        foreach ($panelistIds as $uid) {
            self::bumpGeneration("panel_q_gen:{$uid}");
        }
    }

    /**
     * Batch-count active panel assignments for multiple users in a single query.
     *
     * Returns an associative array of user_id => active_count. Users with zero
     * active assignments are included with count 0.
     *
     * @param int[] $userIds
     * @return array<int, int>
     */
    public static function batchCountActivePanelAssignments(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $result = array_fill_keys($userIds, 0);

        global $wpdb;
        $panelTable   = self::panel_table();
        $disputeTable = self::disputes_table();

        $placeholders = implode(',', array_fill(0, count($userIds), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.panelist_user_id, COUNT(*) AS active_count
             FROM {$panelTable} p
             INNER JOIN {$disputeTable} d ON d.id = p.dispute_id
             WHERE p.panelist_user_id IN ({$placeholders})
               AND d.status = 'reviewing'
               AND p.decision IS NULL
             GROUP BY p.panelist_user_id",
            ...$userIds
        ));

        foreach ($rows as $row) {
            $result[(int) $row->panelist_user_id] = (int) $row->active_count;
        }

        return $result;
    }

    // ── User deletion cleanup ──────────────────────────────────────────────

    /**
     * Remove all dispute/report traces for a deleted user, atomically.
     *
     * - Drops panel assignments (they can no longer vote)
     * - Dismisses open disputes they filed
     * - Dismisses open reports filed by or against them
     *
     * Returns the list of dispute IDs whose caches must be invalidated
     * after commit so callers can bump generation counters outside the
     * transaction.
     *
     * @return array{affected_dispute_ids: int[], committed: bool}
     */
    public static function cleanupForDeletedUser(int $userId): array
    {
        global $wpdb;
        $disputes = self::disputes_table();
        $panel    = self::panel_table();
        $reports  = self::user_reports_table();

        // Collect affected dispute IDs BEFORE modifying rows so callers
        // can invalidate caches after the transaction commits.
        $affectedDisputeIds = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT dispute_id FROM {$panel} WHERE panelist_user_id = %d",
            $userId
        ));
        $reporterDisputeIds = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$disputes} WHERE reporter_id = %d AND status = 'reviewing'",
            $userId
        ));
        // Also capture disputes filed AGAINST this user (voter_id).
        // Without this, an adjudicator call at resolve time would try to
        // mutate trust scores for a deleted user — either fatalling or
        // leaving the dispute as a permanent orphan. Closing here keeps
        // the adjudicator boundary clean.
        $voterDisputeIds = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$disputes} WHERE voter_id = %d AND status = 'reviewing'",
            $userId
        ));
        $affectedDisputeIds = array_values(array_unique(array_map(
            'intval',
            array_merge($affectedDisputeIds, $reporterDisputeIds, $voterDisputeIds)
        )));

        try {
            if (!self::beginTx()) {
                throw new \RuntimeException(
                    '[bcc-disputes] START TRANSACTION failed in cleanupForDeletedUser: ' . (string) $wpdb->last_error
                );
            }

            // Decrement denormalised tallies BEFORE deleting the panel rows.
            // Otherwise bcc_disputes.panel_accepts / panel_rejects keep counting
            // votes from a panelist whose row no longer exists, and the admin /
            // panelist UI (which reads those denormalised columns via
            // DisputeDetailDTO / PanelistQueueItemDTO) shows stale tallies.
            // Verdict math already derives from panel rows (SUM in
            // getExpiredDisputes / getStuckReviewingDisputes / castPanelVoteAtomic)
            // so this fix is purely about keeping the display columns honest.
            //
            // GREATEST(0, …) is belt-and-suspenders: the tally is TINYINT UNSIGNED,
            // which would wrap to 255 on an underflow rather than clamping. We
            // should never underflow (every recorded decision increments exactly
            // one tally), but a prior partial-failure history could leave drift
            // that we don't want to amplify here.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$disputes} d
                 INNER JOIN {$panel} p ON p.dispute_id = d.id
                 SET
                   d.panel_accepts = GREATEST(0, CAST(d.panel_accepts AS SIGNED) - IF(p.decision = 'accept', 1, 0)),
                   d.panel_rejects = GREATEST(0, CAST(d.panel_rejects AS SIGNED) - IF(p.decision = 'reject', 1, 0))
                 WHERE p.panelist_user_id = %d
                   AND p.decision IS NOT NULL",
                $userId
            ));

            // Remove panel assignments for this user (they can no longer vote).
            // Active disputes with reduced panels still auto-resolve via TTL.
            $wpdb->delete($panel, ['panelist_user_id' => $userId], ['%d']);

            // Close any open disputes filed BY this user (reporter leaving).
            $wpdb->query($wpdb->prepare(
                "UPDATE {$disputes} SET status = 'dismissed', resolved_at = UTC_TIMESTAMP()
                 WHERE reporter_id = %d AND status = 'reviewing'",
                $userId
            ));

            // Close any open disputes filed AGAINST this user (target leaving).
            // Same reasoning as the voter_id select above — no resolve path
            // should ever call the adjudicator against a deleted user.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$disputes} SET status = 'dismissed', resolved_at = UTC_TIMESTAMP()
                 WHERE voter_id = %d AND status = 'reviewing'",
                $userId
            ));

            // Close reports filed by or against this user.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$reports} SET status = 'dismissed', reviewed_at = UTC_TIMESTAMP()
                 WHERE (reporter_id = %d OR reported_id = %d) AND status = 'open'",
                $userId, $userId
            ));

            if (!self::commitTx('cleanupForDeletedUser')) {
                // COMMIT failed → all cleanup writes were rolled back by MySQL.
                // Return committed=false so the caller does NOT invalidate
                // caches and a future retry path can re-run the cleanup.
                return ['affected_dispute_ids' => [], 'committed' => false];
            }
        } catch (\Throwable $e) {
            self::rollbackTx('cleanupForDeletedUser:exception');
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[bcc-disputes] delete_user_cleanup_failed', [
                    'user_id' => $userId,
                    'error'   => $e->getMessage(),
                ]);
            }
            return ['affected_dispute_ids' => [], 'committed' => false];
        }

        return ['affected_dispute_ids' => $affectedDisputeIds, 'committed' => true];
    }

    // ── Reconciliation helpers ──────────────────────────────────────────────

    /**
     * Atomically increment the reopen_count circuit breaker for a dispute.
     */
    public static function incrementReopenCount(int $disputeId): void
    {
        global $wpdb;
        $table = self::disputes_table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET reopen_count = reopen_count + 1 WHERE id = %d",
            $disputeId
        ));

        self::invalidateDispute($disputeId);
    }

    /**
     * Atomically mark adjudication as failed AND bump reopen_count in a
     * single UPDATE.
     *
     * The reconcile cron previously issued two separate updates
     * (setAdjudicationStatus → incrementReopenCount). If the first succeeded
     * but the second failed (DB error between them, deadlock on the reopen
     * counter), the circuit breaker counter never advanced and the dispute
     * could be re-picked on every reconcile tick forever.
     *
     * @return bool True on success, false on DB error (caller MUST retry).
     */
    public static function markAdjudicationFailedAndBumpReopen(int $disputeId): bool
    {
        global $wpdb;
        $table = self::disputes_table();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET adjudication_status = 'failed',
                 reopen_count        = reopen_count + 1
             WHERE id = %d",
            $disputeId
        ));

        if ($result === false) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] markAdjudicationFailedAndBumpReopen DB error', [
                'dispute_id' => $disputeId,
                'db_error'   => $wpdb->last_error,
            ]);
            return false;
        }

        self::invalidateDispute($disputeId);

        do_action('bcc_dispute_status_changed', $disputeId, 'failed');

        return true;
    }

    // ── Notification reconciliation ────────────────────────────────────────

    /**
     * Find panel rows for active disputes whose initial notification enqueue
     * never completed (notified_at IS NULL after the grace period).
     *
     * Two failure modes are caught by this query:
     *   (a) Action Scheduler / wp-cron enqueue returned a falsy value, so the
     *       notifyPanelist worker never ran.
     *   (b) The worker ran but wp_mail() failed — notifyPanelist only sets
     *       notified_at on a confirmed send, by design — so the email was
     *       never delivered.
     *
     * Cutoff excludes freshly-created disputes to give the initial enqueue
     * time to fire. Bounded LIMIT prevents a flood of stuck rows from
     * blowing up the reconcile tick.
     *
     * @return list<array{dispute_id:int, panelist_user_id:int, page_id:int}>
     */
    public static function getPendingPanelistNotifications(string $cutoff, int $limit = 20): array
    {
        global $wpdb;
        $disputeTable = self::disputes_table();
        $panelTable   = self::panel_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.dispute_id, p.panelist_user_id, d.page_id
             FROM {$panelTable} p
             INNER JOIN {$disputeTable} d ON d.id = p.dispute_id
             WHERE p.notified_at IS NULL
               AND d.status = 'reviewing'
               AND p.assigned_at <= %s
             ORDER BY p.assigned_at ASC
             LIMIT %d",
            $cutoff,
            $limit
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'dispute_id'       => (int) $row['dispute_id'],
                'panelist_user_id' => (int) $row['panelist_user_id'],
                'page_id'          => (int) $row['page_id'],
            ];
        }
        return $result;
    }

    /**
     * Find resolved disputes whose reporter-result email has not yet been
     * sent. Used by the reconciliation cron to catch gaps where the async
     * enqueue soft-failed OR wp_mail failed and Action Scheduler exhausted
     * retries.
     *
     * resolved_notified_at is the idempotency marker — markResolvedNotified
     * only flips it after a confirmed wp_mail send, so this query is safe to
     * run unbounded without re-delivery risk.
     *
     * Grace period (180s) avoids colliding with the original enqueue dispatch.
     * Only terminal statuses are picked up (accepted / rejected / timeout_no_quorum);
     * 'dismissed' disputes are deliberately excluded — those are cleanup events
     * triggered by user deletion, not user-visible resolutions.
     *
     * @return list<array{dispute_id:int, reporter_id:int, outcome:string}>
     */
    public static function getPendingReporterResultEmails(string $cutoff, int $limit = 20): array
    {
        global $wpdb;
        $table = self::disputes_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id AS dispute_id, reporter_id, status AS outcome
             FROM {$table}
             WHERE status IN ('accepted', 'rejected', 'timeout_no_quorum')
               AND adjudication_status = 'completed'
               AND resolved_notified_at IS NULL
               AND resolved_at <= %s
             ORDER BY resolved_at ASC
             LIMIT %d",
            $cutoff,
            $limit
        ), ARRAY_A);

        if (!is_array($rows)) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'dispute_id'  => (int) $row['dispute_id'],
                'reporter_id' => (int) $row['reporter_id'],
                'outcome'     => (string) $row['outcome'],
            ];
        }
        return $result;
    }

    // ── Notification idempotency ───────────────────────────────────────────

    /**
     * Atomically claim the right to send the panelist notification email.
     * Claim half of claim-before-send; see markResolvedNotified().
     */
    public static function markPanelistNotified(int $disputeId, int $panelistUserId, string $ts): bool
    {
        global $wpdb;
        $table = self::panel_table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET notified_at = %s
             WHERE dispute_id = %d AND panelist_user_id = %d AND notified_at IS NULL",
            $ts,
            $disputeId,
            $panelistUserId
        ));

        return $affected !== false && $affected > 0;
    }

    /**
     * Reset stuck panelist-notification claims so the standard sweep picks
     * them up again.
     *
     * The claim-before-send pattern (markPanelistNotified → wp_mail → on
     * failure clearPanelistNotified) relies on the try/finally running to
     * release the claim when wp_mail fails.  If the PHP worker dies
     * between the mark and the finally (OOM-killer, Action Scheduler
     * SIGKILL at timeout, memory_limit fatal inside wp_mail's hook
     * chain), the claim is stuck: notified_at is set, no email was ever
     * sent, and getPendingPanelistNotifications' "WHERE notified_at IS
     * NULL" filter will never pick it up again.  Panelist never votes →
     * quorum may fail → reporter loses even when evidence was valid.
     *
     * Sweep policy: reset notified_at to NULL where the claim is older
     * than $cutoff AND the panelist has NOT voted (decision IS NULL) AND
     * the dispute is still reviewing.  The "not voted" guard prevents
     * double-emailing a panelist who already acted on the original email.
     *
     * @return int Number of stuck claims released (0 on driver error).
     */
    public static function resetStuckPanelistClaims(string $cutoff): int
    {
        global $wpdb;
        $panelTable   = self::panel_table();
        $disputeTable = self::disputes_table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$panelTable} p
             INNER JOIN {$disputeTable} d ON d.id = p.dispute_id
             SET p.notified_at = NULL
             WHERE p.notified_at IS NOT NULL
               AND p.notified_at < %s
               AND p.decision IS NULL
               AND d.status = 'reviewing'",
            $cutoff
        ));

        return $affected === false ? 0 : (int) $affected;
    }

    /**
     * Reset stuck reporter-result email claims (same rationale as
     * resetStuckPanelistClaims but targets disputes.resolved_notified_at).
     *
     * Guard: only reset where the dispute has reached a terminal status
     * and adjudication_status = 'completed'.  This avoids resetting
     * in-flight claims that the notification worker may legitimately
     * still be processing.
     *
     * @return int Number of stuck claims released (0 on driver error).
     */
    public static function resetStuckReporterResultClaims(string $cutoff): int
    {
        global $wpdb;
        $table = self::disputes_table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET resolved_notified_at = NULL
             WHERE resolved_notified_at IS NOT NULL
               AND resolved_notified_at < %s
               AND status IN ('accepted', 'rejected', 'timeout_no_quorum')
               AND adjudication_status = 'completed'",
            $cutoff
        ));

        return $affected === false ? 0 : (int) $affected;
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

    /**
     * Release a tentative panelist claim when the send failed.
     * Scoped by $ts so only our own claim is cleared.
     */
    public static function clearPanelistNotified(int $disputeId, int $panelistUserId, string $ts): bool
    {
        global $wpdb;
        $table = self::panel_table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET notified_at = NULL
             WHERE dispute_id = %d AND panelist_user_id = %d AND notified_at = %s",
            $disputeId,
            $panelistUserId,
            $ts
        ));

        return $affected !== false && $affected > 0;
    }

    // ── Schema installation ──────────────────────────────────────────────────

    public static function install(): void
    {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $disputes = self::disputes_table();
        $panel    = self::panel_table();

        $sql = "
        CREATE TABLE {$disputes} (
            id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            vote_id         BIGINT UNSIGNED NOT NULL,
            page_id         BIGINT UNSIGNED NOT NULL,
            reporter_id     BIGINT UNSIGNED NOT NULL,
            voter_id        BIGINT UNSIGNED NOT NULL,
            reason          VARCHAR(1000)   NOT NULL DEFAULT '',
            evidence_url    VARCHAR(2083)            DEFAULT NULL,
            status          VARCHAR(20)     NOT NULL DEFAULT 'reviewing',
            adjudication_status VARCHAR(20) NOT NULL DEFAULT 'none',
            panel_accepts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
            panel_rejects   TINYINT UNSIGNED NOT NULL DEFAULT 0,
            panel_size      TINYINT UNSIGNED NOT NULL DEFAULT 5,
            created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reopen_count    TINYINT UNSIGNED NOT NULL DEFAULT 0,
            resolved_at              DATETIME DEFAULT NULL,
            resolved_notified_at     DATETIME DEFAULT NULL,
            resolution_enqueued_at   DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            INDEX idx_page   (page_id),
            INDEX idx_vote   (vote_id),
            INDEX idx_status (status),
            INDEX idx_reporter (reporter_id),
            INDEX idx_reporter_created (reporter_id, created_at),
            INDEX idx_status_created (status, created_at),
            INDEX idx_adjudication (adjudication_status),
            INDEX idx_reconcile (status, adjudication_status, resolved_at)
        ) {$charset};

        CREATE TABLE {$panel} (
            id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            dispute_id       BIGINT UNSIGNED NOT NULL,
            panelist_user_id BIGINT UNSIGNED NOT NULL,
            decision         VARCHAR(20)              DEFAULT NULL,
            note             VARCHAR(500)             DEFAULT NULL,
            assigned_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            voted_at         DATETIME                 DEFAULT NULL,
            notified_at      DATETIME                 DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_panelist_dispute (dispute_id, panelist_user_id),
            INDEX idx_dispute   (dispute_id),
            INDEX idx_panelist  (panelist_user_id),
            INDEX idx_panelist_decision (panelist_user_id, decision),
            INDEX idx_undecided (decision)
        ) {$charset};
        ";

        $reports = self::user_reports_table();

        $sql .= "
        CREATE TABLE {$reports} (
            id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reported_id       BIGINT UNSIGNED NOT NULL,
            reporter_id       BIGINT UNSIGNED NOT NULL,
            reason_key        VARCHAR(100)    NOT NULL DEFAULT '',
            reason_detail     VARCHAR(1000)   NOT NULL DEFAULT '',
            status            VARCHAR(20)     NOT NULL DEFAULT 'open',
            created_at        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
            reviewed_at       DATETIME                 DEFAULT NULL,
            notified_at       DATETIME                 DEFAULT NULL,
            admin_notified_at DATETIME                 DEFAULT NULL,
            PRIMARY KEY (id),
            INDEX idx_reported (reported_id),
            INDEX idx_reporter (reporter_id),
            INDEX idx_status   (status),
            INDEX idx_reporter_reported (reporter_id, reported_id)
        ) {$charset};
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // dbDelta() is most reliable with one CREATE TABLE per call.
        $statements = preg_split('/(?=CREATE TABLE)/i', $sql, -1, PREG_SPLIT_NO_EMPTY);
        if ($statements === false) {
            throw new \LogicException('DisputeRepository::install: preg_split failed — invalid pattern');
        }
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                dbDelta($statement);
            }
        }

        // Post-install migration: add DB-level unique constraint for active disputes.
        // MySQL doesn't support partial unique indexes, so we use a generated column
        // that is vote_id when status='reviewing' and NULL otherwise. UNIQUE indexes
        // in MySQL ignore NULL values, so only one 'reviewing' row per vote_id is allowed.
        self::migrateActiveDisputeConstraint();

        // Post-install migration: add admin_notified_at to user_reports for
        // DB-backed admin-email idempotency (replaces transient-based locking
        // which was prone to eviction and duplicate admin notifications).
        self::migrateAdminNotifiedAt();
    }

    /**
     * Add a generated column + unique index to enforce one active dispute per vote
     * at the database level. Complements the application-level FOR UPDATE check.
     *
     * The generated column `active_vote_lock` is:
     *   - vote_id when status = 'reviewing'  (enforces uniqueness)
     *   - NULL    otherwise                   (ignored by UNIQUE index)
     *
     * Migration failure is SURFACED via a persistent transient so admins see a
     * red notice until the underlying data issue (duplicate reviewing rows) is
     * resolved. Previously the error was only logged; operators had no way to
     * know the app-layer FOR UPDATE check was their sole remaining guarantee.
     */
    private static function migrateActiveDisputeConstraint(): void
    {
        global $wpdb;

        $table = self::disputes_table();

        // Check if column already exists (idempotent).
        $colExists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'active_vote_lock'",
            DB_NAME,
            $table
        ));

        if ((int) $colExists > 0) {
            // Already migrated — clear any prior failure marker.
            delete_option('bcc_disputes_constraint_missing');
            return;
        }

        // Add the generated column. IF() returns vote_id for 'reviewing' rows, NULL otherwise.
        // STORED so it can be indexed. The UNIQUE index ignores NULLs per SQL standard.
        $wpdb->query(
            "ALTER TABLE {$table}
             ADD COLUMN active_vote_lock BIGINT UNSIGNED
                 GENERATED ALWAYS AS (IF(status = 'reviewing', vote_id, NULL)) STORED,
             ADD UNIQUE KEY uq_active_vote (active_vote_lock)"
        );

        if ($wpdb->last_error) {
            $err = $wpdb->last_error;
            \BCC\Core\Log\Logger::error('[bcc-disputes] Failed to add active_vote_lock constraint', [
                'error' => $err,
            ]);
            // Persist failure marker for admin_notices (see warnIfConstraintMissing).
            // Autoloaded so the warn hook can cheaply check it every admin page.
            update_option('bcc_disputes_constraint_missing', $err, true);
            return;
        }

        delete_option('bcc_disputes_constraint_missing');
    }

    /**
     * Add admin_notified_at column to user_reports for DB-backed idempotency
     * of admin-report emails. Replaces the prior transient-based gate which
     * could be evicted under memory pressure, causing duplicate admin alerts.
     *
     * Idempotent: safe to run on every plugins_loaded version bump.
     */
    private static function migrateAdminNotifiedAt(): void
    {
        global $wpdb;

        $table = self::user_reports_table();

        $colExists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'admin_notified_at'",
            DB_NAME,
            $table
        ));

        if ((int) $colExists > 0) {
            return;
        }

        $wpdb->query("ALTER TABLE {$table} ADD COLUMN admin_notified_at DATETIME DEFAULT NULL AFTER notified_at");

        if ($wpdb->last_error) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] Failed to add admin_notified_at column', [
                'error' => $wpdb->last_error,
            ]);
            // Fail-loud: without this column, admin emails will duplicate on
            // every Action Scheduler retry (the idempotency gate becomes a
            // no-op since the SELECT returns NULL forever).
            update_option('bcc_disputes_admin_notified_missing', $wpdb->last_error, true);
        }
    }
}
