<?php
/**
 * Edge Repository
 *
 * Manages the materialized trust-edge table (wp_bcc_trust_edges).
 *
 * Each row stores the pre-aggregated weight of all trust signals flowing
 * from one user (source) to another (target) for a given edge type:
 *
 *   'vote'        — sum of vote weights cast by source on pages owned by target
 *   'endorsement' — LEGACY: sum of endorsement weights given by source for
 *                   pages owned by target. The endorse write path (and its
 *                   backing table) is retired, so stored endorsement-type
 *                   rows are frozen — still read by PageRank, never written.
 *
 * The edge table is kept up to date incrementally:
 *   - When a vote is cast, VoteAuditor calls incrementEdge() — O(1) single-row update.
 *   - When a vote is changed / removed, VoteService calls recalculateVoteEdge() — O(n)
 *     full recalculation is acceptable for the rare edit/delete path.
 *
 * TrustGraph::batchCalculateAllRanks() reads this table instead of joining
 * votes + endorsements + scores on every hourly run, reducing that step from
 * O(votes + endorsements) rows to O(edges) rows — a 10-100× smaller result set
 * at scale because many votes collapse into one aggregated edge per user pair.
 *
 * @package BCC\Trust\Core\Repositories
 * @version 1.0.0
 */

namespace BCC\Trust\Core\Repositories;

use BCC\Trust\Core\Exceptions\RepositoryException;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Row + aggregate shapes returned by bcc_trust_edges reads.
 *
 * @phpstan-type EdgeRow object{
 *   source_user_id: int|numeric-string,
 *   target_user_id: int|numeric-string,
 *   weight: float|numeric-string,
 *   edge_type: string
 * }
 * @phpstan-type EdgeBackfillRow object{
 *   source_user_id: int|numeric-string,
 *   target_user_id: int|numeric-string,
 *   total_weight: float|numeric-string,
 *   vote_count?: int|numeric-string
 * }
 * @phpstan-type EdgeStat array{count: int, avg_weight: float}
 */
class EdgeRepository {

    use \BCC\Trust\Onchain\Repositories\GuardsReadFailures;

    const TYPE_VOTE        = 'vote';
    const TYPE_ENDORSEMENT = 'endorsement';

    /**
     * Memory safety cap on the per-call subgraph edge fetch (Phase 8). The
     * incremental-PageRank caller (TrustGraph::incrementalUpdate) caps the
     * neighborhood at 500 users, but a dense neighborhood (high avg-degree)
     * could still load an unbounded edge set into a PHP array. A result that
     * hits this LIMIT is treated as "too dense for a bounded incremental pass"
     * and deferred to the daily batch cron — same fallback as the >500-user bail.
     */
    public const MAX_SUBGRAPH_EDGES = 100000;

    /**
     * Bound on the neighbour walk (F-574): ONE MORE than
     * TrustGraph::incrementalUpdate()'s 500-user neighbourhood cap, so a hub
     * whose neighbours are cut off here still reads as "more than 500" and is
     * still deferred to the daily batch, never computed on a truncated set.
     */
    public const MAX_NEIGHBOR_IDS = 501;

    private string $table;
    private string $votesTable;
    private string $scoresTable;

    public function __construct(private ?VoteRepository $voteRepo = null) {
        $this->table       = \BCC\Trust\Core\Database\TableRegistry::edges();
        $this->votesTable  = \BCC\Trust\Core\Database\TableRegistry::votes();
        $this->scoresTable = \BCC\Trust\Core\Database\TableRegistry::scores();
    }

    /**
     * Source of the hidden-review predicate (F-55's hiddenReviewExclusion()).
     * Injected by Plugin; resolved lazily otherwise so callers that never
     * touch the vote-edge reads need no VoteRepository.
     */
    private function votes(): VoteRepository {
        return $this->voteRepo ??= \BCC\Trust\Core\Plugin::instance()->voteRepository();
    }

