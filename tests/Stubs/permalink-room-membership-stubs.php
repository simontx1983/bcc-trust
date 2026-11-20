<?php
/**
 * F-451: the room-membership read the permalink's third look asks, and the
 * logger its failure writes to, for FeedPostVisibilityWiringTest only. Kept
 * out of feed-f02-wiring-stubs.php because five other suites load that file,
 * and this declares the REAL `\BCC\Core\Repositories\PeepSoGroupRepository`
 * and `\BCC\Core\Log\Logger` (first declaration wins for the process).
 * SUBPROCESS-ONLY, like every stub that does that.
 *
 * Reads and writes `$GLOBALS['__bcc_f02feed']`:
 *   membership        array<int viewer, array<int group, string|null|false>>
 *                     a status, null for no row, false for a read that failed
 *   membership_calls  list<array{user: int, group: int}>  every call, in order
 *   logs              list<string>  Logger messages
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    final class PeepSoGroupRepository
    {
        public static function getMembershipStatusOrFail(int $userId, int $groupId): string|null|false
        {
            $GLOBALS['__bcc_f02feed']['membership_calls'][] = ['user' => $userId, 'group' => $groupId];

            $status = $GLOBALS['__bcc_f02feed']['membership'][$userId][$groupId] ?? null;

            return is_string($status) || $status === false ? $status : null;
        }
    }
}

namespace BCC\Core\Log {
    final class Logger
    {
        /** @param array<string, mixed> $context */
        public static function warning(string $message, array $context = []): void
        {
            unset($context);
            $GLOBALS['__bcc_f02feed']['logs'][] = $message;
        }
    }
}
