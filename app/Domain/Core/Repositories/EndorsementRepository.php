<?php
/**
 * Endorsement Repository
 * 
 * Handles endorsement operations with PageScore integration
 * Uses config constants for thresholds and weights
 * 
 * @package BCC\Trust\Core\Repositories
 * @version 2.1.0
 */

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) exit;

use Exception;
use BCC\Trust\Core\Exceptions\RepositoryException;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Core\Repositories\EdgeRepository;

/**
 * Row + aggregate shapes returned by bcc_trust_endorsements reads.
 *
 * @phpstan-type EndorsementRow object{
 *   id: int|numeric-string,
 *   endorser_user_id: int|numeric-string,
 *   page_id: int|numeric-string,
 *   context: string,
 *   weight: float|numeric-string,
 *   base_weight: float|numeric-string,
 *   vesting_stage: int|numeric-string,
 *   fraud_score_at_endorsement: int|numeric-string|null,
 *   reason: string|null,
 *   status: int|numeric-string,
 *   created_at: string
 * }
 * @phpstan-type EndorsementWithEndorser object{
 *   id: int|numeric-string,
 *   endorser_user_id: int|numeric-string,
 *   page_id: int|numeric-string,
 *   context: string,
 *   weight: float|numeric-string,
 *   base_weight: float|numeric-string,
 *   vesting_stage: int|numeric-string,
 *   fraud_score_at_endorsement: int|numeric-string|null,
 *   reason: string|null,
 *   status: int|numeric-string,
 *   created_at: string,
 *   endorser_name: string|null
 * }
 * @phpstan-type EndorsementWithPage object{
 *   id: int|numeric-string,
 *   endorser_user_id: int|numeric-string,
 *   page_id: int|numeric-string,
 *   context: string,
 *   weight: float|numeric-string,
 *   base_weight: float|numeric-string,
 *   vesting_stage: int|numeric-string,
 *   fraud_score_at_endorsement: int|numeric-string|null,
 *   reason: string|null,
 *   status: int|numeric-string,
 *   created_at: string,
 *   page_title: string|null
 * }
 * @phpstan-type TopEndorserRow object{
 *   endorser_user_id: int|numeric-string,
 *   count: int|numeric-string,
 *   total_weight: float|numeric-string
 * }
 * @phpstan-type EndorsementFraudRow object{
 *   weight: float|numeric-string,
 *   base_weight: float|numeric-string,
 *   created_at: string,
 *   endorser_user_id: int|numeric-string,
 *   fraud_score_at_endorsement: int|numeric-string|null,
 *   current_fraud_score: int|numeric-string
 * }
 */
class EndorsementRepository {

    /** Cache group for endorsement data. */
    private const CACHE_GROUP = 'bcc_trust_endorsements';

    /** TTL for endorsement lists (HOT — changes on every endorse/revoke). */
    private const CACHE_TTL = 60;

    /** Explicit column list for bcc_trust_endorsements table. */
    private const COLUMNS = 'id, endorser_user_id, page_id, context, weight, base_weight, vesting_stage, fraud_score_at_endorsement, reason, status, created_at';

    private string $table;
    private EdgeRepository $edgeRepo;

    public function __construct() {
        $this->table = \BCC\Trust\Core\Database\TableRegistry::endorsements();
        $this->edgeRepo = new EdgeRepository();
    }

    // ── Cache helpers ────────────────────────────────────────────────────

    /**
     * Get the current generation counter for a page's endorsement cache.
     */
    private function getGeneration(int $pageId): int {
        $genKey = "endorsement_gen:{$pageId}";
        $gen = wp_cache_get($genKey, self::CACHE_GROUP);
        if ($gen === false) {
            $gen = 1;
            wp_cache_set($genKey, $gen, self::CACHE_GROUP, 0);
        }
        return (int) $gen;
    }

    /**
     * Invalidate all endorsement caches for a page.
     *
     * Called by CacheManager::invalidatePageCaches() on endorsement
     * add/remove, and by any other mutation path that affects endorsements.
     */
    public function invalidateEndorsementCache(int $pageId): void {
        $genKey = "endorsement_gen:{$pageId}";
        $result = wp_cache_incr($genKey, 1, self::CACHE_GROUP);
        if ($result === false) {
            wp_cache_set($genKey, 2, self::CACHE_GROUP, 0);
        }
    }

