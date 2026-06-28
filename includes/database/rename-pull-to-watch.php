<?php
/**
 * One-time data-preserving rename: legacy "pull" physical names → "watch".
 *
 * Finishes the pull → watch vocabulary unification at the storage layer
 * (the API/code already moved). dbDelta is additive — it never RENAMEs —
 * so this guarded migration does the physical rename, preserving every row:
 *
 *   bcc_pull_meta              → bcc_watch_meta
 *     · pulled_at   (col)      → watched_at
 *     · tier_at_pull (col)     → tier_at_watch
 *     · idx_pulled_at (key)    → idx_watched_at
 *     · idx_tier_at_pull (key) → idx_tier_at_watch
 *   bcc_pull_batches           → bcc_watch_batches
 *   bcc_page_follows.tier_at_pull (col) → tier_at_watch
 *
 * Idempotent by construction — every step is guarded on the OLD name still
 * existing, so a re-run (or a fresh install that never had the legacy
 * tables) is a clean no-op. MUST run BEFORE dbDelta (it's invoked at the top
 * of bcc_trust_create_tables()) so dbDelta sees the already-renamed shape
 * and doesn't create empty new tables alongside the populated old ones.
 *
 * RENAME TABLE / CHANGE COLUMN are atomic and preserve data + row counts.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since pull→watch unification (2026-06-26)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_rename_pull_to_watch')) {

    /** True if a table (full prefixed name) exists. */
    function bcc_trust_pw_table_exists(string $table): bool
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    /** True if $column exists on $table (full prefixed name). */
    function bcc_trust_pw_column_exists(string $table, string $column): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_var($wpdb->prepare("SHOW COLUMNS FROM `{$table}` LIKE %s", $column));
        return $found === $column;
    }

    /** True if an index named $index exists on $table (full prefixed name). */
    function bcc_trust_pw_index_exists(string $table, string $index): bool
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM `{$table}` WHERE Key_name = %s", $index));
        return $found !== null;
    }

    /**
     * Move a column from $oldCol to $newCol, data-preserving, in either state:
     *   - old exists, new doesn't → plain CHANGE (rename keeps the data).
     *   - BOTH exist (a prior dbDelta additively created $newCol alongside the
     *     legacy $oldCol) → backfill $newCol from $oldCol where it's still
     *     unset, then DROP the orphan $oldCol. Backfill keys on `IS NULL`, so
     *     this is for nullable columns (the tier_at_* pair); the NOT NULL
     *     watched_at/pulled_at pair never hits the dual state because the
     *     migration runs before dbDelta in the gated path.
     * @return bool true if it changed anything.
     */
    function bcc_trust_pw_reconcile_column(string $table, string $oldCol, string $newCol, string $colDef): bool
    {
        global $wpdb;
        $hasOld = bcc_trust_pw_column_exists($table, $oldCol);
        $hasNew = bcc_trust_pw_column_exists($table, $newCol);
        if ($hasOld && !$hasNew) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$table}` CHANGE `{$oldCol}` `{$newCol}` {$colDef}");
            return true;
        }
        if ($hasOld && $hasNew) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("UPDATE `{$table}` SET `{$newCol}` = `{$oldCol}` WHERE `{$newCol}` IS NULL");
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$table}` DROP COLUMN `{$oldCol}`");
            return true;
        }
        return false;
    }

    function bcc_trust_rename_pull_to_watch(): void
    {
        global $wpdb;

        $watchMeta    = $wpdb->prefix . 'bcc_watch_meta';
        $pullMeta     = $wpdb->prefix . 'bcc_pull_meta';
        $watchBatches = $wpdb->prefix . 'bcc_watch_batches';
        $pullBatches  = $wpdb->prefix . 'bcc_pull_batches';
        $pageFollows  = $wpdb->prefix . 'bcc_page_follows';
        $renamed      = [];

        // 1. bcc_pull_meta → bcc_watch_meta (table), then its columns/indexes.
        if (bcc_trust_pw_table_exists($pullMeta) && !bcc_trust_pw_table_exists($watchMeta)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("RENAME TABLE `{$pullMeta}` TO `{$watchMeta}`");
            $renamed[] = 'bcc_pull_meta→bcc_watch_meta';
        }
        if (bcc_trust_pw_table_exists($watchMeta)) {
            $a = bcc_trust_pw_reconcile_column($watchMeta, 'pulled_at', 'watched_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP');
            $b = bcc_trust_pw_reconcile_column($watchMeta, 'tier_at_pull', 'tier_at_watch', 'VARCHAR(20) DEFAULT NULL');
            if ($a || $b) {
                $renamed[] = 'watch_meta.pulled_at→watched_at,tier_at_pull→tier_at_watch';
            }
        }
        // Swap the index names (CHANGE COLUMN keeps the old index name pointing
        // at the renamed column; drop the legacy-named ones so dbDelta recreates
        // idx_watched_at / idx_tier_at_watch instead of leaving duplicates).
        if (bcc_trust_pw_table_exists($watchMeta) && bcc_trust_pw_index_exists($watchMeta, 'idx_pulled_at')) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$watchMeta}` DROP INDEX `idx_pulled_at`");
        }
        if (bcc_trust_pw_table_exists($watchMeta) && bcc_trust_pw_index_exists($watchMeta, 'idx_tier_at_pull')) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("ALTER TABLE `{$watchMeta}` DROP INDEX `idx_tier_at_pull`");
        }

        // 2. bcc_pull_batches → bcc_watch_batches (no column renames needed).
        if (bcc_trust_pw_table_exists($pullBatches) && !bcc_trust_pw_table_exists($watchBatches)) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query("RENAME TABLE `{$pullBatches}` TO `{$watchBatches}`");
            $renamed[] = 'bcc_pull_batches→bcc_watch_batches';
        }

        // 3. bcc_page_follows.tier_at_pull → tier_at_watch (column only).
        if (bcc_trust_pw_table_exists($pageFollows)
            && bcc_trust_pw_reconcile_column($pageFollows, 'tier_at_pull', 'tier_at_watch', 'VARCHAR(20) DEFAULT NULL')) {
            $renamed[] = 'page_follows.tier_at_pull→tier_at_watch';
        }

        if ($renamed !== [] && class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] pull→watch physical rename applied',
                ['steps' => $renamed]
            );
        }
    }
}
