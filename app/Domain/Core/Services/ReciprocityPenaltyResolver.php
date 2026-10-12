<?php
/**
 * ReciprocityPenaltyResolver — mutual-attestation dampener.
 *
 * Plan §9 critical-risk-mitigation item #3: reciprocity penalty.
 * Plan §6.3 contract:
 *   `weightFor(int $attestorUserId, string $targetKind, int $targetId): float`
 * Returns a multiplier that dampens A→B casts when B→A already exists.
 *
 * The intent (per risk-assessment §5 item 3): a pair of operators can
 * trivially inflate each other's score by cross-attesting ("you back
 * me, I back you"). Without a reciprocity dampener, the diversity
 * multiplier (§J.4 +30% for independent signal sources) would compound
 * on a non-independent pair — the exact opposite of the design intent.
 *
 * §12 q6 — Reciprocity-penalty exact threshold. The plan recommends
 * "any mutual pair, no minimum threshold." This implementation commits
 * to that: a single active reverse-attestation (target → attestor) is
 * enough to flag the new cast as reciprocal. No grace period, no
 * minimum reputation gap, no temporal window — keep the rule legible.
 *
 * What counts as "reverse":
 *   - target_kind MUST be `user_profile`. Cards (validator_card,
 *     project_card, creator_card) can't cast attestations back —
 *     reciprocity is structurally impossible. Card casts always
 *     return 1.0 (no dampening).
 *   - Active row only — revoked attestations don't count (the
 *     operator already said "I changed my mind"). Looked up via
 *     `AttestationRepository::findActiveByAttestorAndTarget` so the
 *     SQL is the same primitive the rest of the service uses.
 *   - Vouch OR stand_behind on either side both count. The dampening
 *     applies whenever any judgment flows both ways.
 *
 * Applied at cast-time, captured in `weight_at_time` per the ledger
 * semantics. A future revoke of the reverse attestation does NOT
 * retroactively reweight this cast — that's the snapshot guarantee.
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Trust Attestation Layer PR-4 (2026-05-13)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\AttestationRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ReciprocityPenaltyResolver
{
    /**
     * ⚠⚠ WAS 0.50. NOW BASELINE — ORDINARY RECIPROCITY IS NOT PENALISED.
     *
     * The 0.50 halved an attestation's weight whenever the target had any
     * active reverse attestation on the attestor. That did not match this
     * mechanism's own documented design, in two directions:
     *
     *   SPEC (trust-attestation-risk-assessment.md, cartel mitigation #3):
     *     "both attestations get baseline weight only (no diversity
     *      bonus). Detects reciprocal backing WITHOUT FORBIDDING IT — the
     *      attestations still count, they just don't compound."
     *
     *   1. "baseline weight only" is x1.0. Halving the base weight is a
     *      PENALTY the spec never authorised.
     *   2. The spec asked for the DIVERSITY BONUS to be suppressed so a
     *      reciprocal pair does not COMPOUND. This class never touched
     *      it: the 1.3x multiplier lives in AttestationScoreSynthesis and
     *      is gated purely on the count of distinct sources, with no
     *      reciprocity input at all.
     *
     * So the one thing the spec asked for was not done, and a different,
     * harsher thing was. This change does the first half — restore
     * baseline weight.
     *
     * ⚠ IT DELIBERATELY DOES NOT DO THE SECOND HALF. Suppressing the
     * diversity bonus for reciprocal sources would LOWER scores for some
     * targets, and the old specification is design evidence, not standing
     * authorization for a further policy change. Current diversity
     * behaviour is characterised by tests, not altered. See
     * docs/proposal-reciprocity-attestation-dampener.md.
     *
     * ⚠ The constant is retained rather than deleted so there is exactly
     * ONE place to re-enable a weight effect from, if an evidence-led
     * dampener is ever agreed.
     */
    public const RECIPROCITY_MULTIPLIER = 1.00;

    /** No-dampening case — passed through unchanged into weight_at_time. */
    public const NO_RECIPROCITY = 1.00;

    public function __construct(
        private readonly AttestationRepository $repo
    ) {
    }

    /**
     * Is this cast reciprocal? DETECTION ONLY — carries no consequence.
     *
     * ⚠ This is the observation, and it is retained on purpose. Before
     * this change the only durable record that a cast was reciprocal was
     * the `reciprocity_mult: 0.50` value in its audit row. With the
     * multiplier at baseline that value no longer distinguishes anything,
     * so the boolean is surfaced instead and audited as
     * `reciprocity_detected`. Removing the penalty must not also delete
     * the evidence — the same reasoning applied to the ring detectors in
     * #314, where dropping an increment would have erased the only trace
     * of the observation.
     *
     * Exactly ONE reverse-lookup read, as before.
     *
     * @param int    $attestorUserId The operator casting the new attestation.
     * @param string $targetKind     One of the §J.4 target_kinds.
     * @param int    $targetId       The target id.
     */
    public function isReciprocal(int $attestorUserId, string $targetKind, int $targetId): bool
    {
        // Cards can't cast back — reciprocity is structurally impossible.
        if ($targetKind !== 'user_profile') {
            return false;
        }
        if ($attestorUserId <= 0 || $targetId <= 0 || $attestorUserId === $targetId) {
            return false;
        }

        // The reverse query: does the target (as attestor) have an
        // active attestation on the casting operator (as target)? Uses
        // the same primitive `cast()` itself uses for idempotency lookup,
        // so the read is cheap (covered by the same compound index).
        $reverse = $this->repo->findActiveByAttestorAndTarget(
            $targetId,
            'user_profile',
            $attestorUserId
        );

        return $reverse['vouch'] !== null || $reverse['stand_behind'] !== null;
    }

    /**
     * Resolve the reciprocity multiplier for the given cast.
     *
     * ⚠ Now returns BASELINE in every case — see RECIPROCITY_MULTIPLIER.
     * Kept as the §6.3 contract surface and as the single mapping from
     * "reciprocal" to "weight effect", so a future evidence-led dampener
     * has one place to live.
     *
     * @param int    $attestorUserId The operator casting the new attestation.
     * @param string $targetKind     One of the §J.4 target_kinds.
     * @param int    $targetId       The target id.
     * @return float A multiplier in {RECIPROCITY_MULTIPLIER, NO_RECIPROCITY}.
     */
    public function weightFor(int $attestorUserId, string $targetKind, int $targetId): float
    {
        return $this->isReciprocal($attestorUserId, $targetKind, $targetId)
            ? self::RECIPROCITY_MULTIPLIER
            : self::NO_RECIPROCITY;
    }

    /**
     * The weight effect of a known detection, without re-reading.
     *
     * ⚠ Exists so the caller can audit the observation AND apply the
     * multiplier from a SINGLE reverse-lookup. Calling isReciprocal()
     * and weightFor() in sequence would double the DB read inside the
     * transaction-locked region.
     */
    public static function multiplierFor(bool $isReciprocal): float
    {
        return $isReciprocal ? self::RECIPROCITY_MULTIPLIER : self::NO_RECIPROCITY;
    }
}
