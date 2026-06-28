<?php
/**
 * Watch Batches Table Schema (bcc_watch_batches)
 *
 * Lightweight summary table for §C3 watch-batch feed items. One row
 * per emitted batch — its primary purpose is to give the
 * peepso_activities row (with act_module_id='watch_batch') a typed
 * INT to point at via act_external_id.
 *
 * Why a separate table (not just bcc_watch_meta + GROUP BY batch_id):
 *   - peepso_activities.act_external_id is INT — batch_id (VARCHAR
 *     deterministic hash) doesn't fit there.
 *   - Frozen-history rule (§C3): once emitted, the feed item never
 *     updates. This row holds the AT-EMIT-TIME card_count and
 *     more_count snapshot, immune to subsequent unwatches.
 *   - UNIQUE KEY on batch_id makes the writer idempotent under
 *     duplicate event delivery.
 *
 * Renamed from the legacy `bcc_pull_batches` form (pull → watch
 * vocabulary unification); the rename runs in
 * includes/database/rename-pull-to-watch.php.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since V1 (2026-04, Watch Phase 4)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_watch_batches_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_watch_batches';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        batch_id VARCHAR(64) NOT NULL,
        card_count INT UNSIGNED NOT NULL,
        more_count INT UNSIGNED NOT NULL,
        emitted_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_batch_id (batch_id),
        KEY idx_user_emitted (user_id, emitted_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Watch batches table installed', []);
}
