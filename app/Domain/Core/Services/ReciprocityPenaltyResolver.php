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
     * §12 q6 — mutual pair dampener. Tunable; closed-network testing
     * retunes. Picked at 0.50 to mirror the wallet-age floor and to
     * neutralize a hypothetical 2× cartel-stacking effect (two
     * operators attesting to each other twice each = same outcome
     * as one operator attesting twice unilaterally).
     */
    public const RECIPROCITY_MULTIPLIER = 0.50;

    /** No-dampening case — passed through unchanged into weight_at_time. */
    public const NO_RECIPROCITY = 1.00;

    public function __construct(
        private readonly AttestationRepository $repo
    ) {
    }

    /**
     * Resolve the reciprocity multiplier for the given cast.
     *
     * @param int    $attestorUserId The operator casting the new attestation.
     * @param string $targetKind     One of the §J.4 target_kinds.
     * @param int    $targetId       The target id.
     * @return float A multiplier in {RECIPROCITY_MULTIPLIER, NO_RECIPROCITY}.
     */
    public function weightFor(int $attestorUserId, string $targetKind, int $targetId): float
    {
        // Cards can't cast back — reciprocity is structurally impossible.
        if ($targetKind !== 'user_profile') {
            return self::NO_RECIPROCITY;
        }
        if ($attestorUserId <= 0 || $targetId <= 0 || $attestorUserId === $targetId) {
            return self::NO_RECIPROCITY;
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

        $hasReverseVouch       = $reverse['vouch']       !== null;
        $hasReverseStandBehind = $reverse['stand_behind'] !== null;
        if ($hasReverseVouch || $hasReverseStandBehind) {
            return self::RECIPROCITY_MULTIPLIER;
        }

        return self::NO_RECIPROCITY;
    }
}
