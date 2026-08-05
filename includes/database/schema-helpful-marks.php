<?php
/**
 * Helpful-Marks Table Schema — the §9.2 deliberate "Mark helpful"
 * endorsement store (Rank redesign, helping emitters).
 *
 * One row per (act_id, user_id), enforced by a unique key, so the row's
 * mere existence IS the mark (X-"like" model: one mark per person, no
 * counter). Deliberately SEPARATE from `bcc_trust_stokes` and from
 * PeepSo's cosmetic reaction store: a Helpful mark is the sanctioned,
 * credibility-gated Rank "helping" evidence route (§9.2), NOT a cosmetic
 * reaction. Keeping it in its own table is what preserves the §8.5 line
 * — cosmetic Stoke / like / solid carry no Rank weight; this mark does
 * (when the marker is a credible member).
 *
 * The row `id` is the STABLE source id the Rank evidence ledger keys on
 * (`helpful_mark:{id}:{author}`), so un-marking can reverse exactly the
 * evidence a mark minted. Rows are hard-deleted on un-mark (the ledger
 * keeps the reversed audit trail — never deleted there).
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign (helping emitters)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_helpful_marks_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_trust_helpful_marks';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        act_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_act_user (act_id, user_id),
        KEY idx_act_id (act_id)
    ) ENGINE=InnoDB {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Helpful marks table installed', []);
}
