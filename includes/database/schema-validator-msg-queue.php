<?php
/**
 * Validator Messaging — Pre-Claim Message Queue Schema
 *
 * Messages addressed to a validator page that has never had a verified
 * operator. Rows are durable: an accepted message is NEVER silently
 * deleted and never expires. It leaves the queue only by being
 * delivered (first-claim backlog release), suppressed by an explicit
 * safety rule (with a reason_code), or parked as failed_terminal for
 * manual recovery — all visible states.
 *
 * State machine (see ValidatorMsgQueueRepository for the transition
 * guards): queued | processing | delivered | retryable | suppressed |
 * failed_terminal. Terminal: delivered, suppressed, failed_terminal.
 *
 * Crash safety: a worker leases a row (atomic UPDATE setting
 * lease_token + lease_expires_at); every subsequent write on that row
 * carries the token. A worker that dies mid-row leaves an EXPIRED
 * lease, which the sweep returns to retryable — rows never stick in
 * processing.
 *
 * idempotency_key is a UUIDv4 minted at accept time and never
 * rewritten; it is mirrored onto the delivered PeepSo message as
 * post_meta `_bcc_vmq_idem`, which is the forensic seam that proves
 * at-most-once delivery across crash boundaries.
 *
 * PRIVACY: `body` lives here and (post-delivery) in the PeepSo message
 * post — never in logs, metrics, audit meta, or error text.
 *
 * All timestamps UTC (BCC norm).
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_validator_msg_queue_table(): string {
    return \BCC\Core\DB\DB::table('validator_msg_queue');
}

function bcc_create_validator_msg_queue_table(): void {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_validator_msg_queue_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        page_id BIGINT UNSIGNED NOT NULL,
        sender_user_id BIGINT UNSIGNED NOT NULL,
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'queued',
        lease_token CHAR(36) NULL,
        lease_expires_at DATETIME NULL,
        attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
        next_retry_at DATETIME NULL,
        resolved_operator_user_id BIGINT UNSIGNED NULL,
        delivered_conversation_id BIGINT UNSIGNED NULL,
        delivered_message_id BIGINT UNSIGNED NULL,
        delivered_at DATETIME NULL,
        suppressed_at DATETIME NULL,
        reason_code VARCHAR(40) NULL,
        idempotency_key CHAR(36) NOT NULL,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_idem (idempotency_key),
        KEY idx_page_status (page_id, status),
        KEY idx_status_retry (status, next_retry_at),
        KEY idx_sender_created (sender_user_id, created_at),
        KEY idx_lease (status, lease_expires_at)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
