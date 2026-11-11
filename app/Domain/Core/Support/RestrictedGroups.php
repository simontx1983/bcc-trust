<?php

/**
 * RestrictedGroups — "which PeepSo groups may this viewer NOT see".
 *
 * The computation used to live as `private static` inside
 * FeedRankingService, where only the feed could reach it. The profile
 * Blog tab needs the same answer once a blog post can live inside a
 * group, so it was extracted here rather than copied (§11). The feed's
 * own method now delegates, so there is exactly one definition of
 * "restricted" in the codebase.
 *
 * Two entry points, deliberately different in their failure behaviour:
 *
 *   forViewer()           — the feed's historical contract. Returns a
 *                           best-effort list and never signals doubt.
 *   forViewerOrNull()     — returns NULL when the list cannot be proven
 *                           complete, for callers that use the list as
 *                           their ONLY group gate and must fail closed.
 *
 * @package BCC\Trust\Core\Support
 * @since §D6 group blogs
 */

namespace BCC\Trust\Core\Support;

use BCC\Core\Repositories\PeepSoGroupRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class RestrictedGroups
{
    /**
     * Membership-lookup bound, carried over verbatim from
     * FeedRankingService so the feed's behaviour does not change.
     *
     * ⚠ A viewer in more than this many groups gets a truncated
     * membership list, so groups they really belong to stay on the
     * restricted list and their own group content is hidden from them.
     * That direction is SAFE (it hides more, never less) and is
     * unreachable in practice, so it is recorded rather than fixed.
     */
    private const MEMBER_GROUPS_LOOKUP = 1000;

    /**
     * The bound to ask the non-open lookup for.
     *
     * The LENIENT `getNonOpenGroupIds()` clamps to its own private
     * ceiling and returns a plain list with NO signal that rows were
     * dropped — it orders by `p.ID DESC` and truncates, so the OLDEST
     * non-open groups silently fall off the end — and it also flattens a
     * FAILED query into an empty list. Both of those under-restrict,
     * which is the leaking direction.
     *
     * {@see self::forViewerOrNull()} therefore does not use it. It calls
     * `getNonOpenGroupIdsStrict()`, which returns null for a query error
     * and for truncation and caches neither. Kept in step with bcc-core's
     * `NONOPEN_DEFAULT_LIMIT`, which is private there.
     */
    private const NON_OPEN_BOUND = 500;

    /**
     * Groups the viewer may not see: every non-open group, minus the
     * ones they are a member of. An anonymous viewer (id <= 0) is
     * restricted from all of them.
     *
     * Behaviourally identical to the FeedRankingService original.
     *
     * @return list<int>
     */
    public static function forViewer(int $viewerId): array
    {
        $nonOpen = PeepSoGroupRepository::getNonOpenGroupIds(self::NON_OPEN_BOUND);
        if ($nonOpen === []) {
            return [];
        }
        if ($viewerId <= 0) {
            return $nonOpen;
        }
        $myGroups = PeepSoGroupRepository::getUserMemberGroupIds($viewerId, self::MEMBER_GROUPS_LOOKUP);
        if ($myGroups === []) {
            return $nonOpen;
        }

        return array_values(array_diff($nonOpen, $myGroups));
    }

    /**
     * Same answer, but NULL when the non-open set may be incomplete.
     *
     * For a caller that replaces a visibility gate with this list — the
     * profile Blog tab does exactly that — an incomplete list is not a
     * degraded answer, it is a LEAK: a restricted group missing from the
     * list is a group whose posts are shown. Such a caller must pass
     * null (keep its gate) rather than an under-populated list, and must
     * never substitute `[]`, which asserts "restricted from nothing".
     *
     * @return list<int>|null null = cannot prove complete, fail closed
     */
    public static function forViewerOrNull(int $viewerId): ?array
    {
        // STRICT: null for a query error AND for truncation, caching
        // neither. ⚠ Do NOT swap this for the lenient getNonOpenGroupIds().
        // That one flattens a FAILED query into [], and [] here would be
        // forwarded to the caller as "this viewer is restricted from
        // nothing" — which relaxes its gate with no list behind it. null is
        // the only safe answer when the set cannot be proven.
        $nonOpen = PeepSoGroupRepository::getNonOpenGroupIdsStrict(self::NON_OPEN_BOUND);
        if ($nonOpen === null) {
            return null;
        }

        // A PROVEN-empty non-open set: nothing on the site is non-open, so
        // nothing is restricted. Because the strict lookup refuses to
        // answer on failure, this [] cannot be a disguised error.
        if ($nonOpen === []) {
            return [];
        }
        if ($viewerId <= 0) {
            return $nonOpen;
        }
        $myGroups = PeepSoGroupRepository::getUserMemberGroupIds($viewerId, self::MEMBER_GROUPS_LOOKUP);
        if ($myGroups === []) {
            return $nonOpen;
        }

        return array_values(array_diff($nonOpen, $myGroups));
    }

    /**
     * The groups the viewer BELONGS to, for the members_only parity rule:
     * a member reads every visibility, while a non-member of a group they
     * can merely SEE reads only public_group / public_all.
     *
     * ⚠ Truncation here is SAFE in a way it is not for the deny list. A
     * short membership list makes the viewer look like a non-member of
     * some group, which shows LESS, so the lenient lookup is the right
     * one and its bound is not a correctness problem.
     *
     * @return list<int>
     */
    public static function memberGroupsForViewer(int $viewerId): array
    {
        if ($viewerId <= 0) {
            return [];
        }

        return PeepSoGroupRepository::getUserMemberGroupIds($viewerId, self::MEMBER_GROUPS_LOOKUP);
    }
}
