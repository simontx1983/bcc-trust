<?php
/**
 * On-Chain Claims Schema
 *
 * Tracks user claims to on-chain entities (validators, collections).
 * A claim links a verified wallet to a specific entity with a role
 * (operator, creator, holder) validated by on-chain RPC queries.
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_onchain_claims_table(): string {
    return \BCC\Core\DB\DB::table('onchain_claims');
}

function bcc_onchain_create_claims_table(): void {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_claims_table();

    // recovery_pending: per §B5, when a page-type claim's wallet is lost
    // and the page enters lost-wallet recovery, the claim row stays
    // verified but recovery_pending=1 so the admin queue can surface it.
    // For non-page entity_types (validator, collection), the column is
    // unused and stays at 0.
    //
    // Validator relationships (staff-approved owners, team roles):
    //  - verification_method: how the claim was proven. 'wallet' (a linked
    //    wallet matched on-chain; every row before this column), 'siws',
    //    'solana_identity' (proofs) or 'staff' (approved by staff).
    //  - verified_by: the staff user who approved a 'staff' claim.
    //  - A 'staff' claim has no wallet: wallet_address '' and chain_id 0
    //    (the columns stay NOT NULL; dbDelta cannot relax NOT NULL on an
    //    existing table). Wallet-keyed paths filter on the method, never on
    //    the address alone.
    //  - Team rows are entity_type 'page' with claim_role 'administrator'
    //    or 'representative' and status 'invited' or 'active' (never
    //    'verified'): invited_by, invite_expires_at, accepted_at,
    //    added_by_staff, reconfirmed (set only by a password re-confirmation)
    //    and show_publicly (the member's own choice, off by default).
    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        entity_type VARCHAR(20) NOT NULL,
        entity_id BIGINT UNSIGNED NOT NULL,
        wallet_address VARCHAR(128) NOT NULL,
        chain_id BIGINT UNSIGNED NOT NULL,
        claim_role VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        verified_at DATETIME NULL,
        recovery_pending TINYINT(1) NOT NULL DEFAULT 0,
        verification_method VARCHAR(32) NOT NULL DEFAULT 'wallet',
        verified_by BIGINT UNSIGNED NULL,
        invited_by BIGINT UNSIGNED NULL,
        invite_expires_at DATETIME NULL,
        accepted_at DATETIME NULL,
        added_by_staff TINYINT(1) NOT NULL DEFAULT 0,
        reconfirmed TINYINT(1) NOT NULL DEFAULT 0,
        show_publicly TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_user_entity (user_id, entity_type, entity_id),
        KEY idx_entity (entity_type, entity_id),
        KEY idx_entity_role (entity_type, entity_id, claim_role, status),
        KEY idx_user (user_id),
        KEY idx_status (status),
        KEY idx_recovery_pending (recovery_pending)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
