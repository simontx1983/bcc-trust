<?php
/**
 * ContributionRecoveryEvaluator — the daily batch that turns sustained
 * contribution into gradual trust recovery.
 *
 * For each recovery-eligible user it:
 *   1. computes the capped contribution+consistency bonus (ContributionScoreService),
 *   2. persists it to `bcc_trust_reputation.contribution_bonus` (the INPUT),
 *   3. re-derives reputation_score + tier via recalculateUserReputation
 *      (which blends the bonus under the Rule-2 ceiling — the OUTPUT),
 *   4. audit-logs a real adjustment.
 *
 * Scope (MVP): caution + risky users — the population that actually needs
 * recovery, which also bounds the batch naturally. Reinforcement for
 * already-Neutral users is a deliberate later extension.
 *
 * Off the request path (daily cron only). A per-user failure is isolated
 * and surfaced as a `contribution_recovery` DegradationMetric rather than
 * aborting the whole batch.
 *
 * @package BCC\Trust\Core\Services
 * @since V1 (2026-06-22)
 */

namespace BCC\Trust\Core\Services;

use BCC\Core\Observability\DegradationMetrics;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Security\AuditLogger;

if (!defined('ABSPATH')) {
    exit;
}

final class ContributionRecoveryEvaluator
{
    /** Only re-audit / re-log when the bonus actually moved by at least this much. */
    private const CHANGE_FLOOR = 0.01;

    public function __construct(
        private readonly ContributionScoreService $contributionScore,
        private readonly ReputationRepository $reputationRepo,
        private readonly ReputationCalculatorService $reputationCalc
    ) {
    }

    /**
     * Evaluate the recovery-eligible cohort. Returns a small summary for
     * the cron caller's log line.
     *
     * @return array{evaluated: int, adjusted: int, failed: int}
     */
    public function runDaily(int $maxUsers = 1000): array
    {
        $userIds  = $this->reputationRepo->getCautionAndRiskyUserIds($maxUsers);
        $evaluated = 0;
        $adjusted  = 0;
        $failed    = 0;

        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            if ($userId <= 0) {
                continue;
            }
            $evaluated++;

            try {
                $components = $this->contributionScore->computeBonus($userId);
                $newBonus   = round($components['contribution'] + $components['consistency'], 2);
                $oldBonus   = $this->reputationRepo->getContributionBonus($userId);

                // Persist the input, then re-derive reputation_score + tier
                // (the blend applies the ceiling). Always re-derive so a
                // genuine-score change since the last run is reflected too.
                $this->reputationRepo->update($userId, ['contribution_bonus' => $newBonus]);
                $this->reputationCalc->recalculateUserReputation($userId, 'contribution_recovery');

                if (abs($newBonus - $oldBonus) >= self::CHANGE_FLOOR) {
                    $adjusted++;
                    AuditLogger::log(
                        'contribution_recovery_adjustment',
                        $userId,
                        [
                            'previous_bonus' => $oldBonus,
                            'new_bonus'      => $newBonus,
                            'contribution'   => round($components['contribution'], 2),
                            'consistency'    => round($components['consistency'], 2),
                        ],
                        'user',
                        $userId
                    );
                }
            } catch (\Throwable $e) {
                $failed++;
                DegradationMetrics::record('contribution_recovery', 'user_eval_failed');
                \BCC\Core\Log\Logger::error('[bcc-trust] contribution_recovery eval failed', [
                    'user_id' => $userId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return ['evaluated' => $evaluated, 'adjusted' => $adjusted, 'failed' => $failed];
    }
}
