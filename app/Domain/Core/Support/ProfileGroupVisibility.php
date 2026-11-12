<?php
/**
 * Which of a member's groups may a given viewer see on that member's
 * PROFILE surfaces (F-270 / F-323).
 *
 * ⭐ ONE implementation, two callers, deliberately. The same fact — "which
 * communities does this person belong to" — is published by two different
 * surfaces:
 *
 *   - `UserGroupsEndpoint`  → the profile Groups tab
 *   - `UserViewService::resolveHalls()` → the `halls` field of the user
 *     view-model (misleadingly named: it carries EVERY group, not halls)
 *
 * Before this class they disagreed. The Groups tab at least stripped
 * secret groups from non-members; `resolveHalls()` applied no filter of
 * any kind and ignored the viewer completely, so the user view-model
 * published the owner's full membership — secret groups included — to any
 * profile reader (F-323, High). A second copy of these rules is how that
 * happened, so there must not be a third: a new surface that lists a
 * member's groups calls this.
 *
 * THE RULES (user decisions, 2026-11-12):
 *
 *   0. The owner always sees their own groups. Admins keep the F-02
 *      exemption, via `ProfileAccess::viewerIsExemptFromVisibility()`.
 *   1. "Show my groups on my profile" OFF ⇒ other viewers see only HALLS.
 *      Halls are auto-provisioned one-per-chain with open membership, so
 *      they disclose nothing private, and keeping them preserves the
 *      home-Hall chip on the profile header. Everything else is hidden.
 *   2. SECRET groups are shown ONLY to fellow members, never to an
 *      anonymous viewer. This holds regardless of the toggle — it is the
 *      F-323 fix, not a preference.
 *   3. NFT-holder and delegator groups are shown ONLY to SIGNED-IN
 *      viewers, even with the toggle ON. Membership of either discloses
 *      an on-chain financial position (that this person holds a
 *      qualifying NFT on a named chain; that they delegate to a named
 *      validator), and the rows carry the chain slug, so publishing them
 *      to anonymous readers was the disclosure F-270 was filed about.
 *
 * ⚠ An empty result is NOT an error and must not become a 403. A refusal
 * would itself disclose that there are groups being withheld; an empty
 * list discloses nothing. Both callers return an empty collection.
 *
 * @package BCC\Trust\Core\Support
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Support;

use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;

if (!defined('ABSPATH')) {
    exit;
}

final class ProfileGroupVisibility
{
    /** user_meta flag; ABSENT MEANS ON — see ProfileAccess::showsGroupsOnProfile(). */
    public const META_SHOW_GROUPS = ProfileAccess::META_SHOW_GROUPS;

    /**
     * Narrow `$contexts` to the groups `$viewerId` may see on
     * `$ownerId`'s profile.
     *
     * Takes resolved contexts rather than group ids because the Groups
     * tab already has them for its own enrichment — re-resolving there
     * would double the work on the busier of the two surfaces.
     *
     * Ordering is deliberate: every in-memory drop happens BEFORE the
     * membership query, so the query runs against the secret groups that
     * actually survived (often none) instead of all of them.
     *
     * @param array<int, GroupContext> $contexts keyed by group id
     * @return array<int, GroupContext> same keys, same order, subset
     */
    public static function filter(array $contexts, int $ownerId, int $viewerId): array
    {
        if ($contexts === [] || $ownerId <= 0) {
            return [];
        }

        // The owner's own tab is never filtered — they chose the setting
        // and must be able to see what it is hiding.
        if ($viewerId > 0 && $viewerId === $ownerId) {
            return $contexts;
        }

        // Same exemption the rest of F-02 grants, so an admin's view of a
        // profile does not silently differ between surfaces.
        if (ProfileAccess::viewerIsExemptFromVisibility()) {
            return $contexts;
        }

        $showGroups = ProfileAccess::showsGroupsOnProfile($ownerId);
        $anonymous  = $viewerId <= 0;

        $secretIds = [];
        foreach ($contexts as $groupId => $ctx) {
            // Rule 1 — toggle OFF: halls survive, nothing else does.
            if (!$showGroups && $ctx->type !== GroupType::Hall) {
                unset($contexts[$groupId]);
                continue;
            }

            // Rule 3 — an on-chain position is not for anonymous readers.
            if ($anonymous && $ctx->disclosesOnchainPosition()) {
                unset($contexts[$groupId]);
                continue;
            }

            // Rule 2 — secret: anonymous never, signed-in only if member.
            if ($ctx->privacy === PeepSoPrivacy::Secret) {
                if ($anonymous) {
                    unset($contexts[$groupId]);
                    continue;
                }
                $secretIds[] = $groupId;
            }
        }

        if ($secretIds === []) {
            return $contexts;
        }

        // One batched membership read for whatever secret groups are
        // left. ⚠ Fail-closed: `findUserMemberships` answering with an
        // empty map — whether the viewer really is in none of them or the
        // query failed — drops every secret group. Hiding one the viewer
        // could have seen is the harmless direction of that error.
        $viewerMemberships = PeepSoGroupRepository::findUserMemberships($viewerId, $secretIds);
        foreach ($secretIds as $groupId) {
            if (!isset($viewerMemberships[$groupId])) {
                unset($contexts[$groupId]);
            }
        }

        return $contexts;
    }
}