    // =========================================================================
    // PUBLIC WRITE API
    // =========================================================================

    /**
     * Increment the vote edge from $sourceUserId → $targetUserId by $weight.
     *
     * Called by VoteAuditor immediately after a new vote commits. Uses a single
     * INSERT … ON DUPLICATE KEY UPDATE so the update is one row-write with no
     * preceding SELECT — O(1) regardless of how many votes already exist between
     * this pair.
     *
     * Contrast with recalculateVoteEdge(), which SUMs the entire votes table for
     * this pair (O(n) where n = votes cast by source on pages owned by target).
     *
     * Schema requirement: the edge table must have a `vote_count` INT column and
     * a `last_updated` DATETIME column (added in migration 007).
     *
     * Self-loops are silently ignored.
     *
     * @param int   $sourceUserId  The voter.
     * @param int   $targetUserId  The page owner receiving the trust signal.
     * @param float $weight        The effective weight of the vote being added.
     */
    public function incrementEdge( int $sourceUserId, int $targetUserId, float $weight ): void {
        global $wpdb;

        if ( $sourceUserId === $targetUserId || $weight <= 0 ) {
            return;
        }

        $now = current_time( 'mysql' );

        $sql = $wpdb->prepare(
            "INSERT INTO {$this->table}
                 ( source_user_id, target_user_id, weight, vote_count, edge_type, created_at, updated_at, last_updated )
             VALUES
                 ( %d, %d, %f, 1, %s, %s, %s, %s )
             ON DUPLICATE KEY UPDATE
                 weight       = weight + VALUES( weight ),
                 vote_count   = vote_count + 1,
                 updated_at   = VALUES( updated_at ),
                 last_updated = VALUES( last_updated )",
            $sourceUserId,
            $targetUserId,
            $weight,
            self::TYPE_VOTE,
            $now,
            $now,
            $now
        );

        $result = $wpdb->query( $sql );

        // InnoDB gap-lock deadlock: two concurrent first-inserts for the same
        // (source, target, edge_type) key can deadlock. Retry once after a short
        // back-off — the second attempt always hits the UPDATE path (row exists).
        if ( $result === false && $wpdb->last_error && str_contains( $wpdb->last_error, 'Deadlock' ) ) {
            usleep( 50000 ); // 50 ms
            $result = $wpdb->query( $sql );
        }

        if ( $result === false ) {
            throw new RepositoryException( 'EdgeRepository::incrementEdge failed: ' . $wpdb->last_error );
        }
    }

    /**
     * Recalculate and persist the vote edge from $sourceUserId → $targetUserId.
     *
     * Sums all active vote weights cast by the source on pages owned by the
     * target, then upserts (or removes) the corresponding edge row.
     *
     * - The scores join is pinned to the canonical `category_id = 0` row
     *   (F-142): the scores PK is (page_id, category_id), so a page with
     *   category rows joined once per row and multiplied the vote.
     * - A moderation-hidden review confers no trust (decision: a hidden
     *   review stops counting) — same predicate as the page score reads.
     * - FAIL-CLOSED read. The sum decides a WRITE (a zero weight deletes
     *   the edge), so it must be this query's own answer: runRead() throws
     *   RepositoryReadFailure on a read that did not run, before anything
     *   is written, and both callers already treat a throw as "not
     *   recalculated". Rationale: M7 (private tracker).
     *
     * @param int $sourceUserId  The voter.
     * @param int $targetUserId  The page owner receiving the trust signal.
     * @throws \BCC\Trust\Onchain\Repositories\RepositoryReadFailure when the sum cannot be read
     */
    public function recalculateVoteEdge( int $sourceUserId, int $targetUserId ): void {
        global $wpdb;

        $rows = self::runRead( (string) $wpdb->prepare(
            "SELECT COALESCE( SUM( v.weight ), 0 ) AS total_weight,
                    COUNT(*)                        AS vote_count
               FROM {$this->votesTable} v
               JOIN {$this->scoresTable} s
                    ON s.page_id = v.page_id
                   AND s.category_id = 0
                   AND s.page_owner_id = %d
              WHERE v.voter_user_id = %d
                AND v.status = 1
                AND " . $this->votes()->hiddenReviewExclusion('v'),
            $targetUserId,
            $sourceUserId
        ), __FUNCTION__, true );

        // An aggregate with no GROUP BY yields exactly one row, even over
        // zero votes. No row means the result is not this query's.
        if ( !isset( $rows[0] ) ) {
            throw new \BCC\Trust\Onchain\Repositories\RepositoryReadFailure( static::class, __FUNCTION__, 'aggregate returned no row' );
        }
        /** @var object{total_weight: float|numeric-string|null, vote_count: int|numeric-string|null} $row */
        $row = $rows[0];

        $this->upsertOrDelete(
            $sourceUserId,
            $targetUserId,
            (float) ( $row->total_weight ?? 0 ),
            self::TYPE_VOTE,
            (int)   ( $row->vote_count   ?? 0 )
        );
    }

