<?php
/**
 * F-93 doubles for NftGroupReconcileSweepTest: the option API and bcc-core's
 * Logger, recording into ONE ordered event log ($GLOBALS['__rs']['events'])
 * so a test can prove the cursor is written AFTER each user's work, not
 * before the batch. The lock lives in reconcile-sweep-lock-stub.php, loaded
 * only by the tests that want a lock primitive to exist.
 *
 * Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('get_option')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_option(string $name, $default = false)
        {
            return $GLOBALS['__rs']['options'][$name] ?? $default;
        }
    }
    if (!function_exists('update_option')) {
        /** @param mixed $value */
        function update_option(string $name, $value, $autoload = null): bool
        {
            $GLOBALS['__rs']['options'][$name] = $value;
            $GLOBALS['__rs']['events'][]       = 'cursor:' . (string) $value;
            return true;
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                $GLOBALS['__rs']['errors'][] = [$m, $c];
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
                $GLOBALS['__rs']['warnings'][] = [$m, $c];
            }

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
        }
    }
}
