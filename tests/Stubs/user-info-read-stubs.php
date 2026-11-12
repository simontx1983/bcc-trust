<?php
/**
 * Doubles for UserInfoReadFailureTest: the REAL UserInfoRepository::getByUserId()
 * against a scripted $wpdb and an in-memory object cache.
 *
 * $wpdb->script is a queue of query() outcomes, consumed one per query():
 *   ['rows' => [object…]]                  success; the rows land in $last_result
 *   ['error' => 'msg']                     failure WITH last_error (query() returns false)
 *   ['silent' => true]                     failure WITHOUT last_error (query() returns false,
 *                                          as wpdb does after a failed reconnect); last_result
 *                                          keeps the PREVIOUS query's rows, like the real one.
 *
 * Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope.
 */

declare(strict_types=1);

namespace {
    if (!function_exists('wp_cache_get')) {
        /** @return mixed */
        function wp_cache_get(string $key, string $group = '', bool $force = false, ?bool &$found = null)
        {
            $found = array_key_exists($group . ':' . $key, $GLOBALS['__bcc_cache'] ?? []);
            return $found ? $GLOBALS['__bcc_cache'][$group . ':' . $key] : false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        /** @param mixed $data */
        function wp_cache_set(string $key, $data, string $group = '', int $expire = 0): bool
        {
            $GLOBALS['__bcc_cache'][$group . ':' . $key] = $data;
            return true;
        }
    }

    $GLOBALS['wpdb'] = new class {
        public string $prefix = 'wp_';
        public string $last_error = '';
        /** @var list<array<string, mixed>> */
        public array $script = [];
        public int $queries = 0;
        /** @var list<object> rows of the last successful SELECT, public as in real wpdb */
        public array $last_result = [];

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            return vsprintf(str_replace('%d', '%d', $sql), $args);
        }

        /** @return int|false */
        public function query(string $sql)
        {
            $this->queries++;
            $step = array_shift($this->script) ?? ['rows' => []];
            if (isset($step['error'])) {
                $this->last_error = (string) $step['error'];
                return false;
            }
            if (!empty($step['silent'])) {
                return false; // last_result deliberately NOT flushed
            }
            $this->last_result = $step['rows'];
            return count($this->last_result);
        }

        /**
         * Faithful to real wpdb: get_row(null) runs nothing and returns null.
         * A double that served last_result here once hid a production bug
         * (every user_info read coming back null).
         */
        public function get_row(?string $sql = null): ?object
        {
            if ($sql === null) {
                return null;
            }
            $this->query($sql);
            return $this->last_result[0] ?? null;
        }
    };
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                $GLOBALS['__bcc_logs'][] = $message;
            }
        }
    }
}
