<?php
/**
 * A `$wpdb` double that answers TableRegistry's `SHOW TABLES LIKE`
 * probe per table, plus a spy for the logger GuardsReadFailures writes to.
 * For HiddenReviewTableProbeTest only.
 *
 * SUBPROCESS-ONLY: it declares the REAL `\BCC\Core\Log\Logger` (first
 * declaration wins for the process) and the wp_cache shims come from
 * schema-capability-stubs.php.
 *
 * Each table answers in one of four modes:
 *   present  the probe lists it
 *   missing  the probe ran and lists nothing
 *   error    a driver error: flush(), then last_error set, query() false
 *   bail     wpdb's pre-flush() bail (a failed reconnect, or the `query`
 *            filter emptying the SQL): query() returns false BEFORE flush(),
 *            so last_error stays empty and last_result still holds the
 *            PREVIOUS query's rows
 *
 * get_var() is faithful to wpdb's: it calls query() and DISCARDS its
 * return value, then reads last_result. That is what made the old probe
 * read a stale row, so a mutant that goes back to get_var() behaves here
 * exactly as it would in production.
 */

declare(strict_types=1);

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @var list<string> */
            public static array $errors = [];

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                self::$errors[] = $message . ' ' . (string) ($context['method'] ?? '');
            }
        }
    }
}

namespace {
    final class BccTableProbeWpdb
    {
        public string $prefix     = 'wp_';
        public string $postmeta   = 'wp_postmeta';
        public string $last_error = '';
        /** @var mixed */
        public $last_result = [];

        /** @var array<string, string> table => present|missing|error|bail */
        public array $tables = [];

        /** @var list<string> every table probed, in order */
        public array $probes = [];

        /** @param mixed ...$args */
        public function prepare(string $sql, ...$args): string
        {
            return str_replace('%s', "'" . (string) ($args[0] ?? '') . "'", $sql);
        }

        /** @return int|false */
        public function query(string $sql)
        {
            if (preg_match("/^SHOW TABLES LIKE '([^']+)'$/", $sql, $m) !== 1) {
                $this->last_error  = '';
                $this->last_result = [];
                return 0;
            }
            $table          = $m[1];
            $this->probes[] = $table;
            $mode           = $this->tables[$table] ?? 'missing';

            if ($mode === 'bail') {
                return false;
            }

            // flush()
            $this->last_error  = '';
            $this->last_result = [];

            if ($mode === 'error') {
                $this->last_error = 'MySQL server has gone away';
                return false;
            }
            if ($mode === 'present') {
                $this->last_result = [(object) ['Tables_in_wp (' . $table . ')' => $table]];
            }

            return count($this->last_result);
        }

        public function get_var(?string $sql = null): ?string
        {
            if ($sql !== null) {
                $this->query($sql);
            }
            $row = is_array($this->last_result) ? ($this->last_result[0] ?? null) : null;
            if (!is_object($row)) {
                return null;
            }
            $values = array_values(get_object_vars($row));

            return isset($values[0]) && $values[0] !== '' ? (string) $values[0] : null;
        }
    }
}
