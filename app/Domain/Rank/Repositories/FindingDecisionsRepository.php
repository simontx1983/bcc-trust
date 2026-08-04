<?php
/**
 * Finding Decisions Repository — owns `wp_bcc_trust_finding_decisions`,
 * the APPEND-ONLY decision history for misconduct findings (Rank
 * Phase 8; spec §15.5).
 *
 * Append + bounded read ONLY. There are deliberately NO update or
 * delete methods: §15.5 requires that no reconsideration ever edits a
 * prior decision — the original penalty snapshot survives in the
 * 'issued' row even after a reduction rewrites the finding's current
 * penalty column.
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
 * @phpstan-type FindingDecisionRow object{
 *     id: numeric-string,
 *     finding_id: numeric-string,
 *     decision: string,
 *     decided_by: numeric-string,
 *     reason: string,
 *     penalty_after: numeric-string|null,
 *     created_at: string
 * }
 */
class FindingDecisionsRepository
{
    /** A finding accrues at most a handful of decisions; hard read ceiling. */
    private const MAX_ROWS_PER_FINDING = 100;

    private const COLUMNS = 'id, finding_id, decision, decided_by, reason, penalty_after, created_at';

    private string $table;

    public function __construct()
    {
        $this->table = TableRegistry::findingDecisions();
    }

    /**
     * Append one decision row.
     *
     * @param string     $decision     'issued'|'upheld'|'reduced'|'reversed'|'remanded'
     * @param float|null $penaltyAfter The finding's penalty AFTER this
     *        decision ('issued' carries the original snapshot; 'reduced'
     *        the lowered value; others null).
     * @return int Decision row id, or 0 on failure.
     */
    public function append(
        int $findingId,
        string $decision,
        int $decidedBy,
        string $reason,
        ?float $penaltyAfter
    ): int {
        if ($findingId <= 0) {
            return 0;
        }

        global $wpdb;

        $result = $wpdb->insert(
            $this->table,
            [
                'finding_id'    => $findingId,
                'decision'      => $decision,
                'decided_by'    => $decidedBy,
                'reason'        => $reason,
                'penalty_after' => $penaltyAfter,
                'created_at'    => gmdate('Y-m-d H:i:s'),
            ],
            ['%d', '%s', '%d', '%s', '%f', '%s']
        );

        return $result === false ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Full decision history for one finding, oldest first, bounded.
     *
     * @return list<object>
     * @phpstan-return list<FindingDecisionRow>
     */
    public function listForFinding(int $findingId): array
    {
        if ($findingId <= 0) {
            return [];
        }

        global $wpdb;

        /** @var list<FindingDecisionRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT ' . self::COLUMNS . " FROM {$this->table}
              WHERE finding_id = %d
              ORDER BY id ASC
              LIMIT %d",
            $findingId,
            self::MAX_ROWS_PER_FINDING
        ));

        return $rows ?: [];
    }
}
