<?php
/**
 * Discovery run ledger — execution history for administrator-requested scans.
 *
 * ── WHY THIS IS NOT A SECOND CHECKPOINT TABLE ───────────────────────────
 * `schema-chain-checkpoints.php` states the rule plainly: a second per-chain
 * PROGRESS table "would be exactly the parallel implementation §11 forbids".
 * This table is not that. A checkpoint answers "where does the worker
 * resume?" and is keyed by chain. A run answers "what happened when an
 * administrator started a scan?" and is keyed by run.
 *
 * The line that keeps them distinct: this table stores COUNTS of work done
 * (pages, families, contracts, collections) and NEVER a cursor or resumable
 * position. The moment a column here could tell a worker where to restart,
 * it has become a parallel progress table.
 *
 * ── WHY IT MIRRORS wp_bcc_validator_msg_queue ───────────────────────────
 * That table already solves this exact problem in production: closed status
 * vocabulary, `lease_token` + `lease_expires_at`, `attempt_count` +
 * `next_retry_at`, a bounded `reason_code`, an atomic compare-and-swap
 * claim, and a reaper that returns an expired lease WITHOUT bumping the
 * attempt counter. §11 says reuse the pattern rather than invent one, and
 * the shapes here are deliberately its siblings.
 *
 * ── NO MARKET DATA, EVER ────────────────────────────────────────────────
 * BCC is never a price or trading platform. There is no floor price, no
 * volume, no listed count and no sale figure in this table, and none may be
 * added. The counts here describe WORK, not worth.
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('BCC_TRUST_DISCOVERY_RUNS_LOCK')) {
    define('BCC_TRUST_DISCOVERY_RUNS_LOCK', 'bcc_trust_mig_discovery_runs_v1');
}

/**
 * Table name helper.
 */
function bcc_onchain_discovery_runs_table(): string {
    return \BCC\Core\DB\DB::table('discovery_runs');
}

/**
 * Create the ledger.
 *
 * ── active_marker IS THE WHOLE CONCURRENCY STORY ────────────────────────
 * `uq_active (job_kind, chain_id, active_marker)` with `active_marker = 1`
 * while a run is queued or running and NULL once it is terminal. MySQL
 * treats NULLs as distinct in a unique index, so terminal history is
 * unlimited while AT MOST ONE active run per (job kind, chain) can exist.
 * A second concurrent request fails on a duplicate key at INSERT — before
 * any lock, any provider call, any work. The button being disabled in the
 * UI is a courtesy; this is the guarantee.
 *
 * `scan_mode` is deliberately NOT in that key. Historical and incremental
 * write the same `cw_*` checkpoint columns, so they must exclude each other.
 */
