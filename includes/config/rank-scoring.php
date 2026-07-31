<?php
/**
 * Rank redesign scoring configuration — single source of truth for every
 * tunable in the NEW Rank system (approved plan rev. 3 + addendum, C0/A4).
 *
 * Returns a structured ARRAY (no define(), no side effects) consumed
 * exclusively through the validated immutable value object
 * `BCC\Trust\Rank\Support\RankScoringConfig::fromArray()`. Services never
 * read this file directly and never read constants — they receive the VO
 * by constructor injection. There is deliberately NO apply_filters()
 * layer: spec-locked values must not be silently overridable by runtime
 * filters; calibration changes are reviewed edits to this file, exercised
 * by the config-invariant test suite (tests/Unit/RankScoringConfigTest.php).
 *
 * Boundary note: `includes/config/ranks.php` is the LEGACY Level/Rank
 * system's config and stays untouched until the Phase 5 atomic cutover
 * deletes it. Nothing in this file may be read by the legacy stack, and
 * nothing here restates a ranks.php value.
 *
 * Entries marked `spec-locked` are owner-approved product constants from
 * the Rank/Trust/Meaningful-Voting specification — mechanically editable,
 * but changing one requires an owner decision, not a calibration pass.
 * Entries marked `calibrate` carry proposed defaults re-confirmed at their
 * slice boundary (plan ⚖ items).
 *
 * @package BCC\Trust\Rank
 * @since Rank redesign Phase 1 (2026-07-31)
 */

if (!defined('ABSPATH')) {
    exit;
}

