<?php
/**
 * Repair orphaned Cosmos gate pointers left by the Stargaze retirement
 * (2026-08-06).
 *
 * WHAT BROKE
 * ----------
 * `retire-stargaze-chain.php` deleted the `stargaze` chain row and every
 * collection on it — by design. stargaze-1 halted and its CW-721
 * collections were re-instantiated on Cosmos Hub as NEW contracts with
 * `cosmos1…` addresses (no address continuity), so a remap was impossible;
 * that migration's docblock recorded that collections would instead be
 * "re-curated manually against the `cosmos` chain row". The re-curation
 * happened on the local development database and never reached staging.
 *
 * The PeepSo holder-group posts survived the deletion — only the rows their
 * gate meta points AT were removed. Eleven NFT communities therefore carry
 * `_bcc_gate_chain_id` / `_bcc_gate_collection_id` values referencing a
 * deleted chain and deleted collections, plus dead `stars1…` contract
 * addresses. The live symptom on `GET /bcc/v1/groups` is `chain_tag: null`,
 * `collection_stats: null` and `image_url: null`, and the groups are absent
 * from a Cosmos Hub chain filter.
 *
 * THE REPAIR DATA
 * ---------------
 * Every Hub address in the map below was read back from
 * `/cosmwasm/wasm/v1/contract/{address}` on Cosmos Hub (contract exists,
 * label matches the collection) AND cross-checked against the Stargaze
 * marketplace collections index; nine of the eleven additionally match the
 * operator's own curated rows in the local database byte-for-byte. All are
 * SG721 (code 434) except Mad Scientists (code 467 — an ICS-721 debt
 * voucher whose CW-721 `contract_info` query answers
 * `{"name":"Mad Scientists","symbol":"MS"}`, so it reads as CW-721 to the
 * holdings path like the rest).
 *
 * WHY is_verified = 1
 * -------------------
 * These communities were operator-created and already gated on these exact
 * collections before our own retirement migration deleted the rows.
 * Restoring them verified RESTORES THE OPERATOR'S PRIOR DECISION rather
 * than making a new one on their behalf — the verification flag is not
 * being granted here, it is being put back. (Contrast
 * `CollectionRepository::addManual`, which deliberately writes
 * `is_verified = 0` because that path represents a NEW curation the
 * operator has not yet signed off on.)
 *
 * ORPHAN-ONLY, SO IT CANNOT DISTURB A HEALTHY INSTALL
 * ---------------------------------------------------
 * A group is only touched when its CURRENT `_bcc_gate_chain_id` fails to
 * resolve to a live `bcc_chains` row. A correctly-configured group — the
 * local development database, and production if it was never affected — is
 * skipped untouched. That also makes the migration naturally idempotent:
 * a repaired group is healthy on the next pass and is skipped. Groups that
 * do not exist on an environment at all are skipped as expected, not as an
 * error (production may not carry these communities).
 *
 * Existing collection rows are REUSED by `(chain_id, contract_address)` and
 * never rewritten, so a row the operator has since enriched or re-curated
 * keeps its own fields; only the group's three gate metas are repointed.
 *
 * Status contract: any DB error → INCOMPLETE (retry next request); a
 * missing `cosmos` chain row (nothing sane to point at) or a fully-walked
 * map → COMPLETE.
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since 2026-08-06 (Stargaze-retirement orphan repair)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('bcc_trust_repair_orphaned_cosmos_gates')) {

    function bcc_trust_repair_orphaned_cosmos_gates(): string
    {
        global $wpdb;

        // Group slug (wp_posts.post_name, post_type peepso-group) =>
        // [collection name, Cosmos Hub contract address]. Addresses are
        // already bech32-lowercase; strtolower() below keeps the
        // "lowercase canonical" invariant GatedGroupRepository documents
        // regardless of how this map is edited later.
        $map = [
            'holders-bad-kids'                => ['Bad Kids', 'cosmos12gsv9tmjhhg86wg9fnd9cnju28jx3fxva9cn8dh9meketkfxxajqmg3exz'],
            'holders-baaaad-kids'             => ['Baaaad Kids', 'cosmos1l23wswrytqnfr4w2sgsmng09zgvcr5xpu75jr88u9cyaucwv7lxsx20x8n'],
            'holders-bit-kids'                => ['Bit Kids', 'cosmos1f8tll6cgq2a0y0vxp8eurnvqv66ycwsvp8z6llky0yj06ah7vkpstrvkdk'],
            'holders-celestine-sloth-society' => ['Celestine Sloth Society', 'cosmos1da2fer8ag2zvpznr09sqmakqw8pc6tf9e7erm0jdt58j4zmk6v4q4jf304'],
            'holders-geckies'                 => ['Geckies', 'cosmos1fr49zzqpprzfxhzlh7wlud7gq9w07kkdqyhrn8gsrrk8dndkwnjqag2us3'],
            'holders-ibc-frens'               => ['IBC Frens', 'cosmos1xe4yh0lgfta454g0ecywt29n63t7ypqxc3ttnlxacnwgcyrqlpwsc7h7wa'],
            'holders-mad-scientists'          => ['Mad Scientists', 'cosmos1dhc08f4n6ulzqtncrsue0uk2g5fr2wxp7qc56vcfcdsy3jqzhdts4a0yuf'],
            'holders-miamigos'                => ['MIAMIGOS', 'cosmos19unyz6gqngdgjk45lrv7faak5sn6kyan6rjr9uhlhe647m08tdhq7dn0zh'],
            'holders-rekt-bulls'              => ['Rekt Bulls', 'cosmos1n77hz6pz9h9k5rtvadusvcqwgy0cv7ldeaua8rtqxtwu95960r2q2p7q47'],
            'holders-shrimp-avatars'          => ['Shrimp Avatars', 'cosmos1vpqgq2yarym3c97j7pzfdvz9f7724vjqrujal00mvd9rumm48ydqnsag5j'],
            'holders-sparkle-pets'            => ['Sparkle Pets', 'cosmos144le62dr3u3tglwgjlphnet45w0hrxr2awlxxegpw66d0chzeh0q4qw4nv'],
        ];

        $chainsTable      = $wpdb->prefix . 'bcc_chains';
        $collectionsTable = $wpdb->prefix . 'bcc_onchain_collections';

        // Resolve by slug — ids differ per install, never hardcode
        // (the same rule retire-stargaze-chain.php states explicitly).
        $cosmosChainId = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM `{$chainsTable}` WHERE slug = %s LIMIT 1",
            'cosmos'
        ));
        if ($wpdb->last_error !== '') {
            return BCC_TRUST_MIGRATION_INCOMPLETE;
        }
        if ($cosmosChainId === null) {
            // No Cosmos Hub row to point at. Retrying forever would never
            // change that, so complete and say why.
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::warning(
                    '[bcc-trust] orphaned-cosmos-gate repair skipped: no `cosmos` chain row on this install'
                );
            }
            return BCC_TRUST_MIGRATION_COMPLETE;
        }
        $cosmosChainId = (int) $cosmosChainId;

        $now       = current_time('mysql', true);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS);

        /** @var array<int, bool> $chainExists Memo for the health check. */
        $chainExists = [$cosmosChainId => true];

        $groupsRepaired     = 0;
        $collectionsCreated = 0;
        $collectionsReused  = 0;
        $groupsSkippedOk    = 0;
        $groupsNotFound     = 0;
        /** @var list<string> $repairedSlugs */
        $repairedSlugs = [];

        foreach ($map as $slug => $entry) {
            [$collectionName, $contract] = $entry;
            $contract = strtolower($contract);

            $groupId = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                  WHERE post_type = %s AND post_name = %s
                  LIMIT 1",
                'peepso-group',
                $slug
            ));
            if ($wpdb->last_error !== '') {
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }
            if ($groupId === null) {
                // Expected on environments that never carried this
                // community — not an error.
                $groupsNotFound++;
                continue;
            }
            $groupId = (int) $groupId;

            // ORPHAN GATE. A chain id that still resolves means the group
            // is configured correctly (local dev, or an untouched prod) —
            // leave it exactly as the operator left it.
            $currentChainId = (int) get_post_meta($groupId, '_bcc_gate_chain_id', true);
            if ($currentChainId > 0) {
                if (!array_key_exists($currentChainId, $chainExists)) {
                    $found = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM `{$chainsTable}` WHERE id = %d LIMIT 1",
                        $currentChainId
                    ));
                    if ($wpdb->last_error !== '') {
                        return BCC_TRUST_MIGRATION_INCOMPLETE;
                    }
                    $chainExists[$currentChainId] = $found !== null;
                }
                if ($chainExists[$currentChainId]) {
                    $groupsSkippedOk++;
                    continue;
                }
            }

            // Find-or-create the collection, keyed by the table's own
            // unique (chain_id, contract_address). An existing row is
            // reused as-is — never rewritten.
            $collectionId = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM `{$collectionsTable}`
                  WHERE chain_id = %d AND contract_address = %s
                  LIMIT 1",
                $cosmosChainId,
                $contract
            ));
            if ($wpdb->last_error !== '') {
                return BCC_TRUST_MIGRATION_INCOMPLETE;
            }

            if ($collectionId !== null) {
                $collectionId = (int) $collectionId;
                $collectionsReused++;
            } else {
                // $wpdb->insert parameterizes every value through its
                // format list — no interpolation. wallet_link_id is
                // omitted deliberately: it is DEFAULT NULL and these are
                // curated rows, not wallet-discovered ones.
                $inserted = $wpdb->insert(
                    $collectionsTable,
                    [
                        'contract_address' => $contract,
                        'chain_id'         => $cosmosChainId,
                        'collection_name'  => $collectionName,
                        'token_standard'   => 'CW-721',
                        'show_on_profile'  => 1,
                        'is_verified'      => 1,
                        'source'           => 'manual',
                        'fetched_at'       => $now,
                        'expires_at'       => $expiresAt,
                    ],
                    ['%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s']
                );

                $collectionId = (int) $wpdb->insert_id;
                if ($inserted === false || $collectionId <= 0) {
                    // A concurrent writer may have won the unique key
                    // between the SELECT and the INSERT — re-read before
                    // treating it as a failure.
                    $collectionId = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM `{$collectionsTable}`
                          WHERE chain_id = %d AND contract_address = %s
                          LIMIT 1",
                        $cosmosChainId,
                        $contract
                    ));
                    if ($collectionId <= 0) {
                        return BCC_TRUST_MIGRATION_INCOMPLETE;
                    }
                    $collectionsReused++;
                } else {
                    $collectionsCreated++;
                }
            }

            // Repoint the gate. The contract address matters as much as
            // the ids: the holdings query runs against the stored address,
            // and the stored one is a dead `stars1…`.
            update_post_meta($groupId, '_bcc_gate_chain_id', $cosmosChainId);
            update_post_meta($groupId, '_bcc_gate_collection_id', $collectionId);
            update_post_meta($groupId, '_bcc_gate_contract_address', $contract);

            $groupsRepaired++;
            $repairedSlugs[] = (string) $slug;
        }

        // Admin Chains page shows a 1h-TTL per-chain collection count;
        // drop it so restored rows appear immediately (same courtesy
        // CollectionRepository::addManual pays).
        if ($collectionsCreated > 0) {
            wp_cache_delete('collection_counts_by_chain', 'bcc_onchain');
        }

        if (class_exists('\\BCC\\Core\\Log\\Logger')) {
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] repaired orphaned Cosmos gate pointers (Stargaze retirement fallout)',
                [
                    'cosmos_chain_id'     => $cosmosChainId,
                    'groups_repaired'     => $groupsRepaired,
                    'collections_created' => $collectionsCreated,
                    'collections_reused'  => $collectionsReused,
                    'groups_skipped_ok'   => $groupsSkippedOk,
                    'groups_not_found'    => $groupsNotFound,
                    'repaired_slugs'      => $repairedSlugs,
                ]
            );
        }

        return BCC_TRUST_MIGRATION_COMPLETE;
    }
}
