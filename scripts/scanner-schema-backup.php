<?php
/**
 * Scanner-schema backup and verification — the S9b recovery artefact.
 *
 * ════════════════════════════════════════════════════════════════════════
 * WHY THIS IS CODE AND NOT A RUNBOOK SNIPPET
 * ════════════════════════════════════════════════════════════════════════
 * The restore path for `bcc_trust_drop_scanner_schema()` is the only thing
 * standing between a mistake and permanent data loss: the migration drops
 * three populated tables and eight columns. A procedure written as prose
 * cannot be tested, and an untested encoder is exactly where a backup
 * quietly stops round-tripping. Everything here is therefore a pure
 * function with integration coverage — see
 * `tests/Integration/ScannerSchemaBackupExportIntegrationTest.php`.
 *
 * ⚠ `wp db export` DOES NOT WORK ON THE PRODUCTION HOST. Measured
 * 2026-10-09: *"Cannot do 'Process::run': The PHP functions `proc_open()`
 * and/or `proc_close()` are disabled."* wp-cli shells out to `mysqldump`,
 * and the host's PHP policy forbids that. `mysqldump` is on `PATH` and
 * could be driven directly, but only by handing credentials to a CLI. So
 * this runs inside `wp eval-file … --skip-plugins --skip-themes` and uses
 * the connection WordPress already holds: no credentials on a command
 * line, no `proc_open`, no `--defaults-extra-file` to shred afterwards.
 *
 * ════════════════════════════════════════════════════════════════════════
 * TWO HARD-WON RULES, BOTH FROM FAILURES
 * ════════════════════════════════════════════════════════════════════════
 *
 * ── 1. CAPTURE DDL, NEVER RECONSTRUCT IT ────────────────────────────────
 * Building `ADD COLUMN` statements from `INFORMATION_SCHEMA.COLUMNS` was
 * tried and produced two defects that are silently wrong SQL rather than
 * errors: `COLUMN_DEFAULT` returns some string defaults ALREADY QUOTED, so
 * naive quoting yields `DEFAULT ''idle''`; and a nullable column's default
 * comes back as the literal string `"NULL"`, yielding `DEFAULT 'NULL'` —
 * a column that now defaults to the four characters N-U-L-L. Only
 * `SHOW CREATE TABLE` is byte-exact, so only `SHOW CREATE TABLE` is used.
 *
 * ── 2. AN INDEX CAN SPAN A RETIRED COLUMN ───────────────────────────────
 * The first restore rehearsal FAILED with `ERROR 1072 (42000): Key column
 * 'cw_discovery_state' doesn't exist in table`, because
 * `KEY idx_cw_discovery (cw_discovery_state, cw_last_discovery_at)` spans
 * two of the dropped columns. The artefact had captured the eight columns
 * and not the index, so a restore would have come back silently missing
 * it. `bcc_trust_backup_index_definitions()` exists for that reason, and
 * the restore order is columns-then-indexes for the same reason.
 *
 * ════════════════════════════════════════════════════════════════════════
 * AND WHY THE DIGEST IS LENGTH-PREFIXED
 * ════════════════════════════════════════════════════════════════════════
 * `CHECKSUM TABLE` is deliberately NOT the evidence: it is engine- and
 * version-sensitive, and it cannot tell you WHICH row or column differs.
 * The digest below runs identically on both sides instead.
 *
 * ⚠⚠ But a canonical `CONCAT_WS(sep, …)` digest — the obvious shape, and
 * the one the 2026-10-09 rehearsal used — CANNOT DISTINGUISH values that
 * contain the separator. With `sep = 0x1f`:
 *
 *     CONCAT_WS(0x1f, 'a', 'b<0x1f>c')  ->  a<0x1f>b<0x1f>c
 *     CONCAT_WS(0x1f, 'a<0x1f>b', 'c')  ->  a<0x1f>b<0x1f>c      COLLISION
 *
 * Two different rows, one digest. The same hole swallows the NULL marker:
 * if NULL is rendered as some sentinel string, a value equal to that
 * string is indistinguishable from NULL.
 *
 * So every column is rendered as `OCTET_LENGTH(col) : col`, and NULL as a
 * single `~`. A length-prefixed rendering always begins with a digit, so
 * it can never be confused with `~`; and a length prefix makes the
 * concatenation unambiguous whatever bytes the values contain. The empty
 * string renders as `0:`, which is distinct from both.
 *
 * ⚠ The per-row hashes are folded IN PHP, not with `GROUP_CONCAT`.
 * `group_concat_max_len` defaults to 1024 bytes on both engines and
 * TRUNCATES SILENTLY — a table big enough to matter is exactly a table
 * whose SQL-side fold would be truncated, and the digest would still look
 * like a digest.
 *
 * @package BCC\Trust
 * @see     \bcc_trust_drop_scanner_schema()
 * @see     docs/scanner-schema-drop-runbook.md
 */

