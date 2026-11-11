<?php

declare(strict_types=1);

/**
 * Collaborator fakes for SignalRefreshService's refresh cycle (F-11).
 *
 * Load only in a separate process, before any of these classes is
 * autoloaded: every fake sits behind class_exists(…, false).
 * RepositoryReadFailure is the REAL class. The service's WordPress calls are
 * unqualified inside BCC\Trust\Onchain\Services, so they are faked in that
 * namespace and the shared global function table is untouched.
 */

namespace {
    final class RefreshWorld
    {
        /** @var list<int> every owner with a link, the "table" */
        public static array $owners = [];

        /** @var array<string, mixed> */
        public static array $options = [];

        /** @var list<int> owners refreshed, in order */
        public static array $refreshed = [];

        /** @var list<string> */
        public static array $scheduled = [];

        /** Next N owner reads throw. */
        public static int $readFailures = 0;

        public static int $reads = 0;

        /** Fake clock, seconds. */
        public static int $now = 1_000_000;

        /** Seconds each refresh costs. */
        public static int $secondsPerOwner = 0;

        /** @var list<string> */
        public static array $logs = [];

        public static function reset(): void
        {
            self::$owners          = [];
            self::$options         = [];
            self::$refreshed       = [];
            self::$scheduled       = [];
            self::$readFailures    = 0;
            self::$reads           = 0;
            self::$now             = 1_000_000;
            self::$secondsPerOwner = 0;
            self::$logs            = [];
        }
    }
}

namespace BCC\Core\DB {
    if (!class_exists(__NAMESPACE__ . '\\AdvisoryLock', false)) {
        class AdvisoryLock
        {
            public static function acquire(string $key, int $timeout = 0): bool
            {
                return true;
            }

            public static function release(string $key): void
            {
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void
            {
                \RefreshWorld::$logs[] = 'info:' . $m;
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
                \RefreshWorld::$logs[] = 'warning:' . $m;
            }

            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                \RefreshWorld::$logs[] = 'error:' . $m;
            }
        }
    }
}

namespace BCC\Core\Cron {
    if (!class_exists(__NAMESPACE__ . '\\AsyncDispatcher', false)) {
        class AsyncDispatcher
        {
            /** @param array<int, mixed> $args */
            public static function scheduleSingle(int $timestamp, string $hook, array $args = [], string $group = ''): void
            {
                \RefreshWorld::$scheduled[] = $hook;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Support {
    if (!class_exists(__NAMESPACE__ . '\\ChainSupport', false)) {
        final class ChainSupport
        {
            /** @return list<string> */
            public static function supported(): array
            {
                return ['ethereum', 'solana'];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\WalletRepository', false)) {
        final class WalletRepository
        {
            /**
             * The keyset read, over RefreshWorld::$owners.
             *
             * @param string[] $chainSlugs
             * @return list<int>
             */
            public static function getUserIdsWithChainSlugsAfter(array $chainSlugs, int $afterUserId, int $limit): array
            {
                \RefreshWorld::$reads++;
                if (\RefreshWorld::$readFailures > 0) {
                    \RefreshWorld::$readFailures--;
                    throw new RepositoryReadFailure(self::class, 'getUserIdsWithChainSlugsAfter', 'Lost connection');
                }
                $ids = array_values(array_filter(\RefreshWorld::$owners, static fn (int $id): bool => $id > $afterUserId));
                sort($ids);
                return array_slice($ids, 0, $limit);
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!class_exists(__NAMESPACE__ . '\\BonusRetryService', false)) {
        final class BonusRetryService
        {
            public static function processAll(): void
            {
            }
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        function get_option(string $name, mixed $default = false): mixed
        {
            return array_key_exists($name, \RefreshWorld::$options) ? \RefreshWorld::$options[$name] : $default;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\update_option')) {
        function update_option(string $name, mixed $value, mixed $autoload = null): bool
        {
            \RefreshWorld::$options[$name] = $value;
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\delete_option')) {
        function delete_option(string $name): bool
        {
            unset(\RefreshWorld::$options[$name]);
            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_next_scheduled')) {
        function wp_next_scheduled(string $hook): bool
        {
            return false;
        }
    }
}
