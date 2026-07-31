<?php
/**
 * Vote Repository
 *
 * Handles database operations for votes.
 * Uses config constants for thresholds and limits.
 *
 * @package BCC\Trust\Core\Repositories
 * @version 2.2.0
 */

namespace BCC\Trust\Core\Repositories;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Exception;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Security\TransactionManager;

/**
 * Row shape returned by bcc_trust_votes reads. Numeric columns are returned
 * by $wpdb as string|null; the union with the native numeric type lets
 * downstream code continue to rely on the existing loose-compare / cast
 * behaviour without introducing new type errors.
 *
 * @phpstan-type VoteRow object{
 *   id: int|numeric-string,
 *   voter_user_id: int|numeric-string,
 *   page_id: int|numeric-string,
 *   category_id: int|numeric-string,
 *   vote_type: int|numeric-string,
 *   weight: float|numeric-string,
 *   vested_weight: float|numeric-string,
 *   fraud_score_at_vote: int|numeric-string|null,
 *   vesting_stage: int|numeric-string,
 *   vesting_started_at: string|null,
 *   fully_vested_at: string|null,
 *   weight_corrected_at: string|null,
 *   reason: string|null,
 *   explanation: string|null,
 *   status: int|numeric-string,
 *   ip_address: string|null,
 *   created_at: string,
 *   updated_at: string
 * }
 * @phpstan-type VoteAggregateRow object{
 *   positive_score: float|numeric-string,
 *   negative_score: float|numeric-string,
 *   vote_count: int|numeric-string,
 *   unique_voters: int|numeric-string,
 *   mature_unique_voters: int|numeric-string,
 *   last_vote_at: string|null
 * }
 * @phpstan-type VotePageStatsRow object{
 *   total_votes: int|numeric-string,
 *   total_positive_weight: float|numeric-string|null,
 *   total_negative_weight: float|numeric-string|null,
 *   unique_voters: int|numeric-string,
 *   last_vote_at: string|null,
 *   avg_upvote_weight: float|numeric-string|null,
 *   avg_downvote_weight: float|numeric-string|null
 * }
 * @phpstan-type VoteTypeStatsRow object{
 *   positive: int|numeric-string|null,
 *   negative: int|numeric-string|null,
 *   total: int|numeric-string
 * }
 * @phpstan-type MutualVotePair object{
 *   user_a: int|numeric-string,
 *   user_b: int|numeric-string,
 *   mutual_count: int|numeric-string,
 *   total_weight: float|numeric-string
 * }
 * @phpstan-type UserVoteWithContext object{
 *   id: int|numeric-string,
 *   voter_user_id: int|numeric-string,
 *   page_id: int|numeric-string,
 *   category_id: int|numeric-string,
 *   vote_type: int|numeric-string,
 *   weight: float|numeric-string,
 *   vested_weight: float|numeric-string,
 *   fraud_score_at_vote: int|numeric-string|null,
 *   vesting_stage: int|numeric-string,
 *   vesting_started_at: string|null,
 *   fully_vested_at: string|null,
 *   weight_corrected_at: string|null,
 *   reason: string|null,
 *   explanation: string|null,
 *   status: int|numeric-string,
 *   ip_address: string|null,
 *   created_at: string,
 *   updated_at: string,
 *   fraud_score: int|numeric-string|null,
 *   risk_level: string|null,
 *   automation_score: int|numeric-string|null,
 *   voter_tier: string|null
 * }
 * @phpstan-type VoterWeightRow object{
 *   voter_user_id: int|numeric-string,
 *   weight: float|numeric-string,
 *   fraud_score: int|numeric-string|null,
 *   reputation_tier: string|null,
 *   trust_rank: float|numeric-string|null
 * }
 * @phpstan-type VoteByVoterRow object{
 *   id: int|numeric-string,
 *   voter_user_id: int|numeric-string,
 *   page_id: int|numeric-string,
 *   category_id: int|numeric-string,
 *   vote_type: int|numeric-string,
 *   weight: float|numeric-string,
 *   vested_weight: float|numeric-string,
 *   fraud_score_at_vote: int|numeric-string|null,
 *   vesting_stage: int|numeric-string,
 *   vesting_started_at: string|null,
 *   fully_vested_at: string|null,
 *   weight_corrected_at: string|null,
 *   reason: string|null,
 *   explanation: string|null,
 *   status: int|numeric-string,
 *   ip_address: string|null,
 *   created_at: string,
 *   updated_at: string,
 *   page_title: string|null,
 *   page_score: float|numeric-string|null,
 *   page_tier: string|null
 * }
 * @phpstan-type VoteDistributionByTierRow object{
 *   reputation_tier: string|null,
 *   vote_count: int|numeric-string,
 *   total_weight: float|numeric-string,
 *   avg_weight: float|numeric-string
 * }
 * @phpstan-type VoteSummaryRow object{
 *   total_votes: int|numeric-string,
 *   unique_pages: int|numeric-string,
 *   unique_voters: int|numeric-string,
 *   upvotes: int|numeric-string,
 *   downvotes: int|numeric-string,
 *   total_weight: float|numeric-string|null,
 *   avg_weight: float|numeric-string|null,
 *   last_vote_at: string|null,
 *   config?: array<string, mixed>
 * }
 */
class VoteRepository {

    /** Explicit column list for bcc_trust_votes table. */
    private const COLUMNS = 'id, voter_user_id, page_id, category_id, vote_type, weight, vested_weight, fraud_score_at_vote, vesting_stage, vesting_started_at, fully_vested_at, weight_corrected_at, reason, explanation, status, ip_address, created_at, updated_at';

    private const CACHE_GROUP = 'bcc_votes';
    private const CACHE_TTL   = 60; // seconds

    private string $table;

    /**
     * Config thresholds
     */
    private float $minVoteWeight;
    private float $maxVoteWeight;
    private float $defaultVoteWeight;
    private int   $decayDays;
    private float $decayMin;
    private int   $fraudMedium;
    private float $fraudFloor;
    private int   $cleanupDays;

    public function __construct() {
        $this->table             = \BCC\Trust\Core\Database\TableRegistry::votes();

        $this->minVoteWeight     = BCC_TRUST_MIN_VOTE_WEIGHT;
        $this->maxVoteWeight     = BCC_TRUST_MAX_VOTE_WEIGHT;
        $this->defaultVoteWeight = BCC_TRUST_WEIGHT_NEUTRAL;
        $this->decayDays         = BCC_TRUST_DECAY_DAYS;
        $this->decayMin          = BCC_TRUST_DECAY_MIN;
        $this->fraudMedium       = BCC_TRUST_FRAUD_MEDIUM;
        $this->fraudFloor        = BCC_TRUST_RETROACTIVE_FRAUD_FLOOR;
        $this->cleanupDays       = BCC_TRUST_CLEANUP_VOTES;
    }

    // -------------------------------------------------------------------------
    // Read methods
    // -------------------------------------------------------------------------

