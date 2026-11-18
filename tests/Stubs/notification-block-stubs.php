<?php
/**
 * Stubs for NotificationBlockedActorTest (F-296, F-331). Subprocess-only:
 * the fail-closed block read logs through bcc-core's Logger before
 * throwing, and reads the block lists through bcc-core's
 * PeepSoBlockRepository OrUnknown readers (F-331), doubled here from the
 * test's $wpdb knobs: `blocked`, `blockers`, and `failOn` ('blocked' |
 * 'blockers' | null) for the reader that answers NULL (a failed read).
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\PeepSoBlockRepository', false)) {
        final class PeepSoBlockRepository
        {
            /** @return list<int>|null */
            public static function getBlockedIdsOrUnknown(int $blockerId, int $limit = 500): ?array
            {
                return self::answer('blocked', $blockerId, $limit);
            }

            /** @return list<int>|null */
            public static function getBlockerIdsOrUnknown(int $blockedId, int $limit = 500): ?array
            {
                return self::answer('blockers', $blockedId, $limit);
            }

            /** @return list<int>|null */
            private static function answer(string $which, int $userId, int $limit): ?array
            {
                $w = $GLOBALS['wpdb'];
                $w->blockCalls[] = [$which, $userId, $limit];
                return $w->failOn === $which ? null : $w->{$which};
            }
        }
    }
}

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
