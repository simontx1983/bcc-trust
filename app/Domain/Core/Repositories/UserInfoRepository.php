<?php
/**
 * User Info Repository
 * 
 * Handles database operations for user_info table
 * Uses config constants for thresholds and limits
 * 
 * @package BCC\Trust\Core\Repositories
 * @version 2.1.0
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Exceptions\RepositoryException;

if (!defined('ABSPATH')) exit;

/**
 * Row shape returned by bcc_trust_user_info reads.
 *
 * $wpdb->get_row() returns all scalar columns as string|null unless the
 * repository explicitly casts them. Downstream code treats numeric columns
 * as numbers via loose comparison — we still annotate as int|float|string
 * where a cast is not performed to keep the shape truthful.
 *
 * @phpstan-type UserInfoRow object{
 *   id: int|numeric-string,
 *   user_id: int|numeric-string,
 *   user_login: string,
 *   user_email: string,
 *   display_name: string,
 *   registered: string|null,
 *   usr_last_activity: string|null,
 *   usr_views: int|numeric-string,
 *   usr_likes: int|numeric-string,
 *   usr_role: string|null,
 *   fraud_score: int|numeric-string,
 *   peak_fraud_score: int|numeric-string,
 *   trust_rank: float|numeric-string,
 *   risk_level: string|null,
 *   is_suspended: int|numeric-string,
 *   is_verified: int|numeric-string,
 *   votes_cast: int|numeric-string,
 *   endorsements_given: int|numeric-string,
 *   automation_score: int|numeric-string,
 *   behavior_score: int|numeric-string,
 *   device_fraud_probability: float|numeric-string,
 *   signals_updated_at: string|null,
 *   pages_owned: int|numeric-string,
 *   groups_owned: int|numeric-string,
 *   posts_created: int|numeric-string,
 *   comments_made: int|numeric-string,
 *   last_login: string|null,
 *   last_ip_address: string|null,
 *   device_fingerprint: string|null,
 *   risk_label: string,
 *   risk_color: string,
 *   fraud_triggers: string|null,
 *   page_ids_owned: string|null,
 *   reputation_tier: string,
 *   created_at: string,
 *   updated_at: string
 * }
 */
class UserInfoRepository {

    /** Explicit column list for bcc_trust_user_info table. */
    private const COLUMNS = 'id, user_id, user_login, user_email, display_name, registered, usr_last_activity, usr_views, usr_likes, usr_role, fraud_score, peak_fraud_score, trust_rank, risk_level, is_suspended, is_verified, votes_cast, endorsements_given, automation_score, behavior_score, device_fraud_probability, signals_updated_at, pages_owned, groups_owned, posts_created, comments_made, last_login, last_ip_address, device_fingerprint, risk_label, risk_color, fraud_triggers, page_ids_owned, reputation_tier, created_at, updated_at';

    private string $table;

    private int $fraudHigh;

    public function __construct() {
        $this->table     = \BCC\Trust\Core\Database\TableRegistry::userInfo();
        $this->fraudHigh = BCC_TRUST_FRAUD_HIGH;
    }
    
