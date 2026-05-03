<?php
/**
 * PeepSo Activity Writer — BCC-owned write path into peepso_activities.
 *
 * The cross-plugin write is necessary by design: the §A3 plan says
 * "Activity-stream writer translates events into peepso_activities
 * rows" — BCC owns the act rows for its own modules (pull_batch,
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
     * Whitelist of act_module_id values BCC is allowed to insert.
     * Mirrors FeedItemNormalizer's module map for BCC-owned kinds.
     *
     * Per §C2 the wall column is BCC's only insertion path for these
     * modules; PeepSo never emits them itself, so there's no risk of
     * collision with PeepSo's own writers.
     *
     * @var list<string>
     */
    private const BCC_OWNED_MODULES = ['pull_batch', 'page_claim', 'review', 'blog'];

    /**
     * Public read-only access for callers that want to validate a
     * module name before calling insert().
     *
     * @return list<string>
     */
    public static function ownedModules(): array
    {
        return self::BCC_OWNED_MODULES;
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
    public function insert(
        int $actorUserId,
        int $ownerUserId,
        string $moduleId,
        int $externalId
    ): int {
        if ($actorUserId <= 0 || $ownerUserId <= 0 || $externalId <= 0) {
            return 0;
        }
        if (!in_array($moduleId, self::BCC_OWNED_MODULES, true)) {
            return 0;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'peepso_activities';

        $result = $wpdb->insert(
            $table,
            [
                'act_owner_id'    => $ownerUserId,
                'act_module_id'   => $moduleId,
                'act_external_id' => $externalId,
                // act_access: 0 = public per PeepSo's convention.
                // Per-user privacy filtering happens downstream
                // (PeepSo's visibility filters + our shadow-limit).
                'act_access'      => 0,
            ],
            ['%d', '%s', '%d', '%d']
        );

        if ($result === false) {
            return 0;
        }
        return (int) $wpdb->insert_id;
    }
}
