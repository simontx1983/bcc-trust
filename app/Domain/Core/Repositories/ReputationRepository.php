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

/**
 * Row shape returned by reputation reads. Under Architecture A a member's
 * trust lives on their self-page row in bcc_trust_page_scores (the legacy
 * bcc_trust_reputation table is retired), and the accessors here resolve
 * tier/score from that self-page row. getByUserId() casts the numeric
 * fields before returning, so consumers receive real types — other
 * accessors that return raw $wpdb rows keep string forms.
 *
 * @phpstan-type ReputationRow object{
 *   id: int|numeric-string,
 *   user_id: int|numeric-string,
 *   reputation_score: float,
 *   reputation_tier: string,
 *   contribution_bonus: float,
 *   total_votes_cast: int,
 *   total_votes_received: int,
 *   flag_count: int,
 *   vote_weight: float,
 *   last_calculated_at: string
 * }
 */
class ReputationRepository {

    /**
     * The member self-page score table (Architecture A). All reads resolve a
     * member's tier/score from their self-page row here (page_id =
     * MemberSelfPageService::ID_BASE + user_id, category_id = 0); the retired
     * bcc_trust_reputation table is gone and no method targets it any more.
     */
    private string $scoreTable;

    // Default reputation values
    /** @see BCC_TRUST_NEUTRAL_SCORE in config/scoring.php */
    private const DEFAULT_REPUTATION_SCORE = 50.0; // Must match BCC_TRUST_NEUTRAL_SCORE
    private const DEFAULT_VOTE_WEIGHT = 1.0;

    public function __construct() {
        $this->scoreTable = \BCC\Trust\Core\Database\TableRegistry::scores();
    }

    /**
     * Map a self-page score row to the legacy ReputationRow shape so the
     * ~40 facade callers keep working unchanged after the cutover. The
     * user-centric counters (total_votes_cast, flag_count, vote_weight) are
     * vestigial under Architecture A — total_votes_cast's real source is
     * user_info.votes_cast; the others have no live gate — so they map to
     * harmless defaults. Only reputation_score / reputation_tier /
     * contribution_bonus / total_votes_received are load-bearing.
     *
     * @param object{total_score: float|numeric-string, reputation_tier: string, contribution_bonus: float|numeric-string, vote_count: int|numeric-string, last_calculated_at?: string|null} $row
     * @phpstan-return ReputationRow
     */
    private function mapSelfPageRow(int $userId, object $row): object {
        return (object) [
            'id'                   => \BCC\Trust\Core\Services\MemberSelfPageService::selfPageId($userId),
            'user_id'              => $userId,
            'reputation_score'     => (float) $row->total_score,
            'reputation_tier'      => (string) $row->reputation_tier,
            'contribution_bonus'   => (float) $row->contribution_bonus,
            'total_votes_cast'     => 0,
            'total_votes_received' => (int) $row->vote_count,
            'flag_count'           => 0,
            'vote_weight'          => self::DEFAULT_VOTE_WEIGHT,
            'last_calculated_at'   => (string) ($row->last_calculated_at ?? ''),
        ];
    }

