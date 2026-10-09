<?php
/**
 * S9b — drop the CosmWasm scanner's schema.
 *
 * ⚠⚠⚠ THIS IS THE ONE DESTRUCTIVE STEP IN THE SCANNER RETIREMENT. It drops
 * three tables and eight columns holding real rows. Everything before it
 * (S1–S8, S9a) was a pure code revert.
 *
 * ── A FRESH, VERIFIED BACKUP IS REQUIRED IMMEDIATELY BEFORE EXECUTION ───
 * `docs/scanner-schema-drop-runbook.md` is the procedure. The rehearsal
 * recorded there PROVES the restore path works; it is NOT a backup of the
 * data this migration will destroy, and it does not substitute for one. Take
 * a new artefact, verify its digests against live, then deploy.
 *
 * ── WHAT IT DROPS, EXPLICITLY AND NOTHING ELSE ──────────────────────────
 * Tables: wp_bcc_cosmwasm_code_families, wp_bcc_cosmwasm_contracts,
 *         wp_bcc_discovery_runs.
 * Columns on wp_bcc_chain_checkpoints: the seven `cw_*`.
 * Column on wp_bcc_chains: cosmwasm_nft_discovery_enabled.
 * Options: bcc_cosmos_endpoint_authz_*, bcc_trust_cw721_scan_*,
 *          bcc_trust_cw721_code_ids_* (all non-autoloaded).
 *
 * Every name is a literal in a constant below. There is no wildcard DROP, no
 * `DROP TABLE LIKE`, and no loop over a discovered table list. Unrelated
 * columns, indexes and rows on the two surviving parent tables are untouched.
 *
 * ── ⚠ THE INDEX IS DROPPED EXPLICITLY, AND FIRST ────────────────────────
 * `wp_bcc_chain_checkpoints` carries
 *
 *     KEY `idx_cw_discovery` (`cw_discovery_state`, `cw_last_discovery_at`)
 *
 * which spans TWO of the retired columns. This was found by rehearsing the
 * restore, not by reading the schema: rebuilding the post-drop shape failed
 * with `ERROR 1072 (42000): Key column 'cw_discovery_state' doesn't exist in
 * table`, because stripping the columns while keeping the index is invalid
 * DDL.
 *
 * Two consequences, both handled here:
 *
 *   1. The index is dropped BEFORE its columns, in its own statement. MariaDB
 *      would also remove it implicitly once the last of its columns went, but
 *      relying on that makes the outcome a side effect of column ordering.
 *      Explicit means the log says what happened and a partial run is
 *      diagnosable.
 *   2. The index set is DERIVED from information_schema rather than
 *      hardcoded, so an install carrying a differently-named index over the
 *      same columns is still handled — and an index that merely happens to
 *      exist on this table is NOT dropped, because the query only matches
 *      indexes that reference a retired column.
 *
 * ── STATUS CONTRACT (migration-runner callback) ─────────────────────────
 *   - any DB error, or any probe that cannot be read → INCOMPLETE
 *     (fail closed; the runner retries on the next request and does not
 *     stamp the done_option)
 *   - every postcondition verified absent → COMPLETE
 *
 * An unreadable INFORMATION_SCHEMA probe is treated as UNVERIFIED, never as
 * "already absent" — the same convention as
 * add-collections-metadata-state.php. Claiming absence from a failed read is
 * how a half-dropped schema gets stamped as done.
 *
 * ── IDEMPOTENT AT EVERY STEP, WHICH IS WHAT MAKES RECOVERY WORK ─────────
 * Each drop is gated on its own existence probe, so the migration can resume
 * from any partially-applied state: index gone but columns present, three
 * columns gone, two tables gone, and so on. A crash between statements is
 * therefore a resumable state rather than a repair job.
 *
 * ── DEPLOYMENT TRIGGER ──────────────────────────────────────────────────
 * Removing the three `schema-*.php` files in this same change moves
 * BCC_TRUST_SCHEMA_VERSION from `1a0bf150b1` to `db054e2c71`. dbDelta
 * therefore runs on the first request after deploy, and a files-only rsync is
 * enough to trigger it. This migration runs through migration-runner.php, not
 * dbDelta, so the two are independent — but both fire on that same first
 * request, which is why `bcc_onchain_add_chains_cosmwasm_discovery_column()`
 * had to be deleted in this change too. Left in place it would re-add
 * `cosmwasm_nft_discovery_enabled` on the schema pass immediately after this
 * migration dropped it.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Scanner retirement S9b (2026-10)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_scanner_schema_drop_targets')) {

    /**
     * The complete, literal drop set. Read by the migration AND by its tests,
     * so a target cannot be added in one place and missed in the other.
     *
     * @return array{tables: list<string>, checkpoint_columns: list<string>, chain_columns: list<string>, option_prefixes: list<string>}
     */
    function bcc_trust_scanner_schema_drop_targets(): array
    {
        return [
            'tables' => [
                'bcc_cosmwasm_code_families',
                'bcc_cosmwasm_contracts',
                'bcc_discovery_runs',
            ],
            'checkpoint_columns' => [
                'cw_discovery_state',
                'cw_code_cursor',
                'cw_max_code_id',
                'cw_backfill_completed_at',
                'cw_last_discovery_at',
                'cw_metadata_refreshed_at',
                'cw_last_error',
            ],
            'chain_columns' => [
                'cosmwasm_nft_discovery_enabled',
            ],
            // ⚠⚠ EXACTLY ONE PREFIX, AND THE OTHER TWO WERE REMOVED FOR TWO
            // DIFFERENT REASONS. Both were in the first draft of this file
            // and both were wrong.
            //
            // `bcc_trust_cw721_scan_` — ALREADY OWNED by a shipped
            // migration. `cleanup_cw721_scan_options_v1` in
            // includes/database/cleanup-cw721-scan-options.php sweeps it,
            // and does it properly. Duplicating it here would be a parallel
            // implementation of a migration that already exists — and worse
            // than redundant: that migration's own completion marker is
            // `bcc_trust_cw721_scan_options_cleaned`, which MATCHES the
            // prefix. This sweep would have deleted a sibling migration's
            // done_option and made it run again. That is also the whole
            // explanation for the "1 row on each environment" the S9b
            // inventory measured: the one row was the marker, not a leftover.
            //
            // `bcc_trust_cw721_code_ids_` — the prefix never matched
            // anything. Those are TRANSIENTS, so the rows are named
            // `_transient_bcc_trust_cw721_code_ids_<id>` and
            // `_transient_timeout_…`. A `LIKE 'bcc_trust_cw721_code_ids_%'`
            // matches neither. The measured "0 rows" read as "already
            // clean" and actually meant "this predicate is broken". The
            // migration above handles all three spellings.
            //
            // What is genuinely left to this migration is the authorization
            // option, whose writer was dropped in S2 and whose last reader
            // was deleted with `CosmosEndpointAuthorization` in S8. The
            // retirement plan assigned its cleanup to S9 precisely because
            // nothing else was ever going to sweep it.
            'option_prefixes' => [
                'bcc_cosmos_endpoint_authz_',
            ],
        ];
    }
}

