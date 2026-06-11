<?php

namespace BCC\Trust\Disputes\Repositories;

use BCC\Core\DTO\RowAssert;
use BCC\Trust\Disputes\Domain\DisputeStatus;
use BCC\Trust\Disputes\Domain\PanelDecision;
use BCC\Trust\Disputes\DTO\PanelEntryFullDTO;
use BCC\Trust\Disputes\DTO\PanelEntrySlimDTO;
use BCC\Trust\Disputes\DTO\PanelistQueueItemDTO;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Panel / panelist-entry queries: panelist queues, assignments, load
 * counts, and the panelist-notification idempotency claims
 * (bcc_dispute_panel.notified_at).
 *
 * Extracted verbatim from DisputeRepository (Phase 3.1 split). Core
 * dispute lifecycle mutations that touch the panel table inside a
 * transaction (createDisputeWithPanel, castPanelVoteAtomic) remain in
 * DisputeRepository — this class is the read/claim surface.
 *
 * Table accessors stay on DisputeRepository (the schema owner via
 * install()); this class consumes its public surface.
 */
class DisputePanelRepository
{
    /** Cache group for all dispute-related keys. */
    private const CACHE_GROUP = DisputeRepositorySupport::CACHE_GROUP;

    /** TTL for data that changes frequently (counts, active queues). */
    private const TTL_HOT = DisputeRepositorySupport::TTL_HOT;

    /**
     * Count active disputes assigned to a panelist.
     */
    public static function countPanelQueueForUser(int $userId): int
    {
        $gen      = DisputeRepositorySupport::getGeneration("panel_q_gen:{$userId}");
        $cacheKey = "panel_q_count:{$userId}:{$gen}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;
        $disputeTable = DisputeRepository::disputes_table();
        $panelTable   = DisputeRepository::panel_table();

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
        $gen      = DisputeRepositorySupport::getGeneration("panel_q_gen:{$userId}");
        $cacheKey = "panel_q:{$userId}:{$gen}:{$limit}:{$offset}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        $validated = DisputeRepositorySupport::validateCachedDtoList($cached, PanelistQueueItemDTO::class);
        if ($validated !== null) {
            return $validated;
        }

        global $wpdb;
        $disputeTable = DisputeRepository::disputes_table();
        $panelTable   = DisputeRepository::panel_table();

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
        $table = DisputeRepository::panel_table();

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
     * Get all panelists for a dispute with display names.
     *
     * @return list<PanelEntryFullDTO>
     */
    public static function getPanelistsForDispute(int $disputeId): array
    {
        global $wpdb;
        $table = DisputeRepository::panel_table();

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
        $panelTable   = DisputeRepository::panel_table();
        $disputeTable = DisputeRepository::disputes_table();

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
        $disputeTable = DisputeRepository::disputes_table();
        $panelTable   = DisputeRepository::panel_table();

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

    // ── Notification idempotency ───────────────────────────────────────────

    /**
     * Atomically claim the right to send the panelist notification email.
     * Claim half of claim-before-send; see markResolvedNotified().
     */
    public static function markPanelistNotified(int $disputeId, int $panelistUserId, string $ts): bool
    {
        global $wpdb;
        $table = DisputeRepository::panel_table();

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
        $panelTable   = DisputeRepository::panel_table();
        $disputeTable = DisputeRepository::disputes_table();

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
     * Release a tentative panelist claim when the send failed.
     * Scoped by $ts so only our own claim is cleared.
     */
    public static function clearPanelistNotified(int $disputeId, int $panelistUserId, string $ts): bool
    {
        global $wpdb;
        $table = DisputeRepository::panel_table();

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET notified_at = NULL
             WHERE dispute_id = %d AND panelist_user_id = %d AND notified_at = %s",
            $disputeId,
            $panelistUserId,
            $ts
        ));

        return $affected !== false && $affected > 0;
    }
}