    /**
     * Request-scoped row memo. getTier/getScore all funnel through
     * getByUserId, and list surfaces (member cards) read several of them
     * per row — without a memo a 24-row page re-reads the same rows up to
     * 4× each. Stores misses (null) too. Primed in bulk by primeByUserIds().
     * This facade is read-only (Architecture A — all writes go through
     * ScoreRepository against the self-page row), so there is no in-class
     * write path to invalidate the memo within a request.
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
        $idList = array_keys($wanted);
        // Resolve each user to their deterministic self-page id, querying
        // bcc_trust_page_scores in one bounded IN (Architecture A).
        $pageIds      = array_map(
            static fn(int $uid): int => \BCC\Trust\Core\Services\MemberSelfPageService::selfPageId($uid),
            $idList
        );
        $placeholders = implode(',', array_fill(0, count($pageIds), '%d'));

        /** @var list<object{page_owner_id: int|numeric-string, total_score: float|numeric-string, reputation_tier: string, contribution_bonus: float|numeric-string, vote_count: int|numeric-string, last_calculated_at?: string|null}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT page_owner_id, total_score, reputation_tier, contribution_bonus, vote_count, last_calculated_at
               FROM {$this->scoreTable}
              WHERE page_id IN ({$placeholders}) AND category_id = 0",
            ...$pageIds
        ));

        foreach ($idList as $id) {
            self::$rowMemo[$id] = null;
        }
        foreach ($rows ?: [] as $raw) {
            $uid = (int) $raw->page_owner_id;
            if ($uid > 0) {
                /** @phpstan-var ReputationRow $mapped */
                $mapped = $this->mapSelfPageRow($uid, $raw);
                self::$rowMemo[$uid] = $mapped;
            }
        }
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

        // Architecture A: a member's tier/score lives on their self-page row
        // in bcc_trust_page_scores, not bcc_trust_reputation. Resolve by the
        // deterministic self-page id and map to the legacy ReputationRow shape.
        $pageId = \BCC\Trust\Core\Services\MemberSelfPageService::selfPageId($userId);

        /** @var object{total_score: float|numeric-string, reputation_tier: string, contribution_bonus: float|numeric-string, vote_count: int|numeric-string, last_calculated_at?: string|null}|null $raw */
        $raw = $wpdb->get_row($wpdb->prepare(
            "SELECT page_owner_id, total_score, reputation_tier, contribution_bonus, vote_count, last_calculated_at
               FROM {$this->scoreTable}
              WHERE page_id = %d AND category_id = 0",
            $pageId
        ));

        $row = $raw !== null ? $this->mapSelfPageRow($userId, $raw) : null;

        /** @phpstan-var ReputationRow|null $row */
        self::$rowMemo[$userId] = $row;

        return $row;
    }

    /**
     * Get reputation score for a user
     */
    public function getScore(int $userId): float {
        $record = $this->getByUserId($userId);
        return $record ? (float) $record->reputation_score : self::DEFAULT_REPUTATION_SCORE;
    }

    /**
     * Get the persisted contribution+consistency bonus for a user (the
     * "Trust Recovery Through Contribution" input refreshed by the daily
     * evaluator). Blended into reputation_score by
     * ReputationCalculatorService; 0.0 when no row / never evaluated.
     */
    public function getContributionBonus(int $userId): float {
        $record = $this->getByUserId($userId);
        return $record ? (float) $record->contribution_bonus : 0.0;
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
     * Returns only users with a self-page score row (Architecture A).
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
        $idList  = array_keys($clean);
        // Self-page ids (Architecture A); map page_owner_id back to user.
        $pageIds = array_map(
            static fn(int $uid): int => \BCC\Trust\Core\Services\MemberSelfPageService::selfPageId($uid),
            $idList
        );
        $placeholders = implode(',', array_fill(0, count($pageIds), '%d'));

        global $wpdb;
        /** @var list<object{page_owner_id: int|numeric-string, reputation_tier: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT page_owner_id, reputation_tier
               FROM {$this->scoreTable}
              WHERE page_id IN ({$placeholders}) AND category_id = 0",
            ...$pageIds
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $uid = (int) $row->page_owner_id;
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

        // Architecture A: scan member self-pages (page_id > ID_BASE) only —
        // entity pages can also be caution/risky but are not members.
        /** @var list<object{page_owner_id: int|numeric-string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT page_owner_id
               FROM {$this->scoreTable}
              WHERE category_id = 0
                AND page_id > %d
                AND reputation_tier IN ('caution', 'risky')
              LIMIT %d",
            \BCC\Trust\Core\Services\MemberSelfPageService::ID_BASE,
            $limit
        ));

        $ids = [];
        foreach ($rows ?: [] as $row) {
            $ids[] = (int) $row->page_owner_id;
        }
        return $ids;
    }

    // getVotingStatsForOwner() REMOVED (Architecture A — reputation cutover
    // Stage D). Its sole caller, the now-removed per-user vote-ratio recalc,
    // is gone. A member's vote-driven trust is now the self-page total_score,
    // derived inline by ScoreRepository via the canonical TrustScoreService
    // formula.

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
        $idBase           = \BCC\Trust\Core\Services\MemberSelfPageService::ID_BASE;

        // Common WHERE clause + params, used by both strategies. Architecture A:
        // panelists are members with a trusted/elite SELF-PAGE tier, so filter
        // self-pages (page_id > ID_BASE, category_id = 0) and key on
        // page_owner_id (the member) instead of the retired reputation table.
        $whereSql = "r.reputation_tier IN ({$tierPlaceholders})
                  AND r.page_id > {$idBase}
                  AND r.category_id = 0
                  AND r.page_owner_id > 0
                  AND (ui.fraud_score IS NULL OR ui.fraud_score < %d)
                  AND (ui.is_suspended IS NULL OR ui.is_suspended = 0)";
        $whereParams = array_merge($tiers, [$fraudThreshold]);

        if (!empty($excludeUserIds)) {
            $excludedPlaceholders = implode(',', array_fill(0, count($excludeUserIds), '%d'));
            $whereSql .= " AND r.page_owner_id NOT IN ({$excludedPlaceholders})";
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
                $this->scoreTable,
                $userInfoTable,
                $whereSql,
                $whereParams,
                $limit
            );
        }

        $sql = "SELECT r.page_owner_id AS user_id, ui.last_ip_address
                FROM {$this->scoreTable} r
                LEFT JOIN {$userInfoTable} ui ON r.page_owner_id = ui.user_id
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
                     LEFT JOIN {$userInfoTable} ui ON r.page_owner_id = ui.user_id
                     WHERE {$whereSql}";
        $total = (int) $wpdb->get_var($wpdb->prepare($countSql, ...$whereParams));

        if ($total <= 0) {
            return [];
        }

        // 2) Uniform random offset so the slice wholly fits inside the pool.
        $maxOffset = max(0, $total - $limit);
        $offset    = $maxOffset > 0 ? random_int(0, $maxOffset) : 0;

        // 3) Deterministic order (page_id ASC) keeps the LIMIT slice reproducible
        //    for a given offset (self-page page_id is monotonic in user_id).
        $sliceSql = "SELECT r.page_owner_id AS user_id, ui.last_ip_address
                     FROM {$repTable} r
                     LEFT JOIN {$userInfoTable} ui ON r.page_owner_id = ui.user_id
                     WHERE {$whereSql}
                     ORDER BY r.page_id ASC
                     LIMIT %d OFFSET %d";
        $params   = $whereParams;
        $params[] = $limit;
        $params[] = $offset;

        /** @var list<object{user_id: int|numeric-string, last_ip_address: string|null}> $pool */
        $pool = $wpdb->get_results($wpdb->prepare($sliceSql, ...$params)) ?: [];
        return $pool;
    }
}