if (!function_exists('bcc_trust_scanner_schema_columns_present')) {

    /**
     * Which of `$columns` exist on `$table` right now.
     *
     * @param  list<string> $columns
     * @return list<string>|null null means the probe could not be read —
     *         UNVERIFIED, which the caller must treat as a reason to stop,
     *         never as "absent".
     */
    function bcc_trust_scanner_schema_columns_present(string $table, array $columns): ?array
    {
        global $wpdb;

        if ($columns === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '%s'));
        $sql = $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                AND COLUMN_NAME IN ({$placeholders})",
            array_merge([$table], $columns)
        );

        $found = $wpdb->get_col($sql);
        if ($found === null || ($found === [] && $wpdb->last_error !== '')) {
            return null;
        }

        return array_values(array_map('strval', $found));
    }
}

if (!function_exists('bcc_trust_scanner_schema_indexes_over')) {

    /**
     * Index names on `$table` that reference ANY of `$columns`.
     *
     * ⚠ DERIVED, NOT HARDCODED — see the file docblock. An index is returned
     * only if it names a retired column, so unrelated indexes on the same
     * table are never in the result and therefore never dropped.
     *
     * @param  list<string> $columns
     * @return list<string>|null null = UNVERIFIED
     */
    function bcc_trust_scanner_schema_indexes_over(string $table, array $columns): ?array
    {
        global $wpdb;

        if ($columns === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($columns), '%s'));
        $sql = $wpdb->prepare(
            "SELECT DISTINCT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
                AND COLUMN_NAME IN ({$placeholders})
                AND INDEX_NAME <> 'PRIMARY'",
            array_merge([$table], $columns)
        );

        $found = $wpdb->get_col($sql);
        if ($found === null || ($found === [] && $wpdb->last_error !== '')) {
            return null;
        }

        return array_values(array_map('strval', $found));
    }
}

