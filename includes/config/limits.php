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

// §D6 PR-B — blog cover-image upload throttle (BlogCoverImageEndpoint).
//
// Sized for the composer's "pick a cover, change my mind, pick another"
// flow. Each upload is an attachment write + image-meta generation —
// cheaper than a photo-post but still a real disk/DB hit, and
// attachments stick to the user's media library. 5/60s is generous for
// composer interactivity (one retry every ~12 seconds) and clips
// scripted abuse hard (60/min would let a script fill the uploads
// directory).
//
// Throttled BEFORE wp_handle_upload to keep the disk write off the
// hot abuse path. NOT a primary defense — author-ownership on
// PostsService::createBlog is the security gate.
define('BCC_TRUST_RATE_LIMIT_BLOG_COVER_UPLOAD', 5);
define('BCC_TRUST_RATE_WINDOW_BLOG_COVER_UPLOAD', 60);

// Card-watch / unwatch throttle (WatchingEndpoint watch / unwatch).
//
// Scale-hardening pass (2026-05-13): the watch-graph was previously
// gated by bcc-core's Throttle::allow primitive with hardcoded class
// constants at the endpoint. Promoted to bcc-trust RateLimiter so it
// inherits (a) trust-tier multipliers — Elite at 1.5×, Trusted at
// 1.3× — so legitimate batch curation by high-rep operators isn't
// punished, and (b) per-IP+per-user dual-bucket sliding window so
// subnet-coordinated watch-farming can't multiply via sock-puppets
// on one /24.
//
// 30/60s baseline is intentionally generous — a curator browsing 30
// cards per minute is one every two seconds, which is comfortable
// human cadence. A Trusted reviewer doing batch follow-up gets ~39/60s;
// an Elite gets ~45/60s. Anonymous callers can't watch (auth check
// fires first in the endpoint).
//
// Anti-watch-farming rationale: the floor on bulk-watch is set by
// these constants. At 30/60s = max 43,200 watches/24h for a baseline
// user — well above any legitimate use, well below a scripted attack
// generating mass first_watcher events on the recipient side.
//
// Bucket names `pull` / `unpull` kept stable through release N for
// storage compatibility with in-flight rate-limit counters; the
// vocabulary inside the comments is the canonical one.
define('BCC_TRUST_RATE_LIMIT_PULL', 30);
define('BCC_TRUST_RATE_WINDOW_PULL', 60);

define('BCC_TRUST_RATE_LIMIT_UNPULL', 30);
define('BCC_TRUST_RATE_WINDOW_UNPULL', 60);

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
