<?php
/**
 * Stubs for FollowerListVisibilityWiringTest.
 *
 * ⚠⚠ SUBPROCESS-ONLY. This declares `\BCC\Core\Permissions\Permissions`
 * — a class two other stub files in this suite also declare — and shadows
 * `get_user_meta` inside `BCC\Trust\Core\Support`. A class declaration
 * cannot be undone and the first one wins for the rest of the process,
 * which is how 93 sibling tests were once silently broken here. The
 * consuming test is `#[RunTestsInSeparateProcesses]` +
 * `#[PreserveGlobalState(false)]`.
 *
 * ⛔ Never require this alongside `profile-access-stubs.php`.
 *
 * `PeepSoFollowerRepository` is a SPY, and that is the whole point of the
 * suite: the behaviour under test is WHICH ARGUMENTS the service passes,
 * which no return-value assertion can reach. Its returns are shaped to
 * exit the service early (a non-zero count, then an empty id list) so the
 * test needs no card-prefetch scaffolding to reach both calls.
 *
 * Fixture ($GLOBALS['__bcc_flw']):
 *   is_admin   => bool                       Permissions::is_admin()
 *   friend_ids => list<int>                  PeepSoFriendsModel::get_friends_ids()
 *   counts     => array{followers:int, following:int}
 *   calls      => list<array{method: string, args: list<mixed>}>
 */

declare(strict_types=1);

namespace {
    /** @return array{is_admin: bool, friend_ids: list<int>, counts: array{followers: int, following: int}, calls: list<array{method: string, args: list<mixed>}>} */
    function bcc_flw_fixture(): array
    {
        $f = $GLOBALS['__bcc_flw'] ?? [];

        return [
            'is_admin'   => (bool) ($f['is_admin'] ?? false),
            'friend_ids' => is_array($f['friend_ids'] ?? null) ? $f['friend_ids'] : [],
            'counts'     => is_array($f['counts'] ?? null) ? $f['counts'] : ['followers' => 0, 'following' => 0],
            'calls'      => is_array($f['calls'] ?? null) ? $f['calls'] : [],
        ];
    }

    // PeepSo's friends model, so the MAP → LIST conversion in
    // PeepSoFriendGate::viewerFriendIdList can be exercised with a
    // non-empty graph. In production this class is absent (peepso-friends
    // was removed), which is exactly why the conversion is otherwise
    // untestable: the list is always empty there.
    if (!class_exists('PeepSoFriendsModel', false)) {
        class PeepSoFriendsModel
        {
            public static function get_instance(): self
            {
                return new self();
            }

            /** @return list<int> */
            public function get_friends_ids(int $userId): array
            {
                unset($userId);

                return \bcc_flw_fixture()['friend_ids'];
            }
        }
    }
}

namespace BCC\Core\Permissions {
    if (!class_exists(Permissions::class, false)) {
        class Permissions
        {
            public static function is_admin(): bool
            {
                return \bcc_flw_fixture()['is_admin'];
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoFollowerRepository::class, false)) {
        class PeepSoFollowerRepository
        {
            /**
             * @param list<int>|null $viewerFriendIds
             * @return array{followers: int, following: int}
             */
            public static function getCounts(
                int $userId,
                int $viewerId = 0,
                ?array $viewerFriendIds = null,
                bool $enforceProfileVisibility = false
            ): array {
                $GLOBALS['__bcc_flw']['calls'][] = [
                    'method' => 'getCounts',
                    'args'   => [$userId, $viewerId, $viewerFriendIds, $enforceProfileVisibility],
                ];

                return \bcc_flw_fixture()['counts'];
            }

            /**
             * @param list<int>|null $viewerFriendIds
             * @return list<int>
             */
            public static function getFollowers(
                int $userId,
                int $limit = 200,
                int $offset = 0,
                int $viewerId = 0,
                ?array $viewerFriendIds = null,
                bool $enforceProfileVisibility = false
            ): array {
                $GLOBALS['__bcc_flw']['calls'][] = [
                    'method' => 'getFollowers',
                    'args'   => [$userId, $viewerId, $viewerFriendIds, $enforceProfileVisibility],
                ];

                // Empty, so the service returns before any hydration.
                return [];
            }

            /**
             * @param list<int>|null $viewerFriendIds
             * @return list<int>
             */
            public static function getFollowing(
                int $userId,
                int $limit = 200,
                int $offset = 0,
                int $viewerId = 0,
                ?array $viewerFriendIds = null,
                bool $enforceProfileVisibility = false
            ): array {
                $GLOBALS['__bcc_flw']['calls'][] = [
                    'method' => 'getFollowing',
                    'args'   => [$userId, $viewerId, $viewerFriendIds, $enforceProfileVisibility],
                ];

                return [];
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    // PrivacySettings::flag() reads this; '' means the watching_hidden
    // gate is OFF, so the service reaches the repository calls under test.
    if (!\function_exists('BCC\Trust\Core\Support\get_user_meta')) {
        /** @return string */
        function get_user_meta(int $userId, string $key, bool $single = false): string
        {
            unset($userId, $key, $single);

            return '';
        }
    }
}
