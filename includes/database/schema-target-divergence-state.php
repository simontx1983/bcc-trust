<?php
/**
 * Target Divergence State — sidecar storage for §J.8 classifier output.
 *
 * Stores the most recently computed `divergence_state` per target (entity
 * card or user_profile) plus the prior-state memory the
 * `PolarizationTransitionNotifier` needs to detect "newly transitioned"
 * targets without re-walking history.
 *
 * **Why a dedicated table (not wp_postmeta / wp_usermeta):**
 *   - `wp_postmeta` is keyed on `post_id` only — doesn't cover the
 *     `user_profile` target_kind.
 *   - `wp_usermeta` is keyed on `user_id` only — doesn't cover entity
 *     cards.
 *   - A unified `(target_kind, target_id)` key serves both, and the
 *     dedicated table lets the daily-sweep cron answer "what changed
 *     since last sweep?" in one indexed SELECT.
 *
 * **Lifecycle:**
 *   - INSERTed by `PolarizationTransitionNotifier::sweep()` on first
 *     classification of a target.
 *   - UPDATEd on every subsequent sweep when the classifier output
 *     changes.
 *   - `last_notified_at` is bumped when the 24h coalescing window
 *     advances — drives the notifier's "don't re-notify on the same
 *     state inside 24h" rule.
 *
 * **Source of truth posture:** this table is a CACHE of the classifier
 * output. The classifier is the canonical computer; this table memoizes
 * the result for the worker + the public view-model surfaces. If the
 * table is dropped or out of sync, `DivergenceStateClassifier::classify()`
 * still produces correct values at read-time — only the notifier loses
 * its "newly transitioned" detection capability. Acceptable
 * degradation: the worker will re-discover state on its next sweep
 * (treating every classification as a transition for a single cycle).
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since V2 Trust Attestation Layer PR-8b (2026-05-14)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_target_divergence_state_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_target_divergence_state';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        target_kind VARCHAR(20) NOT NULL,
        target_id BIGINT UNSIGNED NOT NULL,
        current_state VARCHAR(20) NOT NULL,
        computed_at DATETIME NOT NULL,
        last_notified_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_target (target_kind, target_id),
        KEY idx_state (current_state),
        KEY idx_computed_at (computed_at),
        KEY idx_last_notified_at (last_notified_at)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}
