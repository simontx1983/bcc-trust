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
