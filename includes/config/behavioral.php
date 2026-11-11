<?php
/**
 * Behavioral Analysis Thresholds
 * Sensitivity tuning for bot/fraud pattern detection
 */
if (!defined('ABSPATH')) exit;

define('BCC_TRUST_BEHAVIOR_EXTREME_VOTE_RATIO', 0.98);
define('BCC_TRUST_BEHAVIOR_TIMING_VARIANCE', 5);
define('BCC_TRUST_BEHAVIOR_RAPID_INTERVAL', 2);
define('BCC_TRUST_BEHAVIOR_BURST_THRESHOLD', 5);
define('BCC_TRUST_BEHAVIOR_BURST_MAX', 3);
define('BCC_TRUST_BEHAVIOR_SLEEP_RATIO', 0.3);
define('BCC_TRUST_BEHAVIOR_ACTION_RATE_MAX', 30);
define('BCC_TRUST_BEHAVIOR_WEEKLY_VARIANCE', 5);
// Removed (F-128, user decision): BLIND_VOTE_HIGH / BLIND_VOTE_MEDIUM (blind-
// voting read audit actions nothing writes) and VOTER_ONLY_MIN (voting without
// posting is the core action, not a fraud signal).
