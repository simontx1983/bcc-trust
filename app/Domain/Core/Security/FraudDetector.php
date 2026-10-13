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
    private static ?\BCC\Trust\Core\Repositories\PatternRepository $patternRepo = null;
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

    private static function patternRepo(): \BCC\Trust\Core\Repositories\PatternRepository {
        return self::$patternRepo ??= \BCC\Trust\Core\Plugin::instance()->patternRepository();
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

        // ── CONSISTENCY OBSERVATION, NOT A FRAUD SIGNAL ───────────────
        //
        // The rule: >= BCC_TRUST_UNIFORM_VOTE_MIN (10) votes in 7 days, and
        // more than 90% positive OR less than 10% positive.
        //
        // What that establishes is that a member's ratings are CONSISTENT.
        // Consistency is exactly what an honest rater with consistent
        // experience produces, so the rule cannot separate the two cases it
        // most needs to:
        //
        //   a satisfied customer who rated 30 good trades  -> 100% positive
        //   a sock puppet who upvoted 30 for one cartel    -> 100% positive
        //
        // Both were measured at +20 with isolated fixtures; they are
        // INDISTINGUISHABLE to this rule, because it has no input that
        // could tell them apart.
        //
        // ⚠ It also penalised the consumer-protection case. Someone who
        // only flags poor work — 12 bad jobs out of 13 — lands at 7.7%
        // positive and scored +20 for it. That is the member behaviour this
        // product most wants.
        //
        // So the points are removed. The trigger and the `details` entry are
        // RETAINED: they are the durable record, and `details` is what
        // `confidence` is computed from, so dropping it would silently move
        // confidence for every consistent rater.
        //
        // ⚠ NOT changed: the thresholds. Raising the sample floor or
        // widening the band would be a new arbitrary number in place of an
        // old one, and would still not distinguish the two cases above.
        if (self::detectUniformVoting($userId)) {
            $results['triggers'][] = 'uniform_voting';
            $results['details']['uniform_voting'] = true;
            $results['signals'][] = [
                'type'        => 'uniform_voting',
                'points'      => 0,
                'punitive'    => false,
                'description' => 'Consistent rating pattern — nearly all positive or nearly all negative (observation only, adds no fraud points)',
            ];
        }

        // ── RELATIONSHIP OBSERVATION, NOT A FRAUD SIGNAL ──────────────
        //
        // This is the PAIRWISE reciprocity check: countMutualVotePages()
        // > BCC_TRUST_RING_MIN_MUTUAL, i.e. two people who have each voted
        // on four or more of each other's pages. No ring, no third member,
        // no strength gate and no dependence on the ring cache — so unlike
        // `in_vote_ring` below it has always applied on every host.
        //
        // It used to add 25 points. It no longer adds any, because two
        // tradespeople who have repeatedly used each other's services are
        // the ordinary case in this product's population, and the rule has
        // no input that could separate them from a manufactured pair.
        //
        // The trigger and the `details` entry are RETAINED deliberately:
        // they are the durable record of the observation, and `details` is
        // what `confidence` is computed from, so dropping it would silently
        // move confidence for every observed pair.
        if (self::detectVoteRing($userId)) {
            $results['triggers'][] = 'vote_ring';
            $results['details']['vote_ring'] = true;
            $results['signals'][] = [
                'type'        => 'vote_ring',
                'points'      => 0,
                'punitive'    => false,
                'description' => 'Mutual vote-exchange relationship with another member (observation only — adds no fraud points)',
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
        // ── RELATIONSHIP OBSERVATION, NOT A FRAUD SIGNAL ──────────────
        //
        // Ring membership used to add 40 points. It no longer adds any.
        //
        // The detection rule separates RECIPROCAL structure from
        // non-reciprocal structure. It does not separate legitimate
        // reciprocity from manufactured reciprocity, because it has no
        // input that could: a six-person hall where everyone has used
        // everyone scores 48.0 against a threshold of 5.0, on exactly the
        // same terms as six sock puppets. The rule is in fact most lenient
        // toward the newest accounts, whose vote weight is too low to clear
        // the strength gate — the opposite of the risk profile it is meant
        // to describe.
        //
        // Trigger and `details` are RETAINED: they are the durable record,
        // and `ring_size` + `ring_strength` are two of the data points that
        // `confidence` is computed from.
        foreach ($rings as $ring) {
            if (in_array($userId, $ring['users'])) {
                $results['triggers'][] = 'in_vote_ring';
                $results['details']['ring_size'] = $ring['size'];
                $results['details']['ring_strength'] = $ring['strength'];
                $results['signals'][] = [
                    'type'        => 'in_vote_ring',
                    'points'      => 0,
                    'punitive'    => false,
                    'description' => sprintf(
                        'Member of a mutually-connected group of %d (connection strength: %.2f) — observation only, adds no fraud points',
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
                // ── UNCORROBORATED OBSERVATION, NOT A FRAUD FINDING ────
                //
                // What this rule actually measures: five or more accounts
                // registered within a 7-day window voted on one page, and
                // between them have voted on fewer than three other pages.
                //
                // That conjunction is the signature of successful
                // word-of-mouth onboarding. A sparky tells four colleagues
                // about BCC; they sign up the same week, back the person who
                // introduced them, and have not got round to anything else
                // yet. It fires. Meanwhile a motivated ring defeats it
                // trivially by voting on three unrelated pages — so the rule
                // is lenient toward exactly the accounts that warrant
                // scrutiny, and sharp against a cohort's first week.
                //
                // It used to do two punitive things. Both are removed:
                //
                //   1. +15 fraud_score PER VOTER, with peak_fraud_score
                //      bumped atomically and the HIGH-crossing fan-out —
                //      i.e. vote-weight discount, rate limits, and at 60 the
                //      attestation and rank gates. On honest newcomers who
                //      already carry brand_new_account / unverified_email /
                //      very_low_trust, +15 could reach the gate from
                //      ordinary newness plus one fan-in event.
                //
                //   2. ⚠ A fraud_analysis row at min(100, 40 + 5n) — 65 at
                //      the rule's own 5-voter threshold, i.e. HIGH — written
                //      against THE PAGE OWNER. The person who was voted on,
                //      the supposed beneficiary of the coordination, got the
                //      allegation on their moderation screen.
                //
                // What remains is a system-level observation with no member
                // attributed, plus the audit row. Same posture as the ring
                // detectors after #314.
                $voterCount = count($recentVoters);

                // ⚠ user_id 0 — a SYSTEM-LEVEL pattern, deliberately not
                // attributed to the page owner or to any voter. tab-ml.php
                // gates both the member link and the "Investigate" button on
                // `if ($p->user_id)`, so this renders with nobody named and
                // no action offered. The member ids live in pattern_data for
                // an operator who opens the row, not as a subject of it.
                try {
                    self::patternRepo()->storePattern(
                        0,
                        'fan_in_coordination',
                        [
                            'page_id'     => $pageId,
                            'voter_count' => $voterCount,
                            'spread_days' => round($spreadDays, 1),
                            'other_pages' => $otherPageVotes,
                            'voter_ids'   => $voterIds,
                            // Recorded so a reader cannot mistake the row
                            // for a finding, and so the absent corroboration
                            // is explicit rather than implied.
                            'punitive'      => false,
                            'corroborated'  => false,
                            'evidence_note' => 'registration proximity and low vote diversity only — '
                                . 'account proximity alone is not corroborated manipulation',
                        ],
                        // No strength is computed by this rule, so no
                        // confidence is asserted. See the ring-detector
                        // precedent: 0.0 means "none computed", not
                        // "measured as weak".
                        0.0,
                        date('Y-m-d H:i:s', strtotime('+30 days'))
                    );
                } catch (\Exception $e) {
                    \BCC\Core\Log\Logger::error(
                        '[bcc-trust] fan_in_coordination pattern insert failed: ',
                        ['detail' => $e->getMessage()]
                    );
                }

                AuditLogger::log('fan_in_coordination_detected', $pageId, [
                    'voter_count' => $voterCount,
                    'spread_days' => round($spreadDays, 1),
                    'voter_ids'   => $voterIds,
                ], 'page', $voterId);

                \BCC\Core\Log\Logger::info(
                    '[bcc-trust] fan_in_coordination detected (soft-flag)',
                    [
                        'page_id'     => $pageId,
                        'voter_count' => $voterCount,
                        'spread_days' => round($spreadDays, 1),
                    ]
                );

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
