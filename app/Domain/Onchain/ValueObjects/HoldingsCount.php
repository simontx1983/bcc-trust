<?php
/**
 * One wallet's balance under one collection, WITH whether the read that
 * produced it saw everything.
 *
 * ── WHY A COUNT IS NOT ENOUGH ───────────────────────────────────────────
 * A bare integer cannot tell "the wallet holds 0" from "the read stopped
 * before it found anything". Every false revocation found by the PR 7.14
 * audit was one of those two collapsing into the other: a DAS walk cut off
 * at its page ceiling, a Cosmos list limited to thirty collections, an
 * ERC-1155 index that never saw the wallet's history. Each produced an
 * ordinary `0`, and an ordinary `0` is what the revoke sweep acts on.
 *
 * This carries the one extra fact that decides whether a number may be used
 * AGAINST a member:
 *
 *   - exact   → the read finished; the count is the whole balance, so a
 *               shortfall is a real shortfall;
 *   - atLeast → the read did not (or cannot) see everything; the count is a
 *               LOWER BOUND. It can still prove ownership — N found means at
 *               least N held — but it can never prove non-ownership.
 *
 * It is evidence, not a verdict. {@see EligibilityVerdict} stays the only
 * ELIGIBLE / INELIGIBLE / UNKNOWN authority; this type is what that verdict
 * is reduced from.
 *
 * @package BCC\Trust\Onchain\ValueObjects
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class HoldingsCount
{
    private function __construct(
        public readonly int $count,
        public readonly bool $complete,
    ) {}

    /** A finished read: `$count` is the wallet's whole balance. */
    public static function exact(int $count): self
    {
        return new self(max(0, $count), true);
    }

    /** An unfinished read: `$count` is only a lower bound. */
    public static function atLeast(int $count): self
    {
        return new self(max(0, $count), false);
    }

    /**
     * Does this evidence settle a `>= $minBalance` gate?
     *
     *   - true  → proves the wallet qualifies (a lower bound is enough);
     *   - false → proves it does not — ONLY from a complete read;
     *   - null  → cannot settle it either way.
     *
     * ⚠ The `false` branch needs BOTH conditions. A lower bound below the
     * threshold is exactly the case this type exists to keep undecided.
     */
    public function decide(int $minBalance): ?bool
    {
        $min = max(1, $minBalance);

        if ($this->count >= $min) {
            return true;
        }

        if ($this->complete && $this->count < $min) {
            return false;
        }

        return null;
    }
}
