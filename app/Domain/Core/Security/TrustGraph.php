<?php
/**
 * Trust Graph Analyzer
 *
 * Graph-based trust propagation + vote ring detection
 * Uses config constants for thresholds and weights
 *
 * @package BCC\Trust
 * @subpackage Security
 * @version 2.1.1
 * @status alive — canonical PageRank + vote/endorsement ring detector.
 *                 Powers the `bcc_trust_daily_graph_update` cron
 *                 (CronService.php:584). Tunables: `bcc_trust_graph_edge_chunk`,
 *                 `bcc_trust_graph_max_chunks` filters; `BCC_TRUST_GRAPH_*`
 *                 / `BCC_TRUST_RING_*` defines. Phase B V-18 classification
 *                 2026-05-09. See docs/pattern-registry.md "Phase B inventory addendum".
 */

namespace BCC\Trust\Core\Security;

if (!defined('ABSPATH')) exit;

use BCC\Trust\Core\Repositories\EdgeRepository;
use BCC\Trust\Core\Repositories\EndorsementRepository;
use BCC\Trust\Core\Repositories\PatternRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Repositories\VoteRepository;

class TrustGraph {

    private UserInfoRepository $userInfoRepo;
    private EdgeRepository $edgeRepo;
    private VoteRepository $voteRepo;
    private PatternRepository $patternRepo;
    private EndorsementRepository $endorsementRepo;

    const CACHE_GROUP = 'bcc_trust_graph';
    const CACHE_TTL   = BCC_TRUST_CACHE_GRAPH;

    /**
     * Config-based weights and thresholds
     */
    private float $voteWeightMultiplier;
    private float $endorseWeightMultiplier;
    private float $githubAgeBoost;
    private float $githubFollowersBoost;
    private float $githubReposBoost;
    private float $githubOrgsBoost;
    private float $githubVerifiedBoost;
    private float $githubMaxMultiplier;
    private float $ringStrengthThreshold;
    private int $ringMinSize;
    private int $fraudMediumThreshold;
    private float $fraudPenaltyMultiplier;

    public function __construct(
        UserInfoRepository $userInfoRepo,
        EdgeRepository     $edgeRepo
    ) {
        $this->userInfoRepo  = $userInfoRepo;
        $this->edgeRepo      = $edgeRepo;
        $plugin = \BCC\Trust\Core\Plugin::instance();
        $this->voteRepo        = $plugin->voteRepository();
        $this->patternRepo     = $plugin->patternRepository();
        $this->endorsementRepo = $plugin->endorsementRepository();

        $this->voteWeightMultiplier   = BCC_TRUST_GRAPH_VOTE_MULTIPLIER;
        $this->endorseWeightMultiplier = BCC_TRUST_GRAPH_ENDORSE_MULTIPLIER;
        $this->githubAgeBoost         = BCC_TRUST_GITHUB_AGE_BOOST;
        $this->githubFollowersBoost   = BCC_TRUST_GITHUB_FOLLOWERS_BOOST;
        $this->githubReposBoost       = BCC_TRUST_GITHUB_REPOS_BOOST;
        $this->githubOrgsBoost        = BCC_TRUST_GITHUB_ORGS_BOOST;
        $this->githubVerifiedBoost    = BCC_TRUST_GITHUB_VERIFIED_BOOST;
        $this->githubMaxMultiplier    = BCC_TRUST_GITHUB_MAX_MULTIPLIER;
        $this->ringStrengthThreshold  = BCC_TRUST_RING_STRENGTH_THRESHOLD;
        $this->ringMinSize            = BCC_TRUST_RING_MIN_SIZE;
        $this->fraudMediumThreshold   = BCC_TRUST_FRAUD_MEDIUM;
        $this->fraudPenaltyMultiplier = BCC_TRUST_FRAUD_PENALTY_MULTIPLIER;
    }

    /**
     * Calculate PageRank-style trust rank for a single user.
     *
     * At 100k+ users this method reads the pre-computed value from user_info
     * (written by batchCalculateAllRanks() each hour) rather than running a
     * full O(n²) PageRank iteration inline.  A full recalculation is only
     * triggered when no stored rank exists yet (first-ever vote).
     */
    public function calculateTrustRank(int $userId, int $iterations = 10, float $damping = 0.85): float {

        // Object-level cache (within a single request)
        $cached = wp_cache_get('trust_rank_' . $userId, self::CACHE_GROUP);
        if ($cached !== false) {
            return (float) $cached;
        }

        // Read pre-computed rank from user_info — written hourly by batchCalculateAllRanks()
        $stored = $this->userInfoRepo->getByUserId($userId);
        if ($stored && !empty($stored->trust_rank) && (float) $stored->trust_rank > 0) {
            $rank = (float) $stored->trust_rank;
            wp_cache_set('trust_rank_' . $userId, $rank, self::CACHE_GROUP, self::CACHE_TTL);
            return $rank;
        }

        // No stored rank yet — assign a safe default (0.5) and persist it.
        // The hourly batchCalculateAllRanks() cron will overwrite this with
        // the real PageRank value within ~60 minutes. Previously this call
        // triggered a full O(n²) batch recalculation inline, which could
        // block the request for hundreds of milliseconds on large graphs.
        $rank = 0.5;
        $this->userInfoRepo->updateTrustRank($userId, $rank);
        wp_cache_set('trust_rank_' . $userId, $rank, self::CACHE_GROUP, self::CACHE_TTL);

        return $rank;
    }

