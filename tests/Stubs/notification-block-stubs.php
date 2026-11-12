<?php
/**
 * Stubs for NotificationBlockedActorTest (F-296). Subprocess-only.
 *
 * $GLOBALS['__bcc_nb'] = ['blocked' => list<int>, 'blockers' => list<int>]
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\PeepSoBlockRepository', false)) {
        final class PeepSoBlockRepository
        {
            /** @return list<int> */
            public static function getBlockedIds(int $blockerId, int $limit = 500): array
            {
                return $GLOBALS['__bcc_nb']['blocked'] ?? [];
            }

            /** @return list<int> */
            public static function getBlockerIds(int $blockedId, int $limit = 500): array
            {
                return $GLOBALS['__bcc_nb']['blockers'] ?? [];
            }
        }
    }
}

namespace {
    if (!defined('BCC_NOTIFICATION_MODULE_ID')) {
        define('BCC_NOTIFICATION_MODULE_ID', 9001);
    }
}
