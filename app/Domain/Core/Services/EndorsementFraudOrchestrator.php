<?php
/**
 * Endorsement Fraud Orchestrator
 *
 * Coordinates fraud detection, device fingerprinting, behavioral analysis,
 * and trust graph checks for endorsement operations.
 *
 * @package BCC\Trust\Core\Services
 * @version 2.1.0
 */

namespace BCC\Trust\Core\Services;

use Exception;
use BCC\Trust\Core\Repositories\ScoreRepository;
use BCC\Trust\Core\Repositories\FraudAnalysisRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\FraudDetector;
use BCC\Trust\Core\Security\DeviceFingerprinter;
use BCC\Trust\Core\Services\PeepSoPageResolver;

if (!defined('ABSPATH')) {
    exit;
}

class EndorsementFraudOrchestrator {

    private ScoreRepository $scoreRepo;
    private FraudAnalysisRepository $fraudAnalysisRepo;
    private DeviceFingerprinter $fingerprinter;

    public function __construct(
        ScoreRepository         $scoreRepo,
        FraudAnalysisRepository $fraudAnalysisRepo,
        DeviceFingerprinter     $fingerprinter
    ) {
        $this->scoreRepo          = $scoreRepo;
        $this->fraudAnalysisRepo  = $fraudAnalysisRepo;
        $this->fingerprinter      = $fingerprinter;
    }

    /**
     * Run all pre-endorsement fraud and risk checks.
     *
     * Delegates fraud analysis to FraudDetector::analyzeFraud() — the single
     * entry point for all fraud signal collection (behavioral, device, trust
     * graph, rule-based). Extracts signals from the analysis result.
     *
     * BLOCKING checks (throw Exception, reject endorsement):
     *   - Fraud score > BCC_TRUST_FRAUD_HIGH
     *   - Vote ring membership (detected by FraudDetector via TrustGraph)
     *
     * SIGNAL-ONLY side effects:
     *   - Device fingerprint stored for this request
     *
     * @param array<string, mixed>|null $fingerprintData  Ignored — client hash
     *        is never trusted; fingerprint is always derived server-side.
     *        Parameter retained for signature compatibility with callers.
     * @return array<string, mixed>
     * @throws Exception If fraud score exceeds threshold or vote ring detected.
     */
    public function runPreEndorsementChecks(int $endorserUserId, int $pageId, ?array $fingerprintData): array {
        unset($fingerprintData); // explicitly ignored (see docblock)
        // Hot-path cache: the FraudDetector pipeline (behavioral + device +
        // trust graph) is the CPU cost of this request. Same-user bursts
        // (one user endorsing several pages in a session) re-enter this
        // method each time, and EndorsementService::endorsePage fires
        // FraudDetector::clearCache() on commit — so the inner 1-hour
        // cache is busted on every write. A 30-second outer cache absorbs
        // that: block checks and fingerprint side effects still run on
        // every call, so a user whose score crosses HIGH mid-window is
        // still rejected on their next attempt.
        // Audit HIGH-5: cache window reduced from 30s to 5s. The async
        // EndorsementFraudAnalyzer that re-evaluates signals post-commit
        // runs via wp_schedule_single_event, which can lag 30-120s on
        // traffic-light sites. A 30s cache let a burst attacker land
        // many endorsements past the pre-check before any increase in
        // fraud_score became visible. 5s still absorbs legitimate
        // double-clicks but forces re-analysis fast enough to catch a
        // score that crosses the threshold mid-burst.
        $cacheKey      = 'pre_endorse_analysis_' . $endorserUserId;
        $fraudAnalysis = wp_cache_get($cacheKey, 'bcc_trust');
        if (!is_array($fraudAnalysis)) {
            $fraudAnalysis = FraudDetector::analyzeFraud($endorserUserId);
            wp_cache_set($cacheKey, $fraudAnalysis, 'bcc_trust', 5);
        }

        // BLOCKING: high fraud score.  Comparator is >= to match every
        // other threshold gate in the system (FraudDetector::analyzeFraud,
        // EndorsementFraudAnalyzer::run, RateLimiter::getAdjustedLimit,
        // in-transaction re-check in EndorsementService).  The prior '>'
        // let an attacker calibrate signals to land exactly on the
        // threshold value, passing the pre-check while only tripping the
        // post-commit audit log (audit HIGH-4).
        if ($fraudAnalysis['score'] >= BCC_TRUST_FRAUD_HIGH) {
            AuditLogger::log('suspicious_endorse_attempt', $pageId, [
                'endorser_id' => $endorserUserId,
                'fraud_score' => $fraudAnalysis['score'],
                'risk_level'  => $fraudAnalysis['risk_level'],
                'triggers'    => $fraudAnalysis['triggers'],
            ], 'page');
            throw new Exception('Account under review. Please contact support@bluecollarcrypto.io');
        }

        // BLOCKING: vote ring membership (FraudDetector already checked this)
        if (in_array('in_vote_ring', $fraudAnalysis['triggers'], true)) {
            AuditLogger::log('vote_ring_endorse_attempt', $pageId, [
                'endorser_id' => $endorserUserId,
            ], 'page');
            throw new Exception('Suspicious activity detected. Please contact support.');
        }

        // SIDE EFFECT: store fingerprint for this request (always runs —
        // device tracking must record every attempt, not just cache-misses).
        // SECURITY: client-supplied fingerprint.hash is ignored; an attacker
        // can pick random strings per sock-puppet to evade shared-device detection.
        // Always derive server-side.
        $fingerprint    = $this->fingerprinter->generateFingerprint();
        $automationData = $this->fingerprinter->detectAutomation();
        $this->fingerprinter->storeFingerprint($endorserUserId, $fingerprint, $automationData);

        // Extract signals from the unified analysis for weight calculation
        $details = $fraudAnalysis['details'] ?? [];

        return [
            'fraud_analysis'    => $fraudAnalysis,
            'automation_data'   => $automationData,
            'multi_account_risk' => isset($details['shared_device_count']) && $details['shared_device_count'] > 3,
            'behavior'          => [
                'behavior_score' => $details['behavior_score'] ?? 0.0,
                'flags'          => $details['behavior_flags'] ?? [],
            ],
            'trust_rank'        => (float) ($details['trust_rank'] ?? 0),
        ];
    }

