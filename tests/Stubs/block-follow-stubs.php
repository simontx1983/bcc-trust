<?php
/**
 * F-29 test world: bcc-core's follow writer and block read at their FQNs
 * (bcc-core is not autoloaded in bcc-trust's unit suite), plus
 * get_userdata in the WatchingService namespace. Separate processes only
 * (BlockSeversFollowsTest).
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit {
    final class BlockFollowWorld
    {
        /** @var array<string, bool|null> "a:b" (either order) => verdict */
        public static array $blocks = [];

        /** @var array<string, int> "active:passive" => uf_id */
        public static array $follows = [];

        /** @var list<array{int, int}> */
        public static array $followCalls = [];

        /** @var list<array{int, int}> */
        public static array $unfollowCalls = [];

        /** @var list<int> */
        public static array $metaDeleted = [];

        public static function reset(): void
        {
            self::$blocks = self::$follows = self::$followCalls = self::$unfollowCalls = self::$metaDeleted = [];
        }

        public static function pair(int $a, int $b): string
        {
            return min($a, $b) . ':' . max($a, $b);
        }
    }
}

namespace BCC\Core\Repositories {
    use BCC\Trust\Tests\Unit\BlockFollowWorld;

    final class PeepSoBlockRepository
    {
        public static function isMutuallyBlockedOrUnknown(int $userA, int $userB): ?bool
        {
            $k = BlockFollowWorld::pair($userA, $userB);
            return array_key_exists($k, BlockFollowWorld::$blocks) ? BlockFollowWorld::$blocks[$k] : false;
        }
    }
}

namespace BCC\Core\PeepSo {
    use BCC\Trust\Tests\Unit\BlockFollowWorld;

    final class PeepSoFollowWriter
    {
        public static function follow(int $activeUserId, int $passiveUserId): int
        {
            BlockFollowWorld::$followCalls[] = [$activeUserId, $passiveUserId];
            return BlockFollowWorld::$follows[$activeUserId . ':' . $passiveUserId] ?? 0;
        }

        public static function unfollow(int $activeUserId, int $passiveUserId): bool
        {
            BlockFollowWorld::$unfollowCalls[] = [$activeUserId, $passiveUserId];
            return isset(BlockFollowWorld::$follows[$activeUserId . ':' . $passiveUserId]);
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!function_exists(__NAMESPACE__ . '\\get_userdata')) {
        function get_userdata(int $id): object|false
        {
            return $id > 0 ? (object) ['ID' => $id] : false;
        }
    }
}