    // =========================================================================
    // PUBLIC READ API
    // =========================================================================

    /**
     * Cursor-paginated edge reader used by TrustGraph::batchCalculateAllRanks().
     *
     * Returns up to $limit edges with `id > $afterId`, ordered by id ASC.
     * Callers MUST loop until the returned array is empty (or smaller than
     * $limit) to walk the full graph without loading it all into memory.
     *
     * SAFETY: unbounded load (the previous implementation returned every row)
     * OOMs PHP once the graph grows into millions of edges. Always paginate.
     *
     * FAIL-CLOSED read (F-574). Its caller pages until an EMPTY page, so an
     * empty answer means "end of the table". A failed read must therefore
     * never come back as [] — that would end the walk early and the ranks of
     * a truncated graph would be persisted for every member — so runRead()
     * throws RepositoryReadFailure instead, and the computation is skipped.
     *
     * @param int $afterId  Return rows with id > this. Pass 0 to start.
     * @param int $limit    Max rows per page (default 1000, capped at 10000).
     * @throws \BCC\Trust\Onchain\Repositories\RepositoryReadFailure when the page cannot be read
     * @return object[]
     * @phpstan-return list<object{
     *   id: int|numeric-string,
     *   source_user_id: int|numeric-string,
     *   target_user_id: int|numeric-string,
     *   weight: float|numeric-string,
     *   edge_type: string
     * }>
     */
    public function getAllEdges(int $afterId = 0, int $limit = 1000): array {
        global $wpdb;

        $limit = max(1, min($limit, 10000));

        /** @var list<object{id: int|numeric-string, source_user_id: int|numeric-string, target_user_id: int|numeric-string, weight: float|numeric-string, edge_type: string}> $rows */
        $rows = self::runRead(
            (string) $wpdb->prepare(
                "SELECT id, source_user_id, target_user_id, weight, edge_type
                   FROM {$this->table}
                  WHERE id > %d
                  ORDER BY id ASC
                  LIMIT %d",
                $afterId,
                $limit
            ),
            __FUNCTION__,
            true
        );

        return $rows;
    }

