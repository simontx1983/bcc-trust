<?php
/**
 * Marker storage for administrator-created Hall peepso-groups.
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
 * ...plus ONE repair marker, written only on the failure path:
 *   _bcc_hall_owner_incomplete = '1'
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

    /**
     * Repair marker — set when a Hall was created but its ownership could
     * NOT be moved to the intended deterministic owner.
     *
     * The Hall stays discoverable (so the create action remains idempotent
     * and cannot mint a duplicate); this key is what makes the inconsistency
     * VISIBLE instead of silent, and what the admin surface reads to offer a
     * retry. Absence is the healthy state, so nothing is written on the happy
     * path and no existing Hall needs a backfill.
     */
    public const META_OWNER_INCOMPLETE = '_bcc_hall_owner_incomplete';

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

    /**
     * Halls for MANY chains in one bounded query — the admin listing's
     * lookup, so rendering N chains costs one query rather than N.
     *
     * Bounded (§4): `IN (...)` over a caller-supplied, integer-cast and
     * length-capped id list, plus `LIMIT`. An empty input short-circuits
     * without touching the database.
     *
     * @param  list<int> $chainIds
     * @return array<int, int> chain id => Hall post id, absent when none
     */
    public static function findHallsForChains(array $chainIds): array {
        $ids = [];
        foreach ($chainIds as $chainId) {
            $chainId = (int) $chainId;
            if ($chainId > 0) {
                $ids[$chainId] = true;
            }
        }
        $ids = array_keys($ids);

        if ($ids === []) {
            return [];
        }

        // Cap the fan-out so a caller cannot turn this into an unbounded IN.
        $ids = array_slice($ids, 0, 500);

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        /** @var list<string|int> $args */
        $args = array_merge(
            [self::META_CHAIN_TAG],
            $ids,
            [self::META_KIND, self::KIND_HALL, 'peepso-group', 'publish', count($ids)]
        );

        // ⚠ GROUPED, NOT ORDERED-AND-CAPPED.
        //
        // One-Hall-per-chain is procedural — there is no unique index — so a
        // chain CAN carry two Halls. A flat `ORDER BY p.ID ASC LIMIT <n>` over
        // n chains would then spend rows on the duplicate and truncate a LATER
        // chain out of the result entirely. The admin page reads absence as
        // "this chain does not have a Hall" and offers Create, so that
        // truncation would invite an operator to create a SECOND Hall for a
        // chain that already has one — the exact thing this lookup exists to
        // prevent.
        //
        // Grouping collapses each chain to one row, so the LIMIT is exact, and
        // MIN() states the lowest-post-id tie-break in SQL rather than relying
        // on the order rows happen to arrive in. Aggregate + LIMIT, so still
        // bounded per §4.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pm_chain.meta_value AS chain_id, MIN(pm_chain.post_id) AS group_id
               FROM {$wpdb->postmeta} pm_chain
         INNER JOIN {$wpdb->postmeta} pm_kind ON pm_kind.post_id = pm_chain.post_id
         INNER JOIN {$wpdb->posts} p          ON p.ID = pm_chain.post_id
              WHERE pm_chain.meta_key   = %s
                AND pm_chain.meta_value IN ({$placeholders})
                AND pm_kind.meta_key    = %s
                AND pm_kind.meta_value  = %s
                AND p.post_type         = %s
                AND p.post_status       = %s
           GROUP BY pm_chain.meta_value
              LIMIT %d",
            ...$args
        ));

        $out = [];
        foreach (($rows ?: []) as $row) {
            $chainId = (int) $row->chain_id;
            $groupId = (int) $row->group_id;
            if ($chainId > 0 && $groupId > 0) {
                $out[$chainId] = $groupId;
            }
        }

        return $out;
    }

    /**
     * Mark a Hall whose ownership could not be reconciled.
     *
     * Deliberately a marker, not a message: the WHY belongs in the durable
     * audit row and the file log, both of which are bounded and redacted.
     */
    public static function markOwnerIncomplete(int $groupId): void {
        update_post_meta($groupId, self::META_OWNER_INCOMPLETE, '1');
    }

    /** Clear the repair marker once ownership has been reconciled. */
    public static function clearOwnerIncomplete(int $groupId): void {
        delete_post_meta($groupId, self::META_OWNER_INCOMPLETE);
    }

    public static function isOwnerIncomplete(int $groupId): bool {
        if ($groupId <= 0) {
            return false;
        }

        return get_post_meta($groupId, self::META_OWNER_INCOMPLETE, true) === '1';
    }
}
