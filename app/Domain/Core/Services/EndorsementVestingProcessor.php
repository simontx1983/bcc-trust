<?php
/**
 * Endorsement Vesting Processor
 *
 * Batch-updates endorsement vesting stages and weights, then flags
 * affected page scores for recalculation so endorsement_bonus is
 * re-derived from the endorsements table (source of truth).
 *
 * The recalculation happens via the 5-minute processRecalculations
 * cron, which calls VoteService::recalculateScore(). That method
 * derives endorsement_bonus from SUM(endorsements.weight) — ensuring
 * the graduated weights are reflected in total_score.
 *
 * @package BCC\Trust\Core\Services
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Core\Support\CacheManager;

if (!defined('ABSPATH')) {
    exit;
}

class EndorsementVestingProcessor {

    /**
     * Batch-update endorsement vesting stages and weights.
     *
     * Called by cron to graduate endorsements through the unified 5-stage
     * vesting model (shared with votes). After graduating, flags affected
     * pages for score recalculation so that endorsement_bonus is re-derived
     * from the endorsements table and total_score is recomputed.
     *
     * @return int Number of endorsements updated.
     */
    public function process(): int {
        $endorseRepo = Plugin::instance()->endorsementRepository();
        $now         = current_time('mysql');
        $updated     = 0;

        // Unified 5-stage transitions using shared constants from trust-weights.php.
        // Each: [from_stage, to_stage, from_factor, to_factor, day_threshold]
        $transitions = [
            [0, 1, BCC_TRUST_VESTING_STAGE_0_PCT, BCC_TRUST_VESTING_STAGE_1_PCT, BCC_TRUST_VESTING_STAGE_1_DAYS],
            [1, 2, BCC_TRUST_VESTING_STAGE_1_PCT, BCC_TRUST_VESTING_STAGE_2_PCT, BCC_TRUST_VESTING_STAGE_2_DAYS],
            [2, 3, BCC_TRUST_VESTING_STAGE_2_PCT, BCC_TRUST_VESTING_STAGE_3_PCT, BCC_TRUST_VESTING_STAGE_3_DAYS],
            [3, 4, BCC_TRUST_VESTING_STAGE_3_PCT, BCC_TRUST_VESTING_STAGE_4_PCT, BCC_TRUST_VESTING_STAGE_4_DAYS],
        ];

        // Wrap each transition in a transaction so graduateVestingStage
        // (weight UPDATEs) and flagScoresForGraduatedEndorsements (score
        // UPDATE) commit atomically. Without this, a cron failure between
        // the two calls leaves weights bumped but scores not flagged —
        // the graduated values stay invisible until the next unrelated
        // write re-flags the page.
        foreach ($transitions as [$fromStage, $toStage, $fromFactor, $toFactor, $days]) {
            try {
                $result = TransactionManager::run(function () use (
                    $endorseRepo, $fromStage, $toStage, $fromFactor, $toFactor, $now, $days
                ) {
                    $graduated = $endorseRepo->graduateVestingStage(
                        $fromStage, $toStage, $fromFactor, $toFactor, $now, $days
                    );
                    if ($graduated > 0) {
                        $endorseRepo->flagScoresForGraduatedEndorsements($toStage, $now, $days);
                    }
                    return $graduated;
                });
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] vesting graduation transaction failed', [
                    'from'  => $fromStage,
                    'to'    => $toStage,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }
            // TransactionManager::run returns mixed per its signature;
            // the closure above returns an int via graduateVestingStage,
            // but PHPStan cannot narrow the mixed return. Cast explicitly
            // so $updated stays a plain int and the method's declared
            // return type is honoured.
            $resultInt = (int) $result;
            if ($resultInt > 0) {
                $updated += $resultInt;
            }
        }

        if ($updated > 0) {
            // Invalidate caches for all pages with graduated endorsements
            $affectedPageIds = $endorseRepo->getFlaggedPageIds(500);
            foreach ($affectedPageIds as $pid) {
                CacheManager::invalidatePageCaches((int) $pid, null, 'endorsement_vesting');
            }

            AuditLogger::log('endorsement_vesting_batch', 0, [
                'endorsements_graduated' => $updated,
                'pages_cache_busted'     => count($affectedPageIds),
            ], 'system');
        }

        return $updated;
    }
}
