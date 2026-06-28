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
     * Versioned one-time-setup flag. When set to '1', `seed()`
     * short-circuits. Bumping the suffix (e.g. v2) forces re-run after a
     * contract change.
     *
     * NOTE: PeepSo reaction/activity index creation moved to
     * includes/database/peepso-reaction-indexes.php (§1 remediation) and is
     * guarded by its own option flag, independent of this one.
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
     * Trust grammar — solid only (stand_behind retired in Slice 3; vouch
     * relocated to the first-class per-author byline toggle and is no
     * longer a post reaction). Social grammar — fire — is the v1.5 single
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
     * One-time setup entry point. Runs the CPT seeding, then persists the
     * SETUP_FLAG so subsequent calls short-circuit.
     *
     * The peepso_reactions / peepso_activities covering indexes are created
     * separately by includes/database/peepso-reaction-indexes.php (§1
     * remediation: DDL belongs in includes/database/, not a service).
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
}
