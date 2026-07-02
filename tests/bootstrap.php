<?php
/**
 * PHPUnit bootstrap for bcc-disputes unit tests.
 *
 * Deliberately minimal:
 *   - Defines ABSPATH so production files' "if (!defined('ABSPATH')) exit;"
 *     guard lets them parse when required in-process.
 *   - Registers Composer's autoloader so PHPUnit itself can be loaded.
 *   - Defines plugin constants used by computeVerdict's docblock tests
 *     and by the DisputeResolver call-path.
 *
 * No WordPress core is required. DB-dependent code is tested via in-namespace
 * stubs defined in individual test files (see DisputeResolverTest).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Plugin constants referenced by production code we exercise.
if (!defined('BCC_DISPUTES_PANEL_SIZE')) {
    define('BCC_DISPUTES_PANEL_SIZE', 5);
}
if (!defined('BCC_DISPUTES_TTL_DAYS')) {
    define('BCC_DISPUTES_TTL_DAYS', 7);
}

// Stoke stage thresholds — StokeRepositoryTest exercises stageForScore()
// directly via reflection.
if (!defined('BCC_STOKE_DECAY_WINDOW_HOURS')) {
    define('BCC_STOKE_DECAY_WINDOW_HOURS', 48);
}
if (!defined('BCC_STOKE_STAGE_2_MIN')) {
    define('BCC_STOKE_STAGE_2_MIN', 3);
}
if (!defined('BCC_STOKE_STAGE_3_MIN')) {
    define('BCC_STOKE_STAGE_3_MIN', 8);
}
if (!defined('BCC_STOKE_STAGE_4_MIN')) {
    define('BCC_STOKE_STAGE_4_MIN', 20);
}
if (!defined('BCC_STOKE_STAGE_5_MIN')) {
    define('BCC_STOKE_STAGE_5_MIN', 50);
}

// Slice 4 — §J.3.2.1 pre-consensus band edge. Read by
// AttestationService::isPreConsensusPick(), exercised in
// AttestationSlotGraduationTest via reflection.
if (!defined('BCC_RELIABILITY_PRECONSENSUS_MAX_ORDER')) {
    define('BCC_RELIABILITY_PRECONSENSUS_MAX_ORDER', 5);
}

// Claim-verified ranking bonus — read by
// ScoreReadService::computeRankingScore(), pinned in
// RankingClaimVerifiedBonusTest.
if (!defined('BCC_RANK_CLAIM_VERIFIED_BONUS')) {
    define('BCC_RANK_CLAIM_VERIFIED_BONUS', 10.0);
}

require_once __DIR__ . '/../vendor/autoload.php';