    /**
     * Batch PageRank — computes trust ranks for ALL users in one pass and
     * persists each rank to user_info.trust_rank.
     *
     * Called once per hour by the cron job (replaces the per-user loop).
     * Returns the full [userId => rank] map so calculateTrustRank() can read
     * the result without a second DB round-trip.
     *
     * Performance vs the old approach:
     *  - Old: N × (endorsement_query + vote_query + per-row get_var) per cron tick
     *  - New: 3 queries total to build the graph, then one UPDATE per user
     *
     * @return array<int, float>
     */
    public function batchCalculateAllRanks(int $iterations = 10, float $damping = 0.85): array {

        // ── 1. Read the materialized edge table in chunks ────────────────────
        //
        // The edge table is kept current by VoteService and EndorsementRepository
        // on every write.  At 100k users this is O(distinct user pairs) rows
        // rather than O(all votes) rows — typically 5-20× smaller result set.
        //
        // CRITICAL: chunk by primary key to bound a single SQL response and
        // let very large graphs stream into memory. Prior code called
        // getAllEdges() with no arguments, silently capping at the default
        // limit of 1000 edges — on a graph with 10k edges PageRank converged
        // on the first 1000 and ignored the remaining 90% of the graph.
        //
        // Falls back to the raw join approach when the edge table is empty
        // (e.g. immediately after a fresh install before backfill runs).
        $chunkSize = (int) apply_filters('bcc_trust_graph_edge_chunk', 5000);
        $maxChunks = (int) apply_filters('bcc_trust_graph_max_chunks', 2000); // hard ceiling

        $rawEdges = $this->fetchAllEdgesChunked($chunkSize, $maxChunks);

        if ( empty( $rawEdges ) ) {
            // Trigger an inline backfill and retry so new installs work immediately.
            $this->edgeRepo->backfillFromSources();
            $rawEdges = $this->fetchAllEdgesChunked($chunkSize, $maxChunks);
        }

        if ( empty( $rawEdges ) ) {
            return []; // No trust data at all yet.
        }

        // ── 2. Collect distinct users and build the adjacency list ────────────
        [ $users, $graph, $outgoing ] = $this->buildAdjacencyList( $rawEdges );

        if ( empty( $users ) ) {
            return [];
        }

        // 3. Bulk-fetch all user_info rows to avoid N+1 getByUserId() calls.
        $bulkUserInfo = $this->userInfoRepo->getBulkByUserIds( array_values( $users ) );

        // ── Connected component partitioning ─────────────────────────────────
        // Most trust graphs are not fully connected. Partitioning before
        // PageRank allows each component to converge independently, reducing
        // the total iteration count dramatically. A 100k-user graph with
        // 50 components of ~2k users runs 50× faster than one monolith.
        $components = $this->findConnectedComponents( $users, $graph );
        $normalized = [];

        foreach ( $components as $componentUsers ) {
            // Skip trivially small components (isolated pairs)
            if ( count( $componentUsers ) < 3 ) {
                foreach ( $componentUsers as $u ) {
                    $normalized[ $u ] = 0.5; // default rank for isolated users
                }
                continue;
            }

            $componentRanks = $this->runPageRankOnComponent(
                $componentUsers,
                $graph,
                $outgoing,
                $bulkUserInfo,
                $iterations,
                $damping
            );

            $normalized = array_replace( $normalized, $componentRanks );
        }

        // 6. Persist all ranks via chunked bulk UPDATE and warm object cache
        $this->bulkPersistRanks( $normalized );

        return $normalized;
    }

    /**
     * Fetch all edges chunked by primary key, bounded by $maxChunks so a
     * pathological loop cannot spin indefinitely.
     *
     * The return shape is a strict subtype of what buildAdjacencyList()
     * accepts — the adjacency builder ignores the id field but this
     * method needs it for primary-key pagination, so id is required
     * here and simply unused by downstream callers.
     *
     * @phpstan-return list<object{
     *   id: int|numeric-string,
     *   source_user_id: int|numeric-string,
     *   target_user_id: int|numeric-string,
     *   weight: float|numeric-string,
     *   edge_type: string
     * }>
     * @return object[]
     */
    private function fetchAllEdgesChunked(int $chunkSize, int $maxChunks): array {
        $all    = [];
        $afterId = 0;
        for ($i = 0; $i < $maxChunks; $i++) {
            $batch = $this->edgeRepo->getAllEdges($afterId, $chunkSize);
            if (empty($batch)) {
                break;
            }
            foreach ($batch as $row) {
                $all[] = $row;
                $rowId = (int) ($row->id ?? 0);
                if ($rowId > $afterId) {
                    $afterId = $rowId;
                }
            }
            if (count($batch) < $chunkSize) {
                break;
            }
        }
        return $all;
    }

    /**
     * Persist trust ranks in bulk using CASE-expression UPDATEs.
     *
     * Instead of one UPDATE per user (N+1), builds batched UPDATE … CASE
     * statements in chunks of 500 — reducing 10k queries to 20.
     *
     * @param array<int, float> $ranks  userId => normalized rank
     */
    private function bulkPersistRanks( array $ranks ): void {
        // Warm the object cache for each user
        foreach ( $ranks as $userId => $rank ) {
            wp_cache_set( 'trust_rank_' . $userId, $rank, self::CACHE_GROUP, self::CACHE_TTL );
        }

        $this->userInfoRepo->bulkUpdateTrustRanks( $ranks );
    }

