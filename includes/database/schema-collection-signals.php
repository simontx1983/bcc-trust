<?php
/**
 * Collection Signals Schema
 *
 * One row per (user, chain, contract) — the user's declared stance on a
 * discovered collection:
 *
 *   - `waitlist` — "give this collection a community and count me in."
 *     The demand signal behind the Verify Collections queue that airdrop
 *     spam CANNOT manufacture (passive holding can be airdropped;
 *     an explicit opt-in cannot). Waitlisted users get a bell
 *     notification when the community goes live.
 *   - `spam` — "this was airdropped scam junk." Tallying these powers
 *     the soft-hide threshold on user-facing surfaces and the red flag
 *     count on the admin queue.
 *
 * A user holds exactly ONE stance per collection (switchable, not
 * stackable) — the unique key enforces it. Joining a live community
 * supersedes any stance at the UI layer; the row is kept for the
 * demand tally history.
 *
 * `notified_at` stamps the go-live bell so provisioning re-runs can't
 * re-notify.
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_collection_signals_table(): string {
    return \BCC\Core\DB\DB::table('collection_signals');
}

function bcc_onchain_create_collection_signals_table(): void {

    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_collection_signals_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        contract_address VARCHAR(128) NOT NULL,
        stance VARCHAR(10) NOT NULL,
        notified_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_user_collection (user_id, chain_id, contract_address),
        KEY collection_stance (chain_id, contract_address, stance),
        KEY user_id (user_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
