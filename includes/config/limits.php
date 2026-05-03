<?php
/**
 * Rate Limits, Cache TTLs, Cleanup Retention, Admin UI Settings
 */
if (!defined('ABSPATH')) exit;

// ======================================================
// RATE LIMITING - DEFAULTS
// ======================================================
define('BCC_TRUST_RATE_LIMIT_DEFAULT', 20);
define('BCC_TRUST_RATE_WINDOW_DEFAULT', 60);

// ======================================================
// RATE LIMITING - SPECIFIC ACTIONS
// ======================================================
define('BCC_TRUST_RATE_LIMIT_VOTE', 30);
define('BCC_TRUST_RATE_WINDOW_VOTE', 60);

define('BCC_TRUST_RATE_LIMIT_ENDORSE', 10);
define('BCC_TRUST_RATE_WINDOW_ENDORSE', 300);

define('BCC_TRUST_RATE_LIMIT_FLAG', 5);
define('BCC_TRUST_RATE_WINDOW_FLAG', 300);

define('BCC_TRUST_RATE_LIMIT_COMMENT', 20);
define('BCC_TRUST_RATE_WINDOW_COMMENT', 300);

define('BCC_TRUST_RATE_LIMIT_MESSAGE', 10);
define('BCC_TRUST_RATE_WINDOW_MESSAGE', 60);

define('BCC_TRUST_RATE_LIMIT_LOGIN', 5);
define('BCC_TRUST_RATE_WINDOW_LOGIN', 300);

define('BCC_TRUST_RATE_LIMIT_API', 60);
define('BCC_TRUST_RATE_WINDOW_API', 60);

// Status-post burst seatbelt (PostsService::createStatus).
// Tight enough to clip accidental floods + scripted bursts; loose
// enough that humans never hit it. NOT a primary defense — when this
// fires repeatedly in production logs ('status burst seatbelt fired'),
// it's the signal to layer in §K1 fingerprint/IP/fraud-score gates.
define('BCC_TRUST_RATE_LIMIT_STATUS_POST', 5);
define('BCC_TRUST_RATE_WINDOW_STATUS_POST', 120);

// ======================================================
// NOTIFICATIONS (§I1)
// ======================================================
// PeepSo's notifications API takes an integer module_id. Each native
// PeepSo module owns a sequential constant (PeepSoGdpr = 10, etc.).
// 9000 puts BCC well clear of the native range without cliff-ing into
// magic-number territory — high enough to spot in queries, low enough
// to stay an INT.
//
// All BCC-emitted notifications carry this module_id. Per-event
// distinction goes on `not_type` (string) — see NotificationType.
define('BCC_NOTIFICATION_MODULE_ID', 9000);

// Default page size for /me/notifications. Tight enough that the
// dropdown surface stays scannable; the Load-more button paginates
// past it. Hard ceiling per request stays at 50 (set in the endpoint).
define('BCC_NOTIFICATION_PAGE_SIZE', 20);

// ======================================================
// CACHE SETTINGS
// ======================================================
define('BCC_TRUST_CACHE_SCORE', 3600);
define('BCC_TRUST_CACHE_USER', 300);
define('BCC_TRUST_CACHE_FRAUD', 300);
define('BCC_TRUST_CACHE_BEHAVIOR', 3600);
define('BCC_TRUST_CACHE_GRAPH', 3600);

// ======================================================
// CLEANUP / RETENTION
// ======================================================
define('BCC_TRUST_CLEANUP_FINGERPRINTS', 90);
define('BCC_TRUST_CLEANUP_PATTERNS', 30);
define('BCC_TRUST_CLEANUP_FRAUD_ANALYSIS', 90);
define('BCC_TRUST_CLEANUP_ACTIVITY', 90);
define('BCC_TRUST_CLEANUP_VOTES', 30);
define('BCC_TRUST_CLEANUP_SUSPENSIONS', 30);

// ======================================================
// ADMIN UI SETTINGS
// ======================================================
define('BCC_TRUST_ADMIN_USERS_PER_PAGE', 50);
define('BCC_TRUST_ADMIN_VERIFIED_PER_PAGE', 50);
define('BCC_TRUST_ADMIN_PAGES_LIMIT', 100);
define('BCC_TRUST_ADMIN_ALL_PAGES_LIMIT', 200);
define('BCC_TRUST_ADMIN_USERS_LIMIT', 200);
define('BCC_TRUST_ADMIN_ACTIVITY_LIMIT', 100);
define('BCC_TRUST_ADMIN_FRAUD_LIMIT', 100);
define('BCC_TRUST_ADMIN_DEVICES_LIMIT', 100);
define('BCC_TRUST_ADMIN_ML_LIMIT', 100);
