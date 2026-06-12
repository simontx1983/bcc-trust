<?php
/**
 * Reputation Repository
 * 
 * Handles user reputation data and vote weight calculations
 * 
 * @package BCC\Trust\Core\Repositories
 * @version 2.1.0
 */

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) exit;

use Exception;
use RuntimeException;
use BCC\Trust\Core\Exceptions\RepositoryException;

/**
 * Row shape returned by bcc_trust_reputation reads. getByUserId() casts the
 * numeric fields before returning, so consumers receive real types — other
 * accessors that return raw $wpdb rows keep string forms.
 *
 * @phpstan-type ReputationRow object{
 *   id: int|numeric-string,
 *   user_id: int|numeric-string,
 *   reputation_score: float,
 *   reputation_tier: string,
 *   total_votes_cast: int,
 *   total_votes_received: int,
 *   flag_count: int,
 *   vote_weight: float,
 *   last_calculated_at: string
 * }
 */
class ReputationRepository {

    /** Explicit column list for bcc_trust_reputation table. */
    private const COLUMNS = 'id, user_id, reputation_score, reputation_tier, total_votes_cast, total_votes_received, flag_count, vote_weight, last_calculated_at';

    private string $table;

    private const CACHE_GROUP = 'bcc_trust_reputation';

    // Default reputation values
    /** @see BCC_TRUST_NEUTRAL_SCORE in config/scoring.php */
    private const DEFAULT_REPUTATION_SCORE = 50.0; // Must match BCC_TRUST_NEUTRAL_SCORE
    private const DEFAULT_VOTE_WEIGHT = 1.0;

    public function __construct() {
        $this->table = \BCC\Trust\Core\Database\TableRegistry::reputation();
    }

    /**
     * Request-scoped row memo. getTier/getScore/getVoteWeight all funnel
     * through getByUserId, and list surfaces (member cards) read several
     * of them per row — without a memo a 24-row page re-reads the same
     * rows up to 4× each. Stores misses (null) too. Primed in bulk by
     * primeByUserIds(); every write path calls forget() so a
     * read-after-write in the same request sees fresh data.
     *
     * @var array<int, object|null>
     * @phpstan-var array<int, ReputationRow|null>
     */
    private static array $rowMemo = [];

