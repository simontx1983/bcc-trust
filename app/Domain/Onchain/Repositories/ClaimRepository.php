<?php
/**
 * Claim Repository
 *
 * CRUD for on-chain entity claims (validator operator, NFT creator/holder).
 *
 * @package BCC\Trust\Onchain\Repositories
 */

namespace BCC\Trust\Onchain\Repositories;

use BCC\Core\DB\AdvisoryLock;
use BCC\Trust\Onchain\ValueObjects\ClaimStatus;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type ClaimRow object{
 *     id: string,
 *     user_id: string,
 *     entity_type: string,
 *     entity_id: string,
 *     wallet_address: string,
 *     chain_id: string,
 *     claim_role: string,
 *     status: string,
 *     verified_at: string|null,
 *     created_at: string
 * }
 *
 * @phpstan-type ClaimWithClaimer object{
 *     id: string,
 *     user_id: string,
 *     entity_type: string,
 *     entity_id: string,
 *     wallet_address: string,
 *     chain_id: string,
 *     claim_role: string,
 *     status: string,
 *     verified_at: string|null,
 *     created_at: string,
 *     claimer_name: string
 * }
 *
 * @phpstan-type ClaimPublic object{
 *     id: string,
 *     user_id: string,
 *     entity_type: string,
 *     entity_id: string,
 *     chain_id: string,
 *     claim_role: string,
 *     status: string,
 *     verified_at: string|null,
 *     created_at: string,
 *     claimer_name: string,
 *     wallet_address?: string
 * }
 *
 * @phpstan-type ClaimByPageRow object{
 *     page_id: string,
 *     claimer_name: string,
 *     claim_role: string
 * }
 */
class ClaimRepository {

    /** @var string Explicit column list — must match schema-claims.php. */
    private const COLUMNS = 'id, user_id, entity_type, entity_id, wallet_address,
                 chain_id, claim_role, status, verified_at, created_at';

    public static function table(): string {
        return \BCC\Core\DB\DB::table('onchain_claims');
    }

    /**
     * Bulk-check which user_ids hold ANY verified operator/creator
     * claim. Used by FeedRankingService to flip the §N8 OPERATOR
     * badge on feed-item authors without an N+1 lookup.
     *
     * Returns the subset of $userIds that have at least one such
     * claim (across any entity_type / entity_id). Users with no
     * verified operator/creator claim are simply absent from the
     * result.
     *
     * Bounded by the feed-page cap (50 items, so 50 distinct authors
     * worst case) — single GROUP BY query, no fanout.
     *
     * @param list<int> $userIds
     * @return array<int, true> map keyed by user_id for O(1) lookup
     */
    public static function getOperatorUserIdSet(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        // Sanitize + dedupe + bound.
        $ids = [];
        foreach ($userIds as $id) {
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
        $table = self::table();

        /** @var list<object{user_id: numeric-string}>|null $rows */
        $rows = $wpdb->get_results(
            "SELECT DISTINCT user_id
               FROM {$table}
              WHERE user_id IN ({$idList})
                AND status     = 'verified'
                AND claim_role IN ('operator','creator')"
        );

        $out = [];
        foreach ($rows ?: [] as $row) {
            $uid = (int) $row->user_id;
            if ($uid > 0) {
                $out[$uid] = true;
            }
        }
        return $out;
    }

    /**
     * Insert or update a claim. UNIQUE on (user_id, entity_type, entity_id).
     *
     * @return array{id: int, inserted: bool}|false Claim data or false on failure.
     */
    public static function upsert(int $userId, string $entityType, int $entityId, string $walletAddress, int $chainId, string $claimRole, string $status = ClaimStatus::VERIFIED): array|false {
        global $wpdb;
        $table = self::table();

        // Validate at the persistence boundary — an invalid claim status must
        // fail loud, not silently persist (Phase 9).
        $status     = ClaimStatus::assert($status);
        $verifiedAt = ($status === ClaimStatus::VERIFIED) ? current_time('mysql', true) : null;

        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (user_id, entity_type, entity_id, wallet_address, chain_id, claim_role, status, verified_at, created_at)
             VALUES (%d, %s, %d, %s, %d, %s, %s, %s, %s)
             ON DUPLICATE KEY UPDATE
                wallet_address = VALUES(wallet_address),
                chain_id       = VALUES(chain_id),
                claim_role     = VALUES(claim_role),
                status         = VALUES(status),
                verified_at    = VALUES(verified_at)",
            $userId,
            $entityType,
            $entityId,
            $walletAddress,
            $chainId,
            $claimRole,
            $status,
            $verifiedAt,
            current_time('mysql', true)
        ));

