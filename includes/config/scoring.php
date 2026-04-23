<?php
/**
 * Scoring Configuration
 * Score velocity, endorsement diversity, coordination detection, account age, GitHub integration
 */
if (!defined('ABSPATH')) exit;

// ======================================================
// NEUTRAL (DEFAULT) TRUST SCORE
// ======================================================
// Baseline score for new pages. Used in TOTAL_SCORE_SQL formula,
// default returns, and fallback values. Change here to adjust
// the neutral starting point system-wide.
define('BCC_TRUST_NEUTRAL_SCORE', 50);

// ======================================================
// SCORE VELOCITY CAP
// ======================================================
define('BCC_TRUST_MAX_SCORE_CHANGE_PER_DAY', 8);

// ======================================================
// ENDORSEMENT SOURCE DIVERSITY
// ======================================================
define('BCC_TRUST_ENDORSE_DIVERSITY_LOW', 0.20);
define('BCC_TRUST_ENDORSE_DIVERSITY_MED', 0.40);
define('BCC_TRUST_ENDORSE_DIVERSITY_HIGH', 0.60);

// ======================================================
// ENDORSEMENT-TO-VOTE RATIO DETECTION
// ======================================================
define('BCC_TRUST_ENDORSE_VOTE_RATIO_THRESHOLD', 5);

// ======================================================
// TEMPORAL COORDINATION DETECTION
// ======================================================
define('BCC_TRUST_COORDINATION_ACTION_THRESHOLD', 5);
define('BCC_TRUST_COORDINATION_WINDOW_SECONDS', 120);

// ======================================================
// ACCOUNT AGE THRESHOLDS (days)
// ======================================================
define('BCC_TRUST_AGE_NEW', 30);           // < 30 days = new account
define('BCC_TRUST_AGE_ESTABLISHED', 90);   // < 90 days = developing account
define('BCC_TRUST_AGE_VERIFIED', 1095);    // > 3 years = veteran account

// Vote base-weight multipliers for account age (VoteWeightCalculator)
define('BCC_TRUST_AGE_NEW_MULTIPLIER', 0.70);
define('BCC_TRUST_AGE_ESTABLISHED_MULTIPLIER', 0.85);
define('BCC_TRUST_AGE_VERIFIED_MULTIPLIER', 1.15);

// Endorsement weight multipliers for account age (EndorsementWeightCalculator)
define('BCC_TRUST_ENDORSE_AGE_1YR_MULTIPLIER', 1.1);
define('BCC_TRUST_ENDORSE_AGE_2YR_MULTIPLIER', 1.2);

// ======================================================
// GITHUB INTEGRATION SCORING
// ======================================================
define('BCC_TRUST_GITHUB_MAX_BOOST', 50);
define('BCC_TRUST_GITHUB_MAX_REDUCTION', 40);

// GitHub weight factors
define('BCC_TRUST_GITHUB_WEIGHT_AGE', 0.25);
define('BCC_TRUST_GITHUB_WEIGHT_FOLLOWERS', 0.20);
define('BCC_TRUST_GITHUB_WEIGHT_REPOS', 0.15);
define('BCC_TRUST_GITHUB_WEIGHT_ORGS', 0.10);
define('BCC_TRUST_GITHUB_WEIGHT_EMAIL', 0.10);
define('BCC_TRUST_GITHUB_WEIGHT_PROFILE', 0.10);
define('BCC_TRUST_GITHUB_WEIGHT_ACTIVITY', 0.10);
define('BCC_TRUST_GITHUB_WEIGHT_GISTS', 0.05);
define('BCC_TRUST_GITHUB_WEIGHT_TYPE', 0.05);

// GitHub threshold levels
define('BCC_TRUST_GITHUB_AGE_VETERAN_YEARS', 10);
define('BCC_TRUST_GITHUB_AGE_VERIFIED_YEARS', 1);
define('BCC_TRUST_GITHUB_AGE_ESTABLISHED_MONTHS', 6);

define('BCC_TRUST_GITHUB_FOLLOWERS_ELITE', 10000);
define('BCC_TRUST_GITHUB_FOLLOWERS_HIGH', 1000);
define('BCC_TRUST_GITHUB_FOLLOWERS_MEDIUM', 100);

define('BCC_TRUST_GITHUB_REPOS_ELITE', 100);
define('BCC_TRUST_GITHUB_REPOS_HIGH', 30);
define('BCC_TRUST_GITHUB_REPOS_MEDIUM', 10);

define('BCC_TRUST_GITHUB_ORGS_ELITE', 20);
define('BCC_TRUST_GITHUB_ORGS_HIGH', 10);
define('BCC_TRUST_GITHUB_ORGS_MEDIUM', 5);

// ======================================================
// X (TWITTER) INTEGRATION SCORING
// ======================================================
define('BCC_TRUST_X_TRUST_BOOST', 10);       // Flat trust boost for verified X account
define('BCC_TRUST_X_FRAUD_REDUCTION', 5);     // Flat fraud reduction for verified X account

// ======================================================
// VERIFICATION SETTINGS
// ======================================================
define('BCC_TRUST_VERIFICATION_EXPIRY_HOURS', 1);
define('BCC_TRUST_VERIFICATION_RATE_HOURS', 24);
define('BCC_TRUST_VERIFICATION_RATE_MAX', 5);
