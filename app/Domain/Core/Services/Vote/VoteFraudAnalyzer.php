<?php
/**
 * Vote Fraud Analyzer
 *
 * Asynchronous fraud analysis stage of the vote pipeline.
 *
 * Triggered by wp_schedule_single_event() after the vote transaction has
 * committed and the HTTP response has been sent. Never called inline during
 * a vote request.
 *
 * Delegates all fraud signal collection and scoring to FraudDetector —
 * the single entry point for fraud analysis across the entire trust engine.
 *
 * Responsibilities:
 *  - Load the stored vote row to verify it still exists and is active
 *  - Run fraud analysis via FraudDetector::getEnhancedFraudScore()
 *  - Log the outcome via AuditLogger
 *  - Detect and log downvote velocity on the target page
 *
 * @package BCC\Trust\Core\Services\Vote
 * @version 2.0.0
 */

namespace BCC\Trust\Core\Services\Vote;

use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\FraudDetector;
use BCC\Trust\Core\Support\CacheManager;

if (!defined('ABSPATH')) {
    exit;
}

class VoteFraudAnalyzer {

    /**
     * WordPress action hook name.
     * Registered in bootstrap.php; scheduled by VoteService after commit.
     */
    public const HOOK = 'bcc_trust_async_fraud_analysis';

    public function __construct(
        private readonly VoteRepository $voteRepo,
    ) {}

    /**
     * Schedule this analyzer to run after the current request completes.
     */
    public static function schedule(int $voteId): void {
        wp_schedule_single_event(time(), self::HOOK, [$voteId]);
    }

    /**
     * Entry point called by WordPress cron via the registered hook.
     *
     * Delegates to FraudDetector::getEnhancedFraudScore() which runs the
     * full fraud pipeline (rule-based + behavioral + device + trust graph),
     * stores results in user_info and fraud_analysis tables, and returns
     * the blended score.
     */
    public function run(int $voteId): void {
        $vote = $this->voteRepo->getById($voteId);

        if (!$vote || (int) $vote->status !== 1) {
            return;
        }

        $voterId  = (int) $vote->voter_user_id;
        $pageId   = (int) $vote->page_id;
        $voteType = (int) $vote->vote_type;

        // Single call to the unified fraud pipeline.
        // getEnhancedFraudScore() handles: signal collection, scoring,
        // user_info update, fraud_analysis persistence — all in one pass.
        $fraudScore = FraudDetector::getEnhancedFraudScore($voterId);

        // Bust fraud + user_info caches so the next vote on any page uses
        // the freshly-computed fraud_score — closes the stale-influence window.
        FraudDetector::clearCache($voterId);
        CacheManager::delete("user_info_{$voterId}", 'bcc_trust', 'post_fraud_analysis');

        if ($fraudScore >= BCC_TRUST_FRAUD_MEDIUM) {
            AuditLogger::log('fraud_analysis_vote', $pageId, [
                'voter_id'    => $voterId,
                'fraud_score' => $fraudScore,
                'risk_level'  => FraudDetector::getRiskLevel($fraudScore),
            ], 'page', $voterId);
        }

        if ($voteType === -1) {
            $this->checkDownvoteVelocity($voterId, $pageId);
        }

        // Fan-in coordination detection: catches one-directional vote rings
        // where N accounts all target the same page without cross-voting.
        FraudDetector::detectFanInCoordination($pageId, $voterId);
    }

    /**
     * Log an alert when a page receives five or more downvotes within one hour.
     */
    private function checkDownvoteVelocity(int $voterId, int $pageId): void {
        $recent = $this->voteRepo->countRecentDownvotesForPage($pageId, '1 HOUR');

        if ($recent >= 5) {
            AuditLogger::log('downvote_velocity_alert', $pageId, [
                'voter_id'        => $voterId,
                'downvotes_in_1h' => $recent,
            ], 'page', $voterId);
        }
    }
}