return [

    // ── Rank Score categories ────────────────────────────────────────
    // spec-locked — §4.1: five categories totalling exactly 100.
    'category_max' => [
        'contribution' => 25.0,
        'helping'      => 25.0,
        'recognition'  => 15.0,
        'outcomes'     => 15.0,
        'time'         => 20.0,
    ],

    // spec-locked — §4.2 promotion thresholds (0–100 Rank Score).
    'thresholds' => [
        'journeyman' => 40.0,
        'veteran'    => 80.0,
    ],

    // spec-locked — §4.3 mandatory category depth (30% / 60% of maxima).
    'category_minimums' => [
        'journeyman' => ['contribution' => 7.5,  'helping' => 7.5,  'recognition' => 4.5, 'outcomes' => 0.0, 'time' => 0.0],
        'veteran'    => ['contribution' => 15.0, 'helping' => 15.0, 'recognition' => 9.0, 'outcomes' => 9.0, 'time' => 0.0],
    ],

    // spec-locked — §4.4: one event's combined lifetime Rank contribution.
    'event_cap' => 2.0,

    // spec-locked — §8.2 / §9.3 / §10.3 share caps (fractions of the
    // owning category's score).
    'share_caps' => [
        'contribution_type' => 0.35,
        'helping_recipient' => 0.10,
        'recognizer'        => 0.20,
    ],

    // ── Meaningful-vote weight inputs ────────────────────────────────
    // spec-locked — §16.3 Apprentice vesting: floor at award, linear to
    // 1.0 over span_days, measured from apprentice_awarded_at (the
    // maturity epoch survives promotion — owner correction 3).
    'maturity' => [
        'floor'     => 0.075,
        'span_days' => 90,
    ],

    // spec-locked — §16.4. No other rungs exist; Master must never appear.
    'rank_multipliers' => [
        'apprentice' => 1.0,
        'journeyman' => 1.2,
        'veteran'    => 1.4,
    ],

    // spec-locked — §16.5 linear trust multiplier anchors.
    'trust_multiplier' => [
        'min_score' => 45.0,
        'min_value' => 0.75,
        'max_score' => 100.0,
        'max_value' => 1.25,
    ],

    // spec-locked — §16.7 absolute ordinary individual vote ceiling.
    'vote_ceiling' => 1.75,

    // calibrate — weight→trust-score scale replacing the legacy ×2
    // (keeps max score delta comparable under the 1.75 ceiling).
    'weight_to_score_scale' => 0.6,

    // ── Binding outcomes ─────────────────────────────────────────────
    // spec-locked — §18.1 dual quorum: BOTH must be satisfied.
    'quorum' => [
        'voters' => 10,
        'weight' => 7.5,
    ],

    // spec-locked — §18.2 effective-weight majority for a passing outcome.
    'majority' => 0.60,

    // spec-locked — §18.3 binding window (days).
    'binding_window' => [
        'min_days' => 7,
        'max_days' => 90,
    ],

    // spec-locked — §19.4: suspected-cluster combined binding weight is
    // capped at 20% of effective outcome weight, expressed non-circularly
    // as ratio × non-cluster weight (0.25 × W_nc == 20% of the total).
    'suspected_cluster_cap_ratio' => 0.25,

    // ── Time, decay, retention ───────────────────────────────────────
    // §12.1 — calibrate: monthly accrual rate (⚖); cap is spec-locked.
    'time_credit' => [
        'points_per_month' => 1.0,
        'cap'              => 20.0,
    ],

    // spec-locked — §12.3 inactivity decay (explicit-login clock).
    'decay' => [
        'grace_days'      => 365,
        'step_days'       => 30,
        'points_per_step' => 1.0,
    ],

    // calibrate — tier-day qualification history retention (covers the
    // §7 180-day Veteran window with deep margin for audit/repair).
    'retention' => [
        'tier_days_days' => 730,
    ],

    // ── Trust-tier plumbing ──────────────────────────────────────────
    // Ordinal encoding for wp_bcc_trust_tier_days.tier_ord. Exactly the
    // five §2 tiers; order is the qualification order (>= comparisons).
    'tier_ord' => [
        'risky'   => 0,
        'caution' => 1,
        'neutral' => 2,
        'trusted' => 3,
        'elite'   => 4,
    ],

    // spec-locked — §13.1 promotion trust windows.
    'trust_windows' => [
        'journeyman' => ['qualifying_days' => 45,  'window_days' => 60,  'min_tier' => 'neutral'],
        'veteran'    => ['qualifying_days' => 120, 'window_days' => 180, 'min_tier' => 'trusted'],
    ],

    // ── Independent-evidence minimums ────────────────────────────────
    // spec-locked — §10.3 / §11.2 / §6–§7.
    'recognizer_minimums' => ['journeyman' => 3, 'veteran' => 5],
    'outcome_minimums'    => ['veteran_outcomes' => 5, 'veteran_outcome_types' => 2],
    'diversity_minimums'  => ['journeyman_categories' => 3, 'veteran_categories' => 5],

    // ── Community privileges ─────────────────────────────────────────
    // spec-locked — §21.2 active ownership caps + 30-day global cooldown.
    'community' => [
        'caps'          => ['apprentice' => 2, 'journeyman' => 5, 'veteran' => 10],
        'cooldown_days' => 30,
    ],

    // ── Per-action point schedule ────────────────────────────────────
    // calibrate (⚖ plan remaining-decision 1) — working draft; ratified
    // against Phase 9 staging simulations. Consumed by the Phase 4
    // evidence ingestor; unused until then.
    'points' => [
        'post'                      => 0.75,
        'comment'                   => 0.25,
        'review'                    => 0.5,
        'meaningful_vote'           => 0.1,
        'upheld_report'             => 1.5,
        'stewardship'               => 0.5,
        'onboarding_assist'         => 0.5,
        'helping_min'               => 0.5,
        'helping_max'               => 1.0,
        'recognition_weight_factor' => 0.75,
        'proven_outcome'            => 2.0,
    ],

    // ── Evidence source → category map ───────────────────────────────
    // Declarative routing for the Phase 4 ingestor (layered credit per
    // §4.5 — a source may feed several categories, event cap enforced
    // at ingest). Unused until Phase 4.
    'sources' => [
        'post'          => ['contribution'],
        'comment'       => ['contribution'],
        'review'        => ['contribution'],
        'report_upheld' => ['contribution', 'outcomes'],
        'helpful_mark'  => ['helping'],
        'recognition'   => ['recognition'],
        'outcome'       => ['outcomes'],
        'stewardship'   => ['contribution', 'helping'],
        'onboarding'    => ['helping'],
        'login_month'   => ['time'],
    ],
];
