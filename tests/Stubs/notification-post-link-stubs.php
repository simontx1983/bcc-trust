<?php
/**
 * Doubles for NotificationPostLinkTest (F-245): an in-memory object cache
 * (wp_cache_*), a line-recording Logger and a wpdb fake whose query()
 * returns false or an int and leaves rows in last_result, like the real one.
 *
 * ⚠ Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope: file-scope definitions load into PHPUnit's discovery parent and
 * hijack sibling suites that guard their own stubs with function_exists().
 */

declare(strict_types=1);

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        /** Records lines; the unit bootstrap does not load bcc-core's Logger. */
        final class Logger
        {
            /** @var list<array{level: string, message: string}> */
            public static array $lines = [];

            /** @param array<int, mixed> $args */
            public static function __callStatic(string $level, array $args): void
            {
                self::$lines[] = ['level' => $level, 'message' => (string) ($args[0] ?? '')];
            }
        }
    }
}

namespace {
    if (!function_exists('wp_cache_get')) {
        /** @return mixed */
        function wp_cache_get(string $key, string $group = '', bool $force = false, ?bool &$found = null)
        {
            $found = isset($GLOBALS['__nv_cache'][$group][$key]);
            return $found ? $GLOBALS['__nv_cache'][$group][$key] : false;
        }
    }
    if (!function_exists('wp_cache_set')) {
        /** @param mixed $value */
        function wp_cache_set(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            $GLOBALS['__nv_cache'][$group][$key] = $value;
            return true;
        }
    }
    if (!function_exists('wp_cache_add')) {
        /** @param mixed $value */
        function wp_cache_add(string $key, $value, string $group = '', int $ttl = 0): bool
        {
            if (isset($GLOBALS['__nv_cache'][$group][$key])) {
                return false;
            }
            $GLOBALS['__nv_cache'][$group][$key] = $value;
            return true;
        }
    }

    if (!class_exists('NotificationPostLinkFakeWpdb', false)) {
        /** Models real wpdb: query() returns false or an int, rows land in last_result. */
        final class NotificationPostLinkFakeWpdb
        {
            public string $prefix = 'wp_';
            public string $posts  = 'wp_posts';
            public string $last_error = '';
            /** @var list<object>|null */
            public ?array $last_result = null;

            public function __construct()
            {
                // Stale rows from an earlier query: a failure path must not read them.
                $this->last_result = [(object) ['act_id' => '999']];
            }

            /** @param mixed ...$args */
            public function prepare(string $query, ...$args): string
            {
                $i = 0;
                return (string) preg_replace_callback('/%d/', static function () use (&$i, $args): string {
                    return (string) (int) ($args[$i++] ?? 0);
                }, preg_replace('/\s+/', ' ', $query));
            }

            /** @return int|false */
            public function query(string $sql)
            {
                $GLOBALS['__nv_sql'][] = $sql;
                $outcome = array_shift($GLOBALS['__nv_script']);
                if ($outcome === false || $outcome === null) {
                    return false; // last_result left STALE, like wpdb's !ready path
                }
                if (is_array($outcome) && isset($outcome['error'])) {
                    $this->last_error = (string) $outcome['error'];
                    return false;
                }
                $this->last_result = $outcome;
                return count($outcome);
            }
        }
    }
}
