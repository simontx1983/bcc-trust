<?php

declare(strict_types=1);

/**
 * Environment for PeepSoLeftoversServiceTest (separate process only).
 *
 *   $GLOBALS['__pl_tables']   array<string, int>     table => rows (present tables)
 *   $GLOBALS['__pl_options']  array<string, mixed>   wp_options (incl. peepso_config)
 *   $GLOBALS['__pl_usermeta'] array<string, int>     meta_key => rows
 *   $GLOBALS['__pl_dropped']  list<string>           DROP TABLE log
 */

$GLOBALS['__pl_tables']   = [];
$GLOBALS['__pl_options']  = [];
$GLOBALS['__pl_usermeta'] = [];
$GLOBALS['__pl_dropped']  = [];

if (!class_exists('PeepSoLeftoversFakeWpdb')) {
    final class PeepSoLeftoversFakeWpdb
    {
        public string $prefix     = 'wp_';
        public string $usermeta   = 'wp_usermeta';
        public string $last_error = '';
        /** @var list<mixed> */
        private array $args = [];

        public function prepare(string $sql, ...$args): string
        {
            $this->args = $args;
            return $sql;
        }

        public function esc_like(string $s): string
        {
            return addcslashes($s, '_%\\');
        }

        public function get_var(string $sql)
        {
            if (str_starts_with($sql, 'SHOW TABLES LIKE')) {
                $name = stripslashes((string) ($this->args[0] ?? ''));
                return array_key_exists($name, $GLOBALS['__pl_tables']) ? $name : null;
            }
            if (preg_match('/^SELECT COUNT\(\*\) FROM `([A-Za-z0-9_]+)`$/', $sql, $m)) {
                return (string) ($GLOBALS['__pl_tables'][$m[1]] ?? 0);
            }
            if (str_contains($sql, 'FROM wp_usermeta WHERE meta_key = %s')) {
                return (string) ($GLOBALS['__pl_usermeta'][(string) $this->args[0]] ?? 0);
            }
            return null;
        }

        /** @return list<object> */
        public function get_results(string $sql): array
        {
            $prefix = stripslashes(rtrim((string) ($this->args[0] ?? ''), '%'));
            $out    = [];
            foreach ($GLOBALS['__pl_usermeta'] as $key => $n) {
                if (str_starts_with($key, $prefix)) {
                    $out[] = (object) ['meta_key' => $key, 'n' => (string) $n];
                }
            }
            return $out;
        }

        public function query(string $sql)
        {
            if (preg_match('/^DROP TABLE IF EXISTS `([A-Za-z0-9_]+)`$/', $sql, $m)) {
                $GLOBALS['__pl_dropped'][] = $m[1];
                unset($GLOBALS['__pl_tables'][$m[1]]);
                return true;
            }
            return false;
        }
    }
}

if (!function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        return array_key_exists($key, $GLOBALS['__pl_options']) ? $GLOBALS['__pl_options'][$key] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option(string $key, $value, $autoload = null): bool
    {
        $GLOBALS['__pl_options'][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option(string $key): bool
    {
        if (!array_key_exists($key, $GLOBALS['__pl_options'])) {
            return false;
        }
        unset($GLOBALS['__pl_options'][$key]);
        return true;
    }
}
if (!function_exists('delete_metadata')) {
    function delete_metadata(string $type, int $objectId, string $key, $value = '', bool $all = false): bool
    {
        if ($type !== 'user' || $objectId !== 0 || !$all) {
            return false;   // the service must always delete for ALL users
        }
        unset($GLOBALS['__pl_usermeta'][$key]);
        return true;
    }
}
