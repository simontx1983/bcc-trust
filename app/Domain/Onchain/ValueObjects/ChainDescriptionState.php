<?php

declare(strict_types=1);

/**
 * Approval state for an imported BLOCKCHAIN COLLECTION DESCRIPTION.
 *
 * ── TWO DESCRIPTIONS, ON PURPOSE ────────────────────────────────────────
 * BCC keeps two, and they are not interchangeable:
 *
 *   • THIS one describes the NFT collection. It is imported from chain
 *     metadata a contract author controls, so it is untrusted evidence and
 *     stays private until an administrator approves it.
 *
 *   • COMMUNITY ABOUT is the PeepSo community biography, written by community
 *     managers. Its provisioning default — "On-chain verified holders of
 *     {name}. Auto-managed." — is a COMMUNITY default, not a collection
 *     description.
 *
 * A scan may never write Community About, and approving a description may
 * never touch it. The suite asserts each can change while the other stays
 * byte-identical.
 *
 * ── WHY A STATE AND NOT A BOOLEAN ───────────────────────────────────────
 * `approved = 0` cannot distinguish "nobody has looked" from "an
 * administrator read it and said no". Without that distinction a rejected
 * description reappears in the review queue on every scan, and an operator
 * cannot tell a new claim from one already refused.
 *
 * @package BCC\Trust\Onchain\ValueObjects
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class ChainDescriptionState
{
    /** No description has been imported. The column is NULL. */
    public const NONE = 'none';

    /** Imported, awaiting an administrator. NEVER publicly serialized. */
    public const PENDING = 'pending';

    /** An administrator approved this exact text. Public APIs may return it. */
    public const APPROVED = 'approved';

    /** An administrator refused it. Kept so it is not re-queued forever. */
    public const REJECTED = 'rejected';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::NONE, self::PENDING, self::APPROVED, self::REJECTED];
    }

    public static function isValid(string $state): bool
    {
        return in_array($state, self::all(), true);
    }

    /**
     * The ONLY state whose text may leave the backend publicly.
     *
     * Membership in a list, not `!== PENDING` — an unknown or corrupted value
     * must read as "not public", and a negated check would publish it.
     */
    public static function isPubliclyVisible(string $state): bool
    {
        return $state === self::APPROVED;
    }

    /**
     * Transitions an administrator may perform.
     *
     * `approved → pending` is deliberately allowed: a later scan that finds
     * DIFFERENT text must re-enter review rather than inherit the approval of
     * text nobody has read. The writer enforces that by resetting the state
     * whenever the description bytes change.
     *
     * @return list<string>
     */
    public static function allowedTransitionsFrom(string $state): array
    {
        return match ($state) {
            self::NONE     => [self::PENDING],
            self::PENDING  => [self::APPROVED, self::REJECTED],
            self::APPROVED => [self::PENDING, self::REJECTED],
            self::REJECTED => [self::PENDING],
            default        => [],
        };
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedTransitionsFrom($from), true);
    }
}
