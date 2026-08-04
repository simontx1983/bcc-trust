<?php
/**
 * Calibration harness bootstrap — Rank Phase 9.
 *
 * Standalone: NO WordPress, NO database, NO staging mutation. ABSPATH is
 * defined only to satisfy the `if (!defined('ABSPATH')) exit;` file
 * guards on the production classes; nothing WordPress-shaped is loaded
 * or called. The REAL production calculators are driven through the
 * composer autoload:
 *
 *   REAL (executed verbatim):
 *     - RankScoringConfig::fromDefaultFile()  (includes/config/rank-scoring.php)
 *     - RankScoreCalculator::calculate() / decayFor()
 *     - RankEvidenceIngestor::allocateWithinCap()  (the §4.4 event-cap allocator)
 *     - VoteWeightCalculator::calculate()/maturity()/trustMultiplier()/rankMultiplier()
 *     - FraudDiscountCalculator::compute()  (constants from includes/config/fraud-detection.php)
 *
 *   MIRRORED (documented in README.md — not reachable without WP/DB):
 *     - RankPromotionEngine::assess() requirement predicate  → lib/RequirementsMirror.php
 *     - PollService quorum/majority/suspected-cap arithmetic → lib/PollMath.php
 *     - RankEvidenceIngestor ingest routing + R3 zero-credit → lib/MemberSimulator.php
 *     - Tier-days window counting (TierDaysRepository)       → RequirementsMirror::modeledWindows()
 *
 * All timestamps derive from the fixed CALIB_EPOCH — never now().
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    // Satisfies file guards only; no WP file is ever loaded from it.
    define('ABSPATH', dirname(__DIR__, 2) . '/');
}

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

// Pure define()s consumed by FraudDiscountCalculator::compute().
require_once dirname(__DIR__, 2) . '/includes/config/fraud-detection.php';

require_once __DIR__ . '/lib/Stats.php';
require_once __DIR__ . '/lib/PollMath.php';
require_once __DIR__ . '/lib/RequirementsMirror.php';
require_once __DIR__ . '/lib/MemberSimulator.php';

/** Day 0 of every simulation. Fixed; timestamps never come from now(). */
const CALIB_EPOCH = '2026-01-01';

/** Simulation horizon for the honest-cohort day loop. */
const CALIB_MAX_DAY = 400;

function calib_epoch_ts(): int
{
    $ts = strtotime(CALIB_EPOCH . ' 00:00:00 UTC');
    if ($ts === false) {
        throw new RuntimeException('calibration: bad epoch');
    }
    return $ts;
}

function calib_day_to_date(int $day): string
{
    return gmdate('Y-m-d', calib_epoch_ts() + $day * 86400);
}

function calib_day_to_datetime(int $day): string
{
    return gmdate('Y-m-d H:i:s', calib_epoch_ts() + $day * 86400);
}

function calib_config(): \BCC\Trust\Rank\Support\RankScoringConfig
{
    static $config = null;
    if ($config === null) {
        $config = \BCC\Trust\Rank\Support\RankScoringConfig::fromDefaultFile();
    }
    return $config;
}

function calib_calculator(): \BCC\Trust\Rank\Services\RankScoreCalculator
{
    static $calc = null;
    if ($calc === null) {
        $calc = new \BCC\Trust\Rank\Services\RankScoreCalculator(calib_config());
    }
    return $calc;
}

function calib_vote_weight_calculator(): \BCC\Trust\Core\Services\Vote\VoteWeightCalculator
{
    static $calc = null;
    if ($calc === null) {
        $calc = new \BCC\Trust\Core\Services\Vote\VoteWeightCalculator(calib_config());
    }
    return $calc;
}
