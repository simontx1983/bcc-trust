<?php

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Claim status — string-backed validator (mirrors the Disputes
 * DisputeStatus idiom). Only these values may appear in the
 * bcc_onchain_claims.status column: the three claim states, and the two
 * page team states (INVITED, ACTIVE).
 *
 * The Onchain context historically carried these states as bare string
 * literals with no typed home (domain-audit F5: the weakest-typed context).
 * This gives the behavior-gating claim state a canonical home — VERIFIED is
 * trust-conferring, so a silent typo persisting an invalid status must fail
 * loud, not slip through. assert() guards the persistence boundary
 * (ClaimRepository::upsert).
 *
 * @package BCC\Trust\Onchain\ValueObjects
 * @since Phase 9 domain-model clarity (2026-06-25)
 */
final class ClaimStatus
{
    /** Awaiting on-chain verification (the column default). */
    public const PENDING = 'pending';

    /** Wallet→entity relationship confirmed on-chain; confers trust. */
    public const VERIFIED = 'verified';

    /** Previously verified, since revoked (e.g. wallet unlinked / re-verify failed). */
    public const REVOKED = 'revoked';

    /**
     * A page TEAM row (administrator, representative) invited but not yet
     * accepted. Holds no capability.
     */
    public const INVITED = 'invited';

    /**
     * A page TEAM row the invitee accepted. Never 'verified': a team row is
     * not a claim, so readers of verified claims never see it.
     */
    public const ACTIVE = 'active';

    /** @var list<string> */
    private const ALL = [
        self::PENDING,
        self::VERIFIED,
        self::REVOKED,
        self::INVITED,
        self::ACTIVE,
    ];

    /**
     * Fail-fast validator: returns the value when valid, throws otherwise.
     * No defaults, no coercion — claim state is a state machine.
     */
    public static function assert(string $value): string
    {
        if (!in_array($value, self::ALL, true)) {
            throw new \LogicException("Invalid claim status: '{$value}'");
        }
        return $value;
    }
}
