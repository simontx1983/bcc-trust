<?php
/**
 * Stoke Table Schema
 *
 * One row per (act_id, user_id) — `uq_act_user` makes the row's mere
 * existence the stoke: one per person, X-"like" semantics. `stoke_count`
 * is a vestigial always-1 column (StokeRepository inserts it once via
 * INSERT IGNORE and never increments it — see that class's docblock);
 * kept rather than dropped via an ALTER TABLE since presence/COUNT(*)
 * is now the actual signal, not the column's value.
 *
 * Still its own table rather than reusing PeepSo's peepso_reactions:
 * that table's unique (reaction_act_id, reaction_user_id) key models
 * "one reaction, set/replace" and is read-only from bcc-trust's side
 * (PeepSo owns the write path) — Stoke needs its own write path that
 * bcc-trust owns outright, isolated from the existing reaction grammar.
 * Cosmetic for trust — never written to bcc_trust_scores.
 *
 * @package BCC\Trust\Core
 */
if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_stokes_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_stokes';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        act_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        stoke_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_stoked_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_act_user (act_id, user_id),
        KEY idx_act_id (act_id),
        KEY idx_last_stoked_at (last_stoked_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Stokes table installed', []);
}
