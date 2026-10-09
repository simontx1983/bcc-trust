<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The S9b backup export: value encoding, and whether the digest can TELL
 * VALUES APART.
 *
 * ── WHY THIS EXISTS ─────────────────────────────────────────────────────
 * `bcc_trust_drop_scanner_schema()` destroys three populated tables and
 * eight columns. The only thing between a mistake and permanent loss is the
 * artefact `scripts/scanner-schema-backup.php` produces, and the only thing
 * that says the artefact is good is a digest comparison. So two different
 * properties have to hold, and neither is obvious:
 *
 *   1. The export ROUND-TRIPS every byte — NULLs, empty strings, quotes,
 *      backslashes, newlines, NUL, Ctrl-Z, trailing spaces, and the control
 *      bytes a digest might use as delimiters.
 *   2. The digest DISTINGUISHES those values. A digest that round-trips a
 *      corrupted restore to the same hash is worse than no digest, because
 *      it is read as proof.
 *
 * Property 2 is the one that fails quietly, and it is the reason the
 * fingerprint is length-prefixed rather than the obvious `CONCAT_WS`. This
 * suite proves the obvious form really does collide — see
 * `testTheDigestDistinguishesRowsTheNaiveFormulaCollides`, which is a
 * planted positive against `..._naive()`, kept in the script for exactly
 * that purpose.
 *
 * ── WHY IT CANNOT BE A UNIT TEST ────────────────────────────────────────
 * Both the escaping and the digest are the SERVER's behaviour:
 * `real_escape_string` is connection- and charset-dependent, `OCTET_LENGTH`
 * and `SHA2` are engine functions, and VARCHAR comparison semantics (which
 * is why every comparison here goes through `HEX()`) are collation
 * behaviour. A double would assert my own idea of all four.
 *
 * ⚠ RUNS ON BOTH ENGINES BY DESIGN. Production is MariaDB 11.8.9, and the
 * first restore rehearsal was mistakenly done on MySQL 8.0.46, where
 * display widths and default quoting differ enough to give false confidence
 * about DDL portability. Carrying both groups is what makes that mistake
 * impossible to repeat silently.
 *
 * ⚠ This class creates and drops only its OWN scratch tables. It shares
 * nothing, so it cannot leak state into a sibling suite.
 *
 * @see scripts/scanner-schema-backup.php
 * @see docs/scanner-schema-drop-runbook.md
 */
#[Group('integration')]
#[Group('mariadb')]
final class ScannerSchemaBackupExportIntegrationTest extends TestCase
{
    private const SRC = 'bcc_s9b_enc_src';
    private const DST = 'bcc_s9b_enc_dst';
    private const DDL = 'bcc_s9b_enc_ddl';