        // Capture rows_affected AND last_error immediately — both are
        // connection-global state that any intervening query would overwrite.
        // INSERT = 1, UPDATE (changed) = 2, UPDATE (no-op) = 0.
        $inserted  = ((int) $wpdb->rows_affected === 1);
        $lastError = (string) $wpdb->last_error;

        if ($lastError !== '') {
            return false;
        }

        $claimId = (int) $wpdb->insert_id ?: (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE user_id = %d AND entity_type = %s AND entity_id = %d",
            $userId, $entityType, $entityId
        ));

        return ['id' => $claimId, 'inserted' => $inserted];
    }

    /**
     * Get the verified primary claim (exclusive role) for an entity.
     * Returns the single operator/creator claim if one exists, or null.
     * Uses idx_entity index: (entity_type, entity_id).
     *
     * SECURITY-CRITICAL: This is the exclusivity gate for operator/creator
     * claims. If a DB error returns null, treating it as "no claim exists"
     * would let a second user bypass exclusivity while the advisory lock
     * is held. On DB error we throw so createExclusiveClaim() fails closed
     * rather than granting a collision-free claim on stale/empty state.
     *
     * @return ClaimWithClaimer|null
     * @throws \RuntimeException When the query errors (fail-closed).
     */
    public static function getPrimaryClaim(string $entityType, int $entityId, string $role): ?object {
        global $wpdb;
        $table = self::table();

        /** @var ClaimWithClaimer|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT cl.id, cl.user_id, cl.entity_type, cl.entity_id, cl.wallet_address,
                    cl.chain_id, cl.claim_role, cl.status, cl.verified_at, cl.created_at,
                    u.display_name AS claimer_name
             FROM {$table} cl
             INNER JOIN {$wpdb->users} u ON u.ID = cl.user_id
             WHERE cl.entity_type = %s AND cl.entity_id = %d
               AND cl.claim_role = %s AND cl.status = 'verified'
             LIMIT 1",
            $entityType,
            $entityId,
            $role
        ));

        // wpdb::get_row() returns null for BOTH "no row" and "DB error" — the only
        // way to distinguish is $wpdb->last_error, which is connection-GLOBAL state.
        // We snapshot it IMMEDIATELY here; any unrelated query that fires between
        // this point and the check (e.g. a shutdown handler, an autoloader touching
        // the DB, or a cache-miss triggering another query) would clobber it and
        // mask the real error. Do not insert code between these two lines.
        $lastError = (string) $wpdb->last_error;

        if ($row === null && $lastError !== '') {
            throw new \RuntimeException(
                'ClaimRepository::getPrimaryClaim DB error: ' . $lastError
            );
        }

        return $row;
    }

    /**
     * Get all verified claims for an entity.
     *
     * Wallet addresses are NOT included by default to prevent data leaks.
     * Pass $includeWalletAddress = true only when the caller needs it
     * (e.g. admin views).
     *
     * @return list<ClaimPublic> Array of claim objects with user display_name.
     */
    public static function getForEntity(string $entityType, int $entityId, bool $includeWalletAddress = false): array {
        global $wpdb;
        $table = self::table();

        $walletCol = $includeWalletAddress ? ', cl.wallet_address' : '';

        /** @var list<ClaimPublic>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cl.id, cl.user_id, cl.entity_type, cl.entity_id{$walletCol},
                    cl.chain_id, cl.claim_role, cl.status, cl.verified_at, cl.created_at,
                    u.display_name AS claimer_name
             FROM {$table} cl
             INNER JOIN {$wpdb->users} u ON u.ID = cl.user_id
             WHERE cl.entity_type = %s AND cl.entity_id = %d AND cl.status = 'verified'
             ORDER BY cl.verified_at ASC
             LIMIT 100",
            $entityType,
            $entityId
        ));

        return $rows ?: [];
    }

    /**
     * Bulk lookup by claim id — used by the feed-body hydrator to
     * resolve page_claim activity rows (act_external_id is the
     * claim id) in one round-trip.
     *
     * Bounded by LIMIT 100 — feed pages cap at 50, with margin.
     *
     * @param list<int> $ids
     * @return array<int, ClaimRow>
     */
    public static function findManyByIds(array $ids): array {
        if ($ids === []) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        /** @var list<ClaimRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table}
              WHERE id IN ({$placeholders})
              LIMIT 100",
            ...$ids
        ));

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row->id] = $row;
        }
        return $map;
    }

    /**
     * Get a user's claim on a specific entity (if any).
     *
     * @return ClaimRow|null
     */
    public static function getUserClaim(int $userId, string $entityType, int $entityId): ?object {
        global $wpdb;
        $table = self::table();

        /** @var ClaimRow|null */
        return $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table}
             WHERE user_id = %d AND entity_type = %s AND entity_id = %d",
            $userId, $entityType, $entityId
        ));
    }

    /**
     * Batch variant of getUserClaim — one bounded query for a whole
     * cards-list page of entities instead of N per-card lookups. Used
     * by PageCardPrefetcher to feed `is_claimed_by_viewer` resolution.
     *
     * Mirrors getUserClaim's WHERE exactly (user + entity_type +
     * entity_id IN, no status filter — the caller checks
     * status === 'verified', same as the single path). First row per
     * entity wins, matching get_row()'s first-row semantics.
     *
     * Bounded: caller-paginated IN-list (cards per_page ≤ 50); LIMIT
     * is belt-and-suspenders.
     *
     * @param int[] $entityIds
     * @return array<int, object> entity_id => claim row.
     * @phpstan-return array<int, ClaimRow>
     */
    public static function getUserClaimsForEntities(int $userId, string $entityType, array $entityIds): array {
        if ($userId <= 0 || empty($entityIds)) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $ph    = implode(',', array_fill(0, count($entityIds), '%d'));

        /** @var list<ClaimRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table}
             WHERE user_id = %d AND entity_type = %s AND entity_id IN ({$ph})
             LIMIT 500",
            $userId,
            $entityType,
            ...$entityIds
        ));

        $map = [];
        foreach ($rows ?: [] as $row) {
            $entityId = (int) $row->entity_id;
            if (!isset($map[$entityId])) {
                $map[$entityId] = $row;
            }
        }
        return $map;
    }

    /**
     * Batch-check which page IDs have any verified primary claim
     * (operator or creator). Returns page_id => claimer_name map.
     *
     * Joins through entity tables → wallet_links to resolve page ownership.
     * Single query, no N+1.
     *
     * @param int[] $pageIds
     * @return array<int, string> page_id => claimer display_name
     */
    public static function getPrimaryClaimsByPageIds(array $pageIds): array {
        if (empty($pageIds)) {
            return [];
        }

        global $wpdb;
        $table       = self::table();
        $validators  = \BCC\Core\DB\DB::table('onchain_validators');
        $collections = \BCC\Core\DB\DB::table('onchain_collections');
        $wallets     = \BCC\Core\DB\DB::table('wallet_links');

        $ph = implode(',', array_fill(0, count($pageIds), '%d'));

        // Union validators + collections claims, joined to wallet_links for page_id.
        $sql = $wpdb->prepare(
            "SELECT w.post_id AS page_id, u.display_name AS claimer_name, cl.claim_role
             FROM {$table} cl
             JOIN {$validators} v ON v.id = cl.entity_id AND cl.entity_type = 'validator'
             JOIN {$wallets} w ON w.id = v.wallet_link_id
             JOIN {$wpdb->users} u ON u.ID = cl.user_id
             WHERE cl.status = 'verified' AND cl.claim_role IN ('operator','creator')
               AND w.post_id IN ({$ph})

             UNION ALL

             SELECT w.post_id AS page_id, u.display_name AS claimer_name, cl.claim_role
             FROM {$table} cl
             JOIN {$collections} c ON c.id = cl.entity_id AND cl.entity_type = 'collection'
             JOIN {$wallets} w ON w.id = c.wallet_link_id
             JOIN {$wpdb->users} u ON u.ID = cl.user_id
             WHERE cl.status = 'verified' AND cl.claim_role IN ('operator','creator')
               AND w.post_id IN ({$ph})",
            ...array_merge($pageIds, $pageIds)
        );

        /** @var list<ClaimByPageRow>|null $rows */
        $rows = $wpdb->get_results($sql);

        $map = [];
        foreach ($rows ?: [] as $row) {
            // First primary claim wins per page (operator > creator by query order).
            if (!isset($map[(int) $row->page_id])) {
                $map[(int) $row->page_id] = $row->claimer_name;
            }
        }

        return $map;
    }

    /**
     * Batch-load all verified claims for multiple entities of the same type.
     * Single query replacing N per-entity lookups.
     *
     * @param string $entityType 'validator', 'collection', or 'page'
     *                           (page claims back the card-level
     *                           is_claimed flag; see CardViewService /
     *                           PageCardPrefetcher).
     * @param int[]  $entityIds
     * @return array<int, list<ClaimWithClaimer>> entity_id => array of claim objects
     */
    public static function getForEntityBatch(string $entityType, array $entityIds): array {
        if (empty($entityIds)) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $ph    = implode(',', array_fill(0, count($entityIds), '%d'));

        /** @var list<ClaimWithClaimer>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cl.id, cl.user_id, cl.entity_type, cl.entity_id, cl.wallet_address,
                    cl.chain_id, cl.claim_role, cl.status, cl.verified_at, cl.created_at,
                    u.display_name AS claimer_name
             FROM {$table} cl
             INNER JOIN {$wpdb->users} u ON u.ID = cl.user_id
             WHERE cl.entity_type = %s AND cl.entity_id IN ({$ph}) AND cl.status = 'verified'
             ORDER BY cl.verified_at ASC
             LIMIT 500",
            $entityType,
            ...$entityIds
        ));

        $map = [];
        foreach ($rows ?: [] as $row) {
            $map[(int) $row->entity_id][] = $row;
        }

        return $map;
    }

    /**
     * Atomically create an exclusive claim (operator/creator).
     *
     * Uses a MySQL advisory lock scoped to the entity+role to serialize
     * concurrent claims. The lock prevents TOCTOU races between the
     * getPrimaryClaim check and the upsert.
     *
     * MySQL does not support partial unique indexes, so exclusivity for
     * operator/creator roles is enforced at the application level with
     * this advisory lock (not via a DB UNIQUE KEY, which would also
     * block non-exclusive holder claims).
     *
     * @return array{success: true, claim_id: int}|array{success: false, message: string, error?: string}
     */
    public static function createExclusiveClaim(
        int $userId,
        string $entityType,
        int $entityId,
        string $walletAddress,
        int $chainId,
        string $role
    ): array {
        // Advisory lock scoped to this specific entity+role.
        $lockKey = "bcc_claim:{$entityType}:{$entityId}:{$role}";
        if (!AdvisoryLock::acquire($lockKey, 5)) {
            return ['success' => false, 'message' => 'Could not acquire claim lock. Please try again.'];
        }

        try {
            // Under lock: check if anyone already holds this exclusive role.
            // getPrimaryClaim() THROWS on DB error — we deliberately let it propagate
            // to the outer catch so the lock releases and the user sees a transient
            // failure, rather than silently granting a claim on empty result.
            $existingPrimary = self::getPrimaryClaim($entityType, $entityId, $role);

            if ($existingPrimary) {
                if ((int) $existingPrimary->user_id === $userId) {
                    // Idempotent re-claim by the same user.
                    return ['success' => true, 'claim_id' => (int) $existingPrimary->id];
                }

                $roleLabel = ucfirst($role);
                return [
                    'success' => false,
                    'error'   => 'already_claimed',
                    'message' => "This project already has a verified {$roleLabel}.",
                ];
            }

            // No one holds this role yet — safe to upsert under the lock.
            $upsertResult = self::upsert($userId, $entityType, $entityId, $walletAddress, $chainId, $role, 'verified');

            if (!$upsertResult) {
                return ['success' => false, 'message' => 'Failed to save claim.'];
            }

            return ['success' => true, 'claim_id' => $upsertResult['id']];
        } catch (\RuntimeException $e) {
            // Fail closed: if the exclusivity check itself errored, do not grant a claim.
            if (class_exists('\\BCC\\Core\\Log\\Logger')) {
                \BCC\Core\Log\Logger::error('[ClaimRepository] exclusive claim aborted on DB error', [
                    'entity_type' => $entityType,
                    'entity_id'   => $entityId,
                    'role'        => $role,
                    'error'       => $e->getMessage(),
                ]);
            }
            return [
                'success' => false,
                'message' => 'Temporary database error — please try again in a moment.',
            ];
        } finally {
            AdvisoryLock::release($lockKey);
        }
    }

    /**
     * Delete all claims linked to a specific wallet address for a user on a specific chain.
     *
     * Called when a wallet is disconnected so that the trust bonus is revoked.
     * Scoped by chain_id to prevent collateral deletion of claims on other chains
     * that share the same address format (e.g., multiple EVM chains).
     *
     * @return int Number of rows deleted.
     */
    public static function deleteByUserAndWallet(int $userId, string $walletAddress, ?int $chainId = null): int {
        global $wpdb;
        $table = self::table();

        if ($chainId !== null) {
            return (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id = %d AND wallet_address = %s AND chain_id = %d",
                $userId,
                $walletAddress,
                $chainId
            ));
        }

        // Fallback for callers that don't have chain context (backward compat).
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE user_id = %d AND wallet_address = %s",
            $userId,
            $walletAddress
        ));
    }

    /**
     * Delete all claims for a user (full account cleanup).
     */
    public static function deleteForUser(int $userId): void
    {
        global $wpdb;
        $table = self::table();
        $wpdb->delete($table, ['user_id' => $userId], ['%d']);
    }

    /**
     * Atomically purge all data tied to a disconnected wallet: the claim
     * rows (this repository) AND the signal rows (SignalRepository) in a
     * single DB transaction. Wraps both deletes so the two can never
     * diverge — the prior split across BonusService + ClaimRepository +
     * SignalRepository left raw $wpdb transaction control in a Services
     * class (architecture violation) AND could leave signals behind when
     * a PHP timeout fired between the two deletes, inflating onchain_bonus
     * until the next daily refresh.
     *
     * Returns the number of claim rows removed. Signal-row count is not
     * surfaced — callers log the claim count only.
     */
    public static function purgeDisconnectedWallet(
        int $userId,
        string $walletAddress,
        ?int $chainId,
        string $chainSlug
    ): int {
        global $wpdb;

        $wpdb->query('START TRANSACTION');
        try {
            $claimsRemoved  = self::deleteByUserAndWallet($userId, $walletAddress, $chainId);
            $signalsDeleted = SignalRepository::deleteByWallet($walletAddress, $chainSlug);
            // deleteByWallet returns int on success, false on DB error.
            // A false return MUST abort so the claim delete rolls back —
            // otherwise we'd leave the claim revoked but the signal row
            // intact, producing an inflated onchain_bonus that only
            // self-heals on the next daily refresh (up to 24h later).
            if ($signalsDeleted === false) {
                throw new \RuntimeException(
                    'SignalRepository::deleteByWallet returned false for '
                    . $walletAddress . ' on ' . $chainSlug
                );
            }
            $wpdb->query('COMMIT');
            return $claimsRemoved;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * Compute the total claim bonus for a page across ALL entity types.
     *
     * Aggregates validator AND collection claims. Wallet-linked entities are
     * matched via wallet_link → post_id. Bulk-indexed entities (wallet_link_id
     * IS NULL) are matched via the claiming user's ID.
     */
    public static function computePageClaimBonus(
        string $walletTable,
        int $pageId,
        int $userId
    ): float {
        global $wpdb;
        $table       = self::table();
        $validators  = \BCC\Core\DB\DB::table('onchain_validators');
        $collections = \BCC\Core\DB\DB::table('onchain_collections');

        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(bonus), 0) FROM (
                /* Validator claims linked via wallet */
                SELECT CASE
                    WHEN cl.claim_role IN ('operator','creator') THEN 5.0
                    WHEN cl.claim_role = 'holder' THEN 1.0
                    ELSE 0
                END AS bonus
                FROM {$table} cl
                JOIN {$validators} e ON e.id = cl.entity_id AND cl.entity_type = 'validator'
                JOIN {$walletTable} w ON w.id = e.wallet_link_id
                WHERE w.post_id = %d AND cl.status = 'verified'

                UNION ALL

                /* Validator claims on bulk-indexed entities (no wallet_link) */
                SELECT CASE
                    WHEN cl.claim_role IN ('operator','creator') THEN 5.0
                    WHEN cl.claim_role = 'holder' THEN 1.0
                    ELSE 0
                END AS bonus
                FROM {$table} cl
                JOIN {$validators} e ON e.id = cl.entity_id AND cl.entity_type = 'validator'
                WHERE e.wallet_link_id IS NULL
                  AND cl.status = 'verified'
                  AND cl.user_id = %d

                UNION ALL

                /* Collection claims linked via wallet */
                SELECT CASE
                    WHEN cl.claim_role IN ('operator','creator') THEN 5.0
                    WHEN cl.claim_role = 'holder' THEN 1.0
                    ELSE 0
                END AS bonus
                FROM {$table} cl
                JOIN {$collections} e ON e.id = cl.entity_id AND cl.entity_type = 'collection'
                JOIN {$walletTable} w ON w.id = e.wallet_link_id
                WHERE w.post_id = %d AND cl.status = 'verified'

                UNION ALL

                /* Collection claims on bulk-indexed entities (no wallet_link) */
                SELECT CASE
                    WHEN cl.claim_role IN ('operator','creator') THEN 5.0
                    WHEN cl.claim_role = 'holder' THEN 1.0
                    ELSE 0
                END AS bonus
                FROM {$table} cl
                JOIN {$collections} e ON e.id = cl.entity_id AND cl.entity_type = 'collection'
                WHERE e.wallet_link_id IS NULL
                  AND cl.status = 'verified'
                  AND cl.user_id = %d
            ) AS combined_bonuses",
            $pageId,
            $userId,
            $pageId,
            $userId
        ));
    }

}
