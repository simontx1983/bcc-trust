<?php
/**
 * Seedable NON-OPEN GROUP SET for `PeepSoGroupRepository::getNonOpenGroupIdsStrict()`.
 *
 * Needed because bcc-core #111 lets a caller defer the group-tagged
 * permalink decision, which means the GroupSyndicationGate — previously
 * applied inside `ActivityFeedService::isVisibleGlobally()` — is now applied
 * by `FeedItemReportVisibility::groupRuleAllows()` instead. That pulls
 * `getNonOpenGroupIdsStrict()` onto a path this suite exercises.
 *
 * ⭐ WHY THE CACHE AND NOT `$wpdb`: that method reads the object cache BEFORE
 * the database and returns a cached ARRAY as-is, so seeding the cache gives a
 * deterministic answer without teaching a `$wpdb` double the method's JOIN.
 * Simulating the query would be testing the query, not the policy. This is
 * the same convention bcc-core's own ActivityFeedServiceTest uses via its
 * `bcc_test_seed_nonopen_groups()` helper.
 *
 * ⚠⚠ NOT ADDED TO `halls-gate-stubs.php` ON PURPOSE. Three other suites load
 * that file — including `ReportVisibilityGateIntegrationTest`, which runs the
 * real stack — and a namespaced `wp_cache_get` beats the global one for every
 * unqualified call inside `BCC\Core\Repositories`. Declaring it there would
 * silently disable object caching for those suites: the 2026-11-10
 * shared-stub hijack, which cost three suites, in a new costume.
 *
 * ⚠ KEY-AGNOSTIC by design. The real cache key embeds a generation counter
 * and the row limit; reproducing it would break this suite whenever either
 * changed, and what is under test is the GATE. The trade-off is that this
 * cannot model "the deny list is UNREADABLE" — that path returns null from
 * the database branch, and bcc-core owns the test for it.
 *
 * Fixture ($GLOBALS['__bcc_nonopen_groups']):
 *   []           => no group is non-open (what the suite defaults to, and the
 *                   pre-F-23 shape bcc-core's own tests pin)
 *   [<group_id>] => that group is non-open NOW — the state that models "made
 *                   public_all while the group was OPEN, group later closed
 *                   or went secret"
 *   unset        => a cache MISS, so the real code falls through to `$wpdb`.
 *                   Tests should not rely on that; seed explicitly.
 */

declare(strict_types=1);

namespace {
    /** @param list<int> $groupIds */
    function bcc_test_seed_nonopen_groups(array $groupIds): void
    {
        $GLOBALS['__bcc_nonopen_groups'] = array_values(array_map('intval', $groupIds));
    }
}

namespace BCC\Core\Repositories {
    if (!\function_exists('BCC\Core\Repositories\wp_cache_get')) {
        /** @return mixed */
        function wp_cache_get($key, $group = '')
        {
            unset($key, $group);
            $set = $GLOBALS['__bcc_nonopen_groups'] ?? null;

            return \is_array($set) ? $set : false;
        }
    }

    if (!\function_exists('BCC\Core\Repositories\wp_cache_set')) {
        function wp_cache_set($key, $value, $group = '', $ttl = 0): bool
        {
            unset($key, $value, $group, $ttl);

            return true;
        }
    }
}
