<?php
/**
 * UNIT-SUITE DOUBLE of bcc-core's `BCC\Core\Repositories\PostMetaRepository`
 * (F-463). AUTOLOADED by tests/bootstrap.php; the integration suite has
 * its own bootstrap and autoloads the REAL class from the bcc-core sibling.
 *
 * Why a double: bcc-trust's unit suite does not autoload bcc-core, and
 * GroupContextResolver (a group's kind and trust weighting) and the plain
 * join door (its trust minimum) now read those values through core.
 *
 * What it answers, in order:
 *   1. `$failAll` → null (a read that could not be trusted);
 *   2. an explicit `$answers[$postId]` (key => list of raw values; null =
 *      that read failed). Tests that prove bcc-trust DELEGATES to core set
 *      one that contradicts the meta stub;
 *   3. otherwise every value of each key from the `get_post_meta` the
 *      CALLER used to call: the caller's namespaced shim when the test
 *      defines one (tests stub `BCC\Trust\Core\Services\get_post_meta` for
 *      the resolver, `BCC\Trust\Core\REST\get_post_meta` for the join door),
 *      else the global function. So every existing test that sets a kind or
 *      a threshold through its meta stub keeps meaning what it meant.
 *
 * The REAL read (the table, ProbesReads, the pre-flush bail, the bounds) is
 * tested where it lives, in bcc-core's PostMetaRepositoryReadTest (executed
 * on SQLite and MySQL), and end to end by this plugin's integration suite.
 *
 * ⚠ AUTOLOADED, NOT DECLARED. A unit suite that wants core's REAL class
 * requires it (CoreSibling::require('src/Repositories/PostMetaRepository.php'),
 * after ProbesReads and ReadsMetaRows) before first use, and the autoloader
 * never fires.
 */

declare(strict_types=1);

namespace BCC\Core\Repositories {
    if (!class_exists(__NAMESPACE__ . '\\PostMetaRepository', false)) {
        final class PostMetaRepository
        {
            public const CHUNK_POSTS = 100;
            public const BATCH_MAX   = 1000;
            public const MAX_KEYS    = 10;

            /** @var array<int, array<string, list<string>>|null> explicit answers; null = that read failed */
            public static array $answers = [];

            public static bool $failAll = false;

            /** @var list<array{ids: list<int>, keys: list<string>}> every read, in order */
            public static array $calls = [];

            public static function reset(): void
            {
                self::$answers = [];
                self::$failAll = false;
                self::$calls   = [];
            }

            /**
             * @param list<int>    $postIds
             * @param list<string> $keys
             * @return array<int, array<string, list<string>>>|null
             */
            public static function valuesOrUnknown(array $postIds, array $keys): ?array
            {
                $ids = [];
                foreach ($postIds as $id) {
                    $id = (int) $id;
                    if ($id > 0) {
                        $ids[$id] = $id;
                    }
                }
                $ids  = array_values($ids);
                $keys = array_values(array_unique(array_filter($keys, static fn ($k): bool => $k !== '')));
                if ($ids === [] || $keys === []) {
                    return [];
                }
                if (count($keys) > self::MAX_KEYS || count($ids) > self::BATCH_MAX) {
                    return null;
                }
                self::$calls[] = ['ids' => $ids, 'keys' => $keys];
                if (self::$failAll) {
                    return null;
                }

                $fn  = self::callerGetPostMeta();
                $out = [];
                foreach ($ids as $id) {
                    if (array_key_exists($id, self::$answers)) {
                        if (self::$answers[$id] === null) {
                            return null;
                        }
                        foreach ($keys as $key) {
                            $values = self::$answers[$id][$key] ?? [];
                            if ($values !== []) {
                                $out[$id][$key] = array_values(array_map('strval', $values));
                            }
                        }
                        continue;
                    }
                    if ($fn === null) {
                        continue;
                    }
                    foreach ($keys as $key) {
                        $values = self::normalise($fn($id, $key, false));
                        if ($values !== []) {
                            $out[$id][$key] = $values;
                        }
                    }
                }

                return $out;
            }

            /** The get_post_meta an unqualified call in the caller resolves to. */
            private static function callerGetPostMeta(): ?string
            {
                $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
                $class  = (string) ($frames[2]['class'] ?? '');
                $cut    = strrpos($class, '\\');
                if ($cut !== false) {
                    $shim = substr($class, 0, $cut) . '\\get_post_meta';
                    if (function_exists($shim)) {
                        return $shim;
                    }
                }

                return function_exists('get_post_meta') ? 'get_post_meta' : null;
            }

            /**
             * A meta stub's answer as a list of raw strings: an array as is,
             * a lone non-empty scalar as one row, '' / null / false as none.
             *
             * @return list<string>
             */
            private static function normalise(mixed $value): array
            {
                if (is_array($value)) {
                    $out = [];
                    foreach ($value as $row) {
                        if (is_scalar($row)) {
                            $out[] = (string) $row;
                        }
                    }

                    return $out;
                }
                if ($value === '' || $value === null || $value === false) {
                    return [];
                }

                return is_scalar($value) ? [(string) $value] : [];
            }
        }
    }
}
