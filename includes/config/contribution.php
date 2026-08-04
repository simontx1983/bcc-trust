<?php
/**
 * Contribution + Consistency Configuration — "Trust Recovery Through
 * Contribution" (internal recovery signals).
 *
 * These constants drive ContributionScoreService + the daily recovery
 * evaluator. They generalize the §D5 dispute-participation credit
 * pattern (see scoring.php) to other contribution types, with the same
 * cap-based inflation protection.
 *
 * The model (locked):
 *
 *   bonus     = min(contribution, BCC_CONTRIB_MAX) + min(consistency, BCC_CONSIST_MAX)
 *   effective = genuine_reputation + bonus
 *   if genuine_reputation < BCC_TRUST_TIER_TRUSTED:   // Rule 2
 *       effective = min(effective, BCC_CONTRIB_CEILING)
 *
 * Anti-abuse rules these constants encode:
 *   R1 — reactions feed ONLY this capped/ceiling'd bonus, never genuine trust.
 *   R2 — BCC_CONTRIB_CEILING (< Trusted) means contribution alone can't reach Trusted/Elite.
 *   R3 — rolling windows + per-window caps reward consistency over spikes.
 *   R4 — engagement received is weighted by the engager's tier (BCC_TRUST_WEIGHT_* reused).
 *
 * Tune by editing here only — service + evaluator read these constants, never literals.
 *
 * @package BCC\Trust\Core
 */
if (!defined('ABSPATH')) exit;

// ======================================================
// ROLLING WINDOWS (days) — Rule 3
// ======================================================
// Behavior is evaluated across three windows; consistency rewards
// activity present in ALL of them, not a single spike in one.
define('BCC_CONTRIB_WINDOW_SHORT_DAYS', 30);
define('BCC_CONTRIB_WINDOW_MID_DAYS', 90);
define('BCC_CONTRIB_WINDOW_LONG_DAYS', 180);

// ======================================================
// CAPS + CEILING (trust points) — Rules 2 & 3
// ======================================================
define('BCC_CONTRIB_MAX', 12.0);        // max contribution bonus (the ~15% band)
define('BCC_CONSIST_MAX', 4.0);         // max consistency bonus (the ~5% band)
// Ceiling: contribution+consistency may lift a user no higher than this
// UNLESS their genuine (vote/dispute) score already clears Trusted. Set
// below BCC_TRUST_TIER_TRUSTED (65) so recovery tops out in high-Neutral.
define('BCC_CONTRIB_CEILING', 60.0);

// Per-window contribution cap (diminishing returns): no single window can
// contribute more than this share of BCC_CONTRIB_MAX, so a 30-day spike
// can't max the bonus on its own.
define('BCC_CONTRIB_PER_WINDOW_CAP', 6.0);

// ======================================================
// PER-SIGNAL BASE WEIGHTS (trust points per qualifying action)
// ======================================================
// Deliberately small — the caps above are the real ceiling. A "useful"
// post/comment is additionally scaled by its trust-weighted engagement
// (Rule 4), so these are the floor value of a bare qualifying action.
define('BCC_CONTRIB_WEIGHT_POST', 0.05);          // blog/research/guide post
define('BCC_CONTRIB_WEIGHT_COMMENT', 0.02);       // helpful comment
define('BCC_CONTRIB_WEIGHT_REVIEW', 0.05);        // review written
define('BCC_CONTRIB_WEIGHT_SCAM_REPORT', 0.10);   // upheld scam/content report
// Dispute participation carries no contribution weight: the §D5 panel
// credit retired with the panel (Rank Phase 6, D-7), and open dispute
// voting grants no trust or contribution credit.

// ======================================================
// RULE 4 — TRUST-WEIGHTED ENGAGEMENT
// ======================================================
// A contribution's "usefulness" multiplier is driven by WHO engaged with
// it (reacted/commented), weighted by the engager's reputation tier via
// the shared BCC_TRUST_WEIGHT_* constants (trust-weights.php). New/low-tier
// engagement is near-worthless, defeating reaction farming.
//
// usefulness = 1.0 + min(BCC_CONTRIB_ENGAGEMENT_MAX_MULT - 1.0,
//                        sum(tier_weight(engager)) * BCC_CONTRIB_ENGAGEMENT_SCALE)
define('BCC_CONTRIB_ENGAGEMENT_SCALE', 0.5);      // how fast weighted engagement lifts usefulness
define('BCC_CONTRIB_ENGAGEMENT_MAX_MULT', 2.0);   // a contribution counts at most 2× its base
// Bound the per-user-per-window content scan (Rule 4 is the heavy query;
// keep it cheap on the daily cron).
define('BCC_CONTRIB_ENGAGEMENT_MAX_CONTENT_ROWS', 50);

// ======================================================
// CONSISTENCY SIGNALS — Rule "long-term reliability"
// ======================================================
define('BCC_CONSIST_AGE_FULL_DAYS', 180);         // account age at/above which the age sub-signal maxes
define('BCC_CONSIST_STREAK_TARGET_DAYS', 14);     // active-streak at/above which the streak sub-signal maxes
// A recent violation/report against the user inside this window suppresses
// the consistency bonus entirely and dampens contribution (clean-record gate).
define('BCC_CONSIST_VIOLATION_WINDOW_DAYS', 90);
define('BCC_CONTRIB_VIOLATION_DAMPEN', 0.5);      // contribution multiplier when a recent violation exists