    /**
     * The adversarial corpus: `[id, a, b]`, where null is a real SQL NULL.
     *
     * Each row is here because it defeats a specific plausible shortcut, and
     * the comment says which.
     *
     * @return list<array{int, string|null, string|null}>
     */
    private function corpus(): array
    {
        return [
            // NULL vs the strings that get mistaken for it. A sentinel-string
            // NULL marker makes rows 1-4 indistinguishable.
            [1, null, null],
            [2, '', ''],
            [3, 'NULL', 'NULL'],
            [4, '~', '~'],

            // The delimiter bytes themselves. 0x1f is the separator the naive
            // CONCAT_WS form uses; 0x1e is its usual record partner.
            [5, "\x1f", 'after-unit-sep'],
            [6, "\x1e", 'after-record-sep'],

            // ⚠ THE COLLISION PAIR. Under CONCAT_WS(0x1f, a, b) rows 7 and 8
            // produce the IDENTICAL string "a<0x1f>b<0x1f>c".
            [7, "a\x1fb", 'c'],
            [8, 'a', "b\x1fc"],

            // Quotes and backslashes - the classic escaping failures.
            [9, "it's", 'single quote'],
            [10, 'say "hi"', 'double quote'],
            [11, 'back\\slash', 'one backslash'],
            [12, 'double\\\\slash', 'two backslashes'],
            [13, "quote'and\\slash", 'both at once'],
            [14, "\\'", 'a backslash then a quote - the SQL-injection shape'],

            // Bytes `real_escape_string` must handle but `addslashes` gets
            // wrong or leaves alone.
            [15, "line\nbreak", 'newline'],
            [16, "carriage\rreturn", 'CR'],
            [17, "ctrl\x1aZ", 'Ctrl-Z, which MySQL treats specially'],
            [18, "nul\0byte", 'NUL'],

            // Values that MIMIC the length-prefixed rendering, so the
            // encoding cannot be confused with its own framing.
            [19, '0:', 'looks like a length-prefixed empty string'],
            [20, '3:abc', 'looks like its own fingerprint fragment'],

            // Trailing whitespace, which VARCHAR `=` comparison ignores under
            // most collations and OCTET_LENGTH does not.
            [21, 'trailing ', 'one trailing space'],
            [22, 'trailing', 'no trailing space'],

            // Mixed NULL/non-NULL across the two columns, so a per-row
            // fingerprint cannot get away with treating NULL positionally.
            [23, null, 'b only'],
            [24, 'a only', null],
        ];
    }

    private function t(string $bare): string
    {
        return $GLOBALS['wpdb']->prefix . $bare;
    }

    /** Create an empty scratch table with the production column shape. */
    private function createTable(string $bare): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $t    = $this->t($bare);

