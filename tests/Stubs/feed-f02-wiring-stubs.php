<?php
/**
 * Stubs for FeedPostVisibilityWiringTest (§F-02, posts half).
 *
 * ⚠⚠ SUBPROCESS-ONLY, and more aggressively so than the other stub
 * files here: this declares SEVEN classes that also exist for real
 * (`BCC\Core\Feed\ActivityFeedService`, `PeepSoBlockRepository`,
 * `Permissions`, `ReputationRepository`, `HiddenActivityRepository`,
 * `GroupContextResolver`, `FeedHydrationPipeline`, `RestrictedGroups`).
 * A class declaration cannot be undone and the first one wins for the
 * rest of the process. The consuming test is
 * `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`.
 *
 * ⭐ WHY DECLARING REAL CLASSES WORKS: Composer's autoloader only runs
 * when a class is first referenced. Declaring these before anything
 * touches them means `class_exists(..., false)` is already true and the
 * real file is never loaded. The same trick halls-cache-stubs.php uses
 * for `BCC\Trust\Core\Plugin`.
 *
 * ⚠ WHAT IS DELIBERATELY NOT STUBBED: `ProfileAccess` and
 * `PeepSoFriendGate` are the REAL classes. They are what the test is
 * about — the decision (enforce / friend list) has to be the production
 * one, or the suite only proves that a stub returns what it was told.
 *
 * ⚠ THE STUB CANNOT VALIDATE THE NAMED-ARGUMENT NAMES. Production code
 * passes `viewerFriendIds:` / `enforcePostVisibility:` by name; if a
 * name were wrong in BOTH the code and this stub, every assertion here
 * would still pass. What rules that out is PHPStan, which analyses
 * bcc-trust against the real `../bcc-core/src/` and rejects an unknown
 * named parameter. Treat a green run of this file as evidence about
 * WIRING, and PHPStan as the evidence about NAMES.
 *
 * Fixture ($GLOBALS['__bcc_f02feed']):
 *   is_admin   => bool          what Permissions::is_admin() returns
 *   friend_ids => list<int>     what PeepSoFriendsModel hands back
 *   calls      => list<array{method: string, viewer: int,
 *                            friends: mixed, enforce: mixed,
 *                            defer: mixed}>
 *
 * Group-rule keys (the permalink's second look):
 *   group_rule       => bool   what canSeeHydratedItem() answers
 *                              (DEFAULT true, so a forgotten fixture
 *                              cannot make a refusal test pass)
 *   group_rule_calls => list<array{viewer: int, post_id: mixed}>  spy log
 *
 * Permalink-only keys, for the §F-02 group carve-out
 * ({@see FeedRankingService::groupTaggedDespiteProfileLevel()}):
 *   row_enforced   => array|null  what getActivityById() returns when the
 *                                 gate RAN (null models an F-02 refusal)
 *   row_unenforced => array|null  what it returns on the second look
 *                                 (null models the GROUP's own rules
 *                                 refusing — `isVisibleGlobally()` and
 *                                 the module allowlist live in bcc-core,
 *                                 so from here they are that null)
 *   group_by_post  => array<int, int>  external_id => peepso_group_id;
 *                                 absent = not a group post
 *
 * ⚠ `row_enforced` DEFAULTS TO A ROW, not to null. A permalink that
 * resolves is the ordinary case, and it keeps the second look from
 * firing in tests that are not about it — several of them assert that
 * exactly ONE read was forwarded.
 */

declare(strict_types=1);

namespace {
    /** The external_id (backing wp_post) of the default permalink row. */
    const BCC_F02FEED_DEFAULT_POST = 5150;

