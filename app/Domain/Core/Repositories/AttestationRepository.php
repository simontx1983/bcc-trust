<?php
/**
 * Attestation Repository — §J wire contract.
 *
 * DB access layer for the bcc_trust_attestations table. Per §1
 * architecture guardrail, this is the ONLY file that touches the
 * table via $wpdb. Service layer (AttestationService) orchestrates;
 * REST endpoints consume views; this owns the SQL.
 *
 * Phase 1 scope: the minimum queries needed to light up the FE's
 * `viewer_attestation` field. Roster queries, eligibility queries,
 * and synthesis-input aggregates land in subsequent slices.
 *
 * @package BCC\Trust\Core\Repositories
 * @since V2 Trust Attestation Layer (2026-05-13)
 */

namespace BCC\Trust\Core\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Core\Security\TransactionManager;
use RuntimeException;
use wpdb;

/**
 * @phpstan-type AttestationRow object{
 *   id: int|numeric-string,
 *   attestor_user_id: int|numeric-string,
 *   target_kind: string,
 *   target_id: int|numeric-string,
 *   kind: string,
 *   weight_at_time: float|numeric-string,
 *   context_note: string|null,
 *   attestation_order_in_target: int|numeric-string,
 *   created_at: string,
 *   reaffirmed_at: string|null,
 *   revoked_at: string|null
 * }
 */
final class AttestationRepository
{
    /**
     * Allowed target_kind values per §J.6 / api-contract-v1.md §4.20.
     * Enforced in application code (not as a DB enum) per the §J.5.1
     * Phase 1 plan note — allows future extension without ALTER TABLE.
     *
     * @var list<string>
     */
    public const TARGET_KINDS = [
        'user_profile',
        'validator_card',
        'project_card',
        'creator_card',
    ];

    /**
     * Allowed kind values for V1 per §J.1. Dispute lives in a
     * separate table (stake + panel mechanics don't fit this shape).
     *
     * @var list<string>
     */
    public const KINDS = ['vouch', 'stand_behind'];

    /**
     * Explicit column projection for SELECTs — no SELECT * per §2
     * architecture guardrail.
     *
     * @phpstan-var literal-string
     */
    private const COLUMNS = 'id, attestor_user_id, target_kind, target_id, kind, weight_at_time, context_note, attestation_order_in_target, created_at, reaffirmed_at, revoked_at';

