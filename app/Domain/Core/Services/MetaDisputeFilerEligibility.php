<?php
/**
 * MetaDisputeFilerEligibility — static gate preventing cohort
 * members from filing meta-disputes against their own cohort.
 *
 * Plan §9 critical-risk-mitigation item #5. Plan §6.3 contract:
 *   `canFile(int $filerUserId, int $targetUserId): bool`
 *
 * **What is a meta-dispute?**
 *
 * Per the constitution + Phase 1 plan §3 ("Out of Phase 1 scope"),
 * meta-disputes are a Phase 3 filing flow — disputes filed against the
 * dispute system itself (e.g. "panel X was packed with cluster members
 * who voted as a block"). The full filing UX + state machine ships in
 * Phase 3 — but the **eligibility gate is non-negotiable Phase 1
 * scope** (plan §9 item #5). Without the gate landing now, the
 * Phase 3 flow could be exploited by the very clusters meta-disputes
 * exist to surface.
 *
 * **The exploit this prevents:**
 *
 * A cluster of operators (A, B, C, D, E) routinely cross-attest. One
 * of them gets disputed. Without this gate, another cluster member
 * could file a meta-dispute "the panel against operator A was unfair"
 * — except the panel was correctly catching cluster behavior, and the
 * meta-dispute filer is part of the same cluster. The gate refuses
 * meta-dispute filings from operators whose cohort substantially
 * overlaps the dispute target's cohort.
 *
 * **Overlap formula** (mirrors `CohortOverlapDampener`):
 *
 *   overlap_ratio = |filer's user-cohort ∩ target's attestor-cohort|
 *                   / |filer's user-cohort|
 *
 * In words: "what fraction of MY past attestation pool also attests
 * to THIS target?" When ≥50%, my judgment is too aligned with the
 * target's existing supporters to be an independent meta-dispute filer.
 *
 * **Threshold + return value:**
 *
 *   overlap ≥ 50%  →  `canFile() = false` (refuse meta-dispute)
 *   overlap <  50% →  `canFile() = true` (eligible)
 *
 * Single-step rather than graduated because the gate is binary — you
 * either can file or you can't, no half-files. The 50% threshold
 * mirrors the `CohortOverlapDampener` value (§12 q7) so the two
 * surfaces share one "what counts as a cohort member" rule.
 *
 * **Edge cases (all → `true`, i.e. eligible):**
 *   - filer === target: structurally impossible to meta-dispute
 *     yourself; defensive return `false` here per the
 *     "can't file" semantic.
 *   - filer's cohort is empty: can't be a cluster member with
 *     nothing to cluster with — eligible.
 *   - target's cohort is empty: can't overlap with the empty set
 *     — eligible.
 *
 * **Phase 3 wiring point:** when the meta-dispute filing endpoint
 * lands, it calls this gate exactly once at the start of the file
 * action, before any rate-limit / fraud / tier check. Refusing
 * here is a hard wall — no override, no admin bypass (that would
 * recreate the exploit).
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Trust Attestation Layer PR-9 (2026-05-14)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\AttestationRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class MetaDisputeFilerEligibility
{
    /**
     * §12 q7 — overlap threshold. Mirrors `CohortOverlapDampener::OVERLAP_THRESHOLD`
     * by design — "cohort member" is one rule across both surfaces.
     * Tunable; closed-network testing retunes as graph density
     * calibrates.
     */
    public const OVERLAP_THRESHOLD = 0.50;

    public function __construct(
        private readonly AttestationRepository $repo
    ) {
    }

    /**
     * Can `$filerUserId` file a meta-dispute about a panel adjudicating
     * `$targetUserId`?
     *
     * Returns `false` (deny) only when the filer's cohort substantially
     * overlaps the target's attestor cohort. Every other path —
     * disjoint cohorts, empty cohorts, first-time filers — returns
     * `true`. Self-meta-dispute (filer === target) returns `false`
     * by construction.
     */
    public function canFile(int $filerUserId, int $targetUserId): bool
    {
        if ($filerUserId <= 0 || $targetUserId <= 0) {
            return false;
        }
        if ($filerUserId === $targetUserId) {
            // Self meta-dispute is structurally meaningless — defensive
            // hard-deny in case a future caller forgets to gate this
            // upstream.
            return false;
        }

        $filerCohortSize = $this->repo->countDistinctTargetsByAttestor(
            $filerUserId,
            'user_profile'
        );
        if ($filerCohortSize <= 0) {
            // Filer hasn't cast attestations on anyone — can't be a
            // cluster member with nothing to cluster with. Eligible.
            return true;
        }

        $intersectionSize = $this->repo->countOverlapBetweenAttestorAndTarget(
            $filerUserId,
            $targetUserId
        );
        $overlapRatio = $intersectionSize / $filerCohortSize;

        return $overlapRatio < self::OVERLAP_THRESHOLD;
    }
}
