<?php
/**
 * IndependenceResolver — answers "does this member's Rank evidence
 * count, and who is their cluster's one voice?" from FORMAL findings
 * (Rank Phase 4, shadow; approved plan R3/D-5).
 *
 * Reads `wp_bcc_trust_cluster_findings` only. Detection is NOT here:
 * the heuristic signals (TrustGraph rings, FraudDetector snapshots,
 * CohortOverlapDampener cohorts) may eventually feed a Phase 6
 * pipeline that PROPOSES findings with ⚖-calibrated thresholds —
 * environmental evidence alone can never confirm (R3 taxonomy), and
 * nothing is zero-credited without a formal, auditable, reversible
 * determination row.
 *
 * Per-request memo: the ingestor consults this on every evidence hook,
 * and findings change rarely.
 *
 * @package BCC\Trust\Rank\Services
 * @since Rank redesign Phase 4 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Services;

use BCC\Trust\Rank\Repositories\ClusterFindingsRepository;

if (!defined('ABSPATH')) {
    exit;
}

class IndependenceResolver
{
    /**
     * user_id → representative_user_id for every member of an active
     * CONFIRMED finding. Null until built (per request).
     *
     * @var array<int, int>|null
     */
    private static ?array $memberToRepresentative = null;

    public function __construct(
        private readonly ClusterFindingsRepository $findings
    ) {
    }

    /**
     * TRUE when the member sits in an active confirmed cluster and is
     * NOT its representative — their evidence records with
     * capped_value 0 (raw preserved for audit; reversal restores via
     * recalculation).
     */
    public function isZeroCredit(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        $map = $this->map();

        return isset($map[$userId]) && $map[$userId] !== $userId;
    }

    /**
     * The one counted voice for this member's cluster — the member
     * themself when independent (no active confirmed finding).
     */
    public function representativeOf(int $userId): int
    {
        $map = $this->map();

        return $map[$userId] ?? $userId;
    }

    /**
     * The full member→representative map for active confirmed findings
     * — RankScoreCalculator's cluster-collapse input.
     *
     * @return array<int, int>
     */
    public function activeMap(): array
    {
        return $this->map();
    }

    /** @return array<int, int> */
    private function map(): array
    {
        if (self::$memberToRepresentative !== null) {
            return self::$memberToRepresentative;
        }

        $map = [];
        foreach ($this->findings->listActiveConfirmed() as $finding) {
            $members = json_decode((string) $finding->member_user_ids, true);
            if (!is_array($members)) {
                continue;
            }
            $representative = (int) $finding->representative_user_id;
            foreach ($members as $member) {
                $map[(int) $member] = $representative;
            }
        }

        return self::$memberToRepresentative = $map;
    }
}
