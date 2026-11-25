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
 * ⚠ Once any test in a SHARED process (one not run in a separate process)
 * autoloads this double, the real class can no longer load in that process,
 * so a suite that needs the real class must run in a separate process.
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
         * Only the calls that actually TOUCHED the fixture - i.e. what
         * would have been a query. A call served entirely from the memo
         * does not appear here, which is how a test tells a primed list
         * from one read member-by-member.
         *
         * @var list<array{users: list<int>, keys: list<string>}>
         */
        public static array $reads = [];

        /**
         * ⚠⚠ OPT-IN, and OFF by default. This file is autoloaded into
         * every unit process, so a memo that were always on would leak
         * between tests in a SHARED-process suite and make results
         * ORDER-DEPENDENT — a test could pass only because an earlier one
         * happened to memoize the same member.
         *
         * Off, the double reads on every call, which is what every
         * existing suite was written against.
         *
         * A suite that turns it on is asserting something ABOUT the memo
         * (that a primed list costs one read), and must run in separate
         * processes or reset {@see $memo} and {@see $reads} itself.
         */
        public static bool $memoize = false;

        /**
         * user => key => values, as the REAL class memoizes — only while
         * {@see $memoize} is on. Without a memo at all, the double
         * answered a primed list and an unprimed one identically, so no
         * test could see the difference.
         *
         * @var array<int, array<string, list<string>>>
         */
        public static array $memo = [];

        /**
         * Several stored rows for one (member, key), in umeta_id order, which
         * a get_user_meta() shim cannot express: `$rows[$id][$key] = ['0', '1']`.
         * Answers for that pair instead of the shim.
         *
         * @var array<int, array<string, list<string>>>
         */
        public static array $rows = [];

        /**
         * @param list<int>    $userIds
         * @param list<string> $keys
         * @return array<int, array<string, list<string>>>|null
         */
        public static function valuesOrUnknown(array $userIds, array $keys, bool $fresh = false): ?array
        {
            self::$calls[] = ['users' => array_values($userIds), 'keys' => array_values($keys), 'fresh' => $fresh];

            // Which (user, key) pairs are not held? With the memo off that
            // is all of them, which is the pre-existing behaviour: read on
            // every call.
            $toRead = [];
            foreach ($userIds as $id) {
                $id = (int) $id;
                if ($id <= 0) {
                    continue;
                }
                if (!self::$memoize || $fresh) {
                    $toRead[$id] = true;
                    continue;
                }
                foreach ($keys as $key) {
                    if (!isset(self::$memo[$id][$key])) {
                        $toRead[$id] = true;
                        break;
                    }
                }
            }

            $readValues = [];

            if ($toRead !== []) {
                self::$reads[] = ['users' => array_keys($toRead), 'keys' => array_values($keys)];

                // ⚠ THE WHOLE BATCH OR NOTHING, as in core: one untrusted
                // member makes the read untrusted, and nothing from it is
                // kept. Memoizing id-by-id and bailing part way would leave
                // the earlier ids held from a read that answered null.
                foreach (array_keys($toRead) as $id) {
                    if (isset(self::$unreadable[$id])) {
                        return null;
                    }
                    foreach ($keys as $key) {
                        if (isset(self::$rows[$id][$key])) {
                            $readValues[$id][$key] = self::$rows[$id][$key];
                            continue;
                        }
                        $raw = self::getUserMeta($id, $key);
                        $readValues[$id][$key] = ($raw !== '' && $raw !== null && $raw !== false)
                            ? [is_scalar($raw) ? (string) $raw : '']
                            : [];
                    }
                }

                if (self::$memoize && !$fresh) {
                    // A fresh read deliberately does NOT seed the memo, which
                    // is core's rule: `$fresh` means "do not serve me
                    // anything remembered", not "remember this".
                    foreach ($readValues as $id => $byKey) {
                        self::$memo[$id] = array_replace(self::$memo[$id] ?? [], $byKey);
                    }
                }
            }

            $held = self::$memoize && !$fresh ? self::$memo : $readValues;

            $out = [];
            foreach ($userIds as $id) {
                $id = (int) $id;
                if ($id <= 0) {
                    continue;
                }
                foreach ($keys as $key) {
                    $values = $held[$id][$key] ?? [];
                    if ($values !== []) {
                        $out[$id][$key] = $values;
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
            unset(self::$memo[$userId]);
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
