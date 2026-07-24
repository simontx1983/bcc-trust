<?php
/**
 * Marker storage for auto-provisioned Hall peepso-groups.
 *
 * Simpler sibling of {@see GatedGroupRepository} / {@see ValidatorGroupRepository}
 * — a Hall carries NO gate config (open membership), just two post-meta
 * keys that mark it and tie it to a chain:
 *   _bcc_group_kind  = 'hall'
 *   _bcc_chain_tag   (numeric FK to onchain_chains) — the SAME key
 *                     user-created plain groups use, so
 *                     ChainRepository::resolveSlugsForGroups already
 *                     resolves a Hall's chain slug with no special-casing.
 *
 * One open Hall per chain; membership stays in PeepSo's
 * peepso_group_members (single-graph rule). No parallel ledger.
 *
 * @package BCC\Trust\Onchain\Repositories
 */

namespace BCC\Trust\Onchain\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class HallRepository {

    public const META_KIND      = '_bcc_group_kind';
    public const META_CHAIN_TAG = '_bcc_chain_tag';

    public const KIND_HALL = 'hall';

    /**
     * Reverse lookup: find the Hall for a chain. Returns the WP post ID
     * or null. Bounded LIMIT 1 — the provisioner's idempotency check.
     * Mirrors GatedGroupRepository::findGroupForCollection.
     */
    public static function findHallForChain(int $chainId): ?int {
        if ($chainId <= 0) {
            return null;
        }

        global $wpdb;

        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT pm_chain.post_id
               FROM {$wpdb->postmeta} pm_chain
          INNER JOIN {$wpdb->postmeta} pm_kind ON pm_kind.post_id = pm_chain.post_id
              WHERE pm_chain.meta_key   = %s
                AND pm_chain.meta_value = %d
                AND pm_kind.meta_key    = %s
                AND pm_kind.meta_value  = %s
              LIMIT 1",
            self::META_CHAIN_TAG,
            $chainId,
            self::META_KIND,
            self::KIND_HALL
        ));

        return $row !== null ? (int) $row : null;
    }

    /**
     * All Hall group IDs. Bounded (§4): `LIMIT %d`, meta filter.
     *
     * @return list<int>
     */
    public static function listAllHallIds(int $limit = 500): array {
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
              ORDER BY p.ID ASC
              LIMIT %d",
            self::META_KIND,
            self::KIND_HALL,
            'peepso-group',
            'publish',
            $limit
        ));

        return array_values(array_map('intval', $rows ?: []));
    }

    /**
     * Write the two marker post-meta keys (PeepSo group post must exist).
     * `_bcc_chain_tag` is written with add-if-absent semantics elsewhere
     * (user-created groups), but the provisioner owns the whole lifecycle
     * of a Hall so update_post_meta is safe + idempotent here.
     */
    public static function writeHallMeta(int $groupId, int $chainId): void {
        update_post_meta($groupId, self::META_KIND,      self::KIND_HALL);
        update_post_meta($groupId, self::META_CHAIN_TAG, $chainId);
    }
}
