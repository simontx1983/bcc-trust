<?php
/**
 * Group post-policy storage — per-group knobs that govern how members
 * may author posts in a group. Stored as post-meta on the `peepso-group`
 * CPT, mirroring the {@see \BCC\Trust\Onchain\Repositories\GatedGroupRepository}
 * pattern (no parallel ledger; the group post is the record).
 *
 * Keys:
 *   _bcc_group_public_all_members = '1'  → ordinary members of this
 *       (closed/secret) group may set visibility='public_all' and
 *       syndicate to the global feed. Absent/'' → owner/admin only
 *       (the restrictive default). No effect on open groups, where any
 *       posting member may already syndicate.
 *
 * @package BCC\Trust\Core\Repositories
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class GroupPostPolicyRepository
{
    public const META_PUBLIC_ALL_MEMBERS = '_bcc_group_public_all_members';

    /**
     * May ordinary members of this group syndicate to the global feed?
     * Reads WP's post-meta cache. Default (absent meta) is false — the
     * restrictive setting.
     */
    public static function publicAllMembersEnabled(int $groupId): bool
    {
        if ($groupId <= 0) {
            return false;
        }

        return get_post_meta($groupId, self::META_PUBLIC_ALL_MEMBERS, true) === '1';
    }

    /**
     * Enable/disable ordinary-member syndication for a group. Authorization
     * (owner/admin only) is the caller's responsibility — see
     * {@see \BCC\Trust\Core\Services\GroupsService::setPublicAllMembersPolicy}.
     * Deletes the meta on disable so the default stays a clean "absent".
     */
    public static function setPublicAllMembers(int $groupId, bool $enabled): void
    {
        if ($groupId <= 0) {
            return;
        }

        if ($enabled) {
            update_post_meta($groupId, self::META_PUBLIC_ALL_MEMBERS, '1');
        } else {
            delete_post_meta($groupId, self::META_PUBLIC_ALL_MEMBERS);
        }
    }
}
