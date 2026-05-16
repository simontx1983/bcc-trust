<?php
/**
 * Page-Scoped Follow Table Schema
 *
 * BCC's own follow table — independent of PeepSo's user→user
 * follow graph. Exists specifically so a viewer can "Keep Tabs"
 * on a system-minted placeholder page (validator/project/creator)
 * BEFORE any human has claimed it. PeepSo's follow model is
 * user→user and resolves through `post_author`; that fails on
 * unclaimed placeholders because `post_author = 0` (no human owner
 * yet). This table fills the gap.
 *
 * Lifecycle: a row is created when a signed-in viewer pulls an
 * unclaimed page. When the operator claims the page,
 * `PeepSoIntegration::onPageClaimed` walks every row for that page,
 * materializes a real PeepSo follow (user→user, viewer→operator),
 * and deletes the row here. So `bcc_page_follows` is the pre-claim
 * holding pen; the canonical post-claim store is still
 * `peepso_user_friendships` + `bcc_pull_meta`.
 *
 * Why a separate table rather than a `placeholder_post_author=1`
 * trick on `wp_posts`: a shared system user as followee would mean
 * every follower of every unclaimed validator would also "follow"
 * every other unclaimed validator (PeepSo follows are followee-keyed,
 * not page-keyed). The system-user trick collapses the whole
 * pre-claim follow set into one followee — broken semantics.
 *
 * UNIQUE KEY (user_id, page_id) — a viewer can only follow a given
 * page once. `INSERT … ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)`
 * gives the repository an atomic insert-or-find without a SELECT
 * round-trip.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since V1.6 (validator directory backfill, 2026-05-13)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_page_follows_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_page_follows';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        page_id BIGINT UNSIGNED NOT NULL,
        card_kind VARCHAR(32) NOT NULL,
        tier_at_pull VARCHAR(20) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_user_page (user_id, page_id),
        KEY idx_user_id (user_id),
        KEY idx_page_id (page_id),
        KEY idx_card_kind (card_kind),
        KEY idx_created_at (created_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    if (class_exists('\\BCC\\Core\\Log\\Logger')) {
        \BCC\Core\Log\Logger::info('[bcc-trust] Page follows table installed', []);
    }
}
