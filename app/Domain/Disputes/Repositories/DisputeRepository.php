<?php

namespace BCC\Trust\Disputes\Repositories;

use BCC\Core\DB\DB;
use BCC\Core\DTO\RowAssert;
use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Disputes\Domain\DisputeStatus;
use BCC\Trust\Disputes\DTO\DisputeCoreDTO;
use BCC\Trust\Disputes\DTO\DisputeDetailDTO;
use BCC\Trust\Disputes\DTO\DisputeResolutionCandidateDTO;
use BCC\Trust\Disputes\DTO\OrphanedDisputeDTO;

if (!defined('ABSPATH')) {
    exit;
}

class DisputeRepository
{
    /** Cache group for all dispute-related keys. */
    private const CACHE_GROUP = DisputeRepositorySupport::CACHE_GROUP;

    /** TTL for data that changes frequently (counts, active queues). */
    private const TTL_HOT = DisputeRepositorySupport::TTL_HOT;

    /** TTL for data that changes less often (individual dispute lookups). */
    private const TTL_WARM = DisputeRepositorySupport::TTL_WARM;

    public static function disputes_table(): string
    {
        return DB::table('disputes');
    }

    public static function panel_table(): string
    {
        return DB::table('dispute_panel');
    }

    // The §1.0 raw-transaction guards (beginTx / commitTx / rollbackTx)
    // live in DisputeRepositorySupport — shared with UserReportRepository
    // so every raw `START TRANSACTION` site in the dispute domain routes
    // through one audited implementation.

    // ── Delete-cascade (page deletion) ────────────────────────────────────

