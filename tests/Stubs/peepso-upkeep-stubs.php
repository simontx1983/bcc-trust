<?php

declare(strict_types=1);

/**
 * Doubles for PeepSoUpkeepTest. Load only in a separate process.
 *
 * peepso-upkeep-stubs.php (this file): bcc-core's AdvisoryLock and Logger, and
 * a pass-through apply_filters() that honours $GLOBALS['__bcc_up']['filters'].
 *
 * peepso-upkeep-peepso-stubs.php: the four PeepSo entry points, recording
 * their calls. Loaded only by the cases where PeepSo is present.
 */

namespace {
    if (!function_exists('apply_filters')) {
        function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
        {
            return array_key_exists($hook, $GLOBALS['__bcc_up']['filters'] ?? [])
                ? $GLOBALS['__bcc_up']['filters'][$hook]
                : $value;
        }
    }
}

namespace BCC\Core\DB {
    if (!class_exists(__NAMESPACE__ . '\\AdvisoryLock', false)) {
        final class AdvisoryLock
        {
            public static function acquire(string $key, int $timeout = 0): bool
            {
                $GLOBALS['__bcc_up']['lock'][] = 'acquire:' . $key;

                return (bool) ($GLOBALS['__bcc_up']['lock_free'] ?? true);
            }

            public static function release(string $key): void
            {
                $GLOBALS['__bcc_up']['lock'][] = 'release:' . $key;
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void
            {
                $GLOBALS['__bcc_up']['logs'][] = $m . ' ' . json_encode($c);
            }

            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                $GLOBALS['__bcc_up']['logs'][] = $m . ' ' . json_encode($c);
            }

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void
            {
            }
        }
    }
}