    /**
     * @return array{is_admin: bool, friend_ids: list<int>, calls: list<array<string, mixed>>,
     *               row_enforced: array<string, mixed>|null,
     *               row_unenforced: array<string, mixed>|null,
     *               group_by_post: array<int, int>}
     */
    function bcc_f02feed_fixture(): array
    {
        $f = $GLOBALS['__bcc_f02feed'] ?? [];

        // `array_key_exists`, not `??` — an explicit null is the whole
        // point of `row_enforced` and must not fall back to the default.
        $enforced = array_key_exists('row_enforced', $f)
            ? $f['row_enforced']
            : ['external_id' => BCC_F02FEED_DEFAULT_POST];

        return [
            'is_admin'       => (bool) ($f['is_admin'] ?? false),
            'friend_ids'     => is_array($f['friend_ids'] ?? null) ? $f['friend_ids'] : [],
            'calls'          => is_array($f['calls'] ?? null) ? $f['calls'] : [],
            'row_enforced'   => is_array($enforced) ? $enforced : null,
            'row_unenforced' => is_array($f['row_unenforced'] ?? null) ? $f['row_unenforced'] : null,
            'group_by_post'  => is_array($f['group_by_post'] ?? null) ? $f['group_by_post'] : [],
        ];
    }

    /**
     * F-455: `feed_rows` (list of rows, default none), the page a list read
     * answers. Empty unless a test opts in, as every other suite expects.
     *
     * @return list<array<string, mixed>>
     */
    function bcc_f02feed_rows(): array
    {
        $rows = $GLOBALS['__bcc_f02feed']['feed_rows'] ?? [];

        return is_array($rows) ? array_values($rows) : [];
    }

    /** Record one forwarded call's §F-02 arguments. */
    function bcc_f02feed_record(string $method, int $viewer, $friends, $enforce, $defer = false): void
    {
        $GLOBALS['__bcc_f02feed']['calls'][] = [
            'method'  => $method,
            'viewer'  => $viewer,
            'friends' => $friends,
            'enforce' => $enforce,
            // Only the permalink's second look may set this. Every other
            // surface records the default, so a stray `true` anywhere
            // else is visible rather than silent.
            'defer'   => $defer,
        ];
    }

    // The friend graph. Absent in production (peepso-friends is not
    // installed), so the test declares it in order to exercise the
    // level-30 rung AT ALL — see the "empty in production" warning on
    // ProfileAccess::postVisibilityArgs().
    if (!class_exists('PeepSoFriendsModel', false)) {
        class PeepSoFriendsModel
        {
            private static ?self $instance = null;

            public static function get_instance(): self
            {
                return self::$instance ??= new self();
            }

            /** @return list<int> */
            public function get_friends_ids(int $userId): array
            {
                unset($userId);

                return \bcc_f02feed_fixture()['friend_ids'];
            }
        }
    }
}

namespace BCC\Core\Permissions {
    final class Permissions
    {
        public static function is_admin(): bool
        {
            return \bcc_f02feed_fixture()['is_admin'];
        }
    }
}

namespace BCC\Core\Feed {
    /**
     * The recorder. Every method keeps the real parameter ORDER and the
     * real parameter NAMES, because production calls the last two by
     * name; it keeps nothing else, and returns an EMPTY page on purpose
     * so the service's block-probe and hydration paths short-circuit and
     * no further collaborator is needed.
     */
    final class ActivityFeedService
    {
        public const SCOPE_FOR_YOU   = 'for_you';
        public const SCOPE_FOLLOWING = 'following';
        public const SCOPE_SIGNALS   = 'signals';
        public const SCOPE_GROUP     = 'group';
        public const SCOPE_GROUPS    = 'groups';
        public const MEMBER_GROUPS_CAP = 200;

