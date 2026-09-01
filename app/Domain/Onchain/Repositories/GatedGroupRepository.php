<?php
/**
 * Gate-config storage for NFT-gated peepso-groups.
 *
 * Five post-meta keys per gated group:
 *   _bcc_group_kind          = 'holders'
 *   _bcc_gate_chain_id       (numeric FK to onchain_chains)
 *   _bcc_gate_contract_address (chain-aware canonical identity, BYTE-EXACT)
 *   _bcc_gate_min_balance    (default 1)
 *   _bcc_gate_collection_id  (FK to onchain_collections.id) — THE AUTHORITY
 *
 * No parallel ledger — membership stays in PeepSo's peepso_group_members.
 *
 * ── WHICH KEY IS THE IDENTITY (PR 5b) ───────────────────────────────────
 * `_bcc_gate_collection_id` is. `_bcc_gate_contract_address` is kept for
 * backward compatibility and display, and is never allowed to override the
 * linked collection row — see {@see \BCC\Trust\Onchain\Services\GateIdentityResolver}.
 *
 * This docblock previously described `_bcc_gate_contract_address` as
 * "lowercase canonical". It was neither: lower-casing a base58 Solana mint
 * produces a different key, and on the eight production Solana gates the
 * value stored there was a Magic Eden SYMBOL, not an address at all. Both
 * the reader and the writer below now treat the value as byte-exact and
 * defer to the collection row for identity.
 *
 * @package BCC\Trust\Onchain\Repositories
 */

namespace BCC\Trust\Onchain\Repositories;

use BCC\Trust\Onchain\ValueObjects\GatedGroupConfig;

if (!defined('ABSPATH')) {
    exit;
}

final class GatedGroupRepository {

    public const META_KIND       = '_bcc_group_kind';
    public const META_CHAIN_ID   = '_bcc_gate_chain_id';
    public const META_CONTRACT   = '_bcc_gate_contract_address';
    public const META_MIN_BAL    = '_bcc_gate_min_balance';
    public const META_COLLECTION = '_bcc_gate_collection_id';

    public const KIND_HOLDERS = 'holders';

