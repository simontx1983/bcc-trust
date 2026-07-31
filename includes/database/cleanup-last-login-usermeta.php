<?php
/**
 * One-shot cleanup — delete the retired `bcc_trust_last_login` usermeta.
 *
 * Rank redesign Phase 1 retired the meta's only writer
 * (UserLifecycleService::onLogin) and re-pointed its only reader
 * (DormancyDetector) to LoginDaysRepository::lastLoginDay(), so the
 * rows are dead data. `bcc_trust_last_ip` is NOT touched — it has its
 * own live writer and consumers.
 *
 * A1 discipline (approved plan): exact meta_key predicate, remaining
 * count logged before each batch, bounded batched deletes, no wildcard.
 * Not recreatable and not needed — the login_days table is the
 * canonical login record from this phase on.
 *
 * Status contract (migration-runner callback):
 *   - DB error                          → INCOMPLETE (fail closed, retry)
 *   - batch full (more rows may remain) → INCOMPLETE (resume next request)
 *   - zero rows remain                  → COMPLETE
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 1 (2026-07-31)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_cleanup_last_login_usermeta')) {

    function bcc_trust_cleanup_last_login_usermeta(): string
    {
        global $wpdb;

        $batchSize = 500;

        // Dry-run count first — recorded so the removal is auditable.
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
            'bcc_trust_last_login'
        ));

        if ($remaining === 0) {
            \BCC\Core\Log\Logger::info('[bcc-trust] last-login usermeta cleanup complete', ['rows_deleted' => 0]);
            return BCC_TRUST_MIGRATION_COMPLETE;
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] last-login usermeta cleanup: batch starting', [
            'remaining' => $remaining,
            'batch'     => $batchSize,
        ]);

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT %d",
            'bcc_trust_last_login',
            $batchSize
        ));

        if ($deleted === false) {
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        if ((int) $deleted === $batchSize) {
            // A full batch means more rows may remain — resume next request.
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] last-login usermeta cleanup complete', [
            'rows_deleted' => (int) $deleted,
        ]);

        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
