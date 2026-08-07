<?php
/**
 * One-shot cleanup — delete the retired CW-721 discovery scan state.
 *
 * WHAT IS BEING RETIRED
 * ---------------------
 * The pre-2026-08 CW-721 discovery loop kept its state in two places
 * outside any table:
 *
 *   wp_options   `bcc_trust_cw721_scan_{chainId}`
 *                the {code_id, next_key} page cursor
 *   transients   `bcc_trust_cw721_code_ids_{chainId}`
 *                the 7-day cache of code IDs sampled from ALREADY-CURATED
 *                collections
 *
 * Both belonged to a CLOSED loop: code IDs were only ever learned from
 * collections an operator had already curated, so a code family with no
 * curated collection under it was never sampled, never enumerated, and
 * never discovered. A chain with zero curated collections discovered
 * nothing, forever.
 *
 * They are SUPERSEDED, not merely disabled:
 *   - per-chain progress   → `wp_bcc_chain_checkpoints.cw_*`
 *   - per-family inventory
 *     + enumeration cursor → `wp_bcc_cosmwasm_code_families`
 *   - per-contract memory  → `wp_bcc_cosmwasm_contracts`
 *
 * Leaving the old rows behind would leave a second, stale cursor store
 * next to the new one — the "two discovery systems running in parallel"
 * failure this feature exists to end. Nothing reads these keys any more
 * (the code that did was deleted in the same change), so the delete is
 * safe and not recreatable.
 *
 * WHY WILDCARDS ARE ACCEPTABLE HERE
 * ---------------------------------
 * The keys are per-CHAIN-ID, so there is no fixed set to enumerate
 * without first reading the chains table (which would make a data
 * migration depend on another table's contents). The LIKE patterns are
 * anchored on a literal, unambiguous prefix that no other subsystem
 * uses, and each pattern's `_` wildcards are escaped so `_transient_`
 * cannot match `Xtransient9`. Batched and bounded per request.
 *
 * Status contract (migration-runner callback):
 *   - DB error                          → INCOMPLETE (fail closed, retry)
 *   - batch full (more rows may remain) → INCOMPLETE (resume next request)
 *   - zero rows remain                  → COMPLETE
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since CosmWasm discovery (2026-08)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_cleanup_cw721_scan_options')) {

    function bcc_trust_cleanup_cw721_scan_options(): string
    {
        global $wpdb;

        $batchSize = 200;

        // `_` and `%` are LIKE wildcards; esc_like escapes them so the
        // patterns match the literal prefixes only.
        $patterns = [
            $wpdb->esc_like('bcc_trust_cw721_scan_') . '%',
            $wpdb->esc_like('_transient_bcc_trust_cw721_code_ids_') . '%',
            $wpdb->esc_like('_transient_timeout_bcc_trust_cw721_code_ids_') . '%',
        ];

        $totalDeleted = 0;

        foreach ($patterns as $pattern) {
            $remaining = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                $pattern
            ));

            if ($remaining === 0) {
                continue;
            }

            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
                $pattern,
                $batchSize
            ));

            if ($deleted === false) {
                // Fail closed — stay retryable rather than mark complete.
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }

            $totalDeleted += (int) $deleted;

            if ((int) $deleted === $batchSize) {
                // A full batch means more may remain — resume next request.
                \BCC\Core\Log\Logger::info('[bcc-trust] cw721 scan-state cleanup: batch drained, more remain', [
                    'pattern'      => $pattern,
                    'rows_deleted' => (int) $deleted,
                ]);

                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
        }

        \BCC\Core\Log\Logger::info('[bcc-trust] cw721 scan-state cleanup complete', [
            'rows_deleted' => $totalDeleted,
        ]);

        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
