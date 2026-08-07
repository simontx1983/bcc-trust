<?php
/**
 * CosmWasm Code-Family Inventory Schema
 *
 * ONE ROW PER (chain_id, code_id). A "code family" is a stored wasm
 * binary on a CosmWasm chain; every contract instantiated from it shares
 * that binary byte-for-byte (hence `checksum`).
 *
 * ── DIVISION OF RESPONSIBILITY (locked) ─────────────────────────────────
 * This table is ONE of five that together carry CW-721 discovery. Each
 * owns a distinct concern; none duplicates another:
 *
 *   wp_bcc_chain_checkpoints
 *       Overall PER-CHAIN worker state and progress (where the backfill
 *       is, whether the chain supports wasmd at all, when the last
 *       discovery/metadata pass ran). One row per chain.
 *
 *   wp_bcc_cosmwasm_code_families   ← THIS FILE
 *       Durable code-family INVENTORY, CLASSIFICATION and
 *       contract-enumeration state. One row per (chain, code id).
 *
 *   wp_bcc_cosmwasm_contracts
 *       Durable contract INVENTORY, classification and retry state.
 *       One row per (chain, contract address).
 *
 *   wp_bcc_onchain_collections
 *       The canonical discovered/curated COLLECTION model. UNCHANGED
 *       role — discovery writes unverified rows into it, nothing else.
 *
 *   wp_bcc_onchain_nft_spam_contracts
 *       Durable operator ALLOW/DENY decisions. REUSED as-is; discovery
 *       consults it and never builds a second rejection system.
 *
 * ── WHY A SEPARATE TABLE IS NECESSARY (verification restated) ──────────
 * The obvious alternative — parking every discovered contract on
 * `wp_bcc_onchain_collections` with an "is it an NFT?" column — was
 * checked against the actual read paths and rejected:
 *
 *   - Discovery inspects ~5k code families and tens of thousands of
 *     contracts per chain. ~50% of families have ZERO contracts and only
 *     ~5% are CW-721 (measured), so the overwhelming majority of what
 *     discovery learns is NEGATIVE knowledge ("this is a minter", "this
 *     is a factory", "this family has no contracts"). None of it is a
 *     collection.
 *   - NOT ONE reader of `wp_bcc_onchain_collections` filters on an
 *     "is-NFT" predicate: `listVerifiedByChain`, `listKnownByChain`,
 *     `getTopCollectionsByChainType`, `listForAdminVerification`,
 *     `getForProject`, `countByVerification`, `findPendingEnrichment`
 *     all assume every row IS a collection. Landing non-NFT and
 *     inconclusive rows there would silently pollute the gallery, the
 *     admin Verify queue, the enrichment worker's backlog and every
 *     count — each read path would have to grow a new predicate, and any
 *     path that forgot one becomes a leak.
 *   - Classification state (probe outcomes, classifier version, retry
 *     count/backoff, sanitized last error) is worker bookkeeping with a
 *     completely different write cadence from collection metadata. Mixed
 *     into the collections row it would fight the enrichment writer for
 *     the same rows.
 *
 * So: non-collections never enter the collections table, and the
 * collections table keeps its single meaning.
 *
 * ── COLUMN NOTES ───────────────────────────────────────────────────────
 *   checksum
 *       Hex sha256 of the stored wasm binary, taken from the code
 *       LISTING endpoint (`/cosmwasm/wasm/v1/code`, field `data_hash`).
 *       We NEVER fetch `/cosmwasm/wasm/v1/code/{id}` — that endpoint
 *       returns the whole binary base64-encoded in its `data` field.
 *       12–20% of families share a checksum with another (measured), so
 *       an already-classified twin on the same chain can settle a family
 *       with zero probes. Checksum reuse accelerates FAMILY
 *       classification ONLY: it never verifies a collection, never
 *       bypasses a per-contract liveness probe, never bypasses a
 *       spam/deny rule, and never implies that two instances share
 *       collection metadata.
 *
 *   classification
 *       confirmed_cw721 | probable_cw721 | not_cw721 | inconclusive |
 *       temporarily_unreachable. See CosmwasmClassifier for the state
 *       machine. `not_cw721` is TERMINAL — never routinely revisited.
 *
 *   classification_reason / probes_ok / probes_failed / last_error
 *       BOUNDED, STRUCTURED evidence. Short machine tokens and a
 *       sanitized ≤255-char excerpt (the same cap
 *       ChainCheckpointRepository::recordFailure uses). Raw LCD bodies
 *       are NEVER stored.
 *
 *   classifier_version
 *       Bumped when the probe set or the decision rules change. Only
 *       explicitly-affected classifications are requeued; settled
 *       `not_cw721` families are not.
 *
 *   next_attempt_at
 *       Precomputed backoff target. Retry selection is a single bounded
 *       indexed range scan instead of a per-row backoff recomputation.
 *
 *   contracts_cursor / contracts_enumerated / enumeration_complete
 *       Resumable per-family contract enumeration
 *       (`/cosmwasm/wasm/v1/code/{id}/contracts`). A family that settles
 *       `not_cw721` sets enumeration_complete = 1 WITHOUT walking the
 *       rest of its contracts.
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
function bcc_onchain_cosmwasm_code_families_table(): string {
    return \BCC\Core\DB\DB::table('cosmwasm_code_families');
}

/**
 * Create the CosmWasm code-family inventory table.
 */
function bcc_onchain_create_cosmwasm_code_families_table(): void {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_cosmwasm_code_families_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        chain_id BIGINT UNSIGNED NOT NULL,
        code_id BIGINT UNSIGNED NOT NULL,
        checksum VARCHAR(64) DEFAULT NULL,
        classification VARCHAR(24) NOT NULL DEFAULT 'inconclusive',
        classification_reason VARCHAR(64) DEFAULT NULL,
        probes_ok VARCHAR(128) DEFAULT NULL,
        probes_failed VARCHAR(128) DEFAULT NULL,
        classifier_version SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        sample_contract VARCHAR(128) DEFAULT NULL,
        classified_at DATETIME DEFAULT NULL,
        last_attempted_at DATETIME DEFAULT NULL,
        next_attempt_at DATETIME DEFAULT NULL,
        retry_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        last_error VARCHAR(255) DEFAULT NULL,
        contracts_cursor VARCHAR(255) DEFAULT NULL,
        contracts_enumerated INT UNSIGNED NOT NULL DEFAULT 0,
        enumeration_complete TINYINT(1) NOT NULL DEFAULT 0,
        last_enumerated_at DATETIME DEFAULT NULL,
        metadata_checked_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_chain_code (chain_id, code_id),
        KEY idx_chain_classification (chain_id, classification),
        KEY idx_chain_checksum (chain_id, checksum),
        KEY idx_retry (chain_id, classification, next_attempt_at),
        KEY idx_enumeration (chain_id, classification, enumeration_complete, last_enumerated_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