    /**
     * Find the viewer's active (non-revoked) attestations against a
     * specific target. Returns rows for both kinds in a single
     * round-trip — feeds the FE's `viewer_attestation` field which
     * always carries both `vouch` and `stand_behind` slots.
     *
     * @return array{vouch: object|null, stand_behind: object|null}
     */
    public function findActiveByAttestorAndTarget(
        int $attestorUserId,
        string $targetKind,
        int $targetId
    ): array {
        $emptyResult = ['vouch' => null, 'stand_behind' => null];

        if (!in_array($targetKind, self::TARGET_KINDS, true)) {
            return $emptyResult;
        }
        if ($attestorUserId <= 0 || $targetId <= 0) {
            return $emptyResult;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        // Bounded query: at most 2 rows by structure (one per kind
        // per (attestor, target_kind, target_id) per the unique key).
        // The LIMIT is belt-and-suspenders.
        //
        // SQL is concatenated from the literal COLUMNS const + the
        // trusted table name + a literal WHERE clause, matching the
        // canonical NotificationRepository pattern. Parameters bind
        // via prepare() in the standard way. phpstan-wordpress's
        // literal-string requirement on prepare()'s first arg is
        // suppressed because $table comes from the trusted
        // TableRegistry singleton (never user input).
        $sql = "SELECT " . self::COLUMNS
            . " FROM `{$table}`"
            . " WHERE attestor_user_id = %d"
            . " AND target_kind = %s"
            . " AND target_id = %d"
            . " AND revoked_at IS NULL"
            . " LIMIT 2";

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $attestorUserId, $targetKind, $targetId);
        if (!is_string($prepared)) {
            return $emptyResult;
        }

        $rows = $wpdb->get_results($prepared);
        if (!is_array($rows)) {
            return $emptyResult;
        }

        $result = $emptyResult;
        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->kind) || !is_string($row->kind)) {
                continue;
            }
            if ($row->kind === 'vouch') {
                $result['vouch'] = $row;
            } elseif ($row->kind === 'stand_behind') {
                $result['stand_behind'] = $row;
            }
        }

        return $result;
    }

    /**
     * Find one attestation by id. Returns the raw row (with all
     * columns including soft-delete state) — caller decides what to
     * surface based on revoked_at + owner.
     *
     * Used by revoke() / reaffirm() for ownership + state checks.
     */
    public function findOneById(int $id): ?object
    {
        if ($id <= 0) {
            return null;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = 'SELECT ' . self::COLUMNS
            . " FROM `{$table}`"
            . ' WHERE id = %d'
            . ' LIMIT 1';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $id);
        if (!is_string($prepared)) {
            return null;
        }

        $row = $wpdb->get_row($prepared);
        return is_object($row) ? $row : null;
    }

    /**
     * Find the active (non-revoked) row for a single (attestor, target,
     * kind) tuple. Used by cast() for the idempotency check.
     *
     * When $forUpdate is true the caller MUST be inside
     * TransactionManager::run() — the FOR UPDATE clause locks the
     * unique-key range so a concurrent cast() can't slip past the
     * existence check and try to double-insert.
     */
    public function findActiveOneByAttestorTargetKind(
        int $attestorUserId,
        string $targetKind,
        int $targetId,
        string $kind,
        bool $forUpdate = false
    ): ?object {
        if ($attestorUserId <= 0 || $targetId <= 0) {
            return null;
        }
        if (!in_array($targetKind, self::TARGET_KINDS, true)) {
            return null;
        }
        if (!in_array($kind, self::KINDS, true)) {
            return null;
        }
        if ($forUpdate && !TransactionManager::isInRunTransaction()) {
            throw new RuntimeException(
                'AttestationRepository::findActiveOneByAttestorTargetKind('
                . '… forUpdate=true) requires TransactionManager::run().'
            );
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = 'SELECT ' . self::COLUMNS
            . " FROM `{$table}`"
            . ' WHERE attestor_user_id = %d'
            . ' AND target_kind = %s'
            . ' AND target_id = %d'
            . ' AND kind = %s'
            . ' AND revoked_at IS NULL'
            . ' LIMIT 1'
            . ($forUpdate ? ' FOR UPDATE' : '');

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $attestorUserId, $targetKind, $targetId, $kind);
        if (!is_string($prepared)) {
            return null;
        }

        $row = $wpdb->get_row($prepared);
        return is_object($row) ? $row : null;
    }

    /**
     * Count active stand_behind attestations cast by this attestor.
     * Drives the §J.1 bandwidth slot model (Elite 7 / Trusted 5 /
     * Neutral 3 / Caution+Risky 0).
     *
     * Counts across ALL target_kinds — slots are a per-operator
     * resource, not a per-target-kind resource. An operator with 3
     * active stand_behinds (one on a user_profile + two on cards) has
     * 3 slots used regardless of distribution.
     *
     * Bounded by the §J.1 max slot ceiling (Elite=7 baseline + 3
     * graduated cap = 10); LIMIT 50 is belt-and-suspenders.
     */
    public function countActiveStandBehindByAttestor(int $attestorUserId): int
    {
        if ($attestorUserId <= 0) {
            return 0;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = "SELECT COUNT(*) FROM `{$table}`"
            . ' WHERE attestor_user_id = %d'
            . " AND kind = 'stand_behind'"
            . ' AND revoked_at IS NULL'
            . ' LIMIT 50';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $attestorUserId);
        if (!is_string($prepared)) {
            return 0;
        }

        $raw = $wpdb->get_var($prepared);
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Compute the next per-target order number for a new attestation.
     * Used to populate `attestation_order_in_target` so the FE can
     * surface `is_pre_consensus_pick` for the first-mover positions
     * per §J.1.
     *
     * MUST be called inside TransactionManager::run() with the relevant
     * per-target advisory lock held — otherwise concurrent casts on
     * the same target produce duplicate order numbers.
     */
    public function nextOrderInTarget(string $targetKind, int $targetId): int
    {
        if (!in_array($targetKind, self::TARGET_KINDS, true) || $targetId <= 0) {
            return 0;
        }
        if (!TransactionManager::isInRunTransaction()) {
            throw new RuntimeException(
                'AttestationRepository::nextOrderInTarget() requires TransactionManager::run().'
            );
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = "SELECT COALESCE(MAX(attestation_order_in_target), 0) + 1"
            . " FROM `{$table}`"
            . ' WHERE target_kind = %s'
            . ' AND target_id = %d';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $targetKind, $targetId);
        if (!is_string($prepared)) {
            return 1;
        }

        $raw = $wpdb->get_var($prepared);
        $next = is_numeric($raw) ? (int) $raw : 1;
        return $next > 0 ? $next : 1;
    }

    /**
     * Insert a new attestation row. Caller MUST:
     *   - be inside TransactionManager::run()
     *   - have already confirmed no active row exists via
     *     findActiveOneByAttestorTargetKind(..., forUpdate=true)
     *   - hold the per-attestor advisory lock for the duration
     *
     * Returns the new row id. Throws on insert failure (including
     * unique-key violation — that's a race past the FOR UPDATE check
     * and indicates a caller bug, not normal flow).
     */
    public function insert(
        int $attestorUserId,
        string $targetKind,
        int $targetId,
        string $kind,
        float $weight,
        ?string $contextNote,
        int $orderInTarget
    ): int {
        if (!TransactionManager::isInRunTransaction()) {
            throw new RuntimeException(
                'AttestationRepository::insert() requires TransactionManager::run().'
            );
        }
        if (!in_array($targetKind, self::TARGET_KINDS, true)) {
            throw new RuntimeException('Invalid target_kind: ' . $targetKind);
        }
        if (!in_array($kind, self::KINDS, true)) {
            throw new RuntimeException('Invalid kind: ' . $kind);
        }
        if ($attestorUserId <= 0 || $targetId <= 0) {
            throw new RuntimeException('Invalid attestor or target id.');
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $result = $wpdb->insert(
            $table,
            [
                'attestor_user_id'             => $attestorUserId,
                'target_kind'                  => $targetKind,
                'target_id'                    => $targetId,
                'kind'                         => $kind,
                'weight_at_time'               => $weight,
                'context_note'                 => $contextNote,
                'attestation_order_in_target'  => $orderInTarget,
                'created_at'                   => current_time('mysql'),
            ],
            ['%d', '%s', '%d', '%s', '%f', '%s', '%d', '%s']
        );

        if ($result === false) {
            throw new RuntimeException(
                'AttestationRepository::insert failed: ' . $wpdb->last_error
            );
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Soft-revoke an attestation by setting revoked_at = NOW(). Scoped
     * to `revoked_at IS NULL` so a concurrent revoke that races us
     * sees affected_rows = 0 — caller MUST gate cache invalidation +
     * audit log + notification dispatch on the return value to avoid
     * double-firing.
     *
     * Ownership is enforced by the WHERE attestor_user_id clause: a
     * stale or forged id from another user produces affected = 0 and
     * the caller surfaces 404, never a leak.
     *
     * Caller MUST be inside TransactionManager::run() with the per-
     * attestor advisory lock held.
     *
     * @return bool True on real state transition (audit + notify);
     *              false on no-op (already revoked, or not owned).
     */
    public function softRevoke(int $id, int $attestorUserId): bool
    {
        if ($id <= 0 || $attestorUserId <= 0) {
            return false;
        }
        if (!TransactionManager::isInRunTransaction()) {
            throw new RuntimeException(
                'AttestationRepository::softRevoke() requires TransactionManager::run().'
            );
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $result = $wpdb->update(
            $table,
            ['revoked_at' => current_time('mysql')],
            [
                'id'               => $id,
                'attestor_user_id' => $attestorUserId,
                'revoked_at'       => null,
            ],
            ['%s'],
            ['%d', '%d', '%s']
        );

        if ($result === false) {
            throw new RuntimeException(
                'AttestationRepository::softRevoke failed: ' . $wpdb->last_error
            );
        }

        return (int) $result > 0;
    }

    /**
     * Allowed kind filter values for the §J.4 roster query. 'all' maps
     * to "no kind filter applied"; the other two pin the result set
     * to one kind.
     *
     * @var list<string>
     */
    public const LIST_KIND_FILTERS = ['vouch', 'stand_behind', 'all'];

    /**
     * Allowed sort modes for the §J.4 roster query. All three are
     * server-side ORDER BY clauses; the response NEVER exposes the
     * numeric weight that drives the sort (§J.4.1 synthesis
     * invisibility).
     *
     * @var list<string>
     */
    public const LIST_SORT_MODES = ['decayed_weight', 'recency', 'reliability'];

    /** Maximum rows the slot_holders picker payload returns. §J.1 ceiling = 10. */
    private const SLOT_HOLDERS_HARD_CAP = 10;

    /**
     * Paged roster query for `GET /entities/:target_kind/:target_id/
     * attestations` per §J.4. Returns raw rows (caller composes the
     * §J.4 view-model + hydrates the attestor mini-view via
     * UserViewService::getSummary).
     *
     * Pagination is page-based (1-indexed) per the locked contract
     * shape; the repo translates to offset/limit internally.
     *
     * Sort modes (V1):
     *   - decayed_weight (default): weight_at_time DESC, created_at DESC.
     *     V1 has no decay function; this is the same ordering decay
     *     would produce on a roster where every row has age = 0. Slice E
     *     replaces with the read-time decay function.
     *   - recency: created_at DESC, id DESC. Stable tiebreaker on id
     *     so two rows with identical second-precision created_at
     *     don't flicker between paginations.
     *   - reliability: V1 collapses to decayed_weight semantics —
     *     reliability ordering requires the Operator Reliability
     *     synthesis (Slice E). Documented + filterable so consumers
     *     can still pass the param; the order they receive matches
     *     decayed_weight until Slice E ships.
     *
     * include_revoked controls whether soft-deleted rows are returned.
     * Default false (active-only roster); when true, revoked rows
     * append AFTER active rows regardless of sort (revoked is
     * archival, not interleaved with the active list).
     *
     * @param array{
     *   kind?: string,
     *   include_revoked?: bool,
     *   sort?: string,
     *   page?: int,
     *   per_page?: int
     * } $options
     * @return list<object>
     */
    public function listByTarget(
        string $targetKind,
        int $targetId,
        array $options = []
    ): array {
        if (!in_array($targetKind, self::TARGET_KINDS, true)) {
            return [];
        }
        if ($targetId <= 0) {
            return [];
        }

        $kind            = isset($options['kind']) && is_string($options['kind'])
            ? $options['kind']
            : 'all';
        if (!in_array($kind, self::LIST_KIND_FILTERS, true)) {
            $kind = 'all';
        }

        $includeRevoked  = !empty($options['include_revoked']);

        $sort = isset($options['sort']) && is_string($options['sort'])
            ? $options['sort']
            : 'decayed_weight';
        if (!in_array($sort, self::LIST_SORT_MODES, true)) {
            $sort = 'decayed_weight';
        }

        $page     = isset($options['page']) ? max(1, (int) $options['page']) : 1;
        $perPage  = isset($options['per_page']) ? (int) $options['per_page'] : 24;
        if ($perPage < 1) {
            $perPage = 24;
        }
        if ($perPage > 50) {
            $perPage = 50;
        }
        $offset = ($page - 1) * $perPage;

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        // Sort + revoked-handling order:
        //   - active-only: pure ORDER BY by sort mode.
        //   - include-revoked: active rows first, revoked rows after.
        //     Phillip's note: revoked is archival; preserves "no hiding
        //     the past" without interleaving the dead with the living.
        // V1 sort: only 'recency' produces a distinct ORDER BY.
        // 'reliability' and 'decayed_weight' both collapse to the
        // weight-then-recency ordering until Slice E ships real
        // decay + reliability synthesis. We accept all three from
        // the contract surface; the FE renders the order it gets.
        $orderBy = $sort === 'recency'
            ? 'created_at DESC, id DESC'
            : 'weight_at_time DESC, created_at DESC';
        if ($includeRevoked) {
            // Active-first secondary: rows with revoked_at IS NULL
            // come before revoked rows. ISNULL(col) returns 1 for
            // NULL values, so we order by it DESC to put NULLs first.
            $orderBy = 'ISNULL(revoked_at) DESC, ' . $orderBy;
        }

        $where = ['target_kind = %s', 'target_id = %d'];
        $args  = [$targetKind, $targetId];

        if ($kind !== 'all') {
            $where[] = 'kind = %s';
            $args[]  = $kind;
        }
        if (!$includeRevoked) {
            $where[] = 'revoked_at IS NULL';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $sql = 'SELECT ' . self::COLUMNS
            . " FROM `{$table}`"
            . ' ' . $whereSql
            . ' ORDER BY ' . $orderBy
            . ' LIMIT %d OFFSET %d';

        $args[] = $perPage;
        $args[] = $offset;

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, ...$args);
        if (!is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Single-query aggregate for the §J.4 summary block. Returns
     * { vouch_count, stand_behind_count, total } — counts ACTIVE
     * attestations only (revoked rows are excluded from the summary
     * regardless of `include_revoked` on the list query; the summary
     * represents living evidence, not historical).
     *
     * @return array{vouch_count: int, stand_behind_count: int, total: int}
     */
    public function countByTarget(string $targetKind, int $targetId): array
    {
        $empty = ['vouch_count' => 0, 'stand_behind_count' => 0, 'total' => 0];
        if (!in_array($targetKind, self::TARGET_KINDS, true)) {
            return $empty;
        }
        if ($targetId <= 0) {
            return $empty;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = "SELECT kind, COUNT(*) AS c"
            . " FROM `{$table}`"
            . ' WHERE target_kind = %s'
            . ' AND target_id = %d'
            . ' AND revoked_at IS NULL'
            . ' GROUP BY kind'
            . ' LIMIT 10';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $targetKind, $targetId);
        if (!is_string($prepared)) {
            return $empty;
        }

        $rows = $wpdb->get_results($prepared);
        if (!is_array($rows)) {
            return $empty;
        }

        $result = $empty;
        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->kind, $row->c)) {
                continue;
            }
            $kind  = (string) $row->kind;
            $count = (int) $row->c;
            if ($kind === 'vouch') {
                $result['vouch_count'] = $count;
            } elseif ($kind === 'stand_behind') {
                $result['stand_behind_count'] = $count;
            }
        }
        $result['total'] = $result['vouch_count'] + $result['stand_behind_count'];
        return $result;
    }

    /**
     * List active stand_behind attestations cast BY this operator.
     * Drives the §J.2 `slot_holders[]` picker payload returned when
     * a cast hits `bcc_attestation_bandwidth_exhausted`. Bounded by
     * the §J.1 ceiling (Elite=7 baseline + 3 graduated = max 10 ever).
     *
     * Sort: created_at ASC so the user sees their oldest commitment
     * first in the picker. Phillip's note: the picker should feel
     * reflective, not inventory-like — showing oldest first nudges
     * "which of these has changed since I cast it" rather than
     * "which is least valuable to keep."
     *
     * @return list<object>
     */
    public function listActiveStandBehindByAttestor(
        int $attestorUserId,
        int $limit = self::SLOT_HOLDERS_HARD_CAP
    ): array {
        if ($attestorUserId <= 0) {
            return [];
        }
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > self::SLOT_HOLDERS_HARD_CAP) {
            $limit = self::SLOT_HOLDERS_HARD_CAP;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = 'SELECT ' . self::COLUMNS
            . " FROM `{$table}`"
            . ' WHERE attestor_user_id = %d'
            . " AND kind = 'stand_behind'"
            . ' AND revoked_at IS NULL'
            . ' ORDER BY created_at ASC, id ASC'
            . ' LIMIT %d';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $attestorUserId, $limit);
        if (!is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Mark an attestation as reaffirmed by setting reaffirmed_at = NOW().
     * Scoped to `revoked_at IS NULL` so reaffirming a revoked row is
     * structurally impossible — caller should already have surfaced
     * `bcc_attestation_revoked` (409) before calling, but this is the
     * load-bearing invariant.
     *
     * Returns true on real transition (affected_rows > 0); false on
     * no-op (row already revoked, not owned, or doesn't exist).
     *
     * Caller MUST be inside TransactionManager::run().
     */
    public function markReaffirmed(int $id, int $attestorUserId): bool
    {
        if ($id <= 0 || $attestorUserId <= 0) {
            return false;
        }
        if (!TransactionManager::isInRunTransaction()) {
            throw new RuntimeException(
                'AttestationRepository::markReaffirmed() requires TransactionManager::run().'
            );
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $result = $wpdb->update(
            $table,
            ['reaffirmed_at' => current_time('mysql')],
            [
                'id'               => $id,
                'attestor_user_id' => $attestorUserId,
                'revoked_at'       => null,
            ],
            ['%s'],
            ['%d', '%d', '%s']
        );

        if ($result === false) {
            throw new RuntimeException(
                'AttestationRepository::markReaffirmed failed: ' . $wpdb->last_error
            );
        }

        return (int) $result > 0;
    }
}
