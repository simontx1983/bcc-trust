<?php
/**
 * CohortOverlapDampener — cluster-cartel dampener.
 *
 * Plan §9 critical-risk-mitigation item #4. Plan §6.3 contract:
 *   `weightFor(int $attestorUserId, string $targetKind, int $targetId): float`
 * Returns a multiplier that dampens A→B casts when A's prior cohort
 * overlaps substantially with B's existing attestor cohort.
 *
 * **The intent** (per risk-assessment §5 item 4):
 *
 * Reciprocity (PR-4) catches the pairwise A↔B back-scratch. Cohort
 * overlap catches the bigger case: a network cluster where everyone
 * has been attesting to everyone else for months. Even without any
 * A↔B reciprocal pair, three operators A, B, C with overlapping
 * past-cohort histories can stack each other's reputation. Without
 * this dampener the §J.4 diversity multiplier (+30% for independent
 * signal sources) would COMPOUND on a non-independent cluster — the
 * exact opposite of the design intent.
 *
 * **Overlap formula** (chosen for legibility — closed-network can
 * tune to Jaccard or weighted-overlap later):
 *
 *   attestor_cohort_size = COUNT(DISTINCT targets A has actively
 *                                cast on, target_kind='user_profile')
 *   intersection_size    = COUNT(DISTINCT users U such that
 *                                A → U is active AND U → target is active)
 *   overlap_ratio        = intersection_size / attestor_cohort_size
 *
 * In words: "what fraction of MY past attestation pool also attests
 * to THIS target?" When ≥50%, my judgment is too aligned with the
 * target's existing supporters to count as independent signal.
 *
 * **Threshold + multiplier** (§12 q7 — tunable):
 *
 *   overlap ≥ 50%  →  0.8× (dampened)
 *   overlap <  50% →  1.0× (no dampening)
 *
 * Single-step rather than graduated because closed-network testing
 * needs a clean A/B signal before refining the curve. The §6.3 plan
 * says "graduated" — promote to a 2-tier or interpolated curve here
 * when real-data calibration justifies it.
 *
 * **Edge cases (all → NO_DAMPENING):**
 *   - target_kind != 'user_profile' (cards have no attestor cohort)
 *   - attestor self-attesting (caught upstream; defensive here)
 *   - attestor's cohort is empty (first-ever cast; 0/0 undefined)
 *   - target's cohort is empty (first-ever attestation on target)
 *
 * Applied at cast-time, captured in `weight_at_time` per the
 * §J.4 ledger semantics. A later revocation that would change the
 * overlap calculation does NOT retroactively reweight this cast —
 * that's the snapshot guarantee.
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Trust Attestation Layer PR-7 (2026-05-14)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\AttestationRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class CohortOverlapDampener
{
    /**
     * §12 q7 — overlap threshold. Tunable; closed-network testing
     * retunes against actual graph density.
     */
    public const OVERLAP_THRESHOLD = 0.50;

    /**
     * §6.3 — dampened multiplier when threshold crossed. Picked at
     * 0.80 per plan; mirrors the §J.4 +30% diversity bonus we're
     * neutralizing (1.0 / 1.3 ≈ 0.77, rounded to 0.80 for legibility).
     */
    public const DAMPENED_MULTIPLIER = 0.80;

    /** No-dampening case — passed through unchanged into weight_at_time. */
    public const NO_DAMPENING = 1.00;

    public function __construct(
        private readonly AttestationRepository $repo
    ) {
    }

    /**
     * Resolve the cohort-overlap multiplier for the given cast.
     *
     * @param int    $attestorUserId The operator casting the new attestation.
     * @param string $targetKind     One of the §J.4 target_kinds.
     * @param int    $targetId       The target id.
     * @return float A multiplier in {DAMPENED_MULTIPLIER, NO_DAMPENING}.
     */
    public function weightFor(int $attestorUserId, string $targetKind, int $targetId): float
    {
        // Cards have no attestor cohort — overlap is structurally
        // undefined. Only user→user pairs are cohort-comparable.
        if ($targetKind !== 'user_profile') {
            return self::NO_DAMPENING;
        }
        if ($attestorUserId <= 0 || $targetId <= 0 || $attestorUserId === $targetId) {
            return self::NO_DAMPENING;
        }

        $attestorCohortSize = $this->repo->countDistinctTargetsByAttestor(
            $attestorUserId,
            'user_profile'
        );
        if ($attestorCohortSize <= 0) {
            // No prior attestations to overlap against — first-ever
            // cast can't be a cluster vector by construction.
            return self::NO_DAMPENING;
        }

        $intersectionSize = $this->repo->countOverlapBetweenAttestorAndTarget(
            $attestorUserId,
            $targetId
        );
        $overlapRatio = $intersectionSize / $attestorCohortSize;

        if ($overlapRatio >= self::OVERLAP_THRESHOLD) {
            return self::DAMPENED_MULTIPLIER;
        }
        return self::NO_DAMPENING;
    }
}
