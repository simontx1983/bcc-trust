<?php
/**
 * DivergenceStateClassifier — read-time §J.8 five-state classification.
 *
 * Maps a target to exactly one of:
 *
 *   - `disputed`        — active dispute exists on the target's
 *                         backing page (status = 'reviewing'). Meaningful
 *                         today via DisputeRepository.
 *   - `polarizing`      — divergence among high-reliability attestors
 *                         (reliability_standing ≥ consistent). V1 ships
 *                         the slot but the heuristic doesn't fire until
 *                         Slice E.5 ships the reliability cache — until
 *                         then this state is forward-compat only.
 *   - `poorly_regarded` — > 1.5× revoked:active ratio AND ≥ 3 total
 *                         casts. V1 heuristic, refined by outcome
 *                         classifier in Slice E.4+.
 *   - `well_regarded`   — ≥ 3 active attestations AND no active dispute
 *                         AND not polarizing.
 *   - `untested`        — ≤ 2 active attestations (the starting state).
 *
 * **Asymmetric display rule (§J.4.1, §J.3.2):** all five states are
 * INTELLIGENCE, not condemnation. `polarizing` is "a signal worth
 * examining," not a stigma. `disputed` is fact-of-active-dispute,
 * never a verdict. `poorly_regarded` is a tenure-pattern proxy until
 * outcome classification ships. The classifier output renders
 * verbatim on the §J.6 view-model; FE renders without softening.
 *
 * **V1 scope (Phase 1 plan §6.2):** read-time pure function. Each
 * call costs:
 *   - 1 dispute-existence check (page-scoped, indexed)
 *   - 1 active-cast count (`countByTarget`)
 *   - 1 revoked-cast count (`countRevokedByTarget`)
 * Slice E.5 ships `bcc_target_divergence_state` sidecar table that
 * memoizes the result via a nightly worker. Until then the classifier
 * runs at request time — cheap at V1 scale.
 *
 * **user_profile targets:** disputes don't exist for user_profile in
 * V1 (Phase 1.5 backlog). The classifier returns the same five-state
 * enum but the `disputed` branch never fires for user_profile —
 * effectively a 4-state surface until disputes-on-profiles ship.
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Trust Attestation Layer PR-8a (2026-05-14)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Disputes\Repositories\DisputeRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class DivergenceStateClassifier
{
    /** §J.8 five-state catalogue. Mutually exclusive. */
    public const STATE_UNTESTED        = 'untested';
    public const STATE_WELL_REGARDED   = 'well_regarded';
    public const STATE_POORLY_REGARDED = 'poorly_regarded';
    public const STATE_POLARIZING      = 'polarizing';
    public const STATE_DISPUTED        = 'disputed';

    /** §12 q8 — heuristic threshold. Tunable; closed-network retunes. */
    public const UNTESTED_ATTESTATION_FLOOR = 2;
    public const POORLY_REGARDED_REVOCATION_RATIO = 1.5;
    public const POORLY_REGARDED_MIN_TOTAL = 3;

    public function __construct(
        private readonly AttestationRepository $repo
    ) {
    }

    /**
     * Classify a target into one of the five §J.8 states.
     *
     * Thin I/O wrapper: fetches the three inputs (dispute existence,
     * active-cast count, revoked-cast count) and delegates the actual
     * decision to classifyFromCounts() — the ONE heuristic
     * implementation, shared with the batch path where
     * PageCardPrefetcher supplies the same three inputs from grouped
     * queries instead of per-target reads.
     *
     * @return self::STATE_*
     */
    public function classify(string $targetKind, int $targetId): string
    {
        if (!in_array($targetKind, AttestationRepository::TARGET_KINDS, true)) {
            return self::STATE_UNTESTED;
        }
        if ($targetId <= 0) {
            return self::STATE_UNTESTED;
        }

        // user_profile disputes are Phase 1.5; the dispute read is
        // page-scoped only in V1 — skip it entirely for user_profile.
        $hasActiveDispute = $targetKind !== 'user_profile'
            && DisputeRepository::hasActiveDisputeForPage($targetId);

        $counts = $this->repo->countByTarget($targetKind, $targetId);
        $activeTotal = (int) ($counts['total'] ?? 0);

        $revokedTotal = $this->repo->countRevokedByTarget($targetKind, $targetId);

        return $this->classifyFromCounts(
            $targetKind,
            $targetId,
            $hasActiveDispute,
            $activeTotal,
            $revokedTotal
        );
    }

    /**
     * Pure §J.8 classification from pre-fetched inputs — no I/O. The
     * single source of the five-state heuristic; classify() feeds it
     * per-target reads, PageCardPrefetcher consumers feed it grouped
     * batch counts. Keep ALL decision logic here so the two paths can
     * never diverge.
     *
     * @return self::STATE_*
     */
    public function classifyFromCounts(
        string $targetKind,
        int $targetId,
        bool $hasActiveDispute,
        int $activeTotal,
        int $revokedTotal
    ): string {
        if (!in_array($targetKind, AttestationRepository::TARGET_KINDS, true)) {
            return self::STATE_UNTESTED;
        }
        if ($targetId <= 0) {
            return self::STATE_UNTESTED;
        }

        // 1. Disputed wins over every other state — an active dispute
        //    is the strongest single signal. user_profile disputes are
        //    Phase 1.5; the flag can only be true page-scoped in V1
        //    (the guard keeps a stray true harmless for user_profile).
        if ($targetKind !== 'user_profile' && $hasActiveDispute) {
            return self::STATE_DISPUTED;
        }

        // 2. Active attestation floor.
        if ($activeTotal <= self::UNTESTED_ATTESTATION_FLOOR) {
            return self::STATE_UNTESTED;
        }

        // 3. Poorly regarded — revocation pattern dominates. Heuristic
        //    proxy until Slice E.4+ outcome classifier ships.
        //    activeTotal >= POORLY_REGARDED_MIN_TOTAL is implied by the
        //    early return above (UNTESTED_FLOOR=2, POORLY_MIN=3, so
        //    activeTotal > 2 ⇒ activeTotal >= 3 ⇒ >= POORLY_MIN). Only
        //    the ratio check remains. If those constants ever diverge,
        //    re-add the explicit min-total check.
        if ($revokedTotal > $activeTotal * self::POORLY_REGARDED_REVOCATION_RATIO) {
            return self::STATE_POORLY_REGARDED;
        }

        // 4. Polarizing detection requires reliability-cache reads
        //    that Slice E.5 ships. Until then the state slot exists
        //    in the response shape but the heuristic never fires —
        //    forward-compat only. Documented in the class header.

        // 5. Default for active + non-disputed targets.
        return self::STATE_WELL_REGARDED;
    }
}