    /**
     * Hard-delete every dispute attached to a deleted page, cascading its
     * children FIRST so no panel / participation rows orphan.
     *
     * Order is load-bearing: bcc_dispute_panel + bcc_dispute_participations
     * are keyed on dispute_id with no FK ON DELETE CASCADE, so they MUST be
     * removed before their parent dispute rows. The whole cascade runs in
     * one transaction — a mid-cascade failure rolls back rather than leaving
     * a half-deleted dispute tree.
     *
     * Called from UserLifecycleService::onPageDelete (before_delete_post) so
     * a permanently-deleted page leaves no dispute residue. Disputes are
     * page-scoped via the disputes.page_id column.
     */
    public static function deleteForPage(int $pageId): void
    {
        if ($pageId <= 0) {
            return;
        }

        global $wpdb;
        $disputeTable       = self::disputes_table();
        $panelTable         = self::panel_table();
        $participationTable = TableRegistry::disputeParticipations();

        if (!DisputeRepositorySupport::beginTx()) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] START TRANSACTION failed in deleteForPage', [
                'page_id'  => $pageId,
                'db_error' => (string) $wpdb->last_error,
            ]);
            return;
        }

        try {
            // Resolve the dispute ids for this page once, then cascade
            // children by that id set. Bounded by BCC_DISPUTES_MAX_PER_PAGE-
            // class volume; LIMIT is belt-and-suspenders.
            $disputeIds = $wpdb->get_col($wpdb->prepare(
                "SELECT id FROM {$disputeTable} WHERE page_id = %d LIMIT 5000",
                $pageId
            ));
            $disputeIds = array_values(array_filter(
                array_map('intval', $disputeIds),
                static fn(int $id): bool => $id > 0
            ));

            if ($disputeIds !== []) {
                $placeholders = implode(',', array_fill(0, count($disputeIds), '%d'));

                // Children FIRST — panel rows then participation rows.
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$panelTable} WHERE dispute_id IN ({$placeholders})",
                    ...$disputeIds
                ));
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$participationTable} WHERE dispute_id IN ({$placeholders})",
                    ...$disputeIds
                ));
            }

            // Parent disputes LAST.
            $wpdb->delete($disputeTable, ['page_id' => $pageId], ['%d']);

            if (!DisputeRepositorySupport::commitTx('deleteForPage')) {
                \BCC\Core\Log\Logger::error('[bcc-disputes] COMMIT failed in deleteForPage', [
                    'page_id'  => $pageId,
                    'db_error' => (string) $wpdb->last_error,
                ]);
                return;
            }
        } catch (\Throwable $e) {
            DisputeRepositorySupport::rollbackTx('deleteForPage:exception');
            \BCC\Core\Log\Logger::error('[bcc-disputes] deleteForPage cascade failed', [
                'page_id' => $pageId,
                'error'   => $e->getMessage(),
            ]);
            return;
        }

        // Invalidate the status-counts cache so post-delete reads reflect
        // the empty state.
        wp_cache_delete('dispute_status_counts', self::CACHE_GROUP);
    }

    /**
     * Hard-delete every dispute-domain row a deleted user appears in,
     * cascading by user FK across the three user-keyed tables:
     *   - bcc_dispute_panel.panelist_user_id (the user sat on a panel)
     *   - bcc_dispute_participations.user_id (the user's credited votes)
     *   - bcc_user_reports.reporter_id / reported_id (both directions)
     *
     * Dispute ROWS themselves are NOT deleted here — a dispute is
     * page-scoped, not user-scoped, and its reporter_id/voter_id are
     * historical references that survive the user. The verdict math already
     * derives from the authoritative panel rows (getExpiredDisputes /
     * castPanelVoteAtomic re-derive tallies), so a deleted panelist's panel
     * row vanishing is exactly the drift those paths were built to tolerate.
     * Single transaction so the user's dispute footprint is removed atomically.
     *
     * Called from UserLifecycleService::onUserDelete (delete_user).
     */
    public static function deleteForUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        global $wpdb;
        $panelTable         = self::panel_table();
        $participationTable = TableRegistry::disputeParticipations();
        $reportsTable       = UserReportRepository::user_reports_table();

        if (!DisputeRepositorySupport::beginTx()) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] START TRANSACTION failed in deleteForUser', [
                'user_id'  => $userId,
                'db_error' => (string) $wpdb->last_error,
            ]);
            return;
        }

        try {
            $wpdb->delete($panelTable, ['panelist_user_id' => $userId], ['%d']);
            $wpdb->delete($participationTable, ['user_id' => $userId], ['%d']);
            // bcc_user_reports keys the user in BOTH directions — clean each.
            $wpdb->delete($reportsTable, ['reporter_id' => $userId], ['%d']);
            $wpdb->delete($reportsTable, ['reported_id' => $userId], ['%d']);

            if (!DisputeRepositorySupport::commitTx('deleteForUser')) {
                \BCC\Core\Log\Logger::error('[bcc-disputes] COMMIT failed in deleteForUser', [
                    'user_id'  => $userId,
                    'db_error' => (string) $wpdb->last_error,
                ]);
                return;
            }
        } catch (\Throwable $e) {
            DisputeRepositorySupport::rollbackTx('deleteForUser:exception');
            \BCC\Core\Log\Logger::error('[bcc-disputes] deleteForUser cascade failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return;
        }

        wp_cache_delete('dispute_status_counts', self::CACHE_GROUP);
        wp_cache_delete('report_status_counts', self::CACHE_GROUP);
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

    // Generation-counter cache helpers (getGeneration / bumpGeneration)
    // live in DisputeRepositorySupport — shared with DisputePanelRepository
    // so panel-queue keys and the invalidation bumps here stay in lockstep.

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
     * Check whether any active (reviewing) dispute exists against this
     * entity page. Page-scoped predicate for §J.8 `negative_signals.under_review`
     * + the V1 `DivergenceStateClassifier::STATE_DISPUTED` branch.
     *
     * Active state per the canonical enum is `'reviewing'` only —
     * accepted/rejected/dismissed/timeout_no_quorum are all terminal and
     * do NOT count. Mirrors `hasActiveDisputeForVote` shape but keys on
     * `page_id` instead of `vote_id` (votes are vote-scoped; the
     * negative-signal surface is page-scoped — a single page can carry
     * disputes across multiple votes).
     */
    public static function hasActiveDisputeForPage(int $pageId): bool
    {
        if ($pageId <= 0) {
            return false;
        }

        global $wpdb;
        $table = self::disputes_table();

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE page_id = %d AND status = 'reviewing' LIMIT 1",
            $pageId
        ));

        return (bool) $existing;
    }

    /**
     * Distinct `page_id`s with any dispute activity (created OR
     * status-changed) since the given UTC timestamp. Feeds
     * `PolarizationTransitionNotifier::sweep()` candidate set so the
     * daily worker re-classifies entities whose dispute state may
     * have flipped.
     *
     * Bounded by LIMIT (5000 cap; daily dispute volume is realistically
     * single digits to low double digits). Distinct page_ids only — a
     * single page can have multiple dispute rows over time; the
     * classifier handles that via `hasActiveDisputeForPage`.
     *
     * @param string $sinceMysqlUtc UTC datetime "YYYY-MM-DD HH:MM:SS".
     * @return list<int>
     */
    public static function listPagesWithRecentDisputeActivity(string $sinceMysqlUtc): array
    {
        if ($sinceMysqlUtc === '') {
            return [];
        }

        global $wpdb;
        $table = self::disputes_table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT page_id FROM {$table}
              WHERE created_at >= %s OR resolved_at >= %s
              LIMIT 5000",
            $sinceMysqlUtc,
            $sinceMysqlUtc
        ));
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->page_id)) {
                continue;
            }
            $pageId = (int) $row->page_id;
            if ($pageId > 0) {
                $out[] = $pageId;
            }
        }
        return $out;
    }

    /**
     * Count active (reviewing) disputes against this entity page. Feeds
     * `negative_signals.unresolved_claims_count` on the §J.6 view-model.
     * Returns 0 for missing/invalid page IDs. Bounded scan via the
     * existing (page_id, status) index.
     */
    public static function countActiveDisputesForPage(int $pageId): int
    {
        if ($pageId <= 0) {
            return 0;
        }

        global $wpdb;
        $table = self::disputes_table();

        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE page_id = %d AND status = 'reviewing' LIMIT 5000",
            $pageId
        ));

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Grouped variant of countActiveDisputesForPage — one bounded
     * GROUP BY query for a whole cards-list page. Same `status =
     * 'reviewing'` predicate as both per-page methods (verified:
     * hasActiveDisputeForPage and countActiveDisputesForPage use the
     * identical active-state definition, so `has_active ≡ count > 0`
     * and consumers derive both signals from this single map).
     *
     * Pages with zero active disputes are absent from the map —
     * consumers default with `?? 0`.
     *
     * Bounded: caller-paginated IN-list (cards per_page ≤ 50), one
     * row per page via GROUP BY on the (page_id, status) index.
     *
     * @param list<int> $pageIds
     * @return array<int, int> page_id => active (reviewing) dispute count.
     */
    public static function countActiveDisputesForPages(array $pageIds): array
    {
        $ids = [];
        foreach ($pageIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        global $wpdb;
        $table = self::disputes_table();
        $ph    = implode(',', array_fill(0, count($ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT page_id, COUNT(*) AS c FROM {$table}
              WHERE page_id IN ({$ph}) AND status = 'reviewing'
              GROUP BY page_id
              LIMIT 100",
            ...array_keys($ids)
        ));
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->page_id, $row->c)) {
                continue;
            }
            $pageId = (int) $row->page_id;
            if ($pageId > 0) {
                $out[$pageId] = (int) $row->c;
            }
        }
        return $out;
    }

    /**
     * Terminal (resolved) disputes for a set of pages, grouped by page_id —
     * the dispute-outcome input for the §J.3.2.1 Operator Reliability
     * classifier (Slice 2). Batched (one bounded IN-list query for a whole
     * attestor's target set) so the classifier never N+1s the disputes table.
     *
     * "Terminal" = resolved_at IS NOT NULL. The status carried back is the raw
     * column value (one of accepted / rejected / dismissed / timeout_no_quorum
     * — never 'reviewing', whose resolved_at is NULL). The classifier maps
     * direction itself (the load-bearing `rejected` ⇒ the target's negative
     * mark was UPHELD ⇒ a NEGATIVE outcome for whoever backed them; accepted /
     * dismissed / timeout ⇒ the target was VINDICATED).
     *
     * Bounded: caller-paginated IN-list + a defensive LIMIT (a page's lifetime
     * dispute count is realistically single digits — BCC_DISPUTES_MAX_PER_PAGE
     * per 30-day window).
     *
     * @param list<int> $pageIds
     * @return array<int, list<object{status: string, resolved_at: string}>>
     *     Keyed by page_id; pages with no terminal disputes are absent.
     */
    public static function listResolvedForPages(array $pageIds): array
    {
        $ids = [];
        foreach ($pageIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        global $wpdb;
        $table = self::disputes_table();
        $ph    = implode(',', array_fill(0, count($ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT page_id, status, resolved_at FROM {$table}
              WHERE page_id IN ({$ph}) AND resolved_at IS NOT NULL
              LIMIT 5000",
            ...array_keys($ids)
        ));
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->page_id, $row->status, $row->resolved_at)) {
                continue;
            }
            $pageId = (int) $row->page_id;
            if ($pageId <= 0) {
                continue;
            }
            $out[$pageId][] = (object) [
                'status'      => (string) $row->status,
                'resolved_at' => (string) $row->resolved_at,
            ];
        }
        return $out;
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

        if (!DisputeRepositorySupport::beginTx()) {
            \BCC\Core\Log\Logger::error('[bcc-disputes] START TRANSACTION failed in createDisputeWithPanel', [
                'db_error' => (string) $wpdb->last_error,
            ]);
            return ['id' => null, 'failed_panelist' => null, 'db_error' => 'tx_begin_failed'];
        }

        // Atomic dispute limit check: count recent disputes FOR UPDATE to
        // prevent race where two concurrent requests both read count=2.
        $pageId = (int) $disputeData['page_id'];
        // UTC_TIMESTAMP() (not NOW()): created_at is now written in UTC on
        // insert (see below), so this 30-day window must compare on the
        // same clock. NOW() is the MySQL session tz (time_zone=SYSTEM),
        // which skews the window by the server's UTC offset.
        $recentCount = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$disputeTable}
             WHERE page_id = %d
               AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
             FOR UPDATE",
            $pageId
        ));
        if ($recentCount >= BCC_DISPUTES_MAX_PER_PAGE) {
            DisputeRepositorySupport::rollbackTx('createDisputeWithPanel:dispute_limit_reached');
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
            DisputeRepositorySupport::rollbackTx('createDisputeWithPanel:reporter_limit_reached');
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
             LIMIT 1
             FOR UPDATE",
            $voteId
        ));
        if ($existingForVote) {
            DisputeRepositorySupport::rollbackTx('createDisputeWithPanel:already_disputed');
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
            DisputeRepositorySupport::rollbackTx('createDisputeWithPanel:vote_no_longer_active');
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
                DisputeRepositorySupport::rollbackTx('createDisputeWithPanel:insufficient_panelists');
                return ['id' => null, 'failed_panelist' => null, 'db_error' => 'insufficient_panelists'];
            }
        }

        // created_at written explicitly in UTC. The column DEFAULT is
        // CURRENT_TIMESTAMP (MySQL session tz, time_zone=SYSTEM), but every
        // reader compares it against UTC cutoffs — the 30-day rate window
        // above, the 7-day auto-resolve TTL (getExpiredDisputes), recent-
        // activity, and reporter-rate reads. Writing UTC here puts the
        // column on the one clock they all use. Mirrors
        // UserReportRepository's created_at handling.
        $wpdb->insert($disputeTable, [
            'vote_id'      => $disputeData['vote_id'],
            'page_id'      => $disputeData['page_id'],
            'reporter_id'  => $disputeData['reporter_id'],
            'voter_id'     => $disputeData['voter_id'],
            'reason'       => $disputeData['reason'],
            'evidence_url' => $disputeData['evidence_url'],
            'status'       => $disputeData['status'],
            'panel_size'   => $disputeData['panel_size'],
            'created_at'   => gmdate('Y-m-d H:i:s'),
        ], ['%d', '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%s']);

        $dispute_id = $wpdb->insert_id;

        if (!$dispute_id) {
            // Capture last_error BEFORE the ROLLBACK — a subsequent $wpdb->query()
            // clears it, which previously meant production logs contained empty
            // db_error strings. Preserve the original INSERT failure reason.
            $insertError = (string) $wpdb->last_error;
            if ($insertError !== '' && stripos($insertError, 'Duplicate entry') !== false) {
                DisputeRepositorySupport::rollbackTx('createDisputeWithPanel:duplicate_entry');
                return ['id' => null, 'failed_panelist' => null, 'db_error' => 'already_disputed'];
            }
            DisputeRepositorySupport::rollbackTx('createDisputeWithPanel:insert_failed');
            return ['id' => null, 'failed_panelist' => null, 'db_error' => $insertError];
        }

        foreach ($panelistIds as $uid) {
            $wpdb->insert($panelTable, [
                'dispute_id'       => $dispute_id,
                'panelist_user_id' => $uid,
            ], ['%d', '%d']);

            if ($wpdb->last_error) {
                $error = $wpdb->last_error;
                DisputeRepositorySupport::rollbackTx('createDisputeWithPanel:panel_insert_failed');
                return ['id' => null, 'failed_panelist' => $uid, 'db_error' => $error];
            }
        }

        if (!DisputeRepositorySupport::commitTx('createDisputeWithPanel')) {
            // COMMIT failed → MySQL rolled back.  Returning here with
            // db_error='commit_failed' lets the controller surface a 5xx and
            // the reporter retry, instead of returning a dead insert_id.
            return ['id' => null, 'failed_panelist' => null, 'db_error' => 'commit_failed'];
        }

        // Invalidate: each panelist's queue cache, reporter's dispute list, status counts.
        foreach ($panelistIds as $uid) {
            DisputeRepositorySupport::bumpGeneration("panel_q_gen:{$uid}");
        }
        DisputeRepositorySupport::bumpGeneration("reporter_gen:{$disputeData['reporter_id']}");
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
        $gen      = DisputeRepositorySupport::getGeneration("reporter_gen:{$userId}");
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
     * Batched lifetime dispute-filed count per reporter. One bounded
     * GROUP BY replaces N sequential countByReporter() calls for
     * list-shape consumers (member directory / feed cold-start
     * `disputes_signed`). Reporters with no filed disputes are absent
     * from the map; callers default to 0.
     *
     * Replaces the retired FlagsRepository::countByFlaggers. A member's
     * filed disputes key on `reporter_id` (= their user id — they own
     * the self-page being defended), so the map is user-id keyed
     * directly. Uncached (bounded IN aggregate; the batched consumers
     * are already prefetch-bounded).
     *
     * @param int[] $userIds Bounded by caller.
     * @return array<int, int> reporter_id => count
     */
    public static function countByReporters(array $userIds): array
    {
        $clean = [];
        foreach ($userIds as $id) {
            $intVal = (int) $id;
            if ($intVal > 0) {
                $clean[$intVal] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $idList = array_keys($clean);

        global $wpdb;
        $table = self::disputes_table();
        $placeholders = implode(',', array_fill(0, count($idList), '%d'));

        /** @var list<array{reporter_id: string, c: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT reporter_id, COUNT(*) AS c
                   FROM {$table}
                  WHERE reporter_id IN ({$placeholders})
                  GROUP BY reporter_id",
                ...$idList
            ),
            ARRAY_A
        );

        $out = [];
        foreach (($rows ?: []) as $row) {
            $out[(int) $row['reporter_id']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Count disputes UPHELD against this page that resolved within a trailing
     * window. The §J.12 elite gate's clean-record condition.
     *
     * POLARITY — read before touching this query. "Upheld" is not a status
     * value; it is DisputeStatus::REJECTED. A dispute contests a negative mark
     * against a page, so a REJECTED dispute means the contest FAILED and the
     * negative mark STANDS — bad for the page. ACCEPTED, DISMISSED and
     * TIMEOUT_NO_QUORUM all mean the page was vindicated. Filtering on
     * 'accepted' here, or inventing an 'upheld' literal, inverts the gate and
     * blocks exactly the wrong population. Canonical statement of this
     * direction: AttestationOutcomeClassifier::disputeOutcomeFor().
     *
     * SUBJECT — keyed on page_id, the DISPUTED page. Note that
     * DisputeAdjudicator::rejectVoteDispute applies its -5 penalty to the
     * REPORTER's self-page, which is a different subject entirely; do not
     * reuse that path's identifier here.
     *
     * Uncached: a moving `since` boundary makes caching counterproductive,
     * same rationale as countByReporterSince.
     *
     * @param string $sinceMysqlUtc UTC 'Y-m-d H:i:s' window start.
     */
    public static function countUpheldSince(int $pageId, string $sinceMysqlUtc): int
    {
        if ($pageId <= 0 || $sinceMysqlUtc === '') {
            return 0;
        }

        global $wpdb;
        $table = self::disputes_table();

        $raw = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE page_id = %d
                AND status = %s
                AND resolved_at IS NOT NULL
                AND resolved_at >= %s
              LIMIT 5000",
            $pageId,
            DisputeStatus::REJECTED,
            $sinceMysqlUtc
        ));

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Count disputes filed by this reporter since a MySQL DATETIME
     * boundary. Powers the §O3 living-header today-line ("today: 1
     * dispute opened") and the watching summary. Replaces the retired
     * FlagsRepository::countByFlaggerSince. Uncached — a moving `since`
     * boundary makes caching counterproductive; aggregate COUNT is cheap.
     */
    public static function countByReporterSince(int $userId, string $sinceMysql): int
    {
        if ($userId <= 0 || $sinceMysql === '') {
            return 0;
        }

        global $wpdb;
        $table = self::disputes_table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE reporter_id = %d
                AND created_at >= %s",
            $userId,
            $sinceMysql
        ));
    }

    /**
     * Paginated disputes filed by a user, with post/user display names.
     *
     * @return list<DisputeDetailDTO>
     */
    public static function getByReporterPaginated(int $userId, int $limit, int $offset, ?int $pageId = null): array
    {
        $pageSuffix = $pageId !== null ? ":{$pageId}" : '';
        $gen      = DisputeRepositorySupport::getGeneration("reporter_gen:{$userId}");
        $cacheKey = "reporter:{$userId}:{$gen}:{$limit}:{$offset}{$pageSuffix}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        $validated = DisputeRepositorySupport::validateCachedDtoList($cached, DisputeDetailDTO::class);
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
                $dtos[] = DisputeRepositorySupport::hydrateDisputeDetail($row);
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

        if (!DisputeRepositorySupport::beginTx()) {
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
            DisputeRepositorySupport::rollbackTx('castPanelVoteAtomic:dispute_closed');
            return ['status' => 'error', 'code' => 'dispute_closed', 'message' => 'This dispute is no longer open.', 'http' => 410, 'dispute' => null, 'accepts' => 0, 'rejects' => 0];
        }

        // Hydrate + validate inside the transaction. If DB corruption is present,
        // hydrateDisputeCore throws — ROLLBACK keeps the transaction safe.
        try {
            $dispute = self::hydrateDisputeCore($lockedRow);
        } catch (\LogicException $e) {
            DisputeRepositorySupport::rollbackTx('castPanelVoteAtomic:hydrate_failed');
            throw $e;
        }

        if ($dispute->status !== DisputeStatus::REVIEWING) {
            DisputeRepositorySupport::rollbackTx('castPanelVoteAtomic:dispute_closed');
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
            DisputeRepositorySupport::rollbackTx('castPanelVoteAtomic:vote_update_failed');
            return ['status' => 'error', 'code' => 'db_error', 'message' => 'Failed to record vote.', 'http' => 500, 'step' => 'panel_vote_update', 'dispute' => null, 'accepts' => 0, 'rejects' => 0, 'db_error' => $voteError];
        }
        if ($voted === 0) {
            DisputeRepositorySupport::rollbackTx('castPanelVoteAtomic:already_voted');
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
            DisputeRepositorySupport::rollbackTx('castPanelVoteAtomic:tally_update_failed');
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
            DisputeRepositorySupport::rollbackTx('castPanelVoteAtomic:row_vanished');
            throw new \LogicException("Dispute {$disputeId} vanished inside its own transaction");
        }

        try {
            $dispute = self::hydrateDisputeCore($updatedRow);
        } catch (\LogicException $e) {
            DisputeRepositorySupport::rollbackTx('castPanelVoteAtomic:rehydrate_failed');
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

        if (!DisputeRepositorySupport::commitTx('castPanelVoteAtomic')) {
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
     * Release a resolution-enqueue claim that never produced a queued job.
     *
     * Counterpart to claimResolutionEnqueue: when the caller wins the claim but
     * the Action Scheduler enqueue then fails, the claim must be released or the
     * dispute becomes un-retryable — a retry re-hits the `IS NULL` guard and
     * 409s until the TTL auto-resolve path or a manual clear (up to the full
     * dispute TTL, since sub-majority admin-forced disputes are ignored by
     * retryStuckReviewingDisputes). Guarded on status='reviewing' so it can
     * never disturb a dispute that has since progressed; safe because the caller
     * owns the claim it just won. [audit L-B5]
     */
    public static function releaseResolutionEnqueue(int $disputeId): bool
    {
        global $wpdb;
        $table = self::disputes_table();

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET resolution_enqueued_at = NULL
              WHERE id = %d
                AND status = 'reviewing'",
            $disputeId
        ));

        return $result !== false;
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

        if (!DisputeRepositorySupport::beginTx()) {
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
            DisputeRepositorySupport::rollbackTx('beginResolveTransaction:update_failed');
            return ['success' => false, 'affected_rows' => 0, 'db_error' => $updateError, 'race' => false];
        }

        if ($result === 0) {
            DisputeRepositorySupport::rollbackTx('beginResolveTransaction:race');
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
        return DisputeRepositorySupport::commitTx('resolve_commit');
    }

    /**
     * Rollback the current open transaction.
     */
    public static function rollbackTransaction(): void
    {
        DisputeRepositorySupport::rollbackTx('resolve_rollback');
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
            DisputeRepositorySupport::bumpGeneration("reporter_gen:{$dispute->reporter_id}");
            if (!empty($dispute->vote_id)) {
                wp_cache_delete("disputed_vote:{$dispute->vote_id}", self::CACHE_GROUP);
            }
        }

        $panelistIds = $wpdb->get_col($wpdb->prepare(
            "SELECT panelist_user_id FROM {$panelTable} WHERE dispute_id = %d LIMIT %d",
            $disputeId, BCC_DISPUTES_PANEL_SIZE
        ));

        foreach ($panelistIds as $uid) {
            DisputeRepositorySupport::bumpGeneration("panel_q_gen:{$uid}");
        }
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
        $reports  = UserReportRepository::user_reports_table();

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
            if (!DisputeRepositorySupport::beginTx()) {
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

            if (!DisputeRepositorySupport::commitTx('cleanupForDeletedUser')) {
                // COMMIT failed → all cleanup writes were rolled back by MySQL.
                // Return committed=false so the caller does NOT invalidate
                // caches and a future retry path can re-run the cleanup.
                return ['affected_dispute_ids' => [], 'committed' => false];
            }
        } catch (\Throwable $e) {
            DisputeRepositorySupport::rollbackTx('cleanupForDeletedUser:exception');
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

        $reports = UserReportRepository::user_reports_table();

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
            // NOT autoloaded — the warn hook only get_option()s it on admin
            // pages, where one cheap keyed query beats carrying the row in
            // the alloptions blob on every front-end request.
            update_option('bcc_disputes_constraint_missing', $err, false);
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

        $table = UserReportRepository::user_reports_table();

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
            // no-op since the SELECT returns NULL forever). NOT autoloaded —
            // read only by the admin_notices hook (one cheap keyed query).
            update_option('bcc_disputes_admin_notified_missing', $wpdb->last_error, false);
        }
    }
}