        /** @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}} */
        public function getFeed(
            int $viewerId,
            string $scope,
            ?string $cursor = null,
            int $limit = 20,
            ?array $excludedAuthorIds = null,
            ?array $excludedActIds = null,
            ?int $onlyForGroupId = null,
            ?array $excludedGroupIds = null,
            ?array $groupVisibilityIn = null,
            ?string $hashtag = null,
            ?string $hallFeed = null,
            ?array $viewerFriendIds = null,
            bool $enforcePostVisibility = false,
            ?array $onlyForGroupIds = null,
            ?array $softExcludedAuthorIds = null,
            ?array $softExclusionExemptGroupIds = null
        ): array {
            // F-276: every argument, in call order, so a surface's
            // limit / cursor / hashtag can be asserted and a refactor
            // can be diffed call for call.
            $GLOBALS['__bcc_f02feed']['getfeed_args'][] = [
                'viewerId'              => $viewerId,
                'scope'                 => $scope,
                'cursor'                => $cursor,
                'limit'                 => $limit,
                'excludedAuthorIds'     => $excludedAuthorIds,
                'excludedActIds'        => $excludedActIds,
                'onlyForGroupId'        => $onlyForGroupId,
                'excludedGroupIds'      => $excludedGroupIds,
                'groupVisibilityIn'     => $groupVisibilityIn,
                'hashtag'               => $hashtag,
                'hallFeed'              => $hallFeed,
                'viewerFriendIds'       => $viewerFriendIds,
                'enforcePostVisibility' => $enforcePostVisibility,
                // F-416, the "My Groups" channel.
                'onlyForGroupIds'             => $onlyForGroupIds,
                'softExcludedAuthorIds'       => $softExcludedAuthorIds,
                'softExclusionExemptGroupIds' => $softExclusionExemptGroupIds,
            ];

            unset(
                $cursor, $limit, $excludedActIds,
                $excludedGroupIds, $groupVisibilityIn, $hallFeed
            );

            // The scope/group pair is what tells the group feed apart
            // from the three global ones, which matters because they are
            // deliberately gated differently.
            $label = $onlyForGroupId !== null && $onlyForGroupId > 0
                ? 'getFeed:group'
                : ($hashtag !== null ? 'getFeed:tag' : 'getFeed:' . $scope);

            // F-297: the SQL author exclusion each surface forwards.
            $GLOBALS['__bcc_f02feed']['excluded'][$label] = $excludedAuthorIds;

            \bcc_f02feed_record($label, $viewerId, $viewerFriendIds, $enforcePostVisibility);

            // F-455: `feed_rows` (opt-in, default none) gives the page rows,
            // so the exact block check after the read has authors to check.
            return ['items' => \bcc_f02feed_rows(), 'pagination' => ['next_cursor' => null, 'has_more' => false]];
        }

        /** @return array<string, mixed>|null */
        public function getActivityById(
            int $actId,
            int $viewerId,
            ?array $excludedAuthorIds = null,
            ?array $excludedActIds = null,
            ?array $viewerFriendIds = null,
            bool $enforcePostVisibility = false,
            bool $deferGroupVisibilityToCaller = false
        ): ?array {
            $GLOBALS['__bcc_f02feed']['excluded']['getActivityById'] = $excludedAuthorIds;
            // F-451: every permalink read's exclusion list, in call order.
            $GLOBALS['__bcc_f02feed']['excluded_by_read'][] = $excludedAuthorIds;
            $GLOBALS['__bcc_f02feed']['hidden_by_read'][]   = $excludedActIds;
            unset($actId, $excludedActIds);
            \bcc_f02feed_record(
                'getActivityById',
                $viewerId,
                $viewerFriendIds,
                $enforcePostVisibility,
                $deferGroupVisibilityToCaller
            );

            $fixture = \bcc_f02feed_fixture();

            // The only stub method whose RETURN is load-bearing: the
            // permalink's second look is driven entirely by the first
            // call answering null.
            $row = $enforcePostVisibility
                ? $fixture['row_enforced']
                : $fixture['row_unenforced'];

            // As bcc-core does: a row whose author is on the exclusion list
            // is not returned (F-451's caution and block lists). Rows with
            // no `author` key are never excluded, as before.
            $authorId = is_array($row) && is_array($row['author'] ?? null) ? (int) ($row['author']['id'] ?? 0) : 0;
            if ($authorId > 0 && in_array($authorId, array_map('intval', $excludedAuthorIds ?? []), true)) {
                return null;
            }

            return $row;
        }

