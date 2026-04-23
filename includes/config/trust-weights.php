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

// ======================================================
// ENDORSEMENT WEIGHT TIERS
// ======================================================
define('BCC_TRUST_ENDORSE_ELITE', 1.0);
define('BCC_TRUST_ENDORSE_TRUSTED', .5);
define('BCC_TRUST_ENDORSE_NEUTRAL', .20);
define('BCC_TRUST_ENDORSE_CAUTION', 0.1);
define('BCC_TRUST_ENDORSE_RISKY', 0.08);
define('BCC_TRUST_MAX_ENDORSE_WEIGHT', 2.0);

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

// Day thresholds (shared by votes, endorsements, and cron graduation)
define('BCC_TRUST_VESTING_STAGE_1_DAYS', 30);
define('BCC_TRUST_VESTING_STAGE_2_DAYS', 90);
define('BCC_TRUST_VESTING_STAGE_3_DAYS', 152);
define('BCC_TRUST_VESTING_STAGE_4_DAYS', 365);

// Weight factors per stage (shared by votes and endorsements)
// Stage 0 at 30% ensures new user votes are visible on the score
// (old 10% made votes functionally zero — trust-killer for new users).
define('BCC_TRUST_VESTING_STAGE_0_PCT', 0.30);
define('BCC_TRUST_VESTING_STAGE_1_PCT', 0.50);
define('BCC_TRUST_VESTING_STAGE_2_PCT', 0.70);
define('BCC_TRUST_VESTING_STAGE_3_PCT', 0.85);
define('BCC_TRUST_VESTING_STAGE_4_PCT', 1.00);

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
