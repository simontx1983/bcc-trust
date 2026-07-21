<?php
/**
 * Fraud Detector
 *
 * Comprehensive fraud detection using all available data sources
 * Uses config constants for thresholds and weights
 *
 * @package BCC\Trust\Core\Security
 * @version 2.0.0
 */

namespace BCC\Trust\Core\Security;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VerificationRepository;
use BCC\Trust\Core\Repositories\FraudAnalysisRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Repositories\AuditLogRepository;

class FraudDetector {

    /**
     * Cache group for fraud detection results
     */
    const CACHE_GROUP = 'bcc_fraud';

    /**
     * Cache TTL — lazy-loaded to avoid compile-time constant evaluation.
     * PHP evaluates class constants at autoload time, which can happen
     * before config.php defines BCC_TRUST_CACHE_FRAUD. Using a static
     * method defers evaluation until first use (same pattern as RateLimiter).
     */
    private static function cacheTtl(): int
    {
        return defined('BCC_TRUST_CACHE_FRAUD') ? BCC_TRUST_CACHE_FRAUD : 300;
    }

    private static ?UserInfoRepository $userInfoRepo = null;
    private static ?FraudAnalysisRepository $fraudAnalysisRepo = null;
    private static ?VoteRepository $voteRepo = null;
    private static ?AuditLogRepository $auditLogRepo = null;

    private static function userInfoRepo(): UserInfoRepository {
        return self::$userInfoRepo ??= \BCC\Trust\Core\Plugin::instance()->userInfoRepository();
    }

    private static function fraudAnalysisRepo(): FraudAnalysisRepository {
        return self::$fraudAnalysisRepo ??= \BCC\Trust\Core\Plugin::instance()->fraudAnalysisRepository();
    }

    private static function voteRepo(): VoteRepository {
        return self::$voteRepo ??= \BCC\Trust\Core\Plugin::instance()->voteRepository();
    }

    private static function auditLogRepo(): AuditLogRepository {
        // AuditLogRepository has no Plugin singleton accessor yet.
        return self::$auditLogRepo ??= new AuditLogRepository();
    }

    /**
     * Detect rapid voting using config thresholds
     */
    public static function detectRapidVoting(int $userId): bool {

        $count = self::auditLogRepo()->countRecentVoteActions($userId, BCC_TRUST_RAPID_VOTE_WINDOW);

        return $count > BCC_TRUST_RAPID_VOTE_LIMIT;
    }

    /**
     * Detect vote ring using config thresholds
     */
    public static function detectVoteRing(int $userId): bool {

        $mutual = self::voteRepo()->countMutualVotePages($userId);

        return $mutual > BCC_TRUST_RING_MIN_MUTUAL;
    }

    /**
     * Detect same IP voting for multiple different pages rapidly
     */
    public static function detectMultiAccountVoting(string $ip, int $userId): bool {

        // Skip private/loopback IPs — they produce false positives on local/shared networks
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Convert IP to binary for lookup
        $ipBinary = null;
        if ($ip && $ip !== 'unknown') {
            $ipBinary = inet_pton($ip);
        }

        if (!$ipBinary) {
            return false;
        }

        $users = self::auditLogRepo()->countDistinctUsersForIp($ipBinary, $userId, BCC_TRUST_MULTI_ACCOUNT_WINDOW);

        return $users > BCC_TRUST_MULTI_ACCOUNT_LIMIT;
    }

    /**
     * Detect suspicious voting pattern (all votes same type)
     */
    public static function detectUniformVoting(int $userId): bool {

        $stats = self::voteRepo()->getVoteTypeStats($userId, 7);

        if (!$stats || $stats->total < BCC_TRUST_UNIFORM_VOTE_MIN) {
            return false;
        }

        $positivePercent = $stats->positive / $stats->total;
        return $positivePercent > BCC_TRUST_UNIFORM_VOTE_THRESHOLD
            || $positivePercent < (1 - BCC_TRUST_UNIFORM_VOTE_THRESHOLD);
    }

