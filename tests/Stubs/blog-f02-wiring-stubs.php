<?php
/**
 * Stubs for BlogTabPostVisibilityWiringTest (§F-02, posts half).
 *
 * ⚠⚠ SUBPROCESS-ONLY. Declares `PeepSoActivityRepository`,
 * `HiddenActivityRepository`, `RestrictedGroups`, `Permissions` and
 * `Logger`, all of which exist for real, and shadows
 * `get_current_user_id()` / `get_userdata()` inside
 * `BCC\Trust\Core\Services`. A class declaration cannot be undone and
 * the first one wins for the rest of the process. The consuming test is
 * `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`.
 *
 * ⛔ NEVER load this beside feed-f02-wiring-stubs.php: both declare
 * `\BCC\Core\Permissions\Permissions` and `RestrictedGroups`.
 *
 * ⚠ `ProfileAccess` and `PeepSoFriendGate` are again the REAL classes —
 * the decision under test has to be the production one.
 *
 * The repository returns an EMPTY row set on purpose: with no rows,
 * `hydrateGroupBlocks()`, the normaliser loop and the shortcode batch
 * all short-circuit, so this harness never has to fake the blog
 * rendering chain. The query ARGUMENTS are the whole subject.
 *
 * Fixture ($GLOBALS['__bcc_f02blog']):
 *   is_admin   => bool                     Permissions::is_admin()
 *   friends_by => array<int, list<int>>    friend ids PER USER ID
 *   session    => int                      get_current_user_id()
 *   calls      => list<array{viewer: int, friends: mixed, enforce: mixed}>
 *
 * ⚠ `friends_by` is keyed BY USER ID on purpose. A stub that returned
 * one list whatever it was asked makes "whose friend list" unobservable
 * — and that is not hypothetical: with a flat list, swapping the gate's
 * viewer from the session to the caller's argument changed nothing any
 * assertion could see, and the mutation control reported SURVIVED.
 */

declare(strict_types=1);

namespace {
    /**
     * @return array{is_admin: bool, friends_by: array<int, list<int>>, session: int, calls: list<array<string, mixed>>}
     */
    function bcc_f02blog_fixture(): array
    {
        $f = $GLOBALS['__bcc_f02blog'] ?? [];

        return [
            'is_admin'   => (bool) ($f['is_admin'] ?? false),
            'friends_by' => is_array($f['friends_by'] ?? null) ? $f['friends_by'] : [],
            'session'    => (int) ($f['session'] ?? 0),
            'calls'      => is_array($f['calls'] ?? null) ? $f['calls'] : [],
        ];
    }

    // ⚠ GLOBAL, not namespaced, and that is the point: BlogService asks
    // `function_exists('get_current_user_id')` before calling it, and
    // that string is resolved in the GLOBAL namespace. A namespaced stub
    // alone leaves the guard false, the session silently 0, and the test
    // asserting against the anonymous posture it meant to contrast with.
    if (!\function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return \bcc_f02blog_fixture()['session'];
        }
    }

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
                return \bcc_f02blog_fixture()['friends_by'][$userId] ?? [];
            }
        }
    }
}

namespace BCC\Core\Permissions {
    final class Permissions
    {
        public static function is_admin(): bool
        {
            return \bcc_f02blog_fixture()['is_admin'];
        }
    }
}

namespace BCC\Core\Log {
    final class Logger
    {
        /** @param array<string, mixed> $context */
        public static function warning(string $message, array $context = []): void
        {
            unset($message, $context);
        }
    }
}

namespace BCC\Core\Repositories {
    final class PeepSoActivityRepository
    {
        public const GROUP_GATE_AUTHOR_SCOPED = 'author_scoped';
        public const GROUP_GATE_VIEWER_ACCESS = 'viewer_access';
        public const PAGE_TARGET_META         = '_bcc_page_target';

        /**
         * Same parameter ORDER and the same NAMES for the last two as the
         * real method, because production passes them by name. Everything
         * else is discarded.
         *
         * @return list<object>
         */
        public static function getActivities(
            ?array $authorIds = null,
            ?array $modules = null,
            ?string $cursorTime = null,
            ?int $cursorActId = null,
            int $limit = 20,
            ?array $excludedAuthorIds = null,
            ?array $excludedActIds = null,
            ?int $onlyForGroupId = null,
            ?array $excludedGroupIds = null,
            ?array $groupVisibilityIn = null,
            ?string $hashtag = null,
            ?string $hallFeed = null,
            ?int $onlyForPageId = null,
            ?array $restrictedGroupIds = null,
            ?string $groupGateMode = null,
            ?array $memberGroupIds = null,
            int $viewerId = 0,
            ?array $viewerFriendIds = null,
            bool $enforcePostVisibility = false
        ): array {
            unset(
                $authorIds, $modules, $cursorTime, $cursorActId, $limit,
                $excludedAuthorIds, $excludedActIds, $onlyForGroupId,
                $excludedGroupIds, $groupVisibilityIn, $hashtag, $hallFeed,
                $onlyForPageId, $restrictedGroupIds, $groupGateMode,
                $memberGroupIds
            );

            $GLOBALS['__bcc_f02blog']['calls'][] = [
                'viewer'  => $viewerId,
                'friends' => $viewerFriendIds,
                'enforce' => $enforcePostVisibility,
            ];

            return [];
        }
    }
}

namespace BCC\Trust\Core\Repositories {
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

namespace BCC\Trust\Core\Support {
    final class RestrictedGroups
    {
        /** @return list<int>|null */
        public static function forViewerOrNull(int $viewerId): ?array
        {
            unset($viewerId);

            return [];
        }

        /** @return list<int>|null */
        public static function memberGroupsForViewer(int $viewerId): ?array
        {
            unset($viewerId);

            return [];
        }
    }

    if (!\function_exists('BCC\Trust\Core\Support\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return \bcc_f02blog_fixture()['session'];
        }
    }

    // ⚠ F-341: BlogService::getUserBlog() now asks ProfileSectionVisibility
    // whether the owner shows their Blogs section, and that reads user_meta
    // with an UNQUALIFIED call from THIS namespace. Without the shim this
    // suite fatals with "Call to undefined function
    // BCC\Trust\Core\Support\get_user_meta()" — a FATAL, so the whole class
    // dies at the call rather than failing an assertion.
    //
    // Returns '' unconditionally: absent meta means the section is SHOWN,
    // which is what every assertion in this file assumes. This suite is
    // about the §F-02 per-post gate, not about section switches.
    if (!\function_exists('BCC\Trust\Core\Support\get_user_meta')) {
        /** @return mixed */
        function get_user_meta($userId, $key = '', $single = false)
        {
            unset($userId, $key, $single);

            return '';
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!\function_exists('BCC\Trust\Core\Services\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return \bcc_f02blog_fixture()['session'];
        }
    }

    // false short-circuits hydrateAuthor() before PublicIdentity and the
    // avatar cache, which this test has no business loading.
    if (!\function_exists('BCC\Trust\Core\Services\get_userdata')) {
        /** @return false */
        function get_userdata(int $userId): bool
        {
            unset($userId);

            return false;
        }
    }
}
