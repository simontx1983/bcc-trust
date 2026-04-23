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

// ======================================================
// QUEST WEIGHT BONUSES
// Each quest adds this value to the quest multiplier.
// Total max bonus = sum of all = 0.30 → max multiplier = 1.30
// ======================================================
define('BCC_QUEST_BONUS_CONNECT_WALLET',   0.08);
define('BCC_QUEST_BONUS_VERIFY_GITHUB',    0.07);
define('BCC_QUEST_BONUS_VERIFY_X',         0.05);
define('BCC_QUEST_BONUS_SHARE_X',          0.02);
define('BCC_QUEST_BONUS_COMPLETE_PROFILE', 0.03);
define('BCC_QUEST_BONUS_FIRST_VOTE',       0.03);
define('BCC_QUEST_BONUS_EXPLORE_PROJECTS', 0.02);

// ======================================================
// QUEST MULTIPLIER BOUNDS
// ======================================================
define('BCC_QUEST_MULTIPLIER_BASE', 1.0);   // No quests completed
define('BCC_QUEST_MULTIPLIER_MAX',  1.30);   // All quests completed

// ======================================================
// QUEST CACHE
// Bump BCC_QUEST_CACHE_VER when multiplier logic changes
// to avoid stale reads from persistent object cache (Redis).
// ======================================================
define('BCC_QUEST_CACHE_VER', 'v2');
define('BCC_QUEST_CACHE_TTL', 300);          // 5 minutes in object cache
define('BCC_QUEST_CACHE_GROUP', 'bcc_quest');