    /**
     * Bulk-warm the row memo for a bounded id list (one IN query).
     * Ids absent from the table are memoized as null so the per-user
     * fallback query is skipped for them as well.
     *
     * @param list<int> $userIds
     */
    public function primeByUserIds(array $userIds): void {
        $wanted = [];
        foreach ($userIds as $id) {
            $intId = (int) $id;
            if ($intId > 0 && !array_key_exists($intId, self::$rowMemo)) {
                $wanted[$intId] = true;
            }
        }
        if ($wanted === []) {
            return;
        }

        global $wpdb;
        $idList       = array_keys($wanted);
        $placeholders = implode(',', array_fill(0, count($idList), '%d'));

        /** @var list<\stdClass>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$this->table} WHERE user_id IN ({$placeholders})",
            ...$idList
        ));

        foreach ($idList as $id) {
            self::$rowMemo[$id] = null;
        }
        foreach ($rows ?: [] as $row) {
            // Same numeric casts getByUserId applies on the single-row path.
            $row->reputation_score     = (float) $row->reputation_score;
            $row->total_votes_cast     = (int) $row->total_votes_cast;
            $row->total_votes_received = (int) $row->total_votes_received;
            $row->flag_count           = (int) $row->flag_count;
            $row->vote_weight          = (float) $row->vote_weight;

            /** @phpstan-var ReputationRow $row */
            self::$rowMemo[(int) $row->user_id] = $row;
        }
    }

    /** Drop a user's memoized row after a write so re-reads are fresh. */
    private static function forget(int $userId): void {
        unset(self::$rowMemo[$userId]);
    }

    /**
     * Get reputation record by user ID
     *
     * @phpstan-return ReputationRow|null
     */
    public function getByUserId(int $userId): ?object {
        if (array_key_exists($userId, self::$rowMemo)) {
            return self::$rowMemo[$userId];
        }

        global $wpdb;

        /** @var \stdClass|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$this->table} WHERE user_id = %d",
            $userId
        ));

        if ($row) {
            // Cast numeric fields
            $row->reputation_score     = (float) $row->reputation_score;
            $row->total_votes_cast     = (int) $row->total_votes_cast;
            $row->total_votes_received = (int) $row->total_votes_received;
            $row->flag_count           = (int) $row->flag_count;
            $row->vote_weight          = (float) $row->vote_weight;
        }

        /** @phpstan-var ReputationRow|null $row */
        self::$rowMemo[$userId] = $row;

        return $row;
    }

    /**
     * Create or update reputation record
     *
     * @param array<string, mixed> $data
     */
    public function createOrUpdate(int $userId, array $data): void {
        self::forget($userId);
        global $wpdb;

        // Validate and sanitize input data
        $validated = $this->validateData($data);

        // Calculate tier based on reputation score using config thresholds
        if (isset($validated['reputation_score'])) {
            $validated['reputation_tier'] = $this->calculateTier($validated['reputation_score']);
        }

        // Ensure last_calculated_at is set
        if (!isset($validated['last_calculated_at'])) {
            $validated['last_calculated_at'] = current_time('mysql');
        }

        $validated['user_id'] = $userId;

        // Build atomic INSERT ... ON DUPLICATE KEY UPDATE to eliminate race condition.
        $columns = array_keys($validated);
        $placeholders = implode(', ', $this->getFormatSpecifiers($validated));
        $colList = implode(', ', $columns);

        $updates = [];
        foreach ($columns as $col) {
            if ($col === 'user_id') continue;
            $updates[] = "{$col} = VALUES({$col})";
        }
        $updateClause = implode(', ', $updates);

        $sql = "INSERT INTO {$this->table} ({$colList}) VALUES ({$placeholders})
                ON DUPLICATE KEY UPDATE {$updateClause}";

        $result = $wpdb->query($wpdb->prepare($sql, array_values($validated)));

        if ($result === false) {
            throw new RuntimeException(
                'ReputationRepository::createOrUpdate failed for user '
                . $userId . ': ' . $wpdb->last_error
            );
        }

        $this->mirrorTierToUserInfo([$userId]);

        wp_cache_delete('reputation_stats', self::CACHE_GROUP);
    }

    /**
     * Mirror `reputation_tier` from bcc_trust_reputation into the denormalized
     * user_info column. Callers: every repository write that can change the tier.
     * Why: VoteRepository / RateLimiter / VerificationService / TrustRestController
     * all read `ui.reputation_tier`; keeping the denorm column fresh prevents
     * drift without changing those hot-path reads.
     *
     * @param int[] $userIds
     */
    private function mirrorTierToUserInfo(array $userIds): void {
        if (empty($userIds)) {
            return;
        }
        global $wpdb;
        $userInfoTable = \BCC\Trust\Core\Database\TableRegistry::userInfo();
        $placeholders  = implode(',', array_fill(0, count($userIds), '%d'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$userInfoTable} ui
             INNER JOIN {$this->table} r ON ui.user_id = r.user_id
             SET ui.reputation_tier = r.reputation_tier
             WHERE ui.user_id IN ({$placeholders})",
            ...array_map('intval', $userIds)
        ));
    }

    /**
     * Update specific fields for a user
     *
     * @param array<string, mixed> $data
     * @throws RuntimeException if the UPDATE query fails
     */
    public function update(int $userId, array $data): bool {
        self::forget($userId);
        global $wpdb;

        $validated = $this->validateData($data);

        if (isset($validated['reputation_score'])) {
            $validated['reputation_tier'] = $this->calculateTier($validated['reputation_score']);
        }

        $validated['last_calculated_at'] = current_time('mysql');

        $result = $wpdb->update(
            $this->table,
            $validated,
            ['user_id' => $userId],
            $this->getFormatSpecifiers($validated),
            ['%d']
        );

        if ($result === false) {
            throw new RuntimeException(
                'ReputationRepository::update failed for user '
                . $userId . ': ' . $wpdb->last_error
            );
        }

        if (isset($validated['reputation_tier'])) {
            $this->mirrorTierToUserInfo([$userId]);
        }

        wp_cache_delete('reputation_stats', self::CACHE_GROUP);

        return true;
    }

    /**
     * Increment votes cast for a user
     *
     * @throws RuntimeException if the UPDATE query fails
     */
    public function incrementVotesCast(int $userId, int $count = 1): void {
        self::forget($userId);
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET total_votes_cast = total_votes_cast + %d,
                 last_calculated_at = %s
             WHERE user_id = %d",
            $count,
            current_time('mysql'),
            $userId
        ));
        if ($result === false) {
            throw new RuntimeException(
                'ReputationRepository::incrementVotesCast failed for user '
                . $userId . ': ' . $wpdb->last_error
            );
        }
    }

    /**
     * Increment votes received for a user
     *
     * @throws RuntimeException if the UPDATE query fails
     */
    public function incrementVotesReceived(int $userId, int $count = 1): void {
        self::forget($userId);
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET total_votes_received = total_votes_received + %d,
                 last_calculated_at = %s
             WHERE user_id = %d",
            $count,
            current_time('mysql'),
            $userId
        ));
        if ($result === false) {
            throw new RuntimeException(
                'ReputationRepository::incrementVotesReceived failed for user '
                . $userId . ': ' . $wpdb->last_error
            );
        }
    }

    /**
     * Increment flag count for a user
     *
     * @throws RuntimeException if the UPDATE query fails
     */
    public function incrementFlagCount(int $userId, int $count = 1): void {
        self::forget($userId);
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET flag_count = flag_count + %d,
                 last_calculated_at = %s
             WHERE user_id = %d",
            $count,
            current_time('mysql'),
            $userId
        ));
        if ($result === false) {
            throw new RuntimeException(
                'ReputationRepository::incrementFlagCount failed for user '
                . $userId . ': ' . $wpdb->last_error
            );
        }
    }

    /**
     * Update vote weight for a user using config constraints.
     *
     * @throws RepositoryException on database failure
     */
    public function updateVoteWeight(int $userId, float $weight): void {
        self::forget($userId);
        global $wpdb;

        $weight = max(BCC_TRUST_MIN_VOTE_WEIGHT, min(BCC_TRUST_MAX_VOTE_WEIGHT, $weight));

        $result = $wpdb->update(
            $this->table,
            [
                'vote_weight' => $weight,
                'last_calculated_at' => current_time('mysql')
            ],
            ['user_id' => $userId],
            ['%f', '%s'],
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('ReputationRepository::updateVoteWeight failed for user ' . $userId . ': ' . $wpdb->last_error);
        }
    }

    /**
     * Get users by reputation tier
     *
     * @return object[]
     */
    public function getByTier(string $tier, int $limit = 100): array {
        global $wpdb;
        
        $validTiers = ['elite', 'trusted', 'neutral', 'caution', 'risky'];
        if (!in_array($tier, $validTiers)) {
            return [];
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT r.id, r.user_id, r.reputation_score, r.reputation_tier, r.total_votes_cast, r.total_votes_received, r.flag_count, r.vote_weight, r.last_calculated_at, u.display_name, u.user_email
             FROM {$this->table} r
             JOIN {$wpdb->users} u ON r.user_id = u.ID
             WHERE r.reputation_tier = %s
             ORDER BY r.reputation_score DESC
             LIMIT %d",
            $tier,
            $limit
        ));
    }

    /**
     * Delete reputation record for a user.
     *
     * @throws RepositoryException on database failure
     */
    public function delete(int $userId): void {
        self::forget($userId);
        global $wpdb;

        $result = $wpdb->delete(
            $this->table,
            ['user_id' => $userId],
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('ReputationRepository::delete failed for user ' . $userId . ': ' . $wpdb->last_error);
        }

        wp_cache_delete('reputation_stats', self::CACHE_GROUP);
    }

    /**
     * Validate and sanitize reputation data
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validateData(array $data): array {
        $validated = [];
        
        // Allowed fields and their types
        $allowedFields = [
            'reputation_score' => 'float',
            'total_votes_cast' => 'int',
            'total_votes_received' => 'int',
            'flag_count' => 'int',
            'vote_weight' => 'float',
            'reputation_tier' => 'string',
            'last_calculated_at' => 'string'
        ];
        
        foreach ($allowedFields as $field => $type) {
            if (isset($data[$field])) {
                switch ($type) {
                    case 'float':
                        $validated[$field] = (float) $data[$field];
                        if ($field === 'reputation_score') {
                            $validated[$field] = max(0, min(100, $validated[$field]));
                        }
                        if ($field === 'vote_weight') {
                            $validated[$field] = max(BCC_TRUST_MIN_VOTE_WEIGHT, min(BCC_TRUST_MAX_VOTE_WEIGHT, $validated[$field]));
                        }
                        break;
                    case 'int':
                        $validated[$field] = max(0, (int) $data[$field]);
                        break;
                    case 'string':
                        if ($field === 'reputation_tier') {
                            $validTiers = ['elite', 'trusted', 'neutral', 'caution', 'risky'];
                            if (in_array($data[$field], $validTiers)) {
                                $validated[$field] = $data[$field];
                            }
                        } else {
                            $validated[$field] = sanitize_text_field($data[$field]);
                        }
                        break;
                }
            }
        }
        
        return $validated;
    }

    /**
     * Get format specifiers for wpdb operations
     *
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function getFormatSpecifiers(array $data): array {
        $formats = [];
        
        foreach ($data as $field => $value) {
            if (in_array($field, ['user_id'])) {
                continue; // Skip user_id for format specifiers
            }
            
            switch ($field) {
                case 'reputation_score':
                case 'vote_weight':
                    $formats[] = '%f';
                    break;
                case 'total_votes_cast':
                case 'total_votes_received':
                case 'flag_count':
                    $formats[] = '%d';
                    break;
                case 'reputation_tier':
                case 'last_calculated_at':
                    $formats[] = '%s';
                    break;
                default:
                    $formats[] = '%s';
            }
        }
        
        return $formats;
    }

    /**
     * Calculate reputation tier based on score using config thresholds
     */
    private function calculateTier(float $score): string {
        if ($score >= BCC_TRUST_TIER_ELITE)   return 'elite';
        if ($score >= BCC_TRUST_TIER_TRUSTED)  return 'trusted';
        if ($score >= BCC_TRUST_TIER_NEUTRAL)  return 'neutral';
        if ($score >= BCC_TRUST_TIER_CAUTION)  return 'caution';
        return 'risky';
    }

    /**
     * Initialize reputation for a new user
     */
    public function initializeForUser(int $userId): void {
        $defaults = [
            'reputation_score' => self::DEFAULT_REPUTATION_SCORE,
            'total_votes_cast' => 0,
            'total_votes_received' => 0,
            'flag_count' => 0,
            'vote_weight' => self::DEFAULT_VOTE_WEIGHT,
            'reputation_tier' => 'neutral',
            'last_calculated_at' => current_time('mysql')
        ];
        
        $this->createOrUpdate($userId, $defaults);
    }

    /**
     * Get reputation score for a user
     */
    public function getScore(int $userId): float {
        $record = $this->getByUserId($userId);
        return $record ? (float) $record->reputation_score : self::DEFAULT_REPUTATION_SCORE;
    }

    /**
     * Get vote weight for a user
     */
    public function getVoteWeight(int $userId): float {
        $record = $this->getByUserId($userId);

        return $record ? (float) $record->vote_weight : self::DEFAULT_VOTE_WEIGHT;
    }

    /**
     * Get reputation tier for a user
     */
    public function getTier(int $userId): string {
        $record = $this->getByUserId($userId);
        return $record ? $record->reputation_tier : 'neutral';
    }

    /**
     * Batched form of `getTier()` — resolve reputation tiers for many
     * users in a single SELECT. Used by feed/comment view-model
     * assemblers (FeedRankingService::hydrateAuthorRanks,
     * CommentService::shapeCommentRow) to avoid N+1 across a page of
     * authors.
     *
     * Returns only users with a real bcc_trust_reputation row.
     * Callers that need a sentinel for unseen users default to
     * `neutral` (mirrors `getTier()`'s missing-row fallback).
     *
     * Bounded by caller (feed page cap = 50; comment page cap = 50).
     * Empty input short-circuits.
     *
     * @param list<int> $userIds
     * @return array<int, string> user_id → reputation_tier
     */
    public function getTiersForUsers(array $userIds): array {
        if ($userIds === []) {
            return [];
        }

        $clean = [];
        foreach ($userIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $clean[$intId] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $idList = array_keys($clean);
        $placeholders = implode(',', array_fill(0, count($idList), '%d'));

        global $wpdb;
        /** @var list<object{user_id: int|numeric-string, reputation_tier: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id, reputation_tier
               FROM {$this->table}
              WHERE user_id IN ({$placeholders})",
            ...$idList
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $uid = (int) $row->user_id;
            if ($uid > 0) {
                $out[$uid] = (string) $row->reputation_tier;
            }
        }
        return $out;
    }

    /**
     * User IDs whose reputation tier is `caution` or `risky` — the §O4.1
     * "shadow-limited" set. Used by the feed ranker to exclude these
     * users' posts from feed inputs in a single query.
     *
     * Bounded by LIMIT — V1 expects this set to stay small (< few
     * hundred). If it grows past the cap, the feed-ranker strategy
     * needs revisiting (per-page filtering instead of preloaded list).
     *
     * @return list<int>
     */
    public function getCautionAndRiskyUserIds(int $limit = 1000): array {
        global $wpdb;

        /** @var list<object{user_id: int|numeric-string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT user_id
               FROM {$this->table}
              WHERE reputation_tier IN ('caution', 'risky')
              LIMIT %d",
            $limit
        ));

        $ids = [];
        foreach ($rows ?: [] as $row) {
            $ids[] = (int) $row->user_id;
        }
        return $ids;
    }

    /**
     * Check if user has sufficient reputation using config threshold
     */
    public function hasSufficientReputation(int $userId, ?float $minScore = null): bool {
        return $this->getScore($userId) >= ($minScore ?? BCC_TRUST_MIN_REPUTATION_FOR_VOTING);
    }

    /**
     * Batch update reputation scores — chunked UPDATE...CASE WHEN to avoid
     * oversized SQL and long row locks at scale.
     *
     * Processes in chunks of 500 user IDs per query to keep lock duration
     * bounded while still being significantly faster than N individual UPDATEs.
     *
     * @param array<int, float> $updates userId => score
     */
    public function batchUpdate(array $updates): int {
        global $wpdb;

        if ( empty( $updates ) ) {
            return 0;
        }

        foreach (array_keys($updates) as $memoUserId) {
            self::forget((int) $memoUserId);
        }

        $totalAffected = 0;
        $chunks        = array_chunk( $updates, 500, true );

        foreach ( $chunks as $chunk ) {
            $now         = current_time( 'mysql' );
            $score_cases = '';
            $tier_cases  = '';
            $ids         = [];

            foreach ( $chunk as $userId => $score ) {
                $score        = max( 0.0, min( 100.0, (float) $score ) );
                $tier         = $this->calculateTier( $score );
                $score_cases .= $wpdb->prepare( ' WHEN %d THEN %f', (int) $userId, $score );
                $tier_cases  .= $wpdb->prepare( ' WHEN %d THEN %s', (int) $userId, $tier );
                $ids[]        = (int) $userId;
            }

            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

            $result = $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$this->table}
                     SET reputation_score   = CASE user_id {$score_cases} END,
                         reputation_tier    = CASE user_id {$tier_cases} END,
                         last_calculated_at = %s
                     WHERE user_id IN ({$placeholders})",
                    ...array_merge( [ $now ], $ids )
                )
            );

            if ( $result !== false ) {
                $totalAffected += (int) $result;
            }

            $this->mirrorTierToUserInfo($ids);
        }

        wp_cache_delete('reputation_stats', self::CACHE_GROUP);

        return $totalAffected;
    }

    /**
     * Get aggregate voting stats for pages owned by a user.
     *
     * Returns an object with positive_weight, negative_weight, and unique_voters
     * or null if no data is found.
     *
     * @phpstan-return object{
     *   positive_weight: float|numeric-string,
     *   negative_weight: float|numeric-string,
     *   unique_voters: int|numeric-string
     * }|null
     */
    public function getVotingStatsForOwner(int $userId): ?object {
        global $wpdb;

        $votesTable  = \BCC\Trust\Core\Database\TableRegistry::votes();
        $scoresTable = \BCC\Trust\Core\Database\TableRegistry::scores();

        return $wpdb->get_row($wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN v.vote_type > 0 THEN v.weight ELSE 0 END), 0) as positive_weight,
                COALESCE(SUM(CASE WHEN v.vote_type < 0 THEN v.weight ELSE 0 END), 0) as negative_weight,
                COUNT(DISTINCT v.voter_user_id) as unique_voters
             FROM {$votesTable} v
             INNER JOIN {$scoresTable} s ON v.page_id = s.page_id
             WHERE s.page_owner_id = %d
               AND v.status = 1",
            $userId
        ));
    }

    /**
     * Adjust a user's reputation score by a delta (positive or negative).
     *
     * Clamps result to [0, 100], recalculates tier, and persists.
     *
     * @param int    $userId
     * @param float  $delta   Positive to boost, negative to penalize.
     * @param string $reason  Audit label (e.g. 'dispute_rejected').
     */
    public function adjustScore(int $userId, float $delta, string $reason = ''): void {
        self::forget($userId);
        global $wpdb;

        // Ensure a row exists first (atomic upsert with defaults).
        $this->createOrUpdate($userId, []);

        // Atomic increment clamped to [0, 100] — no read-modify-write race.
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET reputation_score = LEAST(100.0, GREATEST(0.0, reputation_score + %f)),
                 reputation_tier  = CASE
                     WHEN LEAST(100.0, GREATEST(0.0, reputation_score + %f)) >= %f THEN 'elite'
                     WHEN LEAST(100.0, GREATEST(0.0, reputation_score + %f)) >= %f THEN 'trusted'
                     WHEN LEAST(100.0, GREATEST(0.0, reputation_score + %f)) >= %f THEN 'neutral'
                     WHEN LEAST(100.0, GREATEST(0.0, reputation_score + %f)) >= %f THEN 'caution'
                     ELSE 'risky'
                 END,
                 last_calculated_at = %s
             WHERE user_id = %d",
            $delta,
            $delta, BCC_TRUST_TIER_ELITE,
            $delta, BCC_TRUST_TIER_TRUSTED,
            $delta, BCC_TRUST_TIER_NEUTRAL,
            $delta, BCC_TRUST_TIER_CAUTION,
            current_time('mysql'),
            $userId
        ));

        if ($result === false) {
            throw new RuntimeException(
                'ReputationRepository::adjustScore failed for user ' . $userId . ': ' . $wpdb->last_error
            );
        }

        $this->mirrorTierToUserInfo([$userId]);

        wp_cache_delete('reputation_stats', self::CACHE_GROUP);
    }

    /**
     * Get eligible panelist candidates (trusted/elite tier, not high-fraud, not suspended).
     *
     * Returns user_id and last_ip_address for IP diversity filtering.
     *
     * @param string[] $tiers           Allowed reputation tiers.
     * @param int      $fraudThreshold  Max fraud score to allow.
     * @param int[]    $excludeUserIds  User IDs to exclude.
     * @param int      $limit           Max rows to return.
     * @return object[] Rows with user_id and last_ip_address.
     * @phpstan-return list<object{user_id: int|numeric-string, last_ip_address: string|null}>
     */
    public function getEligiblePanelists(array $tiers, int $fraudThreshold, array $excludeUserIds, int $limit): array
    {
        global $wpdb;

        $userInfoTable    = \BCC\Trust\Core\Database\TableRegistry::userInfo();
        $tierPlaceholders = implode(',', array_fill(0, count($tiers), '%s'));

        // Common WHERE clause + params, used by both strategies.
        $whereSql = "r.reputation_tier IN ({$tierPlaceholders})
                  AND r.user_id > 0
                  AND (ui.fraud_score IS NULL OR ui.fraud_score < %d)
                  AND (ui.is_suspended IS NULL OR ui.is_suspended = 0)";
        $whereParams = array_merge($tiers, [$fraudThreshold]);

        if (!empty($excludeUserIds)) {
            $excludedPlaceholders = implode(',', array_fill(0, count($excludeUserIds), '%d'));
            $whereSql .= " AND r.user_id NOT IN ({$excludedPlaceholders})";
            $whereParams = array_merge($whereParams, $excludeUserIds);
        }

        // ── Strategy switch ────────────────────────────────────────────────
        // Default: ORDER BY RAND() on the filtered eligible pool. Works well
        // up to ~1k eligible users. For typical deployments this is a few
        // hundred rows — acceptable p50/p95.
        //
        // Opt-in: USE_RANDOM_OFFSET_SELECTION constant → COUNT(*) + random
        // OFFSET + LIMIT. Trades per-call randomness (contiguous PK slice)
        // for O(log N) sampling when the pool grows past ~10k. Two queries
        // instead of one, but neither performs a filesort over the full set.
        $useOffset = defined('USE_RANDOM_OFFSET_SELECTION') && USE_RANDOM_OFFSET_SELECTION;

        if ($useOffset) {
            return self::selectByRandomOffset(
                $this->table,
                $userInfoTable,
                $whereSql,
                $whereParams,
                $limit
            );
        }

        $sql = "SELECT r.user_id, ui.last_ip_address
                FROM {$this->table} r
                LEFT JOIN {$userInfoTable} ui ON r.user_id = ui.user_id
                WHERE {$whereSql}
                ORDER BY RAND() LIMIT %d";
        $params   = $whereParams;
        $params[] = $limit;

        /** @var list<object{user_id: int|numeric-string, last_ip_address: string|null}> $pool */
        $pool = $wpdb->get_results($wpdb->prepare($sql, ...$params)) ?: [];
        return $pool;
    }

    /**
     * Alternative selection strategy: COUNT(*) then random OFFSET + LIMIT.
     *
     * Produces a contiguous PK slice starting at a uniformly-chosen offset.
     * Less random than ORDER BY RAND() (neighbours in PK space will tend to
     * be co-selected) but avoids filesort on very large eligible pools.
     *
     * @param string       $repTable      Qualified reputation table name.
     * @param string       $userInfoTable Qualified user-info table name.
     * @param string       $whereSql      WHERE clause body (no leading WHERE).
     * @param array<mixed> $whereParams   Prepared params for $whereSql.
     * @return list<object{user_id: int|numeric-string, last_ip_address: string|null}>
     */
    private static function selectByRandomOffset(
        string $repTable,
        string $userInfoTable,
        string $whereSql,
        array $whereParams,
        int $limit
    ): array {
        global $wpdb;

        // 1) COUNT(*) on the filtered set.
        $countSql = "SELECT COUNT(*)
                     FROM {$repTable} r
                     LEFT JOIN {$userInfoTable} ui ON r.user_id = ui.user_id
                     WHERE {$whereSql}";
        $total = (int) $wpdb->get_var($wpdb->prepare($countSql, ...$whereParams));

        if ($total <= 0) {
            return [];
        }

        // 2) Uniform random offset so the slice wholly fits inside the pool.
        $maxOffset = max(0, $total - $limit);
        $offset    = $maxOffset > 0 ? random_int(0, $maxOffset) : 0;

        // 3) Deterministic order (PK ASC) keeps the LIMIT slice reproducible
        //    for a given offset. An idx_user_id on reputation covers this.
        $sliceSql = "SELECT r.user_id, ui.last_ip_address
                     FROM {$repTable} r
                     LEFT JOIN {$userInfoTable} ui ON r.user_id = ui.user_id
                     WHERE {$whereSql}
                     ORDER BY r.user_id ASC
                     LIMIT %d OFFSET %d";
        $params   = $whereParams;
        $params[] = $limit;
        $params[] = $offset;

        /** @var list<object{user_id: int|numeric-string, last_ip_address: string|null}> $pool */
        $pool = $wpdb->get_results($wpdb->prepare($sliceSql, ...$params)) ?: [];
        return $pool;
    }
}