<?php
/**
 * F-454: the reads SuggestionService::buildExclusionSet() makes, for
 * SuggestionExclusionReadFailureTest only. SUBPROCESS-ONLY: it declares
 * bcc-core class names.
 *
 * Reads and writes `$GLOBALS['__bcc_sx']`:
 *   blocked / blockers        list<int>  the viewer's blocks, both ways
 *   blocked_fails / blockers_fails  bool  that read did not run (null)
 *   warnings                  list<string>  Logger::warning messages
 *
 * ⚠ No getBlockedIds() / getBlockerIds(): they answer [] on a failed read,
 * so a suggestion path calling them again is a fatal here.
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\PeepSoBlockRepository', false)) {
        final class PeepSoBlockRepository
        {
            /** @return list<int>|null */
            public static function getBlockedIdsOrUnknown(int $blockerId, int $limit = 500): ?array
            {
                unset($blockerId, $limit);

                return empty($GLOBALS['__bcc_sx']['blocked_fails']) ? ($GLOBALS['__bcc_sx']['blocked'] ?? []) : null;
            }

            /** @return list<int>|null */
            public static function getBlockerIdsOrUnknown(int $blockedId, int $limit = 500): ?array
            {
                unset($blockedId, $limit);

                return empty($GLOBALS['__bcc_sx']['blockers_fails']) ? ($GLOBALS['__bcc_sx']['blockers'] ?? []) : null;
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
                unset($c);
                $GLOBALS['__bcc_sx']['warnings'][] = $m;
            }

            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                unset($m, $c);
            }

            /** @param array<string, mixed> $c */
            public static function info(string $m, array $c = []): void
            {
                unset($m, $c);
            }
        }
    }
}