    // ======================================================
    // DEFENSE: Endorsement-to-Vote Ratio Detection
    // ======================================================

    /**
     * Flag pages where endorsement count is suspiciously high relative to votes.
     *
     * This is a signal-only check — it does NOT block the endorsement.
     * Stores the alert in fraud_analysis for review.
     *
     * @param int $pageId
     */
    public function checkEndorsementVoteRatio(int $pageId): void {
        $score = $this->scoreRepo->getByPageId($pageId);
        if (!$score) {
            return;
        }

        $voteCount        = $score->getVoteCount();
        $endorsementCount = $score->getEndorsementCount();

        // Only flag when there's enough data to be meaningful
        if ($voteCount < 1 || $endorsementCount < 3) {
            return;
        }

        $threshold = $voteCount * BCC_TRUST_ENDORSE_VOTE_RATIO_THRESHOLD;

        if ($endorsementCount > $threshold) {
            $pageOwnerId = PeepSoPageResolver::getOwnerId($pageId);

            $this->fraudAnalysisRepo->storeAnalysisWithAutoRisk(
                $pageOwnerId,
                min(100, 30 + (int) (($endorsementCount / max(1, $voteCount)) * 5)),
                ['endorsement_vote_ratio_anomaly'],
                [
                    'page_id'           => $pageId,
                    'vote_count'        => $voteCount,
                    'endorsement_count' => $endorsementCount,
                    'ratio'             => round($endorsementCount / max(1, $voteCount), 2),
                    'threshold'         => BCC_TRUST_ENDORSE_VOTE_RATIO_THRESHOLD,
                ]
            );

            AuditLogger::log('endorsement_vote_ratio_alert', $pageId, [
                'vote_count'        => $voteCount,
                'endorsement_count' => $endorsementCount,
                'ratio'             => round($endorsementCount / max(1, $voteCount), 2),
            ], 'page');
        }
    }