    /**
     * Get specific endorsement
     *
     * @phpstan-return EndorsementRow|null
     */
    public function get(int $endorserUserId, int $pageId, string $context = 'general'): ?object {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table}
                 WHERE endorser_user_id = %d
                 AND page_id = %d
                 AND context = %s
                 AND status = 1",
                $endorserUserId,
                $pageId,
                $context
            )
        );
    }

    /**
     * Create endorsement (or re-activate a soft-deleted one).
     *
     * IMPORTANT: This method uses SELECT … FOR UPDATE and therefore MUST be
     * called from inside a TransactionManager::run() closure.  It no longer
     * opens its own transaction to avoid nested-transaction bugs (C2).
     *
     * @param float $weight  Already-capped weight from the Service layer.
     */
    public function create(int $endorserUserId, int $pageId, string $context = 'general', float $weight = 3.0, ?string $reason = null, int $fraudScoreAtEndorsement = 0, float $baseWeight = 0.0): int {
        // Runtime guard: SELECT … FOR UPDATE below requires an active transaction.
        if (!TransactionManager::isInRunTransaction()) {
            throw new Exception(
                'EndorsementRepository::create() must be called inside TransactionManager::run(). '
                . 'SELECT … FOR UPDATE without a transaction will not lock correctly.'
            );
        }

        global $wpdb;

        $weight = min(BCC_TRUST_MAX_ENDORSE_WEIGHT, $weight);

        $existing = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT " . self::COLUMNS . " FROM {$this->table}
                 WHERE endorser_user_id = %d
                 AND page_id = %d
                 AND context = %s
                 FOR UPDATE",
                $endorserUserId,
                $pageId,
                $context
            )
        );

        if ($existing) {
            $result = $wpdb->update(
                $this->table,
                [
                    'status'                    => 1,
                    'weight'                    => $weight,
                    'base_weight'               => $baseWeight,
                    'vesting_stage'             => 0,
                    'fraud_score_at_endorsement' => $fraudScoreAtEndorsement,
                    'reason'                    => $reason,
                    'created_at'                => current_time('mysql'),
                ],
                ['id' => $existing->id],
                ['%d', '%f', '%f', '%d', '%d', '%s', '%s'],
                ['%d']
            );

            if ($result === false) {
                throw new Exception('Failed to re-activate endorsement row');
            }

            return (int) $existing->id;
        }

        $result = $wpdb->insert(
            $this->table,
            [
                'endorser_user_id'          => $endorserUserId,
                'page_id'                   => $pageId,
                'context'                   => $context,
                'weight'                    => $weight,
                'base_weight'               => $baseWeight,
                'fraud_score_at_endorsement' => $fraudScoreAtEndorsement,
                'reason'                    => $reason,
                'status'                    => 1,
                'created_at'                => current_time('mysql'),
            ],
            ['%d', '%d', '%s', '%f', '%f', '%d', '%s', '%d', '%s']
        );

        if ($result === false) {
            throw new Exception('Failed to insert endorsement row');
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Delete endorsement (soft delete) and recalculate edge weight.
     *
     * IMPORTANT: This method updates both the endorsements table and the
     * materialized edge table. It MUST be called inside TransactionManager::run()
     * so both writes are atomic — a failed edge recalculation will roll back
     * the soft-delete rather than leaving stale edge weights.
     *
     * @return bool True if an active row was soft-deleted; false if no
     *              active row existed (concurrent revoke raced us to it).
     *              Callers MUST gate any score-bonus subtraction on this
     *              return value to avoid double-decrementing.
     * @throws RepositoryException on database failure
     * @throws Exception if called outside a transaction
     */
    public function delete(int $endorserUserId, int $pageId, string $context = 'general'): bool {
        // Runtime guard: both the soft-delete and edge recalculation must
        // be atomic. Without a transaction the edge could become stale if
        // the recalculation fails after the soft-delete succeeds.
        if (!TransactionManager::isInRunTransaction()) {
            throw new \Exception(
                'EndorsementRepository::delete() must be called inside TransactionManager::run(). '
                . 'Soft-delete + edge recalculation must be atomic.'
            );
        }

        global $wpdb;

        // SECURITY: scope to status=1 so a second concurrent revoke sees
        // affected=0 and must not re-subtract the bonus. Without this
        // filter, two revokes both succeed and the caller double-subtracts
        // endorsement_bonus, drifting it negative.
        $result = $wpdb->update(
            $this->table,
            ['status' => 0],
            [
                'endorser_user_id' => $endorserUserId,
                'page_id'          => $pageId,
                'context'          => $context,
                'status'           => 1,
            ],
            ['%d'],
            ['%d', '%d', '%s', '%d']
        );

        if ($result === false) {
            throw new RepositoryException('EndorsementRepository::delete failed: ' . $wpdb->last_error);
        }

        $affected = (int) $result;
        if ($affected === 0) {
            // No active row — another worker already revoked this
            // endorsement in a concurrent transaction. Skip downstream
            // work entirely so the caller doesn't double-subtract.
            return false;
        }

        // Update materialized edge table (inside same transaction).
        $scoresTable = \BCC\Trust\Core\Database\TableRegistry::scores();
        $pageOwnerId = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT page_owner_id FROM {$scoresTable} WHERE page_id = %d",
            $pageId
        ) );
        if ( $pageOwnerId && $pageOwnerId !== $endorserUserId ) {
            $this->edgeRepo->recalculateEndorsementEdge( $endorserUserId, $pageOwnerId );
        }

        return true;
    }

    /**
     * Get all endorsements for a page
     *
     * @return object[]
     * @phpstan-return list<EndorsementWithEndorser>
     */
    public function getAllForPage(int $pageId, int $limit = 50): array {
        $gen      = $this->getGeneration($pageId);
        $cacheKey = "endorsements:{$pageId}:{$gen}:{$limit}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return $cached;
        }

        global $wpdb;

        $result = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.id, e.endorser_user_id, e.page_id, e.context, e.weight, e.base_weight, e.vesting_stage, e.fraud_score_at_endorsement, e.reason, e.status, e.created_at, u.display_name as endorser_name
                 FROM {$this->table} e
                 LEFT JOIN {$wpdb->users} u ON e.endorser_user_id = u.ID
                 WHERE e.page_id = %d
                 AND e.status = 1
                 ORDER BY e.created_at DESC
                 LIMIT %d",
                $pageId,
                $limit
            )
        );

        $result = $result ?: [];
        wp_cache_set($cacheKey, $result, self::CACHE_GROUP, self::CACHE_TTL);
        return $result;
    }

    /**
     * Get endorsements given by user
     *
     * @return object[]
     * @phpstan-return list<EndorsementWithPage>
     */
    public function getByEndorser(int $endorserUserId, int $limit = 20): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.id, e.endorser_user_id, e.page_id, e.context, e.weight, e.base_weight, e.vesting_stage, e.fraud_score_at_endorsement, e.reason, e.status, e.created_at, p.post_title as page_title
                 FROM {$this->table} e
                 LEFT JOIN {$wpdb->posts} p ON e.page_id = p.ID
                 WHERE e.endorser_user_id = %d
                 AND e.status = 1
                 ORDER BY e.created_at DESC
                 LIMIT %d",
                $endorserUserId,
                $limit
            )
        );
    }

    /**
     * Count endorsements for a page
     */
    public function countForPage(int $pageId): int {
        $gen      = $this->getGeneration($pageId);
        $cacheKey = "endorsement_count:{$pageId}:{$gen}";
        $cached   = wp_cache_get($cacheKey, self::CACHE_GROUP);
        if ($cached !== false) {
            return (int) $cached;
        }

        global $wpdb;

        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                 WHERE page_id = %d
                 AND status = 1",
                $pageId
            )
        );

        wp_cache_set($cacheKey, $count, self::CACHE_GROUP, self::CACHE_TTL);
        return $count;
    }

    /**
     * Batched: lifetime count of endorsements RECEIVED per user, where
     * "received" means endorsements on any `peepso-page` post the user
     * owns (`peepso_page_members.pm_user_status = 'member_owner'`).
     *
     * One JOIN scan replaces N×(M+1) sequential queries (per-user owned-
     * pages lookup × per-page countForPage). Powers the /members
     * directory back-of-card endorsements_received slot.
     *
     * Users who own no pages — or whose pages have no endorsements —
     * are absent from the map; callers default to 0.
     *
     * @param int[] $userIds Bounded by caller (directory per_page cap).
     * @return array<int, int> user_id → endorsement count across all owned pages
     */
    public function getReceivedCountsForUsers(array $userIds): array {
        if ($userIds === []) {
            return [];
        }

        $clean = [];
        foreach ($userIds as $id) {
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

        /** @var list<array{user_id: string, c: string}> $rows */
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT pm.pm_user_id AS user_id, COUNT(*) AS c
                   FROM {$wpdb->prefix}peepso_page_members pm
                   INNER JOIN {$this->table} e ON e.page_id = pm.pm_page_id
                  WHERE pm.pm_user_status = 'member_owner'
                    AND pm.pm_user_id IN ({$placeholders})
                    AND e.status = 1
                  GROUP BY pm.pm_user_id",
                ...$idList
            ),
            ARRAY_A
        );

        $out = [];
        foreach (($rows ?: []) as $row) {
            $out[(int) $row['user_id']] = (int) $row['c'];
        }
        return $out;
    }

    /**
     * Count endorsements given by user
     */
    public function countByEndorser(int $endorserUserId): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                 WHERE endorser_user_id = %d
                 AND status = 1",
                $endorserUserId
            )
        );
    }

    /**
     * Check if user has endorsed page
     */
    public function hasEndorsed(int $endorserUserId, int $pageId, ?string $context = null): bool {
        global $wpdb;

        $sql = "SELECT COUNT(*) FROM {$this->table}
                WHERE endorser_user_id = %d
                AND page_id = %d
                AND status = 1";
        
        $params = [$endorserUserId, $pageId];

        if ($context) {
            $sql .= " AND context = %s";
            $params[] = $context;
        }

        return (bool) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    /**
     * Get endorsement weight for a user tier
     */
    public function getEndorsementWeight(string $tier): float {
        $weights = [
            'elite'   => BCC_TRUST_ENDORSE_ELITE,
            'trusted' => BCC_TRUST_ENDORSE_TRUSTED,
            'neutral' => BCC_TRUST_ENDORSE_NEUTRAL,
            'caution' => BCC_TRUST_ENDORSE_CAUTION,
            'risky'   => BCC_TRUST_ENDORSE_RISKY,
        ];
        return $weights[$tier] ?? BCC_TRUST_ENDORSE_NEUTRAL;
    }

    /**
     * Count distinct endorsers for a page today.
     */
    public function countDistinctEndorsersToday(int $pageId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT endorser_user_id)
             FROM {$this->table}
             WHERE page_id = %d
               AND status = 1
               AND created_at >= CURDATE()",
            $pageId
        ));
    }

    /**
     * Count distinct endorsers for a page within a time window (seconds).
     */
    public function countDistinctEndorsersInWindow(int $pageId, int $windowSeconds): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT endorser_user_id)
             FROM {$this->table}
             WHERE page_id = %d
               AND status = 1
               AND created_at >= DATE_SUB(NOW(), INTERVAL %d SECOND)",
            $pageId,
            $windowSeconds
        ));
    }

    /**
     * Count distinct pages owned by a specific owner that a given endorser
     * has endorsed within a time window. Used for cross-page cluster detection.
     */
    public function countEndorserPagesForOwnerInWindow(
        int $endorserUserId,
        int $pageOwnerId,
        int $windowSeconds
    ): int {
        global $wpdb;

        $scoresTable = \BCC\Trust\Core\Database\TableRegistry::scores();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT e.page_id)
             FROM {$this->table} e
             INNER JOIN {$scoresTable} s ON s.page_id = e.page_id
             WHERE e.endorser_user_id = %d
               AND s.page_owner_id = %d
               AND e.status = 1
               AND e.created_at >= DATE_SUB(NOW(), INTERVAL %d SECOND)
             LIMIT 10",
            $endorserUserId,
            $pageOwnerId,
            $windowSeconds
        ));
    }

    /**
     * Get total active endorsement weight sum for a page.
     */
    public function sumActiveWeight(int $pageId): float
    {
        global $wpdb;

        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(weight), 0) FROM {$this->table}
             WHERE page_id = %d AND status = 1",
            $pageId
        ));
    }

    /**
     * Count endorsements given by a user within the last N seconds (rate limiting).
     */
    public function countRecentByEndorser(int $endorserUserId, int $windowSeconds): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
             WHERE endorser_user_id = %d
               AND status = 1
               AND created_at >= DATE_SUB(NOW(), INTERVAL %d SECOND)",
            $endorserUserId,
            $windowSeconds
        ));
    }

    /**
     * Count distinct pages endorsed by a user today (cross-page rate limit).
     *
     * Prevents a single user from endorsing an unlimited number of pages
     * per day, which limits the surface area for coordinated endorsement
     * farming across multiple target pages.
     */
    public function countDistinctPagesEndorsedToday(int $endorserUserId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT page_id)
             FROM {$this->table}
             WHERE endorser_user_id = %d
               AND status = 1
               AND created_at >= CURDATE()",
            $endorserUserId
        ));
    }

    /**
     * Get endorser user IDs for a page within a time window.
     *
     * @return int[]
     */
    public function getRecentEndorserIds(int $pageId, int $windowSeconds): array
    {
        global $wpdb;

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT endorser_user_id
             FROM {$this->table}
             WHERE page_id = %d
               AND status = 1
               AND created_at >= DATE_SUB(NOW(), INTERVAL %d SECOND)",
            $pageId,
            $windowSeconds
        )));
    }

    /**
     * Get average endorsement weight given by a user (across all pages).
     */
    public function getAverageWeightByEndorser(int $endorserUserId): float
    {
        global $wpdb;

        $avg = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(weight) FROM {$this->table}
             WHERE endorser_user_id = %d AND status = 1",
            $endorserUserId
        ));

        return $avg !== null ? (float) $avg : 0.0;
    }

    /**
     * Count endorsers in a cluster (sharing same set of endorsed pages).
     */
    public function countClusterEndorsers(int $pageId, int $endorserUserId): int
    {
        global $wpdb;

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT e2.endorser_user_id)
             FROM {$this->table} e1
             JOIN {$this->table} e2 ON e1.page_id = e2.page_id
             WHERE e1.endorser_user_id = %d
               AND e2.endorser_user_id != %d
               AND e1.status = 1
               AND e2.status = 1
               AND e2.page_id = %d",
            $endorserUserId,
            $endorserUserId,
            $pageId
        ));
    }

    /**
     * Graduate endorsements from one vesting stage to the next.
     *
     * @return int Number of rows updated.
     */
    public function graduateVestingStage(
        int $fromStage,
        int $toStage,
        float $fromFactor,
        float $toFactor,
        string $now,
        int $ageDays
    ): int {
        global $wpdb;

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table}
             SET weight        = weight / %f * %f,
                 vesting_stage = %d
             WHERE status = 1
               AND vesting_stage = %d
               AND created_at <= DATE_SUB(%s, INTERVAL %d DAY)
             LIMIT 500",
            $fromFactor,
            $toFactor,
            $toStage,
            $fromStage,
            $now,
            $ageDays
        ));

        return ($result !== false) ? (int) $result : 0;
    }

    /**
     * Flag page scores for recalculation where endorsements just graduated.
     */
    public function flagScoresForGraduatedEndorsements(int $stage, string $now, int $ageDays): void
    {
        global $wpdb;

        $scoresTable = \BCC\Trust\Core\Database\TableRegistry::scores();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$scoresTable} s
             INNER JOIN {$this->table} e ON e.page_id = s.page_id
             SET s.recalculate_required = 1
             WHERE e.status = 1
               AND e.vesting_stage = %d
               AND e.created_at <= DATE_SUB(%s, INTERVAL %d DAY)",
            $stage,
            $now,
            $ageDays
        ));
    }

    /**
     * Get page IDs flagged for recalculation (for cache invalidation).
     *
     * @return int[]
     */
    public function getFlaggedPageIds(int $limit = 500): array
    {
        global $wpdb;

        $scoresTable = \BCC\Trust\Core\Database\TableRegistry::scores();

        return array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT s.page_id FROM {$scoresTable} s
             WHERE s.recalculate_required = 1
             LIMIT %d",
            $limit
        )));
    }

    /**
     * Count reciprocal endorsements between an endorser and a page owner.
     */
    public function countReciprocal(int $endorserUserId, int $pageOwnerId): int
    {
        global $wpdb;

        $scoresTable = \BCC\Trust\Core\Database\TableRegistry::scores();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} e
             JOIN {$scoresTable} s ON e.page_id = s.page_id
             WHERE e.endorser_user_id = %d
               AND s.page_owner_id = %d
               AND e.status = 1",
            $pageOwnerId,
            $endorserUserId
        ));
    }

    /**
     * Soft-delete all endorsements made by a given endorser.
     *
     * Sets status = 0 for all rows where endorser_user_id matches.
     * Used during user deletion to deactivate their endorsements.
     *
     * @param int $endorserUserId
     * @return int|false Number of rows updated, or false on failure.
     */
    public function softDeleteByEndorser(int $endorserUserId)
    {
        global $wpdb;

        return $wpdb->update(
            $this->table,
            ['status' => 0],
            ['endorser_user_id' => $endorserUserId],
            ['%d'],
            ['%d']
        );
    }

    /**
     * Acquire a MySQL advisory lock for serialising endorsement caps.
     *
     * Used by the service layer to gate per-user and per-page cap checks so
     * that two concurrent COUNT-then-INSERT flows cannot both observe
     * `count = limit - 1` and both succeed past the limit.
     *
     * Advisory locks are session-scoped (held by the wpdb connection) and
     * persist across InnoDB transactions. They MUST be released by the same
     * connection via releaseConcurrencyLock(); otherwise they only release
     * when the connection closes (end of request).
     *
     * @param string $name    Lock name (max 64 chars). Caller-namespaced.
     * @param int    $timeout Seconds to wait before giving up.
     * @return bool True if the lock was acquired, false on timeout/error.
     */
    public function acquireConcurrencyLock(string $name, int $timeout = 5): bool
    {
        global $wpdb;

        // GET_LOCK truncates names beyond 64 chars in MySQL 5.7+; clamp here
        // so we can't silently collide on long-tail names.
        $safeName = substr($name, 0, 64);

        $result = $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $safeName,
            max(0, $timeout)
        ));

        // Returns 1 on success, 0 on timeout, NULL on error.
        return $result === '1' || $result === 1;
    }

    /**
     * Release a MySQL advisory lock previously acquired on this connection.
     *
     * Always invoked from a finally{} block so that exceptions inside the
     * critical section cannot leak the lock to the rest of the request.
     *
     * @param string $name Lock name passed to acquireConcurrencyLock().
     */
    public function releaseConcurrencyLock(string $name): void
    {
        global $wpdb;

        $safeName = substr($name, 0, 64);

        $wpdb->get_var($wpdb->prepare(
            'SELECT RELEASE_LOCK(%s)',
            $safeName
        ));
    }

    /**
     * Get active endorsements for a page with each endorser's current fraud score.
     *
     * Used by recalculateScore() to recompute endorsement_bonus from scratch
     * with retroactive fraud discounts.
     *
     * @param int $pageId
     * @return object[]  Array of objects with weight, base_weight, created_at,
     *                   endorser_user_id, fraud_score_at_endorsement, current_fraud_score.
     * @phpstan-return list<EndorsementFraudRow>
     */
    public function getActiveWithFraudScoresForPage( int $pageId ): array {
        global $wpdb;

        $userInfoTable = \BCC\Trust\Core\Database\TableRegistry::userInfo();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT e.weight, e.base_weight, e.created_at, e.endorser_user_id,
                    e.fraud_score_at_endorsement,
                    COALESCE(ui.fraud_score, 0) AS current_fraud_score
             FROM {$this->table} e
             LEFT JOIN {$userInfoTable} ui ON e.endorser_user_id = ui.user_id
             WHERE e.page_id = %d AND e.status = 1",
            $pageId
        ) ) ?: [];
    }

    /**
     * Get mutual endorse pairs within a rolling time window.
     *
     * Slow-ring endorsement detection primitive (scale-hardening pass,
     * 2026-05-13). Mirrors the exact shape of
     * {@see VoteRepository::getMutualVotePairs} but joins the
     * endorsements table on both sides of the reciprocity and filters
     * each side by `created_at > NOW() - INTERVAL N DAY`.
     *
     * What it finds: pairs (A, B) where A endorsed a page owned by B
     * AND B endorsed a page owned by A AND both endorsements landed
     * inside the rolling window. The COUNT(*) tracks how many such
     * reciprocations exist per pair; the SUM(weight) tracks the total
     * endorsement weight flowing between them.
     *
     * Why slow-ring is its own primitive: the existing burst gates
     * (3-in-300s, 6-in-1h, 3-pages-in-24h in EndorsementService) all
     * fire on RECENT activity. A 5-person ring at 1 endorsement/day
     * for 7 days produces 35 mutual endorsements without tripping any
     * temporal gate. Status=1 + the rolling-window filter is the
     * `detectVoteRings` analogue tuned for that patience-evasion shape.
     *
     * Bounded: `LIMIT %d OFFSET %d` (caller default 500/0); the
     * GROUP BY collapses per-(user_a, user_b) pair; the time-window
     * + status=1 filters keep the JOIN bounded.
     *
     * Indexes used: `bcc_trust_endorsements (status, created_at)`
     * composite + status idx; `bcc_page_read_model (page_id)` PK on
     * the join key.
     *
     * @param int $windowDays Rolling window. Default 14 — see
     *                        BCC_TRUST_SLOW_RING_WINDOW_DAYS rationale.
     * @param int $minSize    Minimum reciprocations per pair to surface.
     *                        Default 1 — any reciprocity in the window
     *                        is signal; the ring-component-size threshold
     *                        gates further in TrustGraph.
     * @param int $pageLimit  Max pairs per call.
     * @param int $offset     Cursor for paginated cron walks.
     * @return object[]
     * @phpstan-return list<object{user_a: int|numeric-string, user_b: int|numeric-string, mutual_count: int|numeric-string, total_weight: float|numeric-string}>
     */
    public function getMutualEndorsePairsInWindow(
        int $windowDays = 14,
        int $minSize = 1,
        int $pageLimit = 500,
        int $offset = 0
    ): array {
        global $wpdb;

        $scoresTable = \BCC\Trust\Core\Database\TableRegistry::scores();

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT
                e1.endorser_user_id AS user_a,
                e2.endorser_user_id AS user_b,
                COUNT(*) AS mutual_count,
                SUM(e1.weight + e2.weight) AS total_weight
             FROM {$this->table} e1
             JOIN {$scoresTable} s1 ON e1.page_id = s1.page_id
             JOIN {$this->table} e2 ON e2.endorser_user_id = s1.page_owner_id
             JOIN {$scoresTable} s2 ON e2.page_id = s2.page_id
             WHERE e1.status = 1
               AND e2.status = 1
               AND s2.page_owner_id = e1.endorser_user_id
               AND e1.endorser_user_id != e2.endorser_user_id
               AND e1.created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
               AND e2.created_at > DATE_SUB(NOW(), INTERVAL %d DAY)
             GROUP BY e1.endorser_user_id, e2.endorser_user_id
             HAVING mutual_count >= %d
             LIMIT %d OFFSET %d",
            $windowDays,
            $windowDays,
            $minSize,
            $pageLimit,
            $offset
        ) ) ?: [];
    }
}