    /**
     * Get user info by user ID.
     *
     * @phpstan-return UserInfoRow|null
     */
    public function getByUserId(int $userId): ?object {
        $cached = wp_cache_get('user_info_' . $userId, 'bcc_trust');
        if ($cached !== false) {
            return $cached ?: null;
        }

        global $wpdb;
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$this->table} WHERE user_id = %d",
            $userId
        ));

        // Cache for 5 minutes; store empty string for null to distinguish from cache miss
        wp_cache_set('user_info_' . $userId, $result ?: '', 'bcc_trust', BCC_TRUST_CACHE_USER);

        return $result;
    }

    /**
     * Refresh the display-only last_login column (admin Users tab).
     * Written by RankLoginListener on explicit authentication; NEVER
     * authoritative (§24.2) — the canonical login record is
     * bcc_trust_login_days. No-op when the user has no info row yet.
     */
    public function touchLastLogin(int $userId): void {
        if ($userId <= 0) {
            return;
        }

        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET last_login = %s WHERE user_id = %d",
            current_time('mysql', true),
            $userId
        ));

        $this->invalidateCache($userId);
    }

    public function invalidateCache(int $userId): void {
        wp_cache_delete('user_info_' . $userId, 'bcc_trust');

        // Also invalidate the rate-limiter's cached copy of user_info so
        // that fraud-score changes take effect immediately for rate limiting.
        \BCC\Trust\Core\Security\RateLimiter::invalidateUserCache($userId);
    }
    
    /**
     * Get multiple users by IDs
     *
     * @param int[] $userIds
     * @return array<int, object>
     * @phpstan-return array<int, UserInfoRow>
     */
    public function getBulkByUserIds(array $userIds): array {
        global $wpdb;
        
        if (empty($userIds)) {
            return [];
        }
        
        $placeholders = implode(',', array_fill(0, count($userIds), '%d'));
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table} WHERE user_id IN ({$placeholders})",
                $userIds
            )
        );
        
        $indexed = [];
        foreach ($results as $row) {
            $indexed[$row->user_id] = $row;
        }
        
        return $indexed;
    }
    
    /**
     * Update fraud score and trigger retroactive score correction when the
     * user crosses the high-risk threshold for the first time.
     *
     * When a user's fraud score rises to or past BCC_TRUST_FRAUD_HIGH, all
     * page scores they have voted on are flagged for recalculation so that
     * their inflated votes are discounted retroactively.
     */
    /**
     * Update a user's fraud score, risk level, and derived display fields.
     *
     * @param int      $userId
     * @param int      $score       New fraud score (0–100).
     * @param int|null $peakScore   Explicit peak override (null = use $score).
     * @param bool     $monotonic   If true, fraud_score can only INCREASE
     *                              (uses GREATEST). Use for live concurrent
     *                              paths where two parallel analyses may race.
     *                              The cron path (single-threaded under advisory
     *                              lock) passes false to allow score recovery.
     * @throws RepositoryException on database failure
     */
    public function updateFraudScore(int $userId, int $score, ?int $peakScore = null, bool $monotonic = false): void {
        global $wpdb;

        // Ensure score is within valid range
        $score = max(0, min(100, $score));

        // Snapshot current fraud score before the update
        $userInfo     = $this->getByUserId($userId);
        $previousScore = $userInfo ? (int) $userInfo->fraud_score : 0;

        $riskLevel = \BCC\Trust\Core\Security\FraudDetector::getRiskLevel($score);
        $riskLabel = \BCC\Trust\Core\Support\FraudClassification::label($score);
        $riskColor = \BCC\Trust\Core\Support\FraudClassification::color($score);

        $peakValue = $peakScore !== null ? max(0, min(100, $peakScore)) : $score;

        // When $monotonic is true, use GREATEST to prevent a concurrent
        // analysis with stale data from regressing a fraud detection.
        // risk_level/risk_label/risk_color are derived from the score,
        // so they must also be conditional to stay consistent.
        //
        // ORDER MATTERS: MySQL evaluates multi-assignment SET left-to-right.
        // Risk columns must be SET BEFORE fraud_score so their IF(%d > fraud_score, ...)
        // comparisons see the OLD fraud_score value. If fraud_score is updated
        // first, the IF reads the new value and becomes `IF(%d > %d, ...)` →
        // always false → risk_level stays stale even when fraud_score jumps
        // from 40 → 85 (bug: fraud promotions invisible in UI).
        if ($monotonic) {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table}
                 SET risk_level       = IF(%d > fraud_score, %s, risk_level),
                     risk_label       = IF(%d > fraud_score, %s, risk_label),
                     risk_color       = IF(%d > fraud_score, %s, risk_color),
                     fraud_score      = GREATEST(fraud_score, %d),
                     peak_fraud_score = GREATEST(COALESCE(peak_fraud_score, 0), %d)
                 WHERE user_id = %d",
                $score, $riskLevel,
                $score, $riskLabel,
                $score, $riskColor,
                $score,
                $peakValue,
                $userId
            ));
        } else {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table}
                 SET fraud_score      = %d,
                     risk_level       = %s,
                     risk_label       = %s,
                     risk_color       = %s,
                     peak_fraud_score = GREATEST(COALESCE(peak_fraud_score, 0), %d)
                 WHERE user_id = %d",
                $score,
                $riskLevel,
                $riskLabel,
                $riskColor,
                $peakValue,
                $userId
            ));
        }

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::updateFraudScore failed for user ' . $userId . ': ' . $wpdb->last_error);
        }

        $this->invalidateCache($userId);

        // Invalidate the rate limiter's separate user_info cache so that the
        // new fraud-score-based rate limits take effect immediately, not after
        // the 5-minute cache TTL. Without this, a user flagged for fraud
        // continues getting the old (higher) rate limits for up to 300 seconds.
        wp_cache_delete('rl_userinfo_' . $userId, 'bcc_trust_rl');

        // Retroactive correction: if user just crossed the high-risk threshold,
        // flag all pages they have voted on OR endorsed for recalculation.
        //
        // Idempotency: two concurrent fraud updates can both detect the
        // crossing (stale previousScore). INSERT IGNORE on a per-user flag
        // key guarantees only the first thread triggers the correction.
        // The flag is cleared when the score drops below threshold so
        // future crossings are not suppressed.
        //
        // DoS defence: an adversarial oscillator (crossing threshold up and
        // down repeatedly) could previously spam `markVotedPagesForRecalc…`
        // inflating the 5-minute recalc queue and starving legitimate work.
        // We track the last-fire timestamp on the flag's option_value and
        // skip the expensive marker calls unless the prior correction ran
        // at least COOLDOWN_SECONDS ago, even if the INSERT-IGNORE flag
        // was deleted in between. COOLDOWN is defensive only; honest
        // crossings are rare and tolerate a short delay.
        if ($previousScore < $this->fraudHigh && $score >= $this->fraudHigh) {
            $this->dispatchFraudCrossingFanout($userId, $previousScore, $score);
        } elseif ($score < $this->fraudHigh) {
            // Score dropped below threshold — clear the crossing flag so
            // future crossings fire correctly.
            $wpdb->delete(
                $wpdb->options,
                ['option_name' => '_bcc_fraud_crossed_' . $userId],
                ['%s']
            );
        }
    }

    /**
     * Dispatch fraud-crossing retroactive correction asynchronously.
     *
     * Shared by updateFraudScore() and incrementFraudScore() so BOTH paths
     * fan-out page flagging off the synchronous user request path. The
     * cooldown-flag dedup logic lives here so the hook is scheduled at most
     * once per 15-minute window per user.
     *
     * Idempotent: two concurrent crossings both compute crossing=true from
     * stale previousScore, but INSERT IGNORE on the cooldown row ensures
     * only one handler runs. Any later writer whose score drops below
     * HIGH in updateFraudScore() clears the flag.
     */
    private function dispatchFraudCrossingFanout(int $userId, int $previousScore, int $newScore): void {
        global $wpdb;

        $flagKey         = '_bcc_fraud_crossed_' . $userId;
        $cooldownSeconds = 15 * MINUTE_IN_SECONDS;
        $now             = time();

        $flagInserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %s, 'no')",
            $flagKey,
            (string) $now
        ));

        $shouldFireCorrection = false;
        if ($flagInserted > 0) {
            $shouldFireCorrection = true;
        } else {
            $lastFired = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
                $flagKey
            ));
            if ($lastFired > 0 && ($now - $lastFired) >= $cooldownSeconds) {
                $wpdb->update(
                    $wpdb->options,
                    ['option_value' => (string) $now],
                    ['option_name'  => $flagKey],
                    ['%s'],
                    ['%s']
                );
                $shouldFireCorrection = true;
            }
        }

        if (!$shouldFireCorrection) {
            return;
        }

        \BCC\Trust\Core\Security\AuditLogger::log('retroactive_correction_dispatched', $userId, [
            'previous_fraud_score' => $previousScore,
            'new_fraud_score'      => $newScore,
            'threshold'            => $this->fraudHigh,
        ], 'user');

        // Schedule async — handler is wired in Plugin::registerAsyncHooks().
        // Using wp_next_scheduled + wp_schedule_single_event gives native
        // dedup across concurrent request workers at the cron level.
        if (!wp_next_scheduled('bcc_trust_async_fraud_crossing_fanout', [$userId, $previousScore, $newScore])) {
            wp_schedule_single_event(
                time(),
                'bcc_trust_async_fraud_crossing_fanout',
                [$userId, $previousScore, $newScore]
            );
        }
    }

    /**
     * Mark all page scores that this user has voted on as needing recalculation.
     *
     * Used after fraud is detected so that inflated/deflated votes are
     * discounted during the next scheduled recalculation pass.
     */
    public function markVotedPagesForRecalculation(int $userId): int {
        global $wpdb;

        $votes_table  = \BCC\Trust\Core\Database\TableRegistry::votes();
        $scores_table = \BCC\Trust\Core\Database\TableRegistry::scores();

        // Bounded, batched UPDATE. An UPDATE...JOIN against a highly-
        // active voter would otherwise lock every affected score row in
        // one transaction — even though this now runs in an async handler
        // (post-C2 fix), unbounded page fan-outs can still starve the
        // cron if one voter has 100k+ votes.
        //
        // Batching via a derived-table LIMIT: MySQL rejects LIMIT on a
        // multi-table UPDATE (the previous UPDATE…JOIN…LIMIT shape errored
        // silently and marked nothing — same defect class as the endorsed
        // variant below). The unmarked filter lives INSIDE the derived
        // table so every iteration picks a fresh batch of genuinely
        // unmarked pages; the derived table is materialized, which is what
        // makes selecting from the updated table legal here.
        $batchSize = (int) apply_filters('bcc_trust_fraud_recalc_batch', 2000);
        $maxLoops  = 50;
        $total     = 0;
        for ($i = 0; $i < $maxLoops; $i++) {
            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$scores_table} s
                 INNER JOIN (
                     SELECT DISTINCT v.page_id
                     FROM {$votes_table} v
                     INNER JOIN {$scores_table} s2 ON s2.page_id = v.page_id
                     WHERE v.voter_user_id = %d
                       AND v.status = 1
                       AND s2.recalculate_required = 0
                     LIMIT %d
                 ) t ON t.page_id = s.page_id
                 SET s.recalculate_required = 1
                 WHERE s.recalculate_required = 0",
                $userId,
                $batchSize
            ));
            if ($affected === false || (int) $affected === 0) {
                break;
            }
            $total += (int) $affected;
        }
        return $total;
    }
    
    /**
     * Mark all page scores that this user has endorsed (= actively
     * vouched for via the Trust Attestation Layer) as needing
     * recalculation.
     *
     * Used after fraud is detected / suspension flips so the affected
     * targets get re-scored on the next recalculation pass. Attestation-
     * backed since the legacy endorsements-table retirement; the join
     * maps attestation targets onto their score-row page ids per the
     * locked target_id invariant (user_profile → ID_BASE + user id,
     * *_card → page id directly).
     *
     * Bounded via the derived-table LIMIT (MySQL rejects LIMIT on a
     * multi-table UPDATE — the legacy UPDATE…JOIN…LIMIT shape errored
     * silently and marked nothing). A single user's active vouch set is
     * realistically dozens; the cap is a runaway fence, not pagination.
     */
    public function markEndorsedPagesForRecalculation(int $userId): int {
        global $wpdb;

        $attestations_table = \BCC\Trust\Core\Database\TableRegistry::trustAttestations();
        $scores_table       = \BCC\Trust\Core\Database\TableRegistry::scores();
        $idBase             = \BCC\Trust\Core\Services\MemberSelfPageService::ID_BASE;

        $batchSize = (int) apply_filters('bcc_trust_fraud_recalc_batch', 2000);

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE {$scores_table} s
             INNER JOIN (
                 SELECT CASE
                     WHEN a.target_kind = 'user_profile' THEN a.target_id + {$idBase}
                     ELSE a.target_id
                 END AS page_id
                 FROM {$attestations_table} a
                 WHERE a.attestor_user_id = %d
                   AND a.kind = 'vouch'
                   AND a.revoked_at IS NULL
                 LIMIT %d
             ) t ON t.page_id = s.page_id
             SET s.recalculate_required = 1
             WHERE s.recalculate_required = 0",
            $userId,
            $batchSize
        ));

        return $affected === false ? 0 : (int) $affected;
    }

    /**
     * Update trust rank.
     *
     * @throws RepositoryException on database failure
     */
    public function updateTrustRank(int $userId, float $rank): void {
        global $wpdb;

        // Ensure rank is within valid range (0-1)
        $rank = max(0, min(1, $rank));

        $result = $wpdb->update(
            $this->table,
            ['trust_rank' => $rank],
            ['user_id' => $userId],
            ['%f'],
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::updateTrustRank failed for user ' . $userId . ': ' . $wpdb->last_error);
        }

        $this->invalidateCache($userId);
    }
    
    /**
     * Set votes_cast to the authoritative count provided by the caller.
     *
     * Callers (e.g. the async stats refresh and the moderation clear-votes
     * flow) compute the true count from the votes table and pass it in here.
     * Earlier this method incorrectly issued `votes_cast = votes_cast + 1`
     * regardless of `$count`, which caused the counter to drift upward on
     * every Action Scheduler retry and to *increment* when admin moderation
     * intended to reset it to zero.
     *
     * @throws RepositoryException on database failure
     */
    public function updateVotesCast(int $userId, int $count): void {
        global $wpdb;

        $count = max(0, $count);

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET votes_cast = %d WHERE user_id = %d",
            $count,
            $userId
        ));

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::updateVotesCast failed for user ' . $userId . ': ' . $wpdb->last_error);
        }

        $this->invalidateCache($userId);
    }

    /**
     * Set endorsements_given to the authoritative count provided by the caller.
     *
     * Same rationale as updateVotesCast: callers (e.g. EndorsementService
     * after a successful endorsement) pass the freshly-computed COUNT() from
     * the endorsements table. Earlier this method ignored `$count` and
     * unconditionally incremented by 1, causing drift under retries.
     *
     * @throws RepositoryException on database failure
     */
    public function updateEndorsementsGiven(int $userId, int $count): void {
        global $wpdb;

        $count = max(0, $count);

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET endorsements_given = %d WHERE user_id = %d",
            $count,
            $userId
        ));

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::updateEndorsementsGiven failed for user ' . $userId . ': ' . $wpdb->last_error);
        }

        $this->invalidateCache($userId);
    }

    
    /**
     * Update automation score.
     *
     * @throws RepositoryException on database failure
     */
    public function updateAutomationScore(int $userId, int $score): void {
        global $wpdb;

        $score = max(0, min(100, $score));

        $result = $wpdb->update(
            $this->table,
            ['automation_score' => $score],
            ['user_id' => $userId],
            ['%d'],
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::updateAutomationScore failed for user ' . $userId . ': ' . $wpdb->last_error);
        }

        $this->invalidateCache($userId);
    }
    
    /**
     * Update behavior score.
     *
     * @throws RepositoryException on database failure
     */
    public function updateBehaviorScore(int $userId, int $score): void {
        global $wpdb;

        $score = max(0, min(100, $score));

        $result = $wpdb->update(
            $this->table,
            ['behavior_score' => $score],
            ['user_id' => $userId],
            ['%d'],
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::updateBehaviorScore failed for user ' . $userId . ': ' . $wpdb->last_error);
        }

        $this->invalidateCache($userId);
    }
    
    /**
     * Update verification status.
     *
     * @throws RepositoryException on database failure
     */
    public function updateVerificationStatus(int $userId, bool $verified): void {
        global $wpdb;

        $result = $wpdb->update(
            $this->table,
            ['is_verified' => $verified ? 1 : 0],
            ['user_id' => $userId],
            ['%d'],
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::updateVerificationStatus failed for user ' . $userId . ': ' . $wpdb->last_error);
        }

        $this->invalidateCache($userId);
    }
    
    /**
     * Suspend user.
     *
     * @throws RepositoryException on database failure
     */
    public function suspendUser(int $userId, string $reason): void {
        global $wpdb;

        $result = $wpdb->update(
            $this->table,
            [
                'is_suspended' => 1,
                'fraud_triggers' => json_encode(['suspension_reason' => $reason, 'suspended_at' => current_time('mysql')])
            ],
            ['user_id' => $userId],
            ['%d', '%s'],
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::suspendUser failed for user ' . $userId . ': ' . $wpdb->last_error);
        }

        $this->invalidateCache($userId);
        // Flush the cross-plugin suspension cache (bcc-core Permissions)
        // immediately — its listener keys on this action. Without it,
        // Permissions::is_not_suspended() serves a stale answer for up to
        // its 60s TTL after a status change. invalidateCache() above only
        // clears the user_info row cache, not the Permissions cache.
        do_action('bcc_user_suspension_changed', $userId);
        $this->dispatchSuspensionFanout($userId);
    }
    
    /**
     * Unsuspend user.
     *
     * @throws RepositoryException on database failure
     */
    public function unsuspendUser(int $userId): void {
        global $wpdb;

        $userInfo = $this->getByUserId($userId);
        $currentTriggers = $userInfo && $userInfo->fraud_triggers ? json_decode($userInfo->fraud_triggers, true) : [];
        $currentTriggers['unsuspended_at'] = current_time('mysql');

        $result = $wpdb->update(
            $this->table,
            [
                'is_suspended' => 0,
                'fraud_triggers' => json_encode($currentTriggers)
            ],
            ['user_id' => $userId],
            ['%d', '%s'],
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::unsuspendUser failed for user ' . $userId . ': ' . $wpdb->last_error);
        }

        $this->invalidateCache($userId);
        // Mirror suspendUser(): flush the cross-plugin Permissions
        // suspension cache immediately so a lifted suspension takes effect
        // without waiting out the 60s TTL. See Permissions::registerHooks.
        do_action('bcc_user_suspension_changed', $userId);
        $this->dispatchSuspensionFanout($userId);
    }

    /**
     * Schedule an async fanout after suspend/unsuspend so pages voted/endorsed
     * by this user get re-scored with the new discount weight.
     *
     * Dedup: wp_next_scheduled with the same args prevents double-scheduling
     * within the same 10-second window. Handler lives in Plugin::registerAsyncJobs
     * under the `bcc_trust_async_suspension_fanout` hook.
     */
    private function dispatchSuspensionFanout(int $userId): void {
        if (!wp_next_scheduled('bcc_trust_async_suspension_fanout', [$userId])) {
            wp_schedule_single_event(time(), 'bcc_trust_async_suspension_fanout', [$userId]);
        }
    }

    /**
     * Increment fraud score atomically — safe under concurrent writers.
     *
     * Concurrency contract:
     *  - Single-statement UPDATE → InnoDB acquires an exclusive row-level
     *    lock on user_info.user_id=%d for the duration of the UPDATE.
     *  - Concurrent incrementFraudScore() calls on the SAME user SERIALISE
     *    on that X-lock. Each one reads the latest committed fraud_score
     *    (post-previous-COMMIT) as the basis for its `+ %d`.
     *  - No PHP-side read-then-write is involved. Lost updates (both writers
     *    computing from the same OLD value then one overwriting the other)
     *    CANNOT occur here.
     *  - Callers MUST NOT wrap a read of user_info with a separate UPDATE
     *    call to mutate fraud_score — always go through this method.
     *
     * @throws RepositoryException on database failure
     */
    public function incrementFraudScore(int $userId, int $increment, string $reason): void {
        global $wpdb;

        // Read the pre-increment score for crossing detection. If the row
        // is missing, previousScore is 0 (a below-HIGH baseline), which
        // means the fresh row created by subsequent writes starts from a
        // known below-threshold state.
        $before        = $this->getByUserId($userId);
        $previousScore = $before ? (int) $before->fraud_score : 0;

        // SECURITY: bump BOTH fraud_score AND peak_fraud_score atomically.
        // Without peak bump, idle fraud decay later drops fraud_score below
        // the peak*0.5 floor (since peak stays 0) and scrubs the user clean
        // — bypassing the anti-oscillation defence for device-sharing /
        // fan-in detection paths, which are the most common signal sources.
        //
        // Both expressions are SELF-CONTAINED and reference fraud_score only
        // as OLD values — we do NOT rely on MySQL's left-to-right SET
        // evaluation order. Engine / optimizer / driver changes cannot
        // silently break this. Increment is bound twice; values are identical.
        //
        // InnoDB's per-row X-lock on this single statement serialises
        // concurrent callers — no lost-update race (see method docblock).
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET fraud_score      = LEAST(100, fraud_score + %d),
                 peak_fraud_score = GREATEST(COALESCE(peak_fraud_score, 0),
                                             LEAST(100, fraud_score + %d)),
                 updated_at       = %s
             WHERE user_id = %d",
            $increment,
            $increment,
            current_time('mysql'),
            $userId
        ));

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::incrementFraudScore failed for user ' . $userId . ': ' . $wpdb->last_error);
        }

        $this->invalidateCache($userId);

        // Crossing-aware retroactive correction. Without this, fraud score
        // pushed up by device_sharing / fan_in_coordination signals bypasses
        // threshold-crossing page flagging entirely, leaving inflated votes
        // uncorrected until the next full getEnhancedFraudScore pass.
        //
        // Computed crossing is deterministic: previousScore is what the row
        // was before the UPDATE, newScore is clamped(previousScore + increment).
        // Races between concurrent incrementFraudScore callers are dedup'd
        // inside dispatchFraudCrossingFanout() via the INSERT-IGNORE cooldown.
        $newScore = min(100, $previousScore + max(0, $increment));
        if ($previousScore < $this->fraudHigh && $newScore >= $this->fraudHigh) {
            $this->dispatchFraudCrossingFanout($userId, $previousScore, $newScore);
        }
    }
    
    /**
     * Get risk level for user.
     *
     * Delegates to FraudClassification::level() — the single source of truth.
     */
    public function getRiskLevel(int $userId): string {
        $userInfo = $this->getByUserId($userId);
        if (!$userInfo) return 'unknown';

        return \BCC\Trust\Core\Support\FraudClassification::level((int) $userInfo->fraud_score);
    }
    
    /**
     * Increment page count.
     *
     * @throws RepositoryException on database failure
     */
    public function incrementPageCount(int $userId, ?int $pageId = null): void {
        global $wpdb;

        if ($pageId) {
            $userInfo = $this->getByUserId($userId);
            $pageIds = $userInfo && $userInfo->page_ids_owned ? json_decode($userInfo->page_ids_owned, true) : [];
            if (!is_array($pageIds)) $pageIds = [];
            $pageIds[] = $pageId;

            $result = $wpdb->update(
                $this->table,
                [
                    'pages_owned' => ($userInfo ? $userInfo->pages_owned : 0) + 1,
                    'page_ids_owned' => json_encode(array_unique($pageIds))
                ],
                ['user_id' => $userId],
                ['%d', '%s'],
                ['%d']
            );
        } else {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table} SET pages_owned = pages_owned + 1 WHERE user_id = %d",
                $userId
            ));
        }

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::incrementPageCount failed for user ' . $userId . ': ' . $wpdb->last_error);
        }
    }
    
    /**
     * Decrement page count.
     *
     * @throws RepositoryException on database failure
     */
    public function decrementPageCount(int $userId, ?int $pageId = null): void {
        global $wpdb;

        if ($pageId) {
            $userInfo = $this->getByUserId($userId);
            $pageIds = $userInfo && $userInfo->page_ids_owned ? json_decode($userInfo->page_ids_owned, true) : [];
            if (!is_array($pageIds)) $pageIds = [];
            $pageIds = array_values(array_diff($pageIds, [$pageId]));

            $result = $wpdb->update(
                $this->table,
                [
                    'pages_owned' => max(0, ($userInfo ? $userInfo->pages_owned : 0) - 1),
                    'page_ids_owned' => !empty($pageIds) ? json_encode($pageIds) : null
                ],
                ['user_id' => $userId],
                ['%d', '%s'],
                ['%d']
            );
        } else {
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table} SET pages_owned = GREATEST(pages_owned - 1, 0) WHERE user_id = %d",
                $userId
            ));
        }

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::decrementPageCount failed for user ' . $userId . ': ' . $wpdb->last_error);
        }
    }
    
    /**
     * Transfer page ownership
     */
    public function transferPageOwnership(int $oldOwnerId, int $newOwnerId, int $pageId): bool {
        $this->decrementPageCount($oldOwnerId, $pageId);
        $this->incrementPageCount($newOwnerId, $pageId);
        return true;
    }
    
    /**
     * Count verified users
     */
    public function countVerified(): int {
        global $wpdb;
        
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE is_verified = 1"
        );
    }
    
    /**
     * Write async-computed fraud signals back to the user_info row and
     * invalidate the object cache so the next read reflects the new values.
     *
     * Only the three columns that async jobs update are written; all other
     * columns are left untouched to avoid clobbering concurrent writes from
     * other workers.
     *
     * Accepted keys in $signals:
     *   behavior_score           int   0–100
     *   device_fraud_probability float 0.0–1.0
     *   signals_updated_at       string MySQL datetime (optional, defaults to now)
     *
     * @param int                    $userId
     * @param array<string, mixed>  $signals  Associative array of signal values (unknown keys ignored).
     */
    public function updateCachedSignals(int $userId, array $signals): void {
        global $wpdb;

        $data    = [];
        $formats = [];

        if (isset($signals['behavior_score'])) {
            $data['behavior_score'] = max(0, min(100, (int) $signals['behavior_score']));
            $formats[]              = '%d';
        }

        if (isset($signals['device_fraud_probability'])) {
            $data['device_fraud_probability'] = max(0.0, min(1.0, (float) $signals['device_fraud_probability']));
            $formats[]                        = '%f';
        }

        $data['signals_updated_at'] = $signals['signals_updated_at'] ?? current_time('mysql');
        $formats[]                  = '%s';

        if (count($data) < 2) {
            // Nothing meaningful to write (only signals_updated_at was set)
            return;
        }

        $result = $wpdb->update(
            $this->table,
            $data,
            ['user_id' => $userId],
            $formats,
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::updateCachedSignals failed for user ' . $userId . ': ' . $wpdb->last_error);
        }

        $this->invalidateCache($userId);
    }

    /**
     * Update multiple user fields at once.
     *
     * @param array<string, mixed> $data
     * @throws RepositoryException on database failure
     */
    public function batchUpdate(int $userId, array $data): void {
        global $wpdb;
        
        $allowedFields = [
            'fraud_score', 'trust_rank', 'risk_level', 'automation_score', 
            'behavior_score', 'is_verified', 'is_suspended'
        ];
        
        $updateData = [];
        $formats = [];
        
        foreach ($data as $field => $value) {
            if (in_array($field, $allowedFields)) {
                $updateData[$field] = $value;
                if (in_array($field, ['fraud_score', 'automation_score', 'behavior_score', 'is_verified', 'is_suspended'])) {
                    $formats[] = '%d';
                } elseif ($field === 'trust_rank') {
                    $formats[] = '%f';
                } elseif ($field === 'risk_level') {
                    $formats[] = '%s';
                }
            }
        }
        
        if (empty($updateData)) {
            return;
        }

        // HIGH-2 guardrail: when batchUpdate writes fraud_score directly,
        // peak_fraud_score must rise with it so subsequent decay and the
        // 50%-of-peak floor in FraudDetector::getEnhancedFraudScore
        // remain authoritative.  If the caller allowed fraud to drop
        // without ever having peaked, peak stays at its old value —
        // correct.  We only need to BUMP it up, never down.
        if (isset($updateData['fraud_score'])) {
            $newScore = (int) $updateData['fraud_score'];
            $result = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table}
                 SET peak_fraud_score = GREATEST(COALESCE(peak_fraud_score, 0), %d)
                 WHERE user_id = %d",
                $newScore,
                $userId
            ));
            if ($result === false) {
                throw new RepositoryException(
                    'UserInfoRepository::batchUpdate peak bump failed for user '
                    . $userId . ': ' . $wpdb->last_error
                );
            }
        }

        $result = $wpdb->update(
            $this->table,
            $updateData,
            ['user_id' => $userId],
            $formats,
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::batchUpdate failed for user ' . $userId . ': ' . $wpdb->last_error);
        }

        $this->invalidateCache($userId);
    }

    /**
     * Increment automation_score and fraud_score atomically (capped at 100).
     */
    public function incrementAutomationAndFraudScore(int $userId, int $increment): void
    {
        global $wpdb;

        // Read pre-increment score for crossing detection + cache bust.
        $before        = $this->getByUserId($userId);
        $previousScore = $before ? (int) $before->fraud_score : 0;

        // SECURITY: also bump peak_fraud_score so idle decay cannot scrub
        // the fraud record clean. See incrementFraudScore() docblock.
        // Expression is self-contained (reads OLD fraud_score) — does NOT
        // depend on MySQL's left-to-right SET evaluation order.
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET automation_score = LEAST(100, automation_score + %d),
                 fraud_score      = LEAST(100, fraud_score + %d),
                 peak_fraud_score = GREATEST(COALESCE(peak_fraud_score, 0),
                                             LEAST(100, fraud_score + %d))
             WHERE user_id = %d",
            $increment, $increment, $increment, $userId
        ));

        if ($result === false) {
            throw new RepositoryException('UserInfoRepository::incrementAutomationAndFraudScore failed for user ' . $userId . ': ' . $wpdb->last_error);
        }

        // Caches must be invalidated — otherwise the RateLimiter's separate
        // rl_userinfo_* cache serves pre-increment fraud_score for up to 300s,
        // letting a newly-flagged bot keep its prior rate-limit multiplier.
        $this->invalidateCache($userId);

        $newScore = min(100, $previousScore + max(0, $increment));
        if ($previousScore < $this->fraudHigh && $newScore >= $this->fraudHigh) {
            $this->dispatchFraudCrossingFanout($userId, $previousScore, $newScore);
        }
    }

    // -------------------------------------------------------------------------
    // Bulk operations for TrustGraph
    // -------------------------------------------------------------------------

    /**
     * Persist trust ranks in bulk using CASE-expression UPDATEs.
     *
     * Builds batched UPDATE ... CASE statements in chunks of 500 to reduce
     * N+1 queries to a handful.
     *
     * @param array<int, float> $ranks  userId => normalized rank
     * @return bool True if all chunks succeeded.
     */
    public function bulkUpdateTrustRanks( array $ranks ): bool {
        global $wpdb;

        $chunks  = array_chunk( $ranks, 500, true );
        $success = true;

        foreach ( $chunks as $chunk ) {
            $cases = '';
            $ids   = [];

            foreach ( $chunk as $userId => $rank ) {
                $cases .= $wpdb->prepare( ' WHEN %d THEN %f', (int) $userId, $rank );
                $ids[]  = (int) $userId;
            }

            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

            $result = $wpdb->query( $wpdb->prepare(
                "UPDATE {$this->table}
                 SET trust_rank = CASE user_id {$cases} ELSE trust_rank END
                 WHERE user_id IN ({$placeholders})",
                ...$ids
            ) );

            if ( $result === false ) {
                \BCC\Core\Log\Logger::error( '[bcc-trust] UserInfoRepository::bulkUpdateTrustRanks failed - ', [ 'detail' => $wpdb->last_error ] );
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Get trust graph statistics from user_info.
     *
     * @param float $highTrustThreshold
     * @param float $lowTrustThreshold
     * @return object|null
     */
    public function getTrustGraphStats( float $highTrustThreshold = 0.8, float $lowTrustThreshold = 0.2 ): ?object {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) as total_users_tracked,
                AVG(trust_rank) as average_trust_rank,
                SUM(CASE WHEN trust_rank > %f THEN 1 ELSE 0 END) as high_trust_count,
                SUM(CASE WHEN trust_rank < %f THEN 1 ELSE 0 END) as low_trust_count
             FROM {$this->table}
             WHERE trust_rank > %d",
            $highTrustThreshold,
            $lowTrustThreshold,
            0
        ) );
    }

    /**
     * Get behavior score statistics.
     *
     * @param int $highThreshold
     * @param int $mediumThreshold
     * @param int $lowThreshold
     * @return array{total_analyzed: int, avg_score: float, high_risk: int, medium_risk: int, low_risk: int}
     */
    public function getBehaviorStats( int $highThreshold, int $mediumThreshold, int $lowThreshold ): array {
        global $wpdb;

        $totalAnalyzed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE behavior_score > %d",
            0
        ) );

        $avgScore = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT AVG(behavior_score) FROM {$this->table} WHERE behavior_score > %d",
            0
        ) );

        $highRisk = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE behavior_score > %d",
            $highThreshold
        ) );

        $mediumRisk = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE behavior_score BETWEEN %d AND %d",
            $mediumThreshold,
            $highThreshold
        ) );

        $lowRisk = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE behavior_score BETWEEN %d AND %d",
            $lowThreshold,
            $mediumThreshold
        ) );

        return [
            'total_analyzed' => $totalAnalyzed,
            'avg_score'      => $avgScore,
            'high_risk'      => $highRisk,
            'medium_risk'    => $mediumRisk,
            'low_risk'       => $lowRisk,
        ];
    }

    /**
     * Count orphaned user_info records (user no longer exists in wp_users).
     */
    public function countOrphaned(): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} u
             LEFT JOIN {$wpdb->users} w ON u.user_id = w.ID
             WHERE w.ID IS NULL AND %d",
            1
        ));
    }

    /**
     * Delete orphaned user_info records.
     *
     * @return int Number of rows deleted.
     */
    public function deleteOrphaned(): int
    {
        global $wpdb;

        return (int) $wpdb->query($wpdb->prepare(
            "DELETE u FROM {$this->table} u
             LEFT JOIN {$wpdb->users} w ON u.user_id = w.ID
             WHERE w.ID IS NULL AND %d",
            1
        ));
    }

    /**
     * Get WordPress users missing from the user_info table.
     *
     * @return object[]
     */
    public function getMissingUsers(int $limit = 10): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT u.ID, u.user_login, u.user_email
             FROM {$wpdb->users} u
             LEFT JOIN {$this->table} ui ON u.ID = ui.user_id
             WHERE ui.user_id IS NULL
             LIMIT %d",
            $limit
        )) ?: [];
    }

    /**
     * Get distinct active user IDs from the activity log within a time window.
     *
     * @return int[]
     */
    public function getActiveUserIds(int $days, int $limit, int $afterUserId = 0): array
    {
        global $wpdb;

        $activityTable = \BCC\Trust\Core\Database\TableRegistry::activity();

        // Deterministic ordering + cursor pagination so sites with more
        // active users than $limit can rotate through everyone across
        // successive cron runs instead of re-picking the same stable-heap
        // subset forever.
        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$activityTable}
             WHERE created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
               AND user_id > %d
             ORDER BY user_id ASC
             LIMIT %d",
            $days, $afterUserId, $limit
        )));
    }

    /**
     * Get suspended user IDs from a set of user IDs.
     *
     * @param int[] $userIds
     * @return int[]
     */
    public function getSuspendedUserIds(array $userIds): array
    {
        global $wpdb;

        if (empty($userIds)) {
            return [];
        }

        $safe = implode(',', array_map('intval', $userIds));

        return array_map('intval', $wpdb->get_col(
            "SELECT user_id FROM {$this->table}
             WHERE is_suspended = 1
               AND user_id IN ({$safe})"
        ));
    }

    /**
     * Update fraud-related fields for a user (admin reanalysis).
     */
    public function updateFraudFields(int $userId, int $fraudScore, string $riskLevel, int $behaviorScore): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table,
            [
                'fraud_score'    => $fraudScore,
                'risk_level'     => $riskLevel,
                'risk_label'     => \BCC\Trust\Core\Support\FraudClassification::label($fraudScore),
                'risk_color'     => \BCC\Trust\Core\Support\FraudClassification::color($fraudScore),
                'behavior_score' => $behaviorScore,
            ],
            ['user_id' => $userId],
            ['%d', '%s', '%s', '%s', '%d'],
            ['%d']
        );

        $this->invalidateCache($userId);
    }

    /**
     * Clear device fingerprint and automation score for a user.
     */
    public function clearFingerprint(int $userId): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table,
            ['device_fingerprint' => null, 'automation_score' => 0],
            ['user_id' => $userId],
            ['%s', '%d'],
            ['%d']
        );

        $this->invalidateCache($userId);
    }

    /**
     * Apply daily fraud score decay for idle non-suspended users.
     *
     * Score can never decay below 50% of peak_fraud_score.
     *
     * Also fans out to `recalculate_required` on pages voted/endorsed by the
     * decayed users so the read-model reflects the new fraud-discount weight.
     * Without the fanout, pages owned by active users who got votes from a
     * decaying voter would show stale positive_score/negative_score until
     * the daily full sync picks them up.
     */
    public function applyDailyFraudDecay(): void
    {
        global $wpdb;

        $idleDays  = BCC_TRUST_FRAUD_DECAY_IDLE_DAYS;
        $batchSize = (int) apply_filters('bcc_trust_fraud_decay_batch', 5000);
        $maxLoops  = 40; // hard ceiling: 200k rows / daily cron / 120s budget

        // Two-step per batch so we know which user_ids to fan out to:
        //   1. SELECT the IDs we're about to decay (same predicate as UPDATE).
        //   2. UPDATE those rows by user_id IN (...).
        //   3. Mark their voted/endorsed pages for recalculation.
        // The formula GREATEST(FLOOR(peak*0.5), fraud_score - 1) is idempotent
        // under concurrent writers — if another writer bumped fraud_score
        // between the SELECT and UPDATE, decaying the new value by 1 is still
        // correct.
        for ($i = 0; $i < $maxLoops; $i++) {
            $userIds = $wpdb->get_col($wpdb->prepare(
                "SELECT user_id FROM {$this->table}
                 WHERE fraud_score > 0
                   AND is_suspended = 0
                   AND updated_at < DATE_SUB(NOW(), INTERVAL %d DAY)
                 ORDER BY user_id ASC
                 LIMIT %d",
                $idleDays,
                $batchSize
            ));

            if (empty($userIds)) {
                break;
            }

            $userIds      = array_map('intval', $userIds);
            $placeholders = implode(',', array_fill(0, count($userIds), '%d'));

            $affected = $wpdb->query($wpdb->prepare(
                "UPDATE {$this->table}
                 SET fraud_score = GREATEST(
                     FLOOR(COALESCE(peak_fraud_score, 0) * 0.5),
                     fraud_score - 1
                 )
                 WHERE user_id IN ({$placeholders})",
                $userIds
            ));

            if ($affected === false) {
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] applyDailyFraudDecay batch failed', [
                        'iteration' => $i,
                        'db_error'  => $wpdb->last_error,
                    ]);
                }
                break;
            }

            // Fan out: flag every page each decayed user has voted on or
            // endorsed. Each markX method is itself batched (2000 rows/loop),
            // so a high-volume voter won't lock the scores table.
            foreach ($userIds as $uid) {
                $this->markVotedPagesForRecalculation($uid);
                $this->markEndorsedPagesForRecalculation($uid);
                $this->invalidateCache($uid);
            }

            if (count($userIds) < $batchSize) {
                break;
            }
        }
    }

    /**
     * Check if the user_info table exists.
     */
    public function tableExists(): bool
    {
        return \BCC\Trust\Core\Database\TableRegistry::exists($this->table);
    }

    /**
     * Check if a user exists in the user_info table.
     */
    public function exists(int $userId): bool
    {
        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM {$this->table} WHERE user_id = %d",
            $userId
        ));
    }

    /**
     * Insert a new user_info row.
     *
     * @param array<string, mixed> $data
     * @param string[] $formats
     */
    public function insert(array $data, array $formats): bool
    {
        global $wpdb;

        return $wpdb->insert($this->table, $data, $formats) !== false;
    }

    /**
     * Update an existing user_info row by user_id.
     *
     * @param array<string, mixed> $data
     * @param string[] $formats
     */
    public function updateByUserId(int $userId, array $data, array $formats): bool
    {
        global $wpdb;

        $result = $wpdb->update($this->table, $data, ['user_id' => $userId], $formats, ['%d']);
        $this->invalidateCache($userId);

        return $result !== false;
    }

    /**
     * Get the fraud_score for a user.
     */
    public function getFraudScore(int $userId): int
    {
        $userInfo = $this->getByUserId($userId);
        return $userInfo ? (int) $userInfo->fraud_score : 0;
    }

    /**
     * Hard-delete a user's row from the user_info table.
     *
     * Used during user deletion to remove analytics data.
     *
     * @param int $userId
     * @return int|false Number of rows deleted, or false on failure.
     */
    public function deleteByUserId(int $userId)
    {
        global $wpdb;

        $result = $wpdb->delete(
            $this->table,
            ['user_id' => $userId],
            ['%d']
        );

        if ($result !== false) {
            $this->invalidateCache($userId);
        }

        return $result;
    }
}