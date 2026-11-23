<?php
/**
 * Validator Relationship Log Table Schema — the hash-chained, append-only
 * history of validator relationships: owner links (staff-approved or
 * proven), revokes, cancels, supersessions and team changes.
 *
 * One global chain:
 *  - seq: assigned by the application (head.seq + 1 under the named lock
 *    `bcc_vrl_append`), UNIQUE, and part of the hash. `id` is a plain
 *    surrogate key outside the hash.
 *  - row_hash = HMAC-SHA256 over the canonical JSON of the row's hashed
 *    fields plus prev_hash, keyed by an HKDF of BCC_ENCRYPTION_KEY
 *    (key_id names the derivation, 1 today). prev_hash is the previous
 *    row's row_hash (a constant genesis value for seq 1).
 *  - evidence_note / evidence_ref are stored as text WITH their SHA-256
 *    digests in their own columns, and the chain hashes the digests. So a
 *    later logged redaction can clear the text and keep the digest without
 *    breaking the chain; the verifier checks any text still present against
 *    its digest.
 *  - created_at is UTC, computed in PHP (gmdate) — never MySQL NOW().
 *
 * Rows are NEVER updated or deleted. The only writer is
 * ValidatorRelationshipLogRepository::append(); a guard test fails on any
 * other write to this table.
 *
 * @package BCC_Trust
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_validator_relationship_log_table(): void {
    global $wpdb;
    $table   = $wpdb->prefix . 'bcc_validator_relationship_log';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        seq BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL,
        action VARCHAR(40) NOT NULL,
        page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        subject_user_id BIGINT UNSIGNED NULL,
        actor_kind VARCHAR(10) NOT NULL,
        actor_user_id BIGINT UNSIGNED NULL,
        payload TEXT NOT NULL,
        evidence_note TEXT NULL,
        evidence_ref VARCHAR(2048) NULL,
        evidence_note_sha256 CHAR(64) NULL,
        evidence_ref_sha256 CHAR(64) NULL,
        key_id TINYINT UNSIGNED NOT NULL DEFAULT 1,
        prev_hash CHAR(64) NOT NULL,
        row_hash CHAR(64) NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_seq (seq),
        KEY idx_page_seq (page_id, seq),
        KEY idx_subject_seq (subject_user_id, seq),
        KEY idx_actor_seq (actor_user_id, seq)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