        /** @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}} */
        public function getActivityForAuthor(
            int $authorId,
            int $viewerId,
            ?string $cursor = null,
            int $limit = 20,
            ?array $excludedAuthorIds = null,
            ?array $excludedActIds = null,
            ?array $excludedGroupIds = null,
            ?array $viewerFriendIds = null,
            bool $enforcePostVisibility = false,
            ?array $moduleIds = null
        ): array {
            $GLOBALS['__bcc_f02feed']['excluded']['getActivityForAuthor'] = $excludedAuthorIds;
            // F-341: the module ALLOWLIST the wall asked for. Recorded
            // separately from the F-02 record because it answers a
            // different question — WHICH KINDS, not which viewer.
            $GLOBALS['__bcc_f02feed']['module_ids']['getActivityForAuthor'] = $moduleIds;
            unset($authorId, $cursor, $limit, $excludedAuthorIds, $excludedActIds, $excludedGroupIds);
            \bcc_f02feed_record('getActivityForAuthor', $viewerId, $viewerFriendIds, $enforcePostVisibility);

            return ['items' => \bcc_f02feed_rows(), 'pagination' => ['next_cursor' => null, 'has_more' => false]];
        }

        /** @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}} */
        public function getActivityForPage(
            int $pageId,
            int $viewerId,
            ?string $cursor = null,
            int $limit = 20,
            ?array $excludedAuthorIds = null,
            ?array $excludedActIds = null,
            ?array $viewerFriendIds = null,
            bool $enforcePostVisibility = false
        ): array {
            $GLOBALS['__bcc_f02feed']['excluded']['getActivityForPage'] = $excludedAuthorIds;
            unset($pageId, $cursor, $limit, $excludedAuthorIds, $excludedActIds);
            \bcc_f02feed_record('getActivityForPage', $viewerId, $viewerFriendIds, $enforcePostVisibility);

            return ['items' => [], 'pagination' => ['next_cursor' => null, 'has_more' => false]];
        }
    }
}

namespace BCC\Core\Repositories {
    final class PeepSoBlockRepository
    {
        // ⚠ F-455: NO getBlockedIds() / getBlockerIds() here. They answer []
        // on a failed read, and the feed must never call them again, so a
        // call is a fatal in these suites rather than a silent pass.

        /**
         * `blocked` (list<int>, default none): who the viewer blocks;
         * `blocked_by_viewer` (viewer id => list<int>) overrides it per viewer.
         * `blocked_fails` (bool): the read did not run (null). Every read is
         * logged in `block_reads`.
         *
         * @return list<int>|null
         */
        public static function getBlockedIdsOrUnknown(int $blockerId, int $limit = 500): ?array
        {
            unset($limit);
            $GLOBALS['__bcc_f02feed']['block_reads'][] = ['blocked', $blockerId];
            if (!empty($GLOBALS['__bcc_f02feed']['blocked_fails'])) {
                return null;
            }

            return $GLOBALS['__bcc_f02feed']['blocked_by_viewer'][$blockerId]
                ?? $GLOBALS['__bcc_f02feed']['blocked']
                ?? [];
        }

        /**
         * `blockers` (list<int>, default none): who blocks the viewer.
         * `blockers_fails` (bool): the read did not run (null).
         *
         * @return list<int>|null
         */
        public static function getBlockerIdsOrUnknown(int $blockedId, int $limit = 500): ?array
        {
            unset($limit);
            $GLOBALS['__bcc_f02feed']['block_reads'][] = ['blockers', $blockedId];
            if (!empty($GLOBALS['__bcc_f02feed']['blockers_fails'])) {
                return null;
            }

            return $GLOBALS['__bcc_f02feed']['blockers'] ?? [];
        }

