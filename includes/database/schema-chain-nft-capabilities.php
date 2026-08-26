<?php
/**
 * Per-chain NFT driver override schema.
 *
 * ONE ROW PER (chain_id, operation, driver_key). A row exists only to
 * DISABLE or REORDER a driver the code registry already offers for that
 * chain and operation.
 *
 * ── THIS TABLE CANNOT GRANT ANYTHING ────────────────────────────────────
 * The invariant is narrow-only: configuration may take capability away,
 * because taking away is always safe; it may never add capability, because
 * adding is a claim about CODE that configuration is in no position to make.
 * A row naming a driver, operation or chain this build does not implement is
 * inert — {@see \BCC\Trust\Onchain\Support\NftDriverRegistry::driversFor()}
 * intersects these rows against the code registry and discards the rest.
 *
 * Enforcement lives at that read rather than at write validation on purpose:
 * a row can arrive from a future admin screen, a manual INSERT, a botched
 * migration, or a restored backup taken from a build with a different driver
 * set. Only the read is guaranteed to run.
 *
 * ── `enabled` DEFAULTS TO 0, AND THAT IS NOT A PERMISSION ───────────────
 * Because an ABSENT row means "registry default applies", the default value
 * on a row that does exist has to be the restrictive one — otherwise an
 * accidentally-inserted blank row would silently re-enable something an
 * operator had turned off. `DEFAULT 0` means a half-written row disables
 * rather than enables.
 *
 * Note this is the opposite reading from the two `wp_bcc_chains` columns,
 * where `DEFAULT 0` withholds permission. Same direction — fail closed —
 * reached from opposite starting points, so it is spelled out here rather
 * than left to be inferred.
 *
 * ── WHY NOT A COLUMN ON wp_bcc_chains ───────────────────────────────────
 * The alternative — one scalar `nft_collection_driver` column per chain —
 * was proposed and rejected. It cannot express the shape the domain actually
 * has: a chain has SIX independent operations, several may have MORE THAN
 * ONE driver, and their order matters. A scalar would have to pick a
 * "primary" driver, and there is no operation for which "primary" is
 * meaningful — enumeration is an ordered list, metadata is a fallback chain,
 * validation is per-chain-family.
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 * @since PR 2 — per-chain NFT capability model
 */

if (!defined('ABSPATH')) {
    exit;
}

// Shared "does this exist, or could I not tell?" probe. Required explicitly
// rather than relying on load order, so this file is self-sufficient
// wherever it is pulled in from (bootstrap, test harness, migration runner).
require_once __DIR__ . '/schema-probe.php';

/**
 * Table name helper.
 */
function bcc_onchain_chain_nft_capabilities_table(): string {
    return \BCC\Core\DB\DB::table('chain_nft_capabilities');
}

/**
 * Create the per-chain NFT capability override table (idempotent).
 *
 * ── POSTCONDITIONS ARE VERIFIED, NOT ASSUMED ────────────────────────────
 * `dbDelta()` is fussy about index syntax and is known to skip an index
 * silently while reporting nothing wrong. A missing UNIQUE key here would
 * not fail loudly — it would allow duplicate (chain, operation, driver)
 * rows, and the override set would become order-dependent and
 * non-deterministic.
 *
 * So the table and its unique key are both re-probed through
 * INFORMATION_SCHEMA afterwards, and a missing index is added explicitly and
 * re-verified.
 *
 * ── FAIL CLOSED ON AN INSPECTION ERROR ──────────────────────────────────
 * A COUNT(*) probe that succeeds ALWAYS returns a number. `null` therefore
 * means the probe itself failed, which is NOT the same as "the thing is
 * absent" — and must never be treated as absence, because that would send us
 * on to blindly re-create objects we cannot see. Every probe below
 * distinguishes the two and reports rather than guesses.
 */
function bcc_onchain_create_chain_nft_capabilities_table(): void {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_chain_nft_capabilities_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        chain_id BIGINT UNSIGNED NOT NULL,
        operation VARCHAR(32) NOT NULL,
        driver_key VARCHAR(32) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 0,
        priority INT NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_chain_op_driver (chain_id, operation, driver_key),
        KEY idx_chain_op (chain_id, operation)
    ) {$charset_collate};";

    // ── Create only when genuinely absent ────────────────────────────────
    // Probing first rather than firing dbDelta on every schema-version bump
    // matches how this directory already handles evolution: a CREATE TABLE
    // for fresh installs, and an explicit idempotent ALTER per later column
    // (see bcc_onchain_add_chains_description_column() and its siblings). A
    // future column on THIS table gets its own ALTER the same way.
    //
    // An unreadable probe skips the create — fail closed. Re-creating a
    // table we merely could not see would be the destructive direction.
    $exists = bcc_onchain_probe_count(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
          LIMIT 1",
        [$table]
    );

    if ($exists === null) {
        \BCC\Core\Log\Logger::error(
            '[schema-chain-nft-capabilities] could not determine whether the table exists; skipping create',
            ['table' => $table]
        );
        return;
    }

    if ($exists === 0) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    // ── Postcondition 1: the table exists ────────────────────────────────
    $tableExists = bcc_onchain_probe_count(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
          LIMIT 1",
        [$table]
    );

    if ($tableExists === null) {
        \BCC\Core\Log\Logger::error(
            '[schema-chain-nft-capabilities] could not verify the table exists; treating as UNVERIFIED, not absent',
            ['table' => $table]
        );
        return;
    }
    if ($tableExists === 0) {
        \BCC\Core\Log\Logger::error(
            '[schema-chain-nft-capabilities] dbDelta did not create the table',
            ['table' => $table]
        );
        return;
    }

    // ── Postcondition 2: the UNIQUE key exists ───────────────────────────
    // This is the guarantee that makes the override set deterministic. If
    // dbDelta skipped it, add it explicitly — ALTER is unambiguous where
    // dbDelta's index parsing is not.
    $uniqueExists = bcc_onchain_probe_count(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
          LIMIT 1",
        [$table, 'uq_chain_op_driver']
    );

    if ($uniqueExists === null) {
        \BCC\Core\Log\Logger::error(
            '[schema-chain-nft-capabilities] could not verify uq_chain_op_driver; treating as UNVERIFIED, not absent',
            ['table' => $table]
        );
        return;
    }

    if ($uniqueExists === 0) {
        // `wpdb::query()` returns 0 (rows affected) on a SUCCESSFUL DDL
        // statement, so `=== false` is the only correct failure test here.
        // A truthiness check would read every successful ALTER as a failure.
        $added = $wpdb->query(
            "ALTER TABLE {$table} ADD UNIQUE KEY uq_chain_op_driver (chain_id, operation, driver_key)"
        );
        if ($added === false) {
            \BCC\Core\Log\Logger::error(
                '[schema-chain-nft-capabilities] failed to add uq_chain_op_driver',
                ['table' => $table, 'db_error' => $wpdb->last_error]
            );
            return;
        }

        $reVerified = bcc_onchain_probe_count(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s
              LIMIT 1",
            [$table, 'uq_chain_op_driver']
        );
        if ($reVerified === null || $reVerified === 0) {
            \BCC\Core\Log\Logger::error(
                '[schema-chain-nft-capabilities] uq_chain_op_driver still not present after ALTER',
                ['table' => $table]
            );
        }
    }
}
