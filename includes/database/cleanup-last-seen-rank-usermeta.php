<?php
/**
 * One-shot cleanup — delete the retired `bcc_last_seen_rank` usermeta.
 *
 * The Rank redesign Phase 5 atomic cutover deleted the meta's only
 * writer/reader (RankProgressionListener — the legacy level-derived
 * promotion detector). Promotions are now decided and audited by
 * RankPromotionEngine against the rank_state table, so the rows are
 * dead data.
 *
 * A1 discipline (approved plan): exact meta_key predicate, remaining
 * count logged before each batch, bounded batched deletes, no wildcard.
 * Not recreatable and not needed — rank_state is the canonical rank
 * record from this phase on.
 *
 * Status contract (migration-runner callback):
 *   - DB error                          → INCOMPLETE (fail closed, retry)
 *   - batch full (more rows may remain) → INCOMPLETE (resume next request)
 *   - zero rows remain                  → COMPLETE
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since Rank redesign Phase 5 (2026-07-31)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_cleanup_last_seen_rank_usermeta')) {

    function bcc_trust_cleanup_last_seen_rank_usermeta(): string
    {
        global $wpdb;

        $batchSize = 500;

        // Dry-run count first — recorded so the removal is auditable.
        $remaining = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
            'bcc_last_seen_rank'
        ));

        if ($remaining === 0) {
            \BCC\Core\Log\Logger::info('[bcc-trust] last-seen-rank usermeta cleanup complete', ['rows_deleted' => 0]);
            return BCC_TRUST_MIGRATION_COMPLETE;
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] last-seen-rank usermeta cleanup: batch starting', [
            'remaining' => $remaining,
            'batch'     => $batchSize,
        ]);

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT %d",
            'bcc_last_seen_rank',
            $batchSize
        ));

        if ($deleted === false) {
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        if ((int) $deleted === $batchSize) {
            // A full batch means more rows may remain — resume next request.
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] last-seen-rank usermeta cleanup complete', [
            'rows_deleted' => (int) $deleted,
        ]);

        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
