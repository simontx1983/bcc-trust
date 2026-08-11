<?php
/**
 * CosmWasm Contract Inventory Schema
 *
 * ONE ROW PER (chain_id, contract_address). Every contract discovered by
 * enumerating a code family lands here — including the ones that turn
 * out NOT to be NFTs.
 *
 * ── DIVISION OF RESPONSIBILITY (locked) ─────────────────────────────────
 * See the full five-table map in schema-cosmwasm-code-families.php. In
 * short:
 *
 *   wp_bcc_chain_checkpoints          per-chain worker state + progress
 *   wp_bcc_cosmwasm_code_families     code-family inventory + classification
 *   wp_bcc_cosmwasm_contracts         ← THIS FILE: contract inventory,
 *                                       classification, retry state
 *   wp_bcc_onchain_collections        canonical collection model (UNCHANGED)
 *   wp_bcc_onchain_nft_spam_contracts operator allow/deny (REUSED)
 *
 * ── WHY A SEPARATE TABLE IS NECESSARY (verification restated) ──────────
 * A discovered contract is a CANDIDATE, not a collection. Only a small
 * minority ever become collections (~5% of code families are CW-721, and
 * within a CW-721 family individual instantiations still fail liveness).
 * Every reader of `wp_bcc_onchain_collections` treats each row as a real
 * collection — none of them filters on an "is-NFT" predicate — so
 * parking minters, factories, bindings contracts and inconclusive
 * probes there would pollute the member gallery, the admin Verify
 * queue, enrichment's backlog and every count at once. Keeping the
 * candidate ledger separate is what lets discovery be broad and the
 * collections table stay meaningful.
 *
 * It is ALSO what makes "previously inspected contracts are never
 * reprocessed" cheap: the durable row IS the memory. Without it the
 * only record of "we already looked at this and it is a minter" would
 * be its absence from the collections table — indistinguishable from
 * "never seen", which is exactly the loop this feature replaces.
 *
 * ── COLUMN NOTES ───────────────────────────────────────────────────────
 *   contract_address VARCHAR(128)
 *       Matches wp_bcc_onchain_nft_spam_contracts. Cosmos bech32
 *       contract addresses run to 66 chars ('cosmos1' + 59); the old
 *       VARCHAR(64) silently truncated them (caught live 2026-07-03).
 *       Stored lowercase — bech32 is lowercase by construction, and the
 *       deny-rule lookup lowercases both sides.
 *
 *   classification / classification_reason / probes_ok / probes_failed
 *   classifier_version / classified_at / last_attempted_at /
 *   next_attempt_at / retry_count / last_error
 *       Same BOUNDED, STRUCTURED evidence contract as the code-family
 *       table: short machine tokens plus a sanitized ≤255-char error
 *       excerpt. Raw LCD bodies are NEVER stored.
 *
 *   denied
 *       CACHE of "an operator deny rule covered this contract at the
 *       time we last looked". The SOURCE OF TRUTH stays
 *       wp_bcc_onchain_nft_spam_contracts — this column exists so an
 *       admin summary can count suppressed candidates with a bounded
 *       aggregate, and so the daily sweep can skip denied rows without
 *       a per-row rule lookup. Every write path re-consults the rule
 *       table before emitting; the column is never trusted as authority.
 *
 *   collection_row_written
 *       Set once a row for this contract has been emitted toward
 *       wp_bcc_onchain_collections, so a later sweep does not re-emit
 *       it (re-emitting would clobber operator-curated name/artwork via
 *       bulkUpsert's ON DUPLICATE KEY UPDATE).
 *
 *   code_checksum_at_discovery / migrated_at
 *       CosmWasm contracts can be MIGRATED to a new code id. The
 *       monthly pass re-reads the contract's current code id; a change
 *       is recorded here (and the row re-pointed) WITHOUT creating a
 *       second collection row for the same address.
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 * @since CosmWasm discovery (2026-08)
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table name helper.
 */
function bcc_onchain_cosmwasm_contracts_table(): string {
    return \BCC\Core\DB\DB::table('cosmwasm_contracts');
}

/**
 * Create the CosmWasm contract inventory table.
 */
function bcc_onchain_create_cosmwasm_contracts_table(): void {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_cosmwasm_contracts_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        chain_id BIGINT UNSIGNED NOT NULL,
        contract_address VARCHAR(128) NOT NULL,
        code_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        classification VARCHAR(24) NOT NULL DEFAULT 'inconclusive',
        classification_reason VARCHAR(64) DEFAULT NULL,
        probes_ok VARCHAR(128) DEFAULT NULL,
        probes_failed VARCHAR(128) DEFAULT NULL,
        classifier_version SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        classified_at DATETIME DEFAULT NULL,
        last_attempted_at DATETIME DEFAULT NULL,
        next_attempt_at DATETIME DEFAULT NULL,
        retry_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(255) DEFAULT NULL,
        denied TINYINT(1) NOT NULL DEFAULT 0,
        collection_row_written TINYINT(1) NOT NULL DEFAULT 0,
        migrated_at DATETIME DEFAULT NULL,
        discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_chain_contract (chain_id, contract_address),
        KEY idx_chain_code (chain_id, code_id),
        KEY idx_chain_classification (chain_id, classification),
        KEY idx_pending (chain_id, classification, denied, next_attempt_at),
        KEY idx_emit (chain_id, classification, denied, collection_row_written)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
