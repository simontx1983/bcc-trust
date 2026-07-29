<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use mysqli;

/**
 * Minimal mysqli-backed `$wpdb` shim for integration tests.
 *
 * Implements the subset of WordPress's $wpdb API that the BCC repositories
 * actually call (see the grep in the integration bootstrap), backed by a real
 * MySQL connection to a THROWAWAY test database — so the integration tests
 * exercise the genuine SQL (DELETE batching, UNIQUE upserts, FOR UPDATE) rather
 * than asserting against a recorded query string.
 *
 * NOT a full $wpdb: prepare() does WP-style %d/%s/%f placeholder substitution
 * with escaping (WP itself doesn't use native prepared statements either), and
 * results default to objects (OBJECT mode), which is what the repos consume.
 */
final class MysqliWpdb
{
    public string $prefix;
    public int $insert_id = 0;
    public int $rows_affected = 0;
    public string $last_error = '';

    // WP core table-name properties some repo SQL references.
    public string $options;
    public string $users;
    public string $usermeta;
    public string $posts;
    public string $postmeta;

    private mysqli $db;
    private bool $suppressErrors = false;

    public function __construct(mysqli $db, string $prefix = 'wp_')
    {
        $this->db       = $db;
        $this->prefix   = $prefix;
        $this->options  = $prefix . 'options';
        $this->users    = $prefix . 'users';
        $this->usermeta = $prefix . 'usermeta';
        $this->posts    = $prefix . 'posts';
        $this->postmeta = $prefix . 'postmeta';
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    /**
     * Match WP's suppress_errors contract: set the flag, return the PREVIOUS
     * value so callers can restore it. TableRegistry::columnExists brackets its
     * `SHOW COLUMNS` probe with this pair, so any repository guarded by a
     * columnExists() check (e.g. the §J.12 elite gate) needs it to exist here.
     * The shim never echoes errors anyway — last_error is what tests read — so
     * this only has to be honest about the flag.
     */
    public function suppress_errors(bool $suppress = true): bool
    {
        $previous             = $this->suppressErrors;
        $this->suppressErrors = $suppress;
        return $previous;
    }

    /**
     * WP-style prepare: %d → int, %f → float, %s → 'escaped'. Accepts either
     * variadic args or a single array (both forms the repos use).
     *
     * @param mixed ...$args
     */
    public function prepare(string $query, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = array_values($args[0]);
        }
        $i = 0;
        $out = preg_replace_callback('/%[dsfF]/', function (array $m) use (&$i, $args): string {
            $v = $args[$i] ?? '';
            $i++;
            switch ($m[0]) {
                case '%d':
                    return (string) (int) $v;
                case '%f':
                case '%F':
                    return (string) (float) $v;
                case '%s':
                default:
                    return "'" . $this->db->real_escape_string((string) $v) . "'";
            }
        }, $query);
        return $out ?? $query;
    }

    /**
     * Run a write/DDL query. Returns affected-row count for DML, true for DDL,
     * or false on error (mirrors $wpdb->query). Sets insert_id + last_error.
     *
     * @return int|bool
     */
    public function query(string $sql)
    {
        $this->last_error = '';
        $result = @$this->db->query($sql);
        if ($result === false) {
            $this->last_error = $this->db->error;
            return false;
        }
        $this->insert_id = (int) $this->db->insert_id;
        if ($result === true) {
            // DML (INSERT/UPDATE/DELETE) → affected rows; DDL → 0 affected, still success.
            $affected            = $this->db->affected_rows;
            $this->rows_affected = max(0, (int) $affected);
            return $affected >= 0 ? $affected : true;
        }
        // SELECT returned a result set — caller should have used get_*; free it.
        $result->free();
        return true;
    }

    /** @return list<object> */
    public function get_results(string $sql): array
    {
        $this->last_error = '';
        $res = @$this->db->query($sql);
        if ($res === false || $res === true) {
            if ($res === false) {
                $this->last_error = $this->db->error;
            }
            return [];
        }
        $rows = [];
        while ($row = $res->fetch_object()) {
            $rows[] = $row;
        }
        $res->free();
        return $rows;
    }

    public function get_row(string $sql): ?object
    {
        $rows = $this->get_results($sql);
        return $rows[0] ?? null;
    }

    /** @return mixed */
    public function get_var(string $sql)
    {
        $this->last_error = '';
        $res = @$this->db->query($sql);
        if ($res === false || $res === true) {
            if ($res === false) {
                $this->last_error = $this->db->error;
            }
            return null;
        }
        $row = $res->fetch_row();
        $res->free();
        return $row[0] ?? null;
    }

    /** @return list<mixed> */
    public function get_col(string $sql): array
    {
        $out = [];
        foreach ($this->get_results($sql) as $row) {
            $vals = array_values((array) $row);
            $out[] = $vals[0] ?? null;
        }
        return $out;
    }

    /**
     * @param array<string, mixed>      $data
     * @param list<string>|string|null  $format unused (we infer)
     * @return int|false rows inserted
     */
    public function insert(string $table, array $data, $format = null)
    {
        $cols = array_keys($data);
        $vals = array_map(fn($v): string => $v === null ? 'NULL' : "'" . $this->db->real_escape_string((string) $v) . "'", array_values($data));
        $sql  = "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ")";
        $r = $this->query($sql);
        return $r === false ? false : (int) $r;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     * @return int|false rows affected
     */
    public function update(string $table, array $data, array $where, $format = null, $whereFormat = null)
    {
        $set = [];
        foreach ($data as $c => $v) {
            $set[] = "`{$c}`=" . ($v === null ? 'NULL' : "'" . $this->db->real_escape_string((string) $v) . "'");
        }
        $cond = [];
        foreach ($where as $c => $v) {
            $cond[] = "`{$c}`=" . ($v === null ? 'NULL' : "'" . $this->db->real_escape_string((string) $v) . "'");
        }
        $sql = "UPDATE `{$table}` SET " . implode(',', $set) . ' WHERE ' . implode(' AND ', $cond);
        $r = $this->query($sql);
        return $r === false ? false : (int) $r;
    }

    /**
     * @param array<string, mixed> $where
     * @return int|false rows deleted
     */
    public function delete(string $table, array $where, $whereFormat = null)
    {
        $cond = [];
        foreach ($where as $c => $v) {
            $cond[] = "`{$c}`=" . ($v === null ? 'NULL' : "'" . $this->db->real_escape_string((string) $v) . "'");
        }
        $sql = "DELETE FROM `{$table}` WHERE " . implode(' AND ', $cond);
        $r = $this->query($sql);
        return $r === false ? false : (int) $r;
    }
}
