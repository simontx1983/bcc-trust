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

    use \BCC\Trust\Onchain\Repositories\GuardsReadFailures;

    /**
     * F-454: the most caution/risky members the excluding reader returns.
     * Far past any realistic size (the old LIMIT was 1000).
     *
     * Reaching it TRUNCATES, most severe first, and alerts; it does not fail
     * closed (security, F-454 review: the list is a shadow-limit quality
     * filter, not a safety gate). Only a read that SUCCEEDED and returned
     * more than this many rows is cut; a read that did not run never is
     * (it serves the last-good copy whole, or throws).
     *   - P2: `caution_list` / `ceiling_reached`, the cut falls among
     *     caution-tier members;
     *   - P1: `caution_list_risky_ceiling` / `risky_fills_ceiling`, the risky
     *     members alone fill it (P1 through {@see withRiskyCeilingAsP1()}).
     *
     * ⚠ NOT A COMFORTABLE OPERATING POINT. bcc-core puts no cap on the
     * `$excludedAuthorIds` it is handed: PeepSoActivityRepository builds
     * `NOT IN (%d × N)` from the whole list. A list anywhere near this size
     * makes every feed query that large. Reaching it means the shadow-limit
     * strategy needs revisiting (a per-page exact check, F-54 style), not
     * a bigger number here.
     */
    public const CAUTION_LIST_CEILING = 20000;

    /** F-454: the P1 subsystem of {@see CAUTION_LIST_CEILING} (bcc-core's map). */
    public const RISKY_CEILING_SUBSYSTEM = 'caution_list_risky_ceiling';

    /**
     * F-454: the last list the excluding reader read successfully, served
     * when a read fails: {ids: list<int>, read_at: float (unix, when that
     * read STARTED), hash}. Autoload no. Not a registered setting and not
     * exposed through REST.
     */
    public const CAUTION_LAST_GOOD_OPTION = 'bcc_caution_list_last_good';

    /**
     * F-454: how old the last-good list may be and still be served, in
     * seconds. 24 h; a site can set BCC_TRUST_CAUTION_LIST_MAX_AGE in
     * wp-config (at least 60).
     */
    public const CAUTION_LAST_GOOD_MAX_AGE = 86400;

    /**
     * F-454: while the list is unchanged, the stored copy is rewritten at
     * most this often, so its `read_at` stays within the max age of the
     * last successful read and the bound measures real staleness.
     */
    private const CAUTION_LAST_GOOD_REFRESH = 3600;

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
    /**
     * The trust score as EVERY surface shows it: getScore() clamped to
     * 0–100 and rounded to an int. One definition, so the profile hero
     * (User/Card `trust_score`) and the feed/comment author block can never
     * show two numbers for one member. Reads the request memo, so callers
     * that primeByUserIds() a page first pay no per-user query.
     */
    public function getDisplayScore(int $userId): int {
        $score = $this->getScore($userId);

        return (int) round(max(0.0, min(100.0, $score)));
    }

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
     * "shadow-limited" set — for a caller that uses it as an INCLUSION set:
     * ContributionRecoveryEvaluator's daily sweep, which re-evaluates these
     * members.
     *
     * ⚠ FAIL-SAFE: a read that did not run answers [] (logged), which for
     * that worker is "nobody this run, retry tomorrow".
     *
     * ⛔ NEVER FOR EXCLUDING. A caller that HIDES these members (the feeds,
     * the permalink, the hot feed, member suggestions, and any future one)
     * must use {@see getCautionAndRiskyUserIdsForExclusion()}, which never
     * answers a failed read with [] (F-454).
     *
     * @return list<int>
     */
    public function getCautionAndRiskyUserIds(int $limit = 1000): array {
        $rows = $this->readCautionAndRisky(max(1, $limit), __FUNCTION__, false);

        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row->page_owner_id ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * F-454: the §O4.1 caution/risky list for a caller that EXCLUDES these
     * members: every per-viewer feed and the permalink (through
     * FeedRankingService::viewerExclusions()), the anonymous hot feed, and
     * member suggestions.
     *
     * - Read in a fixed order, the most severe first: risky before caution,
     *   then the lowest score, then the member id. So the list is the same
     *   on every read, and if the ceiling is ever reached, the members past
     *   it are the least severe.
     * - Up to CAUTION_LIST_CEILING members. One more row is read so that
     *   reaching the ceiling is known; it is recorded (`ceiling_reached`)
     *   and logged at error, and the first CAUTION_LIST_CEILING are used.
     * - A successful read is remembered as the last-good list (an option,
     *   autoload no), rewritten only when the list changes or the stored
     *   copy is over an hour old, so a request does not write.
     * - A read that did not run serves that last-good list while it is no
     *   older than CAUTION_LAST_GOOD_MAX_AGE (logged with its age). With no
     *   list, or one past the bound, it THROWS: the caller answers
     *   "unavailable", never a page without this list.
     *
     * @return list<int>
     * @throws \BCC\Trust\Core\Exceptions\RepositoryException when the list could not be read and no usable last-good list exists
     */
    public function getCautionAndRiskyUserIdsForExclusion(): array {
        $readStartedAt = microtime(true);
        try {
            $rows = $this->readCautionAndRisky(self::CAUTION_LIST_CEILING + 1, __FUNCTION__, true);
        } catch (\BCC\Trust\Onchain\Repositories\RepositoryReadFailure $e) {
            \BCC\Core\Observability\DegradationMetrics::record('caution_list', 'list_read_failed');
            return self::serveCautionLastGood();
        }

        $tierById = [];
        foreach ($rows as $row) {
            $id = (int) ($row->page_owner_id ?? 0);
            if ($id > 0 && !isset($tierById[$id])) {
                $tierById[$id] = (string) ($row->reputation_tier ?? '');
            }
        }
        $ids = array_keys($tierById);

        if (count($ids) > self::CAUTION_LIST_CEILING) {
            $ids = array_slice($ids, 0, self::CAUTION_LIST_CEILING);
            // The read is risky first, so the last member kept is risky only
            // when the risky members alone fill the ceiling.
            $riskyFills = ($tierById[$ids[self::CAUTION_LIST_CEILING - 1]] ?? '') === 'risky';
            \BCC\Core\Observability\DegradationMetrics::record('caution_list', 'ceiling_reached');
            if ($riskyFills) {
                \BCC\Core\Observability\DegradationMetrics::record(self::RISKY_CEILING_SUBSYSTEM, 'risky_fills_ceiling');
            }
            \BCC\Core\Log\Logger::error('[ReputationRepository] caution/risky list ceiling reached; the list is cut, most severe kept', [
                'ceiling'     => self::CAUTION_LIST_CEILING,
                'risky_fills' => $riskyFills,
            ]);
        }

        self::rememberCautionLastGood($ids, $readStartedAt);
        return $ids;
    }

    /**
     * F-454: the `bcc_degradation_p1_subsystems` filter (bcc-core's
     * DegradationAlerter): makes `caution_list_risky_ceiling` page as P1.
     * `caution_list` stays P2. Registered in bcc-trust.php.
     *
     * @param mixed $p1
     * @return list<string>
     */
    public static function withRiskyCeilingAsP1($p1): array {
        $list = is_array($p1) ? array_values(array_filter($p1, 'is_string')) : [];
        if (!in_array(self::RISKY_CEILING_SUBSYSTEM, $list, true)) {
            $list[] = self::RISKY_CEILING_SUBSYSTEM;
        }
        return $list;
    }

    /**
     * The one caution/risky read, most severe first (see
     * getCautionAndRiskyUserIdsForExclusion()). Architecture A: member
     * self-pages only (page_id > ID_BASE); entity pages can be caution/risky
     * too but are not members.
     *
     * @return list<object>
     * @throws \BCC\Trust\Onchain\Repositories\RepositoryReadFailure when $failClosed and the read did not run
     */
    private function readCautionAndRisky(int $limit, string $method, bool $failClosed): array {
        global $wpdb;

        $sql = $wpdb->prepare(
            "SELECT page_owner_id, reputation_tier
               FROM {$this->scoreTable}
              WHERE category_id = 0
                AND page_id > %d
                AND reputation_tier IN ('caution', 'risky')
              ORDER BY FIELD(reputation_tier, 'risky', 'caution'), total_score ASC, page_owner_id ASC
              LIMIT %d",
            \BCC\Trust\Core\Services\MemberSelfPageService::ID_BASE,
            $limit
        );
        if (!is_string($sql)) {
            if ($failClosed) {
                throw new \BCC\Trust\Onchain\Repositories\RepositoryReadFailure(static::class, $method, 'prepare() returned no query');
            }
            return [];
        }

        return self::runRead($sql, $method, $failClosed);
    }

    /**
     * F-454: persist a successful read as the last-good list. Written only
     * when it differs from the stored one, or the stored one is over
     * CAUTION_LAST_GOOD_REFRESH old (so `read_at` keeps measuring the last
     * successful read), and never over a list read later than this one.
     *
     * @param list<int> $ids
     */
    private static function rememberCautionLastGood(array $ids, float $readStartedAt): void {
        $hash   = md5(implode(',', $ids));
        $stored = get_option(self::CAUTION_LAST_GOOD_OPTION, null);
        if (is_array($stored)) {
            $storedAt = (float) ($stored['read_at'] ?? 0);
            if ($storedAt >= $readStartedAt) {
                return; // a newer read already landed
            }
            if (($stored['hash'] ?? '') === $hash && $readStartedAt - $storedAt < self::CAUTION_LAST_GOOD_REFRESH) {
                return; // unchanged and recent: no write
            }
        }
        update_option(
            self::CAUTION_LAST_GOOD_OPTION,
            ['ids' => $ids, 'read_at' => $readStartedAt, 'hash' => $hash],
            false
        );
    }

    /**
     * F-454: a failed read. Serve the last-good list while it is within the
     * max age, else throw.
     *
     * @return list<int>
     * @throws \BCC\Trust\Core\Exceptions\RepositoryException
     */
    private static function serveCautionLastGood(): array {
        $stored = get_option(self::CAUTION_LAST_GOOD_OPTION, null);
        if (!is_array($stored) || !isset($stored['ids']) || !is_array($stored['ids'])) {
            \BCC\Core\Observability\DegradationMetrics::record('caution_list', 'no_last_good');
            \BCC\Core\Log\Logger::error('[ReputationRepository] caution/risky list unreadable and no last-good list exists', []);
            throw new \BCC\Trust\Core\Exceptions\RepositoryException(
                'Caution/risky list unreadable and no last-known-good list exists.'
            );
        }

        $ageSeconds = max(0, (int) round(microtime(true) - (float) ($stored['read_at'] ?? 0)));
        $maxAge     = defined('BCC_TRUST_CAUTION_LIST_MAX_AGE')
            ? max(60, (int) constant('BCC_TRUST_CAUTION_LIST_MAX_AGE'))
            : self::CAUTION_LAST_GOOD_MAX_AGE;
        if ($ageSeconds > $maxAge) {
            \BCC\Core\Observability\DegradationMetrics::record('caution_list', 'last_good_expired');
            \BCC\Core\Log\Logger::error('[ReputationRepository] caution/risky list unreadable and the last-good list is past its max age', [
                'last_good_age_seconds' => $ageSeconds,
                'max_age_seconds'       => $maxAge,
            ]);
            throw new \BCC\Trust\Core\Exceptions\RepositoryException(
                'Caution/risky list unreadable and the last-known-good list is past its max age.'
            );
        }

        \BCC\Core\Observability\DegradationMetrics::record('caution_list', 'served_last_good');
        \BCC\Core\Log\Logger::error('[ReputationRepository] caution/risky list read failed; served the last-good list', [
            'last_good_age_seconds' => $ageSeconds,
            'last_good_count'       => count($stored['ids']),
        ]);

        $ids = [];
        foreach ($stored['ids'] as $raw) {
            if (is_numeric($raw) && (int) $raw > 0) {
                $ids[] = (int) $raw;
            }
        }
        return $ids;
    }

    // getVotingStatsForOwner() REMOVED (Architecture A — reputation cutover
    // Stage D). Its sole caller, the now-removed per-user vote-ratio recalc,
    // is gone. A member's vote-driven trust is now the self-page total_score,
    // derived inline by ScoreRepository via the canonical TrustScoreService
    // formula.

    // getEligiblePanelists() + selectByRandomOffset() deleted (Rank
    // Phase 6, D-7): panelist selection retired with the five-member
    // panel — dispute voting is open to all eligible members via the
    // poll engine (DisputeVoteService::assertEligible).
}