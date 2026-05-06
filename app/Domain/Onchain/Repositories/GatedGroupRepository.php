<?php
/**
 * Gate-config storage for NFT-gated peepso-groups.
 *
 * Five post-meta keys per gated group:
 *   _bcc_group_kind          = 'holders'
 *   _bcc_gate_chain_id       (numeric FK to onchain_chains)
 *   _bcc_gate_contract_address (lowercase canonical)
 *   _bcc_gate_min_balance    (default 1)
 *   _bcc_gate_collection_id  (FK to onchain_collections.id)
 *
 * No parallel ledger — membership stays in PeepSo's peepso_group_members.
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

        global $wpdb;
        $contractCanonical = strtolower($contract);

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
                AND pm_contract.meta_value = %s
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

        return new GatedGroupConfig(
            $groupId,
            $chainId,
            strtolower($contract),
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
     * Write the five gate post-meta keys atomically (PeepSo group post must exist).
     *
     * @param int    $groupId          Existing peepso-group post ID.
     * @param int    $chainId          FK to onchain_chains.
     * @param string $contractAddress  Will be lowercased.
     * @param int    $minBalance       Default 1.
     * @param int    $collectionId     FK to onchain_collections.id.
     */
    public static function writeGateConfig(
        int $groupId,
        int $chainId,
        string $contractAddress,
        int $minBalance,
        int $collectionId
    ): void {
        update_post_meta($groupId, self::META_KIND,       self::KIND_HOLDERS);
        update_post_meta($groupId, self::META_CHAIN_ID,   $chainId);
        update_post_meta($groupId, self::META_CONTRACT,   strtolower($contractAddress));
        update_post_meta($groupId, self::META_MIN_BAL,    max(1, $minBalance));
        update_post_meta($groupId, self::META_COLLECTION, $collectionId);
    }
}