    /**
     * Find connected components in an undirected graph via BFS.
     *
     * Used by batchCalculateAllRanks() to partition the graph before
     * running PageRank. Each component converges independently,
     * reducing total iterations from O(V²×I) to O(sum(Ci²×Ii)).
     *
     * @param  array<int, int>          $users  userId => userId map.
     * @param  array<int, array<int, float>> $graph  Adjacency list.
     * @return array<int, array<int>>   List of components, each a list of user IDs.
     */

    /**
     * Build the adjacency list from raw edge rows.
     *
     * Shared by batchCalculateAllRanks() and incrementalUpdate() to eliminate
     * the ~35 lines of duplicated graph-construction logic.
     *
     * @param  object[] $rawEdges  Rows from EdgeRepository with source_user_id, target_user_id, weight, edge_type.
     * @return array{0: array<int,int>, 1: array<int,array<int,float>>, 2: array<int,float>}  [ $users, $graph, $outgoing ]
     *
     * @phpstan-param list<object{
     *   source_user_id: int|numeric-string,
     *   target_user_id: int|numeric-string,
     *   weight: float|numeric-string,
     *   edge_type: string
     * }> $rawEdges
     */
    private function buildAdjacencyList( array $rawEdges ): array {
        $users    = [];
        $graph    = [];
        $outgoing = [];

        foreach ( $rawEdges as $edge ) {
            $from = (int) $edge->source_user_id;
            $to   = (int) $edge->target_user_id;

            if ( $from === $to ) {
                continue; // Skip self-loops.
            }

            $users[ $from ] = $from;
            $users[ $to ]   = $to;

            $multiplier = ( $edge->edge_type === EdgeRepository::TYPE_ENDORSEMENT )
                ? $this->endorseWeightMultiplier
                : $this->voteWeightMultiplier;

            $w = (float) $edge->weight * $multiplier;

            // Cap per-source→target edge weight to prevent a single user
            // (or sock puppet) from dominating another user's inbound rank
            // by voting on many of their pages.
            $maxEdgeWeight = 10.0;
            $current       = $graph[ $from ][ $to ] ?? 0.0;
            $capped        = min( $w, $maxEdgeWeight - $current );
            if ( $capped <= 0 ) {
                continue;
            }

            $graph[ $from ][ $to ] = $current + $capped;
            $outgoing[ $from ]     = ( $outgoing[ $from ] ?? 0.0 ) + $capped;
        }

        // Ensure every user has an entry in graph/outgoing (needed for dangling nodes)
        foreach ( $users as $u ) {
            $graph[ $u ]    = $graph[ $u ]    ?? [];
            $outgoing[ $u ] = $outgoing[ $u ] ?? 0.0;
        }

        return [ $users, $graph, $outgoing ];
    }

    /**
     * @param array<int, int>              $users
     * @param array<int, array<int, float>> $graph
     * @return array<int, int[]>
     */
    private function findConnectedComponents( array $users, array $graph ): array {
        // Build a reverse adjacency list once — O(E).
        // $reverse[$target] = [ $source1, $source2, … ]
        // This replaces the inner full-graph scan that made BFS O(V*E).
        $reverse = [];
        foreach ( $graph as $source => $targets ) {
            foreach ( $targets as $target => $weight ) {
                $reverse[ $target ][] = $source;
            }
        }

        $visited    = [];
        $components = [];

        foreach ( $users as $userId ) {
            if ( isset( $visited[ $userId ] ) ) {
                continue;
            }

            // BFS from this unvisited node
            $queue     = [ $userId ];
            $component = [];

            while ( ! empty( $queue ) ) {
                $current = array_shift( $queue );
                if ( isset( $visited[ $current ] ) ) {
                    continue;
                }

                $visited[ $current ] = true;
                $component[]         = $current;

                // Forward edges
                foreach ( $graph[ $current ] ?? [] as $neighbor => $weight ) {
                    if ( ! isset( $visited[ $neighbor ] ) ) {
                        $queue[] = $neighbor;
                    }
                }

                // Reverse edges (graph is directed, but for component discovery
                // we treat it as undirected)
                foreach ( $reverse[ $current ] ?? [] as $other ) {
                    if ( ! isset( $visited[ $other ] ) ) {
                        $queue[] = $other;
                    }
                }
            }

            $components[] = $component;
        }

        return $components;
    }