declare(strict_types=1);

if (!function_exists('bcc_trust_backup_sql_literal')) {

    /**
     * Render one value as a SQL literal for an INSERT.
     *
     * NULL is emitted UNQUOTED, because `'NULL'` is the string and `NULL`
     * is the value, and a backup that confuses them restores the wrong
     * thing without erroring. Everything else is quoted and escaped
     * through `$wpdb`'s own escaper — the same `mysqli_real_escape_string`
     * the connection would use — which covers quotes, backslashes, NUL,
     * newline, carriage return and Ctrl-Z.
     *
     * Control bytes that are NOT special to SQL (0x1e, 0x1f and friends)
     * need no escaping: inside a quoted literal they are ordinary bytes.
     * They matter to the DIGEST, not to the INSERT, which is why the two
     * concerns are separate functions.
     *
     * @param scalar|null $value
     */
    function bcc_trust_backup_sql_literal($value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        global $wpdb;

        // Booleans and numbers are normalised to their string form first so
        // the escaper sees exactly what the column will store.
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        }

        return "'" . $wpdb->_real_escape((string) $value) . "'";
    }

    /**
     * One INSERT per row, columns named explicitly.
     *
     * Named columns rather than positional values, because a restore may
     * land on a table whose column ORDER differs — and it will: production's
     * `cosmwasm_nft_discovery_enabled` sits `AFTER description`, not where
     * the installer would have put it, because an earlier release used a
     * different anchor. Order is cosmetic to every reader, but a positional
     * INSERT would silently shuffle values into the wrong columns.
     *
     * One statement per row rather than a multi-row INSERT so that a single
     * rejected row is identifiable instead of failing the whole batch.
     *
     * @param  list<array<string, scalar|null>> $rows
     * @return list<string>
     */
    function bcc_trust_backup_insert_statements(string $table, array $rows): array
    {
        $out = [];

        foreach ($rows as $row) {
            if ($row === []) {
                continue;
            }
            $columns = [];
            $values  = [];
            foreach ($row as $column => $value) {
                $columns[] = '`' . str_replace('`', '``', (string) $column) . '`';
                $values[]  = bcc_trust_backup_sql_literal($value);
            }
            $out[] = 'INSERT INTO `' . str_replace('`', '``', $table) . '` ('
                . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ');';
        }

        return $out;
    }

    /**
     * The per-row fingerprint expression — the heart of the verification.
     *
     * See the length-prefix rationale in the file docblock. In short:
     * `OCTET_LENGTH(col) : col` for a value, `~` for NULL, and the two
     * forms cannot be confused because one always starts with a digit.
     *
     * @param  list<string> $columns
     * @return string a SQL scalar expression
     */
    function bcc_trust_backup_row_fingerprint_expr(array $columns): string
    {
        $parts = [];

        foreach ($columns as $column) {
            $quoted  = '`' . str_replace('`', '``', $column) . '`';
            $parts[] = "COALESCE(CONCAT(OCTET_LENGTH({$quoted}), ':', {$quoted}), '~')";
        }

        // CONCAT (not CONCAT_WS) — the length prefixes already delimit, and
        // CONCAT_WS would silently skip a NULL part, which COALESCE has
        // already made impossible anyway.
        return 'SHA2(CONCAT(' . implode(', ', $parts) . "), 256)";
    }

    /**
     * ⚠ FOR TESTS AND DOCUMENTATION ONLY — the NAIVE expression, kept so
     * the collision it suffers from can be demonstrated rather than
     * asserted about.
     *
     * `ScannerSchemaBackupExportIntegrationTest` uses this as a planted
     * positive: it proves two adversarial rows really do collide under the
     * obvious formula, which is what makes the length prefix in
     * `bcc_trust_backup_row_fingerprint_expr()` load-bearing instead of
     * decorative. Nothing in the backup path calls it.
     *
     * @param  list<string> $columns
     * @return string a SQL scalar expression
     */
    function bcc_trust_backup_row_fingerprint_expr_naive(array $columns): string
    {
        $parts = [];

        foreach ($columns as $column) {
            $quoted  = '`' . str_replace('`', '``', $column) . '`';
            $parts[] = "COALESCE({$quoted}, 'NULL')";
        }

        return 'SHA2(CONCAT_WS(0x1f, ' . implode(', ', $parts) . '), 256)';
    }

    /**
     * Digest a whole table: every row, every named column, order-independent.
     *
     * Returns null when the read could not be completed, which the caller
     * must treat as UNVERIFIED — never as "the table is empty". An empty
     * table has its own digest (the hash of the empty string), so the two
     * are distinguishable, which is the point.
     *
     * @param  list<string> $columns
     * @return string|null  64 hex characters, or null if unreadable
     */
    function bcc_trust_backup_table_digest(string $table, array $columns): ?string
    {
        if ($columns === []) {
            return null;
        }

        global $wpdb;

        $expr = bcc_trust_backup_row_fingerprint_expr($columns);

        /** @var list<string>|null $hashes */
        $hashes = $wpdb->get_col(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            "SELECT {$expr} AS rh FROM `" . str_replace('`', '``', $table) . '`'
        );

        if (!is_array($hashes) || $wpdb->last_error !== '') {
            return null;
        }

        // Sorted in PHP, folded in PHP. See the GROUP_CONCAT warning above:
        // `group_concat_max_len` truncates at 1024 bytes by default and says
        // nothing about it, so a SQL-side fold would produce a plausible
        // digest over a prefix of the table.
        sort($hashes, SORT_STRING);

        return hash('sha256', implode("\n", $hashes));
    }

    /**
     * `SHOW CREATE TABLE`, verbatim. Rule 1 of this file.
     *
     * @return string|null null when the table does not exist or is unreadable
     */
    function bcc_trust_backup_capture_ddl(string $table): ?string
    {
        global $wpdb;

        /** @var list<array<int|string, string>>|null $rows */
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            'SHOW CREATE TABLE `' . str_replace('`', '``', $table) . '`',
            ARRAY_N
        );

        if (!is_array($rows) || $rows === [] || !isset($rows[0][1])) {
            return null;
        }

        return (string) $rows[0][1];
    }

    /**
     * The `ADD COLUMN` fragments for `$columns`, lifted verbatim out of the
     * parent's own `SHOW CREATE TABLE`.
     *
     * A column absent from the DDL is simply absent from the result — the
     * caller compares counts to learn that, rather than receiving a
     * fabricated definition.
     *
     * @param  list<string> $columns
     * @return array<string, string> column name => `ALTER TABLE … ADD COLUMN …`
     */
    function bcc_trust_backup_column_definitions(string $table, array $columns): array
    {
        $ddl = bcc_trust_backup_capture_ddl($table);
        if ($ddl === null) {
            return [];
        }

        $out = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $ddl)) as $line) {
            $line = rtrim(trim($line), ',');
            foreach ($columns as $column) {
                $needle = '`' . $column . '`';
                if (strncmp($line, $needle . ' ', strlen($needle) + 1) !== 0) {
                    continue;
                }
                $out[$column] = 'ALTER TABLE `' . str_replace('`', '``', $table)
                    . '` ADD COLUMN ' . $line . ';';
            }
        }

        return $out;
    }

    /**
     * The `ADD KEY` fragments for every index NAMING one of `$columns`,
     * lifted verbatim out of the parent's own `SHOW CREATE TABLE`.
     *
     * ⚠⚠ RULE 2 OF THIS FILE LIVES HERE. Omitting this is what made the
     * first rehearsal's restore fail, and what would have made a later
     * restore come back silently missing `idx_cw_discovery`.
     *
     * Derived from the DDL rather than from a hardcoded index name, so an
     * install carrying a differently-named index over the same columns is
     * captured too — which is the same reason the drop migration derives
     * its index set instead of naming one.
     *
     * @param  list<string> $columns
     * @return array<string, string> index name => `ALTER TABLE … ADD … KEY …`
     */
    function bcc_trust_backup_index_definitions(string $table, array $columns): array
    {
        $ddl = bcc_trust_backup_capture_ddl($table);
        if ($ddl === null) {
            return [];
        }

        $out = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $ddl)) as $line) {
            $line = rtrim(trim($line), ',');

            if (!preg_match('/^(?:UNIQUE |FULLTEXT |SPATIAL )?KEY\s+`((?:[^`]|``)+)`\s*\((.+)\)$/', $line, $m)) {
                continue;
            }

            $indexName = str_replace('``', '`', $m[1]);
            $spans     = false;
            foreach ($columns as $column) {
                if (str_contains($m[2], '`' . $column . '`')) {
                    $spans = true;
                    break;
                }
            }
            if (!$spans) {
                continue;
            }

            $out[$indexName] = 'ALTER TABLE `' . str_replace('`', '``', $table)
                . '` ADD ' . $line . ';';
        }

        return $out;
    }

    /**
     * Read a whole table as associative rows, bounded and fail-closed.
     *
     * @return list<array<string, scalar|null>>|null null when unreadable
     */
    function bcc_trust_backup_read_rows(string $table, int $limit = 100000): ?array
    {
        global $wpdb;

        /** @var list<array<string, scalar|null>>|null $rows */
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            'SELECT * FROM `' . str_replace('`', '``', $table) . '` LIMIT ' . max(1, $limit),
            ARRAY_A
        );

        if (!is_array($rows) || $wpdb->last_error !== '') {
            return null;
        }

        return $rows;
    }
}
