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

        /** @var list<string> "active:passive" follow-id reads that fail (M2) */
        public static array $failReads = [];

        /** @var list<string> "active:passive" unfollows that return false (M2) */
        public static array $failUnfollows = [];

        /** @var list<array{string, array<string, mixed>}> Logger::error calls */
        public static array $errors = [];

        public static function reset(): void
        {
            self::$blocks = self::$follows = self::$followCalls = self::$unfollowCalls = self::$metaDeleted = [];
            self::$failReads = self::$failUnfollows = self::$errors = [];
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
            if (in_array($activeUserId . ':' . $passiveUserId, BlockFollowWorld::$failUnfollows, true)) {
                return false;
            }
            return isset(BlockFollowWorld::$follows[$activeUserId . ':' . $passiveUserId]);
        }
    }
}

namespace BCC\Core\Log {
    use BCC\Trust\Tests\Unit\BlockFollowWorld;

    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                BlockFollowWorld::$errors[] = [$m, $c];
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
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