    /**
     * Run PageRank iterations on a single connected component.
     *
     * Extracted from batchCalculateAllRanks() for use with connected
     * component partitioning. Convergence check and normalization
     * are applied independently to each component.
     *
     * @param  array<int>                   $componentUsers  User IDs in this component.
     * @param  array<int, array<int, float>> $graph          Full adjacency list.
     * @param  array<int, float>            $outgoing        Pre-computed out-degree sums.
     * @param  array<int, object|null>      $bulkUserInfo    Pre-fetched user_info rows.
     * @param  int                          $iterations      Max iterations.
     * @param  float                        $damping         Damping factor.
     * @return array<int, float>                             userId => normalized rank.
     */
    private function runPageRankOnComponent(
        array $componentUsers,
        array $graph,
        array $outgoing,
        array $bulkUserInfo,
        int   $iterations,
        float $damping
    ): array {
        $n           = count( $componentUsers );
        $initialRank = 1.0 / $n;
        $ranks       = [];

        // Build a lookup set for fast membership tests
        $inComponent = [];
        foreach ( $componentUsers as $u ) {
            $inComponent[ $u ] = true;
            $githubBoost       = $this->getGithubAuthorityFromRow( $bulkUserInfo[ $u ] ?? null );
            $ranks[ $u ]       = $initialRank * $githubBoost;
        }

        // PageRank iterations (scoped to this component)
        for ( $i = 0; $i < $iterations; $i++ ) {
            $newRanks = [];
            $diff     = 0.0;

            foreach ( $componentUsers as $user ) {
                $rankSum = 0.0;

                foreach ( $graph as $other => $targets ) {
                    if ( ! isset( $inComponent[ $other ] ) ) {
                        continue; // Skip nodes outside this component
                    }
                    if ( ! isset( $targets[ $user ] ) ) {
                        continue;
                    }
                    if ( empty( $outgoing[ $other ] ) || $outgoing[ $other ] <= 0 ) {
                        continue;
                    }

                    $rankSum += $ranks[ $other ] * ( $targets[ $user ] / $outgoing[ $other ] );
                }

                $newRanks[ $user ] = ( 1 - $damping ) / $n + $damping * $rankSum;
                $diff             += abs( $newRanks[ $user ] - ( $ranks[ $user ] ?? 0.0 ) );
            }

            $ranks = $newRanks;

            if ( $diff < 0.0001 ) {
                break; // Converged
            }
        }

        // Normalize within this component. An empty $ranks set would mean the
        // PageRank loop produced no users — return early rather than passing
        // an empty array to max()/min() (which would emit ValueError in PHP 8+).
        if ( empty( $ranks ) ) {
            return [];
        }

        $maxRank    = max( $ranks );
        $minRank    = min( $ranks );
        $range      = $maxRank - $minRank;
        $normalized = [];

        foreach ( $ranks as $u => $rank ) {
            $raw = ( $range > 0 ) ? ( ( $rank - $minRank ) / $range ) : 0.5;
            $normalized[ $u ] = max( 0.15, min( 0.95, $raw ) );
        }

        return $normalized;
    }

    /**
     * Incremental PageRank update for a localized neighborhood.
     *
     * Instead of recomputing ranks for ALL users (O(n²) per iteration),
     * this method:
     *   1. Takes the "dirty" users (e.g. voter + page owner)
     *   2. Expands one hop to include their direct neighbors
     *   3. Fetches only the edges touching that subgraph
     *   4. Runs PageRank iterations on just that neighborhood
     *   5. Persists updated ranks only for affected users
     *
     * Complexity: O(k²) where k = size of the 1-hop neighborhood,
     * typically 10-200 users vs 100k+ for the full graph.
     *
     * The hourly/daily full batch remains as a consistency safety net
     * to correct any drift from incremental approximation.
     *
     * When the neighborhood exceeds 500 users (hub nodes), the incremental
     * update is skipped and a full batch recalculation is scheduled instead
     * to prevent excessive memory usage and query time.
     *
     * @param int[] $dirtyUserIds  Users directly involved (voter + page owner).
     * @param int   $iterations    PageRank iterations (fewer needed for local convergence).
     * @param float $damping       Damping factor.
     * @return array<int, float>   Updated ranks for the neighborhood.
     */
    public function incrementalUpdate( array $dirtyUserIds, int $iterations = 5, float $damping = 0.85 ): array {

        if ( empty( $dirtyUserIds ) ) {
            return [];
        }

        // 1. Expand to 1-hop neighbors
        $neighborIds = $this->edgeRepo->getNeighborIds( $dirtyUserIds );
        $subgraphUserIds = array_unique( array_merge( $dirtyUserIds, $neighborIds ) );

        // Cap neighborhood size to prevent hub nodes from causing
        // memory-intensive incremental updates. Fall back to the daily
        // batch cron for consistency correction.
        if ( count( $subgraphUserIds ) > 500 ) {
            return [];
        }

        // 2. Fetch edges for this subgraph only (memory-bounded).
        $rawEdges = $this->edgeRepo->getEdgesForUsers( $subgraphUserIds );

        // A result at the repo's edge cap means this neighborhood is too dense
        // for a bounded in-memory incremental pass — defer to the daily batch
        // cron (same fallback posture as the >500-user bail above) rather than
        // run PageRank on a silently-truncated subgraph.
        if ( empty( $rawEdges )
            || count( $rawEdges ) >= \BCC\Trust\Core\Repositories\EdgeRepository::MAX_SUBGRAPH_EDGES ) {
            return [];
        }

        // 3. Build adjacency list (shared helper with batchCalculateAllRanks)
        [ $users, $graph, $outgoing ] = $this->buildAdjacencyList( $rawEdges );

        if ( empty( $users ) ) {
            return [];
        }

        $n = count( $users );

        // 4. Initialize ranks — use existing stored ranks as starting point
        //    (warm start converges faster than uniform init)
        //    Bulk-fetch to avoid N+1 getByUserId() calls.
        $ranks = [];
        $bulkUserInfo = $this->userInfoRepo->getBulkByUserIds( array_values( $users ) );
        foreach ( $users as $u ) {
            $stored = $bulkUserInfo[ $u ] ?? null;
            $storedRank = ( $stored && ! empty( $stored->trust_rank ) ) ? (float) $stored->trust_rank : 0.5;
            $ranks[ $u ] = $storedRank;
        }

        // 5. PageRank iterations on the subgraph
        for ( $i = 0; $i < $iterations; $i++ ) {
            $newRanks = [];
            $diff     = 0.0;

            foreach ( $users as $user ) {
                $rankSum = 0.0;

                foreach ( $graph as $other => $targets ) {
                    if ( ! isset( $targets[ $user ] ) ) {
                        continue;
                    }
                    if ( empty( $outgoing[ $other ] ) || $outgoing[ $other ] <= 0 ) {
                        continue;
                    }

                    $rankSum += $ranks[ $other ] * ( $targets[ $user ] / $outgoing[ $other ] );
                }

                $newRanks[ $user ] = ( 1 - $damping ) / $n + $damping * $rankSum;
                $diff             += abs( $newRanks[ $user ] - ( $ranks[ $user ] ?? 0.0 ) );
            }

            $ranks = $newRanks;

            if ( $diff < 0.0001 ) {
                break;
            }
        }

        // 6. Normalize within the subgraph (same compression as batch)
        $maxRank = max( $ranks );
        $minRank = min( $ranks );
        $range   = $maxRank - $minRank;
        $normalized = [];

        foreach ( $ranks as $u => $rank ) {
            $raw = ( $range > 0 ) ? ( ( $rank - $minRank ) / $range ) : 0.5;
            $normalized[ $u ] = max( 0.15, min( 0.95, $raw ) );
        }

        // 7. Persist only for the dirty users + their immediate neighbors
        foreach ( $normalized as $u => $rank ) {
            $this->userInfoRepo->updateTrustRank( (int) $u, $rank );
            wp_cache_set( 'trust_rank_' . $u, $rank, self::CACHE_GROUP, self::CACHE_TTL );
        }

        return $normalized;
    }