        $wpdb->query("DROP TABLE IF EXISTS `{$t}`");
        $wpdb->query(
            "CREATE TABLE `{$t}` (
                id INT UNSIGNED NOT NULL,
                a VARCHAR(255) DEFAULT NULL,
                b VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (id)
            )"
        );
    }

    /**
     * Seed through `UNHEX()`, deliberately.
     *
     * ⚠ The fixture must NOT be built with the encoder under test, or a
     * symmetric encoding bug would round-trip perfectly and the suite would
     * pass while the artefact was wrong. `UNHEX()` of a `bin2hex()` string
     * involves no escaping at all, so the bytes that reach the column are
     * exactly the bytes named in `corpus()`.
     */
    private function seed(string $bare): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $t    = $this->t($bare);

        foreach ($this->corpus() as [$id, $a, $b]) {
            $va = $a === null ? 'NULL' : "UNHEX('" . bin2hex($a) . "')";
            $vb = $b === null ? 'NULL' : "UNHEX('" . bin2hex($b) . "')";
            $wpdb->query("INSERT INTO `{$t}` (id, a, b) VALUES ({$id}, {$va}, {$vb})");
        }
    }

    /**
     * Every row as hex, which is the only comparison that is immune to
     * collation and trailing-space semantics.
     *
     * @return list<string>
     */
    private function hexRows(string $bare): array
    {
        $wpdb = $GLOBALS['wpdb'];
        $t    = $this->t($bare);

        /** @var list<object> $rows */
        $rows = $wpdb->get_results(
            "SELECT id,
                    CASE WHEN a IS NULL THEN '<NULL>' ELSE HEX(a) END AS ha,
                    CASE WHEN b IS NULL THEN '<NULL>' ELSE HEX(b) END AS hb
               FROM `{$t}` ORDER BY id"
        );

        return array_map(
            static fn (object $r): string => $r->id . '|' . $r->ha . '|' . $r->hb,
            $rows ?: []
        );
    }

    /** @param list<string> $columns */
    private function fingerprints(string $bare, array $columns, bool $naive = false): array
    {
        $wpdb = $GLOBALS['wpdb'];
        $expr = $naive
            ? bcc_trust_backup_row_fingerprint_expr_naive($columns)
            : bcc_trust_backup_row_fingerprint_expr($columns);

        /** @var list<object> $rows */
        $rows = $wpdb->get_results(
            "SELECT id, {$expr} AS fp FROM `" . $this->t($bare) . '` ORDER BY id'
        );

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[(int) $row->id] = (string) $row->fp;
        }

        return $out;
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__, 2) . '/scripts/scanner-schema-backup.php';
        $GLOBALS['wpdb']->clearFaultInjection();
    }

    protected function tearDown(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->clearFaultInjection();
        foreach ([self::SRC, self::DST, self::DDL] as $bare) {
            $wpdb->query('DROP TABLE IF EXISTS `' . $this->t($bare) . '`');
        }
        parent::tearDown();
    }

    // ══ 1. The round trip ═══════════════════════════════════════════════

    public function testEveryAdversarialValueRoundTripsThroughTheExport(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $this->createTable(self::SRC);
        $this->createTable(self::DST);
        $this->seed(self::SRC);

        $before = $this->hexRows(self::SRC);
        self::assertCount(
            count($this->corpus()),
            $before,
            'anti-vacuity: every corpus row must actually have landed'
        );

        // Export, then load into the clone — the real artefact path.
        $rows = bcc_trust_backup_read_rows($this->t(self::SRC));
        self::assertIsArray($rows, 'the export read must succeed');
        self::assertCount(count($this->corpus()), $rows);

        $statements = bcc_trust_backup_insert_statements($this->t(self::DST), $rows);
        self::assertCount(count($this->corpus()), $statements);

        foreach ($statements as $sql) {
            self::assertNotFalse(
                $wpdb->query($sql),
                'a generated INSERT was rejected: ' . $wpdb->last_error
            );
        }

        self::assertSame(
            $before,
            $this->hexRows(self::DST),
            'every byte of every value must survive the export and reload'
        );

        self::assertSame(
            bcc_trust_backup_table_digest($this->t(self::SRC), ['id', 'a', 'b']),
            bcc_trust_backup_table_digest($this->t(self::DST), ['id', 'a', 'b']),
            'and the digests must agree'
        );
    }

    /**
     * ⚠ THE SENSITIVITY CONTROL. A round trip that passes proves nothing
     * unless the comparison would have FAILED on a corrupted restore, so
     * this mutates one byte of one value in one row of twenty-four and
     * requires both the hex comparison and the digest to notice.
     */
    public function testAOneByteCorruptionIsDetectedByTheDigest(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $this->createTable(self::SRC);
        $this->createTable(self::DST);
        $this->seed(self::SRC);
        $this->seed(self::DST);

        $good = bcc_trust_backup_table_digest($this->t(self::SRC), ['id', 'a', 'b']);
        self::assertIsString($good);
        self::assertSame($good, bcc_trust_backup_table_digest($this->t(self::DST), ['id', 'a', 'b']));

        // One character, in row 9's `a` ("it's" -> "it-s").
        $wpdb->query("UPDATE `" . $this->t(self::DST) . "` SET a = UNHEX('" . bin2hex("it-s") . "') WHERE id = 9");

        $corrupt = bcc_trust_backup_table_digest($this->t(self::DST), ['id', 'a', 'b']);
        self::assertIsString($corrupt);
        self::assertNotSame($good, $corrupt, 'a one-byte corruption must move the digest');
        self::assertNotSame($this->hexRows(self::SRC), $this->hexRows(self::DST));

        // …and recovery restores it, so the evidence covers
        // good -> corrupted -> recovered rather than a one-way load.
        $wpdb->query("UPDATE `" . $this->t(self::DST) . "` SET a = UNHEX('" . bin2hex("it's") . "') WHERE id = 9");
        self::assertSame($good, bcc_trust_backup_table_digest($this->t(self::DST), ['id', 'a', 'b']));
    }

    // ══ 2. The digest must tell values apart ════════════════════════════

    /**
     * NULL, `''`, `'NULL'` and `'~'` must all fingerprint differently.
     *
     * Rows 1-4 of the corpus are exactly these four, and any sentinel-string
     * rendering of NULL collapses at least one pair.
     */
    public function testTheDigestDistinguishesNullFromEveryStringThatImitatesIt(): void
    {
        $this->createTable(self::SRC);
        $this->seed(self::SRC);

        $fp = $this->fingerprints(self::SRC, ['a', 'b']);

        $subject = [1 => 'SQL NULL', 2 => "the empty string", 3 => "the string 'NULL'", 4 => "the string '~'"];
        foreach (array_keys($subject) as $id) {
            self::assertArrayHasKey($id, $fp, 'anti-vacuity: row ' . $id . ' must be fingerprinted');
        }

        foreach (array_keys($subject) as $i) {
            foreach (array_keys($subject) as $j) {
                if ($i >= $j) {
                    continue;
                }
                self::assertNotSame(
                    $fp[$i],
                    $fp[$j],
                    $subject[$i] . ' and ' . $subject[$j] . ' must not share a fingerprint'
                );
            }
        }
    }

    /**
     * ⚠⚠ THE PLANTED POSITIVE, AND THE REASON THE FORMULA IS WHAT IT IS.
     *
     * Rows 7 and 8 are `('a<0x1f>b', 'c')` and `('a', 'b<0x1f>c')`. Under
     * `CONCAT_WS(0x1f, a, b)` both render as `a<0x1f>b<0x1f>c` — one hash,
     * two different rows. The length prefix is what separates them.
     *
     * Asserting the naive form COLLIDES is what makes the next assertion
     * meaningful: without it, "the digests differ" could be true for any
     * reason at all and the length prefix could be removed with the suite
     * still green.
     */
    public function testTheDigestDistinguishesRowsTheNaiveFormulaCollides(): void
    {
        $this->createTable(self::SRC);
        $this->seed(self::SRC);

        $naive = $this->fingerprints(self::SRC, ['a', 'b'], true);
        self::assertArrayHasKey(7, $naive);
        self::assertArrayHasKey(8, $naive);
        self::assertSame(
            $naive[7],
            $naive[8],
            'the control must hold: the naive CONCAT_WS form really does collide here, '
            . 'and if this ever stops being true the next assertion proves nothing'
        );

        $safe = $this->fingerprints(self::SRC, ['a', 'b']);
        self::assertNotSame(
            $safe[7],
            $safe[8],
            'the length-prefixed fingerprint must tell the two rows apart'
        );
    }

    public function testTheDigestDistinguishesTrailingWhitespace(): void
    {
        $this->createTable(self::SRC);
        $this->seed(self::SRC);

        $fp = $this->fingerprints(self::SRC, ['a']);

        self::assertNotSame(
            $fp[21],
            $fp[22],
            "'trailing ' and 'trailing' differ by one byte that VARCHAR `=` ignores "
            . 'under most collations; OCTET_LENGTH does not, which is why it is in the formula'
        );
    }

    public function testTheDigestDistinguishesValuesThatImitateItsOwnFraming(): void
    {
        $this->createTable(self::SRC);
        $this->seed(self::SRC);

        $fp = $this->fingerprints(self::SRC, ['a']);

        // '0:' is what an empty string renders as; '3:abc' is what 'abc'
        // renders as. Neither may be confused with the real thing.
        self::assertNotSame($fp[2], $fp[19], "the empty string and the literal '0:' must differ");
        self::assertNotSame($fp[19], $fp[20], "'0:' and '3:abc' must differ");
        self::assertNotSame($fp[1], $fp[19], "NULL and '0:' must differ");
    }

    /**
     * An UNREADABLE table digests as null; an EMPTY table digests as a real
     * hash. Conflating them is how a verification step reports success for a
     * table it never read.
     */
    public function testAnUnreadableDigestIsNullAndAnEmptyTableIsNot(): void
    {
        $wpdb = $GLOBALS['wpdb'];

        $this->createTable(self::SRC);

        $empty = bcc_trust_backup_table_digest($this->t(self::SRC), ['id', 'a', 'b']);
        self::assertIsString($empty, 'an empty table has a digest');
        self::assertSame(64, strlen($empty));

        $this->seed(self::SRC);
        $full = bcc_trust_backup_table_digest($this->t(self::SRC), ['id', 'a', 'b']);
        self::assertIsString($full);
        self::assertNotSame($empty, $full, 'anti-vacuity: populated and empty must differ');

        // Now make the read fail.
        $wpdb->failQueriesMatching = '/SHA2\(/';
        self::assertNull(
            bcc_trust_backup_table_digest($this->t(self::SRC), ['id', 'a', 'b']),
            'an unreadable table must be UNVERIFIED (null), never the empty digest'
        );
        self::assertSame(1, $wpdb->injectedFailures, 'anti-vacuity: the fault must have fired');
        $wpdb->clearFaultInjection();

        self::assertSame($full, bcc_trust_backup_table_digest($this->t(self::SRC), ['id', 'a', 'b']));
    }

    // ══ 3. The literal encoder ══════════════════════════════════════════

    public function testNullIsEmittedUnquotedAndEverythingElseIsQuoted(): void
    {
        self::assertSame(
            'NULL',
            bcc_trust_backup_sql_literal(null),
            "NULL must be the VALUE, not the four-character string — a backup that "
            . 'quotes it restores the wrong thing without erroring'
        );
        self::assertSame("'NULL'", bcc_trust_backup_sql_literal('NULL'));
        self::assertSame("''", bcc_trust_backup_sql_literal(''));
        self::assertSame("'0'", bcc_trust_backup_sql_literal(0));
        self::assertSame("'0'", bcc_trust_backup_sql_literal(false));
        self::assertSame("'1'", bcc_trust_backup_sql_literal(true));

        // Escaping is the connection's, so assert the OBSERVABLE property —
        // that the literal reads back as the original bytes — rather than a
        // particular escape spelling.
        $wpdb = $GLOBALS['wpdb'];
        foreach (["it's", 'say "hi"', 'back\\slash', "\\'", "a\nb", "a\rb", "x\x1ay", "n\0l", "\x1f", '~'] as $raw) {
            $literal = bcc_trust_backup_sql_literal($raw);
            self::assertSame(
                strtoupper(bin2hex($raw)),
                (string) $wpdb->get_var('SELECT HEX(' . $literal . ')'),
                'the literal must read back byte-identical for ' . bin2hex($raw)
            );
        }
    }

    public function testTheInsertNamesItsColumnsSoAReorderedRestoreIsSafe(): void
    {
        $statements = bcc_trust_backup_insert_statements('wp_x', [
            ['id' => 1, 'a' => 'v', 'b' => null],
        ]);

        self::assertCount(1, $statements);
        self::assertSame(
            "INSERT INTO `wp_x` (`id`, `a`, `b`) VALUES ('1', 'v', NULL);",
            $statements[0],
            'columns must be named — production column ORDER differs from the '
            . "installer's, so a positional INSERT would shuffle values between columns"
        );
    }

    // ══ 4. Captured DDL, and the index that broke the first rehearsal ═══

    /**
     * ⚠⚠ THE GAP THE 2026-10-09 REHEARSAL FOUND.
     *
     * `KEY idx_cw_discovery (cw_discovery_state, cw_last_discovery_at)`
     * spans two retired columns. The backup captured the eight columns and
     * not the index, so the first restore died on `ERROR 1072 (42000): Key
     * column 'cw_discovery_state' doesn't exist in table` — and had the
     * order been the other way round it would have "succeeded" while
     * silently losing the index.
     *
     * This case reproduces the real parent shape, then proves three things:
     * the index is captured, the restore ORDER (columns, then indexes) works,
     * and the reverse order still fails — which is why the runbook states an
     * order rather than a set.
     */
    public function testTheIndexOverRetiredColumnsIsCapturedAndRestoresAfterItsColumns(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $t    = $this->t(self::DDL);

        $wpdb->query("DROP TABLE IF EXISTS `{$t}`");
        $wpdb->query(
            "CREATE TABLE `{$t}` (
                chain_id BIGINT UNSIGNED NOT NULL,
                state VARCHAR(20) NOT NULL DEFAULT 'idle',
                cw_discovery_state VARCHAR(20) NOT NULL DEFAULT 'idle',
                cw_last_discovery_at DATETIME DEFAULT NULL,
                cw_code_cursor VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (chain_id),
                KEY idx_state (state),
                KEY idx_cw_discovery (cw_discovery_state, cw_last_discovery_at)
            )"
        );

        $retired = ['cw_discovery_state', 'cw_last_discovery_at', 'cw_code_cursor'];

        $columnDefs = bcc_trust_backup_column_definitions($t, $retired);
        self::assertCount(3, $columnDefs, 'all three retired columns must be captured');

        $indexDefs = bcc_trust_backup_index_definitions($t, $retired);
        self::assertArrayHasKey(
            'idx_cw_discovery',
            $indexDefs,
            'the composite index over two retired columns MUST be captured — '
            . 'omitting it is what made the first restore rehearsal fail'
        );
        self::assertArrayNotHasKey(
            'idx_state',
            $indexDefs,
            'an index that names no retired column must NOT be captured, or a '
            . 'restore would try to re-add an index the table already has'
        );

        // Simulate the post-drop shape: retired columns and their index gone.
        $wpdb->query("ALTER TABLE `{$t}` DROP INDEX `idx_cw_discovery`");
        foreach ($retired as $column) {
            $wpdb->query("ALTER TABLE `{$t}` DROP COLUMN `{$column}`");
        }
        self::assertContains('idx_state', $this->indexNames(self::DDL));
        self::assertNotContains('idx_cw_discovery', $this->indexNames(self::DDL));

        // ⚠ THE REVERSE ORDER MUST FAIL — this is ERROR 1072, reproduced.
        $wpdb->suppress_errors(true);
        self::assertFalse(
            $wpdb->query($indexDefs['idx_cw_discovery']),
            'adding the index BEFORE its columns must fail; the runbook states an '
            . 'ORDER because of this, not a set'
        );
        $wpdb->suppress_errors(false);

        // The documented order: columns, then indexes.
        foreach ($columnDefs as $sql) {
            self::assertNotFalse($wpdb->query($sql), 'column restore failed: ' . $wpdb->last_error);
        }
        foreach ($indexDefs as $sql) {
            self::assertNotFalse($wpdb->query($sql), 'index restore failed: ' . $wpdb->last_error);
        }

        $after = $this->indexNames(self::DDL);
        self::assertContains('idx_cw_discovery', $after);
        self::assertContains('idx_state', $after);
        self::assertSame(
            ['cw_discovery_state', 'cw_last_discovery_at'],
            $this->indexColumns(self::DDL, 'idx_cw_discovery'),
            'and in the original column ORDER, which is what makes it the same index'
        );
    }

    /**
     * ⚠ RULE 1: capture, never reconstruct.
     *
     * Reconstructing from `INFORMATION_SCHEMA.COLUMNS` produced two defects
     * that are valid SQL and silently wrong: a string default comes back
     * ALREADY QUOTED in some versions, so naive quoting yields
     * `DEFAULT ''idle''`; and a nullable column's default comes back as the
     * literal string `"NULL"`, yielding `DEFAULT 'NULL'` — a column that now
     * defaults to four characters. Both are pinned here.
     */
    public function testCapturedDefinitionsAreVerbatimRatherThanReconstructed(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $t    = $this->t(self::DDL);

        $wpdb->query("DROP TABLE IF EXISTS `{$t}`");
        $wpdb->query(
            "CREATE TABLE `{$t}` (
                chain_id BIGINT UNSIGNED NOT NULL,
                cw_discovery_state VARCHAR(20) NOT NULL DEFAULT 'idle',
                cw_last_discovery_at DATETIME DEFAULT NULL,
                cw_max_code_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (chain_id)
            )"
        );

        $defs = bcc_trust_backup_column_definitions(
            $t,
            ['cw_discovery_state', 'cw_last_discovery_at', 'cw_max_code_id']
        );
        self::assertCount(3, $defs);

        self::assertStringContainsString(
            "DEFAULT 'idle'",
            $defs['cw_discovery_state'],
            'the string default must be quoted exactly once'
        );
        self::assertStringNotContainsString(
            "DEFAULT ''idle''",
            $defs['cw_discovery_state'],
            'double-quoting is the first reconstruction defect'
        );
        self::assertStringNotContainsString(
            "DEFAULT 'NULL'",
            $defs['cw_last_discovery_at'],
            "the nullable column must not default to the STRING 'NULL' — "
            . 'the second reconstruction defect'
        );
        self::assertStringContainsString('ADD COLUMN `cw_max_code_id`', $defs['cw_max_code_id']);

        // The real proof: replay them and compare what the engine itself
        // reports, which also covers the MySQL/MariaDB divergences that
        // misled the first rehearsal.
        $originalLines    = $this->columnLines((string) bcc_trust_backup_capture_ddl($t));
        $originalSemantic = $this->columnSemantics(self::DDL);
        self::assertNotSame([], $originalSemantic, 'anti-vacuity: the probe must read columns');

        foreach (['cw_discovery_state', 'cw_last_discovery_at', 'cw_max_code_id'] as $column) {
            $wpdb->query("ALTER TABLE `{$t}` DROP COLUMN `{$column}`");
        }
        foreach ($defs as $sql) {
            self::assertNotFalse($wpdb->query($sql), $wpdb->last_error);
        }

        // ⚠⚠ SEMANTICS FIRST, AND THIS IS THE ASSERTION THAT MATTERS. Type,
        // nullability, default, charset and collation, straight from
        // INFORMATION_SCHEMA. A restore is correct when the engine agrees the
        // columns are the same columns — not when two strings match.
        self::assertSame(
            $originalSemantic,
            $this->columnSemantics(self::DDL),
            'every restored column must be semantically identical: same type, '
            . 'nullability, default, charset and collation'
        );

        // ⚠ AND THE RENDERED TEXT, NORMALISED — because it is NOT
        // byte-identical on every engine, and finding that out during a
        // restore would be the worst possible moment.
        //
        // On MySQL 8, `SHOW CREATE TABLE` omits `CHARACTER SET` for a column
        // whose charset matches the table default and prints only `COLLATE`.
        // Replaying that captured line as `ADD COLUMN` makes the collation an
        // explicit column-level choice, so MySQL then renders it WITH the
        // redundant `CHARACTER SET utf8mb4` as well:
        //
        //   captured  `cw_discovery_state` varchar(20) COLLATE utf8mb4_unicode_ci …
        //   restored  `cw_discovery_state` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci …
        //
        // Same type, same charset, same collation, same default — the column
        // is identical and only its rendering changed. MariaDB 11.8, which is
        // the PRODUCTION engine, renders both forms identically, so a
        // production restore really is byte-for-byte. This normalisation
        // exists so the suite states that divergence instead of either
        // failing on MySQL or quietly dropping the text check.
        self::assertSame(
            array_map([$this, 'normaliseColumnLine'], $originalLines),
            array_map([$this, 'normaliseColumnLine'], $this->columnLines((string) bcc_trust_backup_capture_ddl($t))),
            'the rendered definitions must match once the redundant CHARACTER SET '
            . 'that MySQL adds to a replayed COLLATE is normalised away'
        );
    }

    public function testCapturingAMissingTableIsNullRatherThanAnEmptyString(): void
    {
        self::assertNull(bcc_trust_backup_capture_ddl($this->t('bcc_s9b_definitely_absent')));
        self::assertSame([], bcc_trust_backup_column_definitions($this->t('bcc_s9b_definitely_absent'), ['x']));
        self::assertSame([], bcc_trust_backup_index_definitions($this->t('bcc_s9b_definitely_absent'), ['x']));
        self::assertNull(bcc_trust_backup_read_rows($this->t('bcc_s9b_definitely_absent')));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return list<string> */
    private function indexNames(string $bare): array
    {
        $wpdb = $GLOBALS['wpdb'];

        /** @var list<object> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT DISTINCT INDEX_NAME AS i FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s ORDER BY INDEX_NAME',
            $this->t($bare)
        ));

        return array_map(static fn (object $r): string => (string) $r->i, $rows ?: []);
    }

    /** @return list<string> */
    private function indexColumns(string $bare, string $index): array
    {
        $wpdb = $GLOBALS['wpdb'];

        /** @var list<object> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME AS c FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              ORDER BY SEQ_IN_INDEX',
            $this->t($bare),
            $index
        ));

        return array_map(static fn (object $r): string => (string) $r->c, $rows ?: []);
    }

    /**
     * What the ENGINE says each column is: type, nullability, default,
     * charset and collation, from `INFORMATION_SCHEMA`.
     *
     * This is the comparison that decides whether a restore is correct. Two
     * identical strings are nice; two columns the server agrees are the same
     * column is the actual requirement, and it is stable across engines in a
     * way the rendered DDL is not.
     *
     * @return array<string, string>
     */
    private function columnSemantics(string $bare): array
    {
        $wpdb = $GLOBALS['wpdb'];

        /** @var list<object> $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT COLUMN_NAME AS c, COLUMN_TYPE AS t, IS_NULLABLE AS n,
                    COALESCE(COLUMN_DEFAULT, %s) AS d,
                    COALESCE(CHARACTER_SET_NAME, %s) AS cs,
                    COALESCE(COLLATION_NAME, %s) AS co
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
              ORDER BY COLUMN_NAME',
            '<none>',
            '<none>',
            '<none>',
            $this->t($bare)
        ));

        $out = [];
        foreach ($rows ?: [] as $r) {
            $out[(string) $r->c] = sprintf(
                'type=%s null=%s default=%s charset=%s collation=%s',
                $r->t,
                $r->n,
                $r->d,
                $r->cs,
                $r->co
            );
        }

        return $out;
    }

    /**
     * Normalise the one benign rendering divergence between MySQL and
     * MariaDB: a redundant `CHARACTER SET x` in front of `COLLATE x_...`.
     *
     * See the long note in
     * `testCapturedDefinitionsAreVerbatimRatherThanReconstructed()`. Only
     * this exact redundancy is collapsed — where the charset is literally the
     * collation's own prefix — so a genuine charset CHANGE still fails the
     * comparison rather than being normalised into agreement.
     */
    private function normaliseColumnLine(string $line): string
    {
        return (string) preg_replace_callback(
            '/CHARACTER SET (\w+) COLLATE (\w+)/',
            static fn (array $m): string => str_starts_with($m[2], $m[1] . '_')
                ? 'COLLATE ' . $m[2]
                : $m[0],
            $line
        );
    }

    /**
     * The column lines of a `SHOW CREATE TABLE`, which is the part a column
     * restore is responsible for. Index and table-option lines are excluded
     * because index order in the rendering is not a column property.
     *
     * @return list<string>
     */
    private function columnLines(string $ddl): array
    {
        $out = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $ddl)) as $line) {
            $line = rtrim(trim($line), ',');
            if (strncmp($line, '`', 1) === 0) {
                $out[] = $line;
            }
        }
        sort($out, SORT_STRING);

        return $out;
    }
}
