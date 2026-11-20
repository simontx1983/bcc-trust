<?php
/**
 * SecretPageFeedProjectionTest: the feed readers around a SECRET page's
 * announcement activity (module 205).
 *
 * ⚠ Required from a test's setUp(), i.e. inside its separate process —
 * NEVER at file level — and AFTER tests/Stubs/secret-page-list-stubs.php,
 * whose `$wpdb` (BccSecretPageListWpdb) answers bcc-core's page-privacy reads
 * from `$GLOBALS['__bcc_spl']`.
 *
 * REAL: FeedRankingService (its page filters, the refill, the hot-feed cache
 * split), AnnouncementPageAccess, AnnouncementRepository::pageIdsFor() (its
 * SQL is answered by the `$wpdb` below), PageAccess, PageDiscoveryRepository,
 * ProfileAccess and bcc-core's PeepSoPagePrivacy.
 *
 * Doubled, because they are not what is under test: bcc-core's
 * ActivityFeedService (it answers pages from a fixture), the block, reputation
 * and hidden-activity reads, hydration (it returns the items it is given),
 * RestrictedGroups, ProfileSectionVisibility, PeepSoFriendGate, and the admin
 * check.
 *
 * Fixture ($GLOBALS['__bcc_spf']):
 *   pages    => array<string, array{items: list<array>, pagination: array}>
 *               keyed by cursor ('' = the first page)
 *   by_id    => array<int, array>   what ActivityFeedService::getActivityById returns
 *   blocked  => list<int>           authors mutually blocked with the viewer
 *   parents  => array<int, int>     announcement id => page id (post_parent)
 *   fail_parents => bool            the post_parent read fails
 *   is_admin => bool                the REQUEST's user is an admin (Permissions::is_admin)
 *   current  => int                 the request's user (default 0: nobody, so a viewer
 *                                   is judged by their own exemption, from `admins`)
 *   admins   => list<int>           users user_can($id, 'manage_options') answers true for
 *   reads    => list<string>        "method:cursor" per ActivityFeedService read
 *   cache    => array<string, mixed> the object cache
 */

declare(strict_types=1);

namespace {
    if (!function_exists('wp_cache_get')) {
        /** @return mixed */
        function wp_cache_get(string $key, string $group = '')
        {
            return $GLOBALS['__bcc_spf']['cache'][$group . ':' . $key] ?? false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        /** @param mixed $value */
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            $GLOBALS['__bcc_spf']['cache'][$group . ':' . $key] = $value;

            return true;
        }
    }

    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_spf']['current'] ?? 0);
        }
    }

    if (!function_exists('user_can')) {
        function user_can(int $userId, string $capability): bool
        {
            return $capability === 'manage_options' && in_array($userId, $GLOBALS['__bcc_spf']['admins'] ?? [], true);
        }
    }

    if (!function_exists('bcc_spf_item')) {
        /** @return array<string, mixed> */
        function bcc_spf_item(int $actId, string $kind, int $externalId, int $authorId): array
        {
            return [
                'id'          => 'feed_' . $actId,
                'post_kind'   => $kind,
                'external_id' => $externalId,
                'author'      => ['kind' => 'user', 'id' => $authorId],
            ];
        }
    }

    if (!function_exists('bcc_spf_page')) {
        /**
         * @param list<array<string, mixed>> $items
         * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
         */
        function bcc_spf_page(array $items, ?string $next = null): array
        {
            return ['items' => $items, 'pagination' => ['next_cursor' => $next, 'has_more' => $next !== null]];
        }
    }

    if (!function_exists('bcc_spf_parents_sql')) {
        /**
         * The `$wpdb->other` handler: answers AnnouncementRepository::pageIdsFor()
         * from the fixture, and nothing else.
         *
         * @return list<object>
         */
        function bcc_spf_parents_sql(string $sql): array
        {
            if (!str_contains($sql, 'p.post_parent AS page_id')) {
                return [];
            }
            $GLOBALS['__bcc_spf']['parent_reads'][] = $sql;
            if (!empty($GLOBALS['__bcc_spf']['fail_parents'])) {
                $GLOBALS['wpdb']->last_error = 'MySQL server has gone away';

                return [];
            }
            $rows = [];
            foreach (\BccSecretPageListWpdb::inList($sql) as $id) {
                $page = $GLOBALS['__bcc_spf']['parents'][$id] ?? 0;
                if ($page > 0) {
                    $rows[] = (object) ['announcement_id' => (string) $id, 'page_id' => (string) $page];
                }
            }

            return $rows;
        }
    }
}

namespace BCC\Core\Permissions {
    if (!class_exists(__NAMESPACE__ . '\\Permissions', false)) {
        final class Permissions
        {
            public static function is_admin(): bool
            {
                return !empty($GLOBALS['__bcc_spf']['is_admin']);
            }
        }
    }
}