if (!function_exists('bcc_trust_drop_scanner_schema')) {

    function bcc_trust_drop_scanner_schema(): string
    {
        global $wpdb;

        $targets        = bcc_trust_scanner_schema_drop_targets();
        $checkpointTbl  = $wpdb->prefix . 'bcc_chain_checkpoints';
        $chainsTbl      = $wpdb->prefix . 'bcc_chains';
        $hasLogger      = class_exists('\\BCC\\Core\\Log\\Logger');

        // ── 1. READ-ONLY INVENTORY, LOGGED BEFORE ANY MUTATION ──────────
        //
        // Deliberately before the first DROP and deliberately logged: once
        // the rows are gone this is the only record of what was destroyed,
        // and a partial run is only diagnosable against it.
        $inventory = [];

        foreach ($targets['tables'] as $bare) {
            $table  = $wpdb->prefix . $bare;
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
            if (!$exists) {
                $inventory[] = ['table' => $table, 'predicate' => 'table absent', 'count' => 0];
                continue;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            if ($rows === null && $wpdb->last_error !== '') {
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
            $inventory[] = ['table' => $table, 'predicate' => 'all rows', 'count' => (int) $rows];
        }

        $ckPresent = bcc_trust_scanner_schema_columns_present($checkpointTbl, $targets['checkpoint_columns']);
        $chPresent = bcc_trust_scanner_schema_columns_present($chainsTbl, $targets['chain_columns']);
        $ckIndexes = bcc_trust_scanner_schema_indexes_over($checkpointTbl, $targets['checkpoint_columns']);
        if ($ckPresent === null || $chPresent === null || $ckIndexes === null) {
            // UNVERIFIED, not absent. Stop and let the runner retry.
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        $inventory[] = ['table' => $checkpointTbl, 'predicate' => 'retired cw_* columns present', 'count' => count($ckPresent)];
        $inventory[] = ['table' => $checkpointTbl, 'predicate' => 'indexes over retired columns', 'count' => count($ckIndexes)];
        $inventory[] = ['table' => $chainsTbl, 'predicate' => 'retired flag column present', 'count' => count($chPresent)];

        // Nothing left to do at all? Then this already ran.
        $nothingToDo = $ckPresent === [] && $chPresent === [] && $ckIndexes === [];
        foreach ($inventory as $row) {
            if ($row['predicate'] === 'all rows') {
                $nothingToDo = false;
                break;
            }
        }

        if ($hasLogger && !$nothingToDo) {
            \BCC\Core\Log\Logger::info(
                '[bcc-trust] scanner-schema drop inventory (read-only, before any mutation)',
                $inventory
            );
        }

        // ── 2. THE INDEX FIRST, EXPLICITLY ──────────────────────────────
        foreach ($ckIndexes as $index) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($wpdb->query("ALTER TABLE `{$checkpointTbl}` DROP INDEX `{$index}`") === false) {
                if ($hasLogger) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] scanner-schema drop: could not drop index', [
                        'table' => $checkpointTbl,
                        'index' => $index,
                    ]);
                }
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
        }

        // ── 3. THE SEVEN cw_* COLUMNS, ONE STATEMENT EACH ───────────────
        //
        // One ALTER per column rather than a combined statement: a combined
        // ALTER that fails tells you nothing about which column it stopped
        // on, and this migration's recoverability depends on knowing.
        foreach ($ckPresent as $column) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($wpdb->query("ALTER TABLE `{$checkpointTbl}` DROP COLUMN `{$column}`") === false) {
                if ($hasLogger) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] scanner-schema drop: could not drop column', [
                        'table'  => $checkpointTbl,
                        'column' => $column,
                    ]);
                }
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
        }

        // ── 4. THE CHAINS FLAG ──────────────────────────────────────────
        foreach ($chPresent as $column) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($wpdb->query("ALTER TABLE `{$chainsTbl}` DROP COLUMN `{$column}`") === false) {
                if ($hasLogger) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] scanner-schema drop: could not drop column', [
                        'table'  => $chainsTbl,
                        'column' => $column,
                    ]);
                }
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
        }

        // ── 5. THE THREE TABLES, EACH NAMED IN FULL ─────────────────────
        foreach ($targets['tables'] as $bare) {
            $table = $wpdb->prefix . $bare;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ($wpdb->query("DROP TABLE IF EXISTS `{$table}`") === false) {
                if ($hasLogger) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] scanner-schema drop: could not drop table', [
                        'table' => $table,
                    ]);
                }
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
        }

        // ── 6. LEFTOVER OPTIONS ─────────────────────────────────────────
        //
        // All non-autoloaded, so leaving them is harmless — but they are
        // write-only remnants of deleted code and this is the one change that
        // is already touching their subsystem.
        $optionsDeleted = 0;
        foreach ($targets['option_prefixes'] as $prefix) {
            $names = $wpdb->get_col($wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like($prefix) . '%'
            ));
            if ($names === null && $wpdb->last_error !== '') {
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
            foreach ($names ?: [] as $name) {
                if (delete_option((string) $name)) {
                    $optionsDeleted++;
                }
            }
        }

        // ── 7. VERIFY EVERY POSTCONDITION BEFORE CLAIMING COMPLETE ──────
        //
        // `$wpdb->query()` reporting success is not proof the schema moved:
        // it is proof the statement did not error. Re-probe.
        $ckAfter = bcc_trust_scanner_schema_columns_present($checkpointTbl, $targets['checkpoint_columns']);
        $chAfter = bcc_trust_scanner_schema_columns_present($chainsTbl, $targets['chain_columns']);
        $ixAfter = bcc_trust_scanner_schema_indexes_over($checkpointTbl, $targets['checkpoint_columns']);
        if ($ckAfter === null || $chAfter === null || $ixAfter === null) {
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }
        if ($ckAfter !== [] || $chAfter !== [] || $ixAfter !== []) {
            if ($hasLogger) {
                \BCC\Core\Log\Logger::error(
                    '[bcc-trust] scanner-schema drop: postcondition not met; leaving migration pending',
                    [
                        'checkpoint_columns_remaining' => $ckAfter,
                        'chain_columns_remaining'      => $chAfter,
                        'indexes_remaining'            => $ixAfter,
                    ]
                );
            }
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        foreach ($targets['tables'] as $bare) {
            $table = $wpdb->prefix . $bare;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
                if ($hasLogger) {
                    \BCC\Core\Log\Logger::error(
                        '[bcc-trust] scanner-schema drop: table still present after DROP; leaving migration pending',
                        ['table' => $table]
                    );
                }
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
        }

        // ── 8. THE SURVIVING PARENTS MUST STILL BE INTACT ───────────────
        //
        // The failure this catches is a mistake in THIS file, not drift: a
        // DROP aimed at the wrong column would otherwise be reported as a
        // clean completion.
        foreach ([
            $checkpointTbl => ['chain_id', 'last_processed_block', 'head_block', 'state',
                               'cu_used_today', 'cu_budget_reset_at', 'last_run_at',
                               'last_error', 'block_progression_history'],
            $chainsTbl     => ['id', 'slug', 'name', 'chain_type', 'is_active',
                               'bcc_supports_nft_collections', 'manual_collection_discovery_enabled'],
        ] as $table => $required) {
            $present = bcc_trust_scanner_schema_columns_present((string) $table, $required);
            if ($present === null || count($present) !== count($required)) {
                if ($hasLogger) {
                    \BCC\Core\Log\Logger::error(
                        '[bcc-trust] scanner-schema drop: a RETAINED column is missing after the drop',
                        [
                            'table'    => $table,
                            'expected' => $required,
                            'present'  => $present,
                        ]
                    );
                }
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
        }

        if ($hasLogger && !$nothingToDo) {
            \BCC\Core\Log\Logger::info('[bcc-trust] scanner-schema drop complete', [
                'tables_dropped'           => $targets['tables'],
                'checkpoint_columns'       => count($targets['checkpoint_columns']),
                'chain_columns'            => count($targets['chain_columns']),
                'indexes_dropped'          => $ckIndexes,
                'options_deleted'          => $optionsDeleted,
                'inventory_before'         => $inventory,
            ]);
        }

        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
