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
 * **user_profile targets (id-duality):** members are full trust
 * subjects on their self-pages, so the `disputed` branch DOES fire for
 * user_profile — they get the same five-state surface as entities.
 * The one trap: for `user_profile` the `target_id` is a RAW user id
 * (attestation counts key on it), but disputes/votes/scores key on the
 * member's self-page id `MemberSelfPageService::selfPageId(target_id)`.
 * classify() reads attestation counts by the raw user id and the
 * dispute existence by the translated self-page id — never crossing the
 * two. (`polarizing` remains the Slice E.5 forward-compat stub.)
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

        // Dispute reads are page-scoped. For user_profile the backing
        // page is the member's self-page (ID_BASE + user_id), NOT the
        // raw user id the attestation counts below use — translate here
        // so the dispute existence read hits the right row (id-duality).
        $disputePageId = $targetKind === 'user_profile'
            ? MemberSelfPageService::selfPageId($targetId)
            : $targetId;
        $hasActiveDispute = $disputePageId > 0
            && DisputeRepository::hasActiveDisputeForPage($disputePageId);

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
        //    is the strongest single signal. Applies to user_profile too
        //    (members are full trust subjects on their self-page); the
        //    caller resolves the self-page id before setting the flag.
        if ($hasActiveDispute) {
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
