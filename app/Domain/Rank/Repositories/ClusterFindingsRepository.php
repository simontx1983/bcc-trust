<?php
/**
 * Cluster Findings Repository — owns `wp_bcc_trust_cluster_findings`,
 * the FORMAL non-independence determination store (Rank Phase 4, R3).
 *
 * Distinct from the heuristic SIGNAL stores (`bcc_trust_patterns` ring
 * detections, `bcc_trust_fraud_analysis` snapshots) — see the schema
 * header for the boundary. Findings are created by explicit decision
 * (admin, or the future Phase 6 detection pipeline's confirmed output),
 * are auditable, and reverse by status flip.
 *
 * Representative selection (D-5): default = oldest account creation
 * among members (tie → lowest user_id), recorded as
 * 'auto_oldest_account'; an explicit admin choice records
 * 'admin_override'. Whichever member is chosen, the cluster's total
 * influence equals ONE member's legitimate standing — selection is an
 * audit/fairness knob, not a security boundary.
 *
 * @package BCC\Trust\Rank\Repositories
 * @since Rank redesign Phase 4 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Repositories;

use BCC\Trust\Core\Database\TableRegistry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type ClusterFindingRow object{
 *     id: numeric-string,
 *     level: string,
 *     member_user_ids: string,
 *     representative_user_id: numeric-string,
 *     selection_method: string,
 *     effective_at: string
 * }
 */
class ClusterFindingsRepository
{
    private const COLUMNS_READ = 'id, level, member_user_ids, representative_user_id, selection_method, effective_at';

    /** Formal findings are rare by construction; hard read ceiling. */
    private const MAX_ACTIVE_FINDINGS = 200;

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::clusterFindings();
    }

    /**
     * Record a formal finding. When $representativeUserId is 0 the D-5
     * default selects the member with the OLDEST account registration
     * (tie → lowest user_id) and records 'auto_oldest_account';
     * a non-zero value records 'admin_override'.
     *
     * @param 'suspected'|'confirmed' $level
     * @param list<int> $memberUserIds
     * @param array<string, mixed> $evidenceRefs
     * @return int Finding id, or 0 on failure.
     */
    public function create(
        string $level,
        array $memberUserIds,
        int $representativeUserId,
        array $evidenceRefs,
        float $confidence,
        int $decidedByUserId
    ): int {
        $members = [];
        foreach ($memberUserIds as $id) {
            $iid = (int) $id;
            if ($iid > 0) {
                $members[$iid] = true;
            }
        }
        if (count($members) < 2 || !in_array($level, ['suspected', 'confirmed'], true)) {
            return 0;
        }
        $memberIds = array_keys($members);

        $selectionMethod = 'admin_override';
        if ($representativeUserId <= 0) {
            $representativeUserId = $this->oldestAccountOf($memberIds);
            $selectionMethod      = 'auto_oldest_account';
        }
        if (!in_array($representativeUserId, $memberIds, true)) {
            return 0; // Representative must be a member.
        }

        global $wpdb;
        $now = current_time('mysql', true);

        $result = $wpdb->insert(
            $this->table,
            [
                'level'                  => $level,
                'member_user_ids'        => (string) wp_json_encode($memberIds),
                'representative_user_id' => $representativeUserId,
                'selection_method'       => $selectionMethod,
                'evidence_refs'          => (string) wp_json_encode($evidenceRefs),
                'confidence'             => $confidence,
                'decided_by'             => $decidedByUserId,
                'decided_at'             => $now,
                'effective_at'           => $now,
            ],
            ['%s', '%s', '%d', '%s', '%s', '%f', '%d', '%s', '%s']
        );

        return $result === false ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Reverse a finding (false positive / appeal). Evidence restoration
     * happens via ledger recalculation, not here.
     */
    public function reverse(int $findingId, string $reason): bool
    {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
                SET reversed_at = %s, reversal_reason = %s
              WHERE id = %d AND reversed_at IS NULL",
            current_time('mysql', true),
            substr($reason, 0, 160),
            $findingId
        ));

        return $result !== false && (int) $result > 0;
    }

    /**
     * All active CONFIRMED findings (bounded). Membership filtering is
     * PHP-side over the JSON member list — findings are rare, and this
     * read backs a per-request memo in IndependenceResolver.
     *
     * @return list<object>
     * @phpstan-return list<ClusterFindingRow>
     */
    public function listActiveConfirmed(): array
    {
        global $wpdb;

        /** @var list<ClusterFindingRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS_READ . " FROM {$this->table}
              WHERE reversed_at IS NULL AND level = 'confirmed'
              ORDER BY id ASC
              LIMIT %d",
            self::MAX_ACTIVE_FINDINGS
        ));

        return $rows ?: [];
    }

    /**
     * D-5 auto-selection: oldest registration wins, ties break to the
     * lowest user_id. Bounded IN over the member list.
     *
     * @param list<int> $memberIds
     */
    private function oldestAccountOf(array $memberIds): int
    {
        if ($memberIds === []) {
            return 0;
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($memberIds), '%d'));

        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users}
              WHERE ID IN ({$placeholders})
              ORDER BY user_registered ASC, ID ASC
              LIMIT 1",
            ...$memberIds
        ));

        return $id !== null ? (int) $id : (int) min($memberIds);
    }
}
