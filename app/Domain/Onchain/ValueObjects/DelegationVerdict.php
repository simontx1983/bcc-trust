<?php
/**
 * Three-outcome delegation-eligibility verdict for validator/delegator
 * communities. Exact sibling of {@see EligibilityVerdict} (the NFT gate's
 * verdict) with stake semantics instead of balance semantics.
 *
 * The whole point is to distinguish "we are SURE the user does not
 * delegate enough" (INELIGIBLE) from "we COULD NOT verify" (UNKNOWN — an
 * LCD timeout, 429, or malformed response). On the JOIN path UNKNOWN
 * fails CLOSED (503, never add during an outage); on the REVOKE sweep
 * UNKNOWN means SKIP (never evict on a hiccup).
 *
 * Derivation (see DelegationEligibilityService):
 *   - ELIGIBLE   → at least one verified wallet has a REAL delegated
 *                  amount ≥ minStake to the gate's validator.
 *   - INELIGIBLE → every wallet produced a REAL answer (a matched row
 *                  with a real amount, or a definite no-delegation) and
 *                  none reached minStake.
 *   - UNKNOWN    → no wallet reached minStake AND at least one wallet's
 *                  delegation set could not be verified (transport
 *                  failure or a matched row with no readable amount).
 *
 * @package BCC\Trust\Onchain\ValueObjects
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class DelegationVerdict
{
    public const ELIGIBLE   = 'eligible';
    public const INELIGIBLE = 'ineligible';
    public const UNKNOWN    = 'unknown';

    /**
     * @param self::ELIGIBLE|self::INELIGIBLE|self::UNKNOWN $outcome
     * @param float|null $bestKnownStake Highest REAL single-wallet delegated
     *                   amount observed (display units), or null when no
     *                   wallet returned a real amount (pure-UNKNOWN).
     *                   Diagnostic only — never trusted to widen the outcome.
     */
    private function __construct(
        public readonly string $outcome,
        public readonly float  $minStake,
        public readonly ?float $bestKnownStake,
    ) {}

    public static function eligible(float $minStake, float $bestKnownStake): self
    {
        return new self(self::ELIGIBLE, $minStake, $bestKnownStake);
    }

    public static function ineligible(float $minStake, float $bestKnownStake): self
    {
        return new self(self::INELIGIBLE, $minStake, $bestKnownStake);
    }

    public static function unknown(float $minStake, ?float $bestKnownStake): self
    {
        return new self(self::UNKNOWN, $minStake, $bestKnownStake);
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
