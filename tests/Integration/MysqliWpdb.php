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

    /**
     * Make every statement matching this regex fail, as the server would.
     *
     * ── WHY FAULT INJECTION AND NOT A MOCK ──────────────────────────────
     * The migration's whole contract is what it does when a probe, an ALTER
     * or a backfill read DOESN'T answer — and those branches are unreachable
     * against a healthy database. A mock would let the test assert the
     * branch it wrote rather than the branch the code takes: the point here
     * is that the REAL function, running its REAL SQL against a REAL server,
     * takes the fail-closed path when one specific statement breaks.
     *
     * A failed query returns exactly what mysqli returns — `false` — and
     * `last_error` is set, so `bcc_onchain_probe_count()` sees `null` and
     * cannot mistake it for a genuine zero.
     */
    public ?string $failQueriesMatching = null;

    /** How many statements the injected fault has actually broken. */
    public int $injectedFailures = 0;

    /**
     * Make every statement matching this regex fail the way wpdb's own
     * SILENT paths do: return `false` with `last_error` left EMPTY.
     *
     * ── WHY A SECOND MODE ───────────────────────────────────────────────
     * `failQueriesMatching` sets `last_error`, so it is caught by a
     * `last_error` check alone — which means it CANNOT exercise the gap
     * that `trustedRows()` exists to close. `wpdb::query()` has real
     * failure paths that return false and record nothing: the
     * `! $this->ready` guard after a failed reconnect, and an empty result
     * from the `query` filter.
     *
     * ⚠⚠ AND THEY RETURN **BEFORE** `flush()`, so `last_result` still holds
     * the PREVIOUS query's rows. That is the dangerous half: a caller
     * checking only `last_error` reads a clean error AND a populated
     * `last_result`, so it can be handed an unrelated query's rows as an
     * answer. This mode is therefore applied before the flush model below,
     * clearing neither field — otherwise it would model something milder
     * than the real thing and a test over it would prove too little.
     */
    public ?string $failSilentlyMatching = null;

    /** How many statements the SILENT fault has broken. */
    public int $silentFailures = 0;

    public function clearFaultInjection(): void
    {
        $this->failQueriesMatching  = null;
        $this->injectedFailures     = 0;
        $this->failSilentlyMatching = null;
        $this->silentFailures       = 0;
    }

    private function shouldFailSilently(string $sql): bool
    {
        if ($this->failSilentlyMatching === null) {
            return false;
        }

        if (preg_match($this->failSilentlyMatching, $sql) !== 1) {
            return false;
        }

        $this->silentFailures++;

        return true;
    }

    private function shouldFail(string $sql): bool
    {
        if ($this->failQueriesMatching === null) {
            return false;
        }

        if (preg_match($this->failQueriesMatching, $sql) !== 1) {
            return false;
        }

        $this->injectedFailures++;

        return true;
    }

    // WP core table-name properties some repo SQL references.
    public string $options;
    public string $users;
    public string $usermeta;
    public string $posts;
    public string $postmeta;
    public string $comments;

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
        $this->comments = $prefix . 'comments';
    }

    /**
     * WordPress's LIKE escaper. `addcslashes($text, '_%\\')` is the real
     * implementation, byte for byte.
     *
     * ⚠ ADDED FOR S9b, AND IT WAS A HARD REQUIREMENT, NOT A CONVENIENCE.
     * `bcc_trust_drop_scanner_schema()` builds its leftover-option sweep as
     * `esc_like($prefix) . '%'`, so without this method the migration
     * cannot be called at all under the integration bootstrap — the sweep
     * would have been structurally untestable and the one destructive
     * migration in the scanner retirement would have had no real-engine
     * coverage of its final step.
     */
    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    /**
     * The connection's own escaper, as `wpdb::_real_escape()`.
     *
     * Used by the backup export in `scripts/scanner-schema-backup.php` to
     * render row values as SQL literals. It must be the REAL
     * `mysqli_real_escape_string` against THIS connection rather than
     * `addslashes`, because the two disagree on exactly the inputs the
     * export has to survive — quotes, backslashes, NUL, newline, CR and
     * Ctrl-Z — and a backup whose encoder is approximated in tests is a
     * backup whose encoder is untested.
     */
    public function _real_escape(string $data): string
    {
        return $this->db->real_escape_string($data);
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
     * Rows of the most recent SELECT, as real `wpdb::$last_result`.
     *
     * ⚠ REAL `wpdb` STORES OBJECTS HERE REGARDLESS of the `$output` the
     * caller asked for: `query()` fills it and the readers project out of
     * it afterwards.
     *
     * ⚠⚠ BUT ONLY TWO OF THE FOUR READERS HAVE A NULL FORM THAT READS IT,
     * and getting this wrong is how a double drifts from production while
     * looking more capable. In `class-wpdb.php`, `get_row()` and
     * `get_results()` open `if ( $query ) { ... } else { return null; }`,
     * so passing null to either returns **null** and never touches
     * `last_result`. `get_var()` and `get_col()` have NO else branch: they
     * fall through and read it. So `query($sql)` then `get_col(null)` is
     * the idiom, and `get_results(null)` is simply null.
     *
     * It is public because real `wpdb::$last_result` is public and callers
     * are entitled to read it.
     *
     * @var list<object>
     */
    public array $last_result = [];

    /**
     * Run a write/DDL query. Returns affected-row count for DML, true for DDL,
     * the ROW COUNT for a SELECT, or false on error (mirrors $wpdb->query).
     * Sets insert_id + last_error.
     *
     * ⚠ A SELECT run through here now FILLS `$last_result` and returns the
     * row count, because that is what real `wpdb::query()` does and it is
     * what the `query($sql)` then `get_col(null)` idiom depends on. It used
     * to free the result set and return `true`, storing nothing — so a
     * caller using that idiom got a TypeError here and correct data in
     * production. No test drives a SELECT through this method and asserts
     * its return value (checked), and the write/DDL paths are untouched.
     *
     * @return int|bool
     */
    public function query(string $sql)
    {
        // ⚠⚠ DELIBERATELY BEFORE the flush model below. wpdb's silent
        // failure paths (`! $this->ready`, an empty `query`-filter result)
        // return false WITHOUT clearing `last_error` or `last_result`, so
        // the previous query's rows stay readable and no error is recorded.
        // Clearing either here would model something milder than the real
        // fault. See $failSilentlyMatching.
        if ($this->shouldFailSilently($sql)) {
            return false;
        }

        $this->last_error = '';
        // Real `wpdb::query()` calls `flush()` first, which clears
        // `last_result`. Modelled, so a failed or non-SELECT query cannot
        // leave the PREVIOUS query's rows readable through `get_col(null)`.
        $this->last_result = [];
        if (trim($sql) === '') {
            // See get_results(): an empty query is a refused prepare(), not a crash.
            $this->last_error = 'Empty query (prepare() returned an empty string).';
            return false;
        }
        if ($this->shouldFail($sql)) {
            $this->last_error = 'injected fault';
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
        // A SELECT result set. Real `wpdb` keeps these in `last_result`
        // and returns the row count; the `get_col(null)` idiom reads them.
        while ($row = $result->fetch_object()) {
            $this->last_result[] = $row;
        }
        $result->free();
        return count($this->last_result);
    }

    /**
     * Project `$last_result` into one of wpdb's output shapes.
     *
     * Real `wpdb` holds objects and converts on the way out, so this is
     * where ARRAY_A / ARRAY_N are produced — never by fetching a different
     * mysqli shape, or `last_result` would disagree with the return value.
     *
     * @return list<object|array<string|int, mixed>>
     */
    private function projectLastResult(string $output): array
    {
        if ($output === 'ARRAY_A') {
            return array_map(static fn(object $r): array => (array) $r, $this->last_result);
        }
        if ($output === 'ARRAY_N') {
            return array_map(static fn(object $r): array => array_values((array) $r), $this->last_result);
        }
        return $this->last_result;
    }

    /**
     * @return list<object>
     *
     * An empty $sql means an upstream prepare() refused to build the query.
     * WordPress does not execute it and the caller sees "no rows"; mysqli would
     * instead throw a ValueError, which would misreport the failure as a crash
     * in the test rather than the silent empty result production actually gets.
     *
     * ⚠ `$output` ADDED FOR S9b. Production passes `ARRAY_A` in a dozen
     * repositories and `ARRAY_N` for `SHOW CREATE TABLE`, and PHP silently
     * IGNORES a surplus argument to a user-defined method — so before this
     * parameter existed, every such call got objects here and arrays in
     * production, and a test over one of those paths was asserting against
     * a shape production never returns. The backup export reads rows as
     * `ARRAY_A` and captures DDL as `ARRAY_N`, so it could not have been
     * covered honestly without this.
     */
    public function get_results(?string $sql = null, string $output = 'OBJECT'): ?array
    {
        // ⚠ NULL, NOT THE ROWS. Real `wpdb::get_results()` is
        // `if ( $query ) { ... } else { return null; }` — the null form is
        // not a "read last_result" shorthand, that is `get_col()` and
        // `get_var()` only. Returning rows here would make the double MORE
        // permissive than production, which is the same false-green class
        // this file exists to close.
        if ($sql === null) {
            return null;
        }
        $this->last_error = '';
        // ⚠⚠ FLUSH FIRST, on EVERY path below. Real `wpdb` reaches its
        // result-reading code only through `query()`, which calls `flush()`
        // and empties `last_result` before anything can fail. The branches
        // below (empty query, injected fault, mysqli error) return WITHOUT
        // going through `query()`, so without this a failed read would
        // leave the PREVIOUS query's rows readable through a later
        // `get_col(null)` — and at the bcc-core call site those rows are an
        // exclusion list, so stale data there excludes the wrong groups.
        $this->last_result = [];
        if (trim($sql) === '') {
            $this->last_error = 'Empty query (prepare() returned an empty string).';
            return [];
        }
        if ($this->shouldFail($sql)) {
            // ⚠ AN EMPTY ARRAY, NOT FALSE — and this is the failure class.
            // `wpdb::get_results()` hands back `last_result` for a failed
            // query exactly as it does for a genuine no-rows result, and
            // returns null only for an empty query string. `last_error` is
            // the ONLY thing that separates them, which is why every caller
            // in PR 6 has to consult it. Returning false here would let a
            // caller notice the failure by its type and never exercise that.
            $this->last_error = 'injected fault';
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
        // Fetch objects ONCE into `last_result`, then project — the order
        // real `wpdb` uses. Fetching the requested shape directly instead
        // would leave `last_result` empty or disagreeing with the return.
        $this->last_result = [];
        while ($row = $res->fetch_object()) {
            $this->last_result[] = $row;
        }
        $res->free();
        return $this->projectLastResult($output);
    }

    /**
     * ⚠ `$output` and `$y` ADDED HERE for the same reason `$output` was
     * added to `get_results()`: real `wpdb::get_row($query, $output, $y)`
     * takes them, PHP SILENTLY IGNORES a surplus argument to a
     * user-defined method, and so a caller passing `ARRAY_A` would have
     * received an object here and an array in production — with no error
     * anywhere. A latent gap rather than a live break: no current caller
     * in either plugin passes them (checked). Closed now so it cannot
     * become one.
     *
     * ⚠ The null form returns NULL, like real
     * `wpdb::get_row()`'s `else { return null; }` — it does NOT read
     * `last_result`. Only `get_var()` and `get_col()` do.
     *
     * @return object|array<string|int, mixed>|null
     */
    public function get_row(?string $sql = null, string $output = 'OBJECT', int $y = 0)
    {
        if ($sql === null) {
            return null;
        }
        $rows = $this->get_results($sql, $output);
        return $rows === null ? null : ($rows[$y] ?? null);
    }

    /**
     * ⚠ `$x` and `$y` ADDED — same silent-surplus-argument class as
     * `get_row()` above. A caller asking for column 1 would have been
     * handed column 0 here and column 1 in production, with no error.
     * No current caller passes them (checked).
     *
     * ⚠ It reads `last_result` DIRECTLY rather than through
     * `get_results(null)`, which returns null (see above). Real `wpdb`'s
     * `get_var()` has no else branch at all: it runs the query when given
     * one, then reads `last_result` either way.
     *
     * The single-row fast path is PRESERVED for the ordinary call
     * (`get_var($sql)`), so every existing call keeps its cost and
     * behaviour. A non-zero `$y` fetches every row, because it has to
     * reach row `$y`.
     *
     * ⚠ `get_var($sql)` now reads the row through `(array) $object`, so
     * DUPLICATE COLUMN NAMES COLLAPSE — `SELECT a.id, b.id` yields one
     * entry, and column index 1 is whatever came third. That is exactly
     * what production does (real `wpdb` stores objects too), and the
     * previous `fetch_row()` did NOT collapse them. A test that relied on
     * positional access across duplicate names would change meaning here,
     * and it changes it TOWARDS production.
     *
     * @return mixed
     */
    public function get_var(?string $sql = null, int $x = 0, int $y = 0)
    {
        if ($sql !== null) {
            $this->runForLastResult($sql, $y > 0);
        }
        $row = $this->last_result[$y] ?? null;
        if ($row === null) {
            return null;
        }
        $vals = array_values((array) $row);
        return $vals[$x] ?? null;
    }

    /**
     * Execute `$sql` and leave the rows in `last_result`, flushing first.
     *
     * Factored out of `get_var()` so its fast path and its `$y > 0` path
     * share one set of failure semantics. `$all = false` fetches a single
     * row, which is what an ordinary `get_var($sql)` needs.
     */
    private function runForLastResult(string $sql, bool $all): void
    {
        $this->last_error = '';
        // ⚠ Flush first — see get_results(). Every early return below
        // must leave `last_result` empty, not stale.
        $this->last_result = [];
        if ($this->shouldFail($sql)) {
            // ⚠ The CALLER returns null, not false. Real
            // `wpdb::get_var()` returns null for a failed query — it never
            // returns false — and the difference is the whole point of the
            // fault. `bcc_onchain_probe_count()` casts a non-null to int,
            // so a `false` there becomes 0, and a probe that never ran
            // reads as "the column is absent": exactly the conversion
            // these tests exist to forbid. Leaving `last_result` empty is
            // what makes the caller return null.
            $this->last_error = 'injected fault';
            return;
        }
        $this->queryCount++;
        $res = @$this->db->query($sql);
        if ($res === false || $res === true) {
            if ($res === false) {
                $this->last_error = $this->db->error;
            }
            return;
        }
        if ($all) {
            while ($row = $res->fetch_object()) {
                $this->last_result[] = $row;
            }
        } else {
            $obj = $res->fetch_object();
            if ($obj !== null) {
                $this->last_result[] = $obj;
            }
        }
        $res->free();
    }

    /**
     * ⚠ `$sql` IS NULLABLE AND `$x` EXISTS, because real `wpdb` is
     * `get_col( $query = null, $x = 0 )` and the null form — project
     * `last_result` without re-querying — is a documented, used idiom.
     * bcc-core's `PeepSoGroupRepository` calls `$wpdb->query($prepared)`
     * then `$wpdb->get_col(null)` at two sites; against the previous
     * `get_col(string $sql)` that was a hard
     * `TypeError: Argument #1 ($sql) must be of type string, null given`,
     * which red-lit bcc-trust's integration suite on EVERY branch,
     * including main, for code that is correct in production.
     *
     * ⚠ Like real `wpdb::get_col()` it has no else branch: it runs the
     * query when given one and then reads `last_result` DIRECTLY — not
     * via `get_results(null)`, which returns null.
     *
     * @return list<mixed>
     */
    public function get_col(?string $sql = null, int $x = 0): array
    {
        if ($sql !== null) {
            // Fills (and flushes) `last_result`; its return is irrelevant.
            $this->get_results($sql);
        }
        $out = [];
        foreach ($this->last_result as $row) {
            $vals = array_values((array) $row);
            $out[] = $vals[$x] ?? null;
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
