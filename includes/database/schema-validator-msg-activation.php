<?php
/**
 * Validator Messaging — First-Activation Record Schema
 *
 * One row per validator page, created atomically the FIRST time the
 * page gains a verified operator claim ("first activation"). The
 * pre-claim message backlog belongs to this activation and to no
 * other: a later claim transfer NEVER replays it, and a previously
 * activated page that is currently unclaimed does not reopen the
 * first-claim queue (messaging reports temporarily-unavailable).
 *
 * backlog_status lifecycle: scheduled → running → completed | failed
 * (failed may return to running via the vmq sweep's recovery pass).
 * All timestamps UTC (BCC norm). last_error_code carries a CODE only
 * — never message bodies, never free text.
 *
 * @package BCC\Trust\Onchain
 * @subpackage Database
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_validator_msg_activation_table(): string {
    return \BCC\Core\DB\DB::table('validator_msg_activation');
}

function bcc_create_validator_msg_activation_table(): void {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $table = bcc_validator_msg_activation_table();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        page_id BIGINT UNSIGNED NOT NULL,
        entity_id BIGINT UNSIGNED NOT NULL,
        first_operator_user_id BIGINT UNSIGNED NOT NULL,
        first_activated_at DATETIME NOT NULL,
        backlog_status VARCHAR(16) NOT NULL DEFAULT 'scheduled',
        backlog_started_at DATETIME NULL,
        backlog_completed_at DATETIME NULL,
        last_error_code VARCHAR(40) NULL,
        sweep_attempts INT UNSIGNED NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_page (page_id),
        KEY idx_backlog_status (backlog_status)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
