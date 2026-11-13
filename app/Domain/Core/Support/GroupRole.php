<?php
/**
 * GroupRole — the one public vocabulary for a member's role in a group.
 *
 * Shared by the group roster (§4.7.7, GroupMembersService) and the
 * profile's group list (MemberProfile.halls[].role), so a member reads as
 * the same role wherever they appear.
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupRole
{
    /**
     * PeepSo gm_user_status → the public role: `member_owner` → `owner`,
     * `member_moderator` / `member_manager` → `moderator`, anything else →
     * `member`.
     *
     * `member_manager` is folded into `moderator` because the FE doesn't
     * need to distinguish the two PeepSo flavors; both are
     * "above-baseline-but-not-owner" roles. If a future surface needs the
     * manager/moderator split it can re-derive from the raw status, but the
     * public contract stays narrow.
     *
     * @return 'owner'|'moderator'|'member'
     */
    public static function normalize(string $rawStatus): string
    {
        return match ($rawStatus) {
            'member_owner'                       => 'owner',
            'member_moderator', 'member_manager' => 'moderator',
            default                              => 'member',
        };
    }

    /**
     * Server-rendered label per §A2 / §L5. Frontend prints verbatim — if a
     * deployment localizes via `bcc_group_role_labels`, that copy lands here
     * too. Single source of truth for "what does a role READ as on the page."
     */
    public static function label(string $role): string
    {
        $defaults = [
            'owner'     => 'OWNER',
            'moderator' => 'MODERATOR',
            'member'    => 'MEMBER',
        ];

        /** @var array<string, string> $map */
        $map = apply_filters('bcc_group_role_labels', $defaults);

        return $map[$role] ?? $defaults[$role] ?? 'MEMBER';
    }
}
