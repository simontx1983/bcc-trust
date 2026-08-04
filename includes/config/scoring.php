<?php
/**
 * Scoring Configuration
 * Score velocity, coordination detection, account age, GitHub integration
 */
if (!defined('ABSPATH')) exit;

// ======================================================
// NEUTRAL (DEFAULT) TRUST SCORE
// ======================================================
// Baseline score for new pages. Used by the canonical formula in
// \BCC\Trust\Core\Services\TrustScoreService (compute + formulaSql),
// plus default returns and fallback values. Change here to adjust
// the neutral starting point system-wide.
define('BCC_TRUST_NEUTRAL_SCORE', 50);

// ======================================================
// RETALIATION GUARD (direct person-reviews)
// ======================================================
// A down-review on a member's self-page is refused when that member
// filed a user-report against the voter within this window. Closes the
// "you reported me, so I'll down-rate you" retaliation path on the new
// direct-person-review capability (Slice 2). Entity-page votes are
// unaffected — only self-pages carry a reporter/voter relationship.
define('BCC_TRUST_RETALIATION_WINDOW_DAYS', 14);

// ======================================================
// SCORE VELOCITY CAP
// ======================================================
define('BCC_TRUST_MAX_SCORE_CHANGE_PER_DAY', 8);

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
// CLAIM-VERIFIED RANKING BONUS
// ======================================================
// Discovery/search ranking bonus applied when a page has a verified
// on-chain operator/creator claim (bcc_page_read_model.has_verified_claim).
// This is on-chain ownership — distinct from, and dominant over, the
// owner-EMAIL verification term (+3.0). Consumed by ScoreReadService's
// composite ranking_score. Filterable via `bcc_rank_claim_verified_bonus`.
define('BCC_RANK_CLAIM_VERIFIED_BONUS', 10.0);

// ======================================================
// X (TWITTER) INTEGRATION SCORING
// ======================================================
define('BCC_TRUST_X_TRUST_BOOST', 10);       // Flat trust boost for verified X account
define('BCC_TRUST_X_FRAUD_REDUCTION', 5);     // Flat fraud reduction for verified X account

// §D5 DISPUTE PARTICIPATION (panel-vote credit) RETIRED (Rank Phase 6,
// owner decision D-7): the five-member panel is gone — disputes are
// decided by open meaningful voting on the poll engine, and dispute
// participation carries no trust credit.

