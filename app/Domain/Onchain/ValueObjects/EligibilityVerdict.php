<?php
/**
 * Three-outcome eligibility verdict for NFT-gated holder groups.
 *
 * The whole point of this VO is to distinguish "we are SURE the user
 * does not qualify" (INELIGIBLE) from "we COULD NOT verify" (UNKNOWN —
 * a provider timeout, 429, circuit-breaker-open, or malformed RPC).
 *
 * Before this existed, every provider failure collapsed to a balance of
 * 0, indistinguishable from a real zero — which meant an RPC outage
 * looked exactly like "the user sold their NFT." On the JOIN path that
 * is merely annoying (fail-closed: don't add during an outage). On the
 * REVOKE sweep (Part 2) it is dangerous: a hiccup would evict every
 * member of a gated group. UNKNOWN is the seam that lets the revoke
 * sweep skip-and-retry instead of revoking on a transient failure.
 *
 * Verdict derivation (see HoldingsService::eligibilityVerdict):
 *   - ELIGIBLE   → at least one wallet returned a REAL count ≥ minBalance.
 *   - INELIGIBLE → every wallet returned a REAL count, and none reached
 *                  minBalance (we are certain they don't qualify).
 *   - UNKNOWN    → no wallet reached minBalance AND at least one wallet
 *                  returned null (provider error) — we cannot be sure
 *                  they don't qualify, so we refuse to act against them.
 *
 * @package BCC\Trust\Onchain\ValueObjects
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class EligibilityVerdict
{
    public const ELIGIBLE   = 'eligible';
    public const INELIGIBLE = 'ineligible';
    public const UNKNOWN    = 'unknown';

    // ── Why an UNKNOWN happened (PR 5b) ─────────────────────────────────
    // UNKNOWN used to mean exactly one thing: a provider could not answer.
    // PR 5b adds a second, operationally opposite cause — the gate itself
    // is misconfigured, so no provider was ever asked. Both refuse to act
    // against the member, but they need different responses: one clears
    // itself, the other needs an operator repair run. Collapsing them would
    // tell an operator to wait out a problem that never expires.

    /** A provider timed out / 429'd / broke. Transient; retry helps. */
    public const REASON_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /**
     * The gate's collection identity could not be resolved, so ZERO
     * provider calls were made. Retrying cannot help; a repair can.
     */
    public const REASON_IDENTITY_UNRESOLVED = 'collection_identity_unresolved';

    /**
     * @param self::ELIGIBLE|self::INELIGIBLE|self::UNKNOWN $outcome
     * @param int|null $bestKnownBalance Highest REAL (non-null) single-wallet
     *                 count observed, or null when no wallet returned a real
     *                 count (pure-UNKNOWN). Diagnostic only — never trusted to
     *                 widen the outcome.
     * @param string   $reason Machine-readable cause; '' for a decided
     *                 outcome (ELIGIBLE / INELIGIBLE), which has no "why".
     */
    private function __construct(
        public readonly string $outcome,
        public readonly int    $minBalance,
        public readonly ?int   $bestKnownBalance,
        public readonly string $reason = '',
    ) {}

    public static function eligible(int $minBalance, int $bestKnownBalance): self
    {
        return new self(self::ELIGIBLE, $minBalance, $bestKnownBalance);
    }

    public static function ineligible(int $minBalance, int $bestKnownBalance): self
    {
        return new self(self::INELIGIBLE, $minBalance, $bestKnownBalance);
    }

    /**
     * UNKNOWN because a provider could not verify. Unchanged behaviour —
     * the default reason keeps every existing caller correct without edit.
     */
    public static function unknown(int $minBalance, ?int $bestKnownBalance): self
    {
        return new self(self::UNKNOWN, $minBalance, $bestKnownBalance, self::REASON_PROVIDER_UNAVAILABLE);
    }

    /**
     * UNKNOWN because the gate's identity is unresolved and nothing was
     * asked of any provider.
     *
     * `bestKnownBalance` is null and MUST stay null: there is no observed
     * balance, and a `0` here would be the same lie this PR removes.
     */
    public static function identityUnresolved(int $minBalance): self
    {
        return new self(self::UNKNOWN, $minBalance, null, self::REASON_IDENTITY_UNRESOLVED);
    }

    /** True when this UNKNOWN was caused by an unresolvable gate identity. */
    public function isIdentityUnresolved(): bool
    {
        return $this->outcome === self::UNKNOWN
            && $this->reason === self::REASON_IDENTITY_UNRESOLVED;
    }

    public function isEligible(): bool
    {
        return $this->outcome === self::ELIGIBLE;
    }

    public function isIneligible(): bool
    {
        return $this->outcome === self::INELIGIBLE;
    }

    public function isUnknown(): bool
    {
        return $this->outcome === self::UNKNOWN;
    }
}
