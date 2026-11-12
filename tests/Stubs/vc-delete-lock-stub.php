<?php

declare(strict_types=1);

/**
 * F-130: the per-collection provisioning lock the Verify Collections delete
 * takes. Required ONLY by VerifyCollectionsHandlerSecurityTest, which runs
 * each test in a separate process — so this double never competes with the
 * richer AdvisoryLock doubles other suites declare (provisioning-intent-stubs,
 * hall-provision-stubs). Same property names as those, so tests read alike.
 */

namespace BCC\Core\DB;

if (!class_exists(AdvisoryLock::class, false)) {
    final class AdvisoryLock
    {
        /** Global override: false makes every acquisition fail. */
        public static bool $acquirable = true;

        /** @var array<string, bool> name => held */
        public static array $held = [];

        /** @var list<string> every acquire attempt, in order */
        public static array $acquired = [];

        /** @var list<string> every release call, in order */
        public static array $released = [];

        public static function reset(): void
        {
            self::$acquirable = true;
            self::$held       = [];
            self::$acquired   = [];
            self::$released   = [];
        }

        public static function acquire(string $key, int $timeout = 0): bool
        {
            self::$acquired[] = $key;
            if (!self::$acquirable || isset(self::$held[$key])) {
                return false;
            }
            self::$held[$key] = true;
            return true;
        }

        public static function release(string $key): void
        {
            self::$released[] = $key;
            unset(self::$held[$key]);
        }
    }
}
