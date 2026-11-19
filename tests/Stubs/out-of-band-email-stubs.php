<?php
/**
 * Stubs for OutOfBandEmailChangeTest (H1) — loaded FIRST, before
 * pending-email-stubs.php and account-prehijack-stubs.php.
 *
 * Subprocess-only. A Logger whose audit() RECORDS (account-prehijack-stubs'
 * is silent), so the revocation's audit entry is observable:
 *   log_audit   list<array{0: string, 1: array<string, mixed>}>  [message, context]
 *   log_errors  list<string>  (same as account-prehijack-stubs)
 */

declare(strict_types=1);

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function audit(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_ph']['log_audit'][] = [$message, $context];
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_ph']['log_errors'][] = $message;
            }
        }
    }
}