        /**
         * `mutual_blocked` (list<int> author ids, default none): the
         * authors the viewer and they have blocked between them.
         * `mutual_fails` (bool): the exact check did not run (null), the
         * F-455 race after the pre-filter's reads succeeded.
         *
         * @return array<int, true>|null
         */
        public static function getMutuallyBlockedSetOrUnknown(int $viewerId, array $candidateIds): ?array
        {
            unset($viewerId);
            if (!empty($GLOBALS['__bcc_f02feed']['mutual_fails'])) {
                return null;
            }
            $set = [];
            foreach ($GLOBALS['__bcc_f02feed']['mutual_blocked'] ?? [] as $id) {
                if (in_array((int) $id, array_map('intval', $candidateIds), true)) {
                    $set[(int) $id] = true;
                }
            }

            return $set;
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    final class ReputationRepository
    {
        /** @return list<int> */
        public function getCautionAndRiskyUserIds(): array
        {
            return $GLOBALS['__bcc_f02feed']['caution'] ?? [];
        }
    }

    final class HiddenActivityRepository
    {
        /** @return list<int> */
        public function getAllHiddenIds(): array
        {
            // F-438: `hidden_throws` (bool): the read failed with no
            // last-good list, which the real repository throws for.
            if (!empty($GLOBALS['__bcc_f02feed']['hidden_throws'])) {
                throw new \BCC\Trust\Core\Exceptions\RepositoryException('hidden list unreadable, no last-good copy');
            }

            // F-451: `hidden` (list<int> act ids, default none).
            $hidden = $GLOBALS['__bcc_f02feed']['hidden'] ?? [];

            return is_array($hidden) ? array_values(array_map('intval', $hidden)) : [];
        }

        public static function getGeneration(): int
        {
            return 1;
        }
    }
}

namespace BCC\Trust\Core\Services {
    /**
     * The GROUP's own visibility rule, stubbed as a SPY.
     *
     * The permalink's second look reads unenforced and so MUST apply this
     * instead; the interesting assertions are that it is consulted at all,
     * that its refusal is honoured, and that it is asked about the viewer.
     * A return-value-only stub would let "never consulted" pass.
     */
    final class FeedItemReportVisibility
    {
        public function canSeeHydratedItem(int $viewerId, array $item): bool
        {
            $GLOBALS['__bcc_f02feed']['group_rule_calls'][] = [
                'viewer'  => $viewerId,
                'post_id' => $item['external_id'] ?? null,
            ];

            // Defaults to ALLOW, so a test that forgets to set it does not
            // accidentally pass for the wrong reason; the refusal case sets
            // it explicitly.
            return (bool) ($GLOBALS['__bcc_f02feed']['group_rule'] ?? true);
        }
    }

    final class GroupContextResolver
    {
        /**
         * `group_contexts` (array<int, GroupContext>, default none): the
         * room each group id resolves to. F-451's third look asks it whether
         * the room lifts the shadow-limit.
         */
        public function forGroup(int $groupId): ?\BCC\Trust\Core\ValueObjects\GroupContext
        {
            // ⚠ Default none: a suite that never sets it gets null, as before.
            $ctx = $GLOBALS['__bcc_f02feed']['group_contexts'][$groupId] ?? null;

            return $ctx instanceof \BCC\Trust\Core\ValueObjects\GroupContext ? $ctx : null;
        }

        /**
         * F-438: "My Groups" asks each of the viewer's rooms at once.
         *
         * @param list<int> $groupIds
         * @return array<int, \BCC\Trust\Core\ValueObjects\GroupContext|null>
         */
        public function forManyGroups(array $groupIds): array
        {
            $out = [];
            foreach ($groupIds as $groupId) {
                $out[(int) $groupId] = $this->forGroup((int) $groupId);
            }

            return $out;
        }
    }
}

namespace BCC\Trust\Core\Support {
    // ⚠ ProfileAccess and PeepSoFriendGate live in this namespace too and
    // are deliberately NOT declared here — they are the real thing.
    final class RestrictedGroups
    {
        /** @return list<int> */
        public static function forViewer(int $viewerId): array
        {
            unset($viewerId);

            return [];
        }
    }
}

namespace BCC\Trust\Core\Services\Feed {
    final class FeedHydrationPipeline
    {
        /**
         * @param list<array<string, mixed>> $items
         * @return list<array<string, mixed>>
         */
        public function hydrate(array $items, int $viewerId): array
        {
            // F-276: which viewer a page was hydrated for — the
            // viewer_reaction / vouch / can_report chrome follows it.
            $GLOBALS['__bcc_f02feed']['hydrated_for'][] = $viewerId;

            return $items;
        }
    }

