<?php
/**
 * CapabilityCatalog — the canonical key list for the Rank-redesign
 * capability model (approved plan §25).
 *
 * AUTHORITATIVE since the Phase 5 atomic cutover — the resolver's
 * verdicts gate the write paths. Status per key:
 *
 *   DELEGATED — evaluated by delegating to the canonical gate:
 *     write_review  → rank_state row (Apprentice+; New Members denied)
 *                     AND Trust Neutral+ (CapabilityResolver::
 *                     writeReviewGate — Journeyman never required, §20.1)
 *     vouch         → AttestationService::checkCastEligibility (carries
 *                     the New-Member exclusion + tier ≥ Neutral +
 *                     fraud-clear + self-target)
 *     stand_behind  → same as vouch (the transactional slot check stays
 *                     inside cast() — race-correct there)
 *     open_dispute  → Permissions::is_not_suspended + owns_page
 *                     (the DisputeController chain; rank never blocks
 *                     opening, §20.4)
 *
 *   DEFERRED — the live gate cannot be re-evaluated side-effect-free
 *   (VoteEligibilityChecker::check consumes rate-limit quota), so the
 *   resolver returns UNKNOWN and the live gate remains the only judge:
 *     cast_meaningful_vote, downvote
 *
 *   FUTURE — no such feature exists yet; fail-closed DENY:
 *     create_community, transfer_community, receive_community,
 *     post_ranked_hall_feed, list_as_mentor,
 *     receive_rank_vote_multiplier, cast_dispute_vote
 *
 * KNOWN DIVERGENCE (documented, expected): `cast_dispute_vote` reports
 * DENY while the legacy fixed-panel vote route (POST /disputes/{id}/vote)
 * is still live — the approved plan retires the panel in Phase 6; the
 * resolver models the POST-retirement world. The resolver is not
 * consulted on that route, so nothing logs.
 *
 * Key names are implementation choices, not contract fields (§25).
 *
 * @package BCC\Trust\Core\Services\Capability
 * @since Rank redesign Phase 3 (2026-07-31)
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services\Capability;

if (!defined('ABSPATH')) {
    exit;
}

final class CapabilityCatalog
{
    public const CAST_MEANINGFUL_VOTE         = 'cast_meaningful_vote';
    public const DOWNVOTE                     = 'downvote';
    public const WRITE_REVIEW                 = 'write_review';
    public const VOUCH                        = 'vouch';
    public const STAND_BEHIND                 = 'stand_behind';
    public const OPEN_DISPUTE                 = 'open_dispute';
    public const CAST_DISPUTE_VOTE            = 'cast_dispute_vote';
    public const CREATE_COMMUNITY             = 'create_community';
    public const TRANSFER_COMMUNITY           = 'transfer_community';
    public const RECEIVE_COMMUNITY            = 'receive_community';
    public const POST_RANKED_HALL_FEED        = 'post_ranked_hall_feed';
    public const LIST_AS_MENTOR               = 'list_as_mentor';
    public const RECEIVE_RANK_VOTE_MULTIPLIER = 'receive_rank_vote_multiplier';

    /** Every key the resolver recognizes. Anything else fails closed. */
    public const KEYS = [
        self::CAST_MEANINGFUL_VOTE,
        self::DOWNVOTE,
        self::WRITE_REVIEW,
        self::VOUCH,
        self::STAND_BEHIND,
        self::OPEN_DISPUTE,
        self::CAST_DISPUTE_VOTE,
        self::CREATE_COMMUNITY,
        self::TRANSFER_COMMUNITY,
        self::RECEIVE_COMMUNITY,
        self::POST_RANKED_HALL_FEED,
        self::LIST_AS_MENTOR,
        self::RECEIVE_RANK_VOTE_MULTIPLIER,
    ];

    public static function isKnown(string $key): bool
    {
        return in_array($key, self::KEYS, true);
    }
}
