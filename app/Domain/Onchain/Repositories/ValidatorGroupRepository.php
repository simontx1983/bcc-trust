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
 *   _bcc_gate_min_stake         (display units, set by the page's operator)
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

    private const MAX_VALIDATOR_LOOKUP = 1600;

    /**
     * Reverse lookup: the delegator community of each validator that has one.
     * One query for any number of validators, so a page bound to several and a
     * grid of pages cost the same. Lowest group id wins if a validator ever
     * carries two, so every reader names the same one.
     *
     * Bounded (§4): the caller's id list is capped here and `LIMIT %d` matches
     * it, one row per validator.
     *
     * @param list<int> $validatorIds
     * @return array<int, int> validator_id => group_id
     */
    public static function findGroupIdsForValidators(array $validatorIds): array {
        $ids = [];
        foreach ($validatorIds as $id) {
            if ($id > 0) {
                $ids[(string) $id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $idList = array_slice(array_map('strval', array_keys($ids)), 0, self::MAX_VALIDATOR_LOOKUP);

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($idList), '%s'));

        // Only a published community counts, the same rule the revoke sweep
        // walks by: a trashed group must not block a new one with a 409 or
        // put a dead link on the card.
        /** @var list<object{validator_id: numeric-string, group_id: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm_validator.meta_value AS validator_id, MIN(pm_validator.post_id) AS group_id
               FROM {$wpdb->postmeta} pm_validator
         INNER JOIN {$wpdb->postmeta} pm_kind ON pm_kind.post_id = pm_validator.post_id
         INNER JOIN {$wpdb->posts} p ON p.ID = pm_validator.post_id
              WHERE pm_validator.meta_key = %s
                AND pm_validator.meta_value IN ({$placeholders})
                AND pm_kind.meta_key   = %s
                AND pm_kind.meta_value = %s
                AND p.post_type   = %s
                AND p.post_status = %s
              GROUP BY pm_validator.meta_value
              LIMIT %d",
            ...[self::META_VALIDATOR_ID, ...$idList, self::META_KIND, self::KIND_DELEGATORS, 'peepso-group', 'publish', count($idList)]
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[(int) $row->validator_id] = (int) $row->group_id;
        }
        return $out;
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
     * The stored minimum as a decimal string, for the wire. Same fallback as
     * {@see getGateConfig()}: a missing or unusable value is enforced as 1, so
     * it reads as 1. The gate compares against the parsed float; this keeps
     * what the operator typed free of float formatting.
     */
    public static function readMinStake(int $groupId): string {
        $raw = trim((string) get_post_meta($groupId, self::META_MIN_STAKE, true));

        return (is_numeric($raw) && (float) $raw > 0.0) ? $raw : '1';
    }

    /**
     * Replace the minimum on an existing community and prove it landed.
     *
     * `update_post_meta` is the one write path on purpose: it clears the post's
     * meta cache, and the join gate and revoke sweep both read through that
     * cache in {@see getGateConfig()}, so neither can see the old value after
     * this returns. Its own return value can't be the check, because it answers
     * false for an unchanged value as well as for a failure.
     */
    public static function writeMinStake(int $groupId, string $minStake): bool {
        update_post_meta($groupId, self::META_MIN_STAKE, $minStake);

        return self::minStakeIsStored($groupId, $minStake);
    }

    /**
     * Whether exactly this value is in the row. Reads the raw meta, not
     * {@see readMinStake()}, whose fallback to 1 would make a lost write of '1'
     * look like a success.
     */
    public static function minStakeIsStored(int $groupId, string $minStake): bool {
        return (string) get_post_meta($groupId, self::META_MIN_STAKE, true) === $minStake;
    }

    /**
     * Remove the gate from a community whose creation is being undone.
     */
    public static function deleteGateConfig(int $groupId): void {
        foreach ([
            self::META_KIND,
            self::META_CHAIN_ID,
            self::META_VALIDATOR_ID,
            self::META_OPERATOR,
            self::META_MIN_STAKE,
        ] as $key) {
            delete_post_meta($groupId, $key);
        }
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
     * Delegator communities with an id above $afterId, ascending. The revoke
     * sweep's walk: keyset, so it reaches every group however many there are,
     * and a group deleted between two calls can't make it skip or repeat one.
     *
     * Bounded (§4): `LIMIT %d`, unique-key meta filter.
     *
     * @return list<int>
     */
    public static function listValidatorGroupIdsAfter(int $afterId, int $limit): array {
        if ($limit <= 0) {
            return [];
        }

        global $wpdb;

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT pm.post_id
               FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key   = %s
                AND pm.meta_value = %s
                AND p.post_type   = %s
                AND p.post_status = %s
                AND p.ID > %d
              ORDER BY p.ID ASC
              LIMIT %d",
            self::META_KIND,
            self::KIND_DELEGATORS,
            'peepso-group',
            'publish',
            max(0, $afterId),
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
     * @param string $minStake        Display-unit threshold.
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
