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

    /**
     * @param self::ELIGIBLE|self::INELIGIBLE|self::UNKNOWN $outcome
     * @param int|null $bestKnownBalance Highest REAL (non-null) single-wallet
     *                 count observed, or null when no wallet returned a real
     *                 count (pure-UNKNOWN). Diagnostic only — never trusted to
     *                 widen the outcome.
     */
    private function __construct(
        public readonly string $outcome,
        public readonly int    $minBalance,
        public readonly ?int   $bestKnownBalance,
    ) {}

    public static function eligible(int $minBalance, int $bestKnownBalance): self
    {
        return new self(self::ELIGIBLE, $minBalance, $bestKnownBalance);
    }

    public static function ineligible(int $minBalance, int $bestKnownBalance): self
    {
        return new self(self::INELIGIBLE, $minBalance, $bestKnownBalance);
    }

    public static function unknown(int $minBalance, ?int $bestKnownBalance): self
    {
        return new self(self::UNKNOWN, $minBalance, $bestKnownBalance);
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
