<?php
/**
 * CapabilityDecision — typed verdict from the CapabilityResolver.
 *
 * Three-outcome design mirroring EligibilityVerdict (Onchain): ALLOWED,
 * DENIED, and UNKNOWN. UNKNOWN exists because some live gates cannot be
 * re-evaluated side-effect-free in shadow (VoteEligibilityChecker::check
 * consumes rate-limit quota) or need context the caller didn't supply —
 * an honest "the live gate is authoritative" beats a fabricated verdict.
 * If resolver output is ever ENFORCED (Phase 5+), UNKNOWN must be
 * treated as DENY (fail closed); in shadow it simply doesn't compare.
 *
 * @package BCC\Trust\Core\ValueObjects
 * @since Rank redesign Phase 3 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class CapabilityDecision
{
    public const ALLOWED = 'allowed';
    public const DENIED  = 'denied';
    public const UNKNOWN = 'unknown';

    /**
     * @param self::ALLOWED|self::DENIED|self::UNKNOWN $outcome
     * @param string $reason Machine-readable reason slug (e.g.
     *        'unknown_capability', 'feature_gate', 'not_affected_party').
     * @param string $source Which rule family produced the verdict
     *        ('fail_closed' | 'feature_access' | 'attestation_gate' |
     *        'permissions' | 'future' | 'live_gate').
     */
    private function __construct(
        public readonly string $outcome,
        public readonly string $reason,
        public readonly string $source,
    ) {
    }

    public static function allowed(string $source): self
    {
        return new self(self::ALLOWED, 'allowed', $source);
    }

    public static function denied(string $reason, string $source): self
    {
        return new self(self::DENIED, $reason, $source);
    }

    public static function unknown(string $reason, string $source): self
    {
        return new self(self::UNKNOWN, $reason, $source);
    }

    public function isAllowed(): bool
    {
        return $this->outcome === self::ALLOWED;
    }

    public function isDenied(): bool
    {
        return $this->outcome === self::DENIED;
    }

    /** ALLOWED or DENIED — i.e. comparable against a live gate verdict. */
    public function isDefinitive(): bool
    {
        return $this->outcome !== self::UNKNOWN;
    }
}