    /**
     * Return all edges where at least one endpoint is in $userIds.
     *
     * This is the subgraph needed for incremental PageRank: given a set of
     * "dirty" users, we fetch every edge touching them (inbound or outbound)
     * so we can re-converge only that neighborhood.
     *
     * FAIL-SAFE read (F-574): a read that cannot be verified answers [],
     * never another query's rows. That is enough here because the only
     * caller treats an empty subgraph as "skip" and writes nothing; the
     * daily batch corrects the neighbourhood.
     *
     * @param int[] $userIds
     * @return object[]
     * @phpstan-return list<EdgeRow>
     */
    public function getEdgesForUsers( array $userIds, int $limit = self::MAX_SUBGRAPH_EDGES ): array {
        global $wpdb;

        if ( empty( $userIds ) ) {
            return [];
        }

        $limit        = max( 1, min( $limit, self::MAX_SUBGRAPH_EDGES ) );
        $placeholders = implode( ',', array_fill( 0, count( $userIds ), '%d' ) );

        // LIMIT bounds the in-memory subgraph: a dense neighborhood would
        // otherwise pull an unbounded edge set into this PHP array. A result at
        // the LIMIT signals "too dense" to the caller, which defers to the daily
        // batch cron rather than run PageRank on a truncated subgraph.
        /** @var list<EdgeRow> $rows */
        $rows = self::runRead( (string) $wpdb->prepare(
            "SELECT source_user_id, target_user_id, weight, edge_type
               FROM {$this->table}
              WHERE source_user_id IN ({$placeholders})
                 OR target_user_id IN ({$placeholders})
              LIMIT %d",
            array_merge( $userIds, $userIds, [ $limit ] )
        ), __FUNCTION__, false );

        return $rows;
    }

