<?php
/**
 * Reputation Tiers Configuration
 */
if (!defined('ABSPATH')) exit;

// ======================================================
// REPUTATION TIER THRESHOLDS
// ======================================================
define('BCC_TRUST_TIER_ELITE', 80);
define('BCC_TRUST_TIER_TRUSTED', 65);
define('BCC_TRUST_TIER_NEUTRAL', 45);
define('BCC_TRUST_TIER_CAUTION', 30);

define('BCC_TRUST_MIN_REPUTATION_FOR_VOTING', 30);

// ======================================================
// §J.12 ELITE GATE
// ======================================================
//
// Elite is no longer a pure score cutoff. Reaching BCC_TRUST_TIER_ELITE is
// necessary but not sufficient; a page must also clear a native-conduct floor
// and the cross-table eligibility gates below.
//
// Why: onchain_bonus contributes up to BCC_ONCHAIN_MAX_TOTAL_BONUS (20) from
// wallet age, transaction depth and contract deployments — properties of a
// wallet, not of conduct on BCC. With attestation_bonus also capped at 20, an
// aged wallet plus a handful of vouches reached score 90 with no platform
// history at all. That contradicts the product's core claim that reputation
// cannot be bought.
//
// These are DESIGN-INTENT defaults, to be tuned against closed-network data
// rather than argued about — every one is read through apply_filters at the
// call site, matching the convention in includes/config/attestation.php.
// Change them with a filter, not a redeploy.

// Minimum score-excluding-onchain required for elite. attestation_bonus is
// ceiling'd at 20 (BCC_ATTEST_CEILING), so backing-with-no-reviews tops out at
// exactly 70; a floor of 71 forces at least some genuine review signal.
// Filterable: 'bcc_trust_elite_native_floor'.
define('BCC_TRUST_ELITE_NATIVE_FLOOR', 71);

// Minimum count of DISTINCT active attestors. Deliberately above
// BCC_ATTEST_DIVERSITY_MIN_SOURCES (3) — that gate is already cleared by the
// diversity multiplier, so reusing it would be no bar at all. The native floor
// closes "wallet depth alone"; this closes "vouch ring", which the floor does
// not, because attestation_bonus counts as native conduct.
// Filterable: 'bcc_trust_elite_min_attestors'.
define('BCC_TRUST_ELITE_MIN_ATTESTORS', 5);

// Minimum account tenure in days (page age for entity cards).
// Filterable: 'bcc_trust_elite_min_tenure_days'.
define('BCC_TRUST_ELITE_MIN_TENURE_DAYS', 90);

// A dispute UPHELD against the page within this trailing window blocks elite.
// Note the polarity: an upheld mark against a page is DisputeStatus::REJECTED
// (the dispute of the negative mark failed). See AttestationOutcomeClassifier.
// Filterable: 'bcc_trust_elite_dispute_window_days'.
define('BCC_TRUST_ELITE_DISPUTE_WINDOW_DAYS', 180);
