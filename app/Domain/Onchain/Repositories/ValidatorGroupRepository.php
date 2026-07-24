<?php
/**
 * Gate-config storage for validator/delegator peepso-groups.
 *
 * Exact sibling of {@see GatedGroupRepository} (the NFT holder gate).
 * Five post-meta keys per delegator community:
 *   _bcc_group_kind             = 'delegators'
 *   _bcc_gate_chain_id          (numeric FK to onchain_chains)
 *   _bcc_gate_validator_id      (FK to onchain_validators.id)
 *   _bcc_gate_operator_address  (lowercase valoper)
 *   _bcc_gate_min_stake         (display units, default '1')
 *
 * No parallel ledger — membership stays in PeepSo's peepso_group_members.
 *
 * @package BCC\Trust\Onchain\Repositories
 */

namespace BCC\Trust\Onchain\Repositories;

use BCC\Trust\Onchain\ValueObjects\ValidatorGatedGroupConfig;

if (!defined('ABSPATH')) {
    exit;
}

final class ValidatorGroupRepository {

    public const META_KIND         = '_bcc_group_kind';
    public const META_CHAIN_ID     = '_bcc_gate_chain_id';
    public const META_VALIDATOR_ID = '_bcc_gate_validator_id';
    public const META_OPERATOR     = '_bcc_gate_operator_address';
    public const META_MIN_STAKE    = '_bcc_gate_min_stake';

    public const KIND_DELEGATORS = 'delegators';

    /**
     * Reverse lookup: find the delegator community for a
     * (chain, validator) pair. Returns the WP post ID or null.
     * Bounded LIMIT 1 — mirrors GatedGroupRepository::findGroupForCollection.
     */
    public static function findGroupForValidator(int $chainId, int $validatorId): ?int {
        if ($chainId <= 0 || $validatorId <= 0) {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT pm_chain.post_id
               FROM {$wpdb->postmeta} pm_chain
          INNER JOIN {$wpdb->postmeta} pm_kind      ON pm_kind.post_id      = pm_chain.post_id
          INNER JOIN {$wpdb->postmeta} pm_validator ON pm_validator.post_id = pm_chain.post_id
              WHERE pm_chain.meta_key     = %s
                AND pm_chain.meta_value   = %d
                AND pm_kind.meta_key      = %s
                AND pm_kind.meta_value    = %s
                AND pm_validator.meta_key = %s
                AND pm_validator.meta_value = %d
              LIMIT 1",
            self::META_CHAIN_ID,
            $chainId,
            self::META_KIND,
            self::KIND_DELEGATORS,
            self::META_VALIDATOR_ID,
            $validatorId
        ));

        return $row !== null ? (int) $row : null;
    }

    /**
     * Read the gate config for a group. Returns null if not a delegator
     * community. Uses WP's post-meta cache (call update_meta_cache for
     * batches first).
     */
    public static function getGateConfig(int $groupId): ?ValidatorGatedGroupConfig {
        if ($groupId <= 0) {
            return null;
        }

        $kind = (string) get_post_meta($groupId, self::META_KIND, true);
        if ($kind !== self::KIND_DELEGATORS) {
            return null;
        }

        $chainId     = (int) get_post_meta($groupId, self::META_CHAIN_ID, true);
        $validatorId = (int) get_post_meta($groupId, self::META_VALIDATOR_ID, true);
        $operator    = (string) get_post_meta($groupId, self::META_OPERATOR, true);
        if ($chainId <= 0 || $validatorId <= 0 || $operator === '') {
            return null;
        }

        $minStake = (float) get_post_meta($groupId, self::META_MIN_STAKE, true);
        if ($minStake <= 0.0) {
            $minStake = 1.0;
        }

        return new ValidatorGatedGroupConfig(
            $groupId,
            $chainId,
            $validatorId,
            strtolower($operator),
            $minStake
        );
    }

    /**
     * All delegator communities, hydrated to ValidatorGatedGroupConfig.
     * One SELECT for IDs, one update_meta_cache, then per-group hydration
     * off the warm cache.
     *
     * @return list<ValidatorGatedGroupConfig>
     */
    public static function listAllValidatorGroupConfigs(int $limit = 500): array {
        $ids = self::listAllValidatorGroupIds($limit);
        if ($ids === []) {
            return [];
        }

        return array_values(self::findManyByGroupIds($ids));
    }

    /**
     * Bulk-fetch ValidatorGatedGroupConfig for a caller-supplied set of
     * group_ids. One `update_meta_cache` warms post_meta for the whole
     * batch; each subsequent `getGateConfig` call hits the warm cache
     * instead of the DB. Non-delegator group_ids are silently dropped.
     *
     * @param int[] $groupIds
     * @return array<int, ValidatorGatedGroupConfig> map keyed by group_id
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
     * Bounded (§4): `LIMIT %d`, unique-key meta filter.
     *
     * @return list<int>
     */
    public static function listAllValidatorGroupIds(int $limit = 500): array {
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
            self::KIND_DELEGATORS,
            'peepso-group',
            'publish',
            $limit
        ));

        return array_values(array_map('intval', $rows ?: []));
    }

    /**
     * Write the five gate post-meta keys (PeepSo group post must exist).
     *
     * @param int    $groupId         Existing peepso-group post ID.
     * @param int    $chainId         FK to onchain_chains.
     * @param int    $validatorId     FK to onchain_validators.id.
     * @param string $operatorAddress Will be lowercased.
     * @param string $minStake        Display-unit threshold, default '1'.
     */
    public static function writeGateConfig(
        int $groupId,
        int $chainId,
        int $validatorId,
        string $operatorAddress,
        string $minStake = '1'
    ): void {
        if ((float) $minStake <= 0.0) {
            $minStake = '1';
        }
        update_post_meta($groupId, self::META_KIND,         self::KIND_DELEGATORS);
        update_post_meta($groupId, self::META_CHAIN_ID,     $chainId);
        update_post_meta($groupId, self::META_VALIDATOR_ID, $validatorId);
        update_post_meta($groupId, self::META_OPERATOR,     strtolower($operatorAddress));
        update_post_meta($groupId, self::META_MIN_STAKE,    $minStake);
    }
}