    /**
     * GitHub authority multiplier from a pre-fetched user_info row.
     *
     * Used by batchCalculateAllRanks() and incrementalUpdate() after a
     * bulk getBulkByUserIds() call to avoid N+1 queries.
     *
     * @param object|null $user  A row from user_info, or null.
     */
    private function getGithubAuthorityFromRow( ?object $user ): float {

        if ( ! $user || empty( $user->github_verified_at ) ) {
            return 1.0;
        }

        $score = 1.0;

        if ( ! empty( $user->github_account_age_days ) && $user->github_account_age_days > BCC_TRUST_GITHUB_AGE_THRESHOLD ) {
            $score += $this->githubAgeBoost;
        }

        if ( ! empty( $user->github_followers ) && $user->github_followers > BCC_TRUST_GITHUB_FOLLOWERS_THRESHOLD ) {
            $score += $this->githubFollowersBoost;
        }

        if ( ! empty( $user->github_public_repos ) && $user->github_public_repos > BCC_TRUST_GITHUB_REPOS_THRESHOLD ) {
            $score += $this->githubReposBoost;
        }

        if ( ! empty( $user->github_org_count ) && $user->github_org_count > 0 ) {
            $score += $this->githubOrgsBoost;
        }

        if ( ! empty( $user->github_has_verified_email ) ) {
            $score += $this->githubVerifiedBoost;
        }

        // Fraud penalty (simplified graph-level discount).
        // The canonical multi-signal fraud model lives in FraudDiscountCalculator::compute().
        // This intentionally uses a simpler formula (single threshold + flat multiplier)
        // because the graph operates on aggregate trust propagation, not individual weights.
        // @see FraudDiscountCalculator::compute() for the full additive penalty model.
        if ( ! empty( $user->fraud_score ) && (int) $user->fraud_score > $this->fraudMediumThreshold ) {
            $score *= $this->fraudPenaltyMultiplier;
        }

        return min( $score, $this->githubMaxMultiplier );
    }

