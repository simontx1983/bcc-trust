<?php

declare(strict_types=1);

/**
 * F-240 BonusRetryProcessAllTest: an in-memory world for
 * BonusRetryService::processAll(). Load only in a separate process, BEFORE
 * BonusRetryService is autoloaded, so these declarations win.
 *
 * - options live in BonusRetryWorld::$options (namespaced get/update_option);
 * - every advisory lock is granted;
 * - no page has an owner, so the signal and claim reads return nothing
 *   without a database and the bonus to write is 0.0;
 * - the score contributor records each page it is asked to write, and THROWS
 *   for the pages in BonusRetryWorld::$throwFor.
 */

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', sys_get_temp_dir() . '/');
    }
    if (!defined('BCC_ONCHAIN_MAX_TOTAL_BONUS')) {
        define('BCC_ONCHAIN_MAX_TOTAL_BONUS', 20);
    }

    final class BonusRetryWorld
    {
        /** @var array<string, mixed> */
        public static array $options = [];
        /** @var list<int> */
        public static array $applied = [];
        /** @var array<int, true> */
        public static array $throwFor = [];
        /** @var list<array{string, array<string, mixed>}> */
        public static array $errors = [];

        public static function reset(): void
        {
            self::$options  = [];
            self::$applied  = [];
            self::$throwFor = [];
            self::$errors   = [];
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        /** @param mixed $default @return mixed */
        function get_option(string $name, $default = false)
        {
            return \BonusRetryWorld::$options[$name] ?? $default;
        }
    }
    if (!function_exists(__NAMESPACE__ . '\\update_option')) {
        /** @param mixed $value */
        function update_option(string $name, $value, $autoload = null): bool
        {
            \BonusRetryWorld::$options[$name] = $value;
            return true;
        }
    }
}

namespace BCC\Core\DB {
    if (!class_exists(__NAMESPACE__ . '\\AdvisoryLock', false)) {
        final class AdvisoryLock
        {
            public static function acquire(string $key, int $timeout = 0): bool { return true; }
            public static function release(string $key): bool { return true; }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists(__NAMESPACE__ . '\\PeepSo', false)) {
        final class PeepSo
        {
            public static function get_page_owner(int $pageId): ?int { return null; }
        }
    }
}

namespace BCC\Core {
    if (!class_exists(__NAMESPACE__ . '\\ServiceLocator', false)) {
        final class ServiceLocator
        {
            public static function hasRealService(string $contract): bool { return true; }

            public static function resolveScoreContributor(): object
            {
                return new class {
                    public function applyBonus(int $pageId, string $source, float $value): bool
                    {
                        \BonusRetryWorld::$applied[] = $pageId;
                        if (isset(\BonusRetryWorld::$throwFor[$pageId])) {
                            throw new \RuntimeException('Transaction callback returned false');
                        }
                        return true;
                    }
                };
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void { \BonusRetryWorld::$errors[] = [$m, $c]; }
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function debug(string $m, array $c = []): void {}
        }
    }
}
