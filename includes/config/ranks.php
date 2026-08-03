<?php
/**
 * Rank Vocabulary — labels and descriptions ONLY (§2.6 / §4.8).
 *
 * Rank is ONE of the two orthogonal identity axes:
 *
 *   Rank  — Apprentice / Journeyman / Veteran. Earned via the Rank
 *           redesign engine (RankPromotionEngine over the rank_events
 *           evidence ledger; Apprentice via the §5.2 readiness path).
 *   Trust — risky / caution / neutral / trusted / elite. Derived from
 *           what other people say about you. Lives in tiers.php.
 *
 * They are never the same measurement and must not be merged. A "Risky
 * Veteran" is a coherent thing: an old-timer who burned people.
 *
 * ── WHAT LIVES HERE, AND WHAT DELIBERATELY DOES NOT ──────────────────
 *
 * HERE: the display strings (label + description) for each rung. That
 * is ALL. The legacy level-gate constants (pulls / reviews / account
 * age) that used to sit below were deleted at the Phase 5 atomic
 * cutover along with FeatureAccessService — promotion thresholds now
 * live in includes/config/rank-scoring.php (RankScoringConfig).
 *
 * NOT here: the rank SLUGS (`apprentice` / `journeyman` / `veteran`).
 * Those stay as class constants on RankCatalog because they are wire
 * values, they are referenced as RankCatalog::RANK_* across the plugin
 * and its test suite, and one of them is PERSISTED per user in the
 * `rank_state` table. A runtime-swappable slug would orphan stored
 * state exactly the way the v1.58 master -> veteran rename would have.
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
 * @package BCC_Trust
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
