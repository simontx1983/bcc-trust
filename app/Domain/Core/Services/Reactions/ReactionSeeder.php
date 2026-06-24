<?php
/**
 * ReactionSeeder — one-time install of the BCC custom reactions.
 *
 * Seeds the BCC-owned peepso_reaction_user CPT posts (trust: solid,
 * vouch; social: fire) at plugin activation and stores the resolved
 * numeric reaction_type IDs in BCC options. (stand_behind was retired
 * in Slice 3.)
 *
 * Idempotent: re-running is safe. The seeder checks the persisted
 * id map first; missing kinds get inserted, present kinds are
 * left alone.
 *
 * Icons: post_excerpt holds the SVG filename PeepSo's renderer reads
 * (relative to peepso/assets/images/svg/). V1 uses placeholder
 * filenames — real artwork lands separately. PeepSo falls back
 * gracefully on missing files (no crash, just default rendering).
 *
 * Reaction order: menu_order = 100+ so the BCC reactions slot in
 * AFTER PeepSo's built-in reactions (typically menu_order 1–10).
 * Final visual ordering is up to the admin UI; this is just the
 * default.
 *
 * @package BCC\Trust\Core\Services\Reactions
 * @since V1 (2026-04, §D5 reactions)
 */

namespace BCC\Trust\Core\Services\Reactions;

use BCC\Trust\Core\Support\ReactionTypeRegistry;

if (!defined('ABSPATH')) {
    exit;
}

final class ReactionSeeder
{
    /** post_type slug for PeepSo custom reactions. */
    private const REACTION_CPT = 'peepso_reaction_user';

    /**
     * Versioned one-time-setup flag. When set to '1', `seed()` and
     * `ensureIndexes()` short-circuit. Bumping the suffix (e.g. v2)
     * forces re-run after a contract change.
     *
     * v2 (v1.5): added the social-grammar Fire reaction. Bumping the
     * suffix re-runs the seeder on existing installs; the per-kind
     * skip check inside seedReactions() means the trust three are
     * left intact and only Fire is inserted.
     */
    private const SETUP_FLAG = 'bcc_reactions_seeded_v2';

    /**
     * Spec for BCC-seeded reactions. Order here is also the default
     * menu_order priority within the BCC block (offset by BASE_ORDER).
     *
     * Helper labels (the §N1 plain-English descriptors) live on the
     * frontend; the post_title here is the brand-name (Solid, etc.).
     *
     * Trust grammar — solid/vouch (stand_behind retired in Slice 3).
     * Social grammar — fire — is the v1.5 single
     * BCC-owned addition (PeepSo's defaults cover like/love/haha/wow,
     * but no Fire); ReactionGrammarRegistry resolves the rest of the
     * social set from PeepSo's seeded posts at lookup time.
     *
     * @var list<array{kind: string, title: string, helper: string, icon: string, content: string}>
     */
    private const REACTION_SPECS = [
        [
            'kind'    => ReactionTypeRegistry::KIND_SOLID,
            'title'   => 'Solid',
            'helper'  => 'Agree',
            'icon'    => 'bcc-reaction-solid.svg',
            'content' => 'Agree — a basic acknowledgment of the post.',
        ],
        [
            'kind'    => ReactionTypeRegistry::KIND_VOUCH,
            'title'   => 'Vouch',
            'helper'  => 'Back this',
            'icon'    => 'bcc-reaction-vouch.svg',
            'content' => 'Back this — public endorsement of the post.',
        ],
        [
            'kind'    => ReactionTypeRegistry::KIND_FIRE,
            'title'   => 'Fire',
            'helper'  => 'Hot',
            'icon'    => 'bcc-reaction-fire.svg',
            'content' => 'Hot — energy / hype / appreciation on social-grammar posts.',
        ],
    ];

    /** menu_order baseline — sits after PeepSo's built-in reactions. */
    private const BASE_ORDER = 100;

    /**
     * One-time setup entry point. Runs the CPT seeding + the
     * peepso_reactions / peepso_activities index creation, then
     * persists the SETUP_FLAG so subsequent calls short-circuit.
     *
     * Safe to call repeatedly: the flag check is the first line.
     *
     * @return array<string, int> kind → post.ID after run (or current map if already setup)
     */
    public function seed(): array
    {
        if (get_option(self::SETUP_FLAG) === '1') {
            // Already done. Return the current resolved map for callers
            // that want it (e.g. the test harness after a setup call).
            $current = [];
            foreach (ReactionTypeRegistry::all() as $kind => $id) {
                if ($id !== null) {
                    $current[$kind] = $id;
                }
            }
            return $current;
        }

        $map = $this->seedReactions();
        $this->ensureIndexes();

        // Mark setup complete only after both subtasks succeeded
        // (best-effort — a failing index creation shouldn't keep us
        // re-running on every request, but it does mean re-seeding
        // requires bumping SETUP_FLAG).
        update_option(self::SETUP_FLAG, '1', false);

        return $map;
    }