    /**
     * Return the distinct user IDs that share an edge with any user in
     * $userIds, at most MAX_NEIGHBOR_IDS of them.
     *
     * FAIL-CLOSED read (F-574). An empty answer shrinks the neighbourhood to
     * the dirty users alone, which slips under TrustGraph's 500-user hub cap
     * and persists the ranks of a truncated subgraph. So a read that cannot
     * be verified throws RepositoryReadFailure instead, and the incremental
     * pass is skipped (its hook catches; the daily batch corrects).
     *
     * Bounded at MAX_NEIGHBOR_IDS, one more than that cap, so a hub still
     * reads as "too many" and the caller still defers it.
     *
     * @param int[] $userIds
     * @return int[]
     * @throws \BCC\Trust\Onchain\Repositories\RepositoryReadFailure when the neighbours cannot be read
     */
    public function getNeighborIds( array $userIds ): array {
        global $wpdb;

        if ( empty( $userIds ) ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $userIds ), '%d' ) );

        /** @var list<object{uid: int|numeric-string}> $rows */
        $rows = self::runRead( (string) $wpdb->prepare(
            "SELECT DISTINCT source_user_id AS uid FROM {$this->table}
              WHERE target_user_id IN ({$placeholders})
             UNION
             SELECT DISTINCT target_user_id AS uid FROM {$this->table}
              WHERE source_user_id IN ({$placeholders})
             LIMIT %d",
            array_merge( $userIds, $userIds, [ self::MAX_NEIGHBOR_IDS ] )
        ), __FUNCTION__, true );

        return array_map( fn( $r ) => (int) $r->uid, $rows );
    }

    /**
     * Count total edges — used for health / stats reporting.
     */
    public function count(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table}"
        );
    }

    // =========================================================================
    // BACKFILL
    // =========================================================================

    /**
     * Populate the edge table from existing votes.
     *
     * Idempotent — ON DUPLICATE KEY UPDATE means existing rows are refreshed,
     * not duplicated. Safe to call multiple times.
     *
     * The legacy endorsement leg was removed with the endorsements-table
     * retirement: existing endorsement-type edge rows are frozen data;
     * vouch attestations do not materialize into the edge table (they
     * carry their own graph reads in AttestationRepository).
     *
     * @return int Number of edges written.
     */
    public function backfillFromSources(): int {
        global $wpdb;

        $count = 0;

        // Aggregate all vote weights: voter → page_owner
        //
        // ⚠⚠ runRead, NOT get_results() — and here the stakes are higher
        // than on a read path. Every row this returns is UPSERTED into the
        // edge table below, so rows that are not this query's own become
        // trust-graph EDGES: wrong source, target and weight, written and
        // durable. A read gate recovers when the database does; this does
        // not.
        //
        // `get_results()` could not see that. It discards query()'s return
        // value, and wpdb::query() bails before flush() on a failed
        // reconnect or an emptied `query` filter — leaving the PREVIOUS
        // query's rows in last_result with last_error EMPTY. The old
        // `?: []` then collapsed a failed read and "no votes yet" into the
        // same answer, so a bail could not even be distinguished from an
        // empty source table.
        //
        // runRead() clears last_error, calls query() explicitly, checks its
        // return AND last_error, and throws with failClosed — so a backfill
        // that cannot trust its source writes NOTHING rather than writing
        // something it made up. Same guard recalculateVoteEdge() already
        // uses, for the same reason.
        /** @var list<EdgeBackfillRow> $voteEdges */
        $voteEdges = self::runRead(
            (string) $wpdb->prepare(
                "SELECT v.voter_user_id   AS source_user_id,
                        s.page_owner_id   AS target_user_id,
                        SUM( v.weight )   AS total_weight,
                        COUNT(*)          AS vote_count
                   FROM {$this->votesTable} v
                   JOIN {$this->scoresTable} s
                        ON s.page_id = v.page_id
                       AND s.category_id = 0
                  WHERE v.status = %d
                    AND s.page_owner_id > 0
                    AND " . $this->votes()->hiddenReviewExclusion('v') . "
               GROUP BY v.voter_user_id, s.page_owner_id",
                1
            ),
            __FUNCTION__,
            true
        );

        foreach ( $voteEdges as $edge ) {
            $this->upsertOrDelete(
                (int)   $edge->source_user_id,
                (int)   $edge->target_user_id,
                (float) $edge->total_weight,
                self::TYPE_VOTE,
                (int)   ( $edge->vote_count ?? 0 )
            );
            $count++;
        }

        return $count;
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /**
     * Upsert an edge if weight > 0, or delete it if weight has dropped to 0.
     *
     * Uses INSERT … ON DUPLICATE KEY UPDATE so concurrent writes are safe
     * without a separate SELECT + conditional INSERT/UPDATE round-trip.
     *
     * $voteCount is the authoritative count from the recalculation query and
     * replaces whatever was stored — callers on the increment path use
     * incrementEdge() instead, which atomically increments the counter.
     */
    private function upsertOrDelete(
        int    $sourceUserId,
        int    $targetUserId,
        float  $weight,
        string $edgeType,
        int    $voteCount = 0
    ): void {
        global $wpdb;

        if ( $sourceUserId === $targetUserId ) {
            return; // Never create self-loops.
        }

        if ( $weight <= 0 ) {
            $wpdb->delete(
                $this->table,
                [
                    'source_user_id' => $sourceUserId,
                    'target_user_id' => $targetUserId,
                    'edge_type'      => $edgeType,
                ],
                [ '%d', '%d', '%s' ]
            );
            return;
        }

        $now = current_time( 'mysql' );

        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$this->table}
                 ( source_user_id, target_user_id, weight, vote_count, edge_type, created_at, updated_at )
             VALUES
                 ( %d, %d, %f, %d, %s, %s, %s )
             ON DUPLICATE KEY UPDATE
                 weight     = VALUES( weight ),
                 vote_count = VALUES( vote_count ),
                 updated_at = VALUES( updated_at )",
            $sourceUserId,
            $targetUserId,
            $weight,
            $voteCount,
            $edgeType,
            $now,
            $now
        ) );

        if ( $result === false ) {
            throw new RepositoryException( 'EdgeRepository::upsertOrDelete failed: ' . $wpdb->last_error );
        }
    }

    /**
     * Hard-delete all edges involving a user (as source or target).
     *
     * Used during user deletion to remove trust graph data.
     *
     * @param int $userId
     */
    public function deleteByUser(int $userId): void {
        global $wpdb;

        $wpdb->delete(
            $this->table,
            ['source_user_id' => $userId],
            ['%d']
        );

        $wpdb->delete(
            $this->table,
            ['target_user_id' => $userId],
            ['%d']
        );
    }
}
