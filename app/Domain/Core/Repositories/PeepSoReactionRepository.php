<?php
/**
 * PeepSoReactionRepository — read-side access to peepso_reactions.
 *
 * Owns BCC's queries against PeepSo's reaction table. PeepSo writes
 * reactions when users click them in its UI; we never write here
 * (single-graph rule, mirroring our PeepSoFollowerRepository read-only
 * pattern).
 *
 * Backing table: {prefix}peepso_reactions
 *   reaction_id, reaction_user_id, reaction_act_id, reaction_type,
 *   reaction_timestamp
 *
 * `reaction_type` is the post.ID of a peepso_reaction_user CPT entry
 * — for BCC reactions, the IDs are persisted in bcc_options via
 * ReactionTypeRegistry.
 *
 * @package BCC\Trust\Core\Repositories
 * @since V1 (2026-04, §D5 reactions)
 */

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoReactionRepository
{
    private const TABLE_SUFFIX = 'peepso_reactions';

    private static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    private static function activitiesTable(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'peepso_activities';
    }

    /**
     * Lifetime count of a given reaction kind GIVEN by a user
     * (`reaction_user_id = $userId`).
     */
    public function countGivenByUser(int $userId, int $reactionTypeId): int
    {
        if ($userId <= 0 || $reactionTypeId <= 0) {
            return 0;
        }

        global $wpdb;
        $table = self::table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE reaction_user_id = %d
                AND reaction_type    = %d",
            $userId,
            $reactionTypeId
        ));
    }

    /**
     * Lifetime count of a given reaction kind RECEIVED by a user —
     * reactions placed on activities owned by the user
     * (`peepso_activities.act_owner_id = $ownerId`).
     */
    public function countReceivedByUser(int $ownerId, int $reactionTypeId): int
    {
        if ($ownerId <= 0 || $reactionTypeId <= 0) {
            return 0;
        }

        global $wpdb;
        $reactions  = self::table();
        $activities = self::activitiesTable();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$reactions} r
                INNER JOIN {$activities} a ON a.act_id = r.reaction_act_id
              WHERE a.act_owner_id  = %d
                AND r.reaction_type = %d",
            $ownerId,
            $reactionTypeId
        ));
    }

    /**
     * Reactions of a given kind RECEIVED since a MySQL DATETIME
     * boundary. Used by the §O3 living header for today's
     * solids_received count.
     */
    public function countReceivedByUserSince(
        int $ownerId,
        int $reactionTypeId,
        string $sinceMysql
    ): int {
        if ($ownerId <= 0 || $reactionTypeId <= 0 || $sinceMysql === '') {
            return 0;
        }

        global $wpdb;
        $reactions  = self::table();
        $activities = self::activitiesTable();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$reactions} r
                INNER JOIN {$activities} a ON a.act_id = r.reaction_act_id
              WHERE a.act_owner_id      = %d
                AND r.reaction_type     = %d
                AND r.reaction_timestamp >= %s",
            $ownerId,
            $reactionTypeId,
            $sinceMysql
        ));
    }

    /**
     * Counts grouped by reaction_type for a given activity. Used by
     * the post-mutation response on /reactions to return the freshly-
     * updated count map without a second roundtrip.
     *
     * Bounded by the activity's reactions; PeepSo's `reaction_act_id`
     * key keeps this O(reactions on this act).
     *
     * @return array<int, int> reaction_type_id → count
     */
    public function countsByActId(int $actId): array
    {
        if ($actId <= 0) {
            return [];
        }

        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT reaction_type, COUNT(*) AS reaction_count
               FROM {$table}
              WHERE reaction_act_id = %d
              GROUP BY reaction_type",
            $actId
        ));

        $out = [];
        foreach ($rows as $row) {
            $typeId = (int) $row->reaction_type;
            $count  = (int) $row->reaction_count;
            if ($typeId > 0) {
                $out[$typeId] = $count;
            }
        }
        return $out;
    }

    /**
     * Batched form of countsByActId for feed-page hydration. Groups
     * counts by (reaction_act_id, reaction_type) in one round trip
     * across the entire feed page (cap 50 items, so the IN() list
     * stays bounded).
     *
     * Returns nested arrays keyed by act_id at the outer level and
     * reaction_type_id at the inner level. Activities with zero
     * reactions are simply absent from the outer map — callers fall
     * back to an empty inner array per item.
     *
     * @param list<int> $actIds
     * @return array<int, array<int, int>> act_id → (type_id → count)
     */
    public function countsByActIds(array $actIds): array
    {
        if ($actIds === []) {
            return [];
        }

        // Sanitize + dedupe + bound. Feed page cap is 50; defending
        // here keeps the SQL safe even if a future caller sends a
        // larger list.
        $ids = [];
        foreach ($actIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $idList = implode(',', array_keys($ids));

        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results(
            "SELECT reaction_act_id, reaction_type, COUNT(*) AS reaction_count
               FROM {$table}
              WHERE reaction_act_id IN ({$idList})
              GROUP BY reaction_act_id, reaction_type"
        );

        $out = [];
        foreach ($rows as $row) {
            $actId  = (int) $row->reaction_act_id;
            $typeId = (int) $row->reaction_type;
            $count  = (int) $row->reaction_count;
            if ($actId <= 0 || $typeId <= 0) {
                continue;
            }
            if (!isset($out[$actId])) {
                $out[$actId] = [];
            }
            $out[$actId][$typeId] = $count;
        }
        return $out;
    }

    /**
     * Batched form of viewerReactionForActId. Returns the viewer's
     * current reaction_type for each activity in the input list (only
     * activities the viewer has reacted to appear in the result).
     *
     * @param list<int> $actIds
     * @return array<int, int> act_id → type_id
     */
    public function viewerReactionsByActIds(int $viewerId, array $actIds): array
    {
        if ($viewerId <= 0 || $actIds === []) {
            return [];
        }

        $ids = [];
        foreach ($actIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $idList = implode(',', array_keys($ids));

        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT reaction_act_id, reaction_type
               FROM {$table}
              WHERE reaction_user_id = %d
                AND reaction_act_id IN ({$idList})",
            $viewerId
        ));

        $out = [];
        foreach ($rows as $row) {
            $actId  = (int) $row->reaction_act_id;
            $typeId = (int) $row->reaction_type;
            if ($actId > 0 && $typeId > 0) {
                $out[$actId] = $typeId;
            }
        }
        return $out;
    }

    /**
     * The reaction_type a viewer currently has on the given activity,
     * or null when they haven't reacted. Single-row lookup keyed on
     * the unique (reaction_act_id, reaction_user_id) pair.
     */
    public function viewerReactionForActId(int $actId, int $viewerId): ?int
    {
        if ($actId <= 0 || $viewerId <= 0) {
            return null;
        }

        global $wpdb;
        $table = self::table();

        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT reaction_type FROM {$table}
              WHERE reaction_act_id  = %d
                AND reaction_user_id = %d
              LIMIT 1",
            $actId,
            $viewerId
        ));

        if ($value === null) {
            return null;
        }
        $typeId = (int) $value;
        return $typeId > 0 ? $typeId : null;
    }
}
