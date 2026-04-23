<?php
/**
 * Fraud Detection Configuration
 * Fraud thresholds, detection limits, vote rings, ML weights, suspension, automation
 */
if (!defined('ABSPATH')) exit;

// ======================================================
// FRAUD SCORE THRESHOLDS
// ======================================================
define('BCC_TRUST_FRAUD_CRITICAL', 80);
define('BCC_TRUST_FRAUD_HIGH', 60);
define('BCC_TRUST_FRAUD_MEDIUM', 40);
define('BCC_TRUST_FRAUD_LOW', 20);

// ======================================================
// FRAUD DISCOUNT MODEL CONSTANTS
// Used by FraudDiscountCalculator (single source of truth).
// ======================================================

// Floor for retroactive fraud factor (PHP + SQL mirror).
// Prevents complete zeroing of historical votes/endorsements.
define('BCC_TRUST_RETROACTIVE_FRAUD_FLOOR', 0.1);

// Additive penalty >= this triggers a hard block (discount = 0.0).
define('BCC_TRUST_FRAUD_BLOCK_THRESHOLD', 0.90);

// Penalty assigned when automation is confirmed.
define('BCC_TRUST_FRAUD_PENALTY_AUTOMATION', 0.90);

// Penalty assigned for shared device fingerprint (multi-account).
define('BCC_TRUST_FRAUD_PENALTY_MULTI_ACCOUNT', 0.50);

// Device fraud probability must exceed this to trigger a penalty.
define('BCC_TRUST_FRAUD_DEVICE_THRESHOLD', 0.7);

// Weight applied to device fraud probability when penalizing.
define('BCC_TRUST_FRAUD_DEVICE_WEIGHT', 0.50);

// Weight applied to fraud_score on record when penalizing.
define('BCC_TRUST_FRAUD_SCORE_WEIGHT', 0.50);

// Trust rank must exceed this for the good-actor boost.
define('BCC_TRUST_FRAUD_RANK_BOOST_THRESHOLD', 0.7);

// Maximum penalty reduction from trust rank boost.
define('BCC_TRUST_FRAUD_RANK_BOOST_CAP', 0.15);

// Days of inactivity before daily fraud score decay begins (-1/day).
// Used by UserInfoRepository::applyDailyFraudDecay().
define('BCC_TRUST_FRAUD_DECAY_IDLE_DAYS', 30);

// ======================================================
// FRAUD DETECTION THRESHOLDS
// ======================================================
define('BCC_TRUST_RAPID_VOTE_LIMIT', 20);
define('BCC_TRUST_RAPID_VOTE_WINDOW', 2);
define('BCC_TRUST_IP_CLUSTER_LIMIT', 50);
define('BCC_TRUST_IP_CLUSTER_WINDOW', 10);
define('BCC_TRUST_MULTI_ACCOUNT_LIMIT', 5);
define('BCC_TRUST_MULTI_ACCOUNT_WINDOW', 1);
define('BCC_TRUST_UNIFORM_VOTE_THRESHOLD', 0.9);
define('BCC_TRUST_UNIFORM_VOTE_MIN', 10);
define('BCC_TRUST_VOTE_CHANGE_LIMIT', 5);

// ======================================================
// VOTE RING DETECTION
// ======================================================
define('BCC_TRUST_RING_MIN_SIZE', 3);
define('BCC_TRUST_RING_MIN_MUTUAL', 3);
define('BCC_TRUST_RING_STRENGTH_THRESHOLD', 5.0);

// ======================================================
// DEVICE FINGERPRINTING / AUTOMATION
// ======================================================
define('BCC_TRUST_AUTOMATION_HIGH', 70);
define('BCC_TRUST_AUTOMATION_MEDIUM', 40);
define('BCC_TRUST_AUTOMATION_LOW', 20);

// ======================================================
// SUSPENSION THRESHOLDS
// ======================================================
define('BCC_TRUST_SUSPEND_SCORE', 85);
define('BCC_TRUST_SUSPEND_CONFIDENCE', 0.8);

// ======================================================
// ML FRAUD DETECTION WEIGHTS
// ======================================================
define('BCC_TRUST_ML_WEIGHT_AUTOMATION', 0.12);
define('BCC_TRUST_ML_WEIGHT_FRAUD', 0.10);
define('BCC_TRUST_ML_WEIGHT_BEHAVIOR', 0.10);
define('BCC_TRUST_ML_WEIGHT_TRUST', 0.08);
define('BCC_TRUST_ML_WEIGHT_VPN', 0.08);
define('BCC_TRUST_ML_WEIGHT_HEADLESS', 0.08);
define('BCC_TRUST_ML_WEIGHT_VOTE_RING', 0.08);
define('BCC_TRUST_ML_WEIGHT_VOTE_VARIANCE', 0.05);
define('BCC_TRUST_ML_WEIGHT_BURSTINESS', 0.05);
define('BCC_TRUST_ML_WEIGHT_UNIQUE_IPS', 0.05);
define('BCC_TRUST_ML_WEIGHT_MUTUAL', 0.03);
define('BCC_TRUST_ML_WEIGHT_UPRATIO', 0.03);
define('BCC_TRUST_ML_WEIGHT_NIGHT', 0.03);
define('BCC_TRUST_ML_WEIGHT_SESSION', 0.03);
define('BCC_TRUST_ML_WEIGHT_AGE', 0.03);
define('BCC_TRUST_ML_WEIGHT_EMAIL', 0.02);
define('BCC_TRUST_ML_WEIGHT_PROFILE', 0.02);
define('BCC_TRUST_ML_WEIGHT_FOLLOWERS', 0.02);

// ML risk thresholds
define('BCC_TRUST_ML_RISK_LOW', 0.3);
define('BCC_TRUST_ML_RISK_MEDIUM', 0.6);
define('BCC_TRUST_ML_RISK_HIGH', 0.8);
define('BCC_TRUST_ML_RISK_CRITICAL', 0.9);
define('BCC_TRUST_ML_PREDICTION_EXPIRY_DAYS', 90);

// ======================================================
// TRANSACTION MANAGER
// ======================================================
define('BCC_TRUST_TX_RETRY_ATTEMPTS', 3);
define('BCC_TRUST_TX_BACKOFF_MS', 100);
define('BCC_TRUST_TX_MAX_BACKOFF_MS', 5000);
define('BCC_TRUST_LOG_DEADLOCKS', true);
