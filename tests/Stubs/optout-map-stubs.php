<?php
/**
 * One user-meta store for OptOutMapCoherenceTest, OptOutMapReadFailClosedTest
 * and UserMetaSwapRepositoryTest: the REAL NftGroupGateService reads and
 * writes its opt-out map here.
 *
 * H7: the store is the token-state $wpdb double's usermeta rows, behind:
 *   - the REAL bcc-core UserMetaRepository (the map's trusted, fresh read);
 *   - the REAL UserMetaSwapRepository (its guarded INSERT / UPDATE / DELETE,
 *     which the double executes; `$db->beforeWrite` is another request
 *     writing in between);
 *   - get_user_meta() below, for the DISPLAY reads (isOptOutActive,
 *     isPermanentOptOut): the first row, as WordPress answers it, and ''
 *     whenever the double is failing ('error' or 'false_early'), which is
 *     what WordPress gives for a failed meta read.
 * wp_cache_delete() records its calls in $GLOBALS['__bcc_optout_cache_deletes'].
 *
 * Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope: PHPUnit loads test files in the parent process too.
 */

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/token-state-wpdb-stubs.php';

    if (!defined('DAY_IN_SECONDS')) {
        define('DAY_IN_SECONDS', 86400);
    }

    if (!function_exists('wp_cache_delete')) {
        /** @param int|string $key */
        function wp_cache_delete($key, string $group = ''): bool
        {
            $GLOBALS['__bcc_optout_cache_deletes'][] = [$key, $group];
            return true;
        }
    }

    if (!class_exists('OptOutStore', false)) {
        final class OptOutStore
        {
            public static function db(): \TokenStateWpdb
            {
                /** @var \TokenStateWpdb */
                return $GLOBALS['wpdb'];
            }

            public static function failing(): bool
            {
                return in_array(self::db()->mode, ['error', 'false_early'], true);
            }

            /** @return list<int> row indexes for (user, key), oldest first */
            public static function rowsFor(int $userId, string $key): array
            {
                $out = [];
                foreach (self::db()->rows as $i => $row) {
                    if ($row['user_id'] === $userId && $row['meta_key'] === $key) {
                        $out[] = $i;
                    }
                }
                return $out;
            }

            /** @return array<int, int>|null the stored map, decoded; null = no row */
            public static function map(int $userId): ?array
            {
                $rows = self::rowsFor($userId, 'bcc_gated_groups_optout');
                if ($rows === []) {
                    return null;
                }
                $decoded = json_decode((string) self::db()->rows[$rows[0]]['meta_value'], true);
                return is_array($decoded) ? $decoded : [];
            }

            /** Overwrite the first stored value directly: ANOTHER request's write. */
            public static function overwrite(int $userId, string $value): void
            {
                $rows = self::rowsFor($userId, 'bcc_gated_groups_optout');
                self::db()->rows[$rows[0]]['meta_value'] = $value;
            }
        }
    }
}

namespace BCC\Trust\Onchain\Services {
    // Namespace-scoped: NftGroupGateService's unqualified call resolves here
    // first. Loaded only in these classes' separate processes.
    if (!function_exists(__NAMESPACE__ . '\get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            $GLOBALS['__bcc_optout_reads'] = ($GLOBALS['__bcc_optout_reads'] ?? 0) + 1;
            if (\OptOutStore::failing()) {
                return $single ? '' : [];
            }
            $rows = \OptOutStore::rowsFor($userId, $key);
            if ($rows === []) {
                return $single ? '' : [];
            }
            $value = (string) \OptOutStore::db()->rows[$rows[0]]['meta_value'];

            return $single ? $value : [$value];
        }
    }
    if (!function_exists(__NAMESPACE__ . '\apply_filters')) {
        /**
         * @param mixed $value
         * @param mixed ...$args
         * @return mixed
         */
        function apply_filters(string $hook, $value, ...$args)
        {
            return $value;
        }
    }
}

namespace BCC\Core\Log {
    // The give-up path logs at ERROR; tests read what it logged.
    if (!class_exists(__NAMESPACE__ . '\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_optout_log'][] = ['message' => $message, 'context' => $context];
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function info(string $message, array $context = []): void
            {
            }
        }
    }
}
