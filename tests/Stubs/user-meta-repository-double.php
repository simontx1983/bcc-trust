<?php
/**
 * Unit double for bcc-core's UserMetaRepository, provided to every unit
 * process by an autoloader in tests/bootstrap.php.
 *
 * WHY. PrivacySettings reads the §K2 flags through core's guarded
 * user-meta read, which queries `$wpdb` directly. Dozens of unit suites
 * drive those flags (often indirectly, through PublicIdentity::visibleName())
 * with their own get_user_meta() shims, and none of their `$wpdb` doubles
 * answers that query. This double reads through the SAME function
 * PrivacySettings used before:
 *   1. `BCC\Trust\Core\Support\get_user_meta()`, the namespaced shim many
 *      suites declare;
 *   2. otherwise the global get_user_meta().
 * So every existing suite keeps its old answers, one value per key, as
 * get_user_meta(…, true) gave.
 *
 * ⚠ AUTOLOADED, NOT DECLARED. A suite that wants core's REAL class requires
 * it (CoreSibling::require('src/Repositories/UserMetaRepository.php'))
 * before first use, and the autoloader never fires.
 *
 * The real class is pinned in bcc-core (UserMetaRepositoryReadTest) and,
 * through PrivacySettings, against real MySQL by
 * tests/Integration/PrivacyFlagsReadIntegrationTest.php.
 *
 * Tests make a member's read fail with `$unreadable[$id] = true`.
 */

declare(strict_types=1);

namespace BCC\Core\Repositories;

if (!class_exists(UserMetaRepository::class, false)) {
    final class UserMetaRepository
    {
        public const CHUNK_USERS = 100;
        public const BATCH_MAX   = 1000;
        public const MAX_KEYS    = 10;

        /** @var array<int, true> members whose read fails */
        public static array $unreadable = [];

        /** @var list<array{users: list<int>, keys: list<string>, fresh: bool}> every call, in order */
        public static array $calls = [];

        /**
         * @param list<int>    $userIds
         * @param list<string> $keys
         * @return array<int, array<string, list<string>>>|null
         */
        public static function valuesOrUnknown(array $userIds, array $keys, bool $fresh = false): ?array
        {
            self::$calls[] = ['users' => array_values($userIds), 'keys' => array_values($keys), 'fresh' => $fresh];

            $out = [];
            foreach ($userIds as $id) {
                if ($id <= 0) {
                    continue;
                }
                if (isset(self::$unreadable[$id])) {
                    return null;
                }
                foreach ($keys as $key) {
                    $raw = self::getUserMeta($id, $key);
                    if ($raw !== '' && $raw !== null && $raw !== false) {
                        $out[$id][$key] = [is_scalar($raw) ? (string) $raw : ''];
                    }
                }
            }

            return $out;
        }

        /**
         * @param list<int>    $userIds
         * @param list<string> $keys
         * @return array<int, array<string, string>>|null
         */
        public static function firstValuesOrUnknown(array $userIds, array $keys, bool $fresh = false): ?array
        {
            $all = self::valuesOrUnknown($userIds, $keys, $fresh);
            if ($all === null) {
                return null;
            }
            $out = [];
            foreach ($all as $id => $byKey) {
                foreach ($byKey as $key => $values) {
                    $out[$id][$key] = $values[0];
                }
            }

            return $out;
        }

        public static function forget(int $userId): void
        {
            unset($userId);
        }

        /** @return mixed */
        private static function getUserMeta(int $id, string $key)
        {
            if (function_exists('BCC\\Trust\\Core\\Support\\get_user_meta')) {
                return \BCC\Trust\Core\Support\get_user_meta($id, $key, true);
            }
            if (function_exists('get_user_meta')) {
                return \get_user_meta($id, $key, true);
            }

            return '';
        }
    }
}
