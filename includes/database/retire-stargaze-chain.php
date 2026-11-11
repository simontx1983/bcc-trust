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

    /** Log a failed retirement step and back off for an hour (F-41). */
    function bcc_trust_stargaze_retire_failed(string $step): void
    {
        global $wpdb;
        set_transient('bcc_trust_stargaze_retire_backoff', 1, HOUR_IN_SECONDS);
        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::error('[bcc-trust] stargaze retirement step failed; not marking retired, retrying in 1h', [
                'step'     => $step,
                'db_error' => $wpdb->last_error,
            ]);
        }
    }

    /**
     * Run a single-value SELECT and return its value ONLY when the result can
     * be trusted (#69 runProbe shape, #425 review L1). Returns [false, null]
     * on any failure.
     *
     * last_error alone is not an exhaustive failure signal: wpdb::query() can
     * return false WITHOUT setting it (the `! $this->ready` guard after a
     * failed reconnect, or a `query` filter emptying the SQL), and get_var()
     * discards query()'s return — so on those paths get_var() would read the
     * PREVIOUS statement's last_result (e.g. a table name from SHOW TABLES)
     * and (int) it to a plausible-looking 0. Run the statement through
     * query() and check BOTH, then read the value with get_var(null).
     *
     * @return array{0: bool, 1: string|null}
     */
    function bcc_trust_stargaze_probe(string $sql): array
    {
        global $wpdb;
        $wpdb->last_error = '';
        $ok = $wpdb->query($sql);
        if ($ok === false || (string) $wpdb->last_error !== '') {
            return [false, null];
        }
        $value = $wpdb->get_var(null);
        return [true, $value === null ? null : (string) $value];
    }

    /**
     * Rows OUTSIDE the retirement's own delete set that still reference the
     * stargaze chain (F-127), as [label => count] for every non-zero count.
     * Tables that do not exist are skipped. Returns null if any probe or
     * count failed — unknown must not be read as "none".
     *
     * @return array<string, int>|null
     */
    function bcc_trust_stargaze_retire_dependents(int $chainId): ?array
    {
        global $wpdb;
        $found = [];

        foreach ([
            'bcc_nft_holdings',
            'bcc_onchain_delegations',
            'bcc_onchain_validators',
            'bcc_onchain_claims',
            'bcc_collection_signals',
            'bcc_chain_checkpoints',
            'bcc_chain_nft_capabilities',
            'bcc_blog_chain_tags',
            'bcc_nft_spam_contracts',
        ] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            [$ok, $exists] = bcc_trust_stargaze_probe($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            if (!$ok) {
                return null;
            }
            if ($exists !== $table) {
                continue;
            }
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            [$ok, $count] = bcc_trust_stargaze_probe($wpdb->prepare("SELECT COUNT(*) FROM `{$table}` WHERE chain_id = %d", $chainId));
            if (!$ok || $count === null) {
                return null;
            }
            if ((int) $count > 0) {
                $found[$suffix] = (int) $count;
            }
        }

        // Every probe below counts posts in ANY non-trashed status — a draft
        // or private group/Hall can be published later.
        $collections = $wpdb->prefix . 'bcc_onchain_collections';

        // Gated groups whose gate points at a stargaze COLLECTION.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        [$ok, $groups] = bcc_trust_stargaze_probe($wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.post_id)
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status <> 'trash'
               JOIN `{$collections}` c ON c.id = CAST(pm.meta_value AS UNSIGNED)
              WHERE pm.meta_key = '_bcc_gate_collection_id'
                AND c.chain_id = %d",
            $chainId
        ));
        if (!$ok || $groups === null) {
            return null;
        }
        if ((int) $groups > 0) {
            $found['gated_groups'] = (int) $groups;
        }

        // Posts that reference the CHAIN directly (#425 review M1): a
        // chain-gated holder/validator group (GatedGroupRepository::
        // META_CHAIN_ID / ValidatorGroupRepository::META_CHAIN_ID) or a Hall
        // tagged with the chain (HallRepository::META_CHAIN_TAG). Both are
        // read as `c.id = CAST(pm.meta_value AS UNSIGNED)`, so deleting the
        // chain row would orphan them.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        [$ok, $chainTagged] = bcc_trust_stargaze_probe($wpdb->prepare(
            "SELECT COUNT(DISTINCT pm.post_id)
               FROM {$wpdb->postmeta} pm
               JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status <> 'trash'
              WHERE pm.meta_key IN ('_bcc_gate_chain_id', '_bcc_chain_tag')
                AND CAST(pm.meta_value AS UNSIGNED) = %d",
            $chainId
        ));
        if (!$ok || $chainTagged === null) {
            return null;
        }
        if ((int) $chainTagged > 0) {
            $found['chain_tagged_posts'] = (int) $chainTagged;
        }

        return $found;
    }

    function bcc_trust_retire_stargaze_chain(): void
    {
        if (get_option('bcc_trust_stargaze_chain_retired')) {
            return;
        }

        // F-41 / F-127: a failed step or a refusal leaves this unstamped and
        // re-checks at most hourly (a transient, so a permanently failing
        // environment is not re-scanned on every request).
        if (get_transient('bcc_trust_stargaze_retire_backoff')) {
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
        $renamed = $wpdb->query(
            "UPDATE `{$collections}` SET source = 'toplist' WHERE source = 'stargaze'"
        );
        if ($renamed === false) {
            bcc_trust_stargaze_retire_failed('rename stargaze source');
            return;
        }
        $deleted['source_renamed'] = (int) $renamed;

        // Resolve by slug — ids differ per install, never hardcode.
        $chainId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$chains}` WHERE slug = %s",
            'stargaze'
        ));
        if ($chainId === null && $wpdb->last_error !== '') {
            // A failed lookup is UNKNOWN, not "no stargaze chain".
            bcc_trust_stargaze_retire_failed('resolve stargaze chain id');
            return;
        }

        if ($chainId === null) {
            update_option('bcc_trust_stargaze_chain_retired', time(), false);
            return;
        }
        $chainId = (int) $chainId;

        // ── F-127: never orphan what this does not delete ──────────────
        // The deletes below remove selections, wallet links, pieces,
        // collections and the chain row. Rows elsewhere keyed by this chain
        // (holdings, delegations, validators, claims, signals, checkpoints,
        // capabilities, blog tags, spam rules) and gated groups pointing at
        // a stargaze collection would be left dangling: a gated group whose
        // collection id no longer exists can never be joined. Rather than
        // guess which of those are safe to delete, the retirement REFUSES
        // when any exist, logs the inventory, and leaves everything intact
        // for an operator.
        $dependents = bcc_trust_stargaze_retire_dependents($chainId);
        if ($dependents === null) {
            bcc_trust_stargaze_retire_failed('inventory dependents');
            return;
        }
        if ($dependents !== []) {
            set_transient('bcc_trust_stargaze_retire_backoff', 1, HOUR_IN_SECONDS);
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error(
                    '[bcc-trust] stargaze retirement refused: rows elsewhere still reference the chain; nothing deleted',
                    ['chain_id' => $chainId, 'dependents' => $dependents]
                );
            }
            return;
        }

        // All-or-nothing: a failure part-way used to leave collections whose
        // chain row was already gone (unreachable through the admin, which
        // INNER JOINs chains), with the retired flag stamped regardless.
        if ($wpdb->query('START TRANSACTION') === false) {
            bcc_trust_stargaze_retire_failed('start transaction');
            return;
        }
        // wpdb silently reconnects after "server has gone away", and the new
        // connection is in AUTOCOMMIT — the deletes after that point would no
        // longer be atomic. Pin the connection id and re-check it before
        // COMMIT (#425 review L2).
        [$ok, $connectionAtStart] = bcc_trust_stargaze_probe('SELECT CONNECTION_ID()');
        if (!$ok || $connectionAtStart === null) {
            $wpdb->query('ROLLBACK');
            bcc_trust_stargaze_retire_failed('read connection id');
            return;
        }
        foreach ([
            'bcc_user_nft_selections',
            'bcc_wallet_links',
            'bcc_onchain_collection_pieces',
            'bcc_onchain_collections',
        ] as $suffix) {
            $table = $wpdb->prefix . $suffix;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$table}` WHERE chain_id = %d",
                $chainId
            ));
            if ($result === false) {
                $wpdb->query('ROLLBACK');
                bcc_trust_stargaze_retire_failed("delete {$suffix}");
                return;
            }
            $deleted[$suffix] = (int) $result;
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$chains}` WHERE id = %d",
            $chainId
        ));
        if ($result === false) {
            $wpdb->query('ROLLBACK');
            bcc_trust_stargaze_retire_failed('delete chain row');
            return;
        }
        $deleted['bcc_chains'] = (int) $result;

        [$ok, $connectionAtCommit] = bcc_trust_stargaze_probe('SELECT CONNECTION_ID()');
        if (!$ok || $connectionAtCommit !== $connectionAtStart) {
            // Reconnected mid-transaction: the deletes ran in autocommit and
            // are already applied piecemeal. Do NOT stamp retired — a re-run
            // completes whatever is left, and the log says exactly what
            // happened.
            $wpdb->query('ROLLBACK');
            bcc_trust_stargaze_retire_failed('connection changed mid-transaction');
            return;
        }

        if ($wpdb->query('COMMIT') === false) {
            $wpdb->query('ROLLBACK');
            bcc_trust_stargaze_retire_failed('commit');
            return;
        }

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
