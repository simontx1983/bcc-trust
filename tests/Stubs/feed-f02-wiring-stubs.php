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
            bool $enforcePostVisibility = false
        ): array {
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

            return ['items' => [], 'pagination' => ['next_cursor' => null, 'has_more' => false]];
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
            unset($actId, $excludedAuthorIds, $excludedActIds);
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
            return $enforcePostVisibility
                ? $fixture['row_enforced']
                : $fixture['row_unenforced'];
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
            bool $enforcePostVisibility = false
        ): array {
            $GLOBALS['__bcc_f02feed']['excluded']['getActivityForAuthor'] = $excludedAuthorIds;
            unset($authorId, $cursor, $limit, $excludedAuthorIds, $excludedActIds, $excludedGroupIds);
            \bcc_f02feed_record('getActivityForAuthor', $viewerId, $viewerFriendIds, $enforcePostVisibility);

            return ['items' => [], 'pagination' => ['next_cursor' => null, 'has_more' => false]];
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
        /** @return list<int> */
        public static function getBlockedIds(int $userId): array
        {
            unset($userId);

            return $GLOBALS['__bcc_f02feed']['blocked'] ?? [];
        }

        /** @return list<int> */
        public static function getBlockerIds(int $userId): array
        {
            unset($userId);

            return $GLOBALS['__bcc_f02feed']['blockers'] ?? [];
        }

        /** @return array<int, true>|null */
        public static function getMutuallyBlockedSetOrUnknown(int $viewerId, array $candidateIds): ?array
        {
            unset($viewerId, $candidateIds);

            return [];
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
            return [];
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
        public function forGroup(int $groupId): ?object
        {
            unset($groupId);

            return null;
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
            unset($viewerId);

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
            unset($key, $group);

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
            unset($single);
            if ($key !== 'peepso_group_id') {
                return '';
            }

            // '' for an unmapped post — what WordPress returns for absent
            // meta, and what `(int)` then turns into the 0 that means
            // "not a group post".
            return \bcc_f02feed_fixture()['group_by_post'][$postId] ?? '';
        }
    }

    if (!\function_exists('BCC\Trust\Core\Services\Feed\wp_cache_set')) {
        /** @param mixed $value */
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            unset($key, $value, $group, $ttl);

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
}
