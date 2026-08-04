<?php
/**
 * Quest Rewards Configuration
 *
 * Defines the weight bonuses granted by each onboarding quest.
 * Quest multiplier = 1.0 + sum(completed quest bonuses).
 *
 * Example: user completes all quests → multiplier = 1.0 + 0.30 = 1.30
 * That's a 30% vote/endorsement weight boost on top of existing tier weights.
 */
if (!defined('ABSPATH')) exit;

// Quest WEIGHT BONUSES + MULTIPLIER BOUNDS retired (Rank Phase 6,
// decision D-1): quests grant NO vote, Trust, or Rank power — they
// remain onboarding guidance / achievements only. The §16.6 formula
// (maturity × rank × trust × fraud) has no quest term.

// ======================================================
// QUEST CACHE
// Bump BCC_QUEST_CACHE_VER when multiplier logic changes
// to avoid stale reads from persistent object cache (Redis).
// ======================================================
define('BCC_QUEST_CACHE_VER', 'v2');
define('BCC_QUEST_CACHE_TTL', 300);          // 5 minutes in object cache
define('BCC_QUEST_CACHE_GROUP', 'bcc_quest');