    /**
     * Reverse lookup: find the gated group for a (chain, contract) pair.
     * Returns the WP post ID or null. Bounded LIMIT 1.
     */
    public static function findGroupForCollection(int $chainId, string $contract): ?int {
        if ($chainId <= 0 || $contract === '') {
            return null;
        }

        // PR 5b: this is an identity lookup, so the value it matches on
        // goes through the one chain-aware rule.
        //
        // It used to be `strtolower($contract)` — right for EVM and Cosmos,
        // wrong for Solana, where base58 is case-sensitive and a folded
        // mint is a DIFFERENT key. `writeGateConfig()` now stores the
        // canonical form byte-exact, so folding here would stop the lookup
        // ever finding a Solana gate.
        //
        // A value that is not a valid identity for this chain matches
        // nothing, so it returns null rather than running a query whose
        // answer could only be misleading.
        //
        // ⚠ The comparison below is forced to `utf8mb4_bin`, and that is
        // NOT cosmetic. `wp_postmeta.meta_value` is a WordPress core column
        // with a case-INSENSITIVE collation, so removing the PHP
        // `strtolower()` is not by itself enough: MySQL would still match a
        // case-folded mint against the stored one, and this lookup would
        // keep resolving two different Solana keys to the same gate. That
        // is the same class of defect as the PHP fold, one layer down, and
        // it is invisible until something asserts it (an integration test
        // caught exactly this).
        //
        // Safe for every family: EVM and Cosmos canonical forms are
        // lowercase and are stored canonical, so a binary comparison
        // returns the same rows it did before.
        $chain  = ChainRepository::getById($chainId);
        $family = $chain === null ? '' : (string) ($chain->chain_type ?? '');

        $identity = \BCC\Trust\Onchain\Support\NftCollectionIdentifier::canonicalize($family, $contract);
        if (!$identity->isAccepted()) {
            return null;
        }

        $contractCanonical = $identity->canonical();

        global $wpdb;

        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT pm_chain.post_id
               FROM {$wpdb->postmeta} pm_chain
          INNER JOIN {$wpdb->postmeta} pm_kind     ON pm_kind.post_id     = pm_chain.post_id
          INNER JOIN {$wpdb->postmeta} pm_contract ON pm_contract.post_id = pm_chain.post_id
              WHERE pm_chain.meta_key    = %s
                AND pm_chain.meta_value  = %d
                AND pm_kind.meta_key     = %s
                AND pm_kind.meta_value   = %s
                AND pm_contract.meta_key = %s
                AND pm_contract.meta_value COLLATE utf8mb4_bin = %s
              LIMIT 1",
            self::META_CHAIN_ID,
            $chainId,
            self::META_KIND,
            self::KIND_HOLDERS,
            self::META_CONTRACT,
            $contractCanonical
        ));

        return $row !== null ? (int) $row : null;
    }

    /**
     * Read the gate config for a group. Returns null if not a holders group.
     * Uses WP's post-meta cache (call update_meta_cache for batches first).
     */
    public static function getGateConfig(int $groupId): ?GatedGroupConfig {
        if ($groupId <= 0) {
            return null;
        }

        $kind = (string) get_post_meta($groupId, self::META_KIND, true);
        if ($kind !== self::KIND_HOLDERS) {
            return null;
        }

        $chainId  = (int) get_post_meta($groupId, self::META_CHAIN_ID, true);
        $contract = (string) get_post_meta($groupId, self::META_CONTRACT, true);
        if ($chainId <= 0 || $contract === '') {
            return null;
        }

        $minBalance = (int) get_post_meta($groupId, self::META_MIN_BAL, true);
        if ($minBalance < 1) {
            $minBalance = 1;
        }

        $collectionMeta = get_post_meta($groupId, self::META_COLLECTION, true);
        $collectionId   = $collectionMeta !== '' ? (int) $collectionMeta : null;

        // PR 5b: the stored meta value is returned BYTE-EXACT. It used to be
        // `strtolower($contract)`, which silently corrupted every Solana
        // identity it touched.
        //
        // This value is now legacy/display only — `GateIdentityResolver`
        // derives the identity a provider is actually asked about from the
        // linked collection row's `canonical_identifier`. Handing back the
        // raw stored value keeps that distinction honest: a caller reading
        // `contractAddress` gets what is stored, not a normalised fiction.
        return new GatedGroupConfig(
            $groupId,
            $chainId,
            $contract,
            $minBalance,
            $collectionId
        );
    }

    /**
     * All gated groups, hydrated to GatedGroupConfig. One SELECT for IDs,
     * one update_meta_cache, then per-group hydration off the warm cache.
     *
     * @return list<GatedGroupConfig>
     */
    public static function listAllGatedGroupConfigs(int $limit = 500): array {
        $ids = self::listAllGatedGroupIds($limit);
        if ($ids === []) {
            return [];
        }

        return array_values(self::findManyByGroupIds($ids));
    }

    /**
     * Bulk-fetch GatedGroupConfig for a caller-supplied set of group_ids.
     * One `update_meta_cache` warms post_meta for the whole batch; each
     * subsequent `getGateConfig` call hits the warm cache instead of the
     * DB. Non-holder group_ids in the input set are silently dropped.
     *
     * Used by the Profile Groups Tab + Holder-Groups REST surface to
     * resolve viewer eligibility across N gated groups in one DB
     * round-trip rather than N.
     *
     * @param int[] $groupIds
     * @return array<int, GatedGroupConfig> map keyed by group_id
     */
    public static function findManyByGroupIds(array $groupIds): array {
        if ($groupIds === []) {
            return [];
        }

        update_meta_cache('post', $groupIds);

        $map = [];
        foreach ($groupIds as $groupId) {
            $cfg = self::getGateConfig($groupId);
            if ($cfg !== null) {
                $map[$cfg->groupId] = $cfg;
            }
        }
        return $map;
    }

    /**
     * @return list<int>
     */
    public static function listAllGatedGroupIds(int $limit = 500): array {
        global $wpdb;

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT pm.post_id
               FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key   = %s
                AND pm.meta_value = %s
                AND p.post_type   = %s
                AND p.post_status = %s
              ORDER BY p.ID ASC
              LIMIT %d",
            self::META_KIND,
            self::KIND_HOLDERS,
            'peepso-group',
            'publish',
            $limit
        ));

        return array_values(array_map('intval', $rows ?: []));
    }

    /**
     * Opted-in auto-join user IDs, cursor-paged by user_id ASC.
     *
     * Drives the twicedaily reconcile sweep's rotation: callers pass the
     * last-seen user_id as `$afterId` so every opted-in user is eventually
     * processed instead of the query re-hitting the first N forever. A
     * short result (< $limit) means the cursor reached the end → caller
     * wraps to 0.
     *
     * Bounded (§4): `LIMIT %d`, unique-key meta filter, cursor bound.
     * Explicit single column. The meta_key/meta_value pair matches
     * NftGroupGateService::USER_META_AUTO_JOIN = '1' (the only opted-in
     * marker).
     *
     * @return list<int>
     */
    public static function listAutoJoinUserIdsAfter(int $afterId, int $limit = 20): array {
        if ($limit <= 0) {
            return [];
        }
        if ($afterId < 0) {
            $afterId = 0;
        }

        global $wpdb;

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT user_id
               FROM {$wpdb->usermeta}
              WHERE meta_key   = %s
                AND meta_value = %s
                AND user_id    > %d
              ORDER BY user_id ASC
              LIMIT %d",
            \BCC\Trust\Onchain\Services\NftGroupGateService::USER_META_AUTO_JOIN,
            '1',
            $afterId,
            $limit
        ));

        return array_values(array_map('intval', $rows ?: []));
    }

    /**
     * Write the five gate post-meta keys (PeepSo group post must exist).
     *
     * ── PR 5b: THIS CAN NOW REFUSE ──────────────────────────────────────
     * It used to store `strtolower($contractAddress)` unconditionally and
     * return void. Two things were wrong with that:
     *
     *  1. Folding case corrupts a Solana identity outright — base58 is
     *     case-sensitive, so the stored value became a different key.
     *  2. It would happily create a gate for a collection with no resolved
     *     identity. Such a gate is unsatisfiable BY CONSTRUCTION: no
     *     provider can ever return holdings for a marketplace alias, the
     *     count is permanently 0, and a real 0 reads as INELIGIBLE. That
     *     is the exact defect this PR removes — so manufacturing new
     *     instances of it is refused rather than merely fixed afterwards.
     *
     * The value written is now validated for the chain's family and stored
     * BYTE-EXACT. A refusal writes NOTHING — not a partial gate, not four
     * of five keys — and returns false for the caller to report.
     *
     * @param int    $groupId          Existing peepso-group post ID.
     * @param int    $chainId          FK to onchain_chains.
     * @param string $chainFamily      `wp_bcc_chains.chain_type` — NOT inferred.
     * @param string $canonicalAddress The collection's canonical identifier.
     *                                 Stored verbatim when accepted.
     * @param int    $minBalance       Default 1.
     * @param int    $collectionId     FK to onchain_collections.id.
     *
     * @return bool true when all five keys were written; false when the
     *              identity was refused and nothing was written.
     */
    public static function writeGateConfig(
        int $groupId,
        int $chainId,
        string $chainFamily,
        string $canonicalAddress,
        int $minBalance,
        int $collectionId
    ): bool {
        $identity = \BCC\Trust\Onchain\Support\NftCollectionIdentifier::canonicalize(
            $chainFamily,
            $canonicalAddress
        );

        if (!$identity->isAccepted()) {
            \BCC\Core\Log\Logger::warning(
                '[bcc-trust] refusing to write a holder gate with an unresolved collection identity',
                [
                    'group_id'      => $groupId,
                    'collection_id' => $collectionId,
                    'chain_id'      => $chainId,
                    'chain_family'  => $chainFamily,
                    'reason'        => $identity->reason(),
                ]
            );

            return false;
        }

        update_post_meta($groupId, self::META_KIND,       self::KIND_HOLDERS);
        update_post_meta($groupId, self::META_CHAIN_ID,   $chainId);
        update_post_meta($groupId, self::META_CONTRACT,   $identity->canonical());
        update_post_meta($groupId, self::META_MIN_BAL,    max(1, $minBalance));
        update_post_meta($groupId, self::META_COLLECTION, $collectionId);

        return true;
    }
}
