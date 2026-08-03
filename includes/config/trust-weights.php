<?php
/**
 * Trust Weights Configuration
 * Vote weights, endorsement weights, score multipliers, decay, confidence, trust graph
 */
if (!defined('ABSPATH')) exit;

// ======================================================
// VOTE WEIGHT TIERS
// ======================================================
define('BCC_TRUST_WEIGHT_ELITE', 0.30);
define('BCC_TRUST_WEIGHT_TRUSTED', 0.25);
define('BCC_TRUST_WEIGHT_NEUTRAL', 0.15);
define('BCC_TRUST_WEIGHT_CAUTION', 0.08);
define('BCC_TRUST_WEIGHT_RISKY', 0.03);
define('BCC_TRUST_MIN_VOTE_WEIGHT', 0.1);
define('BCC_TRUST_MAX_VOTE_WEIGHT', 0.6);

// NOTE: the legacy ENDORSEMENT WEIGHT TIERS block
// (BCC_TRUST_ENDORSE_ELITE/TRUSTED/NEUTRAL/CAUTION/RISKY,
// BCC_TRUST_MAX_ENDORSE_WEIGHT, BCC_TRUST_VOUCH_WEIGHT) was deleted in
// the endorse-retirement final slice — its last readers
// (EndorsementRepository, AuditLogger::endorse) are gone. Vouch weight
// now composes at cast time in AttestationService (wallet-age ×
// reciprocity × cohort multipliers, captured in weight_at_time).

// ======================================================
// UNIFIED VESTING (5-stage graduated model)
//
// Shared by BOTH votes and endorsements. Single source of truth.
//
// Stage 0:  0–29 days   → 10%  (new, minimal influence)
// Stage 1: 30–89 days   → 50%  (early participation)
// Stage 2: 90–151 days  → 70%  (established contributor)
// Stage 3: 152–364 days → 85%  (trusted participant)
// Stage 4: 365+ days    → 100% (fully vested)
// ======================================================

// Vote-time vesting constants RETIRED (Rank Phase 6): the §16.6
// formula's only vesting is the member-level maturity term
// (rank-scoring.php `maturity`), computed from the apprentice epoch.
// The graduation cron died in Phase 2; the calculator stages and
// resetVesting died at the voting cutover.

// ======================================================
// VOTE DECAY SETTINGS
// ======================================================
define('BCC_TRUST_DECAY_DAYS', 90);
define('BCC_TRUST_DECAY_MIN', 0.3);

// ======================================================
// CONFIDENCE CALCULATION
// ======================================================
define('BCC_TRUST_MAX_CONFIDENCE_VOTES', 50);
define('BCC_TRUST_MIN_VOTES_RELIABLE', 10);

// ======================================================
// TRUST GRAPH (PageRank-style propagation)
// ======================================================
define('BCC_TRUST_GRAPH_VOTE_MULTIPLIER', 0.2);
define('BCC_TRUST_GRAPH_ENDORSE_MULTIPLIER', 1.0);
define('BCC_TRUST_GITHUB_AGE_BOOST', 0.10);
define('BCC_TRUST_GITHUB_FOLLOWERS_BOOST', 0.10);
define('BCC_TRUST_GITHUB_REPOS_BOOST', 0.10);
define('BCC_TRUST_GITHUB_ORGS_BOOST', 0.10);
define('BCC_TRUST_GITHUB_VERIFIED_BOOST', 0.05);
define('BCC_TRUST_GITHUB_MAX_MULTIPLIER', 1.5);
define('BCC_TRUST_FRAUD_PENALTY_MULTIPLIER', 0.5);
define('BCC_TRUST_GITHUB_AGE_THRESHOLD', 365);
define('BCC_TRUST_GITHUB_FOLLOWERS_THRESHOLD', 20);
define('BCC_TRUST_GITHUB_REPOS_THRESHOLD', 10);

// ======================================================
// NFT COLLECTION TRUST BOOSTS
// ======================================================
define('BCC_TRUST_NFT_CREATOR_BOOST', 2.0);
define('BCC_TRUST_NFT_CREATOR_MAX',  10.0);
define('BCC_TRUST_NFT_HOLDER_BOOST', 0.5);
define('BCC_TRUST_NFT_HOLDER_MAX',   2.5);