    /**
     * Detect rapid vote changes (flip-flopping)
     */
    public static function detectVoteChanging(int $userId, int $minutes = 30): bool {

        $changes = self::voteRepo()->countVoteChanges($userId, $minutes);

        return $changes > BCC_TRUST_VOTE_CHANGE_LIMIT;
    }

    /**
     * ======================================================
     * ENHANCED FRAUD DETECTION USING CONFIG CONSTANTS
     * ======================================================
     */

    /**
     * Comprehensive fraud analysis using all available data
     *
     * @param int $userId
     * @return array<string, mixed> Complete fraud analysis
     */
    public static function analyzeFraud(int $userId): array {

        // Check cache first
        $cached = wp_cache_get('fraud_analysis_' . $userId, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        // Get user info from user_info table
        $userInfo = self::userInfoRepo()->getByUserId($userId);

        $results = [
            'triggers' => [],
            'signals'  => [],
            'score' => 0,
            'confidence' => 0,
            'risk_level' => 'low',
            'details' => []
        ];

        // ======================================================
        // 1. Legacy fraud detection (existing methods)
        // ======================================================

        if (self::detectRapidVoting($userId)) {
            $results['triggers'][] = 'rapid_voting';
            $results['score'] += 30;
            $results['details']['rapid_voting'] = true;
            $results['signals'][] = [
                'type'        => 'rapid_voting',
                'points'      => 30,
                'description' => sprintf(
                    'Cast more than %d votes within %d minutes',
                    BCC_TRUST_RAPID_VOTE_LIMIT,
                    BCC_TRUST_RAPID_VOTE_WINDOW
                ),
            ];
        }

        if (self::detectUniformVoting($userId)) {
            $results['triggers'][] = 'uniform_voting';
            $results['score'] += 20;
            $results['details']['uniform_voting'] = true;
            $results['signals'][] = [
                'type'        => 'uniform_voting',
                'points'      => 20,
                'description' => 'Suspiciously consistent vote pattern (all up or all down)',
            ];
        }

        if (self::detectVoteRing($userId)) {
            $results['triggers'][] = 'vote_ring';
            $results['score'] += 25;
            $results['details']['vote_ring'] = true;
            $results['signals'][] = [
                'type'        => 'vote_ring',
                'points'      => 25,
                'description' => 'Mutual vote-exchange pattern detected with another user',
            ];
        }

        if (self::detectVoteChanging($userId)) {
            $results['triggers'][] = 'vote_changing';
            $results['score'] += 15;
            $results['details']['vote_changing'] = true;
            $results['signals'][] = [
                'type'        => 'vote_changing',
                'points'      => 15,
                'description' => 'Repeatedly changed votes on the same pages (flip-flopping)',
            ];
        }

        $ip = self::getUserIp($userId);
        if ($ip && self::detectMultiAccountVoting($ip, $userId)) {
            $results['triggers'][] = 'multi_account';
            $results['score'] += 25;
            $results['details']['multi_account'] = true;
            $results['signals'][] = [
                'type'        => 'multi_account',
                'points'      => 25,
                'description' => 'Multiple different accounts detected voting from the same IP address',
            ];
        }

        // ======================================================
        // 2. Device fingerprinting analysis (single aggregated fetch)
        // ======================================================

        $fingerprinter = \BCC\Trust\Core\Plugin::instance()->deviceFingerprinter();
        $deviceData    = $fingerprinter->getAggregatedFingerprintData($userId);

        $deviceFraudProbability = $deviceData['fraud_probability'];

        if ($deviceFraudProbability > 0.7) {
            $results['triggers'][] = 'high_device_fraud';
            $results['score'] += 30;
            $results['details']['device_fraud_probability'] = $deviceFraudProbability;
            $results['signals'][] = [
                'type'        => 'high_device_fraud',
                'points'      => 30,
                'description' => sprintf(
                    'Device fingerprint flagged as high fraud risk (%.0f%% probability)',
                    $deviceFraudProbability * 100
                ),
            ];
        } elseif ($deviceFraudProbability > 0.4) {
            $results['triggers'][] = 'medium_device_fraud';
            $results['score'] += 15;
            $results['details']['device_fraud_probability'] = $deviceFraudProbability;
            $results['signals'][] = [
                'type'        => 'medium_device_fraud',
                'points'      => 15,
                'description' => sprintf(
                    'Device fingerprint flagged as medium fraud risk (%.0f%% probability)',
                    $deviceFraudProbability * 100
                ),
            ];
        }

        // Check for multiple accounts on same device (uses pre-fetched data).
        if ($deviceData['shared_device']) {
            $userCount    = $deviceData['shared_device']['user_count'];
            $devicePoints = min(30, $userCount * 5);
            $results['triggers'][] = 'shared_device_' . $userCount . '_users';
            $results['score'] += $devicePoints;
            $results['details']['shared_device_count'] = $userCount;
            $results['signals'][] = [
                'type'        => 'shared_device',
                'points'      => $devicePoints,
                'description' => sprintf(
                    'Device fingerprint shared by %d different accounts',
                    $userCount
                ),
            ];
        }

        // ======================================================
        // 3. Behavioral analysis
        // ======================================================

        $behavioralAnalyzer = \BCC\Trust\Core\Plugin::instance()->behavioralAnalyzer();
        $behavior = $behavioralAnalyzer->analyzeUserBehavior($userId);

        $results['details']['behavior_score'] = $behavior['behavior_score'];
        $results['details']['behavior_flags'] = $behavior['flags'];

        if ($behavior['behavior_score'] > 70) {
            $results['triggers'][] = 'critical_behavior';
            $results['score'] += 40;
            $results['signals'][] = [
                'type'        => 'critical_behavior',
                'points'      => 40,
                'description' => sprintf(
                    'Behavioral analysis score %d/100 indicates critical risk',
                    $behavior['behavior_score']
                ),
            ];
        } elseif ($behavior['behavior_score'] > 50) {
            $results['triggers'][] = 'high_risk_behavior';
            $results['score'] += 25;
            $results['signals'][] = [
                'type'        => 'high_risk_behavior',
                'points'      => 25,
                'description' => sprintf(
                    'Behavioral analysis score %d/100 indicates high risk',
                    $behavior['behavior_score']
                ),
            ];
        } elseif ($behavior['behavior_score'] > 30) {
            $results['triggers'][] = 'suspicious_behavior';
            $results['score'] += 15;
            $results['signals'][] = [
                'type'        => 'suspicious_behavior',
                'points'      => 15,
                'description' => sprintf(
                    'Behavioral analysis score %d/100 indicates suspicious activity',
                    $behavior['behavior_score']
                ),
            ];
        }

        // Add specific behavior flags to triggers
        foreach ($behavior['flags'] as $flag) {
            $results['triggers'][] = 'behavior_' . $flag;
        }

        // ======================================================
        // 4. Trust graph analysis
        // ======================================================

        $trustGraph = \BCC\Trust\Core\Plugin::instance()->trustGraph();

        // Get trust rank from user_info table, with cached fallback to PageRank.
        $trustRank = $userInfo ? (float) $userInfo->trust_rank : 0;
        if (!$trustRank) {
            $cacheKey = 'trust_rank_' . $userId;
            $trustRank = wp_cache_get($cacheKey, self::CACHE_GROUP);
            if ($trustRank === false) {
                $trustRank = $trustGraph->calculateTrustRank($userId);
                // Cache for 1 hour — invalidated by clearCache() on vote/endorsement.
                wp_cache_set($cacheKey, $trustRank, self::CACHE_GROUP, 3600);
            }
            if ($userInfo) {
                self::userInfoRepo()->updateTrustRank($userId, (float) $trustRank);
            }
        }

        $results['details']['trust_rank'] = $trustRank;

        // Low trust rank increases fraud score (skip if rank is 0 — means not yet calculated)
        if ($trustRank > 0 && $trustRank < 0.2) {
            $results['triggers'][] = 'very_low_trust';
            $results['score'] += 30;
            $results['signals'][] = [
                'type'        => 'very_low_trust',
                'points'      => 30,
                'description' => sprintf(
                    'Trust rank is very low (%.2f) — user has minimal trust in the network',
                    $trustRank
                ),
            ];
        } elseif ($trustRank > 0 && $trustRank < 0.4) {
            $results['triggers'][] = 'low_trust';
            $results['score'] += 15;
            $results['signals'][] = [
                'type'        => 'low_trust',
                'points'      => 15,
                'description' => sprintf(
                    'Trust rank is below average (%.2f)',
                    $trustRank
                ),
            ];
        }

        // Check if user is in a vote ring — use cached results from hourly cron.
        // CronService stores results via wp_cache_set('ring_results', ..., 'bcc_trust_admin').
        $rings = wp_cache_get('ring_results', 'bcc_trust_admin');
        if ( ! is_array( $rings ) ) {
            // Fallback: compute only when no cached results are available
            $rings = $trustGraph->detectVoteRings( BCC_TRUST_RING_MIN_SIZE );
        }
        foreach ($rings as $ring) {
            if (in_array($userId, $ring['users'])) {
                $results['triggers'][] = 'in_vote_ring';
                $results['score'] += 40;
                $results['details']['ring_size'] = $ring['size'];
                $results['details']['ring_strength'] = $ring['strength'];
                $results['signals'][] = [
                    'type'        => 'in_vote_ring',
                    'points'      => 40,
                    'description' => sprintf(
                        'User is part of a vote ring with %d members (strength: %.2f)',
                        $ring['size'],
                        $ring['strength']
                    ),
                ];
                break;
            }
        }

        // ======================================================
        // 5. Account age and verification status using config
        // ======================================================

        // Audit H-3: account-age check must run WITHOUT a user_info row so
        // sock puppets registered seconds before an attack are not scored
        // 0 (minimal risk).  Age comes from the authoritative wp_users
        // row; user_info may not be provisioned yet on first page load.
        $user = get_userdata($userId);
        if ($user) {
            $accountAge  = time() - strtotime($user->user_registered);
            $accountDays = $accountAge / DAY_IN_SECONDS;

            $results['details']['account_days'] = round($accountDays, 1);

            // Very new accounts are higher risk
            if ($accountDays < 1) {
                $results['triggers'][] = 'brand_new_account';
                $results['score'] += 20;
                $results['signals'][] = [
                    'type'        => 'brand_new_account',
                    'points'      => 20,
                    'description' => 'Account is less than 1 day old',
                ];
            } elseif ($accountDays < BCC_TRUST_AGE_NEW) {
                $results['triggers'][] = 'new_account';
                $results['score'] += 10;
                $results['signals'][] = [
                    'type'        => 'new_account',
                    'points'      => 10,
                    'description' => sprintf(
                        'Account is only %.0f days old (threshold: %d days)',
                        $accountDays,
                        BCC_TRUST_AGE_NEW
                    ),
                ];
            }
        }

        // Email verification is stored on user_info — only evaluate
        // when the row exists.  If the row is missing, treat the user
        // as unverified (pessimistic) rather than silently skipping the
        // signal.
        if ($userInfo) {
            if (!$userInfo->is_verified) {
                $results['triggers'][] = 'unverified_email';
                $results['score'] += 15;
                $results['details']['email_verified'] = false;
                $results['signals'][] = [
                    'type'        => 'unverified_email',
                    'points'      => 15,
                    'description' => 'Email address has not been verified',
                ];
            } else {
                $results['details']['email_verified'] = true;
            }
        } else {
            // Missing user_info row → account is newly provisioned or
            // mid-sync.  Treat as unverified (audit H-3 mitigation).
            $results['triggers'][] = 'user_info_missing';
            $results['score'] += 15;
            $results['details']['email_verified'] = false;
            $results['signals'][] = [
                'type'        => 'user_info_missing',
                'points'      => 15,
                'description' => 'User profile not yet provisioned',
            ];
        }

        // ======================================================
        // 6. Calculate final scores and risk level
        // ======================================================

        // Cap score at 100
        $results['score'] = min(100, $results['score']);

        // Calculate confidence based on data availability
        $dataPoints = count($results['details']);
        $results['confidence'] = min(1, $dataPoints / 15); // Need at least 15 data points for full confidence

        if ($results['score'] >= BCC_TRUST_FRAUD_CRITICAL) {
            $results['risk_level'] = 'critical';
        } elseif ($results['score'] >= BCC_TRUST_FRAUD_HIGH) {
            $results['risk_level'] = 'high';
        } elseif ($results['score'] >= BCC_TRUST_FRAUD_MEDIUM) {
            $results['risk_level'] = 'medium';
        } elseif ($results['score'] >= BCC_TRUST_FRAUD_LOW) {
            $results['risk_level'] = 'low';
        } else {
            $results['risk_level'] = 'minimal';
        }

        // Remove duplicate triggers
        $results['triggers'] = array_unique($results['triggers']);

        // Cache results
        wp_cache_set('fraud_analysis_' . $userId, $results, self::CACHE_GROUP, self::cacheTtl());

        return $results;
    }

    /**
     * Get risk level based on score.
     *
     * Delegates to FraudClassification::level() — the single source of truth.
     */
    public static function getRiskLevel(int $score): string {
        return \BCC\Trust\Core\Support\FraudClassification::level($score);
    }

    /**
     * Enhanced fraud score calculation using all systems and user_info table
     */
    public static function getEnhancedFraudScore(int $userId): int {

        $analysis = self::analyzeFraud($userId);

        // Get existing fraud score from user_info
        $userInfo = self::userInfoRepo()->getByUserId($userId);
        $existingScore = $userInfo ? (int) $userInfo->fraud_score : 0;

        // Blend with new analysis (70% new, 30% existing to smooth changes)
        $newScore = (int) round(($analysis['score'] * 0.7) + ($existingScore * 0.3));

        // Cap at 100
        $newScore = min(100, max(0, $newScore));

        // -- Peak fraud score tracking --
        // Prevents oscillation attack: attacker goes idle -> score decays
        // -> resumes voting with clean score -> repeats.
        // Effective score can never drop below 50% of peak.
        $peakScore = $userInfo ? (int) ($userInfo->peak_fraud_score ?? 0) : 0;
        if ($newScore > $peakScore) {
            $peakScore = $newScore;
        }
        $effectiveFloor = (int) floor($peakScore * 0.5);
        $newScore = max($newScore, $effectiveFloor);

        // Update user_info table (fraud_score + peak_fraud_score).
        // Monotonic: live fraud detection should never regress a concurrent
        // higher detection — only the cron path (single-threaded) may lower scores.
        self::userInfoRepo()->updateFraudScore($userId, $newScore, $peakScore, true);

        // Store analysis results in the new fraud_analysis table
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . BCC_TRUST_CLEANUP_FRAUD_ANALYSIS . ' days'));

