<?php

declare(strict_types=1);

/**
 * Environment for AnnouncementArchiveAuthorityTest (separate process only).
 *
 *   $GLOBALS['__bcc_arch_own']     ?stdClass  row returned to the author-bound lookup
 *   $GLOBALS['__bcc_arch_public']  ?stdClass  row returned to the public lookup
 *   $GLOBALS['__bcc_arch_meta']    array<int, array<string, string>>
 *   $GLOBALS['__bcc_arch_actions'] list<array{string, mixed}>
 *   $GLOBALS['__bcc_arch_queries'] list<string>  every get_row SQL
 */

$GLOBALS['__bcc_arch_own']     = null;
$GLOBALS['__bcc_arch_public']  = null;
$GLOBALS['__bcc_arch_meta']    = [];
$GLOBALS['__bcc_arch_actions'] = [];
$GLOBALS['__bcc_arch_queries'] = [];

if (!class_exists('AnnouncementArchiveFakeWpdb')) {
    final class AnnouncementArchiveFakeWpdb
    {
        public string $prefix   = 'wp_';
        public string $posts    = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $last_error = '';
        public int $insert_id = 0;
        /** @var list<array{table: string, data: array<string, mixed>}> */
        public array $inserts = [];

        public function prepare(string $sql, ...$args): string
        {
            return $sql . ' /* ' . json_encode($args) . ' */';
        }

        public function get_row(string $sql)
        {
            $GLOBALS['__bcc_arch_queries'][] = $sql;
            if (str_contains($sql, 'p.post_author = %d')) {
                return $GLOBALS['__bcc_arch_own'];
            }
            if (str_contains($sql, "post_status = 'publish'")) {
                return $GLOBALS['__bcc_arch_public'];
            }
            return null;
        }

        /** @param array<string, mixed> $data */
        public function insert(string $table, array $data, $formats = null)
        {
            $this->inserts[] = ['table' => $table, 'data' => $data];
            $this->insert_id = count($this->inserts);
            return 1;
        }
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $id, string $key = '', bool $single = false)
    {
        return $GLOBALS['__bcc_arch_meta'][$id][$key] ?? '';
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta(int $id, string $key, $value)
    {
        $GLOBALS['__bcc_arch_meta'][$id][$key] = (string) $value;
        return true;
    }
}
if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $id, string $key): bool
    {
        unset($GLOBALS['__bcc_arch_meta'][$id][$key]);
        return true;
    }
}
if (!function_exists('do_action')) {
    function do_action(string $hook, ...$args): void
    {
        $GLOBALS['__bcc_arch_actions'][] = [$hook, $args[0] ?? null];
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int
    {
        return 0;
    }
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($s): string
    {
        return trim((string) $s);
    }
}
if (!function_exists('current_time')) {
    function current_time(string $type, $gmt = 0): string
    {
        return '2026-11-11 12:00:00';
    }
}
