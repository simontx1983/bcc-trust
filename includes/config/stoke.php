<?php
/**
 * Stoke — forge-fire feed reaction. Cosmetic for trust (never writes to
 * bcc_trust_scores); a real input to feed ranking (reach/virality).
 */
if (!defined('ABSPATH')) exit;

// ======================================================
// STOKE — VELOCITY WINDOW
// ======================================================
// Time-decay is approximated as a fixed lookback window (same style as
// bcc-core's PeepSoGroupRepository::getActivityHeat()) rather than a
// continuous half-life — simpler, bounded, and consistent with the
// only other "heat" concept already in this codebase. Stokes older
// than this window stop contributing to heat_stage.
define('BCC_STOKE_DECAY_WINDOW_HOURS', 48);

// ======================================================
// STOKE — STAGE THRESHOLDS
// ======================================================
// heat_stage (1-5) is derived from COUNT(*) of distinct stokers within
// the decay window — one stoke per person (X-"like" model), so this is
// a straight headcount, not a weighted sum. Stage 1 is the floor —
// every post with heat_stage populated renders at least stage 1, never
// "stage 0". Tunable without a deploy; revisit once real traffic data
// exists.
define('BCC_STOKE_STAGE_2_MIN', 3);
define('BCC_STOKE_STAGE_3_MIN', 8);
define('BCC_STOKE_STAGE_4_MIN', 20);
define('BCC_STOKE_STAGE_5_MIN', 50);
