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
 *   4. (F-341 PR 3) An individual HALL the owner has hidden is dropped,
 *      even while the Halls section itself is shown. Absent meta means
 *      nothing is hidden.
 *
 *      ⚠ This is NOT dominated by rule 1's toggle, which is the opposite
 *      of what a reader might assume. Toggle OFF leaves halls — and ONLY
 *      halls — visible, so with it off the hidden ones are the only
 *      choice still doing any work. It therefore applies in both states.
 *
 *      ⚠ Scoped to `GroupType::Hall` deliberately, matching the ratified
 *      name `bcc_hidden_hall_ids`. The check is keyed on group id, so
 *      widening it to any group is a one-line change IF that is ever
 *      decided — but it must be decided, not drifted into, because the
 *      meta name would then be misleading and this codebase has already
 *      paid for two of those (`halls` carrying every group;
 *      `manual_collection_discovery_enabled` authorising nothing
 *      chain-wide).
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

        // The owner's own view is never filtered, and an admin keeps the
        // F-02 exemption. ONE copy of both, shared with
        // hidesPrimaryHall() below: before F-401 these two rules lived
        // only here, and the home-Hall chip published by getSummary()
        // answered to neither.
        if (self::viewerSeesEverything($ownerId, $viewerId)) {
            return $contexts;
        }

        // ⚠ The viewer goes with it, so an unreadable flag hides
        // rather than publishing the groups of a member who opted out.
        // viewerSeesEverything() has already answered the owner and the
        // exempt, so this is the strict answer by the time it is asked.
        $showGroups = ProfileAccess::showsGroupsOnProfile($ownerId, $viewerId);
        $anonymous  = $viewerId <= 0;
        // ONE read for the whole page, after the owner and admin
        // short-circuits above — neither of them is filtered, so neither
        // should pay for this.
        $hiddenHalls = ProfileAccess::hiddenHallIds($ownerId);

        $secretIds = [];
        foreach ($contexts as $groupId => $ctx) {
            // Rule 1 — toggle OFF: halls survive, nothing else does.
            if (!$showGroups && $ctx->type !== GroupType::Hall) {
                unset($contexts[$groupId]);
                continue;
            }

            // Rule 4 — this HALL was hidden individually. Checked after
            // rule 1 but before the queries below, keeping the ordering
            // promise in the docblock: every in-memory drop happens
            // before the membership read, so a hidden secret Hall never
            // reaches it.
            if ($hiddenHalls !== []
                && $ctx->type === GroupType::Hall
                && in_array($groupId, $hiddenHalls, true)
            ) {
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

    /**
     * Does $viewerId see $ownerId's groups exactly as the owner does?
     *
     * The owner chose the setting and must be able to see what it is
     * hiding; an admin keeps the exemption the rest of F-02 grants, so a
     * moderator's view of a profile does not silently differ between
     * surfaces.
     *
     * ⭐ Extracted for F-401 rather than copied into the new rule. Two
     * copies of an EXEMPTION fail in the direction that hides a Hall
     * from the one person entitled to see it, and this class already
     * carries the scar of two copies of a visibility rule (F-323).
     */
    private static function viewerSeesEverything(int $ownerId, int $viewerId): bool
    {
        if ($viewerId > 0 && $viewerId === $ownerId) {
            return true;
        }

        // ⚠ Resolved for the VIEWER BEING SERVED. This read the
        // REQUEST's user, which is the right answer only when the two
        // are the same person. `getSummary($id, 0)` deliberately
        // composes for an anonymous viewer while an admin may be the
        // one asking, and the request user's exemption then skipped
        // this whole filter for a viewer who has none. See
        // {@see ProfileAccess::exemptFor()}, the one copy of the rule.
        return ProfileAccess::exemptFor($viewerId);
    }

    /**
     * F-401 — is the owner's HOME-HALL chip withheld from this viewer?
     *
     * The user decision (2026-11-18): hiding a Hall hides the home-Hall
     * chip too when that Hall is the member's primary. Rule 4 above
     * already drops it from the `halls` LIST; the chip is the same fact
     * published as a single field, and leaving it visible would have
     * defeated the choice in the one place it is most prominent.
     *
     * ⚠ The group TYPE is not re-resolved here, deliberately. The
     * pointer this id comes from (`bcc_primary_hall_group_id`) is written
     * only by {@see \BCC\Trust\Core\Services\HallsService::setPrimaryHall()},
     * which requires a Hall, so a context lookup would buy nothing and
     * would cost one resolver call PER ROW on every directory page. If
     * the pointer ever did aim at a non-Hall, hiding it is the harmless
     * direction — rule 4's type scope exists to stop the hide list
     * reaching OTHER groups, not to protect this one id.
     *
     * ⚠ Costs no query on a list path: the hidden-list read is
     * `get_user_meta`, and WP caches a user's whole meta row set on
     * first touch — `MemberSummaryPrefetcher::primeFor()` primes it for
     * the entire page via `update_meta_cache()`, and `getSummary()`
     * reads privacy meta for the row before reaching this.
     */
    public static function hidesPrimaryHall(int $ownerId, int $groupId, int $viewerId): bool
    {
        if ($ownerId <= 0 || $groupId <= 0) {
            return false;
        }

        if (self::viewerSeesEverything($ownerId, $viewerId)) {
            return false;
        }

        return in_array($groupId, ProfileAccess::hiddenHallIds($ownerId), true);
    }
}
