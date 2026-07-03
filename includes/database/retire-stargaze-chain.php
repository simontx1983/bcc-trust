<?php
/**
 * Retire the Stargaze chain (Stargaze → Cosmos Hub migration, 2026).
 *
 * The stargaze-1 L1 halted in June 2026 after Cosmos Hub Prop 1017: its
 * CW-721 collections were re-instantiated on the Hub as NEW contracts
 * with `cosmos1…` addresses (no address continuity), so every stored
 * `stars1…` contract and wallet address is permanently dead. Decision
 * (2026-07-02): delete rather than remap — collections are re-curated
 * manually against the `cosmos` chain row via the admin Verify
 * Collections page, and the two local test wallets simply relink.
 *
 * Removes, in dependency order: user NFT selections and wallet links on
 * the stargaze chain, its collections, then the chain row itself
 * (schema-chains.php no longer seeds it). collection_pieces /
 * nft_holdings / claims / chain_checkpoints were verified empty for the
 * stargaze chain locally, and any stragglers elsewhere are cache-layer
 * rows keyed by contract address that can never be queried again once
 * the collections are gone.
 *
 * Idempotent: guarded by the `bcc_trust_stargaze_chain_retired` option,
 * and a missing chain row short-circuits to done (fresh installs never
 * seed stargaze anymore). Runs on init priority 29, after the one-shot
 * migrations at 27/28.
 *
 * @since Stargaze retirement (2026-07-02)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_retire_stargaze_chain')) {

    function bcc_trust_retire_stargaze_chain(): void
    {
        if (get_option('bcc_trust_stargaze_chain_retired')) {
            return;
        }

        global $wpdb;

        $chains = $wpdb->prefix . 'bcc_chains';

        $deleted = [];

        // CollectionRepository::bulkUpsert stamped source='stargaze' on
        // every auto-pulled row regardless of chain (the literal predated
        // Injective/EVM/Solana top-collection sync). The code now writes
        // 'toplist'; rename the survivors so the admin source badge and
        // the manual-vs-auto upsert guard keep matching. Independent of
        // the chain row, so it runs before the missing-row short-circuit.
        $collections = $wpdb->prefix . 'bcc_onchain_collections';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted['source_renamed'] = (int) $wpdb->query(
            "UPDATE `{$collections}` SET source = 'toplist' WHERE source = 'stargaze'"
        );

        // Resolve by slug — ids differ per install, never hardcode.
        $chainId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$chains}` WHERE slug = %s",
            'stargaze'
        ));

        if ($chainId === null) {
            update_option('bcc_trust_stargaze_chain_retired', time(), false);
            return;
        }
        $chainId = (int) $chainId;
        foreach ([
            'bcc_user_nft_selections',
            'bcc_wallet_links',
            'bcc_onchain_collection_pieces',
            'bcc_onchain_collections',
        ] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $deleted[$suffix] = (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$table}` WHERE chain_id = %d",
                $chainId
            ));
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted['bcc_chains'] = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$chains}` WHERE id = %d",
            $chainId
        ));

        if (class_exists('\\BCC\\Trust\\Onchain\\Repositories\\ChainRepository')) {
            \BCC\Trust\Onchain\Repositories\ChainRepository::clearCache();
        }

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] retired stargaze chain (Stargaze → Cosmos Hub migration)',
                ['chain_id' => $chainId, 'deleted' => $deleted]
            );
        }

        update_option('bcc_trust_stargaze_chain_retired', time(), false);
    }

    // Priority 29 — after the existing one-shot migrations (27/28),
    // keeping the drop-legacy chain strictly ordered.
    add_action('init', 'bcc_trust_retire_stargaze_chain', 29);
}
