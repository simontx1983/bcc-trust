<?php
/**
 * Photo Alt-Text Table Schema
 *
 * Sidecar metadata for PeepSo photos that captures the author-supplied
 * alt text emitted by §3.3.9 of the V1 contract. PeepSo's `peepso_photos`
 * table has no native alt column; storing it here keeps PeepSo updates
 * from clobbering BCC data.
 *
 * Rows are 1:1 with `peepso_photos.pho_id`. PK is `pho_id` (not an
 * autoincrement) so an upsert-by-id is a single statement and a
 * cascading delete on the parent photo removes exactly one row.
 *
 * `owner_id` is denormalised from peepso_photos so the write-path
 * authorisation check (current_user_id === owner) is a single PK read
 * on this table — no second join into peepso_photos at write time.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since v1.5 a11y (2026-05)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_photo_alts_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_photo_alts';
    $charset = $wpdb->get_charset_collate();

    // alt_text VARCHAR(500): A11y best practice is 125–150 chars; 500
    // caps abuse without truncating descriptions of complex images.
    // updated_at is server-set on every upsert so moderation queues
    // can sort by recency.
    $sql = "CREATE TABLE {$table} (
        pho_id BIGINT UNSIGNED NOT NULL,
        owner_id BIGINT UNSIGNED NOT NULL,
        alt_text VARCHAR(500) NOT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (pho_id),
        KEY idx_owner_id (owner_id),
        KEY idx_updated_at (updated_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Photo alts table installed', []);
}
