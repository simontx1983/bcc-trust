<?php
/**
 * StewardshipCandidateRepository — enumerates the User-kind (member-
 * created) communities and their owners that the weekly stewardship
 * sweep considers (Rank redesign, helping emitters).
 *
 * "User-kind" mirrors PeepSoGroupRepository::countOwnedUserGroups
 * exactly: a published `peepso-group` post carrying NO `_bcc_group_kind`
 * post meta (LEFT JOIN … IS NULL). Kinded groups — hall / holders /
 * delegators / system — are infra- or gate-provisioned and NEVER earn a
 * member stewardship credit. Ownership = `gm_user_status = 'member_owner'`.
 *
 * This is a bcc-trust read against the PeepSo group tables — precedented
 * (HallRepository / GatedGroupRepository / GroupPostPolicyRepository all
 * read these tables here) and required because bcc-core exposes no
 * "list User-kind groups with owners" method and takes no change this
 * slice. Single bounded read (§4: explicit columns §2, `LIMIT %d`).
 *
 * @package BCC\Trust\Rank\Repositories
 * @since Rank redesign (helping emitters)
 */

declare(strict_types=1);

namespace BCC\Trust\Rank\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

class StewardshipCandidateRepository
{
    private const META_KIND    = '_bcc_group_kind';
    private const POST_TYPE     = 'peepso-group';
    private const POST_STATUS   = 'publish';
    private const OWNER_STATUS  = 'member_owner';

    /**
     * (group_id, owner_id) for up to $limit User-kind communities whose
     * gm_group_id is STRICTLY GREATER THAN $afterGroupId, ordered by
     * group id ASC (stable). One bounded aggregate-free read; a
     * peepso-group has exactly one member_owner row, so no dedupe is
     * needed.
     *
     * $afterGroupId is the sweep's wrap-around cursor (0 = from the
     * start) — StewardshipSweepService pages through the full set across
     * successive weekly runs so no community beyond the first $limit is
     * ever starved of stewardship credit.
     *
     * @return list<object{group_id: int, owner_id: int}>
     */
    public function listUserKindCommunitiesWithOwners(int $limit = 100, int $afterGroupId = 0): array
    {
        if ($limit <= 0) {
            return [];
        }

        global $wpdb;
        $members = $wpdb->prefix . 'peepso_group_members';

        /** @var list<object{group_id: numeric-string, owner_id: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT gm.gm_group_id AS group_id, gm.gm_user_id AS owner_id
               FROM {$members} gm
         INNER JOIN {$wpdb->posts} p ON p.ID = gm.gm_group_id
          LEFT JOIN {$wpdb->postmeta} pm_kind ON pm_kind.post_id = p.ID
                                             AND pm_kind.meta_key = %s
              WHERE gm.gm_user_status = %s
                AND p.post_type       = %s
                AND p.post_status     = %s
                AND pm_kind.meta_value IS NULL
                AND gm.gm_group_id    > %d
              ORDER BY gm.gm_group_id ASC
              LIMIT %d",
            self::META_KIND,
            self::OWNER_STATUS,
            self::POST_TYPE,
            self::POST_STATUS,
            $afterGroupId,
            $limit
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $groupId = (int) $row->group_id;
            $ownerId = (int) $row->owner_id;
            if ($groupId > 0 && $ownerId > 0) {
                $out[] = (object) ['group_id' => $groupId, 'owner_id' => $ownerId];
            }
        }
        return $out;
    }
}
