<?php
/**
 * Public-All Authoring Policy — who may set `visibility='public_all'`
 * on a GROUP-scoped post (i.e. syndicate it to the global / anonymous
 * feed).
 *
 * The feed reader itself honours `public_all` regardless of group
 * privacy (Gate-12 = Option B, "public_all wins"); this policy gates the
 * *authoring* side — a rank-and-file member of a closed/secret group
 * must not be able to push content to everyone unless the group opts in.
 *
 * Rules (single canonical decision):
 *   - No membership row, or a non-posting status (`member_readonly`,
 *     `pending_*`, `banned`, …) → DENY (fail closed).
 *   - Elevated role (owner / manager / moderator) → ALLOW, any privacy.
 *   - Ordinary `member`:
 *       · Open group          → ALLOW (open membership; syndication is
 *                               the expected default).
 *       · Closed / Secret     → ALLOW iff the group owner opted ordinary
 *                               members in.
 *       · Unknown privacy     → DENY (restrictive default).
 *
 * Pure by construction — no WordPress, PeepSo, or singleton access — so
 * the full authorization matrix is unit-testable without stubs. The
 * caller ({@see \BCC\Trust\Core\Services\GroupsService::canUsePublicAll})
 * resolves the privacy, membership status, and opt-in flag and delegates
 * here.
 *
 * The posting-capable set mirrors
 * {@see \BCC\Trust\Core\Services\CommentService::WRITE_ALLOWED_STATUSES}
 * byte-for-byte (that const is private to the comment domain; posting a
 * status is a distinct action, so the parity is documented rather than
 * shared to avoid coupling comment-write policy to post-write policy).
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;

if (!defined('ABSPATH')) {
    exit;
}

final class PublicAllPolicy
{
    /**
     * PeepSo membership statuses that may author a post at all
     * (`member_readonly` reads but cannot contribute). Mirrors
     * CommentService::WRITE_ALLOWED_STATUSES.
     *
     * @var list<string>
     */
    public const POSTING_CAPABLE_STATUSES = [
        'member',
        'member_owner',
        'member_manager',
        'member_moderator',
    ];

    /**
     * The group-admin roles that may always syndicate, independent of
     * privacy or the ordinary-member opt-in. `member_manager` and
     * `member_moderator` are PeepSo's administrative roles alongside the
     * single `member_owner`.
     *
     * @var list<string>
     */
    public const ELEVATED_STATUSES = [
        'member_owner',
        'member_manager',
        'member_moderator',
    ];

    /**
     * The single authorization decision.
     *
     * @param PeepSoPrivacy $privacy               Resolved group privacy.
     * @param string|null   $membershipStatus      PeepSoGroupRepository::getMembershipStatus() (null = no row).
     * @param bool          $ordinaryMembersEnabled Group opt-in: may ordinary members syndicate from a closed/secret group?
     */
    public static function canSyndicate(
        PeepSoPrivacy $privacy,
        ?string $membershipStatus,
        bool $ordinaryMembersEnabled
    ): bool {
        // Fail closed: no row, or a status that cannot author (readonly,
        // pending, banned, or anything unrecognised).
        if ($membershipStatus === null
            || !in_array($membershipStatus, self::POSTING_CAPABLE_STATUSES, true)
        ) {
            return false;
        }

        // Group admins may always syndicate.
        if (in_array($membershipStatus, self::ELEVATED_STATUSES, true)) {
            return true;
        }

        // Ordinary posting member — privacy decides.
        return match ($privacy) {
            PeepSoPrivacy::Open              => true,
            PeepSoPrivacy::Closed,
            PeepSoPrivacy::Secret            => $ordinaryMembersEnabled,
        };
    }
}
