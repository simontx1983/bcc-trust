<?php
/**
 * F-454: ReputationRepository's caution/risky readers, unit stubs. Loaded
 * ONLY from a #[RunTestsInSeparateProcesses] suite (it declares real
 * bcc-core class names and namespaced WordPress functions).
 *
 * The $wpdb double models the failure semantics GuardsReadFailures::runRead()
 * relies on: query() returns the row count or false; a normal failure resets
 * then sets `last_error` (wpdb::flush()); the SILENT failure (`! $this->ready`)
 * returns false with `last_error` '' and `last_result` still holding the
 * previous query's rows.
 *
 * It returns the rows in the order the test gives them, with only the
 * columns the SELECT names, and applies only the LIMIT. The ORDER BY is real
 * SQL, so it is pinned against a real database
 * (CautionListOrderIntegrationTest), not here.
 *
 * Reads and writes:
 *   $GLOBALS['__caution_options']        option name => value
 *   $GLOBALS['__caution_option_writes']  list<array{name: string, autoload: mixed}>
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Stubs {
    final class CautionListWpdb
    {
        public string $prefix = 'wp_';
        public string $last_error = '';
        /** @var list<object>|null wpdb::$last_result */
        public ?array $last_result = [];
        /** @var list<int> the caution/risky owner ids, in the order the read returns them */
        public array $owners = [];
        /** @var array<int, string> owner id => reputation_tier; default 'caution' */
        public array $tiers = [];
        /** Number of upcoming queries that fail with an error. */
        public int $failNext = 0;
        /** Number of upcoming queries that hit the silent failure path. */
        public int $failSilentNext = 0;
        public int $queries = 0;
        /** The LIMIT of the last read. */
        public int $lastLimit = 0;
        /** @var array<int, mixed> */
        private array $args = [];

        public function prepare(string $sql, mixed ...$args): string
        {
            $this->args = $args;
            return $sql;
        }

        public function query(string $sql): int|false
        {
            $this->queries++;
            if ($this->failSilentNext > 0) {
                $this->failSilentNext--;
                return false; // no flush(): last_error stays '', last_result is stale
            }
            $this->last_error = ''; // wpdb::flush()
            if ($this->failNext > 0) {
                $this->failNext--;
                $this->last_error  = 'MySQL server has gone away';
                $this->last_result = [];
                return false;
            }
            $this->lastLimit = (int) ($this->args[1] ?? 0);
            // As a database does: only the columns the SELECT names.
            $selected = preg_match('/SELECT\s+(.*?)\s+FROM/s', $sql, $m) === 1
                ? array_map('trim', explode(',', $m[1]))
                : [];
            $this->last_result = array_map(
                function (int $id) use ($selected): object {
                    $row = [
                        'page_owner_id'   => (string) $id,
                        'reputation_tier' => $this->tiers[$id] ?? 'caution',
                    ];

                    return (object) array_intersect_key($row, array_flip($selected));
                },
                array_slice($this->owners, 0, $this->lastLimit)
            );
            return count($this->last_result);
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    function get_option(string $name, $default = false)
    {
        return array_key_exists($name, $GLOBALS['__caution_options'] ?? []) ? $GLOBALS['__caution_options'][$name] : $default;
    }

    function update_option(string $name, $value, $autoload = null): bool
    {
        $GLOBALS['__caution_options'][$name]  = $value;
        $GLOBALS['__caution_option_writes'][] = ['name' => $name, 'autoload' => $autoload];
        return true;
    }
}

namespace BCC\Core\Observability {
    if (!class_exists(__NAMESPACE__ . '\\DegradationMetrics', false)) {
        final class DegradationMetrics
        {
            /** @var list<string> "subsystem:event" */
            public static array $recorded = [];

            public static function record(string $subsystem, string $event): void
            {
                self::$recorded[] = $subsystem . ':' . $event;
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(__NAMESPACE__ . '\\Logger', false)) {
        final class Logger
        {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public static array $errors = [];

            /** @param array<string, mixed> $c */
            public static function error(string $m, array $c = []): void
            {
                self::$errors[] = [$m, $c];
            }

            /** @param array<string, mixed> $c */
            public static function warning(string $m, array $c = []): void
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
