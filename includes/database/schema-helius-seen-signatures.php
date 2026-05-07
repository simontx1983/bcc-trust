<?php
/**
 * Helius Seen-Signatures Schema
 *
 * Bounded LRU for replay protection on the Helius webhook endpoint.
 * Helius does NOT use HMAC + does NOT include verifiable timestamps in
 * payloads (per spike 2, May 2026); idempotency must be enforced
 * client-side via the transaction signature.
 *
 * Operational bounds (load-bearing — replay-protection tables silently
 * becoming infinite append-only junk drawers is a known footgun):
 *   - Capped at 10 000 rows
 *   - 1-hour age TTL
 *   - Sweep cron `bcc_helius_dedupe_sweep` runs every 5 minutes;
 *     deletes rows older than 1 h AND trims oldest-first to keep the
 *     row count <= 10 000
 *   - Post-sweep size persisted to `bcc_helius_dedupe_size` option;
 *     dashboard flags `helius_dedupe_overgrown: true` when > 12 000
 *     (implies the sweep cron itself stopped firing — same alarm
 *     pattern as the existing CronService overdue detector)
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 * @since V2 Phase 1b
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Table name helper.
 */
function bcc_onchain_helius_seen_signatures_table(): string {
    return \BCC\Core\DB\DB::table('helius_seen_signatures');
}

/**
 * Create the Helius seen-signatures table.
 */
function bcc_onchain_create_helius_seen_signatures_table(): void {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_onchain_helius_seen_signatures_table();

    // Solana transaction signatures are 87–88 base58 chars (64 bytes).
    // VARCHAR(96) with utf8mb4 indexable as PK without prefix-key tricks.
    $sql = "CREATE TABLE {$table} (
        signature VARCHAR(96) NOT NULL,
        seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (signature),
        KEY idx_seen_at (seen_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
