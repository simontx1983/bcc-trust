<?php
/**
 * PeepSo Activity Writer — BCC-owned write path into peepso_activities.
 *
 * The cross-plugin write is necessary by design: the §A3 plan says
 * "Activity-stream writer translates events into peepso_activities
 * rows" — BCC owns the act rows for its own modules (watch_batch,
 * page_claim, …). PeepSo's own modules continue writing through their
 * own paths; we only insert rows for BCC-owned act_module_id values.
 *
 * Read-side lives in bcc-core's PeepSoActivityRepository — reads are
 * intentionally separated from writes so an attempt to UPDATE/DELETE
 * here would have to come through a different repository (BCC never
 * mutates PeepSo's own activity rows).
 *
 * Module column rule: this writer MUST refuse any module value not
 * registered in BCC_OWNED_MODULES — prevents accidental injection
 * into PeepSo-native module spaces (status, comment, friend_added,
 * …) that PeepSo itself manages.
 *
 * @package BCC\Trust\Core\Repositories
 * @since V1 (2026-04, Binder Phase 4)
 */

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoActivityWriter
{
    /**
     * BCC-owned module name → numeric `act_module_id`.
     *
     * The `peepso_activities.act_module_id` column is SMALLINT (PeepSo
     * owns the schema), so any attempt to INSERT a string like 'blog'
     * coerces to 0. That coercion broke kind discrimination on the
     * read side — FeedItemNormalizer's `MODULE_TO_KIND['0']` is
     * undefined, so every BCC-owned row fell through to 'status'.
     *
     * Fix (2026-05-15): every BCC-owned module gets a stable numeric
     * id in the 200-range, comfortably outside PeepSo's known module
     * ids (1 status, 4 photo, 6 messages, 9 pages, 30 polls,
     * 111 backgrounds, 6661 peepso-blog). The writer translates the
     * string name to the integer id before INSERT; the normalizer
     * reads integer keys. New rows render with the correct
     * post_kind; existing pre-fix rows (act_module_id=0) keep
     * rendering as 'status' until a backfill (post-V1).
     *
     * Adding a new BCC-owned module requires:
     *   1. Add an entry here with the next available integer.
     *   2. Add the matching integer key to
     *      `FeedItemNormalizer::MODULE_TO_KIND`.
     *
     * @var array<string, int>
     */
    private const MODULE_ID_BY_NAME = [
        'watch_batch' => 200,
        'page_claim' => 201,
        'review'     => 202,
        'dispute'    => 203,
        'blog'       => 204,
    ];

    /**
     * Public read-only access for callers that want to validate a
     * module name before calling insert().
     *
     * @return list<string>
     */
    public static function ownedModules(): array
    {
        return array_keys(self::MODULE_ID_BY_NAME);
    }

    /**
     * Resolve a BCC-owned module name to its numeric `act_module_id`.
     *
     * Returns null when the name isn't in the whitelist — callers
     * should treat that as "skip" (the row probably belongs to PeepSo
     * or a future module not yet mapped here).
     *
     * Used by the 2026-05-16 backfill CLI to translate legacy
     * meta-stored module names ('blog', 'review', …) back to the
     * canonical integer that `MODULE_ID_BY_NAME` now stamps.
     */
    public static function getNumericId(string $name): ?int
    {
        return self::MODULE_ID_BY_NAME[$name] ?? null;
    }

    /**
     * Insert a BCC-owned activity row.
     *
     * Returns the new act_id on success, or 0 on:
     *   - module not in BCC_OWNED_MODULES (refused)
     *   - invalid owner / external IDs
     *   - underlying DB error
     *
     * `$externalId` MUST be a wp_posts.ID — the read path INNER-JOINs
     * wp_posts on `act_external_id` to derive the actor (post_author),
     * timestamp (post_date_gmt), and active state (post_status). For
     * BCC modules, `ActivityStreamWriter` creates a backing wp_post
     * (post_type = 'peepso-activity-status') and stores the sidecar
     * row id in post_meta — see that file for the canonical flow.
     *
     * peepso_activities columns written: act_owner_id, act_module_id,
     * act_external_id, act_access. The historical schema BCC was
     * written against (act_user_id, act_time, act_status) does NOT
     * exist in PeepSo's actual table — those fields live on wp_posts
     * and are read via JOIN.
     *
     * `$actorUserId` is kept on the signature for caller compatibility;
     * the value is recorded on the wp_post (post_author) the caller
     * created before invoking this writer, not in this row.
     */
    /**
     * Resolve the existing act_id for a (module, externalId) pair, if any.
     *
     * Used by ActivityStreamWriter to make handlers idempotent — a
     * second dispatch of `bcc_blog_post_created` for an already-
     * surfaced post returns the existing act_id rather than inserting
     * a duplicate row.
     *
     * Bounded LIMIT 1; matches by the literal moduleId string we wrote
     * on insert (BCC-owned modules use string keys: 'blog', 'review',
     * 'page_claim', 'watch_batch').
     *
     * Returns 0 when no row matches.
     */
    public function findActIdForExternal(string $moduleId, int $externalId): int
    {
        if ($externalId <= 0 || !isset(self::MODULE_ID_BY_NAME[$moduleId])) {
            return 0;
        }

        $numericModuleId = self::MODULE_ID_BY_NAME[$moduleId];

        global $wpdb;
        $table = $wpdb->prefix . 'peepso_activities';

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT act_id FROM {$table}
              WHERE act_external_id = %d
                AND act_module_id   = %d
              LIMIT 1",
            $externalId,
            $numericModuleId
        ));
    }

    /**
     * Delete a BCC-owned activity row by act_id. Used by the §D6
     * crypto-blog composer's publish→draft transition to stop
     * surfacing an un-published post in feeds.
     *
     * Bounded: act_id is the PK. Returns true when a row was deleted,
     * false when none existed (idempotent no-op for the caller).
     *
     * SECURITY: Caller MUST ensure the row is BCC-owned before
     * invoking — there's no WHERE clause filtering on module here, so
     * a wrong caller could (theoretically) delete a PeepSo-native row.
     * In practice BlogStatusTransitionHandler resolves the act_id via
     * findActIdForExternal($moduleId, $externalId), which gates on the
     * module whitelist. Adding a defensive subquery here would be
     * cheap insurance — keeping it as-is because the only call site
     * is BCC-owned and the gate is in findActIdForExternal.
     */
    public function deleteByActId(int $actId): bool
    {
        if ($actId <= 0) {
            return false;
        }
        global $wpdb;
        $table = $wpdb->prefix . 'peepso_activities';

        $rows = $wpdb->delete($table, ['act_id' => $actId], ['%d']);
        return is_int($rows) && $rows > 0;
    }

    /**
     * Backfill `act_module_id = 0` rows that were string-coerced by
     * the pre-2026-05-15 writer.
     *
     * The legacy writer wrote module names ('blog', 'review', …) as
     * strings into the SMALLINT column. MySQL silently coerced them
     * to 0, losing the discriminator. The hot-path read side
     * ({@see FeedItemNormalizer::MODULE_TO_KIND}) then fell through
     * to 'status' for every affected row.
     *
     * Recovery path: every BCC-owned activity row also carries the
     * canonical module name on the backing wp_post as the
     * `_bcc_activity_module` post_meta key
     * ({@see ActivityStreamWriter::META_MODULE}). We JOIN against
     * that meta to recover the intended module name, translate it
     * to the canonical integer via {@see getNumericId}, and UPDATE
     * the row in place. Bounded by `$maxRows` per invocation;
     * idempotent (re-running after a successful pass is a no-op
     * because no `act_module_id=0` rows remain that match the
     * meta join).
     *
     * Returns a `{module_name => rows_updated}` map for the caller
     * to surface in the CLI output.
     *
     * §1: stays inside this Repository (only $wpdb access lives here).
     * §2: explicit SELECT projection.
     * §4: SELECT is bounded by `LIMIT $maxRows`; the UPDATE is
     *     per-row (one row per matched name) and bounded by the
     *     SELECT's row count.
     *
     * @return array<string, int>
     */
    public function backfillCoercedModuleIds(int $maxRows = 500): array
    {
        if ($maxRows <= 0) {
            return [];
        }
        $maxRows = min($maxRows, 5000);

        global $wpdb;
        $actTable  = $wpdb->prefix . 'peepso_activities';
        $metaTable = $wpdb->postmeta;

        // The `IN (...)` list is the canonical BCC-owned module
        // names. Bounded by the size of MODULE_ID_BY_NAME (5 entries
        // today) so the placeholder count is constant.
        $names = array_keys(self::MODULE_ID_BY_NAME);
        $namePlaceholders = implode(',', array_fill(0, count($names), '%s'));

        /** @var list<object{act_id: numeric-string, meta_value: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT a.act_id, pm.meta_value
               FROM {$actTable} a
               INNER JOIN {$metaTable} pm
                       ON pm.post_id = a.act_external_id
                      AND pm.meta_key = '_bcc_activity_module'
              WHERE a.act_module_id = 0
                AND pm.meta_value IN ({$namePlaceholders})
              ORDER BY a.act_id ASC
              LIMIT %d",
            ...array_merge($names, [$maxRows])
        ));

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        /** @var array<string, int> $counts */
        $counts = [];
        foreach ($rows as $row) {
            $name      = (string) $row->meta_value;
            $numericId = self::getNumericId($name);
            if ($numericId === null) {
                continue;
            }
            $result = $wpdb->update(
                $actTable,
                ['act_module_id' => $numericId],
                ['act_id'        => (int) $row->act_id],
                ['%d'],
                ['%d']
            );
            if ($result === false || $result === 0) {
                continue;
            }
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        return $counts;
    }

    public function insert(
        int $actorUserId,
        int $ownerUserId,
        string $moduleId,
        int $externalId
    ): int {
        if ($actorUserId <= 0 || $ownerUserId <= 0 || $externalId <= 0) {
            return 0;
        }
        if (!isset(self::MODULE_ID_BY_NAME[$moduleId])) {
            return 0;
        }

        $numericModuleId = self::MODULE_ID_BY_NAME[$moduleId];

        global $wpdb;
        $table = $wpdb->prefix . 'peepso_activities';

        $result = $wpdb->insert(
            $table,
            [
                'act_owner_id'    => $ownerUserId,
                'act_module_id'   => $numericModuleId,
                'act_external_id' => $externalId,
                // act_access: 0 = public per PeepSo's convention.
                // Per-user privacy filtering happens downstream
                // (PeepSo's visibility filters + our shadow-limit).
                'act_access'      => 0,
            ],
            ['%d', '%d', '%d', '%d']
        );

        if ($result === false) {
            return 0;
        }
        return (int) $wpdb->insert_id;
    }
}
