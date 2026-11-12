<?php
/**
 * Behavioral Analyzer
 *
 * Analyzes user behavior patterns to detect bots, fraudsters, and suspicious activity.
 * Uses config constants for thresholds and detection sensitivity.
 *
 * Analysis methods (each returns a weight + reasons array):
 *
 *   analyzeVotingPattern()      — Vote distribution, timing regularity, burst detection,
 *                                 weight consistency, and vote churn.
 *   analyzeTemporalPattern()    — Hourly distribution (night-owl only with a known
 *                                 timezone), uniform-distribution check, daily
 *                                 consistency.
 *   analyzeEngagementPattern()  — Session length analysis, action rate, metronome
 *                                 timing detection.
 *   analyzeSocialPattern()      — Page-owner diversity, reciprocal voting, score
 *                                 targeting analysis.
 *   analyzeConsistencyPattern() — Behavioral consistency over rolling time windows.
 *
 * @package BCC\Trust
 * @subpackage Security
 * @version 2.0.0
 */

namespace BCC\Trust\Core\Security;

if (!defined('ABSPATH')) exit;

use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\PatternRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Repositories\AuditLogRepository;

class BehavioralAnalyzer {

    /**
     * Repositories
     */
    private UserInfoRepository $userInfoRepo;
    private PatternRepository $patternRepo;
    private VoteRepository $voteRepo;
    private AuditLogRepository $auditLogRepo;

    /**
     * Config thresholds
     */
    private int $fraudMedium;

    private int $automationMedium;

    private int $minVotesReliable;

    private int $ringMinMutual;

    /**
     * Cache settings
     */
    const CACHE_GROUP = 'bcc_behavior';
    const CACHE_TTL = BCC_TRUST_CACHE_BEHAVIOR;

    /**
     * Constructor
     */
    public function __construct() {
        $plugin = \BCC\Trust\Core\Plugin::instance();
        $this->userInfoRepo = $plugin->userInfoRepository();
        $this->patternRepo  = $plugin->patternRepository();
        $this->voteRepo     = $plugin->voteRepository();
        // AuditLogRepository has no Plugin singleton accessor yet.
        $this->auditLogRepo = new AuditLogRepository();

        $this->fraudMedium           = BCC_TRUST_FRAUD_MEDIUM;
        $this->automationMedium      = BCC_TRUST_AUTOMATION_MEDIUM;
        $this->minVotesReliable      = BCC_TRUST_MIN_VOTES_RELIABLE;
        $this->ringMinMutual         = BCC_TRUST_RING_MIN_MUTUAL;
    }

    /**
     * Get risk level based on score.
     *
     * Delegates to FraudClassification::level() — the single source of truth.
     */
    private function getRiskLevel(int $score): string {
        return \BCC\Trust\Core\Support\FraudClassification::level($score);
    }

