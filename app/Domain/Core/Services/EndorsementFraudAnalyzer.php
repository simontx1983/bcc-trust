<?php
/**
 * Endorsement Fraud Analyzer
 *
 * Asynchronous fraud analysis stage of the endorsement pipeline.
 *
 * Triggered via wp_schedule_single_event() after the endorsement transaction
 * has committed. Delegates all fraud signal collection and scoring to
 * FraudDetector — the single entry point for fraud analysis.
 *
 * @package BCC\Trust\Core\Services
 * @version 2.0.0
 */

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\EndorsementRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\FraudDetector;
use BCC\Trust\Core\Support\CacheManager;

if (!defined('ABSPATH')) {
    exit;
}

class EndorsementFraudAnalyzer {

    /**
     * WordPress action hook name.
     * Registered in hooks.php; scheduled by EndorsementService after commit.
     */
    public const HOOK = 'bcc_trust_async_endorsement_fraud_analysis';

    public function __construct(
        private readonly EndorsementRepository $endorsementRepo,
    ) {}

    /**
     * Schedule this analyzer to run after the current request completes.
     *
     * Delegates to AsyncDispatcher so the call lands on Action Scheduler
     * when available and falls back to wp_schedule_single_event otherwise
     * (parity with the rest of the trust async surface).
     *
     * Observability: a soft-failed enqueue means fraud analysis never runs
     * for this endorsement — the user keeps any trust bonus they should
     * not have. There is NO reconciliation sweep for endorsement fraud
     * scoring, so a silent loss is unrecoverable without manual replay.
     * We record `cron_dispatch / endorsement_fraud_analyzer` on the
     * canonical-subsystem map and emit a structured Logger::error so the
     * operator journal carries the full context.
     */
    public static function schedule(int $endorserUserId, int $pageId): void {
        $enqueued = \BCC\Core\Cron\AsyncDispatcher::enqueueAsync(
            self::HOOK,
            [$endorserUserId, $pageId],
            'bcc-trust'
        );

        if (!$enqueued) {
            \BCC\Core\Observability\DegradationMetrics::record('cron_dispatch', 'endorsement_fraud_analyzer');
            \BCC\Core\Log\Logger::error('[bcc-trust] endorsement fraud analyzer enqueue failed', [
                'hook'        => self::HOOK,
                'endorser_id' => $endorserUserId,
                'page_id'     => $pageId,
            ]);
        }
    }

    /**
     * Entry point called by WordPress cron via the registered hook.
     *
     * Delegates to FraudDetector::getEnhancedFraudScore() which runs the
     * full fraud pipeline (rule-based + behavioral + device + trust graph),
     * stores results in user_info and fraud_analysis tables, and returns
     * the blended score.
     */
    public function run(int $endorserUserId, int $pageId): void {
        // Verify the endorsement still exists and is active
        if (!$this->endorsementRepo->hasEndorsed($endorserUserId, $pageId)) {
            return;
        }

        // Single call to the unified fraud pipeline.
        $fraudScore = FraudDetector::getEnhancedFraudScore($endorserUserId);

        // Bust fraud + user_info caches so the next action by this user
        // uses the freshly-computed fraud_score — closes the stale-influence window.
        FraudDetector::clearCache($endorserUserId);
        CacheManager::delete("user_info_{$endorserUserId}", 'bcc_trust', 'post_fraud_analysis');

        if ($fraudScore >= BCC_TRUST_FRAUD_MEDIUM) {
            AuditLogger::log('fraud_analysis_endorsement', $pageId, [
                'endorser_id' => $endorserUserId,
                'fraud_score' => $fraudScore,
                'risk_level'  => FraudDetector::getRiskLevel($fraudScore),
            ], 'page', $endorserUserId);
        }
    }
}
