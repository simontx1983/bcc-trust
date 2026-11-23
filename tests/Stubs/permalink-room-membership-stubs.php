<?php
/**
 * F-451: the room-membership read the permalink's third look asks, and the
 * logger its failure writes to, for FeedPostVisibilityWiringTest and (F-438)
 * FeedExclusionReadFailureTest only. Kept
 * out of feed-f02-wiring-stubs.php because five other suites load that file,
 * and this declares the REAL `\BCC\Core\Repositories\PeepSoGroupRepository`
 * and `\BCC\Core\Log\Logger` (first declaration wins for the process).
 * SUBPROCESS-ONLY, like every stub that does that.
 *
 * Reads and writes `$GLOBALS['__bcc_f02feed']`:
 *   membership        array<int viewer, array<int group, string|null|false>>
 *                     a status, null for no row, false for a read that failed
 *   membership_calls  list<array{user: int, group: int}>  every call, in order
 *   my_groups         list<int>|null  F-438: the viewer's rooms for "My Groups"
 *                     (null: the read failed); default none
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

        /** @return list<int>|null */
        public static function getActiveMemberGroupIdsOrFail(int $userId, int $limit = 200): ?array
        {
            unset($userId, $limit);
            $groups = array_key_exists('my_groups', $GLOBALS['__bcc_f02feed'] ?? [])
                ? $GLOBALS['__bcc_f02feed']['my_groups']
                : [];

            return is_array($groups) ? array_values(array_map('intval', $groups)) : null;
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
