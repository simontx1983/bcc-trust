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
     * ⚠⚠ WAS 0.80. NOW BASELINE — COHORT DENSITY IS NOT PENALISED.
     *
     * The old value was documented as "mirrors the §J.4 +30% diversity
     * bonus we're neutralizing (1.0 / 1.3 ≈ 0.77, rounded to 0.80)". Two
     * things were wrong with that in practice.
     *
     * 1. ⚠ IT NEUTRALIZED NOTHING IN THE CASES IT HIT HARDEST. The 1.3x
     *    diversity bonus lives in AttestationScoreSynthesis and is gated on
     *    the TARGET having at least BCC_ATTEST_DIVERSITY_MIN_SOURCES
     *    distinct sources. This multiplier was applied unconditionally at
     *    CAST time. So where the bonus did not apply — a target with only
     *    one or two backers — the 0.80 was not an offset, it was a flat 20%
     *    penalty with nothing to offset. Measured: two casts at 0.80 on a
     *    two-source target synthesise to 1.60 instead of 2.00.
     *
     * 2. ⚠ WHAT IT MEASURES IS COMMUNITY DENSITY, NOT INDEPENDENCE OF
     *    JUDGMENT, and it has no input that could measure the latter.
     *    Every ordinary close-knit shape was dampened by isolated fixture:
     *
     *      6-person hall, everyone has used everyone   ratio 0.80  x0.80
     *      family business, 3 relatives + a new member ratio 1.00  x0.80
     *      trade crew of 4 backing the gaffer          ratio 1.00  x0.80
     *      specialist, all 4 prior in one discipline   ratio 1.00  x0.80
     *      5 sock puppets backing each other           ratio 0.80  x0.80
     *
     *    The sock puppets scored the SAME as the hall.
     *
     * 3. ⚠⚠ AND IT WAS TRIVIAL TO EVADE. The denominator is the ATTESTOR'S
     *    OWN cohort size, so a ring that pads its cohort with unrelated
     *    targets dilutes the ratio away: 3 overlapping of 24 prior = 0.13,
     *    NOT dampened. Meanwhile an honest member's SECOND-EVER vouch, with
     *    one prior target who happens to back the same person, is ratio
     *    1.00 and dampened. That is the inverse of the risk profile.
     *
     * ⚠ NOT DONE: no graduated curve, no minimum-cohort floor, no new
     * ratio. Each would replace one arbitrary number with another and would
     * still be measuring density. The threshold is left exactly where it
     * was and only the consequence is removed.
     *
     * ⚠ The constant is retained rather than deleted so there is exactly
     * ONE place to re-enable a weight effect from, if an evidence-led
     * dampener is ever agreed.
     */
    public const DAMPENED_MULTIPLIER = 1.00;

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
    /**
     * The measured overlap ratio, or NULL where overlap is not defined.
     *
     * ⚠ This is the OBSERVATION, and it is retained on purpose. Before this
     * change the only durable record that a cast was cohort-dampened was the
     * `cohort_overlap_mult: 0.80` value in its audit row. With the
     * multiplier at baseline that value no longer distinguishes anything, so
     * the ratio itself is surfaced and audited. Removing a penalty must not
     * also delete the evidence.
     *
     * NULL means the question does not apply: a card target (no attestor
     * cohort exists), a self/non-positive pair, or an attestor with no prior
     * casts. Those are distinct from a measured 0.0.
     *
     * Exactly the same TWO repository reads as before — no extra query.
     */
    public function overlapRatio(int $attestorUserId, string $targetKind, int $targetId): ?float
    {
        // Cards have no attestor cohort — overlap is structurally
        // undefined. Only user→user pairs are cohort-comparable.
        if ($targetKind !== 'user_profile') {
            return null;
        }
        if ($attestorUserId <= 0 || $targetId <= 0 || $attestorUserId === $targetId) {
            return null;
        }

        $attestorCohortSize = $this->repo->countDistinctTargetsByAttestor(
            $attestorUserId,
            'user_profile'
        );
        if ($attestorCohortSize <= 0) {
            // No prior attestations to overlap against — first-ever
            // cast can't be a cluster vector by construction.
            return null;
        }

        $intersectionSize = $this->repo->countOverlapBetweenAttestorAndTarget(
            $attestorUserId,
            $targetId
        );

        return $intersectionSize / $attestorCohortSize;
    }

    /**
     * Does this cast cross the overlap threshold? DETECTION ONLY — carries
     * no consequence. The threshold is unchanged.
     */
    public static function isHighOverlap(?float $ratio): bool
    {
        return $ratio !== null && $ratio >= self::OVERLAP_THRESHOLD;
    }

    /**
     * The weight effect of a known ratio, without re-reading.
     *
     * ⚠ Exists so the caller can audit the observation AND apply the
     * multiplier from a SINGLE pair of reads. Calling overlapRatio() and
     * weightFor() in sequence would double the DB work inside the
     * transaction-locked region.
     */
    public static function multiplierFor(?float $ratio): float
    {
        return self::isHighOverlap($ratio) ? self::DAMPENED_MULTIPLIER : self::NO_DAMPENING;
    }

    /**
     * Resolve the cohort-overlap multiplier for the given cast.
     *
     * ⚠ Now returns BASELINE in every case — see DAMPENED_MULTIPLIER.
     * Kept as the §6.3 contract surface and as the single mapping from
     * "high overlap" to "weight effect", so a future evidence-led dampener
     * has one place to live.
     */
    public function weightFor(int $attestorUserId, string $targetKind, int $targetId): float
    {
        return self::multiplierFor(
            $this->overlapRatio($attestorUserId, $targetKind, $targetId)
        );
    }
}