    // The hot feed's object cache. Kept per-process and always a MISS on
    // the first read, because a hit would return before reaching
    // ActivityFeedService and the test would assert nothing.
    if (!\function_exists('BCC\Trust\Core\Services\Feed\wp_cache_get')) {
        /** @return mixed */
        function wp_cache_get(string $key, string $group = '')
        {
            // F-276: which shared-cache keys a surface read.
            $GLOBALS['__bcc_f02feed']['cache_reads'][] = $group . '/' . $key;

            return false;
        }
    }

    // The group marker the permalink's second look reads. Namespaced so
    // it shadows the global for FeedRankingService only, exactly as the
    // cache functions below do.
    if (!\function_exists('BCC\Trust\Core\Services\Feed\get_post_meta')) {
        /** @return mixed */
        function get_post_meta(int $postId, string $key = '', bool $single = false)
        {
            if ($key !== 'peepso_group_id') {
                return $single ? '' : [];
            }

            // `group_by_post` maps a post to one group id, or (F-451) to a
            // list of them for a post tagged into several rooms. As
            // WordPress answers: $single gives the FIRST value ('' for an
            // unmapped post, which `(int)` turns into the 0 that means
            // "not a group post"); otherwise every value, as strings.
            $mapped = \bcc_f02feed_fixture()['group_by_post'][$postId] ?? null;
            $values = $mapped === null ? [] : (is_array($mapped) ? array_values($mapped) : [$mapped]);
            if ($single) {
                return $values === [] ? '' : $values[0];
            }

            return array_map('strval', $values);
        }
    }

    if (!\function_exists('BCC\Trust\Core\Services\Feed\wp_cache_set')) {
        /** @param mixed $value */
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            unset($value, $ttl);
            // F-276: which shared-cache keys a surface wrote.
            $GLOBALS['__bcc_f02feed']['cache_writes'][] = $group . '/' . $key;

            return true;
        }
    }
}

namespace BCC\Trust\Core\Support {
    // ProfileAccess::viewerIsExemptFromVisibility() needs no viewer id,
    // but other ProfileAccess paths call get_current_user_id(); shadow it
    // so nothing in this namespace reaches WordPress.
    if (!\function_exists('BCC\Trust\Core\Support\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return 0;
        }
    }

    // ProfileAccess::userIsExemptFromVisibility() asks about a GIVEN user
    // (canView()'s mentionee), unlike the `is_admin` key, which models the
    // request's user. Fixture key `admin_ids` (list<int>) lists the users
    // holding `manage_options`. Outside this suite's fixture it defers to
    // whatever global user_can() another suite defined, so it changes
    // nothing for them.
    if (!\function_exists('BCC\Trust\Core\Support\user_can')) {
        function user_can($user, string $capability, ...$args): bool
        {
            if (!isset($GLOBALS['__bcc_f02feed'])) {
                return \function_exists('user_can') && \user_can($user, $capability, ...$args);
            }
            $id = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;

            return $capability === 'manage_options'
                && in_array($id, $GLOBALS['__bcc_f02feed']['admin_ids'] ?? [], true);
        }
    }

    // ⚠⚠ REQUIRED, not optional. F-341 made the author wall consult
    // ProfileSectionVisibility, which reads user_meta with an UNQUALIFIED
    // call from THIS namespace — as does PrivacySettings, for the §K2
    // `bcc_privacy_*` keys. Without this shadow every wall test dies with
    // "Call to undefined function BCC\Trust\Core\Support\get_user_meta()",
    // a FATAL rather than a failed assertion, which is far easier to
    // misread as a broken test than as a missing stub.
    //
    // ⚠ ProfileSectionVisibility and PrivacySettings are the REAL classes
    // here. Only the storage is faked, so the suite still exercises the
    // actual switch semantics — including "absent meta means SHOWN",
    // which is why an unset key returns '' exactly as WordPress does.
    if (!\function_exists('BCC\Trust\Core\Support\get_user_meta')) {
        /** @return mixed */
        function get_user_meta($userId, $key = '', $single = false)
        {
            unset($single);
            $meta = $GLOBALS['__bcc_f02feed']['section_meta'] ?? [];

            return $meta[(int) $userId][$key] ?? '';
        }
    }
}
