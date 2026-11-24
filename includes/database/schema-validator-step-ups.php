<?php
/**
 * Validator Step-Ups Table Schema — short-lived, single-use re-confirmations
 * a staff member completes before a validator relationship change (link,
 * revoke or cancel an owner; add or remove a team member).
 *
 * One row per step-up:
 *  - id: an opaque 128-bit random token (hex), the client's step_up_id.
 *  - the BINDING: user_id (who confirmed), action, page_id, target_user_id
 *    and role. A step-up is consumed only by the exact action it was
 *    started for (the consume matches every binding column).
 *  - credential_verified: the password was checked when the step-up began.
 *  - code_hash: HMAC-SHA256 (an HKDF-derived key) of the emailed one-time
 *    code, bound to the id; NULL for password-only actions. The code itself
 *    is never stored, logged or returned.
 *  - attempts: counted atomically BEFORE a code is compared; at the cap the
 *    step-up is dead.
 *  - code_verified_at: set (by a conditional UPDATE) only when the right
 *    code was entered; a code-bearing step-up cannot be consumed without it.
 *    A resend clears it.
 *  - expires_at / resend_after / used_at: UTC, computed in PHP. A resend
 *    never extends a step-up past created_at + 30 minutes.
 *
 * Rows expire after minutes and a bounded cron purge deletes them.
 *
 * @package BCC_Trust
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_validator_step_ups_table(): void {
    global $wpdb;
    $table   = $wpdb->prefix . 'bcc_validator_step_ups';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id CHAR(32) NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(32) NOT NULL,
        page_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        target_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        role VARCHAR(20) NOT NULL DEFAULT '',
        code_hash CHAR(64) NULL,
        credential_verified TINYINT(1) NOT NULL DEFAULT 0,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        expires_at DATETIME NOT NULL,
        resend_after DATETIME NULL,
        used_at DATETIME NULL,
        code_verified_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_user_created (user_id, created_at),
        KEY idx_expires (expires_at)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