function bcc_onchain_create_discovery_runs_table(): void {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_discovery_runs_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        run_uuid CHAR(36) NOT NULL,
        job_kind VARCHAR(32) NOT NULL,
        scan_mode VARCHAR(16) NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'queued',
        active_marker TINYINT UNSIGNED DEFAULT 1,
        requested_by BIGINT UNSIGNED NOT NULL,
        requested_at DATETIME NOT NULL,
        started_at DATETIME DEFAULT NULL,
        finished_at DATETIME DEFAULT NULL,
        lease_token CHAR(36) DEFAULT NULL,
        lease_expires_at DATETIME DEFAULT NULL,
        heartbeat_at DATETIME DEFAULT NULL,
        attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        next_retry_at DATETIME DEFAULT NULL,
        retry_of_run_id BIGINT UNSIGNED DEFAULT NULL,
        stop_reason VARCHAR(40) DEFAULT NULL,
        error_code VARCHAR(40) DEFAULT NULL,
        partial TINYINT(1) NOT NULL DEFAULT 0,
        audit_degraded TINYINT(1) NOT NULL DEFAULT 0,
        requests_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        pages_fetched SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        families_seen INT UNSIGNED NOT NULL DEFAULT 0,
        contracts_seen INT UNSIGNED NOT NULL DEFAULT 0,
        collections_emitted INT UNSIGNED NOT NULL DEFAULT 0,
        collections_denied INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_run_uuid (run_uuid),
        UNIQUE KEY uq_active (job_kind, chain_id, active_marker),
        KEY idx_claimable (status, next_retry_at, id),
        KEY idx_lease_reap (status, lease_expires_at),
        KEY idx_chain_history (job_kind, chain_id, finished_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/**
 * Verify the ledger reached the shape the code expects, and REPORT it.
 *
 * ── WHY THIS RETURNS bool ───────────────────────────────────────────────
 * `bcc_trust_schema_version` is the only thing deciding whether the
 * installer runs again, and it is a content hash of these files. Stamping
 * it after a migration that did not finish means nothing will ever bump it
 * and the migration is never retried. PR 6 introduced
 * {@see bcc_trust_stamp_schema_version()} for exactly this; a new table
 * joins that contract rather than trusting dbDelta silently.
 *
 * ⚠ An unreadable probe is UNVERIFIED, never "absent".
 * `bcc_onchain_probe_count()` returns null on failure precisely so a broken
 * COUNT(*) cannot be read as a genuine zero — a zero here would mean "the
 * index is missing" and would send us to create something that already
 * exists, or report a schema complete that is not.
 *
 * @return bool true only when every required object was VERIFIED present
 */
function bcc_onchain_verify_discovery_runs_schema(): bool {
    $table = bcc_onchain_discovery_runs_table();

    if (!\BCC\Core\DB\AdvisoryLock::acquire(BCC_TRUST_DISCOVERY_RUNS_LOCK, 0)) {
        // Another request holds it. Not an error, but not complete from
        // this request's point of view either — and the caller uses this
        // answer to decide whether to stamp the schema version. Reporting
        // true here would let the loser of a race stamp a version the
        // winner had not finished reaching.
        return false;
    }

    try {
        $tableExists = bcc_onchain_probe_count(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
              LIMIT 1",
            [$table]
        );

        if ($tableExists === null) {
            \BCC\Core\Log\Logger::error(
                '[schema-discovery-runs] could not determine whether the table exists; treating as UNVERIFIED, not absent',
                ['table' => $table]
            );
            return false;
        }

        if ($tableExists === 0) {
            \BCC\Core\Log\Logger::error(
                '[schema-discovery-runs] table absent after dbDelta',
                ['table' => $table]
            );
            return false;
        }

        // Every column the repository writes. A dbDelta that silently
        // skipped one would otherwise surface as a runtime SQL error on the
        // first administrator request.
        $required = [
            'id', 'run_uuid', 'job_kind', 'scan_mode', 'chain_id', 'status',
            'active_marker', 'requested_by', 'requested_at', 'started_at',
            'finished_at', 'lease_token', 'lease_expires_at', 'heartbeat_at',
            'attempt_count', 'next_retry_at', 'retry_of_run_id', 'stop_reason',
            'error_code', 'partial', 'audit_degraded', 'requests_used',
            'pages_fetched', 'families_seen', 'contracts_seen',
            'collections_emitted', 'collections_denied', 'updated_at',
        ];

        foreach ($required as $column) {
            $present = bcc_onchain_probe_count(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s
                  LIMIT 1",
                [$table, $column]
            );

            if ($present === null) {
                \BCC\Core\Log\Logger::error(
                    '[schema-discovery-runs] could not verify a column; treating as UNVERIFIED, not absent',
                    ['table' => $table, 'column' => $column]
                );
                return false;
            }

            if ($present === 0) {
                \BCC\Core\Log\Logger::error(
                    '[schema-discovery-runs] required column missing',
                    ['table' => $table, 'column' => $column]
                );
                return false;
            }
        }

        // The active-run guarantee. Without this index two administrators
        // can start overlapping scans on one chain, so its absence is a
        // correctness failure, not a performance one.
        foreach (['uq_run_uuid', 'uq_active', 'idx_claimable', 'idx_lease_reap', 'idx_chain_history'] as $index) {
            $present = bcc_onchain_probe_count(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
                  LIMIT 1",
                [$table, $index]
            );

            if ($present === null) {
                \BCC\Core\Log\Logger::error(
                    '[schema-discovery-runs] could not verify an index; treating as UNVERIFIED, not absent',
                    ['table' => $table, 'index' => $index]
                );
                return false;
            }

            if ($present === 0) {
                \BCC\Core\Log\Logger::error(
                    '[schema-discovery-runs] required index missing',
                    ['table' => $table, 'index' => $index]
                );
                return false;
            }
        }

        return true;
    } finally {
        \BCC\Core\DB\AdvisoryLock::release(BCC_TRUST_DISCOVERY_RUNS_LOCK);
    }
}