    /**
     * Detect vote rings using config thresholds
     *
     * @return array<int, array<string, mixed>>
     */
    public function detectVoteRings(?int $minSize = null): array {
        $minSize = $minSize ?? $this->ringMinSize;

        $cached = wp_cache_get('vote_rings', self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        // Hard page limit prevents a full cross-product scan of the votes table
        // (1M votes × 1M votes = catastrophic at scale).  500 pairs covers any
        // realistic ring cluster while keeping memory and query time bounded.
        // Use $offset to paginate across multiple cron ticks when needed.
        //
        // Serialise cursor advance under a non-blocking advisory lock so that
        // concurrent callers (hot-path FraudDetector fallback when the admin
        // `ring_results` cache is cold) cannot both read the same offset,
        // scan the same slice twice, and lose coverage of the following slice.
        // Callers that fail to acquire the lock fall through with the current
        // cached offset (no advance, no double-scan) which is strictly safe.
        $pageLimit   = 500;
        $lockKey     = 'bcc_ring_cursor';
        $lockHeld    = \BCC\Core\DB\AdvisoryLock::acquire($lockKey, 0);

        try {
            $offset      = (int) (wp_cache_get('vote_ring_offset', self::CACHE_GROUP) ?: 0);
            $mutualVotes = $this->voteRepo->getMutualVotePairs($minSize, $pageLimit, $offset);

            if ($lockHeld) {
                // Only the lock holder advances the shared cursor. Reset when the
                // result set was smaller than the page (we've walked the tail).
                $nextOffset = (count($mutualVotes) === $pageLimit) ? $offset + $pageLimit : 0;
                wp_cache_set('vote_ring_offset', $nextOffset, self::CACHE_GROUP, self::CACHE_TTL);
            }
        } finally {
            if ($lockHeld) {
                \BCC\Core\DB\AdvisoryLock::release($lockKey);
            }
        }

        $graph = [];
        $weights = [];

        foreach ($mutualVotes as $mv) {
            $a = (int) $mv->user_a;
            $b = (int) $mv->user_b;

            $graph[$a] = $graph[$a] ?? [];
            $graph[$b] = $graph[$b] ?? [];

            $graph[$a][] = $b;
            $graph[$b][] = $a;

            $weights["{$a}_{$b}"] = (float) $mv->total_weight;
            $weights["{$b}_{$a}"] = (float) $mv->total_weight;
        }

        $visited = [];
        $components = [];

        foreach (array_keys($graph) as $user) {
            if (isset($visited[$user])) continue;

            $component = $this->bfsComponent($user, $graph, $visited);

            if (count($component) >= $minSize) {
                $strength = $this->calculateRingStrength($component, $weights);

                if ($strength > $this->ringStrengthThreshold) {
                    $components[] = [
                        'users' => $component,
                        'size' => count($component),
                        'strength' => $strength,
                        'detected_at' => current_time('mysql'),
                    ];

                    // Store pattern for ML analysis
                    $this->storeVoteRingPattern($component, $strength);

                    // Only penalise members the first time this ring is detected.
                    // A sorted hash of member IDs uniquely identifies the ring;
                    // a 30-day transient prevents re-penalising on subsequent runs.
                    $ringKey = $this->ringTransientKey('vote', $component);
                    if (false === get_transient($ringKey)) {
                        foreach ($component as $uid) {
                            $this->userInfoRepo->incrementFraudScore((int)$uid, 15, 'vote_ring_member');
                        }
                        set_transient($ringKey, 1, 30 * DAY_IN_SECONDS);
                    }
                }
            }
        }

        wp_cache_set('vote_rings', $components, self::CACHE_GROUP, self::CACHE_TTL);

        return $components;
    }

    /**
     * Detect slow-ring endorsements within a rolling time window.
     *
     * Patience-evasion patch (scale-hardening pass, 2026-05-13). The
     * existing endorsement burst gates fire on RECENT activity
     * (3-in-300s, 6-in-1h, 3-pages-in-24h). A 5-person ring at one
     * endorsement per pair per week produces dozens of mutual
     * endorsements without tripping any of them. detectEndorsementRings
     * (DFS-based) walks the FULL historical graph and catches A→B→C→A
     * cycles but is unbounded by time and misses the pair-level
     * reciprocity signature that defines the slow-ring shape.
     *
     * This detector closes that gap. Mirrors detectVoteRings exactly:
     * fetch mutual-pair primitive → build undirected graph → BFS
     * connected components → calculate ring strength → soft-flag
     * + dedupe via transient.
     *
     * Soft-flag discipline (per scale-hardening doctrine — favor
     * soft-flagging over auto-punishment initially):
     *   - Pattern stored for ML / ops review.
     *   - Audit log written so the ring is visible to operators.
     *   - NO automatic fraud_score increment (unlike detectVoteRings
     *     which adds 15 per member, or detectEndorsementRings DFS
     *     which adds 20). Operator escalates manually if real.
     *
     * Threshold tuning:
     *   - Mutual-count threshold per pair = 1 (decoupled from
     *     $minSize — slow rings are by definition low-velocity; any
     *     reciprocity in the window is signal).
     *   - Component-size threshold = $minSize default
     *     BCC_TRUST_RING_MIN_SIZE (3).
     *   - Ring strength = half the standard BCC_TRUST_RING_STRENGTH_THRESHOLD
     *     to compensate for the inherently weaker signal of paced rings.
     *     Filterable via `bcc_trust_slow_ring_strength_threshold`.
     *
     * Cron cadence: weekly (bcc_trust_weekly_slow_ring_scan in
     * CronService::scheduleAll). Lower frequency + wider window is
     * the entire point of this detector.
     *
     * @param int|null $windowDays Rolling window. Default
     *                             BCC_TRUST_SLOW_RING_WINDOW_DAYS (14).
     * @param int|null $minSize    Min component size. Default
     *                             BCC_TRUST_RING_MIN_SIZE (3).
     * @return array<int, array<string, mixed>>
     */
    public function detectSlowEndorsementRings(?int $windowDays = null, ?int $minSize = null): array {
        $windowDays = $windowDays ?? (int) BCC_TRUST_SLOW_RING_WINDOW_DAYS;
        $minSize    = $minSize ?? $this->ringMinSize;

        $cacheKey = 'slow_endorsement_rings_w' . $windowDays;
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        // Same paginated-cursor + advisory-lock pattern as detectVoteRings
        // so concurrent callers (cron + admin-dashboard cold-cache fallback)
        // cannot both read the same offset, scan the same slice twice, and
        // lose coverage of the following slice.
        $pageLimit  = 500;
        $lockKey    = 'bcc_slow_endorse_ring_cursor';
        $lockHeld   = \BCC\Core\DB\AdvisoryLock::acquire($lockKey, 0);
        $offsetKey  = 'slow_endorsement_ring_offset_w' . $windowDays;

        try {
            $offset  = (int) (wp_cache_get($offsetKey, self::CACHE_GROUP) ?: 0);
            // mutual_count threshold = 1: any reciprocity in the window
            // surfaces; component-size gates further. (Vote rings use
            // $minSize for both pair- and component-thresholds; for
            // slow endorsement rings we decouple them.)
            $mutuals = $this->endorsementRepo->getMutualEndorsePairsInWindow(
                $windowDays,
                1,
                $pageLimit,
                $offset
            );

            if ($lockHeld) {
                $nextOffset = (count($mutuals) === $pageLimit) ? $offset + $pageLimit : 0;
                wp_cache_set($offsetKey, $nextOffset, self::CACHE_GROUP, self::CACHE_TTL);
            }
        } finally {
            if ($lockHeld) {
                \BCC\Core\DB\AdvisoryLock::release($lockKey);
            }
        }

        $graph   = [];
        $weights = [];

        foreach ($mutuals as $mv) {
            $a = (int) $mv->user_a;
            $b = (int) $mv->user_b;

            $graph[$a] = $graph[$a] ?? [];
            $graph[$b] = $graph[$b] ?? [];

            $graph[$a][] = $b;
            $graph[$b][] = $a;

            $weights["{$a}_{$b}"] = (float) ($mv->total_weight ?? 0);
            $weights["{$b}_{$a}"] = (float) ($mv->total_weight ?? 0);
        }

        // Half the burst-ring threshold: slow rings are by construction
        // lower-velocity, so the strength score is lower. Filterable for
        // ops tuning when production data informs the calibration.
        $strengthThreshold = (float) apply_filters(
            'bcc_trust_slow_ring_strength_threshold',
            $this->ringStrengthThreshold * 0.5
        );

        $visited    = [];
        $components = [];

        foreach (array_keys($graph) as $user) {
            if (isset($visited[$user])) {
                continue;
            }

            $component = $this->bfsComponent($user, $graph, $visited);

            if (count($component) >= $minSize) {
                $strength = $this->calculateRingStrength($component, $weights);

                if ($strength > $strengthThreshold) {
                    $components[] = [
                        'users'       => $component,
                        'size'        => count($component),
                        'strength'    => $strength,
                        'window_days' => $windowDays,
                        'type'        => 'slow_endorsement_ring',
                        'detected_at' => current_time('mysql'),
                    ];

                    // SOFT FLAG: store the pattern + log, do NOT
                    // increment fraud_score. 30-day transient prevents
                    // re-storing the same ring on every weekly tick.
                    $ringKey = $this->ringTransientKey('slow_endorse', $component);
                    if (false === get_transient($ringKey)) {
                        $this->storeSlowEndorsementRingPattern($component, $strength, $windowDays);

                        \BCC\Core\Log\Logger::info(
                            '[bcc-trust] slow_endorsement_ring detected (soft-flag)',
                            [
                                'size'        => count($component),
                                'strength'    => round($strength, 2),
                                'window_days' => $windowDays,
                                'users'       => $component,
                            ]
                        );

                        set_transient($ringKey, 1, 30 * DAY_IN_SECONDS);
                    }
                }
            }
        }

        wp_cache_set($cacheKey, $components, self::CACHE_GROUP, self::CACHE_TTL);

        return $components;
    }

    /**
     * Store slow-ring pattern for ML analysis + operator review surface.
     * Distinct pattern_type from 'vote_ring' / 'endorsement_ring' so
     * admin reports don't collide and the slow-ring case is its own
     * observable signal class.
     *
     * @param int[] $ring
     */
    private function storeSlowEndorsementRingPattern(array $ring, float $strength, int $windowDays): void {
        $patternData = [
            'users'       => $ring,
            'strength'    => $strength,
            'size'        => count($ring),
            'window_days' => $windowDays,
            'threshold'   => $this->ringStrengthThreshold * 0.5,
        ];

        try {
            $this->patternRepo->storePattern(
                0, // system-level pattern, no specific user
                'slow_endorsement_ring',
                $patternData,
                min(1.0, $strength / 10),
                date('Y-m-d H:i:s', strtotime('+30 days'))
            );
        } catch (\Exception $e) {
            \BCC\Core\Log\Logger::error(
                '[bcc-trust] [BCC TrustGraph] storeSlowEndorsementRingPattern insert failed: ',
                ['detail' => $e->getMessage()]
            );
        }
    }

    /**
     * Store vote ring pattern for ML analysis
     *
     * @param int[] $ring
     */
    private function storeVoteRingPattern(array $ring, float $strength): void {
        $patternData = [
            'users' => $ring,
            'strength' => $strength,
            'size' => count($ring),
            'threshold' => $this->ringStrengthThreshold
        ];

        try {
            $this->patternRepo->storePattern(
                0, // system-level pattern, no specific user
                'vote_ring',
                $patternData,
                min(1.0, $strength / 10),
                date('Y-m-d H:i:s', strtotime('+30 days'))
            );
        } catch (\Exception $e) {
            \BCC\Core\Log\Logger::error('[bcc-trust] [BCC TrustGraph] storeVoteRingPattern insert failed: ', ['detail' => $e->getMessage()]);
        }
    }

    /**
     * Build a deterministic transient key for a detected ring.
     *
     * Sorting the member IDs before hashing ensures the same set of users
     * always produces the same key regardless of discovery order.
     */
    /**
     * @param int[] $members
     */
    private function ringTransientKey(string $type, array $members): string {
        $ids = array_map('intval', $members);
        sort($ids);
        return 'bcc_ring_' . $type . '_' . md5(implode(',', $ids));
    }

    /**
     * @param array<int, int[]>    $graph   Adjacency list: userId => list of neighbor user IDs.
     * @param array<int, bool>     $visited Visited set, modified by reference.
     * @return int[]
     */
    private function bfsComponent(int $start, array $graph, array &$visited): array {
        $queue = [$start];
        $component = [];

        while (!empty($queue)) {
            $current = array_shift($queue);
            if (isset($visited[$current])) continue;

            $visited[$current] = true;
            $component[] = $current;

            foreach ($graph[$current] ?? [] as $neighbor) {
                if (!isset($visited[(int) $neighbor])) {
                    $queue[] = (int) $neighbor;
                }
            }
        }

        return $component;
    }

    /**
     * @param int[]                $ring
     * @param array<string, float> $weights
     */
    private function calculateRingStrength(array $ring, array $weights): float {
        $totalWeight = 0.0;
        $edges = 0;

        $count = count($ring);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $key = $ring[$i] . '_' . $ring[$j];
                if (isset($weights[$key])) {
                    $totalWeight += (float) $weights[$key];
                    $edges++;
                }
            }
        }

