<?php
/**
 * PR 7.3 — add `chunks_used` to the discovery-run ledger.
 *
 * ── WHY A MIGRATION AND NOT JUST dbDelta ────────────────────────────────
 * `bcc_onchain_create_discovery_runs_table()` carries the column now, so a
 * FRESH install gets it from the CREATE TABLE. Existing installs — staging
 * and production both — already have the table, and dbDelta's ADD COLUMN
 * behaviour on an existing table is not something to bet a release on. This
 * migration is the explicit, verifiable path for them.
 *
 * ── FAIL-CLOSED ─────────────────────────────────────────────────────────
 * The done_option is set ONLY once INFORMATION_SCHEMA confirms the column
 * exists. An inspection that could not run is NOT treated as absence: it
 * returns without marking the migration complete, so the next boot retries.
 *
 * ⚠ `wpdb::query()` RETURNS 0 ON A SUCCESSFUL DDL. `=== false` is the only
 * correct failure test; truthiness would read every success as a failure.
 *
 * ⚠ EXISTING ROWS ARE PRESERVED. `NOT NULL DEFAULT 0` backfills every historic
 * run with zero chunks, which is exactly true of them: they were single-pass
 * runs. The two Cosmos Hub staging canary rows keep every other value.
 *
 * @package BCC\Trust\Onchain
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_add_discovery_run_chunks_used')) {
    /**
     * Add `bcc_discovery_runs.chunks_used` if it is not already there.
     *
     * @return string 'complete' once the column is proven present, '' otherwise.
     */
    function bcc_trust_add_discovery_run_chunks_used(): string
    {
        global $wpdb;

        if (!function_exists('bcc_onchain_discovery_runs_table')
            || !function_exists('bcc_onchain_probe_count')
        ) {
            \BCC\Core\Log\Logger::error(
                '[schema-discovery-runs-chunks] schema helpers unavailable; not marking complete'
            );
            return '';
        }

        $table = bcc_onchain_discovery_runs_table();

        // The table itself must exist first. On an install that has never
        // booted the discovery schema, the CREATE TABLE already carries the
        // column and there is nothing to migrate — but "absent table" and
        // "unreadable schema" are different answers, so both are handled.
        $tableExists = bcc_onchain_probe_count(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s LIMIT 1",
            [$table]
        );

        if ($tableExists === null) {
            \BCC\Core\Log\Logger::error(
                '[schema-discovery-runs-chunks] could not inspect the table; treating as UNVERIFIED, not absent',
                ['table' => $table]
            );
            return '';
        }

        if ($tableExists === 0) {
            // Nothing to alter. The creator owns this install.
            return 'complete';
        }

        $columnExists = bcc_onchain_probe_count(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
              LIMIT 1",
            [$table, 'chunks_used']
        );

        if ($columnExists === null) {
            \BCC\Core\Log\Logger::error(
                '[schema-discovery-runs-chunks] could not inspect columns; treating as UNVERIFIED, not absent',
                ['table' => $table]
            );
            return '';
        }

        if ($columnExists === 0) {
            // Placed after `attempt_count` so the two counters read together
            // in a describe — they are neighbours that must never be
            // confused for one another.
            $added = $wpdb->query(
                "ALTER TABLE {$table}
                   ADD COLUMN chunks_used SMALLINT UNSIGNED NOT NULL DEFAULT 0
                       AFTER attempt_count"
            );

            if ($added === false) {
                \BCC\Core\Log\Logger::error(
                    '[schema-discovery-runs-chunks] failed to add chunks_used',
                    ['table' => $table, 'db_error' => $wpdb->last_error]
                );
                return '';
            }
        }

        // ⚠ RE-VERIFY. The ALTER not returning false is not proof the column
        // is there; only INFORMATION_SCHEMA is.
        $reVerified = bcc_onchain_probe_count(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
              LIMIT 1",
            [$table, 'chunks_used']
        );

        if ($reVerified === null || $reVerified === 0) {
            \BCC\Core\Log\Logger::error(
                '[schema-discovery-runs-chunks] chunks_used not confirmed after ALTER; leaving migration pending',
                ['table' => $table, 'probe' => var_export($reVerified, true)]
            );
            return '';
        }

        return 'complete';
    }
}
