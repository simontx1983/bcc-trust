<?php
/**
 * Rank Ladder Configuration — the earned axis (§2.6 / §4.8).
 *
 * Rank is ONE of the two orthogonal identity axes:
 *
 *   Rank  — Apprentice / Journeyman / Veteran. Derived from the
 *           feature-access LEVEL (1/2/3). Tenure + activity.
 *   Trust — risky / caution / neutral / trusted / elite. Derived from
 *           what other people say about you. Lives in tiers.php.
 *
 * They are never the same measurement and must not be merged. A "Risky
 * Veteran" is a coherent thing: an old-timer who burned people.
 *
 * ── WHAT LIVES HERE, AND WHAT DELIBERATELY DOES NOT ──────────────────
 *
 * HERE: the display strings for each rung, and the three counters that
 * gate promotion between rungs.
 *
 * NOT here: the rank SLUGS (`apprentice` / `journeyman` / `veteran`).
 * Those stay as class constants on RankCatalog because they are wire
 * values, they are referenced as RankCatalog::RANK_* across the plugin
 * and its test suite, and one of them is PERSISTED per user in the
 * `bcc_last_seen_rank` meta. A runtime-swappable slug would orphan that
 * stored meta exactly the way the v1.58 master -> veteran rename did.
 * Labels are free to change; slugs are a migration.
 *
 * ── LABELS ARE CONFIG, NOT FILTERS ───────────────────────────────────
 *
 * Unlike every other file in this directory, the label constants below
 * are NOT read back through apply_filters. That is deliberate. The
 * frontend renders the rank ladder from its own hardcoded array
 * (bcc-frontend/src/lib/identity/rank-ladder.ts :: RANK_RUNGS) while the
 * profile chip renders the backend's `rank_label` verbatim. A runtime
 * label filter would silently desync those two surfaces — the chip would
 * read "Old Timer" while the info modal still listed "Veteran". Editing
 * this file and redeploying keeps the rename atomic across both repos,
 * which is the discipline v1.58 established. Do not add a label filter.
 *
 * ── THE GATES ARE FILTERABLE; THAT IS NOT AN INVITATION ──────────────
 *
 * The three threshold numbers ARE filterable, matching the convention in
 * includes/config/attestation.php — they are design-intent defaults to be
 * calibrated against closed-network data.
 *
 * But read this before you touch them (it restates the warning carried in
 * FeatureAccessService's own docblock): all three counters are SELF-DEALT.
 * `pulls` is the user's own following count. `reviews_written` counts vote
 * rows. `account_age_days` is calendar age since user_registered and
 * accrues while the account is dormant. So the ladder is a tenure check
 * standing in front of adjudication powers — level 3 gates open_dispute,
 * see_signal_details, see_trust_breakdown and feed_tab_signals.
 *
 * Nudging these numbers does not fix that; it only moves the same weak
 * gate. Strengthening Rank needs outcome data from the reliability engine.
 * Do not paper over the gap here.
 *
 * Resolution order for the gates:  constant -> filter -> wp_options
 * ('bcc_level_thresholds', merged per-field by
 * FeatureAccessService::getLevelThresholds()).
 *
 * @package BCC\Trust\Core
 */
if (!defined('ABSPATH')) exit;

// ======================================================
// EARNED LADDER — DISPLAY STRINGS
// ======================================================
//
// Order is fixed by RankCatalog (apprentice=1, journeyman=2, veteran=3);
// only the words are configurable here.
//
// "Master" was retired in v1.58 and is RESERVED for a future
// outcome-derived merit rung. Do not reuse it as a label for a rung that
// is earned by waiting — that overclaim is the whole reason for the
// rename. Both test suites assert it absent.

define('BCC_TRUST_RANK_LABEL_APPRENTICE', 'Apprentice');
define('BCC_TRUST_RANK_DESC_APPRENTICE',  'New on the floor.');

define('BCC_TRUST_RANK_LABEL_JOURNEYMAN', 'Journeyman');
define('BCC_TRUST_RANK_DESC_JOURNEYMAN',  'Earned the basics.');

define('BCC_TRUST_RANK_LABEL_VETERAN',    'Veteran');
define('BCC_TRUST_RANK_DESC_VETERAN',     'Been on the floor a while.');

// ======================================================
// LEVEL GATES — THE THRESHOLDS BEHIND EACH RUNG
// ======================================================
//
// Requirements are cumulative: level 3 requires the level-2 gate met too
// (100 reviews with 0 pulls is still level 1).

// Level 1 -> 2 (Apprentice -> Journeyman). Unlocks write_review and
// vouch_reaction. Pulls = follows on PeepSo's graph, i.e. the user's own
// following count — self-controlled.
// Filterable: 'bcc_trust_rank_pulls_required'.
define('BCC_TRUST_RANK_PULLS_REQUIRED', 5);

// Level 2 -> 3 (Journeyman -> Veteran), first of two. Counts vote rows
// authored by the user, not a distinct review CPT.
// Filterable: 'bcc_trust_rank_reviews_required'.
define('BCC_TRUST_RANK_REVIEWS_REQUIRED', 3);

// Level 2 -> 3, second of two. Calendar days since user_registered —
// account age, NOT days the user was active. Named for what it measures.
// Filterable: 'bcc_trust_rank_account_age_days_required'.
define('BCC_TRUST_RANK_ACCOUNT_AGE_DAYS_REQUIRED', 30);
