<?php
/**
 * ContributionRecoveryEvaluator — the daily batch that turns sustained
 * contribution into gradual trust recovery.
 *
 * For each recovery-eligible user it:
 *   1. computes the capped contribution+consistency bonus (ContributionScoreService),
 *   2. writes it to the member's self-page `contribution_bonus` column
 *      (Architecture A) via ScoreRepository::applyContributionBonus, which
 *      recomputes the self-page total_score + reputation_tier inline under
 *      the canonical formula — so no separate reputation recalc is needed,
 *   3. audit-logs a real adjustment.
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
        private readonly ReputationRepository $reputationRepo
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
                $rawBonus   = round($components['contribution'] + $components['consistency'], 2);
                $oldBonus   = $this->reputationRepo->getContributionBonus($userId);

                // Rule R2 — contribution alone can lift a member toward Neutral
                // but never into Trusted. The self-page formula adds
                // contribution_bonus RAW, so the ceiling must be applied here
                // (the legacy recalc applied it via blendContribution at blend
                // time). Cap the bonus so genuine + bonus can't exceed
                // BCC_CONTRIB_CEILING while the genuine (non-contribution) score
                // is below Trusted. blendContribution is the single source of
                // the ceiling rule — we derive the writable bonus back out of it.
                $genuine        = $this->reputationRepo->getScore($userId) - $oldBonus;
                $effectiveTotal = ReputationCalculatorService::blendContribution($genuine, $rawBonus);
                $newBonus       = round(max(0.0, $effectiveTotal - $genuine), 2);

                // Write the (ceiling'd) bonus directly onto the member's
                // self-page (Architecture A). applyContributionBonus SETs
                // contribution_bonus and recomputes total_score +
                // reputation_tier inline via the canonical formula.
                $pageId = \BCC\Trust\Core\Plugin::instance()
                    ->memberSelfPageService()
                    ->ensureSelfPage($userId);
                \BCC\Trust\Core\Plugin::instance()
                    ->scoreRepository()
                    ->applyContributionBonus($pageId, $newBonus);

                if (abs($newBonus - $oldBonus) >= self::CHANGE_FLOOR) {
                    $adjusted++;
                    AuditLogger::log(
                        'contribution_recovery_adjustment',
                        $userId,
                        [
                            'previous_bonus' => $oldBonus,
                            'new_bonus'      => $newBonus,
                            'raw_bonus'      => $rawBonus,
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
