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

        /** @var list<object{page_id: string, user_id: string}>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT w.post_id AS page_id, cl.user_id
             FROM {$table} cl
             JOIN {$validators} v ON v.id = cl.entity_id AND cl.entity_type = 'validator'
             JOIN {$wallets} w ON w.id = v.wallet_link_id
             WHERE w.post_id IN ({$ph})
               AND cl.status = 'verified' AND cl.claim_role = 'operator'
             ORDER BY w.post_id, cl.user_id",
            ...$ids
        ));

        /** @var array<int, array<int, true>> $byPage */
        $byPage = [];
        foreach ($rows ?: [] as $row) {
            $byPage[(int) $row->page_id][(int) $row->user_id] = true;
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
            /** @var list<object{page_id: string, user_id: string}>|null $metaRows */
            $metaRows = $wpdb->get_results($wpdb->prepare(
                "SELECT pm.post_id AS page_id, cl.user_id
                 FROM {$wpdb->postmeta} pm
                 JOIN {$validators} v ON v.id = CAST(pm.meta_value AS UNSIGNED)
                 JOIN {$table} cl ON cl.entity_id = v.id AND cl.entity_type = 'validator'
                 WHERE pm.meta_key = '_bcc_onchain_validator_id'
                   AND pm.post_id IN ({$phMissing})
                   AND cl.status = 'verified' AND cl.claim_role = 'operator'
                 ORDER BY pm.post_id, cl.user_id",
                ...$missing
            ));

            foreach ($metaRows ?: [] as $row) {
                $byPage[(int) $row->page_id][(int) $row->user_id] = true;
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
        $table = self::table();
        $none  = ['revoked' => false, 'user_id' => 0, 'entity_type' => '', 'entity_id' => 0, 'claim_role' => '', 'mirror_page_ids' => [], 'bound_page_ids' => []];

        if (\BCC\Trust\Core\Security\TransactionManager::isInRunTransaction()) {
            throw new \LogicException('ClaimRepository::revoke() must not run inside a TransactionManager transaction');
        }

        if ($claimId <= 0) {
            return $none;
        }

        if ($wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException('START TRANSACTION failed');
        }

        try {
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
                $wpdb->query('COMMIT');
                return $none;
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

            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException('COMMIT failed');
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
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
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
        global $wpdb;
        $table       = self::table();
        $wallets     = \BCC\Core\DB\DB::table('wallet_links');
        $validators  = \BCC\Core\DB\DB::table('onchain_validators');
        $collections = \BCC\Core\DB\DB::table('onchain_collections');

        /** @var list<object{n: numeric-string}> $rows */
        $rows = self::runRead($wpdb->prepare(
            "SELECT COUNT(*) AS n
               FROM {$table} cl
              WHERE cl.user_id = %d
                AND cl.id <> %d
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
            $userId,
            $exceptClaimId,
            $pageId,
            $pageId,
            $pageId
        ), __FUNCTION__, true);

        return (int) ($rows[0]->n ?? 0) > 0;
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

        /** @var list<object{id: numeric-string, user_id: numeric-string, entity_type: string, entity_id: numeric-string, wallet_address: string, chain_id: numeric-string, claim_role: string, entity_row_id: numeric-string|null, operator_address: string|null, contract_address: string|null, entity_name: string|null, chain_slug: string|null, chain_type: string|null}> $rows */
        $rows = self::runRead($wpdb->prepare(
            "SELECT cl.id, cl.user_id, cl.entity_type, cl.entity_id, cl.wallet_address, cl.chain_id, cl.claim_role,
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
