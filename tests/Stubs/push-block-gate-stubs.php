<?php
/**
 * F-74 test world for PushDispatcher::enqueue().
 *
 * Loaded in SEPARATE processes only (PushBlockGateTest), so the bcc-core
 * class stubs below win over anything autoloaded.
 *
 *  - PeepSoBlockRepository: answers from PushBlockWorld (true / false /
 *    null = unreadable) and records every pair it was asked about.
 *  - get_user_meta (NotificationPrefs' namespace): every push pref ON, so
 *    the only gate under test is the block gate.
 *  - AsyncDispatcher / transients: record what got queued.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit {
    final class PushBlockWorld
    {
        /** @var array<string, bool|null> "a:b" (either order) => verdict */
        public static array $blocks = [];

        /** @var list<array{int, int}> */
        public static array $asked = [];

        /** @var list<array{string, list<mixed>}> */
        public static array $scheduled = [];

        /** @var array<string, mixed> */
        public static array $transients = [];

        /** @var list<string> */
        public static array $warnings = [];

        public static function reset(): void
        {
            self::$blocks = self::$asked = self::$scheduled = self::$transients = self::$warnings = [];
        }

        public static function key(int $a, int $b): string
        {
            return min($a, $b) . ':' . max($a, $b);
        }
    }
}

namespace BCC\Core\Repositories {
    use BCC\Trust\Tests\Unit\PushBlockWorld;

    final class PeepSoBlockRepository
    {
        public static function isMutuallyBlockedOrUnknown(int $userA, int $userB): ?bool
        {
            PushBlockWorld::$asked[] = [$userA, $userB];
            $k = PushBlockWorld::key($userA, $userB);
            return array_key_exists($k, PushBlockWorld::$blocks) ? PushBlockWorld::$blocks[$k] : false;
        }
    }
}

namespace BCC\Core\Cron {
    use BCC\Trust\Tests\Unit\PushBlockWorld;

    final class AsyncDispatcher
    {
        /** @param list<mixed> $args */
        public static function scheduleSingle(int $timestamp, string $hook, array $args = [], string $group = ''): void
        {
            PushBlockWorld::$scheduled[] = [$hook, $args];
        }
    }
}

namespace BCC\Core\Log {
    use BCC\Trust\Tests\Unit\PushBlockWorld;

    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $ctx */
            public static function warning(string $msg, array $ctx = []): void
            {
                PushBlockWorld::$warnings[] = $msg;
            }
            /** @param array<string, mixed> $ctx */
            public static function error(string $msg, array $ctx = []): void
            {
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    /** @return mixed */
    function get_user_meta(int $userId, string $key, bool $single = false)
    {
        return '1';
    }
}

namespace {
    use BCC\Trust\Tests\Unit\PushBlockWorld;

    if (!function_exists('get_transient')) {
        /** @return mixed */
        function get_transient(string $key)
        {
            return PushBlockWorld::$transients[$key] ?? false;
        }
    }
    if (!function_exists('set_transient')) {
        /** @param mixed $value */
        function set_transient(string $key, $value, int $ttl = 0): bool
        {
            PushBlockWorld::$transients[$key] = $value;
            return true;
        }
    }
}
