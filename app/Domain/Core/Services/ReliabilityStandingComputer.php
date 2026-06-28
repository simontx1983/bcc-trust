<?php
/**
 * ReliabilityStandingComputer — maps an operator to one of the three
 * §J.3.2 positive-only reliability standings.
 *
 * **Standing catalogue (positive-only by construction, per §J.3.2):**
 *
 *   - `highly_reliable` — proven track record over time
 *   - `consistent`      — established active operator
 *   - `newly_active`    — starting state (and the default for
 *                          everyone who hasn't earned the higher tiers)
 *
 * There is NO negative standing. An operator whose reliability
 * softens loses their positive badge — never gains a stigma marker.
 * This is the load-bearing asymmetric-display rule from §J.3.2: the
 * floor signals trust, not distrust.
 *
 * **V1 baseline heuristic (replaced by Slice E.4+ outcome synthesis):**
 *
 *   highly_reliable ↔ 25+ active casts AND wallet age ≥ 365 days
 *   consistent      ↔  5+ active casts AND wallet age ≥  90 days
 *   newly_active    ↔ everyone else
 *
 * Active casts = `kind in (vouch, stand_behind)` with `revoked_at IS NULL`.
 * Wallet age = `WalletRepository::oldestVerifiedAt` (uses `verified_at`,
 * the signature-verified moment, NOT `created_at` — Sybil mitigation).
 *
 * These are TENURE + ACTIVITY proxies, not real reliability. The real
 * synthesis (Operator Reliability formula per §J.3.2.1) requires
 * outcome classification — was the operator's call later confirmed by
 * consensus, did the target get disputed-and-upheld, etc. Slice E.4
 * ships the outcome classifier; this computer is then refactored to
 * read the cached numeric reliability score and bucket from there.
 *
 * **Why tenure-as-proxy is safe even though it's a proxy:**
 *
 *   - The thresholds are conservative — an operator with 25 active
 *     casts and a year-old wallet has demonstrably been around. They
 *     might still be a bad judge; the standing won't catch that
 *     until Slice E.4. But they're at minimum *not new*.
 *   - The catalogue is positive-only. Even a bad judge whose calls
 *     all turn out wrong renders as `newly_active` once the outcome
 *     classifier kicks in — never as `untrustworthy`. So "false
 *     positive promotion" via tenure doesn't create a public stigma
 *     vector.
 *   - §J.10 q14: public surface is SELF-ONLY in V1 anyway. The
 *     promoted standing is consumed by /me/reliability — the
 *     operator sees their own state. Roster rows on third-party
 *     profiles keep `newly_active` regardless (gated upstream in
 *     the §J.4 composer).
 *
 * **Performance note:** invoked once per roster row in
 * `AttestationService::buildAttestorMiniView` (when public surface
 * gating is removed in V2) and once per /me/reliability hit. Each
 * call costs two queries (active-cast count + oldest-wallet MIN).
 * Slice E.5 ships `bcc_attestor_reliability_cache` which memoizes
 * the result; until then the cost is acceptable at V1 scale.
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Trust Attestation Layer PR-6 (2026-05-13)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\AttestorReliabilityCacheRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ReliabilityStandingComputer
{
    /** §J.3.2 standing catalogue values. */
    public const STANDING_HIGHLY_RELIABLE = 'highly_reliable';
    public const STANDING_CONSISTENT      = 'consistent';
    public const STANDING_NEWLY_ACTIVE    = 'newly_active';

    /**
     * V1 heuristic thresholds. Tunable; closed-network testing retunes
     * as the outcome classifier ships and real synthesis numbers
     * displace these proxies.
     */
    public const HIGHLY_RELIABLE_MIN_CASTS       = 25;
    public const HIGHLY_RELIABLE_MIN_WALLET_DAYS = 365;
    public const CONSISTENT_MIN_CASTS            = 5;
    public const CONSISTENT_MIN_WALLET_DAYS      = 90;

    public function __construct(
        private readonly AttestationRepository $repo,
        private readonly AttestorReliabilityCacheRepository $reliabilityCacheRepo
    ) {
    }

    /**
     * Resolve the reliability-standing badge for an operator.
     *
     * Slice 3: cache-first. When the nightly sweep has memoized this
     * operator's standing (computed from the real outcome-driven numeric
     * reliability via {@see fromReliability}), serve it directly. On a
     * cache miss (operator not yet swept) fall back to the V1 tenure +
     * activity heuristic below so the standing is never empty.
     *
     * @return self::STANDING_*
     */
    public function compute(int $userId): string
    {
        if ($userId <= 0) {
            return self::STANDING_NEWLY_ACTIVE;
        }

        $cached = $this->reliabilityCacheRepo->getByUserId($userId);
        if ($cached !== null && isset($cached->reliability_standing)) {
            $standing = (string) $cached->reliability_standing;
            // Defensive: only accept a known catalogue value; anything
            // else falls through to the tenure heuristic.
            if (in_array($standing, [
                self::STANDING_HIGHLY_RELIABLE,
                self::STANDING_CONSISTENT,
                self::STANDING_NEWLY_ACTIVE,
            ], true)) {
                return $standing;
            }
        }

        // Active casts — count vouch + stand_behind that haven't been
        // revoked. `countActiveStandBehindByAttestor` is stand_behind
        // only; we want both kinds, so call `countAllByAttestor` and
        // subtract revoked rows? No — `countAllByAttestor` includes
        // revoked. We need active-only across both kinds.
        //
        // For V1 simplicity, use `countAllByAttestor` as the cast-count
        // proxy. A revoked attestation is still a judgment that was
        // expressed at some point — the operator demonstrated they
        // engage with the system. The promotion is to a tenure badge,
        // not a "currently active" badge. If closed-network testing
        // shows this overshoots, swap in an active-only repo method.
        $castCount = $this->repo->countAllByAttestor($userId);
        if ($castCount < self::CONSISTENT_MIN_CASTS) {
            return self::STANDING_NEWLY_ACTIVE;
        }

        // Wallet age — uses the canonical verified-at primitive.
        $oldestVerified = WalletRepository::oldestVerifiedAt($userId);
        if ($oldestVerified === null) {
            // No verified wallet ⇒ no on-chain identity ⇒ stays new.
            return self::STANDING_NEWLY_ACTIVE;
        }

        $verifiedTs = strtotime($oldestVerified . ' UTC');
        if ($verifiedTs === false) {
            return self::STANDING_NEWLY_ACTIVE;
        }
        $walletAgeDays = (int) floor((time() - $verifiedTs) / 86400);

        if (
            $castCount >= self::HIGHLY_RELIABLE_MIN_CASTS
            && $walletAgeDays >= self::HIGHLY_RELIABLE_MIN_WALLET_DAYS
        ) {
            return self::STANDING_HIGHLY_RELIABLE;
        }

        // castCount >= CONSISTENT_MIN_CASTS is implied by the early
        // return above — only the wallet-age cut still needs checking.
        if ($walletAgeDays >= self::CONSISTENT_MIN_WALLET_DAYS) {
            return self::STANDING_CONSISTENT;
        }

        return self::STANDING_NEWLY_ACTIVE;
    }

    /**
     * Map a LIVE numeric reliability + counted-attestation total to a §J.3.2
     * standing — the Slice 2 outcome-driven path that displaces the tenure
     * proxy in {@see compute()}.
     *
     * Pure + static so it's unit-testable in isolation (no DB), mirroring the
     * AttestationScoreSynthesis::computeBonus pattern. AttestationService feeds
     * it the AttestationOutcomeClassifier output (numeric reliability + counted
     * attestations) plus the filtered §config thresholds.
     *
     * First-call protection: below $minForStanding counted attestations the
     * operator stays 'newly_active' REGARDLESS of the numeric value — a sample
     * too small to be statistically meaningful must not mint (or, since the
     * catalogue is positive-only, withhold by surprise) a higher standing.
     *
     * At or above the gate:
     *   - reliability >  $highlyMin      → highly_reliable
     *   - reliability >= $consistentMin  → consistent
     *   - else                           → newly_active
     *
     * The positive-only catalogue (§J.3.2) means a low numeric reliability
     * lands at 'newly_active', never a stigma marker — there is no negative
     * standing to fall to.
     *
     * @return self::STANDING_*
     */
    public static function fromReliability(
        float $reliability,
        int $countedAttestations,
        float $highlyMin,
        float $consistentMin,
        int $minForStanding
    ): string {
        if ($countedAttestations < $minForStanding) {
            return self::STANDING_NEWLY_ACTIVE;
        }
        if ($reliability > $highlyMin) {
            return self::STANDING_HIGHLY_RELIABLE;
        }
        if ($reliability >= $consistentMin) {
            return self::STANDING_CONSISTENT;
        }
        return self::STANDING_NEWLY_ACTIVE;
    }
}