        if ($count < 2) return 0.0;

        $possibleEdges = ($count * ($count - 1)) / 2;
        $density = $possibleEdges > 0 ? ($edges / $possibleEdges) : 0.0;
        $avgWeight = $edges > 0 ? ($totalWeight / $edges) : 0.0;

        return $avgWeight * $density * $count;
    }

    /**
     * Detect circular endorsement chains via DFS cycle detection.
     *
     * A→B→C→A style endorsement rings are invisible to detectVoteRings()
     * because that method only examines mutual vote pairs, not endorsements.
     * This method builds a directed endorser→page_owner graph and uses DFS
     * to find back edges (cycles), penalising all members found.
     *
     * @return array<int, array<string, mixed>>
     */
    public function detectEndorsementRings(int $minSize = 3): array {
        $cached = wp_cache_get('endorsement_rings', self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        // Build directed graph: endorser_user_id → page_owner_id
        // LIMIT prevents unbounded result sets on large deployments.
        $endorsements = $this->voteRepo->getEndorsementEdges(10000);

        $graph = [];
        foreach ($endorsements as $e) {
            $from = (int) $e->endorser_user_id;
            $to   = (int) $e->page_owner_id;
            if ($from === $to) {
                continue; // skip self-loops
            }
            $graph[$from][] = $to;
        }

        $visited  = [];
        $recStack = [];
        $cycles   = [];

        foreach (array_keys($graph) as $node) {
            if (!isset($visited[$node])) {
                $this->dfsCycleDetect((int) $node, $graph, $visited, $recStack, [], $cycles);
            }
        }

        // Deduplicate cycles (same members, different starting point)
        $seen  = [];
        $rings = [];
        foreach ($cycles as $cycle) {
            if (count($cycle) < $minSize) {
                continue;
            }
            $key = implode(',', array_unique(array_map('intval', $cycle)));
            $sortedKey = implode(',', array_unique(array_map('intval', $cycle)));
            sort($cycle);
            $sortedKey = implode(',', $cycle);
            if (isset($seen[$sortedKey])) {
                continue;
            }
            $seen[$sortedKey] = true;

            $rings[] = [
                'users'       => $cycle,
                'size'        => count($cycle),
                'type'        => 'endorsement_ring',
                'detected_at' => current_time('mysql'),
            ];

            // Only penalise members the first time this ring is detected.
            $ringKey = $this->ringTransientKey('endorse', $cycle);
            if (false === get_transient($ringKey)) {
                foreach ($cycle as $uid) {
                    $this->userInfoRepo->incrementFraudScore((int) $uid, 20, 'endorsement_ring');
                }
                set_transient($ringKey, 1, 30 * DAY_IN_SECONDS);
            }
        }

        wp_cache_set('endorsement_rings', $rings, self::CACHE_GROUP, self::CACHE_TTL);

        return $rings;
    }

    /**
     * Recursive DFS with recursion-stack cycle extraction.
     *
     * @param array<int, list<int>> $graph
     * @param array<int, bool> $visited
     * @param array<int, bool> $recStack
     * @param list<int> $path
     * @param list<list<int>> $cycles
     */
    private function dfsCycleDetect(
        int $node,
        array $graph,
        array &$visited,
        array &$recStack,
        array $path,
        array &$cycles
    ): void {
        $visited[$node]  = true;
        $recStack[$node] = true;
        $path[]          = $node;

        foreach ($graph[$node] ?? [] as $neighbor) {
            $neighbor = (int) $neighbor;

            if (!isset($visited[$neighbor])) {
                $this->dfsCycleDetect($neighbor, $graph, $visited, $recStack, $path, $cycles);
            } elseif (isset($recStack[$neighbor])) {
                // Back edge found — extract the cycle
                $cycleStart = array_search($neighbor, $path, true);
                if ($cycleStart !== false) {
                    $cycles[] = array_slice($path, (int) $cycleStart);
                }
            }
        }

        unset($recStack[$node]);
    }

    /**
     * Get suspicious clusters — combines vote rings AND endorsement rings.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSuspiciousClusters(int $minSize = 3): array {
        return array_merge(
            $this->detectVoteRings($minSize),
            $this->detectEndorsementRings($minSize)
        );
    }

}