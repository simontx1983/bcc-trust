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

    use GuardsReadFailures;

    /** @var string Explicit column list — must match schema-claims.php. */
    private const COLUMNS = 'id, user_id, entity_type, entity_id, wallet_address,
                 chain_id, claim_role, status, verified_at, created_at';

    /** Operator-resolution states (validator-messaging routing). */
    public const OPERATOR_UNCLAIMED = 'UNCLAIMED';
    public const OPERATOR_RESOLVED  = 'RESOLVED';
    public const OPERATOR_AMBIGUOUS = 'AMBIGUOUS';

    /**
     * In-request dedup for the ambiguous-operator audit row (the
     * degradation metric still counts every observation).
     *
     * @var array<int, true>
     */
    private static array $ambiguousAudited = [];

    /**
     * D1: the `claim_role IN (...)` condition every role-scoped reader
     * adds. Roles must be ones PageClaimRolePolicy knows; anything else is
     * a programming error and throws rather than reading the wrong rows.
     *
     * @param list<string> $roles non-empty
     * @return array{0: string, 1: list<string>} the SQL fragment and its args
     */
    private static function roleCondition(string $column, array $roles): array
    {
        $known = array_merge(
            \BCC\Trust\Onchain\Services\PageClaimRolePolicy::ENTITY_CLAIM_ROLES,
            \BCC\Trust\Onchain\Services\PageClaimRolePolicy::TEAM_ROLES
        );
        foreach ($roles as $role) {
            if (!in_array($role, $known, true)) {
                throw new \InvalidArgumentException('Unknown claim role: ' . $role);
            }
        }
        $roles = array_values(array_unique($roles));

        return [$column . ' IN (' . implode(',', array_fill(0, count($roles), '%s')) . ')', $roles];
    }

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

        // upsert() is the WALLET claim path. A row it touches is a wallet
        // claim from then on: the update clause resets how it was proven
        // (a re-claim of a once staff-approved entity must not stay 'staff',
        // or a wallet unlink and the sweep would skip it) and clears the
        // page team columns (a team member who becomes the owner is no
        // longer a team row). Staff, proof and team writes have their own
        // writers and never come through here.
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (user_id, entity_type, entity_id, wallet_address, chain_id, claim_role, status, verified_at, created_at, verification_method)
             VALUES (%d, %s, %d, %s, %d, %s, %s, %s, %s, 'wallet')
             ON DUPLICATE KEY UPDATE
                wallet_address      = VALUES(wallet_address),
                chain_id            = VALUES(chain_id),
                claim_role          = VALUES(claim_role),
                status              = VALUES(status),
                verified_at         = VALUES(verified_at),
                verification_method = 'wallet',
                verified_by         = NULL,
                invited_by          = NULL,
                invite_expires_at   = NULL,
                accepted_at         = NULL,
                added_by_staff      = 0,
                reconfirmed         = 0,
                show_publicly       = 0",
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
     * Get all verified claims for an entity in the given roles.
     *
     * D1: the caller names the roles it reads, from PageClaimRolePolicy
     * (OWNER_ROLES for "is this page claimed", ENTITY_CLAIM_ROLES for an
     * entity row), so a page TEAM row is never read as an owner.
     *
     * Wallet addresses are NOT included by default to prevent data leaks.
     * Pass $includeWalletAddress = true only when the caller needs it
     * (e.g. admin views).
     *
     * @param list<string> $roles
     * @return list<ClaimPublic> Array of claim objects with user display_name.
     */
    public static function getForEntity(string $entityType, int $entityId, array $roles, bool $includeWalletAddress = false): array {
        if ($roles === []) {
            return [];
        }
        global $wpdb;
        $table = self::table();

        $walletCol = $includeWalletAddress ? ', cl.wallet_address' : '';
        [$roleSql, $roleArgs] = self::roleCondition('cl.claim_role', $roles);

        /** @var list<ClaimPublic>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cl.id, cl.user_id, cl.entity_type, cl.entity_id{$walletCol},
                    cl.chain_id, cl.claim_role, cl.status, cl.verified_at, cl.created_at,
                    u.display_name AS claimer_name
             FROM {$table} cl
             INNER JOIN {$wpdb->users} u ON u.ID = cl.user_id
             WHERE cl.entity_type = %s AND cl.entity_id = %d AND cl.status = 'verified'
               AND {$roleSql}
             ORDER BY cl.verified_at ASC
             LIMIT 100",
            $entityType,
            $entityId,
            ...$roleArgs
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
     * F-89: VERIFIED claims only. A revoked claim's feed card must not keep
     * saying "Claimed this page"; the feed drops the item (read time, so
     * it comes back if the claim is made again).
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
                AND status = 'verified'
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
     * Get a user's claim on a specific entity (if any) in the given roles.
     * D1: the roles come from PageClaimRolePolicy — see getForEntity().
     *
     * @param list<string> $roles
     * @return ClaimRow|null
     */
    public static function getUserClaim(int $userId, string $entityType, int $entityId, array $roles): ?object {
        if ($roles === []) {
            return null;
        }
        global $wpdb;
        $table = self::table();
        [$roleSql, $roleArgs] = self::roleCondition('claim_role', $roles);

        /** @var ClaimRow|null */
        return $wpdb->get_row($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table}
             WHERE user_id = %d AND entity_type = %s AND entity_id = %d
               AND {$roleSql}",
            $userId, $entityType, $entityId, ...$roleArgs
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
     * D1: the roles come from PageClaimRolePolicy — see getForEntity().
     *
     * @param int[] $entityIds
     * @param list<string> $roles
     * @return array<int, object> entity_id => claim row.
     * @phpstan-return array<int, ClaimRow>
     */
    public static function getUserClaimsForEntities(int $userId, string $entityType, array $entityIds, array $roles): array {
        if ($userId <= 0 || empty($entityIds) || $roles === []) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $ph    = implode(',', array_fill(0, count($entityIds), '%d'));
        [$roleSql, $roleArgs] = self::roleCondition('claim_role', $roles);

        /** @var list<ClaimRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT " . self::COLUMNS . " FROM {$table}
             WHERE user_id = %d AND entity_type = %s AND entity_id IN ({$ph})
               AND {$roleSql}
             LIMIT 500",
            $userId,
            $entityType,
            ...[...$entityIds, ...$roleArgs]
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
     * Batch-check which page IDs have a verified operator/creator claim.
     *
     * Thin wrapper over getPrimaryClaimsByPageIds() (which already applies
     * the exact `status = 'verified' AND claim_role IN ('operator','creator')`
     * filter and is bounded by the caller-paginated IN-list) — returns a
     * presence map instead of claimer names so callers that only need the
     * boolean `is_claim_verified` flag don't carry a claim-name dependency.
     *
     * Keys are page_ids with at least one verified primary claim; pages
     * without one are simply absent (O(1) `isset()` lookup).
     *
     * @param int[] $pageIds
     * @return array<int, true>
     */
    public static function getVerifiedPagesMap(array $pageIds): array {
        $map = [];
        // Late static binding so the DB-touching source method can be
        // stubbed in isolation (the SQL filter itself is exercised by the
        // integration suite); production binds to the real implementation.
        foreach (static::getPrimaryClaimsByPageIds($pageIds) as $pageId => $_claimerName) {
            $map[(int) $pageId] = true;
        }
        return $map;
    }

    /**
     * Whether a user holds a verified operator/creator claim on an entity
     * whose wallet link binds to THIS page — the canonical claim→page
     * resolution (the SAME two-leg validator+collection join as
     * getPrimaryClaimsByPageIds, which feeds has_verified_claim /
     * is_claim_verified), narrowed to one user + one page.
     *
     * Used by the page-image permission gate (PagesEndpoint::requireClaimer).
     * The gate must agree with the verified-operator state users see, so it
     * reads the underlying validator/collection claim rather than the
     * best-effort entity_type='page' mirror row — the mirror only exists
     * when the POST /pages/:id/claim mirror write succeeded, and is never
     * repaired afterwards (the idempotent re-claim path returns early).
     *
     * Bounded: point lookup (indexed user_id + post_id equality per leg);
     * the trailing LIMIT 1 applies to the whole UNION.
     */
    public static function userHasVerifiedClaimOnPage(int $userId, int $pageId): bool {
        if ($userId <= 0 || $pageId <= 0) {
            return false;
        }

        global $wpdb;
        $table       = self::table();
        $validators  = \BCC\Core\DB\DB::table('onchain_validators');
        $collections = \BCC\Core\DB\DB::table('onchain_collections');
        $wallets     = \BCC\Core\DB\DB::table('wallet_links');

        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT 1
             FROM {$table} cl
             JOIN {$validators} v ON v.id = cl.entity_id AND cl.entity_type = 'validator'
             JOIN {$wallets} w ON w.id = v.wallet_link_id
             WHERE cl.user_id = %d AND w.post_id = %d
               AND cl.status = 'verified' AND cl.claim_role IN ('operator','creator')

             UNION ALL

             SELECT 1
             FROM {$table} cl
             JOIN {$collections} c ON c.id = cl.entity_id AND cl.entity_type = 'collection'
             JOIN {$wallets} w ON w.id = c.wallet_link_id
             WHERE cl.user_id = %d AND w.post_id = %d
               AND cl.status = 'verified' AND cl.claim_role IN ('operator','creator')
             LIMIT 1",
            $userId,
            $pageId,
            $userId,
            $pageId
        ));

        return $found !== null;
    }

    /**
     * Deterministic verified-OPERATOR resolution for VALIDATOR pages —
     * the routing source for the validator-messaging surface. Unlike
     * getPrimaryClaimsByPageIds (display names, first-row-wins,
     * operator OR creator, both entity legs), this resolver:
     *
     *   - considers the validator leg ONLY (project/creator pages are
     *     out of messaging scope until their claim semantics get a
     *     separate audit);
     *   - filters claim_role = 'operator' strictly;
     *   - NEVER picks a row when more than one distinct verified
     *     operator exists — that state is AMBIGUOUS and every caller
     *     fails closed. Private messages are never routed from
     *     unordered SQL output.
     *
     * States per page:
     *   UNCLAIMED — zero verified operator claims resolve to the page
     *               (absent from the returned map; use the `??
     *               UNCLAIMED` default)
     *   RESOLVED  — exactly one distinct operator user (user_id set)
     *   AMBIGUOUS — >1 distinct operator users (user_id null; fires a
     *               validator_messaging/ambiguous_operator degradation
     *               metric per observation + one audit row per page
     *               per request)
     *
     * The trailing ORDER BY only stabilizes scan output for humans —
     * correctness never depends on row order because ambiguity is
     * detected, not resolved. Bounded: IN-list capped at 100 (list
     * pages cap at 50), indexed point joins per row.
     *
     * @param int[] $pageIds
     * @return array<int, array{state: string, user_id: int|null}>
     */
    public static function resolveVerifiedOperatorForPages(array $pageIds): array {
        return self::resolveOperators($pageIds, false);
    }

    /**
     * The user's ACTIVE team role (administrator / representative) on each
     * of $pageIds, for the card's viewer_page_role: the team's own read, so a
     * team row is never read through the owner readers. An invite or any
     * other status holds no role. Bounded by the page list; read fail-soft
     * (a card flag, never an authorization: a failed read answers no role).
     *
     * @param list<int> $pageIds
     * @return array<int, string> page id => role
     */
    public static function activeTeamRolesOnPages(int $userId, array $pageIds): array {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $pageIds), static fn (int $id): bool => $id > 0))), 0, 100);
        if ($userId <= 0 || $ids === []) {
            return [];
        }
        global $wpdb;
        $table = self::table();
        [$roleSql, $roleArgs] = self::roleCondition('claim_role', \BCC\Trust\Onchain\Services\PageClaimRolePolicy::TEAM_ROLES);
        $ph = implode(',', array_fill(0, count($ids), '%d'));

        /** @var list<object{entity_id: numeric-string, claim_role: string}> $rows */
        $rows = self::runRead($wpdb->prepare(
            "SELECT entity_id, claim_role FROM {$table}
              WHERE user_id = %d AND entity_type = 'page' AND entity_id IN ({$ph})
                AND status = 'active' AND {$roleSql}
              LIMIT 100",
            $userId,
            ...[...$ids, ...$roleArgs]
        ), __FUNCTION__, false);

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row->entity_id] = (string) $row->claim_role;
        }

        return $out;
    }

    /**
     * The card-flag resolution of resolveVerifiedOperatorForPages() plus, for
     * each RESOLVED page, the verification methods of that owner's claims on
     * it (both binding legs). The same query; read fail-soft like the card
     * resolver, so a failed read answers no owner and no badge.
     *
     * @param array<int|string> $pageIds
     * @return array{operators: array<int, array{state: string, user_id: int|null}>, methods: array<int, list<string>>}
     */
    public static function resolveVerifiedOperatorsWithMethodsForPages(array $pageIds): array {
        $claims    = [];
        $operators = self::resolveOperators($pageIds, false, $claims);
        $methods   = [];
        foreach ($operators as $pageId => $resolution) {
            if ($resolution['state'] !== self::OPERATOR_RESOLVED) {
                continue;
            }
            foreach ($claims[$pageId] ?? [] as $claim) {
                if ($claim['user_id'] === $resolution['user_id']) {
                    $methods[$pageId][] = $claim['verification_method'];
                }
            }
        }

        return ['operators' => $operators, 'methods' => $methods];
    }

    /**
     * FAIL-CLOSED point variant: the same two-leg resolution, but a read
     * that fails THROWS instead of reading as UNCLAIMED. For authority
     * decisions that must not mistake "could not read" for "no owner" (the
     * page-ownership aligner, live team authorization).
     *
     * @return array{state: string, user_id: int|null}
     * @throws RepositoryReadFailure
     */
    public static function resolveVerifiedOperatorForPageStrict(int $pageId): array {
        if ($pageId <= 0) {
            return ['state' => self::OPERATOR_UNCLAIMED, 'user_id' => null];
        }
        $map = self::resolveOperators([$pageId], true);
        return $map[$pageId] ?? ['state' => self::OPERATOR_UNCLAIMED, 'user_id' => null];
    }

    /**
     * The verified OPERATOR claims that make a user the owner of a page
     * (both binding legs, exactly as the resolvers see them), with each
     * claim's id and verification method. Read fail-closed. More than one
     * distinct user here is the AMBIGUOUS state.
     *
     * @return list<array{claim_id: int, user_id: int, verification_method: string}>
     * @throws RepositoryReadFailure
     */
    public static function ownerClaimsForPageStrict(int $pageId): array {
        if ($pageId <= 0) {
            return [];
        }
        $claims = [];
        self::resolveOperators([$pageId], true, $claims);

        return $claims[$pageId] ?? [];
    }

    /**
     * The resolution behind both variants. Reads go through runRead(), so a
     * failed read (including wpdb's pre-flush bail, which would otherwise
     * hand back a previous query's rows) is never read as rows: it answers
     * no rows, or throws when $failClosed. $claims, when passed, receives
     * the claim rows per page.
     *
     * @param array<int|string> $pageIds
     * @param array<int, list<array{claim_id: int, user_id: int, verification_method: string}>>|null $claims
     * @param-out array<int, list<array{claim_id: int, user_id: int, verification_method: string}>> $claims
     * @return array<int, array{state: string, user_id: int|null}>
     * @throws RepositoryReadFailure when $failClosed and a read fails
     */
    private static function resolveOperators(array $pageIds, bool $failClosed, ?array &$claims = null): array {
        $claims = [];
        if ($pageIds === []) {
            return [];
        }
        $unique = [];
        foreach ($pageIds as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $unique[$id] = true;
            }
        }
        if ($unique === []) {
            return [];
        }
        $ids = array_slice(array_keys($unique), 0, 100);

        global $wpdb;
        $table      = self::table();
        $validators = \BCC\Core\DB\DB::table('onchain_validators');
        $wallets    = \BCC\Core\DB\DB::table('wallet_links');
        $ph         = implode(',', array_fill(0, count($ids), '%d'));

        /** @var list<object{page_id: string, user_id: string, claim_id: string, verification_method: string}> $rows */
        $rows = self::runRead($wpdb->prepare(
            "SELECT w.post_id AS page_id, cl.user_id, cl.id AS claim_id, cl.verification_method
             FROM {$table} cl
             JOIN {$validators} v ON v.id = cl.entity_id AND cl.entity_type = 'validator'
             JOIN {$wallets} w ON w.id = v.wallet_link_id
             WHERE w.post_id IN ({$ph})
               AND cl.status = 'verified' AND cl.claim_role = 'operator'
             ORDER BY w.post_id, cl.user_id",
            ...$ids
        ), __FUNCTION__, $failClosed);

        /** @var array<int, array<int, true>> $byPage */
        $byPage = [];
        foreach ($rows as $row) {
            $byPage[(int) $row->page_id][(int) $row->user_id] = true;
            $claims[(int) $row->page_id][] = ['claim_id' => (int) $row->claim_id, 'user_id' => (int) $row->user_id, 'verification_method' => (string) $row->verification_method];
        }

        // ── ⚠⚠ SECOND LEG: the post_meta page<->validator binding ──────────
        // The query above maps validator -> page through
        // `wallet_links.post_id`, which is NOT the only binding this codebase
        // recognises. `ValidatorPageMinter` writes the page's validator id to
        // `_bcc_onchain_validator_id` post_meta, and FIVE other read paths
        // already treat that as a first-class binding:
        // `ValidatorRepository::findFirstByPageId` / `findAllByPageId` /
        // `findCardRowsByPageIds` / `findCommunityStatsByIds`, plus
        // `PageDiscoveryRepository` and `CardsListEndpoint`.
        //
        // Resolving only the wallet-link leg here made this method disagree
        // with `findFirstByPageId`, and the two are consulted BY THE SAME
        // CALLER one after the other: `ValidatorPageOperatorAuthority::decide()`
        // proves the page is a validator page with `findFirstByPageId` (two
        // legs) and then asks who the operator is with this method (one leg).
        // A page bound only through post_meta therefore passed the first gate
        // and always answered OPERATOR_UNCLAIMED, so `decide()` denied the
        // page's REAL operator with `claim_absent` — on their own page.
        //
        // ⚠ That is reachable without any data repair, because nothing writes
        // a non-zero `wallet_links.post_id`: `WalletRepository::insertOrFind`
        // is the only write path into that table, its `ON DUPLICATE KEY
        // UPDATE` clause deliberately touches only `id`, and its one caller
        // (`WalletAuthController`, wallet signup) passes `0`. So for any page
        // minted after that became true, post_meta is the only binding there
        // is.
        //
        // Fallback ONLY for pages the first leg missed, which is the semantics
        // every other two-leg reader in this codebase uses (wallet-link page
        // wins; see `WalletRepository::resolveEntitiesForPages` for the same
        // $missing shape). Deliberately NOT a union: a page that resolves to
        // one operator today through the wallet link must keep resolving to
        // that operator, and merging a second validator's claimants into its
        // set would turn a working page AMBIGUOUS.
        $missing = [];
        foreach ($ids as $id) {
            if (!isset($byPage[$id])) {
                $missing[] = $id;
            }
        }

        if ($missing !== []) {
            $phMissing = implode(',', array_fill(0, count($missing), '%d'));

            // ⚠ NO `LIMIT`, matching the first leg in this same method, and
            // that is load-bearing rather than an omission. The unit of
            // correctness here is the COMPLETE claimant set per page: the
            // count decides RESOLVED vs AMBIGUOUS. A truncating LIMIT could
            // drop a contested page's second claimant and silently convert
            // AMBIGUOUS into RESOLVED, handing one of two rival claimants
            // sole authority. Bounded by the 100-id IN-list above instead.
            // CAST per the collation note on findMissingPlaceholderPage.
            /** @var list<object{page_id: string, user_id: string, claim_id: string, verification_method: string}> $metaRows */
            $metaRows = self::runRead($wpdb->prepare(
                "SELECT pm.post_id AS page_id, cl.user_id, cl.id AS claim_id, cl.verification_method
                 FROM {$wpdb->postmeta} pm
                 JOIN {$validators} v ON v.id = CAST(pm.meta_value AS UNSIGNED)
                 JOIN {$table} cl ON cl.entity_id = v.id AND cl.entity_type = 'validator'
                 WHERE pm.meta_key = '_bcc_onchain_validator_id'
                   AND pm.post_id IN ({$phMissing})
                   AND cl.status = 'verified' AND cl.claim_role = 'operator'
                 ORDER BY pm.post_id, cl.user_id",
                ...$missing
            ), __FUNCTION__, $failClosed);

            foreach ($metaRows as $row) {
                $byPage[(int) $row->page_id][(int) $row->user_id] = true;
                $claims[(int) $row->page_id][] = ['claim_id' => (int) $row->claim_id, 'user_id' => (int) $row->user_id, 'verification_method' => (string) $row->verification_method];
            }
        }

        $out = [];
        foreach ($byPage as $pageId => $userSet) {
            if (count($userSet) === 1) {
                $out[$pageId] = [
                    'state'   => self::OPERATOR_RESOLVED,
                    'user_id' => array_key_first($userSet),
                ];
                continue;
            }
            $out[$pageId] = ['state' => self::OPERATOR_AMBIGUOUS, 'user_id' => null];
            self::recordAmbiguousOperator($pageId, count($userSet));
        }
        return $out;
    }

    /**
     * Point variant of resolveVerifiedOperatorForPages.
     *
     * @return array{state: string, user_id: int|null}
     */
    public static function resolveVerifiedOperatorForPage(int $pageId): array {
        if ($pageId <= 0) {
            return ['state' => self::OPERATOR_UNCLAIMED, 'user_id' => null];
        }
        $map = static::resolveVerifiedOperatorForPages([$pageId]);
        return $map[$pageId] ?? ['state' => self::OPERATOR_UNCLAIMED, 'user_id' => null];
    }

    /**
     * AMBIGUOUS observation plumbing: the degradation metric counts
     * every observation (any occurrence is an incident — DB state the
     * app-level claim exclusivity should make impossible); the audit
     * row is deduped per page per request so card lists cannot spam
     * bcc_trust_activity. Meta carries the claim COUNT only — never
     * claimant user ids (identity non-exposure) and never bodies.
     */
    private static function recordAmbiguousOperator(int $pageId, int $claimUserCount): void {
        \BCC\Core\Observability\DegradationMetrics::record('validator_messaging', 'ambiguous_operator');
        if (isset(self::$ambiguousAudited[$pageId])) {
            return;
        }
        self::$ambiguousAudited[$pageId] = true;
        \BCC\Trust\Core\Security\AuditLogger::log(
            'validator_operator_ambiguous',
            $pageId,
            ['claim_user_count' => $claimUserCount],
            'page',
            null
        );
    }

    /**
     * Batch-load all verified claims for multiple entities of the same type.
     * Single query replacing N per-entity lookups.
     *
     * @param string $entityType 'validator', 'collection', or 'page'
     *                           (page claims back the card-level
     *                           is_claimed flag; see CardViewService /
     *                           PageCardPrefetcher).
     * D1: the roles come from PageClaimRolePolicy — see getForEntity().
     *
     * @param int[]  $entityIds
     * @param list<string> $roles
     * @return array<int, list<ClaimWithClaimer>> entity_id => array of claim objects
     */
    public static function getForEntityBatch(string $entityType, array $entityIds, array $roles): array {
        if (empty($entityIds) || $roles === []) {
            return [];
        }

        global $wpdb;
        $table = self::table();
        $ph    = implode(',', array_fill(0, count($entityIds), '%d'));
        [$roleSql, $roleArgs] = self::roleCondition('cl.claim_role', $roles);

        /** @var list<ClaimWithClaimer>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cl.id, cl.user_id, cl.entity_type, cl.entity_id, cl.wallet_address,
                    cl.chain_id, cl.claim_role, cl.status, cl.verified_at, cl.created_at,
                    u.display_name AS claimer_name
             FROM {$table} cl
             INNER JOIN {$wpdb->users} u ON u.ID = cl.user_id
             WHERE cl.entity_type = %s AND cl.entity_id IN ({$ph}) AND cl.status = 'verified'
               AND {$roleSql}
             ORDER BY cl.verified_at ASC
             LIMIT 500",
            $entityType,
            ...[...$entityIds, ...$roleArgs]
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
            // 'transient' so the route answers 503 (retryable) rather than
            // the default 403 'your wallet does not match' — a lock timeout
            // is a busy server, not a wrong wallet.
            return ['success' => false, 'error' => 'transient', 'message' => 'Could not acquire claim lock. Please try again.'];
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
                return ['success' => false, 'error' => 'transient', 'message' => 'Failed to save claim.'];
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
                'error'   => 'transient',
                'message' => 'Temporary database error — please try again in a moment.',
            ];
        } finally {
            AdvisoryLock::release($lockKey);
        }
    }

    /** Most claims one revoke-all reads at once (F-521); more is refused, never truncated. */
    public const REVOKE_ALL_MAX = 200;

    /**
     * F-521: the ids of the VERIFIED validator/collection claims a user holds,
     * or only those on one wallet address (and chain, when given), lowest id
     * first. The same wallet/chain match as {@see deleteByUserAndWallet()}.
     *
     * Read FAIL-CLOSED and bounded: a failed read, or more than
     * REVOKE_ALL_MAX rows, THROWS rather than answering with a part, so a
     * caller never revokes some claims and purges the rest unaudited.
     *
     * @return list<int>
     * @throws RepositoryReadFailure
     */
    public static function verifiedEntityClaimIdsForUser(int $userId, ?string $walletAddress = null, ?int $chainId = null): array
    {
        $ids = self::verifiedEntityClaimIds($userId, $walletAddress, $chainId, self::REVOKE_ALL_MAX + 1);
        if (count($ids) > self::REVOKE_ALL_MAX) {
            throw new RepositoryReadFailure(
                static::class,
                __FUNCTION__,
                'more than ' . self::REVOKE_ALL_MAX . ' verified claims: revoke them in batches first with '
                . '`wp bcc-trust claims-revoke-user ' . $userId . ' --reason=<wallet_unlinked|account_deleted>`, then retry'
            );
        }

        return $ids;
    }

    /**
     * Up to $limit of the same ids, lowest first, WITHOUT refusing a larger
     * set: for working through a user with more than REVOKE_ALL_MAX claims
     * in batches (the claims-revoke-user command). Read fail-closed.
     *
     * @return list<int>
     * @throws RepositoryReadFailure
     */
    public static function verifiedEntityClaimIdsBatch(int $userId, int $limit, ?string $walletAddress = null, ?int $chainId = null): array
    {
        return self::verifiedEntityClaimIds($userId, $walletAddress, $chainId, max(1, min(self::REVOKE_ALL_MAX, $limit)));
    }

    /**
     * Does the user hold a verified VALIDATOR claim (or one on that wallet
     * and chain)? The same match as verifiedEntityClaimIdsForUser(), read
     * fail-closed: the CLI break-glass asks it before revoking.
     *
     * @throws RepositoryReadFailure
     */
    public static function verifiedValidatorClaimExists(int $userId, ?string $walletAddress = null, ?int $chainId = null): bool
    {
        return self::verifiedEntityClaimIds($userId, $walletAddress, $chainId, 1, true) !== [];
    }

    /**
     * A claim's identity: who holds it, on which entity, in which role and
     * status, proven how. Read FAIL-CLOSED (a failed read throws, never
     * reads as "no claim"). Unlocked: it names the locks a revoke then takes.
     *
     * @return array{user_id: int, entity_type: string, entity_id: int, claim_role: string, status: string, verification_method: string}|null
     * @throws RepositoryReadFailure
     */
    public static function claimEntityStrict(int $claimId): ?array
    {
        if ($claimId <= 0) {
            return null;
        }
        global $wpdb;
        $table = self::table();

        /** @var list<object{user_id: numeric-string, entity_type: string, entity_id: numeric-string, claim_role: string, status: string, verification_method: string}> $rows */
        $rows = self::runRead($wpdb->prepare(
            "SELECT user_id, entity_type, entity_id, claim_role, status, verification_method
               FROM {$table} WHERE id = %d LIMIT 1",
            $claimId
        ), __FUNCTION__, true);
        $row = $rows[0] ?? null;
        if ($row === null) {
            return null;
        }

        return [
            'user_id'             => (int) $row->user_id,
            'entity_type'         => (string) $row->entity_type,
            'entity_id'           => (int) $row->entity_id,
            'claim_role'          => (string) $row->claim_role,
            'status'              => (string) $row->status,
            'verification_method' => (string) $row->verification_method,
        ];
    }

    /**
     * Validator pages still authored by an owner who has DEPARTED, after
     * $afterPageId (ascending), at most $limit: the author's account is gone,
     * or their owner row on that page is now revoked; AND the author holds no
     * verified validator claim bound to the page. The aligner's discovery
     * pass queues them, so a page can never stay owned by a deleted user or
     * a released owner. A page whose living author never owned it through a
     * claim (a hand reassignment, a legacy page) is never returned. Read
     * fail-soft (cron; a failed read finds nothing this time).
     *
     * @return list<int>
     */
    public static function pagesOwnedByDepartedAuthors(int $afterPageId, int $limit): array
    {
        global $wpdb;
        $table = self::table();

        /** @var list<object{ID: numeric-string}> $rows */
        $rows = self::runRead($wpdb->prepare(
            "SELECT p.ID
               FROM {$wpdb->posts} p
               JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_bcc_onchain_validator_id'
               LEFT JOIN {$wpdb->users} u ON u.ID = p.post_author
              WHERE p.post_type = 'peepso-page' AND p.post_author > 0 AND p.ID > %d
                AND (
                     u.ID IS NULL
                  OR EXISTS (SELECT 1 FROM {$table} r
                              WHERE r.user_id = p.post_author AND r.entity_type = 'page' AND r.entity_id = p.ID
                                AND r.status = 'revoked' AND r.claim_role IN ('operator', 'creator'))
                )
                AND NOT EXISTS (SELECT 1 FROM {$table} cl
                                 WHERE cl.user_id = p.post_author AND cl.entity_type = 'validator'
                                   AND cl.status = 'verified' AND cl.claim_role IN ('operator', 'creator')
                                   AND cl.entity_id = CAST(pm.meta_value AS UNSIGNED))
              GROUP BY p.ID
              ORDER BY p.ID ASC
              LIMIT %d",
            max(0, $afterPageId),
            max(1, min(100, $limit))
        ), __FUNCTION__, false);

        $out = [];
        foreach ($rows as $row) {
            $out[] = (int) $row->ID;
        }

        return $out;
    }

    /**
     * @return list<int>
     * @throws RepositoryReadFailure
     */
    private static function verifiedEntityClaimIds(int $userId, ?string $walletAddress, ?int $chainId, int $limit, bool $validatorsOnly = false): array
    {
        if ($userId <= 0) {
            return [];
        }
        global $wpdb;
        $table = self::table();

        $where = $validatorsOnly
            ? "user_id = %d AND status = 'verified' AND entity_type = 'validator'"
            : "user_id = %d AND status = 'verified' AND entity_type IN ('validator', 'collection')";
        $args  = [$userId];
        if ($walletAddress !== null) {
            // A wallet's claims are the ones PROVEN by that wallet. A staff
            // or proof claim stores no linked wallet ('' / 0) and never
            // matches, and a blank address is refused: it would otherwise
            // match every claim that has none.
            if (trim($walletAddress) === '') {
                throw new \InvalidArgumentException('A blank wallet address matches no claim.');
            }
            $where .= " AND wallet_address = %s AND verification_method = 'wallet'";
            $args[] = $walletAddress;
            if ($chainId !== null) {
                $where .= ' AND chain_id = %d';
                $args[] = $chainId;
            }
        }
        $args[] = $limit;

        /** @var list<object{id: numeric-string}> $rows */
        $rows = self::runRead($wpdb->prepare(
            "SELECT id FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d",
            ...$args
        ), __FUNCTION__, true);

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row->id;
        }

        return $ids;
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
        // Only claims PROVEN by this wallet are its claims: a staff or proof
        // claim ('' / 0, its own verification_method) is never purged with a
        // wallet, and a blank address is refused outright.
        if (trim($walletAddress) === '') {
            throw new \InvalidArgumentException('A blank wallet address matches no claim.');
        }
        global $wpdb;
        $table = self::table();

        if ($chainId !== null) {
            return (int) $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id = %d AND wallet_address = %s AND chain_id = %d
                    AND verification_method = 'wallet'",
                $userId,
                $walletAddress,
                $chainId
            ));
        }

        // Fallback for callers that don't have chain context (backward compat).
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE user_id = %d AND wallet_address = %s
                AND verification_method = 'wallet'",
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

        try {
            // Fail closed if the transaction never opened (DB failover): a
            // no-op START leaves the multi-table delete in autocommit with no
            // rollback if a later step fails.
            if ($wpdb->query('START TRANSACTION') === false) {
                throw new \RuntimeException('START TRANSACTION failed');
            }
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
     * Compute the total claim bonus for a user's own page.
     *
     * Sums the bonus for every VERIFIED claim the user personally holds,
     * across validator and collection entities. Attribution is
     * claimant-centric: a claim credits the CLAIMANT's page, never the page
     * a claimed entity happens to be wallet-linked to. Without this, a
     * holder's verified claim on a creator-linked collection would inflate
     * the CREATOR's score (a category error — each person's on-chain
     * activity must boost their own trust, not someone else's). The caller
     * already resolves the page from this same user via getPageForOwner, so
     * the user is the sole attribution key.
     *
     * The entity JOINs restrict the sum to claims whose validator/collection
     * row still exists (stale claims on deleted entities don't count) and
     * naturally exclude the entity_type='page' mirror rows.
     *
     * Bonus map mirrors BonusService::CLAIM_BONUS_MAP:
     *   operator/creator → 5.0, holder → 1.0.
     */
    public static function computePageClaimBonus(int $userId): float
    {
        global $wpdb;
        $table       = self::table();
        $validators  = \BCC\Core\DB\DB::table('onchain_validators');
        $collections = \BCC\Core\DB\DB::table('onchain_collections');

        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(bonus), 0) FROM (
                /* Validator claims held by the user */
                SELECT CASE
                    WHEN cl.claim_role IN ('operator','creator') THEN 5.0
                    WHEN cl.claim_role = 'holder' THEN 1.0
                    ELSE 0
                END AS bonus
                FROM {$table} cl
                JOIN {$validators} e ON e.id = cl.entity_id AND cl.entity_type = 'validator'
                WHERE cl.user_id = %d AND cl.status = 'verified'

                UNION ALL

                /* Collection claims held by the user */
                SELECT CASE
                    WHEN cl.claim_role IN ('operator','creator') THEN 5.0
                    WHEN cl.claim_role = 'holder' THEN 1.0
                    ELSE 0
                END AS bonus
                FROM {$table} cl
                JOIN {$collections} e ON e.id = cl.entity_id AND cl.entity_type = 'collection'
                WHERE cl.user_id = %d AND cl.status = 'verified'
            ) AS combined_bonuses",
            $userId,
            $userId
        ));
    }


    /**
     * Every validator page this user has a VERIFIED operator claim on,
     * across every chain — the operator dashboard's only data source.
     *
     * ⭐ MULTI-CHAIN BY CONSTRUCTION, NOT BY A FLAG. The claims table's
     * only uniqueness is `uq_user_entity (user_id, entity_type, entity_id)`
     * and the exclusivity lock in createExclusiveClaim() is keyed
     * `bcc_claim:{entityType}:{entityId}:{role}`. Neither is scoped to a
     * user or a chain, so one account holds as many operator claims as it
     * can prove and a claim on one chain cannot block a claim on another.
     * This read must never introduce a narrower rule than the table does.
     *
     * The page join casts postmeta's LONGTEXT meta_value to UNSIGNED
     * rather than casting v.id to CHAR — the other direction trips a
     * collation mismatch on this MySQL build (see
     * ValidatorRepository::findMissingPlaceholderPage()).
     *
     * ⚠ A claim whose page has been deleted yields page_id NULL rather
     * than dropping the row: the operator still holds the claim, and the
     * dashboard must be able to say so instead of silently losing it.
     *
     * @return list<object{
     *     claim_id: numeric-string,
     *     claim_role: string,
     *     claim_status: string,
     *     validator_id: numeric-string,
     *     chain_id: numeric-string,
     *     chain_slug: string|null,
     *     chain_name: string|null,
     *     chain_type: string|null,
     *     moniker: string|null,
     *     operator_address: string,
     *     validator_status: string,
     *     page_id: numeric-string|null,
     *     page_slug: string|null
     * }>
     */
    public static function listVerifiedValidatorPagesForUser(int $userId, int $limit = 100): array {
        if ($userId <= 0 || $limit <= 0) {
            return [];
        }
        if ($limit > 200) {
            $limit = 200;
        }

        global $wpdb;
        $table      = self::table();
        $validators = \BCC\Core\DB\DB::table('onchain_validators');
        $chains     = \BCC\Core\DB\DB::table('chains');

        /** @var list<object{claim_id: numeric-string, claim_role: string, claim_status: string, validator_id: numeric-string, chain_id: numeric-string, chain_slug: string|null, chain_name: string|null, chain_type: string|null, moniker: string|null, operator_address: string, validator_status: string, page_id: numeric-string|null, page_slug: string|null}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT cl.id            AS claim_id,
                    cl.claim_role    AS claim_role,
                    cl.status        AS claim_status,
                    v.id             AS validator_id,
                    v.chain_id       AS chain_id,
                    c.slug           AS chain_slug,
                    c.name           AS chain_name,
                    c.chain_type     AS chain_type,
                    v.moniker        AS moniker,
                    v.operator_address AS operator_address,
                    v.status         AS validator_status,
                    p.ID             AS page_id,
                    p.post_name      AS page_slug
               FROM {$table} cl
              INNER JOIN {$validators} v ON v.id = cl.entity_id
               LEFT JOIN {$chains} c ON c.id = v.chain_id
               LEFT JOIN {$wpdb->postmeta} pm
                      ON pm.meta_key = '_bcc_onchain_validator_id'
                     AND CAST(pm.meta_value AS UNSIGNED) = v.id
               LEFT JOIN {$wpdb->posts} p
                      ON p.ID = pm.post_id
                     AND p.post_type = 'peepso-page'
              WHERE cl.user_id     = %d
                AND cl.entity_type = 'validator'
                AND cl.claim_role  = 'operator'
                AND cl.status      = 'verified'
              ORDER BY c.name ASC, v.id ASC
              LIMIT %d",
            $userId,
            $limit
        ));

        return $rows ?: [];
    }

    /**
     * F-14: revoke a verified claim — `verified` → `revoked`, never a DELETE,
     * so history is kept and a re-claim (upsert ON DUPLICATE) restores it.
     *
     * The claim's PAGE MIRROR rows (entity_type 'page', which drive
     * `is_claimed` and the page capabilities) flip in the same transaction —
     * but only for pages BOUND to this claim's entity (the two legs of
     * ClaimService::isEntityBoundToPage), and only when no OTHER verified
     * claim of the same user is still bound to that page. A mirror only
     * shares user/wallet/chain/role with its claim, and one wallet can claim
     * several collections on different pages, so matching on those alone
     * would revoke the wrong page.
     *
     * Every read is FAIL-CLOSED (runRead): the existing binding readers
     * (ValidatorRepository::findAllByPageId, WalletRepository::
     * getPostIdForEntity) answer "no page" on a DB error, which here would
     * silently leave a revoked user able to edit the page. A failed read or
     * write rolls back and throws; the caller treats that as UNKNOWN.
     *
     * Idempotent: a claim that is not (or no longer) verified is a no-op.
     *
     * ⚠ NEVER call this inside TransactionManager::run(). It opens its own
     * transaction, and a START TRANSACTION inside an open one implicitly
     * COMMITS the outer one. That is refused up front (F-14 PR B1).
     *
     * `bound_page_ids` are every page bound to the claim's entity; the
     * caller resyncs their read model (`has_verified_claim`).
     *
     * @return array{revoked: bool, user_id: int, entity_type: string, entity_id: int, claim_role: string, mirror_page_ids: list<int>, bound_page_ids: list<int>}
     * @throws RepositoryReadFailure on a failed read
     * @throws \RuntimeException on a failed transaction or write
     * @throws \LogicException when called inside a TransactionManager run
     */
    public static function revoke(int $claimId): array
    {
        global $wpdb;

        if (\BCC\Trust\Core\Security\TransactionManager::isInRunTransaction()) {
            throw new \LogicException('ClaimRepository::revoke() must not run inside a TransactionManager transaction');
        }

        if ($claimId <= 0) {
            return self::NOT_REVOKED;
        }

        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('START TRANSACTION failed');
        }

        try {
            $result = self::flipRevoked($claimId);

            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('COMMIT failed');
            }

            return $result;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    /**
     * {@see revoke()}, but INSIDE the caller's TransactionManager::run(), so
     * the flip commits or rolls back with the caller's other writes (a staff
     * revoke and its log row; an owner swap). Refused outside a run.
     *
     * @return array{revoked: bool, user_id: int, entity_type: string, entity_id: int, claim_role: string, mirror_page_ids: list<int>, bound_page_ids: list<int>}
     * @throws RepositoryReadFailure on a failed read
     * @throws \RuntimeException on a failed write
     * @throws \LogicException when called outside a TransactionManager run
     */
    public static function revokeInTransaction(int $claimId): array
    {
        if (!\BCC\Trust\Core\Security\TransactionManager::isInRunTransaction()) {
            throw new \LogicException('ClaimRepository::revokeInTransaction() must run inside a TransactionManager transaction');
        }
        if ($claimId <= 0) {
            return self::NOT_REVOKED;
        }

        return self::flipRevoked($claimId);
    }

    /** @var array{revoked: false, user_id: 0, entity_type: '', entity_id: 0, claim_role: '', mirror_page_ids: array{}, bound_page_ids: array{}} */
    private const NOT_REVOKED = ['revoked' => false, 'user_id' => 0, 'entity_type' => '', 'entity_id' => 0, 'claim_role' => '', 'mirror_page_ids' => [], 'bound_page_ids' => []];

    /**
     * The revoke itself, with NO transaction control of its own: lock the
     * claim row, find the page mirrors it alone grants, flip both to
     * revoked. The callers own the transaction.
     *
     * @return array{revoked: bool, user_id: int, entity_type: string, entity_id: int, claim_role: string, mirror_page_ids: list<int>, bound_page_ids: list<int>}
     * @throws RepositoryReadFailure on a failed read
     * @throws \RuntimeException on a failed write
     */
    private static function flipRevoked(int $claimId): array
    {
        global $wpdb;
        $table = self::table();

        {
            /** @var list<object{id: numeric-string, user_id: numeric-string, entity_type: string, entity_id: numeric-string, wallet_address: string, chain_id: numeric-string, claim_role: string, status: string}> $rows */
            $rows = self::runRead($wpdb->prepare(
                "SELECT id, user_id, entity_type, entity_id, wallet_address, chain_id, claim_role, status
                   FROM {$table} WHERE id = %d FOR UPDATE",
                $claimId
            ), __FUNCTION__, true);

            $claim = $rows[0] ?? null;
            if ($claim === null
                || $claim->status !== ClaimStatus::VERIFIED
                || !in_array($claim->entity_type, ['validator', 'collection'], true)) {
                return self::NOT_REVOKED;
            }

            $userId     = (int) $claim->user_id;
            $entityType = (string) $claim->entity_type;
            $entityId   = (int) $claim->entity_id;

            $mirrorIds   = [];
            $mirrorPages = [];
            $boundPages  = self::boundPageIds($entityType, $entityId);
            foreach ($boundPages as $pageId) {
                if (self::otherVerifiedClaimBoundToPage($userId, $claimId, $pageId)) {
                    continue; // the user still holds this page through another claim
                }
                /** @var list<object{id: numeric-string}> $mirrors */
                $mirrors = self::runRead($wpdb->prepare(
                    "SELECT id FROM {$table}
                      WHERE user_id = %d AND entity_type = 'page' AND entity_id = %d
                        AND status = 'verified'
                        AND wallet_address = %s AND chain_id = %d AND claim_role = %s",
                    $userId,
                    $pageId,
                    (string) $claim->wallet_address,
                    (int) $claim->chain_id,
                    (string) $claim->claim_role
                ), __FUNCTION__, true);
                foreach ($mirrors as $m) {
                    $mirrorIds[]   = (int) $m->id;
                    $mirrorPages[] = $pageId;
                }
            }

            $ids = array_merge([$claimId], $mirrorIds);
            $ph  = implode(',', array_fill(0, count($ids), '%d'));
            $ok  = $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'revoked' WHERE id IN ({$ph}) AND status = 'verified'",
                ...$ids
            ));
            if ($ok === false || (string) $wpdb->last_error !== '') {
                throw new \RuntimeException('claim revoke UPDATE failed: ' . (string) $wpdb->last_error);
            }

            return [
                'revoked'         => true,
                'user_id'         => $userId,
                'entity_type'     => $entityType,
                'entity_id'       => $entityId,
                'claim_role'      => (string) $claim->claim_role,
                'mirror_page_ids' => array_values(array_unique($mirrorPages)),
                'bound_page_ids'  => $boundPages,
            ];
        }
    }

    /**
     * Write a NON-WALLET owner claim (staff-approved or proven) for an
     * entity, and its page mirrors, INSIDE the caller's TransactionManager
     * run. Never upsert(): an existing row for the user and entity is
     * written only when it is not already a verified claim.
     *
     * Only a 'staff' claim may store no wallet ('' / chain 0), and it must
     * name the approving staff user; a proof claim names its proven address
     * and chain. The mirrors carry the same method. A page row that was a
     * TEAM row (administrator, representative) becomes the owner row; those
     * pages are returned so the caller logs the end of that membership.
     *
     * @param list<int> $pageIds the pages bound to the entity (boundPagesFor())
     * @return array{ok: true, claim_id: int, mirror_page_ids: list<int>, converted_team_roles: array<int, string>}|array{ok: false, reason: 'already_verified'}
     * @throws RepositoryReadFailure on a failed read
     * @throws \RuntimeException on a failed write
     * @throws \InvalidArgumentException on an invalid method / wallet combination
     * @throws \LogicException when called outside a TransactionManager run
     */
    public static function writeRelationshipClaim(
        int $userId,
        string $entityType,
        int $entityId,
        string $role,
        string $method,
        ?int $verifiedBy,
        string $walletAddress,
        int $chainId,
        array $pageIds
    ): array {
        if (!\BCC\Trust\Core\Security\TransactionManager::isInRunTransaction()) {
            throw new \LogicException('ClaimRepository::writeRelationshipClaim() must run inside a TransactionManager transaction');
        }
        if ($userId <= 0 || $entityId <= 0 || !in_array($entityType, ['validator', 'collection'], true)
            || !in_array($role, \BCC\Trust\Onchain\Services\PageClaimRolePolicy::OWNER_ROLES, true)
        ) {
            throw new \InvalidArgumentException('Invalid relationship claim target.');
        }
        if ($method === 'staff') {
            if ($walletAddress !== '' || $chainId !== 0 || $verifiedBy === null || $verifiedBy <= 0) {
                throw new \InvalidArgumentException('A staff claim stores no wallet and names its approver.');
            }
        } elseif (in_array($method, ['siws', 'solana_identity'], true)) {
            if (trim($walletAddress) === '' || $chainId <= 0 || $verifiedBy !== null) {
                throw new \InvalidArgumentException('A proof claim names its proven address and chain.');
            }
        } else {
            throw new \InvalidArgumentException('Unknown relationship claim method: ' . $method);
        }

        global $wpdb;
        $table = self::table();
        $now   = gmdate('Y-m-d H:i:s');
        $set   = [
            'wallet_address'      => $walletAddress,
            'chain_id'            => $chainId,
            'claim_role'          => $role,
            'status'              => ClaimStatus::VERIFIED,
            'verified_at'         => $now,
            'verification_method' => $method,
            'verified_by'         => $verifiedBy,
            'invited_by'          => null,
            'invite_expires_at'   => null,
            'accepted_at'         => null,
            'added_by_staff'      => 0,
            'reconfirmed'         => 0,
            'show_publicly'       => 0,
        ];

        $existing = self::lockedUserRow($userId, $entityType, $entityId);
        if ($existing !== null && $existing->status === ClaimStatus::VERIFIED) {
            return ['ok' => false, 'reason' => 'already_verified'];
        }
        $claimId = self::writeRow($existing, $set + ['user_id' => $userId, 'entity_type' => $entityType, 'entity_id' => $entityId, 'created_at' => $now]);

        $mirrors   = [];
        $converted = [];
        foreach (array_values(array_unique(array_map('intval', $pageIds))) as $pageId) {
            if ($pageId <= 0) {
                continue;
            }
            $row = self::lockedUserRow($userId, 'page', $pageId);
            if ($row !== null && $row->status === ClaimStatus::VERIFIED
                && in_array($row->claim_role, \BCC\Trust\Onchain\Services\PageClaimRolePolicy::OWNER_ROLES, true)
            ) {
                continue; // already this user's owner row, through another claim
            }
            if ($row !== null && \BCC\Trust\Onchain\Services\PageClaimRolePolicy::isTeamRole((string) $row->claim_role)
                && in_array($row->status, [ClaimStatus::INVITED, ClaimStatus::ACTIVE], true)
            ) {
                $converted[$pageId] = (string) $row->claim_role;
            }
            self::writeRow($row, $set + ['user_id' => $userId, 'entity_type' => 'page', 'entity_id' => $pageId, 'created_at' => $now]);
            $mirrors[] = $pageId;
        }

        return ['ok' => true, 'claim_id' => $claimId, 'mirror_page_ids' => $mirrors, 'converted_team_roles' => $converted];
    }

    /**
     * Every VERIFIED owner-role row (operator / creator) on one entity, any
     * user: a validator or collection claim, or a page row. Read fail-closed,
     * for exclusivity checks made under the entity's claim lock.
     *
     * @return list<array{claim_id: int, user_id: int, verification_method: string}>
     * @throws RepositoryReadFailure
     */
    public static function verifiedOwnerRowsForEntityStrict(string $entityType, int $entityId): array
    {
        if ($entityId <= 0 || !in_array($entityType, ['validator', 'collection', 'page'], true)) {
            return [];
        }
        global $wpdb;
        $table = self::table();

        /** @var list<object{id: numeric-string, user_id: numeric-string, verification_method: string}> $rows */
        $rows = self::runRead($wpdb->prepare(
            "SELECT id, user_id, verification_method FROM {$table}
              WHERE entity_type = %s AND entity_id = %d AND status = 'verified'
                AND claim_role IN ('operator', 'creator')
              ORDER BY id ASC LIMIT 50",
            $entityType,
            $entityId
        ), __FUNCTION__, true);

        $out = [];
        foreach ($rows as $row) {
            $out[] = ['claim_id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'verification_method' => (string) $row->verification_method];
        }

        return $out;
    }

    /**
     * The pages bound to an entity, read fail-closed (both binding legs).
     *
     * @return list<int>
     * @throws RepositoryReadFailure
     */
    public static function boundPagesFor(string $entityType, int $entityId): array
    {
        return self::boundPageIds($entityType, $entityId);
    }

    /**
     * Does the user hold the page through ANY verified claim bound to it?
     * Read fail-closed (a failed read throws, never reads as "no").
     *
     * @throws RepositoryReadFailure
     */
    public static function userHoldsPageThroughAnyClaim(int $userId, int $pageId): bool
    {
        if ($userId <= 0 || $pageId <= 0) {
            return false;
        }

        return self::otherVerifiedClaimBoundToPage($userId, 0, $pageId);
    }

    /**
     * The TEAM role (administrator / representative) the user holds on a
     * page through an invited or active row, read FOR UPDATE inside the
     * caller's transaction; null when their row (if any) is not a team row.
     *
     * @throws RepositoryReadFailure
     * @throws \LogicException when called outside a TransactionManager run
     */
    public static function teamRoleOnPageForUpdate(int $userId, int $pageId): ?string
    {
        if (!\BCC\Trust\Core\Security\TransactionManager::isInRunTransaction()) {
            throw new \LogicException('ClaimRepository::teamRoleOnPageForUpdate() must run inside a TransactionManager transaction');
        }
        if ($userId <= 0 || $pageId <= 0) {
            return null;
        }
        $row = self::lockedUserRow($userId, 'page', $pageId);
        if ($row !== null && \BCC\Trust\Onchain\Services\PageClaimRolePolicy::isTeamRole((string) $row->claim_role)
            && in_array($row->status, [ClaimStatus::INVITED, ClaimStatus::ACTIVE], true)
        ) {
            return (string) $row->claim_role;
        }

        return null;
    }

    /**
     * Does the account exist (wp_users)? Read FAIL-CLOSED: a failed read
     * THROWS, so "could not read" is never mistaken for "the account is
     * gone" (a departed owner) or "the account exists" (a link target).
     *
     * @throws RepositoryReadFailure
     */
    public static function accountExistsStrict(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }
        global $wpdb;

        /** @var list<object{ID: numeric-string}> $rows */
        $rows = self::runRead($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID = %d LIMIT 1",
            $userId
        ), __FUNCTION__, true);

        return $rows !== [];
    }

    /**
     * Did the user once own the page through a claim that is now REVOKED
     * (an owner-role page row in status revoked)? Separates an owner who
     * lost their claim from an author set some other way. Read fail-closed.
     *
     * @throws RepositoryReadFailure
     */
    public static function userHadRevokedOwnerRowOnPage(int $userId, int $pageId): bool
    {
        if ($userId <= 0 || $pageId <= 0) {
            return false;
        }
        global $wpdb;
        $table = self::table();

        /** @var list<object{id: numeric-string}> $rows */
        $rows = self::runRead($wpdb->prepare(
            "SELECT id FROM {$table}
              WHERE user_id = %d AND entity_type = 'page' AND entity_id = %d
                AND status = 'revoked' AND claim_role IN ('operator', 'creator')
              LIMIT 1",
            $userId,
            $pageId
        ), __FUNCTION__, true);

        return $rows !== [];
    }

    /**
     * The user's row for an entity, locked FOR UPDATE, or null.
     *
     * @return object{id: numeric-string, status: string, claim_role: string}|null
     * @throws RepositoryReadFailure
     */
    private static function lockedUserRow(int $userId, string $entityType, int $entityId): ?object
    {
        global $wpdb;
        $table = self::table();

        /** @var list<object{id: numeric-string, status: string, claim_role: string}> $rows */
        $rows = self::runRead($wpdb->prepare(
            "SELECT id, status, claim_role FROM {$table}
              WHERE user_id = %d AND entity_type = %s AND entity_id = %d FOR UPDATE",
            $userId,
            $entityType,
            $entityId
        ), __FUNCTION__, true);

        return $rows[0] ?? null;
    }

    /**
     * Insert $set, or update the existing (non-verified) row with it.
     *
     * @param object{id: numeric-string, status: string, claim_role: string}|null $existing
     * @param array<string, int|string|null> $set
     * @return int the row id
     * @throws \RuntimeException on a failed write
     */
    private static function writeRow(?object $existing, array $set): int
    {
        global $wpdb;
        $table = self::table();
        $wpdb->last_error = '';

        if ($existing === null) {
            $ok = $wpdb->insert($table, $set);
            if ($ok !== 1 || self::lastReadError() !== '') {
                throw new \RuntimeException('relationship claim insert failed: ' . (string) $wpdb->last_error);
            }
            return (int) $wpdb->insert_id;
        }

        unset($set['user_id'], $set['entity_type'], $set['entity_id'], $set['created_at']);
        $ok = $wpdb->update($table, $set, ['id' => (int) $existing->id, 'status' => (string) $existing->status]);
        if ($ok !== 1 || self::lastReadError() !== '') {
            throw new \RuntimeException('relationship claim update failed: ' . (string) $wpdb->last_error);
        }

        return (int) $existing->id;
    }

    /**
     * Pages bound to a claimed entity — the two legs of
     * ClaimService::isEntityBoundToPage, read FAIL-CLOSED.
     *
     * @return list<int>
     * @throws RepositoryReadFailure
     */
    private static function boundPageIds(string $entityType, int $entityId): array
    {
        global $wpdb;
        $wallets = \BCC\Core\DB\DB::table('wallet_links');

        if ($entityType === 'validator') {
            $validators = \BCC\Core\DB\DB::table('onchain_validators');
            $sql = $wpdb->prepare(
                "SELECT w.post_id AS page_id
                   FROM {$validators} v
                   JOIN {$wallets} w ON w.id = v.wallet_link_id
                  WHERE v.id = %d AND w.post_id > 0
                 UNION
                 SELECT pm.post_id AS page_id
                   FROM {$wpdb->postmeta} pm
                  WHERE pm.meta_key = '_bcc_onchain_validator_id'
                    AND CAST(pm.meta_value AS UNSIGNED) = %d
                    AND pm.post_id > 0
                  LIMIT 32",
                $entityId,
                $entityId
            );
        } else {
            $collections = \BCC\Core\DB\DB::table('onchain_collections');
            $sql = $wpdb->prepare(
                "SELECT w.post_id AS page_id
                   FROM {$collections} c
                   JOIN {$wallets} w ON w.id = c.wallet_link_id
                  WHERE c.id = %d AND w.post_id > 0
                  LIMIT 32",
                $entityId
            );
        }

        /** @var list<object{page_id: numeric-string}> $rows */
        $rows = self::runRead($sql, __FUNCTION__, true);

        $out = [];
        foreach ($rows as $row) {
            $out[] = (int) $row->page_id;
        }
        return array_values(array_unique($out));
    }

    /**
     * Does the user hold another VERIFIED validator/collection claim that is
     * bound to this page? Then the page mirror stays. Read FAIL-CLOSED.
     *
     * @throws RepositoryReadFailure
     */
    private static function otherVerifiedClaimBoundToPage(int $userId, int $exceptClaimId, int $pageId): bool
    {
        return self::verifiedClaimsBoundToPage($userId, $exceptClaimId, $pageId) > 0;
    }

    /**
     * F-334: does ANY user still hold a verified operator/creator claim bound
     * to this page — through the page's wallet link OR its placeholder
     * binding (`_bcc_onchain_validator_id`), the same rule revoke() uses? A
     * multi-chain page binds several validators, so another operator can still
     * hold it after one claim is revoked. Read FAIL-CLOSED: a caller about to
     * free a page must not read a failure as "nobody holds it".
     *
     * @throws RepositoryReadFailure
     */
    public static function anyPrimaryClaimBoundToPage(int $pageId): bool
    {
        if ($pageId <= 0) {
            return false;
        }

        return self::verifiedClaimsBoundToPage(null, 0, $pageId) > 0;
    }

    /**
     * Verified validator/collection claims bound to $pageId (wallet-link leg,
     * or the placeholder postmeta leg for validators). With $userId, that
     * user's claims of any role except $exceptClaimId; without, any user's
     * OPERATOR/CREATOR claims (a holder never holds a page).
     *
     * @throws RepositoryReadFailure
     */
    private static function verifiedClaimsBoundToPage(?int $userId, int $exceptClaimId, int $pageId): int
    {
        global $wpdb;
        $table       = self::table();
        $wallets     = \BCC\Core\DB\DB::table('wallet_links');
        $validators  = \BCC\Core\DB\DB::table('onchain_validators');
        $collections = \BCC\Core\DB\DB::table('onchain_collections');

        $who = $userId !== null
            ? $wpdb->prepare('cl.user_id = %d AND cl.id <> %d', $userId, $exceptClaimId)
            : "cl.claim_role IN ('operator','creator')";

        /** @var list<object{n: numeric-string}> $rows */
        $rows = self::runRead($wpdb->prepare(
            "SELECT COUNT(*) AS n
               FROM {$table} cl
              WHERE {$who}
                AND cl.status = 'verified'
                AND (
                     (cl.entity_type = 'validator' AND (
                          EXISTS (SELECT 1 FROM {$validators} v JOIN {$wallets} w ON w.id = v.wallet_link_id
                                   WHERE v.id = cl.entity_id AND w.post_id = %d)
                       OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm
                                   WHERE pm.meta_key = '_bcc_onchain_validator_id'
                                     AND pm.post_id = %d
                                     AND CAST(pm.meta_value AS UNSIGNED) = cl.entity_id)))
                  OR (cl.entity_type = 'collection' AND
                          EXISTS (SELECT 1 FROM {$collections} c JOIN {$wallets} w ON w.id = c.wallet_link_id
                                   WHERE c.id = cl.entity_id AND w.post_id = %d))
                )",
            $pageId,
            $pageId,
            $pageId
        ), __FUNCTION__, true);

        return (int) ($rows[0]->n ?? 0);
    }

    /**
     * F-14 PR B2: the re-verify sweep's keyset read — verified validator and
     * collection claims after a cursor, with what the sweep needs to decide
     * each one. FAIL-CLOSED (runRead): a DB error throws instead of reading
     * as "no more claims".
     *
     * The entity rows are LEFT JOINed, so an absent `entity_row_id` on a row
     * that WAS returned is a definite "the validator/collection row is gone"
     * — unlike ValidatorRepository::getByIdWithChain(), which reads a DB
     * error as null too.
     *
     * @return list<object{id: numeric-string, user_id: numeric-string, entity_type: string, entity_id: numeric-string, wallet_address: string, chain_id: numeric-string, claim_role: string, entity_row_id: numeric-string|null, operator_address: string|null, contract_address: string|null, entity_name: string|null, chain_slug: string|null, chain_type: string|null}>
     * @throws RepositoryReadFailure
     */
    public static function listVerifiedEntityClaimsAfter(int $afterId, int $limit): array
    {
        global $wpdb;
        $table       = self::table();
        $validators  = \BCC\Core\DB\DB::table('onchain_validators');
        $collections = \BCC\Core\DB\DB::table('onchain_collections');
        $chains      = \BCC\Core\DB\DB::table('chains');

        /** @var list<object{id: numeric-string, user_id: numeric-string, entity_type: string, entity_id: numeric-string, wallet_address: string, chain_id: numeric-string, claim_role: string, verification_method: string, entity_row_id: numeric-string|null, operator_address: string|null, contract_address: string|null, entity_name: string|null, chain_slug: string|null, chain_type: string|null}> $rows */
        $rows = self::runRead($wpdb->prepare(
            "SELECT cl.id, cl.user_id, cl.entity_type, cl.entity_id, cl.wallet_address, cl.chain_id, cl.claim_role,
                    cl.verification_method,
                    COALESCE(v.id, c.id)                 AS entity_row_id,
                    v.operator_address                   AS operator_address,
                    c.contract_address                   AS contract_address,
                    COALESCE(v.moniker, c.collection_name) AS entity_name,
                    ch.slug                              AS chain_slug,
                    ch.chain_type                        AS chain_type
               FROM {$table} cl
               LEFT JOIN {$validators}  v ON cl.entity_type = 'validator'  AND v.id = cl.entity_id
               LEFT JOIN {$collections} c ON cl.entity_type = 'collection' AND c.id = cl.entity_id
               LEFT JOIN {$chains}     ch ON ch.id = COALESCE(v.chain_id, c.chain_id)
              WHERE cl.id > %d
                AND cl.status = 'verified'
                AND cl.entity_type IN ('validator', 'collection')
              ORDER BY cl.id ASC
              LIMIT %d",
            max(0, $afterId),
            max(1, $limit)
        ), __FUNCTION__, true);

        return $rows;
    }
}