    /**
     * Analyze user behavior and return risk score and flags
     *
     * ⚠ A FAILED read throws; it never scores. Every sub-analysis below
     * answers "too little data, not suspicious" for an empty read, so a
     * failed read used to produce behaviour score 0 — which this method
     * then PERSISTED over the stored score (updateBehaviorScore) and cached
     * for CACHE_TTL (stale copy 2×). The reads now throw
     * RepositoryReadFailure / RepositoryException before any write or cache,
     * and the callers' retry (post-vote fraud retry, daily cron per-user
     * catch, admin reanalyse) takes it from there.
     *
     * @param int $userId
     * @return array<string, mixed> Behavior analysis results
     * @throws \RuntimeException when a read fails (nothing written or cached)
     */
    public function analyzeUserBehavior(int $userId): array {
        // Check cache first
        $cached = wp_cache_get('behavior_' . $userId, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        // Audit MED-8: per-user coarse throttle.  FraudDetector::clearCache
        // runs on every endorsement commit (orchestrator + analyzer paths),
        // so a bot burst can repeatedly miss the cache and force full
        // re-analysis — N concurrent burst callers × 7 sub-analyses each.
        // A hard throttle keyed on wp_cache_add guarantees that, regardless
        // of cache bust frequency, the expensive path runs at most once
        // per THROTTLE_SECONDS per user.  Callers that lose the race get
        // a cheap 'cooling-down' skeleton result so the caller proceeds
        // without paying for the full analysis.
        $throttleKey = 'behavior_lock_' . $userId;
        $gotThrottle = wp_cache_add($throttleKey, 1, self::CACHE_GROUP, 60);
        if ($gotThrottle === false) {
            $stale = wp_cache_get('behavior_stale_' . $userId, self::CACHE_GROUP);
            if ($stale !== false) {
                return $stale;
            }
            // F-128: no cached result to reuse. Fall back to the LAST STORED
            // behaviour score, not 0 — a throttled call used to read as
            // "clean" (score 0, minimal risk), so a bot burst that kept
            // missing the cache was scored as harmless during its burst.
            // ...OrThrow: a FAILED read must not fall back to 0 either.
            $info        = $this->userInfoRepo->getByUserIdOrThrow($userId);
            $storedScore = $info !== null ? (int) ($info->behavior_score ?? 0) : 0;
            return [
                'behavior_score' => $storedScore,
                'risk_level'     => $this->getRiskLevel($storedScore),
                'flags'          => [],
                'details'        => [],
                'analyzed_at'    => time(),
                'cooling_down'   => true,
            ];
        }

        $patterns = [
            'voting_pattern' => $this->analyzeVotingPattern($userId),
            'temporal_pattern' => $this->analyzeTemporalPattern($userId),
            'engagement_pattern' => $this->analyzeEngagementPattern($userId),
            'social_pattern' => $this->analyzeSocialPattern($userId),
            'consistency_pattern' => $this->analyzeConsistencyPattern($userId)
        ];

        $score = 0;
        $flags = [];
        $details = [];

        foreach ($patterns as $pattern => $result) {
            if ($result['suspicious']) {
                $score += $result['weight'];
                $flags = array_merge($flags, $result['reasons'] ?? []);
            }
            $details[$pattern] = $result;
        }

        // Cap score at 100
        $score = min(100, $score);

        // Determine risk level using config thresholds
        $riskLevel = $this->getRiskLevel($score);

        // Store pattern for ML training if above config threshold
        if ($score > $this->fraudMedium) {
            $this->patternRepo->storePattern(
                $userId,
                'suspicious_behavior',
                [
                    'score' => $score,
                    'flags' => $flags,
                    'patterns' => $details,
                    'thresholds' => [
                        'fraud_medium' => $this->fraudMedium,
                        'automation_medium' => $this->automationMedium
                    ]
                ],
                $score / 100
            );
        }

        // Update user_info table with behavior score
        $this->userInfoRepo->updateBehaviorScore($userId, $score);

        $result = [
            'behavior_score' => $score,
            'risk_level' => $riskLevel,
            'flags' => array_unique($flags),
            'details' => $details,
            'analyzed_at' => time()
        ];

        // Cache for configured time
        wp_cache_set('behavior_' . $userId, $result, self::CACHE_GROUP, self::CACHE_TTL);
        // Keep a stale copy at 2× TTL so burst callers that lose the
        // throttle race still see a meaningful analysis.
        wp_cache_set('behavior_stale_' . $userId, $result, self::CACHE_GROUP, self::CACHE_TTL * 2);

        return $result;
    }

    /**
     * Analyze voting pattern for bot-like behavior using config thresholds
     *
     * @param int $userId
     * @return array<string, mixed>
     */
    private function analyzeVotingPattern(int $userId): array {
        // Get last 100 votes via repository
        $votes = $this->voteRepo->getRecentVotesForUser($userId, 100);

        if (count($votes) < $this->minVotesReliable) {
            return [
                'suspicious' => false,
                'weight' => 0,
                'reasons' => [],
                'data_points' => count($votes)
            ];
        }

        $suspicious = false;
        $weight = 0;
        $reasons = [];
        $metrics = [];

        // ======================================================
        // 1. Check vote type distribution
        // ======================================================
        $upvotes = 0;
        $downvotes = 0;
        foreach ($votes as $vote) {
            if ($vote->vote_type > 0) $upvotes++;
            else $downvotes++;
        }

        $totalVotes = count($votes);
        $upvoteRatio = $upvotes / $totalVotes;
        $metrics['upvote_ratio'] = $upvoteRatio;

        // Extreme voting patterns using config thresholds
        if ($upvoteRatio > BCC_TRUST_BEHAVIOR_EXTREME_VOTE_RATIO) {
            $suspicious = true;
            $weight += 25;
            $reasons[] = 'extreme_upvoting';
            $metrics['extreme'] = 'up';
        } elseif ($upvoteRatio < (1 - BCC_TRUST_BEHAVIOR_EXTREME_VOTE_RATIO)) {
            $suspicious = true;
            $weight += 25;
            $reasons[] = 'extreme_downvoting';
            $metrics['extreme'] = 'down';
        }

        // ======================================================
        // 2. Check timing regularity (bots vote at consistent intervals)
        // ======================================================
        $timestamps = array_map(function($v) {
            return strtotime($v->created_at);
        }, $votes);

        $intervals = [];
        for ($i = 0; $i < count($timestamps) - 1; $i++) {
            $intervals[] = $timestamps[$i] - $timestamps[$i + 1];
        }

        if (!empty($intervals)) {
            $avgInterval = array_sum($intervals) / count($intervals);
            $variance = $this->calculateVariance($intervals);
            $metrics['avg_interval'] = $avgInterval;
            $metrics['interval_variance'] = $variance;

            // Very regular intervals (low variance) indicate bot
            if ($variance < BCC_TRUST_BEHAVIOR_TIMING_VARIANCE && $avgInterval < 300) {
                $suspicious = true;
                $weight += 30;
                $reasons[] = 'regular_timing';
            }

            // Extremely fast voting
            if ($avgInterval < BCC_TRUST_BEHAVIOR_RAPID_INTERVAL) {
                $suspicious = true;
                $weight += 35;
                $reasons[] = 'rapid_fire_voting';
            }
        }

        // ======================================================
        // 3. Check for burst patterns (many votes in short time)
        // ======================================================
        $burstThreshold = BCC_TRUST_BEHAVIOR_BURST_THRESHOLD;
        $bursts = 0;
        for ($i = 0; $i < count($timestamps) - $burstThreshold; $i++) {
            if (($timestamps[$i] - $timestamps[$i + $burstThreshold - 1]) < 60) {
                $bursts++;
            }
        }

        $metrics['burst_count'] = $bursts;
        if ($bursts > BCC_TRUST_BEHAVIOR_BURST_MAX) {
            $suspicious = true;
            $weight += 20;
            $reasons[] = 'voting_bursts';
        }

        // ======================================================
        // 4. Check for consistent vote weight (all votes same weight)
        // ======================================================
        $weights = array_column($votes, 'weight');
        $uniqueWeights = count(array_unique($weights));
        $metrics['unique_weights'] = $uniqueWeights;

        if ($uniqueWeights === 1 && count($weights) > 10) {
            // All votes have exactly the same weight - suspicious
            $suspicious = true;
            $weight += 15;
            $reasons[] = 'uniform_weights';
        }

        // ======================================================
        // 5. Check for vote churn (changing votes frequently)
        // ======================================================
        $voteChanges = 0;
        $lastVoteType = null;
        foreach ($votes as $vote) {
            if ($lastVoteType !== null && $vote->vote_type != $lastVoteType) {
                $voteChanges++;
            }
            $lastVoteType = $vote->vote_type;
        }

        $metrics['vote_changes'] = $voteChanges;
        if ($voteChanges > $totalVotes * 0.3) { // Changed more than 30% of the time
            $suspicious = true;
            $weight += 15;
            $reasons[] = 'frequent_vote_changes';
        }

        return [
            'suspicious' => $suspicious,
            'weight' => $weight,
            'reasons' => $reasons,
            'metrics' => $metrics
        ];
    }

    /**
     * Analyze temporal patterns (time of day, day of week)
     *
     * @param int $userId
     * @return array<string, mixed>
     */
    private function analyzeTemporalPattern(int $userId): array {
        // Get activity for last 30 days via repository
        $activities = $this->auditLogRepo->getActivityBreakdown($userId, 30);

        if (count($activities) < 10) {
            return [
                'suspicious' => false,
                'weight' => 0,
                'reasons' => [],
                'data_points' => count($activities)
            ];
        }

        $suspicious = false;
        $weight = 0;
        $reasons = [];
        $metrics = [];

        // ======================================================
        // 1. Check hourly distribution
        // ======================================================
        $hourlyDistribution = array_fill(0, 24, 0);
        foreach ($activities as $act) {
            $hourlyDistribution[(int)$act->hour] += $act->count;
        }

        $total = array_sum($hourlyDistribution);
        $metrics['hourly_distribution'] = $hourlyDistribution;

        // Night-owl check (F-128, user decision): ONLY when the member's
        // timezone is actually known. `bcc_timezone` is read but nothing
        // writes it, and the old UTC fallback judged US-evening and
        // Asia-daytime members as "active while asleep" (+25). Unknown
        // timezone → no judgement (today: always, so the check is dormant
        // until a timezone is collected).
        $userTimezone = get_user_meta($userId, 'bcc_timezone', true);
        if (is_string($userTimezone) && $userTimezone !== '') {
            // Calculate sleeping hours (12 AM - 6 AM in user's timezone)
            $sleepingHours = $this->getSleepingHours($userTimezone);
            $sleepActivity = 0;

            foreach ($sleepingHours as $hour) {
                $sleepActivity += $hourlyDistribution[$hour] ?? 0;
            }

            $sleepRatio = $sleepActivity / max(1, $total);
            $metrics['sleep_ratio'] = $sleepRatio;

            // High activity during sleeping hours is suspicious
            if ($sleepRatio > BCC_TRUST_BEHAVIOR_SLEEP_RATIO) {
                $suspicious = true;
                $weight += 25;
                $reasons[] = 'night_owl_activity';
            }
        }

        // ======================================================
        // 2. Check for uniform hourly distribution (bots run 24/7)
        // ======================================================
        $activeHours = array_filter($hourlyDistribution, function($count) {
            return $count > 0;
        });

        $metrics['active_hours'] = count($activeHours);

        if (count($activeHours) > 20) { // Active in more than 20 hours
            $hourlyVariance = $this->calculateVariance(array_values($hourlyDistribution));
            $metrics['hourly_variance'] = $hourlyVariance;

            if ($hourlyVariance < 2) { // Very even distribution
                $suspicious = true;
                $weight += 20;
                $reasons[] = 'uniform_hourly_pattern';
            }
        }

        // (Former check 3, "perfect weekly pattern" (+15), removed — F-128,
        // user decision: a weekend share within 5% of 2/7 is what an
        // ordinary human week looks like, so it flagged normal members.)

        // ======================================================
        // 4. Check for consistent daily activity (same time every day)
        // ======================================================
        // Build per-day hourly vectors: [ date => [ h0_count, h1_count, ..., h23_count ] ]
        $dailyHourly = [];
        foreach ($activities as $act) {
            $day  = $act->date;
            $hour = (int) $act->hour;
            if (!isset($dailyHourly[$day])) {
                $dailyHourly[$day] = array_fill(0, 24, 0);
            }
            $dailyHourly[$day][$hour] += $act->count;
        }

        // Hash each day's 24-hour vector independently
        $patternHashes = [];
        foreach ($dailyHourly as $day => $hourVector) {
            $patternHashes[$day] = md5(implode(',', $hourVector));
        }

        $uniquePatterns = count(array_unique($patternHashes));
        $metrics['unique_daily_patterns'] = $uniquePatterns;

        if ($uniquePatterns === 1 && count($patternHashes) > 5) {
            // Exactly the same pattern every day - bot
            $suspicious = true;
            $weight += 30;
            $reasons[] = 'identical_daily_patterns';
        }

        return [
            'suspicious' => $suspicious,
            'weight' => $weight,
            'reasons' => $reasons,
            'metrics' => $metrics
        ];
    }

    /**
     * Analyze engagement pattern (session length, actions per session)
     *
     * @param int $userId
     * @return array<string, mixed>
     */
    private function analyzeEngagementPattern(int $userId): array {
        // Get session data via repository
        $actions = $this->auditLogRepo->getRecentActions($userId, 30);

        if (count($actions) < 10) {
            return [
                'suspicious' => false,
                'weight' => 0,
                'reasons' => [],
                'data_points' => count($actions)
            ];
        }

        // Group into sessions (30 min gap threshold)
        $sessions = [];
        $currentSession = [];
        $lastTime = null;

        foreach ($actions as $action) {
            $time = strtotime($action->created_at);

            if ($lastTime === null || ($time - $lastTime) > 1800) { // 30 min gap
                if (!empty($currentSession)) {
                    $sessions[] = $currentSession;
                }
                $currentSession = [];
            }

            $currentSession[] = $action;
            $lastTime = $time;
        }

        // After the loop, $currentSession always has at least the last action.
        $sessions[] = $currentSession;

        $suspicious = false;
        $weight = 0;
        $reasons = [];
        $metrics = [];

        // ======================================================
        // 1. Analyze session lengths
        // ======================================================
        $sessionLengths = [];
        $actionsPerSession = [];

        foreach ($sessions as $session) {
            if (count($session) < 2) continue;

            $first = strtotime($session[0]->created_at);
            $last = strtotime($session[count($session)-1]->created_at);
            $length = $last - $first;

            $sessionLengths[] = $length;
            $actionsPerSession[] = count($session);
        }

        if (!empty($sessionLengths)) {
            $avgSessionLength = array_sum($sessionLengths) / count($sessionLengths);
            $avgActionsPerSession = array_sum($actionsPerSession) / count($actionsPerSession);

            $metrics['avg_session_length'] = $avgSessionLength;
            $metrics['avg_actions_per_session'] = $avgActionsPerSession;

            // Extremely short sessions (under 10 seconds)
            if ($avgSessionLength < 10 && $avgActionsPerSession > 5) {
                $suspicious = true;
                $weight += 30;
                $reasons[] = 'ultra_fast_sessions';
            }

            // No variance in session length
            $lengthVariance = $this->calculateVariance($sessionLengths);
            $metrics['session_length_variance'] = $lengthVariance;

            if ($lengthVariance < 2 && count($sessionLengths) > 5) {
                $suspicious = true;
                $weight += 20;
                $reasons[] = 'uniform_session_lengths';
            }
        }

        // ======================================================
        // 2. Check action rate (actions per minute)
        // ======================================================
        $actionRates = [];
        foreach ($sessions as $session) {
            if (count($session) < 3) continue;

            $first = strtotime($session[0]->created_at);
            $last = strtotime($session[count($session)-1]->created_at);
            $duration = max(1, ($last - $first) / 60); // minutes

            $rate = count($session) / $duration;
            $actionRates[] = $rate;
        }

        if (!empty($actionRates)) {
            $avgActionRate = array_sum($actionRates) / count($actionRates);
            $metrics['avg_action_rate'] = $avgActionRate;

            // Extremely high action rate using config threshold
            if ($avgActionRate > BCC_TRUST_BEHAVIOR_ACTION_RATE_MAX) {
                $suspicious = true;
                $weight += 25;
                $reasons[] = 'superhuman_action_rate';
            }
        }

        // ======================================================
        // 3. Check for consistent time between actions
        // ======================================================
        $allIntervals = [];
        for ($i = 0; $i < count($actions) - 1; $i++) {
            $interval = strtotime($actions[$i+1]->created_at) - strtotime($actions[$i]->created_at);
            if ($interval < 3600) { // Less than 1 hour
                $allIntervals[] = $interval;
            }
        }

        if (!empty($allIntervals)) {
            $intervalVariance = $this->calculateVariance($allIntervals);
            $metrics['action_interval_variance'] = $intervalVariance;

            if ($intervalVariance < 1 && count($allIntervals) > 20) {
                $suspicious = true;
                $weight += 25;
                $reasons[] = 'metronome_timing';
            }
        }

        return [
            'suspicious' => $suspicious,
            'weight' => $weight,
            'reasons' => $reasons,
            'metrics' => $metrics
        ];
    }

    /**
     * Analyze social pattern (who they interact with)
     *
     * @param int $userId
     * @return array<string, mixed>
     */
    private function analyzeSocialPattern(int $userId): array {
        // Get pages this user votes on via repository
        $votedPages = $this->voteRepo->getVotedPagesWithOwners($userId, 500);

        if (count($votedPages) < 5) {
            return [
                'suspicious' => false,
                'weight' => 0,
                'reasons' => [],
                'data_points' => count($votedPages)
            ];
        }

        $suspicious = false;
        $weight = 0;
        $reasons = [];
        $metrics = [];

        // ======================================================
        // 1. Check diversity of page owners
        // ======================================================
        $ownerIds = array_column($votedPages, 'page_owner_id');
        $uniqueOwners = count(array_unique($ownerIds));
        $metrics['unique_owners'] = $uniqueOwners;

        // If voting on many pages by same owner
        $ownerDistribution = array_count_values($ownerIds);
        $maxForOwner = count($ownerDistribution) > 0 ? max($ownerDistribution) : 0;
        $metrics['max_votes_same_owner'] = $maxForOwner;

        if ($maxForOwner > 10 && $uniqueOwners < 3) {
            // Voting mostly for one person's pages
            $suspicious = true;
            $weight += 35;
            $reasons[] = 'single_owner_focus';
        }

        // ======================================================
        // 2. Check for reciprocal voting (vote rings) using config thresholds
        // ======================================================
        $mutualVotes = $this->voteRepo->countReciprocalVotes($userId);

        $metrics['mutual_votes'] = (int) $mutualVotes;

        if ($mutualVotes > $this->ringMinMutual) {
            $suspicious = true;
            $weight += 30;
            $reasons[] = 'reciprocal_voting';
        }

        // ======================================================
        // 3. Check if they only vote for high-reputation users
        // ======================================================
        // $votedPages is guaranteed non-empty (early return at count < 5 above).
        $pageIds = array_column($votedPages, 'page_id');

        $pageScores = $this->voteRepo->getPageScoresForPageIds($pageIds);

        if (count($pageScores) > 0) {
            $avgScore = array_sum($pageScores) / count($pageScores);
            $metrics['avg_page_score_voted'] = $avgScore;

            // If they only vote for very high or very low pages.
            //
            // These constants are used here as SCORE-BAND boundaries, not as a
            // tier resolution — the question is "does this voter only touch
            // extreme-scoring pages", which is a property of the number. Do
            // NOT rewrite this through TrustScoreService::tierFor(): since the
            // §J.12 gate, a page can sit above the elite threshold while being
            // tiered `trusted`, and that distinction is irrelevant (and
            // misleading) to this heuristic.
            if ($avgScore > BCC_TRUST_TIER_ELITE || $avgScore < BCC_TRUST_TIER_CAUTION) {
                $suspicious = true;
                $weight += 15;
                $reasons[] = 'extreme_page_focus';
            }
        }

        return [
            'suspicious' => $suspicious,
            'weight' => $weight,
            'reasons' => $reasons,
            'metrics' => $metrics
        ];
    }

    // analyzeBrowsingPattern() removed (F-128, user decision): its blind-voting
    // and fast-vote rules read 'page_view' / 'page_visit' / 'vote_up' audit rows
    // that nothing writes, so they never fired. Rebuild only on real tracking.

    // analyzeContentPattern() removed (F-128, user decision): its only rule,
    // voter_only_no_content (+30), penalised members who vote without posting —
    // voting is the core action.

    /**
     * Analyze consistency of behavior over time
     *
     * @param int $userId
     * @return array<string, mixed>
     */
    private function analyzeConsistencyPattern(int $userId): array {
        // Get weekly activity for last 3 months via repository
        $weeklyActivity = $this->auditLogRepo->getWeeklyActivitySummary($userId, 90);

        if (count($weeklyActivity) < 4) {
            return [
                'suspicious' => false,
                'weight' => 0,
                'reasons' => []
            ];
        }

        $suspicious = false;
        $weight = 0;
        $reasons = [];
        $metrics = [];

        $counts = array_column($weeklyActivity, 'action_count');
        $metrics['weekly_counts'] = $counts;

        // Check for perfect consistency week to week
        $variance = $this->calculateVariance($counts);
        $metrics['weekly_variance'] = $variance;

        if ($variance < BCC_TRUST_BEHAVIOR_WEEKLY_VARIANCE && count($counts) > 4) {
            $suspicious = true;
            $weight += 25;
            $reasons[] = 'perfect_weekly_consistency';
        }

        // Check for sudden changes in behavior
        $changes = [];
        for ($i = 1; $i < count($counts); $i++) {
            if ($counts[$i-1] > 0) {
                $change = abs($counts[$i] - $counts[$i-1]) / $counts[$i-1];
                $changes[] = $change;
            }
        }

        if (!empty($changes)) {
            $avgChange = array_sum($changes) / count($changes);
            $metrics['avg_weekly_change'] = $avgChange;

            if ($avgChange < 0.1) { // Less than 10% change week to week
                $suspicious = true;
                $weight += 20;
                $reasons[] = 'minimal_weekly_variance';
            }
        }

        return [
            'suspicious' => $suspicious,
            'weight' => $weight,
            'reasons' => $reasons,
            'metrics' => $metrics
        ];
    }

    /**
     * Calculate variance of an array
     *
     * @param float[] $arr
     */
    private function calculateVariance(array $arr): float {
        if (empty($arr)) return 0;

        $avg = array_sum($arr) / count($arr);
        if ($avg == 0) return 0;

        $squaredDiffs = array_map(function($val) use ($avg) {
            return pow($val - $avg, 2);
        }, $arr);

        return sqrt(array_sum($squaredDiffs) / count($arr));
    }

    /**
     * Get sleeping hours (12 AM - 6 AM) in UTC based on user timezone
     *
     * @return int[]
     */
    private function getSleepingHours(string $timezone): array {
        try {
            $userTz = new \DateTimeZone($timezone);
            $now = new \DateTime('now', $userTz);

            $offset = $now->getOffset() / 3600; // Offset in hours

            // Sleeping hours: 12 AM - 6 AM local time
            $sleepHoursLocal = [0, 1, 2, 3, 4, 5];

            // Convert to UTC
            $sleepHoursUTC = array_map(function($hour) use ($offset) {
                return ($hour - $offset + 24) % 24;
            }, $sleepHoursLocal);

            return $sleepHoursUTC;
        } catch (\Exception $e) {
            // Default to UTC if timezone invalid
            return [0, 1, 2, 3, 4, 5];
        }
    }

}
