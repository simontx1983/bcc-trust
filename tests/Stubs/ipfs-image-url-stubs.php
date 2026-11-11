<?php

declare(strict_types=1);

/**
 * F-165 IpfsImageUrlTest: a recording Logger. Load only in a separate process.
 */

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @var list<string> */
            public static array $warnings = [];

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void { self::$warnings[] = $m; }
            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void {}
        }
    }
}
