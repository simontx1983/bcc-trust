<?php
/**
 * UNIT-SUITE DOUBLE of bcc-core's `BCC\Core\Repositories\PeepSoGroupPrivacy`
 * (F-460). Loaded once from tests/bootstrap.php; the integration suite has
 * its own bootstrap and autoloads the REAL class from the bcc-core sibling.
 *
 * Why a double: bcc-trust's unit suite does not autoload bcc-core, and
 * `PeepSoPrivacy` (so GroupContextResolver, and every group gate) now asks
 * core for a group's privacy. Without this, every unit test that reaches a
 * group would die on a missing class.
 *
 * What it answers, in order:
 *   1. `$failAll` → null for everything (a read that could not be trusted);
 *   2. an explicit `$answers[$groupId]` (null = that read failed). Tests
 *      that need to prove bcc-trust DELEGATES to core set one that
 *      contradicts the meta stub;
 *   3. otherwise the STRICTEST of the group's `peepso_group_privacy` meta,
 *      read through the same `get_post_meta` the old trust code called.
 *      That is the ValueObjects-namespaced shim if the test defines one,
 *      else the global function. So every existing test that sets privacy
 *      through its meta stub keeps meaning what it meant.
 *
 * ⚠ The REAL read (table, ProbesReads, strictest via CAST AS UNSIGNED, the
 * pre-flush bail) is tested where it lives, in bcc-core's
 * PeepSoGroupPrivacyReadTest (executed on SQLite and MySQL), and end to end
 * by this plugin's integration suite.
 *
 * ⛔ Never CoreSibling::require the real PeepSoGroupPrivacy.php from a UNIT
 * test: this double is already declared, and PHP would fatal on the second
 * declaration. Use the integration suite for the real class.
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\PeepSoGroupPrivacy', false)) {
        final class PeepSoGroupPrivacy
        {
            public const META_KEY  = 'peepso_group_privacy';
            public const OPEN      = 0;
            public const CLOSED    = 1;
            public const SECRET    = 2;
            public const BATCH_MAX = 500;

            /** @var array<int, int|null> explicit answers; null = that read failed */
            public static array $answers = [];

            public static bool $failAll = false;

            /** @var list<list<int>> the id list of every batch read, in order */
            public static array $batchCalls = [];

            public static function reset(): void
            {
                self::$answers    = [];
                self::$failAll    = false;
                self::$batchCalls = [];
            }

            public static function privacyForGroup(int $groupId): ?int
            {
                if ($groupId <= 0) {
                    return null;
                }
                $byGroup = self::privacyForGroups([$groupId]);

                return $byGroup === null ? null : ($byGroup[$groupId] ?? null);
            }

            /**
             * @param list<int> $groupIds
             * @return array<int, int>|null
             */
            public static function privacyForGroups(array $groupIds): ?array
            {
                $ids = [];
                foreach ($groupIds as $id) {
                    $id = (int) $id;
                    if ($id > 0) {
                        $ids[$id] = true;
                    }
                }
                $ids = array_keys($ids);
                if ($ids === []) {
                    return [];
                }
                if (count($ids) > self::BATCH_MAX) {
                    return null;
                }
                self::$batchCalls[] = $ids;
                if (self::$failAll) {
                    return null;
                }

                $out = [];
                foreach ($ids as $id) {
                    if (array_key_exists($id, self::$answers)) {
                        if (self::$answers[$id] === null) {
                            return null;
                        }
                        $out[$id] = self::$answers[$id];
                        continue;
                    }
                    $out[$id] = self::strictestFromMeta($id);
                }

                return $out;
            }

            private static function strictestFromMeta(int $groupId): int
            {
                $fn = function_exists('BCC\\Trust\\Core\\ValueObjects\\get_post_meta')
                    ? 'BCC\\Trust\\Core\\ValueObjects\\get_post_meta'
                    : (function_exists('get_post_meta') ? 'get_post_meta' : null);
                if ($fn === null) {
                    return self::OPEN;
                }
                $rows = $fn($groupId, self::META_KEY, false);
                if (!is_array($rows)) {
                    $rows = $rows === '' || $rows === null || $rows === false ? [] : [$rows];
                }
                $strictest = self::OPEN;
                foreach ($rows as $row) {
                    if (!is_scalar($row)) {
                        continue;
                    }
                    $value = (int) $row;
                    if ($value === self::SECRET) {
                        return self::SECRET;
                    }
                    if ($value === self::CLOSED) {
                        $strictest = self::CLOSED;
                    }
                }

                return $strictest;
            }
        }
    }
}
