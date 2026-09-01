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

    /**
     * Every statement actually sent to the server.
     *
     * Exists so a test can assert an N+1 is ABSENT. Counting rows returned
     * proves nothing about how many round trips produced them, and an
     * admin list that issues one lookup per row still looks correct until
     * the page is big enough to time out.
     */
    public int $queryCount = 0;

    public function resetQueryCount(): void
    {
        $this->queryCount = 0;
    }

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
     * Placeholder/argument mismatches seen by prepare(), newest last.
     *
     * WordPress reports these via _doing_it_wrong(); there is no WP here, so the
     * shim records them instead and tests assert the list is empty.
     *
     * @var list<string>
     */
    public array $doingItWrong = [];

    /** Forget any recorded prepare() mismatches. */
    public function resetDoingItWrong(): void
    {
        $this->doingItWrong = [];
    }

    /**
     * Count the placeholders `wpdb::prepare()` would count in $query.
     *
     * Deliberately textual, exactly like WordPress: `prepare()` does not parse
     * SQL, so a `%f` sitting inside an embedded SQL comment counts just the
     * same as one in a value position. Reproducing that is the whole point —
     * the shim used to substitute positionally and silently pad missing args
     * with '', which is why a real 14-placeholders/13-arguments defect in
     * VoteRepository::getVoteAggregatesForPage() passed the integration suite
     * while failing in production.
     *
     * `%%` is an escaped literal percent and is not a placeholder.
     */
    private static function countPlaceholders(string $query): int
    {
        $stripped = str_replace('%%', '', $query);
        return preg_match_all('/%(?:\d+\$)?[dsfFi]/', $stripped);
    }

    /**
     * WP-style prepare: %d → int, %f → float, %s → 'escaped', %i → identifier.
     * Accepts either variadic args or a single array (both forms the repos use).
     *
     * Mirrors WordPress's contract on a count mismatch: record the fault and
     * return an empty string, so the caller's query does not run. That empty
     * return is what turns this class of bug into silently-zeroed results
     * rather than a loud failure, so the shim has to reproduce it.
     *
     * @param mixed ...$args
     */
    public function prepare(string $query, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = array_values($args[0]);
        }

        $placeholders = self::countPlaceholders($query);
        $argCount     = count($args);

        if ($placeholders !== $argCount) {
            $this->doingItWrong[] = sprintf(
                'The query does not contain the correct number of placeholders (%d) for the number of arguments passed (%d).',
                $placeholders,
                $argCount
            );

            // WP returns '' when it has fewer arguments than placeholders and
            // no numbered placeholders are in play. Anything else is left to
            // substitute so over-supplied args stay as visible as they are in WP.
            if ($argCount < $placeholders && !preg_match('/%\d+\$/', $query)) {
                return '';
            }
        }

        $i = 0;
        $out = preg_replace_callback('/%[dsfFi]/', function (array $m) use (&$i, $args): string {
            $v = $args[$i] ?? '';
            $i++;
            switch ($m[0]) {
                case '%d':
                    return (string) (int) $v;
                case '%f':
                case '%F':
                    return (string) (float) $v;
                case '%i':
                    return '`' . str_replace('`', '``', (string) $v) . '`';
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
        if (trim($sql) === '') {
            // See get_results(): an empty query is a refused prepare(), not a crash.
            $this->last_error = 'Empty query (prepare() returned an empty string).';
            return false;
        }
        $this->queryCount++;
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

    /**
     * @return list<object>
     *
     * An empty $sql means an upstream prepare() refused to build the query.
     * WordPress does not execute it and the caller sees "no rows"; mysqli would
     * instead throw a ValueError, which would misreport the failure as a crash
     * in the test rather than the silent empty result production actually gets.
     */
    public function get_results(string $sql): array
    {
        $this->last_error = '';
        if (trim($sql) === '') {
            $this->last_error = 'Empty query (prepare() returned an empty string).';
            return [];
        }
        $this->queryCount++;
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
        $this->queryCount++;
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