namespace BCC\Core\Feed {
    if (!class_exists(__NAMESPACE__ . '\\ActivityFeedService', false)) {
        final class ActivityFeedService
        {
            public const SCOPE_FOR_YOU = 'for_you';
            public const SCOPE_GROUP   = 'group';
            // F-438: "My Groups" (F-416), so that surface can be walked too.
            public const SCOPE_GROUPS      = 'groups';
            public const MEMBER_GROUPS_CAP = 200;

            /** bcc-core #158: no author name is masked in this harness. */
            public static function authorReadFailures(): int
            {
                return 0;
            }

            /**
             * @param mixed ...$rest
             * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
             */
            public function getFeed(int $viewerId, string $scope, ?string $cursor, int $limit, ...$rest): array
            {
                return self::page('getFeed', $cursor);
            }

            /**
             * @param mixed ...$rest
             * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
             */
            public function getActivityForAuthor(int $authorId, int $viewerId, ?string $cursor, int $limit, ...$rest): array
            {
                return self::page('getActivityForAuthor', $cursor);
            }

            /**
             * @param mixed ...$rest
             * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
             */
            public function getActivityForPage(int $pageId, int $viewerId, ?string $cursor, int $limit, ...$rest): array
            {
                return self::page('getActivityForPage', $cursor);
            }

            /**
             * @param mixed ...$rest
             * @return array<string, mixed>|null
             */
            public function getActivityById(int $actId, int $viewerId, ...$rest): ?array
            {
                $GLOBALS['__bcc_spf']['reads'][] = 'getActivityById:' . $actId;

                return $GLOBALS['__bcc_spf']['by_id'][$actId] ?? null;
            }

            /** @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}} */
            private static function page(string $method, ?string $cursor): array
            {
                $GLOBALS['__bcc_spf']['reads'][] = $method . ':' . ($cursor ?? '');

                return $GLOBALS['__bcc_spf']['pages'][$cursor ?? '']
                    ?? ['items' => [], 'pagination' => ['next_cursor' => null, 'has_more' => false]];
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\PeepSoBlockRepository', false)) {
        final class PeepSoBlockRepository
        {
            // F-438 / F-455: the feed's pre-filter reads these (null = the
            // read did not run). The old getBlockedIds() / getBlockerIds()
            // are gone, so a feed calling them again is a fatal here.

            /** @return list<int>|null */
            public static function getBlockedIdsOrUnknown(int $blockerId, int $limit = 500): ?array
            {
                return [];
            }

            /** @return list<int>|null */
            public static function getBlockerIdsOrUnknown(int $blockedId, int $limit = 500): ?array
            {
                return [];
            }

            /**
             * @param list<int> $candidateIds
             * @return array<int, true>|null
             */
            public static function getMutuallyBlockedSetOrUnknown(int $viewerId, array $candidateIds): ?array
            {
                $set = [];
                foreach ($GLOBALS['__bcc_spf']['blocked'] ?? [] as $id) {
                    if (in_array($id, $candidateIds, true)) {
                        $set[$id] = true;
                    }
                }

                return $set;
            }
        }
    }
}

namespace BCC\Core\Repositories {
    // F-438: "My Groups" reads the viewer's rooms first
    // (`my_groups` => list<int>, default none).
    if (!class_exists(__NAMESPACE__ . '\\PeepSoGroupRepository', false)) {
        final class PeepSoGroupRepository
        {
            /** @return list<int>|null */
            public static function getActiveMemberGroupIdsOrFail(int $userId, int $limit = 200): ?array
            {
                return $GLOBALS['__bcc_spf']['my_groups'] ?? [];
            }
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\ReputationRepository', false)) {
        final class ReputationRepository
        {
            // F-454: the feeds read the EXCLUDING reader. The fail-safe
            // getCautionAndRiskyUserIds() is gone here, so a feed calling it
            // again is a fatal.

            /** @return list<int> */
            public function getCautionAndRiskyUserIdsForExclusion(): array
            {
                return [];
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\HiddenActivityRepository', false)) {
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
}

namespace BCC\Trust\Core\Services {
    if (!class_exists(__NAMESPACE__ . '\\GroupContextResolver', false)) {
        final class GroupContextResolver
        {
            public function forGroup(int $groupId): ?object
            {
                return null;
            }

            /**
             * F-438: "My Groups" asks each room at once; none lifts the
             * shadow-limit here.
             *
             * @param list<int> $groupIds
             * @return array<int, null>
             */
            public function forManyGroups(array $groupIds): array
            {
                return array_fill_keys($groupIds, null);
            }
        }
    }
}

namespace BCC\Trust\Core\Services\Feed {
    if (!class_exists(__NAMESPACE__ . '\\FeedHydrationPipeline', false)) {
        /** Returns the items it is given: hydration is not what is under test. */
        final class FeedHydrationPipeline
        {
            /**
             * @param list<array<string, mixed>> $items
             * @return list<array<string, mixed>>
             */
            public function hydrate(array $items, int $viewerId): array
            {
                return $items;
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    if (!class_exists(__NAMESPACE__ . '\\RestrictedGroups', false)) {
        final class RestrictedGroups
        {
            /** @return list<int> */
            public static function forViewer(int $viewerId): array
            {
                return [];
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\PeepSoFriendGate', false)) {
        final class PeepSoFriendGate
        {
            /** @return list<int> */
            public static function viewerFriendIdList(int $viewerId): array
            {
                return [];
            }
        }
    }

    if (!class_exists(__NAMESPACE__ . '\\ProfileSectionVisibility', false)) {
        final class ProfileSectionVisibility
        {
            public const SECTION_BLOGS   = 'blogs';
            public const SECTION_REVIEWS = 'reviews';

            public static function hides(string $section, int $ownerId, int $viewerId): bool
            {
                return false;
            }
        }
    }
}