    /**
     * @return array<string, int>
     */
    private function seedReactions(): array
    {
        $existing = ReactionTypeRegistry::all();
        $map = [];
        foreach ($existing as $kind => $id) {
            if ($id !== null) {
                $map[$kind] = $id;
            }
        }

        foreach (self::REACTION_SPECS as $i => $spec) {
            if (isset($map[$spec['kind']])) {
                continue;
            }

            $postId = wp_insert_post([
                'post_type'    => self::REACTION_CPT,
                'post_title'   => $spec['title'],
                'post_content' => $spec['content'],
                // post_excerpt = icon filename, per PeepSoReactionsModel's read path
                'post_excerpt' => $spec['icon'],
                'post_status'  => 'publish',
                'menu_order'   => self::BASE_ORDER + $i,
            ], true);

            if (is_wp_error($postId) || !is_int($postId) || $postId <= 0) {
                \BCC\Core\Log\Logger::error('[ReactionSeeder] failed to insert reaction', [
                    'kind'  => $spec['kind'],
                    'error' => is_wp_error($postId) ? $postId->get_error_message() : 'invalid id',
                ]);
                continue;
            }

            // Helper label as post-meta — frontend reads this for the
            // §N1 dual-label display until the user is "familiar."
            update_post_meta($postId, 'bcc_helper_label', $spec['helper']);

            $map[$spec['kind']] = $postId;

            \BCC\Core\Log\Logger::info('[ReactionSeeder] reaction seeded', [
                'kind'    => $spec['kind'],
                'post_id' => $postId,
            ]);
        }

        if ($map !== []) {
            ReactionTypeRegistry::persist($map);
        }

        return $map;
    }

    /**
     * Ensure the indexes our reaction-aggregation queries depend on.
     * These live on PeepSo's tables — risky territory, but the indexes
     * are additive (CREATE INDEX with pre-check) and unblock realistic
     * read load on `solids_received` and per-time-window queries.
     *
     * If PeepSo drops these on upgrade, bumping SETUP_FLAG re-creates them.
     */
    private function ensureIndexes(): void
    {
        global $wpdb;

        // peepso_reactions covering index for "solids given by user"
        // — covers WHERE reaction_user_id = ? AND reaction_type = ?
        self::createIndexIfMissing(
            $wpdb->prefix . 'peepso_reactions',
            'idx_bcc_reactions_user_type',
            'reaction_user_id, reaction_type'
        );

        // peepso_reactions covering index for "solids received since"
        // — covers JOIN by reaction_act_id + filter on reaction_type
        // + reaction_timestamp boundary.
        self::createIndexIfMissing(
            $wpdb->prefix . 'peepso_reactions',
            'idx_bcc_reactions_act_type_time',
            'reaction_act_id, reaction_type, reaction_timestamp'
        );

        // peepso_activities — speeds up "received" query's JOIN target
        // (the act_owner_id filter). PeepSo's default schema has indexes
        // on act_user_id but not always act_owner_id; covering this
        // explicitly avoids the table scan when filtering by content owner.
        self::createIndexIfMissing(
            $wpdb->prefix . 'peepso_activities',
            'idx_bcc_activities_owner',
            'act_owner_id'
        );
    }

    private static function createIndexIfMissing(string $table, string $indexName, string $columns): void
    {
        global $wpdb;

        // SHOW INDEX FROM is the canonical pre-check. Table name comes
        // from $wpdb->prefix + a hard-coded suffix — no user input,
        // safe to interpolate.
        $existing = $wpdb->get_results($wpdb->prepare(
            "SHOW INDEX FROM `{$table}` WHERE Key_name = %s",
            $indexName
        ));

        if (!empty($existing)) {
            return;
        }

        // CREATE INDEX doesn't accept prepared placeholders for the
        // identifier; columns are hard-coded by caller. No user input.
        $result = $wpdb->query("CREATE INDEX `{$indexName}` ON `{$table}` ({$columns})");

        if ($result === false) {
            \BCC\Core\Log\Logger::error('[ReactionSeeder] failed to create index', [
                'table' => $table,
                'index' => $indexName,
                'error' => $wpdb->last_error,
            ]);
            return;
        }

        \BCC\Core\Log\Logger::info('[ReactionSeeder] index created', [
            'table' => $table,
            'index' => $indexName,
        ]);
    }
}
