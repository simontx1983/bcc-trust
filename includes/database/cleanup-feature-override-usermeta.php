<?php
/**
 * One-shot cleanup — delete the retired `bcc_feature_override_{key}`
 * usermeta rows (Rank Phase 5; approved plan Addendum A2).
 *
 * The override mechanism died with FeatureAccessService at the atomic
 * cutover: capability authorization now flows through the
 * CapabilityResolver against canonical Rank/Trust state, with no
 * per-user bypass. All existing rows belong to fake accounts
 * (fresh-install doctrine).
 *
 * A1 discipline: exact prefix predicate (escaped LIKE), remaining
 * count logged before each batch, bounded deletes, no wildcard beyond
 * the documented key family. Not recreatable and not needed.
 *
 * Status contract: DB error or full batch → INCOMPLETE (resume);
 * drained → COMPLETE.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 5 (2026-07-31)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_cleanup_feature_override_usermeta')) {

    function bcc_trust_cleanup_feature_override_usermeta(): string
    {
        global $wpdb;

        $batchSize = 500;
        $pattern   = $wpdb->esc_like('bcc_feature_override_') . '%';

        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
            $pattern
        ));

        if ($remaining === 0) {
            \BCC\Core\Log\Logger::info('[bcc-trust] feature-override usermeta cleanup complete', ['rows_deleted' => 0]);
            return BCC_TRUST_MIGRATION_COMPLETE;
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] feature-override usermeta cleanup: batch starting', [
            'remaining' => $remaining,
            'batch'     => $batchSize,
        ]);

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s LIMIT %d",
            $pattern,
            $batchSize
        ));

        if ($deleted === false || (int) $deleted === $batchSize) {
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] feature-override usermeta cleanup complete', [
            'rows_deleted' => (int) $deleted,
        ]);

        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
