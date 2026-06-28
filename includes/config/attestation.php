<?php
/**
 * Trust Attestation Layer — synthesis tuning constants (Slice E).
 *
 * These govern how active vouch/stand_behind attestations fold into a
 * subject's trust score (`attestation_bonus`). They are DESIGN-INTENT
 * defaults; per the §12 scope-freeze they are tuned in closed-network
 * testing, so every one is read through `apply_filters` at synthesis time
 * (AttestationScoreSynthesis) — change them with a filter, not a redeploy.
 *
 * The synthesis reads each attestation's immutable `weight_at_time` (which
 * already bakes in cast-time wallet-age × reciprocity × cohort-overlap),
 * applies time decay (DecayResolver, the locked curve), then the anti-cartel
 * caps below, and finally the hard ceiling.
 *
 * @package BCC\Trust\Core
 */
if (!defined('ABSPATH')) {
    exit;
}

// Hard ceiling on attestation_bonus — the most a subject's score can gain from
// backing alone (the §J.4 ceiling). Sized so maximal backing lifts a member
// from neutral well into trusted but cannot single-handedly mint Elite; votes,
// on-chain, and contribution still matter. Tune from closed-network data.
// Filterable: 'bcc_attest_ceiling'.
define('BCC_ATTEST_CEILING', 20.00);

// §J.4 Elite-tier SOURCE cap: weight from elite-tier attestors may contribute
// at most this share of the running decayed-weight total, so a small ring of
// elites cannot dominate a subject's reputation. Filterable: 'bcc_attest_elite_source_cap_pct'.
define('BCC_ATTEST_ELITE_SOURCE_CAP_PCT', 0.40);

// §J.4 signal-source diversity multiplier: reward breadth of distinct backers.
// Applied only when the number of distinct attestors reaches
// BCC_ATTEST_DIVERSITY_MIN_SOURCES (a single source never earns it).
// Filterable: 'bcc_attest_diversity_multiplier' / 'bcc_attest_diversity_min_sources'.
define('BCC_ATTEST_DIVERSITY_MULTIPLIER', 1.30);
define('BCC_ATTEST_DIVERSITY_MIN_SOURCES', 3);

// New-entity velocity cap — ENTITY CARDS ONLY (validator/project/creator). For
// the first N days of a card's existence its attestation_bonus is clamped to
// this value, blunting fast-mover Sybil reputation on brand-new entities. Does
// NOT apply to member self-pages (people aren't "new entities" in this sense).
// Filterable: 'bcc_attest_new_entity_velocity_cap' / 'bcc_attest_new_entity_window_days'.
define('BCC_ATTEST_NEW_ENTITY_VELOCITY_CAP', 50.00);
define('BCC_ATTEST_NEW_ENTITY_WINDOW_DAYS', 60);

// ─────────────────────────────────────────────────────────────────────────
// Slice 2 — Operator Reliability engine (§J.3.2 / §J.3.2.1).
//
// These govern AttestationOutcomeClassifier: the per-outcome "goodness" of
// each of an attestor's active attestations, the kind + early-read weights
// that average them, and the count/threshold gates that bucket the resulting
// numeric reliability into a §J.3.2 self-mirror standing.
//
// reliability = clamp01( Σ(goodness × kind_weight × early_read_mult)
//                        ÷ Σ(kind_weight × early_read_mult) )
//
// The constitution phrases the denominator as "÷ total attestations"; Phase 1
// interprets that as a WEIGHTED-AVERAGE normalization (denominator = Σ weights)
// so the score is bounded in [0,1] regardless of how heavily any single
// attestation is weighted. This is the tunable interpretation — every value
// below is read through apply_filters at classify time, so closed-network
// testing can retune (or switch the denominator) with a filter, not a redeploy.
//
// Filter prefix: 'bcc_reliability_*'.
// ─────────────────────────────────────────────────────────────────────────

// Standing gates. Below BCC_RELIABILITY_MIN_FOR_STANDING counted attestations
// the operator is held at 'newly_active' regardless of numeric reliability —
// first-call protection so a tiny sample can't mint (or sink) a standing. At or
// above the gate: > HIGHLY_MIN ⇒ highly_reliable, ≥ CONSISTENT_MIN ⇒ consistent,
// else newly_active. Filterable: 'bcc_reliability_min_for_standing' /
// 'bcc_reliability_highly_min' / 'bcc_reliability_consistent_min'.
define('BCC_RELIABILITY_MIN_FOR_STANDING', 20);
define('BCC_RELIABILITY_HIGHLY_MIN', 0.85);
define('BCC_RELIABILITY_CONSISTENT_MIN', 0.65);

// Per-outcome goodness ∈ [0,1] (§J.3.2.1 outcome classification):
//   - disputed-and-upheld  → the target's negative mark was UPHELD by panel
//                            (a dispute resolved 'rejected' after the cast).
//                            The attestor backed someone the floor judged
//                            against → goodness 0.0.
//   - clean & active       → no dispute, no further attestors → mild positive
//                            (the call hasn't been confirmed OR contradicted).
//   - further attestations → ≥1 distinct OTHER attestor followed → confirmed.
//   - disputed-and-dismissed → a dispute resolved accepted/dismissed/timeout
//                            after the cast → the target was VINDICATED.
// Filterable: 'bcc_reliability_goodness_disputed_upheld' /
// '..._clean_active' / '..._further_attest' / '..._disputed_dismissed'.
define('BCC_RELIABILITY_GOODNESS_DISPUTED_UPHELD', 0.0);
define('BCC_RELIABILITY_GOODNESS_CLEAN_ACTIVE', 0.5);
define('BCC_RELIABILITY_GOODNESS_FURTHER_ATTEST', 1.0);
define('BCC_RELIABILITY_GOODNESS_DISPUTED_DISMISSED', 1.0);

// Kind weights — stand_behind is the scarcer, higher-conviction signal and so
// weighs more in the average than the abundant vouch.
// Filterable: 'bcc_reliability_kind_vouch' / 'bcc_reliability_kind_stand_behind'.
define('BCC_RELIABILITY_KIND_VOUCH', 1.0);
define('BCC_RELIABILITY_KIND_STAND_BEHIND', 1.5);

// Early-read multiplier (stand_behind ONLY; vouch always 1.0), keyed on the
// attestation's attestation_order_in_target. Being first to stand behind a
// target that later earns consensus is the strongest reliability signal;
// piling on after the crowd is the weakest. Tiers by order:
//   1st → EARLY_1ST, 2nd–5th → EARLY_2_5, 6th–20th → EARLY_6_20, 21st+ → EARLY_21PLUS.
// First-mover PROTECTION: the operator's OWN first EARLY_PROTECT_FIRST
// stand_behind casts (chronologically, across all targets) are pinned to 1.0×
// regardless of order — a new operator isn't penalised for joining late on
// already-crowded targets while they build a history.
// Filterable: 'bcc_reliability_early_1st' / '..._2_5' / '..._6_20' /
// '..._21plus' / '..._protect_first'.
define('BCC_RELIABILITY_EARLY_1ST', 2.5);
define('BCC_RELIABILITY_EARLY_2_5', 1.5);
define('BCC_RELIABILITY_EARLY_6_20', 1.0);
define('BCC_RELIABILITY_EARLY_21PLUS', 0.5);
define('BCC_RELIABILITY_EARLY_PROTECT_FIRST', 5);