        self::fraudAnalysisRepo()->storeAnalysis(
            $userId,
            $newScore,
            $analysis['risk_level'],
            $analysis['confidence'],
            $analysis['triggers'],
            $analysis['details'],
            $expiresAt
        );

        return $newScore;
    }

    /**
     * Get user's last known IP
     */
    private static function getUserIp(int $userId): ?string {

        $ipBinary = self::auditLogRepo()->getLastIpForUser($userId);

        if ($ipBinary) {
            return inet_ntop($ipBinary) ?: null;
        }

        return null;
    }

    /**
     * Detect one-directional fan-in vote coordination on a page.
     *
     * Catches the blind spot where N accounts all vote on the same target
     * without cross-voting (which would be caught by ring detection).
     *
     * @param int $pageId   Target page that just received a vote.
     * @param int $voterId  The voter who just cast.
     * @return bool True if coordination detected (caller should flag for recalc).
     */
    public static function detectFanInCoordination(int $pageId, int $voterId): bool
    {
        // Per-page dedup: without this, every subsequent downvote re-runs
        // the full detection and re-adds +15 fraud score to the same
        // voters for as long as the 24h window is populated. Transient
        // expires after 1h so a re-clustered attack within the same day
        // still triggers, but an honest page with 5+ clustered voters
        // isn't punished repeatedly.
        $dedupKey = 'bcc_trust_fan_in_fired_' . $pageId;
        if (get_transient($dedupKey)) {
            return false;
        }

        // Threshold: 5+ voters on same page within 24h whose accounts
        // were all created within a 7-day window.
        $fanInThreshold = 5;

        // Get distinct voters on this page in the last 24 hours.
        $recentVoters = self::voteRepo()->getRecentVotersWithRegistration($pageId, 1);

        if (count($recentVoters) < $fanInThreshold) {
            return false;
        }

        // Check if account creation dates are clustered (all within 7 days of each other).
        $registrationDates = [];
        foreach ($recentVoters as $row) {
            if (!empty($row->registered)) {
                $registrationDates[] = strtotime($row->registered);
            }
        }

        if (count($registrationDates) < $fanInThreshold) {
            return false;
        }

        sort($registrationDates);
        $minDate = $registrationDates[0];
        $maxDate = end($registrationDates);
        $spreadDays = ($maxDate - $minDate) / DAY_IN_SECONDS;

        // If all recent voters were created within a 7-day window, flag it.
        if ($spreadDays <= 7) {
            // Check voting diversity: do these voters ONLY vote on this page?
            $voterIds = array_map(fn($r) => (int) $r->voter_user_id, $recentVoters);

            $otherPageVotes = self::voteRepo()->countOtherPagesVotedByUsers($voterIds, $pageId);

            // Low diversity: these accounts vote on fewer than 3 other pages combined.
            if ($otherPageVotes < 3) {
                // Store fraud signal for all participants.
                $fraudRepo = self::fraudAnalysisRepo();
                $pageOwnerId = \BCC\Trust\Core\Services\PeepSoPageResolver::getOwnerId($pageId);

                $fraudRepo->storeAnalysisWithAutoRisk(
                    $pageOwnerId > 0 ? $pageOwnerId : $voterId,
                    min(100, 40 + count($recentVoters) * 5),
                    ['fan_in_coordination'],
                    [
                        'page_id'       => $pageId,
                        'voter_count'   => count($recentVoters),
                        'spread_days'   => round($spreadDays, 1),
                        'other_pages'   => $otherPageVotes,
                        'voter_ids'     => $voterIds,
                    ]
                );

                AuditLogger::log('fan_in_coordination_detected', $pageId, [
                    'voter_count' => count($recentVoters),
                    'spread_days' => round($spreadDays, 1),
                    'voter_ids'   => $voterIds,
                ], 'page', $voterId);

                // Increment fraud score for each participating voter.
                foreach ($voterIds as $uid) {
                    self::userInfoRepo()->incrementFraudScore($uid, 15, 'fan_in_coordination');
                }

                // Mark this page as already processed for the next hour.
                set_transient($dedupKey, 1, HOUR_IN_SECONDS);

                return true;
            }
        }

        return false;
    }

    /**
     * Clear fraud analysis cache for a user
     */
    public static function clearCache(int $userId): void {
        wp_cache_delete('fraud_analysis_' . $userId, self::CACHE_GROUP);
        wp_cache_delete('trust_rank_' . $userId, self::CACHE_GROUP);
    }
}
