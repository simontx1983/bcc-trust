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