    // ======================================================
    // DEFENSE: Temporal Coordination Detection
    // ======================================================

    /**
     * Detect coordinated endorsement bursts on a page.
     *
     * If 5+ distinct users endorse the same page within 120 seconds,
     * log a coordination pattern via FraudAnalysisRepository.
     *
     * This is a signal-only check — it does NOT block the endorsement.
     *
     * @param int $pageId
     * @param int $endorserUserId
     */
    public function detectTemporalCoordination(int $pageId, int $endorserUserId): void {
        $endorseRepo = \BCC\Trust\Core\Plugin::instance()->endorsementRepository();
        $window    = BCC_TRUST_COORDINATION_WINDOW_SECONDS;
        $threshold = BCC_TRUST_COORDINATION_ACTION_THRESHOLD;

        // Count distinct endorsers within the coordination window
        $recentCount = $endorseRepo->countDistinctEndorsersInWindow($pageId, $window);

        // Also check a wider 1-hour window with a higher threshold
        $hourlyCount = $endorseRepo->countDistinctEndorsersInWindow($pageId, 3600);

        if (($hourlyCount + 1) >= ($threshold * 3)) {
            $pageOwnerId = PeepSoPageResolver::getOwnerId($pageId);

            $this->fraudAnalysisRepo->storeAnalysisWithAutoRisk(
                $pageOwnerId,
                min(100, 20 + ($hourlyCount * 3)),
                ['hourly_endorsement_velocity'],
                [
                    'page_id'      => $pageId,
                    'window_secs'  => 3600,
                    'action_count' => $hourlyCount + 1,
                ]
            );

            AuditLogger::log('endorsement_hourly_velocity_alert', $pageId, [
                'action_count' => $hourlyCount + 1,
                'window'       => '1_hour',
            ], 'page');
        }

        // +1 for the current endorser (not yet committed)
        if (($recentCount + 1) >= $threshold) {
            // Get the endorser IDs involved
            $endorserIds = $endorseRepo->getRecentEndorserIds($pageId, $window);
            $endorserIds[] = $endorserUserId;

            $pageOwnerId = PeepSoPageResolver::getOwnerId($pageId);

            $this->fraudAnalysisRepo->storeAnalysisWithAutoRisk(
                $pageOwnerId,
                min(100, 25 + ($recentCount * 5)),
                ['temporal_coordination_endorsement'],
                [
                    'page_id'      => $pageId,
                    'window_secs'  => $window,
                    'action_count' => $recentCount + 1,
                    'endorser_ids' => array_map('intval', array_unique($endorserIds)),
                ]
            );

            AuditLogger::log('temporal_coordination_detected', $pageId, [
                'type'         => 'endorsement',
                'action_count' => $recentCount + 1,
                'window_secs'  => $window,
                'endorser_ids' => array_map('intval', array_unique($endorserIds)),
            ], 'page');
        }
    }

    /**
     * Update fraud score in user_info table based on endorsement activity
     */
    /**
     * Re-evaluate and persist the user's fraud score after endorsement.
     *
     * Delegates to FraudDetector::getEnhancedFraudScore() which runs the
     * full pipeline and updates user_info + fraud_analysis atomically.
     *
     * @param array<string, mixed> $fraudAnalysis
     * @param array<string, mixed> $automationData
     */
    public function updateFraudScore(int $userId, array $fraudAnalysis, array $automationData): void {
        // Only re-evaluate when signals indicate risk.
        if (!$automationData['is_automated'] && $fraudAnalysis['risk_level'] !== 'high') {
            return;
        }

        FraudDetector::clearCache($userId);
        $newScore = FraudDetector::getEnhancedFraudScore($userId);

        if ($newScore > $fraudAnalysis['score']) {
            AuditLogger::log('fraud_score_increased', $userId, [
                'old_score' => $fraudAnalysis['score'],
                'new_score' => $newScore,
                'reason'    => 'endorsement_activity',
                'automation' => $automationData['is_automated'],
            ], 'user');
        }
    }
}