    /**
     * Get vote by voter, page, and category.
     * Pass $categoryId = null to match any category (legacy callers).
     *
     * @phpstan-return VoteRow|null
     */
    public function get( int $voterId, int $pageId, ?int $categoryId = null ): ?object {
        global $wpdb;

        if ( $categoryId !== null ) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT " . self::COLUMNS . " FROM {$this->table}
                     WHERE voter_user_id = %d
                       AND page_id       = %d
                       AND category_id   = %d
                       AND status        = 1",
                    $voterId,
                    $pageId,
                    $categoryId
                )
            );
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table}
                 WHERE voter_user_id = %d
                   AND page_id       = %d
                   AND status        = 1
                 LIMIT 1",
                $voterId,
                $pageId
            )
        );
    }

    /**
     * Get vote by ID.
     *
     * @phpstan-return VoteRow|null
     */
    public function getById( int $voteId ): ?object {
        $cacheKey = "vote_{$voteId}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached ?: null;
        }

        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table} WHERE id = %d",
                $voteId
            )
        );

        wp_cache_set($cacheKey, $row ?: '', self::CACHE_GROUP, self::CACHE_TTL);
        return $row;
    }

    /**
     * Lock the vote row FOR UPDATE and report whether it is still active.
     *
     * IMPORTANT: uses SELECT … FOR UPDATE and therefore MUST be called from
     * inside the caller's open TransactionManager::run() closure on the
     * shared $wpdb connection; the row lock is released on that
     * transaction's COMMIT/ROLLBACK. Prevents the trust engine from
     * soft-deleting the vote between dispute-create validation and the
     * dispute INSERT.
     *
     * Bounded by the id primary key (LIMIT 1). LIMIT MUST precede FOR UPDATE
     * in MySQL — the reversed order is a syntax error that $wpdb swallows
     * (get_var → null), which made every dispute creation fail as
     * "vote_no_longer_active".
     */
    public function lockActiveForDispute(int $voteId): bool
    {
        // Runtime guard: SELECT … FOR UPDATE below requires an active transaction.
        if (!TransactionManager::isInRunTransaction()) {
            throw new Exception(
                'VoteRepository::lockActiveForDispute() must be called inside TransactionManager::run(). '
                . 'SELECT … FOR UPDATE without a transaction will not lock correctly.'
            );
        }

        if ($voteId <= 0) {
            return false;
        }

        global $wpdb;
        $locked = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE id = %d AND status = 1 LIMIT 1 FOR UPDATE",
            $voteId
        ));

        return $locked !== null;
    }

    /**
     * Bulk-fetch vote rows by primary key. Used by FeedRankingService
     * to hydrate review post bodies for an entire feed page in one
     * round trip (vs N+1).
     *
     * Cache is intentionally bypassed — feed-page hydration is rare
     * enough that the per-vote cache (60s TTL) doesn't help, and the
     * IN-list query is already a single round trip. Bounded by the
     * feed page cap (50 items at a time).
     *
     * @param list<int> $voteIds
     * @return array<int, object> vote_id → row
     */
    public function findManyByIds(array $voteIds): array
    {
        if ($voteIds === []) {
            return [];
        }

        // Sanitize + dedupe + bound. Defensive in case a future caller
        // passes an unbounded list.
        $ids = [];
        foreach ($voteIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $idList = implode(',', array_keys($ids));

        global $wpdb;
        /** @var list<object>|null $rows */
        $rows = $wpdb->get_results(
            "SELECT " . self::COLUMNS . " FROM {$this->table}
              WHERE id IN ({$idList})"
        );

        $out = [];
        foreach ($rows ?: [] as $row) {
            $rowId = (int) ($row->id ?? 0);
            if ($rowId > 0) {
                $out[$rowId] = $row;
            }
        }
        return $out;
    }

    /**
     * Batch-check which of the given pages this voter has an active
     * vote on. Used by PageCardPrefetcher so the cards-list path can
     * resolve `viewer_has_reviewed` for a whole page of cards in one
     * round trip instead of N per-card `get()` calls.
     *
     * Replicates the exact predicate `get($voterId, $pageId)` uses on
     * the null-category path (voter + page + status = 1) — kept in
     * lockstep so the batch and single-card answers can never diverge.
     *
     * Bounded: the IN-list is caller-paginated (cards per_page ≤ 50);
     * DISTINCT caps the result at one row per page.
     *
     * @param list<int> $pageIds
     * @return array<int, true> Set keyed by page_id for O(1) lookup.
     */
    public function getVotedPageIdsForVoter(int $voterId, array $pageIds): array
    {
        if ($voterId <= 0 || $pageIds === []) {
            return [];
        }

        // Sanitize + dedupe — same defensive posture as findManyByIds.
        $ids = [];
        foreach ($pageIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $idList = implode(',', array_keys($ids));

        global $wpdb;
        /** @var list<object{page_id: int|numeric-string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT page_id FROM {$this->table}
              WHERE voter_user_id = %d
                AND page_id IN ({$idList})
                AND status = 1",
            $voterId
        ));

        $out = [];
        foreach ($rows ?: [] as $row) {
            $pageId = (int) $row->page_id;
            if ($pageId > 0) {
                $out[$pageId] = true;
            }
        }
        return $out;
    }

    /**
     * Set the long-form `explanation` body on a single vote row.
     *
     * Per §D2 reviews this is the persistence path for the review's
     * paragraph-of-text — VoteService's castPageVote / VoteWriter
     * write the gated trust signal (vote_type + weight + reason)
     * inside a security-critical transaction, then PostsService
     * follows up with this method to attach the long-form body.
     *
     * Two-step write rationale: keeping castPageVote untouched
     * preserves its fraud / fan-in / sybil / idempotency invariants.
     * If this update fails, the vote still stands as a degraded
     * signal (a bare upvote/downvote) — recoverable from the UI.
     *
     * Returns the number of rows affected (0 = vote_id not found).
     */
    public function setExplanation(int $voteId, string $explanation): int
    {
        if ($voteId <= 0) {
            return 0;
        }

        global $wpdb;
        $rows = $wpdb->update(
            $this->table,
            ['explanation' => $explanation],
            ['id' => $voteId],
            ['%s'],
            ['%d']
        );

        // Bust the per-vote cache so subsequent getById reflects the
        // new body without waiting for the 60s TTL.
        wp_cache_delete("vote_{$voteId}", self::CACHE_GROUP);

        return is_int($rows) ? $rows : 0;
    }

    // lockForUpdate() removed — dead code. lockAllForUpdate() is the live method.

    /**
     * Acquire row-level locks on ALL active votes for a voter+page pair.
     *
     * Used by removePageVote() to lock every category row before reversing
     * score deltas. Returns an empty array when no active rows exist.
     *
     * @return object[]
     * @phpstan-return list<VoteRow>
     */
    public function lockAllForUpdate( int $voterId, int $pageId ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table}
                 WHERE voter_user_id = %d
                   AND page_id       = %d
                   AND status        = 1
                 FOR UPDATE",
                $voterId,
                $pageId
            )
        ) ?: [];
    }

    /**
     * Acquire a row-level lock on a SINGLE active vote by id.
     *
     * Companion to lockAllForUpdate() for row-scoped dispute acceptance —
     * used when the dispute policy is "delete only the disputed row" rather
     * than "delete all votes by this user on this page". Returns the row as
     * a single-element array (same shape as lockAllForUpdate) or [] when
     * the row is missing / already removed.
     *
     * @return object[]
     * @phpstan-return list<VoteRow>
     */
    public function lockOneForUpdate(int $voteId): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table}
                 WHERE id     = %d
                   AND status = 1
                 FOR UPDATE",
                $voteId
            )
        ) ?: [];
    }

    /**
     * Count ACTIVE vote rows for a voter+page pair, OPTIONALLY excluding an id.
     *
     * Used by the row-scoped dispute-accept path to determine whether the
     * just-removed vote was this voter's LAST active vote on the page —
     * which tells scoreRepository::reverseVoteDelta whether to decrement
     * unique_voters. Counting inside the same open transaction as the
     * UPDATE is safe because the row-lock serialises concurrent writes.
     *
     * @param int $voterId
     * @param int $pageId
     * @param int $excludeVoteId   Vote id to exclude (0 to count all)
     */
    public function countActiveForVoterOnPage(int $voterId, int $pageId, int $excludeVoteId = 0): int {
        global $wpdb;

        if ($excludeVoteId > 0) {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                 WHERE voter_user_id = %d
                   AND page_id       = %d
                   AND status        = 1
                   AND id           <> %d",
                $voterId,
                $pageId,
                $excludeVoteId
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                 WHERE voter_user_id = %d
                   AND page_id       = %d
                   AND status        = 1",
                $voterId,
                $pageId
            );
        }

        return (int) $wpdb->get_var($sql);
    }

    // -------------------------------------------------------------------------
    // Write methods
    // -------------------------------------------------------------------------

    // upsert() REMOVED — wraps its own TransactionManager::run(), which incorrectly
    // nests transactions when called from VoteWriter. All production callers use
    // upsertRaw() directly inside the VoteWriter transaction.

    /**
     * Insert or update a vote row assuming the caller already holds an open
     * database transaction. Skips data validation — callers must normalise
     * the data array before calling this method.
     *
     * Use this from VoteWriter, which wraps the entire write stage (vote +
     * score) in a single TransactionManager::run() call. Calling the standard
     * upsert() from inside an existing transaction would open a nested
     * START TRANSACTION, silently committing the outer transaction and
     * breaking atomicity with the score update.
     *
     * @param array<string, mixed> $data
     * @return array{vote_id:int,was_inserted:bool,vote_type_changed:bool,weight_changed:bool,previous_vote_type:int|null,previous_weight:float}
     */
    public function upsertRaw( array $data ): array {
        // Runtime assertion: this method's FOR UPDATE lock is only
        // meaningful inside an outer transaction. In autocommit mode the
        // row lock releases immediately, re-opening the double-insert
        // race the method's docblock warns about. The in-file comment at
        // line 362 is the only prior enforcement; this guard is the
        // belt-and-braces.
        if ( ! \BCC\Trust\Core\Security\TransactionManager::isInRunTransaction() ) {
            throw new \LogicException(
                'VoteRepository::upsertRaw() must be called inside TransactionManager::run(). '
                . 'Its FOR UPDATE lock is a no-op under autocommit.'
            );
        }

        if ( empty( $data['voter_user_id'] ) || empty( $data['page_id'] ) || ! isset( $data['vote_type'] ) ) {
            throw new Exception( 'Missing required vote data' );
        }

        if ( ! in_array( (int) $data['vote_type'], [ 1, -1 ], true ) ) {
            throw new Exception( 'Invalid vote type. Must be 1 or -1' );
        }

        $now      = current_time( 'mysql' );
        $voteData = $this->normaliseVoteData( $data );

        return $this->executeUpsert( $voteData, $now );
    }

    /**
     * Soft-delete a vote row (sets status = 0).
     *
     * Edge recalculation and stats updates are intentionally NOT performed
     * here. The caller (VoteService::removePageVote) dispatches async jobs via
     * VoteJobDispatcher::dispatchRemoval() after the transaction commits.
     *
     * @throws RepositoryException on database failure
     */
    public function delete( int $voterId, int $pageId ): void {
        global $wpdb;

        // Collect affected vote ids BEFORE the UPDATE so we can bust the
        // per-row cache; otherwise getById() returns stale status=1 rows
        // until CACHE_TTL expires.
        $affectedIds = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table}
              WHERE voter_user_id = %d AND page_id = %d AND status = 1",
            $voterId,
            $pageId
        ));

        // CRITICAL: scope UPDATE to status=1 so dispute-removed rows (status=2)
        // are not silently reverted to status=0 and their audit trail lost.
        $result = $wpdb->update(
            $this->table,
            [
                'status'     => 0,
                'updated_at' => current_time( 'mysql' ),
            ],
            [
                'voter_user_id' => $voterId,
                'page_id'       => $pageId,
                'status'        => 1,
            ],
            [ '%d', '%s' ],
            [ '%d', '%d', '%d' ]
        );

        if ( $result === false ) {
            throw new RepositoryException( 'VoteRepository::delete failed: ' . $wpdb->last_error );
        }

        foreach ($affectedIds as $id) {
            $this->bustVoteCache((int) $id);
        }
    }

    /**
     * Soft-delete a vote as removed by dispute (sets status = 2).
     *
     * Uses a distinct status from regular soft-delete (status = 0) so that
     * dispute-removed votes can be identified and reversed independently.
     *   status 0 = user/admin removed
     *   status 1 = active
     *   status 2 = removed by dispute
     *
     * @throws RepositoryException on database failure
     */
    public function deleteByDispute( int $voterId, int $pageId ): void {
        global $wpdb;

        // Audit MED-3: require caller to own a run() transaction.  The
        // adjudicator must wrap delete + score reversal atomically; a
        // crash between the two leaves vote-count in the read model out
        // of sync with the scored state until the next recalc cron tick.
        if ( ! \BCC\Trust\Core\Security\TransactionManager::isInRunTransaction() ) {
            throw new \LogicException(
                'VoteRepository::deleteByDispute must be called inside TransactionManager::run()'
            );
        }

        // Collect affected ids first so we can bust their per-row cache
        // after the UPDATE (getById() would otherwise return status=1 rows
        // for up to CACHE_TTL, misleading the dispute adjudicator).
        $affectedIds = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$this->table}
              WHERE voter_user_id = %d AND page_id = %d AND status = 1",
            $voterId,
            $pageId
        ));

        $result = $wpdb->update(
            $this->table,
            [
                'status'     => 2,
                'updated_at' => current_time( 'mysql' ),
            ],
            [
                'voter_user_id' => $voterId,
                'page_id'       => $pageId,
                'status'        => 1,
            ],
            [ '%d', '%s' ],
            [ '%d', '%d', '%d' ]
        );

        if ( $result === false ) {
            throw new RepositoryException( 'VoteRepository::deleteByDispute failed: ' . $wpdb->last_error );
        }

        foreach ($affectedIds as $id) {
            $this->bustVoteCache((int) $id);
        }
    }

    /**
     * Soft-delete a SINGLE vote row as removed by dispute (status = 2).
     *
     * Companion to deleteByDispute() for row-scoped dispute acceptance.
     * Used when the dispute policy is "only the specific disputed vote row
     * is removed" — other active votes by the same voter on the same page
     * (different categories) are preserved.
     *
     * Returns the number of rows updated (0 or 1). A 0 return means the
     * row was already removed or never existed as status=1 — callers should
     * treat it as a race / idempotent no-op, not an error.
     *
     * @throws RepositoryException on database failure
     */
    public function deleteOneByDispute(int $voteId): int {
        global $wpdb;

        // Audit MED-3: require caller to own a run() transaction (same
        // reason as deleteByDispute).
        if ( ! \BCC\Trust\Core\Security\TransactionManager::isInRunTransaction() ) {
            throw new \LogicException(
                'VoteRepository::deleteOneByDispute must be called inside TransactionManager::run()'
            );
        }

        $result = $wpdb->update(
            $this->table,
            [
                'status'     => 2,
                'updated_at' => current_time( 'mysql' ),
            ],
            [
                'id'     => $voteId,
                'status' => 1,
            ],
            [ '%d', '%s' ],
            [ '%d', '%d' ]
        );

        if ( $result === false ) {
            throw new RepositoryException( 'VoteRepository::deleteOneByDispute failed: ' . $wpdb->last_error );
        }

        if ( $result > 0 ) {
            $this->bustVoteCache( $voteId );
        }

        return (int) $result;
    }

    // -------------------------------------------------------------------------
    // Query methods
    // -------------------------------------------------------------------------

    /**
     * Count active votes for a page.
     *
     * @param int $pageId
     */
    public function countActiveForPage( int $pageId ): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE page_id = %d AND status = 1",
                $pageId
            )
        );
    }

    /**
     * Get all active votes for a page.
     *
     * @return object[]
     * @phpstan-return list<object{
     *   id: int|numeric-string,
     *   voter_user_id: int|numeric-string,
     *   page_id: int|numeric-string,
     *   category_id: int|numeric-string,
     *   vote_type: int|numeric-string,
     *   weight: float|numeric-string,
     *   fraud_score_at_vote: int|numeric-string|null,
     *   vesting_stage: int|numeric-string,
     *   vesting_started_at: string|null,
     *   reason: string|null,
     *   status: int|numeric-string,
     *   created_at: string,
     *   voter_name: string|null,
     *   fraud_score: int|numeric-string|null,
     *   risk_level: string|null,
     *   automation_score: int|numeric-string|null,
     *   voter_is_verified: int|numeric-string|null,
     *   voter_behavior_score: int|numeric-string|null,
     *   voter_trust_rank: float|numeric-string|null,
     *   voter_reputation_tier: string|null
     * }>
     */
    public function getAllForPage( int $pageId, int $limit = 500, int $offset = 0 ): array {
        global $wpdb;

        // Architecture A: the voter's tier lives on their self-page score row
        // (page_id = ID_BASE + voter_user_id, category_id = 0), not the retired
        // bcc_trust_reputation table.
        $scoreTable = \BCC\Trust\Core\Database\TableRegistry::scores();
        $idBase     = \BCC\Trust\Core\Services\MemberSelfPageService::ID_BASE;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.id, v.voter_user_id, v.page_id, v.category_id,
                        v.vote_type, v.weight, v.fraud_score_at_vote,
                        v.vesting_stage, v.vesting_started_at, v.reason,
                        v.status, v.created_at,
                        u.display_name as voter_name,
                        ui.fraud_score, ui.risk_level, ui.automation_score,
                        ui.is_verified as voter_is_verified,
                        ui.behavior_score as voter_behavior_score,
                        ui.trust_rank as voter_trust_rank,
                        r.reputation_tier as voter_reputation_tier
                 FROM {$this->table} v
                 LEFT JOIN {$wpdb->users} u  ON v.voter_user_id = u.ID
                 LEFT JOIN " . \BCC\Trust\Core\Database\TableRegistry::userInfo() . " ui ON v.voter_user_id = ui.user_id
                 LEFT JOIN {$scoreTable} r ON r.page_id = ({$idBase} + v.voter_user_id) AND r.category_id = 0
                 WHERE v.page_id = %d
                   AND v.status  = 1
                 ORDER BY v.created_at DESC
                 LIMIT %d OFFSET %d",
                $pageId,
                $limit,
                $offset
            )
        );
    }

    /**
     * Aggregate vote scores for a page using SQL.
     *
     * Computes positive_score, negative_score, vote_count, unique_voters,
     * and last_vote_at in a single query — no per-row PHP loop required.
     *
     * The effective weight per vote is:
     *   stored_weight × retroactive_fraud_discount × time_decay
     *
     * Fraud discount mirrors FraudDiscountCalculator::retroactiveFactor():
     *   - No discount if current fraud_score <= FRAUD_MEDIUM
     *   - Otherwise: incremental discount = current_factor / original_factor
     *     where factor = GREATEST(BCC_TRUST_RETROACTIVE_FRAUD_FLOOR, 1 - fraud_score / 100)
     *   - Only applied when fraud has INCREASED since vote time
     *
     * Time decay mirrors VoteRepository::applyTimeDecay():
     *   - Linear decay over decayDays, floored at decayMin
     *   - Uses TIMESTAMPDIFF(SECOND, …) / 86400 for fractional days
     *
     * @param int $pageId  Page to aggregate votes for.
     * @return object      Always returns a row with positive_score, negative_score,
     *                     vote_count, unique_voters, mature_unique_voters,
     *                     last_vote_at. vote_count = 0 when no active votes exist.
     *                     mature_unique_voters counts only voters whose
     *                     wp_users.user_registered is at least
     *                     bcc_trust_confidence_min_voter_age_days old (default 7).
     *                     Used by calculateConfidenceScore's diversity term so
     *                     24-hour-old sockpuppets cannot inflate confidence tier.
     *
     * @phpstan-return VoteAggregateRow
     */
    public function getVoteAggregatesForPage( int $pageId ): object {
        global $wpdb;

        $userInfoTable = \BCC\Trust\Core\Database\TableRegistry::userInfo();
        $fraudMedium   = $this->fraudMedium;
        $fraudFloor    = $this->fraudFloor;
        $decayDays     = $this->decayDays;
        $decayMin      = $this->decayMin;

        /** @var int $minVoterAgeDays */
        $minVoterAgeDays = (int) apply_filters('bcc_trust_confidence_min_voter_age_days', 7);
        if ($minVoterAgeDays < 0) {
            $minVoterAgeDays = 0;
        }

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $result = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN d.vote_type > 0  THEN d.eff_weight ELSE 0 END), 0) AS positive_score,
                COALESCE(SUM(CASE WHEN d.vote_type <= 0 THEN d.eff_weight ELSE 0 END), 0) AS negative_score,
                COUNT(*)                   AS vote_count,
                COUNT(DISTINCT d.voter_id) AS unique_voters,
                COUNT(DISTINCT CASE WHEN d.is_mature = 1 THEN d.voter_id END) AS mature_unique_voters,
                MAX(d.created_at)          AS last_vote_at
             FROM (
                SELECT
                    v.vote_type,
                    v.voter_user_id AS voter_id,
                    v.created_at,
                    /* ── voter maturity for confidence-diversity term ──
                     * Fresh accounts (age < N days) are excluded from the
                     * mature_unique_voters distinct count. user_registered is
                     * in site-local time (same as NOW()); matching frames
                     * avoids timezone drift in the comparison.
                     */
                    CASE
                        WHEN u.user_registered IS NOT NULL
                         AND u.user_registered <= DATE_SUB(NOW(), INTERVAL %d DAY)
                          THEN 1
                        ELSE 0
                    END AS is_mature,
                    /* ── vesting-aware weight ──
                     * Use vested_weight when populated (graduation cron
                     * keeps it current with the voter's vesting stage).
                     * Fall back to weight on legacy rows where vested_weight
                     * was never written. Without this, fresh voters' stage-0
                     * 10% votes were summed at full effective weight,
                     * nullifying the documented Sybil-mitigation vesting.
                     */
                    COALESCE(NULLIF(v.vested_weight, 0), v.weight)
                    /* ── retroactive fraud discount ──
                     *
                     * SAFETY CONTRACT: This SQL uses a simplified fraud_score-only
                     * model. The full multi-signal fraud logic (automation, device,
                     * behavior, multi-account) exists only in PHP — see
                     * FraudDiscountCalculator::compute().
                     *
                     * To keep both paths aligned, ALL fraud signals MUST ultimately
                     * update the user_info.fraud_score column. If a signal penalizes
                     * in PHP but doesn't flow into fraud_score, historical votes will
                     * be under-discounted during recalculation.
                     *
                     * Floor (%f) = BCC_TRUST_RETROACTIVE_FRAUD_FLOOR — must match PHP.
                     * @see FraudDiscountCalculator::retroactiveFactor() — PHP equivalent
                     */
                    * CASE
                        WHEN COALESCE(ui.fraud_score, 0) <= %d
                          THEN 1.0
                        WHEN GREATEST(%f, 1.0 - COALESCE(ui.fraud_score, 0) / 100.0)
                             >= IF(COALESCE(v.fraud_score_at_vote, 0) > %d,
                                   GREATEST(%f, 1.0 - COALESCE(v.fraud_score_at_vote, 0) / 100.0),
                                   1.0)
                          THEN 1.0
                        ELSE GREATEST(%f, 1.0 - COALESCE(ui.fraud_score, 0) / 100.0)
                             / IF(COALESCE(v.fraud_score_at_vote, 0) > %d,
                                  GREATEST(%f, 1.0 - COALESCE(v.fraud_score_at_vote, 0) / 100.0),
                                  1.0)
                      END
                    /* ── time decay ── */
                    * CASE
                        WHEN TIMESTAMPDIFF(SECOND, v.created_at, UTC_TIMESTAMP()) / 86400.0 > %d
                          THEN %f
                        ELSE GREATEST(%f, 1.0 - TIMESTAMPDIFF(SECOND, v.created_at, UTC_TIMESTAMP()) / (86400.0 * %d))
                      END
                    AS eff_weight
                FROM {$this->table} v
                LEFT JOIN {$userInfoTable} ui ON v.voter_user_id = ui.user_id
                LEFT JOIN {$wpdb->users} u ON v.voter_user_id = u.ID
                WHERE v.page_id = %d
                  AND v.status  = 1
             ) d",
            $minVoterAgeDays, // maturity: INTERVAL %d DAY for user_registered cutoff
            $fraudMedium,   // fraud: current <= threshold → no discount
            $fraudFloor,    // fraud: GREATEST floor (current factor)
            $fraudMedium,   // fraud: original > threshold (>= branch)
            $fraudFloor,    // fraud: GREATEST floor (original factor, >= branch)
            $fraudFloor,    // fraud: GREATEST floor (current factor, ELSE branch)
            $fraudMedium,   // fraud: original > threshold (ELSE branch)
            $fraudFloor,    // fraud: GREATEST floor (original factor, ELSE branch)
            $decayDays,     // decay: beyond threshold → min
            $decayMin,      // decay: min factor (beyond threshold)
            $decayMin,      // decay: GREATEST floor
            $decayDays,     // decay: divisor for linear interpolation
            $pageId         // WHERE page_id
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

        // $wpdb->get_row always returns an object or null; guarantee object.
        if ( ! $result ) {
            return (object) [
                'positive_score'       => 0.0,
                'negative_score'       => 0.0,
                'vote_count'           => 0,
                'unique_voters'        => 0,
                'mature_unique_voters' => 0,
                'last_vote_at'         => null,
            ];
        }

        return $result;
    }

    /**
     * Get votes by voter.
     *
     * @return object[]
     * @phpstan-return list<VoteByVoterRow>
     */
    public function getByVoter( int $voterId, int $limit = 20 ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.id, v.voter_user_id, v.page_id, v.category_id, v.vote_type, v.weight, v.vested_weight, v.fraud_score_at_vote, v.vesting_stage, v.vesting_started_at, v.fully_vested_at, v.weight_corrected_at, v.reason, v.explanation, v.status, v.ip_address, v.created_at, v.updated_at, p.post_title as page_title,
                        s.total_score as page_score,
                        s.reputation_tier as page_tier
                 FROM {$this->table} v
                 LEFT JOIN {$wpdb->posts} p ON v.page_id = p.ID
                 LEFT JOIN " . \BCC\Trust\Core\Database\TableRegistry::scores() . " s ON v.page_id = s.page_id
                 WHERE v.voter_user_id = %d
                   AND v.status        = 1
                 ORDER BY v.created_at DESC
                 LIMIT %d",
                $voterId,
                $limit
            )
        );
    }

    /**
     * Paginated review list for the §V1.5 /users/:handle/reviews tab.
     *
     * Joins post_name + post_type so the service can compose `subject_handle`
     * + `scope_label` without a follow-up query per row. `explanation`
     * carries the long-form review body when present (reviews are the
     * §D2 long-form variant of votes — bare votes leave it empty).
     *
     * @return list<object{
     *   id: int|numeric-string,
     *   page_id: int|numeric-string,
     *   vote_type: int|numeric-string,
     *   explanation: string|null,
     *   reason: string|null,
     *   created_at: string,
     *   page_title: string|null,
     *   page_name: string|null,
     *   page_type: string|null
     * }>
     */
    public function findByVoterPaginated(int $voterId, int $limit, int $offset): array
    {
        if ($voterId <= 0) {
            return [];
        }
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT v.id,
                        v.page_id,
                        v.vote_type,
                        v.explanation,
                        v.reason,
                        v.created_at,
                        p.post_title AS page_title,
                        p.post_name  AS page_name,
                        p.post_type  AS page_type
                 FROM {$this->table} v
                 LEFT JOIN {$wpdb->posts} p ON v.page_id = p.ID
                 WHERE v.voter_user_id = %d
                   AND v.status        = 1
                 ORDER BY v.created_at DESC
                 LIMIT %d OFFSET %d",
                $voterId,
                $limit,
                $offset
            )
        );

        /** @var list<object{
         *   id: int|numeric-string,
         *   page_id: int|numeric-string,
         *   vote_type: int|numeric-string,
         *   explanation: string|null,
         *   reason: string|null,
         *   created_at: string,
         *   page_title: string|null,
         *   page_name: string|null,
         *   page_type: string|null
         * }> $rows
         */
        return $rows ?: [];
    }

    /**
     * Page-side counterpart to {@see findByVoterPaginated()} — paginated
     * list of active reviews whose subject is the given page. Used by
     * `/entities/{kind}/{id}/reviews` to power the Reviews tab on
     * entity profiles.
     *
     * No JOIN here — the service hydrates each voter via the shared
     * `MemberSummaryPrefetcher` + `UserViewService::getSummary` so the
     * caller can render a full MemberSummary card per row instead of
     * a flat `voter_login` string.
     *
     * @return list<object{
     *   id: int|numeric-string,
     *   voter_user_id: int|numeric-string,
     *   vote_type: int|numeric-string,
     *   explanation: string|null,
     *   reason: string|null,
     *   created_at: string
     * }>
     */
    public function findByPageIdPaginated(int $pageId, int $limit, int $offset): array
    {
        if ($pageId <= 0) {
            return [];
        }
        $limit  = max(1, min(100, $limit));
        $offset = max(0, $offset);

        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id,
                        voter_user_id,
                        vote_type,
                        explanation,
                        reason,
                        created_at
                 FROM {$this->table}
                 WHERE page_id = %d
                   AND status  = 1
                 ORDER BY created_at DESC
                 LIMIT %d OFFSET %d",
                $pageId,
                $limit,
                $offset
            )
        );

        /** @var list<object{
         *   id: int|numeric-string,
         *   voter_user_id: int|numeric-string,
         *   vote_type: int|numeric-string,
         *   explanation: string|null,
         *   reason: string|null,
         *   created_at: string
         * }> $rows
         */
        return $rows ?: [];
    }

    /**
     * Count total active votes whose subject is the given page. Pair
     * to {@see findByPageIdPaginated()} for `pagination.total`.
     */
    public function countByPageId(int $pageId): int
    {
        if ($pageId <= 0) {
            return 0;
        }
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE page_id = %d
               AND status  = 1",
            $pageId
        ));
    }

    /**
     * Count total active votes by voter.
     */
    public function countByVoter( int $voterId ): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE voter_user_id = %d
               AND status        = 1",
            $voterId
        ) );
    }

    /**
     * Batched: count of active votes per voter for a list of voter ids.
     * Single GROUP BY replaces N sequential `countByVoter` calls.
     * Voters with no votes are absent from the map; callers default to 0.
     *
     * @param int[] $voterIds Bounded by caller.
     * @return array<int, int> voter_user_id → count
     */
    public function countByVoters( array $voterIds ): array {
        if ($voterIds === []) {
            return [];
        }

        $clean = [];
        foreach ($voterIds as $id) {
            $intVal = (int) $id;
            if ($intVal > 0) {
                $clean[$intVal] = true;
            }
        }
        if ($clean === []) {
            return [];
        }
        $idList = array_keys($clean);

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($idList), '%d'));

        /** @var list<array{voter_user_id: string, c: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT voter_user_id, COUNT(*) AS c
                   FROM {$this->table}
                  WHERE voter_user_id IN ({$placeholders})
                    AND status = 1
                  GROUP BY voter_user_id",
                ...$idList
            ),
            ARRAY_A
        );

        $out = [];
        foreach (($rows ?: []) as $row) {
            $out[(int) $row['voter_user_id']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Count active votes by voter since a MySQL DATETIME boundary.
     * Used by the §O3 living header for today's reviews_written count.
     */
    public function countByVoterSince( int $voterId, string $sinceMysql ): int {
        if ($voterId <= 0 || $sinceMysql === '') {
            return 0;
        }

        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE voter_user_id = %d
               AND status        = 1
               AND created_at   >= %s",
            $voterId,
            $sinceMysql
        ) );
    }

    /**
     * Get PeepSo category IDs for a page.
     *
     * Delegates to PeepSoQueryRepository (centralised PeepSo table access).
     *
     * @return object[] Array of objects with term_id property.
     * @phpstan-return list<object{term_id: int|numeric-string}>
     */
    public function getPageCategories(int $pageId): array {
        return PeepSoQueryRepository::getPageCategories($pageId);
    }

    /**
     * Get vote statistics for a page.
     *
     * Results are cached in the WordPress object cache for 60 seconds.
     * Invalidated by VoteService::invalidateAfterVoteChange() which deletes
     * the cache key on every vote cast/removal.
     *
     * @phpstan-return VotePageStatsRow
     */
    public function getPageStats( int $pageId ): object {
        $cacheKey = "page_vote_stats_{$pageId}";
        $cached   = wp_cache_get( $cacheKey, 'bcc_trust_votes' );

        if ( $cached !== false ) {
            return $cached;
        }

        global $wpdb;

        $stats = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) as total_votes,
                SUM(CASE WHEN vote_type > 0 THEN weight ELSE 0 END) as total_positive_weight,
                SUM(CASE WHEN vote_type < 0 THEN weight ELSE 0 END) as total_negative_weight,
                COUNT(DISTINCT voter_user_id) as unique_voters,
                MAX(created_at) as last_vote_at,
                AVG(CASE WHEN vote_type > 0 THEN weight ELSE NULL END) as avg_upvote_weight,
                AVG(CASE WHEN vote_type < 0 THEN weight ELSE NULL END) as avg_downvote_weight
             FROM {$this->table}
             WHERE page_id = %d
               AND status  = 1",
            $pageId
        ) );

        if ( ! $stats ) {
            $stats = (object) [
                'total_votes'           => 0,
                'total_positive_weight' => 0,
                'total_negative_weight' => 0,
                'unique_voters'         => 0,
                'last_vote_at'          => null,
                'avg_upvote_weight'     => 0,
                'avg_downvote_weight'   => 0,
            ];
        }

        wp_cache_set( $cacheKey, $stats, 'bcc_trust_votes', 60 );

        return $stats;
    }

    /**
     * Get vote counts by type for a page.
     *
     * @return array<string, int|float>
     */
    public function getVoteCountsByType( int $pageId ): array {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT vote_type,
                        COUNT(DISTINCT voter_user_id) as count,
                        SUM(weight) as total_weight
                 FROM {$this->table}
                 WHERE page_id = %d
                   AND status  = 1
                 GROUP BY vote_type",
                $pageId
            )
        );

        $counts = [
            'upvotes'             => 0,
            'downvotes'           => 0,
            'upvote_weight'       => 0,
            'downvote_weight'     => 0,
            'upvote_avg_weight'   => 0,
            'downvote_avg_weight' => 0,
        ];

        foreach ( $results as $row ) {
            if ( $row->vote_type > 0 ) {
                $counts['upvotes']           = (int)   $row->count;
                $counts['upvote_weight']     = (float) $row->total_weight;
                $counts['upvote_avg_weight'] = $row->count > 0 ? $row->total_weight / $row->count : 0;
            } else {
                $counts['downvotes']             = (int)   $row->count;
                $counts['downvote_weight']       = (float) $row->total_weight;
                $counts['downvote_avg_weight']   = $row->count > 0 ? $row->total_weight / $row->count : 0;
            }
        }

        return $counts;
    }

    /**
     * Get votes with fraud indicators using config threshold.
     *
     * @return object[]
     */
    public function getVotesWithFraud( int $pageId, ?float $minFraudScore = null ): array {
        global $wpdb;

        $minFraudScore = $minFraudScore ?? $this->fraudMedium;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT v.id, v.voter_user_id, v.page_id, v.category_id, v.vote_type, v.weight, v.vested_weight, v.fraud_score_at_vote, v.vesting_stage, v.vesting_started_at, v.fully_vested_at, v.weight_corrected_at, v.reason, v.explanation, v.status, v.ip_address, v.created_at, v.updated_at, u.display_name, ui.fraud_score, ui.risk_level, ui.automation_score
             FROM {$this->table} v
             JOIN " . \BCC\Trust\Core\Database\TableRegistry::userInfo() . " ui ON v.voter_user_id = ui.user_id
             JOIN {$wpdb->users} u ON v.voter_user_id = u.ID
             WHERE v.page_id      = %d
               AND v.status       = 1
               AND ui.fraud_score >= %f
             ORDER BY ui.fraud_score DESC
             LIMIT 500",
            $pageId,
            $minFraudScore
        ) );
    }


    /**
     * Clean up old deleted votes using config.
     *
     * Chunked so a large backlog (first cleanup after an outage, or after a
     * mass soft-delete) does not hold a long table-level write lock and
     * block live vote submissions. Each statement deletes at most
     * BCC_TRUST_VOTE_CLEANUP_BATCH rows; the caller (daily cron) invokes
     * this inside its 120s budget and the short-lived statements release
     * locks between iterations.
     *
     * @return int Total rows deleted across all iterations.
     */
    public function cleanupOldDeleted(): int {
        global $wpdb;

        $batchSize = defined('BCC_TRUST_VOTE_CLEANUP_BATCH')
            ? max(100, (int) BCC_TRUST_VOTE_CLEANUP_BATCH)
            : 5000;

        // Hard ceiling on total iterations so a pathological clock / data
        // state cannot make this loop spin forever inside the cron tick.
        $maxIterations = 20;
        $total         = 0;

        for ($i = 0; $i < $maxIterations; $i++) {
            $affected = $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$this->table}
                     WHERE status     = 0
                       AND updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d DAY)
                     LIMIT %d",
                    $this->cleanupDays,
                    $batchSize
                )
            );

            if ($affected === false) {
                // Fail-loud: don't hide DB errors under a success-shaped return.
                if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                    \BCC\Core\Log\Logger::error('[bcc-trust] cleanupOldDeleted DB error', [
                        'iteration' => $i,
                        'total'     => $total,
                        'db_error'  => $wpdb->last_error,
                    ]);
                }
                break;
            }

            $total += (int) $affected;

            if ((int) $affected < $batchSize) {
                break;
            }
        }

        return $total;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Execute the INSERT / UPDATE SQL for a vote row.
     *
     * LOCKING ORDER CONTRACT:
     *   ① SELECT … FOR UPDATE on the vote row  (this method)
     *   ② UPDATE on the score row              (ScoreRepository, called by VoteWriter AFTER this returns)
     *
     * This order must be consistent across ALL code paths that touch both
     * tables inside a transaction to prevent deadlocks. VoteWriter::write()
     * enforces this by calling upsertRaw() before any ScoreRepository method.
     *
     * updateVoterStats() has been intentionally removed. It previously issued
     * a COUNT(*) + UPDATE inside the transaction, holding the write lock longer
     * than necessary. Stats are now refreshed by the StatsRefresher async job.
     *
     * @param array<string, mixed> $voteData
     * @return array{vote_id:int,was_inserted:bool,vote_type_changed:bool,weight_changed:bool,previous_vote_type:int|null,previous_weight:float}
     */
    private function executeUpsert( array $voteData, string $now ): array {
        global $wpdb;

        // ① Acquire row-level write lock on the vote row (active or soft-deleted).
        //
        // We lock ANY existing row for this (voter, page, category) tuple regardless
        // of status so that concurrent transactions cannot both see "no active row"
        // and both insert. The status is checked AFTER the lock is held.
        //
        // Three outcomes are handled:
        //   A. Active row   (status = 1) → idempotent update or type-change update.
        //   B. Deleted row  (status = 0) → restore as a fresh vote (was_inserted = true).
        //   C. No row at all             → insert (was_inserted = true).
        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, vote_type, weight, status FROM {$this->table}
                 WHERE voter_user_id = %d
                   AND page_id       = %d
                   AND category_id   = %d
                 FOR UPDATE",
                $voteData['voter_user_id'],
                $voteData['page_id'],
                $voteData['category_id']
            )
        );

        // ── Case A: Active row exists ─────────────────────────────────────────
        if ( $existing && (int) $existing->status === 1 ) {
            $voteTypeChanged = (int) $existing->vote_type !== (int) $voteData['vote_type'];
            $weightChanged   = abs( (float) $existing->weight - (float) $voteData['weight'] ) > 0.0001;

            // Idempotent retry: nothing changed — skip the write entirely.
            if ( $voteTypeChanged || $weightChanged ) {
                $result = $wpdb->update(
                    $this->table,
                    $voteData,
                    [ 'id' => $existing->id ],
                    $this->getFormatSpecifiers( $voteData ),
                    [ '%d' ]
                );
                if ( $result === false ) {
                    throw new \Exception( 'Vote update failed: ' . $wpdb->last_error );
                }
            }

            return [
                'vote_id'            => (int)   $existing->id,
                'was_inserted'       => false,
                'vote_type_changed'  => $voteTypeChanged,
                'weight_changed'     => $weightChanged,
                'previous_vote_type' => (int)   $existing->vote_type,
                'previous_weight'    => (float) $existing->weight,
            ];
        }

        // ── Case B1: Dispute-removed row (status = 2) ────────────────────────
        // A previous vote was removed via accepted dispute adjudication. The
        // voter is blocked from re-voting on this page to prevent abuse.
        if ( $existing && (int) $existing->status === 2 ) {
            throw new \BCC\Trust\Core\Exceptions\VoteEligibilityException(
                'Your previous vote on this page was removed by dispute adjudication and cannot be recast.'
            );
        }

        // ── Case B: Soft-deleted row exists (status = 0) ─────────────────────
        // Restore it as a brand-new vote. The score delta must be applied as if
        // this were an insertion, so was_inserted = true. The row's created_at is
        // reset so time-decay starts from now.
        if ( $existing && (int) $existing->status === 0 ) {
            $voteData['created_at'] = $now;

            $result = $wpdb->update(
                $this->table,
                $voteData,
                [ 'id' => $existing->id ],
                $this->getFormatSpecifiers( $voteData ),
                [ '%d' ]
            );
            if ( $result === false ) {
                throw new \Exception( 'Vote restore failed: ' . $wpdb->last_error );
            }

            return [
                'vote_id'            => (int) $existing->id,
                'was_inserted'       => true,   // treat as new so score delta is applied
                'vote_type_changed'  => false,
                'weight_changed'     => false,
                'previous_vote_type' => null,
                'previous_weight'    => 0.0,
            ];
        }

        // ── Case C: No row exists — insert ────────────────────────────────────
        $voteData['created_at'] = $now;

        $inserted = $wpdb->insert(
            $this->table,
            $voteData,
            $this->getFormatSpecifiers( $voteData )
        );

        if ( $inserted === false ) {
            throw new \Exception( 'Vote insert failed: ' . $wpdb->last_error );
        }

        return [
            'vote_id'            => (int) $wpdb->insert_id,
            'was_inserted'       => true,
            'vote_type_changed'  => false,
            'weight_changed'     => false,
            'previous_vote_type' => null,
            'previous_weight'    => 0.0,
        ];
    }

    /**
     * Normalise and clamp a raw data array into the shape expected by executeUpsert().
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normaliseVoteData( array $data ): array {
        $now = current_time( 'mysql' );

        return [
            'voter_user_id'       => (int)   $data['voter_user_id'],
            'page_id'             => (int)   $data['page_id'],
            'category_id'         => isset( $data['category_id'] )         ? (int)   $data['category_id']         : 0,
            'vote_type'           => (int)   $data['vote_type'],
            'weight'              => max( $this->minVoteWeight, min( $this->maxVoteWeight,
                                        isset( $data['weight'] ) ? (float) $data['weight'] : $this->defaultVoteWeight ) ),
            'vested_weight'       => isset( $data['vested_weight'] )       ? (float) $data['vested_weight']        : 0.0,
            'fraud_score_at_vote' => isset( $data['fraud_score_at_vote'] ) ? (int)   $data['fraud_score_at_vote']  : null,
            'vesting_stage'       => isset( $data['vesting_stage'] )       ? (int)   $data['vesting_stage']        : 0,
            'vesting_started_at'  => $data['vesting_started_at'] ?? null,
            'fully_vested_at'     => $data['fully_vested_at']     ?? null,
            // Schema: reason VARCHAR(100). Cap pre-INSERT so STRICT-mode
            // MySQL does not reject a long string with a hard error (and
            // non-STRICT does not silently truncate, stripping user intent).
            'reason'              => isset( $data['reason'] )
                ? mb_substr( sanitize_text_field( $data['reason'] ), 0, 100 )
                : null,
            'explanation'         => isset( $data['explanation'] ) ? sanitize_textarea_field( $data['explanation'] ) : null,
            'ip_address'          => ( isset( $data['ip_address'] ) && strlen( $data['ip_address'] ) <= 16 )
                                        ? $data['ip_address'] : null,
            'status'              => 1,
            'updated_at'          => $now,
        ];
    }

    /**
     * Get PDO-style format specifiers for wpdb prepared statements.
     *
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function getFormatSpecifiers( array $data ): array {
        $formats = [];

        foreach ( array_keys( $data ) as $field ) {
            switch ( $field ) {
                case 'voter_user_id':
                case 'page_id':
                case 'category_id':
                case 'vote_type':
                case 'status':
                case 'vesting_stage':
                case 'fraud_score_at_vote':
                    $formats[] = '%d';
                    break;
                case 'weight':
                case 'vested_weight':
                    $formats[] = '%f';
                    break;
                default:
                    $formats[] = '%s';
                    break;
            }
        }

        return $formats;
    }

    /**
     * Mark a vote for admin review (does NOT change weight).
     */
    public function markForReview(int $voteId): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table,
            ['reason' => 'flagged_pending_review'],
            ['id' => $voteId],
            ['%s'],
            ['%d']
        );
    }

    // -------------------------------------------------------------------------
    // Behavioral analysis queries
    // -------------------------------------------------------------------------

    /**
     * Get recent votes for a user (vote_type, created_at, page_id, weight).
     *
     * @param int $userId
     * @param int $limit
     * @return object[]
     * @phpstan-return list<object{
     *   vote_type: int|numeric-string,
     *   created_at: string,
     *   page_id: int|numeric-string,
     *   weight: float|numeric-string
     * }>
     */
    public function getRecentVotesForUser( int $userId, int $limit = 100 ): array {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT vote_type, created_at, page_id, weight
             FROM {$this->table}
             WHERE voter_user_id = %d AND status = 1
             ORDER BY created_at DESC
             LIMIT %d",
            $userId,
            $limit
        ) );
    }

    /**
     * Get distinct pages a user has voted on, along with page_owner_id from scores.
     *
     * @param int $userId
     * @param int $limit
     * @return object[]
     */
    public function getVotedPagesWithOwners( int $userId, int $limit = 500 ): array {
        global $wpdb;

        $scoresTable = \BCC\Trust\Core\Database\TableRegistry::scores();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT v.page_id, s.page_owner_id
             FROM {$this->table} v
             JOIN {$scoresTable} s ON v.page_id = s.page_id
             WHERE v.voter_user_id = %d AND v.status = 1
             LIMIT %d",
            $userId,
            $limit
        ) );
    }

    /**
     * Count mutual (reciprocal) vote pages between a user and page owners.
     *
     * Pattern: user A votes on pages owned by B, AND B votes on pages owned by A.
     *
     * @param int $userId
     * @return int
     */
    public function countMutualVotePages( int $userId ): int {
        global $wpdb;

        $scoresTable = \BCC\Trust\Core\Database\TableRegistry::scores();

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT v1.page_id)
             FROM {$this->table} v1
             JOIN {$scoresTable} s1 ON v1.page_id = s1.page_id
             JOIN {$this->table} v2 ON v2.voter_user_id = s1.page_owner_id
             JOIN {$scoresTable} s2 ON v2.page_id = s2.page_id
             WHERE v1.voter_user_id = %d
               AND v2.voter_user_id = s1.page_owner_id
               AND s2.page_owner_id = %d
               AND v1.status = 1
               AND v2.status = 1",
            $userId,
            $userId
        ) );
    }

    /**
     * Count reciprocal votes for the social pattern analysis.
     *
     * Returns the total mutual vote count (not distinct pages).
     *
     * @param int $userId
     * @return int
     */
    public function countReciprocalVotes( int $userId ): int {
        global $wpdb;

        $scoresTable = \BCC\Trust\Core\Database\TableRegistry::scores();

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$this->table} v1
             JOIN {$scoresTable} s1 ON v1.page_id = s1.page_id
             JOIN {$this->table} v2 ON v2.voter_user_id = s1.page_owner_id
             JOIN {$scoresTable} s2 ON v2.page_id = s2.page_id
             WHERE v1.voter_user_id = %d
               AND v2.voter_user_id = s1.page_owner_id
               AND s2.page_owner_id = %d
               AND v1.status = 1
               AND v2.status = 1",
            $userId,
            $userId
        ) );
    }

    /**
     * Get vote type distribution stats for a user within the last N days.
     *
     * @param int $userId
     * @param int $days
     * @return object|null  { positive, negative, total }
     * @phpstan-return VoteTypeStatsRow|null
     */
    public function getVoteTypeStats( int $userId, int $days = 7 ): ?object {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN vote_type > 0 THEN 1 ELSE 0 END) as positive,
                SUM(CASE WHEN vote_type < 0 THEN 1 ELSE 0 END) as negative,
                COUNT(*) as total
             FROM {$this->table}
             WHERE voter_user_id = %d
             AND status = 1
             AND created_at > (UTC_TIMESTAMP() - INTERVAL %d DAY)",
            $userId,
            $days
        ) );
    }

    /**
     * Count vote changes (flip-flops) for a user in the last N minutes.
     *
     * @param int $userId
     * @param int $minutes
     * @return int
     */
    public function countVoteChanges( int $userId, int $minutes = 30 ): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$this->table} v1
             JOIN {$this->table} v2 ON v1.page_id = v2.page_id
             WHERE v1.voter_user_id = %d
             AND v2.voter_user_id = %d
             AND v1.id != v2.id
             AND v1.vote_type != v2.vote_type
             AND v2.created_at > v1.created_at
             AND v2.created_at > (UTC_TIMESTAMP() - INTERVAL %d MINUTE)
             AND v1.status = 1
             AND v2.status = 1",
            $userId,
            $userId,
            $minutes
        ) );
    }

    /**
     * Get total_score values for a list of page IDs.
     *
     * @param int[] $pageIds
     * @return string[]
     */
    public function getPageScoresForPageIds( array $pageIds ): array {
        global $wpdb;

        if ( empty( $pageIds ) ) {
            return [];
        }

        $scoresTable   = \BCC\Trust\Core\Database\TableRegistry::scores();
        $placeholders  = implode( ',', array_fill( 0, count( $pageIds ), '%d' ) );

        return $wpdb->get_col( $wpdb->prepare(
            "SELECT total_score FROM {$scoresTable}
             WHERE page_id IN ({$placeholders})",
            ...$pageIds
        ) );
    }

    /**
     * Get recent voters for a page within the last N days, joined with user_info.
     *
     * @param int $pageId
     * @param int $days
     * @return object[]
     * @phpstan-return list<object{voter_user_id: int|numeric-string, registered: string|null}>
     */
    public function getRecentVotersWithRegistration( int $pageId, int $days = 1 ): array {
        global $wpdb;

        $userInfoTable = \BCC\Trust\Core\Database\TableRegistry::userInfo();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT v.voter_user_id, ui.registered
             FROM {$this->table} v
             LEFT JOIN {$userInfoTable} ui ON v.voter_user_id = ui.user_id
             WHERE v.page_id = %d
               AND v.status = 1
               AND v.created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY v.voter_user_id",
            $pageId,
            $days
        ) );
    }

    /**
     * Count distinct other pages voted on by a set of voter IDs (excluding a specific page).
     *
     * @param int[] $voterIds
     * @param int   $excludePageId
     * @return int
     */
    public function countOtherPagesVotedByUsers( array $voterIds, int $excludePageId ): int {
        global $wpdb;

        if ( empty( $voterIds ) ) {
            return 0;
        }

        $placeholders = implode( ',', array_fill( 0, count( $voterIds ), '%d' ) );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT page_id) FROM {$this->table}
             WHERE voter_user_id IN ({$placeholders})
               AND page_id != %d
               AND status = 1",
            ...array_merge( $voterIds, [ $excludePageId ] )
        ) );
    }

    /**
     * Get mutual vote pairs for vote ring detection.
     *
     * @param int $minSize    Minimum mutual count threshold.
     * @param int $pageLimit  Maximum rows to return.
     * @param int $offset     Offset for pagination.
     * @return object[]
     * @phpstan-return list<MutualVotePair>
     */
    public function getMutualVotePairs( int $minSize, int $pageLimit = 500, int $offset = 0 ): array {
        global $wpdb;

        $scoresTable = \BCC\Trust\Core\Database\TableRegistry::scores();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                v1.voter_user_id as user_a,
                v2.voter_user_id as user_b,
                COUNT(*) as mutual_count,
                SUM(v1.weight + v2.weight) as total_weight
             FROM {$this->table} v1
             JOIN {$scoresTable} s1 ON v1.page_id = s1.page_id
             JOIN {$this->table} v2 ON v2.voter_user_id = s1.page_owner_id
             JOIN {$scoresTable} s2 ON v2.page_id = s2.page_id
             WHERE v1.status = 1
               AND v2.status = 1
               AND s2.page_owner_id = v1.voter_user_id
               AND v1.voter_user_id != v2.voter_user_id
             GROUP BY v1.voter_user_id, v2.voter_user_id
             HAVING mutual_count >= %d
             LIMIT %d OFFSET %d",
            $minSize,
            $pageLimit,
            $offset
        ) );
    }

    /**
     * Batch-fetch vote data by vote IDs (lightweight: id, vote_type, weight, reason, created_at).
     *
     * @param  int[] $voteIds
     * @return array<int, array{vote_type: int, weight: float, reason: string, created_at: ?string}>
     */
    public function getBatchByIds(array $voteIds): array
    {
        global $wpdb;

        if (empty($voteIds)) {
            return [];
        }

        $voteIds      = array_map('intval', array_unique($voteIds));
        $placeholders = implode(',', array_fill(0, count($voteIds), '%d'));

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, vote_type, weight, reason, created_at
                 FROM {$this->table}
                 WHERE id IN ({$placeholders})",
                ...$voteIds
            )
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->id] = [
                'vote_type'  => (int) $row->vote_type,
                'weight'     => (float) $row->weight,
                'reason'     => isset($row->reason) ? (string) $row->reason : '',
                'created_at' => isset($row->created_at) ? (string) $row->created_at : null,
            ];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Admin / moderation bulk operations
    // -------------------------------------------------------------------------

    /**
     * Soft-delete all active votes by a voter.
     *
     * @return int|false Number of rows updated, or false on failure.
     */
    public function softDeleteAllByVoter(int $voterId)
    {
        global $wpdb;

        return $wpdb->update(
            $this->table,
            ['status' => 0, 'updated_at' => current_time('mysql')],
            ['voter_user_id' => $voterId],
            ['%d', '%s'],
            ['%d']
        );
    }

    /**
     * Get distinct active page IDs voted on by a voter.
     *
     * @return int[]
     */
    public function getActivePageIdsByVoter(int $voterId): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT page_id FROM {$this->table} WHERE voter_user_id = %d AND status = 1",
            $voterId
        )));
    }

    /**
     * Soft-delete all active votes by voters in a set of user IDs.
     *
     * @param int[] $voterIds
     * @return int|false Number of rows updated, or false on failure.
     */
    public function softDeleteByVoterIds(array $voterIds)
    {
        global $wpdb;

        if (empty($voterIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($voterIds), '%d'));

        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET status = 0, updated_at = %s
             WHERE voter_user_id IN ({$placeholders})",
            current_time('mysql'), ...$voterIds
        ));
    }

    /**
     * Get distinct active page IDs voted on by any of the given voter IDs.
     *
     * @param int[] $voterIds
     * @return int[]
     */
    public function getActivePageIdsByVoterIds(array $voterIds): array
    {
        global $wpdb;

        if (empty($voterIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($voterIds), '%d'));

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT page_id FROM {$this->table}
             WHERE voter_user_id IN ({$placeholders}) AND status = 1",
            ...$voterIds
        )));
    }

    /**
     * Soft-delete all active votes on the given page IDs.
     *
     * @param int[] $pageIds
     * @return int|false
     */
    public function softDeleteByPageIds(array $pageIds)
    {
        global $wpdb;

        if (empty($pageIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($pageIds), '%d'));

        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table} SET status = 0, updated_at = %s
             WHERE page_id IN ({$placeholders})",
            current_time('mysql'), ...$pageIds
        ));
    }

    /**
     * Whether a page carries at least one active (contestable) downvote
     * — an `vote_type = -1`, `status = 1` row. Feeds the §4.4
     * `can_open_dispute` gate on the owner's own card: the dispute
     * entry point only makes sense when there is a live downvote to
     * contest. Bounded by the existing (page_id, status) index + an
     * explicit LIMIT 1 (existence check, not a count).
     *
     * Mirrors the active-downvote predicate the DisputeController write
     * path enforces per-vote (vote_type < 0, vote active); this is the
     * page-scoped "any contestable downvote?" read for the view-model.
     */
    public function hasActiveDownvoteForPage(int $pageId): bool
    {
        if ($pageId <= 0) {
            return false;
        }

        global $wpdb;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table}
             WHERE page_id = %d
               AND vote_type = -1
               AND status = 1
             LIMIT 1",
            $pageId
        ));

        return (bool) $existing;
    }

    /**
     * Count recent downvotes on a page within a time window.
     */
    public function countRecentDownvotesForPage(int $pageId, string $interval = '1 HOUR'): int
    {
        global $wpdb;

        // Parameterize both the magnitude AND the unit so nothing is
        // interpolated into the SQL string. Unit is still allowlisted
        // belt-and-braces.
        $allowedUnits = ['SECOND', 'MINUTE', 'HOUR', 'DAY', 'WEEK'];
        $parts = preg_split('/\s+/', trim(strtoupper($interval)));
        $magnitude = isset($parts[0]) ? (int) $parts[0] : 1;
        $unit      = $parts[1] ?? 'HOUR';
        if ($magnitude <= 0 || !in_array($unit, $allowedUnits, true)) {
            $magnitude = 1;
            $unit      = 'HOUR';
        }

        // INTERVAL's unit keyword (HOUR/DAY/etc.) is a SQL token and
        // cannot be bound as a parameter — switch per allowlisted unit.
        $intervalSql = match ($unit) {
            'SECOND' => 'INTERVAL %d SECOND',
            'MINUTE' => 'INTERVAL %d MINUTE',
            'HOUR'   => 'INTERVAL %d HOUR',
            'DAY'    => 'INTERVAL %d DAY',
            'WEEK'   => 'INTERVAL %d WEEK',
        };

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$this->table}
             WHERE page_id = %d
               AND vote_type = -1
               AND status = 1
               AND created_at >= DATE_SUB(NOW(), {$intervalSql})",
            $pageId,
            $magnitude
        ));
    }

    // graduateVestingBatch() + flagScoresForFullyVestedVotes() deleted
    // (Rank Phase 2, audit conflict #10): the cron graduation recomputed
    // vested_weight from the RAW weight column, overwriting every
    // velocity-capped correctWeight() value below and letting the next
    // recalc re-sum uncapped. Vote-time vesting (VoteWeightCalculator,
    // the insert path, resetVesting) stays live until Phase 6.

    /**
     * Persist the velocity-capped weight onto the vote row.
     *
     * The score delta applied inside the transaction uses the velocity-capped
     * weight, but upsertRaw stored the raw effective weight on the row.
     * Without this correction SUM(v.weight) in the recalculation cron would
     * diverge strictly upward from the incremental path, silently reversing
     * every velocity-cap clamp on the next recalc tick.
     *
     * Does NOT touch weight_corrected_at — historically consumed by the
     * retired vesting-graduation cron (Rank Phase 2); left NULL-until-set
     * so the column's audit meaning ("a correction was applied") holds
     * until Phase 6 retires the vesting columns wholesale.
     *
     * Called from VoteWriter inside the same transaction as the score delta,
     * so readers never observe the intermediate uncapped value.
     *
     * @throws RepositoryException on database failure
     */
    public function correctWeight(int $voteId, float $cappedWeight): void
    {
        global $wpdb;

        // Write the velocity-capped VESTED contribution onto vested_weight,
        // not onto the canonical `weight` column. Keeping `weight` as the
        // raw effective value preserves the audit trail. The recalc SUM
        // reads vested_weight so the incremental delta (cappedWeight)
        // stays aligned with the authoritative path — and with the
        // graduation cron retired (Phase 2, audit #10), nothing rewrites
        // this value from the raw column anymore.
        $result = $wpdb->update(
            $this->table,
            [
                'vested_weight' => $cappedWeight,
                'updated_at'    => current_time('mysql'),
            ],
            ['id' => $voteId],
            ['%f', '%s'],
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('VoteRepository::correctWeight failed: ' . $wpdb->last_error);
        }

        // Invalidate the per-row cache — getById() would otherwise serve the
        // pre-cap weight for up to CACHE_TTL, misleading downstream consumers
        // (dispute adjudicator, admin inspector) about the authoritative weight.
        $this->bustVoteCache($voteId);
    }

    /**
     * Reset vesting fields for a vote by ID.
     *
     * @throws RepositoryException on database failure
     */
    public function resetVesting(int $voteId, int $vestingStage, float $vestedWeight, string $vestingStartedAt): void
    {
        global $wpdb;

        $result = $wpdb->update(
            $this->table,
            [
                'vesting_stage'      => $vestingStage,
                'vested_weight'      => $vestedWeight,
                'vesting_started_at' => $vestingStartedAt,
                'fully_vested_at'    => null,
            ],
            ['id' => $voteId],
            ['%d', '%f', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            throw new RepositoryException('VoteRepository::resetVesting failed: ' . $wpdb->last_error);
        }

        $this->bustVoteCache($voteId);
    }

    /**
     * Invalidate the per-row vote cache.
     *
     * Called from every mutation path (correctWeight, resetVesting, deletes)
     * so getById() can never return a stale row for up to CACHE_TTL seconds.
     */
    private function bustVoteCache(int $voteId): void
    {
        wp_cache_delete("vote_{$voteId}", self::CACHE_GROUP);
    }

    /**
     * Get the earliest vote date for a voter (first-ever vote).
     *
     * @param int $voterId
     * @return string|null  MySQL datetime string, or null if the voter has never voted.
     */
    public function getEarliestVoteDate( int $voterId ): ?string {
        global $wpdb;

        $result = $wpdb->get_var( $wpdb->prepare(
            "SELECT MIN(created_at) FROM {$this->table} WHERE voter_user_id = %d",
            $voterId
        ) );

        return $result ?: null;
    }

    /**
     * Count active votes cast by a user within the last N days.
     *
     * Drives §D5 interest-coupled panel duty (scale-hardening Phase 2,
     * 2026-05-13). The "recent civic activity" affinity signal: a
     * panelist who has voted in the last 30 days is materially more
     * informed about current floor dynamics than one who has gone
     * quiet. The dispute panel benefits from at least some such
     * panelists.
     *
     * Indexed: bcc_trust_votes (voter_user_id, created_at) composite
     * covers WHERE + range filter cheaply. The status=1 filter excludes
     * removed/disputed votes from the count so revocations don't
     * artificially deflate the recent-activity signal.
     *
     * Bounded by the time window — never a full table scan.
     *
     * @param int $voterId   The user to count for.
     * @param int $daysBack  Window size in days. Caller-bounded.
     */
    public function countRecentByActor( int $voterId, int $daysBack ): int {
        global $wpdb;

        $daysBack = max(1, $daysBack);

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
              WHERE voter_user_id = %d
                AND status = 1
                AND created_at > DATE_SUB(NOW(), INTERVAL %d DAY)",
            $voterId,
            $daysBack
        ) );
    }
}
