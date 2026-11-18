<?php
/**
 * Stubs for NotificationBlockedActorTest (F-296). Subprocess-only: the
 * fail-closed block read logs through bcc-core's Logger before throwing.
 */

declare(strict_types=1);

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                $GLOBALS['__bcc_nb_errors'][] = $m;
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void {}

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void {}
        }
    }
}
