<?php
/**
 * Post Shortcodes Table Schema
 *
 * Sidecar table mapping peepso_activities rows to the 8-letter public
 * shortcode used in post permalinks (`/u/{handle}/post/{shortcode}`).
 *
 * PK is act_id (one shortcode per activity, forever); short_id carries
 * its own UNIQUE key so the resolver (`GET /feed/{code}`) is an index
 * lookup. Codes are assigned lazily on first feed emission
 * (PostShortcodeRepository::ensureForActIds) — this schema file also
 * runs a one-time, option-guarded backfill for pre-existing dev rows
 * (production starts empty).
 *
 * Why a sidecar (not a column on peepso_activities):
 *   - PeepSo owns peepso_activities; BCC must not alter its schema
 *   - append-only rows (a shortcode is never rewritten) keep the table
 *     trivial to reason about
 *
 * Fields:
 *   - act_id     BIGINT(20) UNSIGNED PK — peepso_activities.act_id
 *   - short_id   CHAR(8) — exactly 8 ASCII letters [a-zA-Z]; letters-only
 *                keeps the code space disjoint from the numeric
 *                /feed/{id} route (see PostShortcodeRepository::generate)
 *   - created_at DATETIME NOT NULL — UTC via gmdate(), NEVER a DB
 *                default (MySQL session time_zone is SYSTEM/UTC−5 on
 *                Local while WP writes UTC — the clock-split norm says
 *                a feature's writes+reads must share one clock, so the
 *                writer supplies the timestamp explicitly)
 *
 * @package BCC_Trust
 * @subpackage Database
 * @since 1.2.23 (post-shortcode permalinks)
 */

if (!defined('ABSPATH')) {
    exit;
}

function bcc_trust_create_post_shortcodes_table(): void {
    global $wpdb;

    $table   = $wpdb->prefix . 'bcc_post_shortcodes';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        act_id BIGINT(20) UNSIGNED NOT NULL,
        short_id CHAR(8) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (act_id),
        UNIQUE KEY short_id (short_id)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    \BCC\Core\Log\Logger::info('[bcc-trust] Post shortcodes table installed', []);

    // One-time dev-DB backfill — production launches with an empty
    // table and codes mint lazily on feed emission. Option-guarded
    // exactly like the attestation-order backfill in
    // schema-trust-attestations.php.
    if (!get_option('bcc_trust_post_shortcodes_backfilled')) {
        bcc_trust_backfill_post_shortcodes();
        update_option('bcc_trust_post_shortcodes_backfilled', time(), false);
    }
}

/**
 * Backfill a shortcode for every top-level published activity that has
 * none. "Top-level" = act_comment_object_id is 0/NULL (comments never
 * get permalinks); "published" = the backing wp_post is post_status =
 * 'publish' (drafts surface nowhere, so they mint lazily on publish).
 *
 * Batched (500/iteration, matching PostShortcodeRepository's bounded
 * IN-list cap) with a hard iteration cap so a pathological DB can't
 * wedge the migration request. Local dev is ~160 rows → one batch.
 *
 * Code generation + INSERT go through PostShortcodeRepository::
 * ensureForActIds — the single writer — so the backfill can never
 * drift from the lazy-mint path (§11: no parallel implementations).
 */
function bcc_trust_backfill_post_shortcodes(): void {
    global $wpdb;

    $sidecar    = \BCC\Trust\Core\Database\TableRegistry::postShortcodes();
    $activities = $wpdb->prefix . 'peepso_activities';

    // PeepSo optional — no activities table means nothing to backfill.
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $activities)) !== $activities) {
        return;
    }

    $batchSize     = 500; // == PostShortcodeRepository bounded-IN cap
    $maxIterations = 40;  // hard cap: 20k rows per migration request
    $assigned      = 0;

    for ($i = 0; $i < $maxIterations; $i++) {
        /** @var list<int|string> $rawIds */
        $rawIds = $wpdb->get_col($wpdb->prepare(
            "SELECT a.act_id
               FROM {$activities} a
              INNER JOIN {$wpdb->posts} p ON p.ID = a.act_external_id
               LEFT JOIN {$sidecar} s ON s.act_id = a.act_id
              WHERE p.post_status = 'publish'
                AND (a.act_comment_object_id = 0 OR a.act_comment_object_id IS NULL)
                AND s.act_id IS NULL
              ORDER BY a.act_id ASC
              LIMIT %d",
            $batchSize
        ));

        $actIds = [];
        foreach ($rawIds as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $actIds[] = $id;
            }
        }
        if ($actIds === []) {
            break;
        }

        $repo = new \BCC\Trust\Core\Repositories\PostShortcodeRepository();
        $map  = $repo->ensureForActIds($actIds);
        $assigned += count($map);

        // No-progress guard: if the writer couldn't cover the batch
        // (exhausted retries / table vanished mid-run), bail instead of
        // re-selecting the same rows forever.
        if (count($map) < count($actIds)) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] post-shortcode backfill made partial progress; stopping', [
                'requested' => count($actIds),
                'assigned'  => count($map),
            ]);
            break;
        }

        if (count($actIds) < $batchSize) {
            break; // final partial batch — done
        }
    }

    \BCC\Core\Log\Logger::info('[bcc-trust] Post shortcode backfill complete', [
        'assigned' => $assigned,
    ]);
}
