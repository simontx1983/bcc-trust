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
     * ── INVARIANT: target_id semantics differ by kind ──────────────────
     * `user_profile`  → target_id is a RAW user id. Its trust-score row is
     *                   `MemberSelfPageService::selfPageId(target_id)`,
     *                   NOT `target_id`. The card kinds below carry a
     *                   peepso-page post id directly (see PAGE_TARGET_KINDS).
     * Any code that maps a `user_profile` attestation onto a trust-score row
     * (e.g. the deferred §J "Slice E" synthesis that folds attestations into
     * the self-page score) MUST translate through
     * `MemberSelfPageService::selfPageId()` — using `target_id` as a page id
     * would silently score the wrong row (or nothing). Locked by
     * MemberSelfPageTest::testUserProfileAttestationTargetResolvesToSelfPage.
     * ───────────────────────────────────────────────────────────────────
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
     * The subset of TARGET_KINDS whose `target_id` is a peepso-page post
     * id (validator / project / creator cards all key on the page — see
     * CardViewService::cardKindToTargetKind). `user_profile` is excluded
     * because its target_id is a user id. Used by the page-deletion
     * cleanup cascade so a deleted page's card-attestations don't orphan.
     *
     * @var list<string>
     */
    public const PAGE_TARGET_KINDS = [
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
     * Batch variant of findActiveByAttestorAndTarget — one bounded
     * query for a whole cards-list page of targets instead of N
     * per-card round trips. Used by PageCardPrefetcher to feed the
     * `viewer_attestation` field on every card in the list.
     *
     * Same COLUMNS projection and WHERE predicate as the single-target
     * method (attestor + target_kind + target_id IN + revoked_at IS
     * NULL) — kept in lockstep so batch and single answers can never
     * diverge.
     *
     * Every requested target id is present in the result (empty slots
     * for targets the viewer hasn't attested against), so consumers
     * can index without an existence check.
     *
     * Bounded: the IN-list is caller-paginated (cards per_page ≤ 50)
     * and the unique key caps rows at 2 per (attestor, target); the
     * LIMIT is belt-and-suspenders at 2 × 50.
     *
     * @param list<int> $targetIds
     * @return array<int, array{vouch: object|null, stand_behind: object|null}>
     *     Keyed by target_id.
     */
    public function findActiveByAttestorForTargets(
        int $attestorUserId,
        string $targetKind,
        array $targetIds
    ): array {
        if ($attestorUserId <= 0) {
            return [];
        }
        if (!in_array($targetKind, self::TARGET_KINDS, true)) {
            return [];
        }

        $ids = [];
        foreach ($targetIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        $result = [];
        foreach (array_keys($ids) as $id) {
            $result[$id] = ['vouch' => null, 'stand_behind' => null];
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT " . self::COLUMNS
            . " FROM `{$table}`"
            . " WHERE attestor_user_id = %d"
            . " AND target_kind = %s"
            . " AND target_id IN ({$placeholders})"
            . " AND revoked_at IS NULL"
            . " LIMIT 100";

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $attestorUserId, $targetKind, ...array_keys($ids));
        if (!is_string($prepared)) {
            return $result;
        }

        $rows = $wpdb->get_results($prepared);
        if (!is_array($rows)) {
            return $result;
        }

        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->kind, $row->target_id) || !is_string($row->kind)) {
                continue;
            }
            $targetId = (int) $row->target_id;
            if (!isset($result[$targetId])) {
                continue;
            }
            if ($row->kind === 'vouch') {
                $result[$targetId]['vouch'] = $row;
            } elseif ($row->kind === 'stand_behind') {
                $result[$targetId]['stand_behind'] = $row;
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
     * Lifetime attestation count for an attestor — every row ever
     * inserted, including revoked. Drives `since_attestation_count`
     * on the §J.5 self-mirror surface so the operator sees a real
     * "how much have I done" anchor even before Slice E's outcome
     * classifier populates `track_record.outcomes`.
     *
     * Counts vouch + stand_behind across all target_kinds. Bounded
     * with a defensive LIMIT — a single operator's lifetime cast
     * count is realistically dozens, not thousands.
     */
    public function countAllByAttestor(int $attestorUserId): int
    {
        if ($attestorUserId <= 0) {
            return 0;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = "SELECT COUNT(*) FROM `{$table}`"
            . ' WHERE attestor_user_id = %d'
            . ' LIMIT 10000';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $attestorUserId);
        if (!is_string($prepared)) {
            return 0;
        }

        $raw = $wpdb->get_var($prepared);
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Has-recent-activity probe for the dormancy detector.
     *
     * Counts attestation rows where the attestor (a) cast a new row,
     * (b) reaffirmed an existing row, or (c) revoked an existing row
     * since the supplied UTC datetime. Any of the three counts as a
     * "sign of life" for §J.4 `attestor.is_dormant` — we don't want
     * to mark an operator dormant just because they haven't cast
     * NEW rows; a revoke is still active judgment.
     *
     * Bounded with LIMIT defensively — for the dormancy threshold
     * (60-day window) the realistic count is single digits per user.
     *
     * @param string $sinceMysqlUtc UTC datetime in MySQL format ("YYYY-MM-DD HH:MM:SS").
     */
    public function countByActorSince(int $attestorUserId, string $sinceMysqlUtc): int
    {
        if ($attestorUserId <= 0 || $sinceMysqlUtc === '') {
            return 0;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        // Three-way OR: created / reaffirmed / revoked since $since.
        // `created_at >= ?` is index-covered via idx_attestor_active
        // (attestor_user_id, kind, revoked_at, created_at DESC). The
        // other two cases scan non-indexed columns within the
        // attestor's row subset; at our scale (≤ dozens of rows per
        // attestor) this is cheap. LIMIT 1000 keeps a misbehaving
        // dataset from running away.
        //
        // String concatenation (vs a multi-line "..." literal) keeps
        // PHPStan's literal-string inference happy — same idiom as
        // `countAllByAttestor` and `countActiveStandBehindByAttestor`
        // above.
        $sql = "SELECT COUNT(*) FROM `{$table}`"
            . ' WHERE attestor_user_id = %d'
            . ' AND (created_at >= %s OR reaffirmed_at >= %s OR revoked_at >= %s)'
            . ' LIMIT 1000';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $attestorUserId, $sinceMysqlUtc, $sinceMysqlUtc, $sinceMysqlUtc);
        if (!is_string($prepared)) {
            return 0;
        }

        $raw = $wpdb->get_var($prepared);
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * How many distinct targets has this attestor cast ACTIVE
     * attestations on, for the given target_kind? Drives the
     * "attestor's cohort size" denominator in
     * `CohortOverlapDampener` — % overlap with target's cohort.
     *
     * Counts vouch + stand_behind, active rows only (revoked
     * attestations represent withdrawn judgment and shouldn't
     * inflate the cohort denominator).
     *
     * Bounded with LIMIT 5000 — a single operator's lifetime
     * distinct-target count is realistically dozens to low hundreds.
     */
    public function countDistinctTargetsByAttestor(int $attestorUserId, string $targetKind): int
    {
        if ($attestorUserId <= 0) {
            return 0;
        }
        if (!in_array($targetKind, self::TARGET_KINDS, true)) {
            return 0;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = "SELECT COUNT(DISTINCT target_id) FROM `{$table}`"
            . ' WHERE attestor_user_id = %d'
            . ' AND target_kind = %s'
            . ' AND revoked_at IS NULL'
            . ' LIMIT 5000';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $attestorUserId, $targetKind);
        if (!is_string($prepared)) {
            return 0;
        }

        $raw = $wpdb->get_var($prepared);
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Size of the intersection between attestor's cohort and
     * target's attestor-cohort, scoped to `target_kind='user_profile'`:
     *
     *   |{ users U : attestor → U active AND U → target active }|
     *
     * In words: "how many of the operators I've vouched/backed
     * on have also vouched/backed on this target?" Drives the
     * `CohortOverlapDampener` numerator.
     *
     * Self-join across two row sets via the `target_id = attestor_user_id`
     * bridge. Both sides bounded by `target_kind='user_profile'` and
     * `revoked_at IS NULL`; LIMIT belt-and-suspenders applies on the
     * outer COUNT in case a misbehaving dataset goes nuts (realistic
     * intersection sizes are dozens).
     */
    public function countOverlapBetweenAttestorAndTarget(int $attestorUserId, int $targetUserId): int
    {
        if ($attestorUserId <= 0 || $targetUserId <= 0 || $attestorUserId === $targetUserId) {
            return 0;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        // a.target_id is the user the attestor cast on;
        // b.attestor_user_id is anyone who has cast on the target.
        // Equal a.target_id = b.attestor_user_id ⇒ that user is in
        // both sets ⇒ counted once via DISTINCT.
        $sql = "SELECT COUNT(DISTINCT a.target_id) FROM `{$table}` a"
            . " INNER JOIN `{$table}` b ON b.attestor_user_id = a.target_id"
            . " WHERE a.attestor_user_id = %d"
            . " AND a.target_kind = 'user_profile'"
            . " AND a.revoked_at IS NULL"
            . " AND b.target_id = %d"
            . " AND b.target_kind = 'user_profile'"
            . " AND b.revoked_at IS NULL"
            . ' LIMIT 5000';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $attestorUserId, $targetUserId);
        if (!is_string($prepared)) {
            return 0;
        }

        $raw = $wpdb->get_var($prepared);
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Distinct (target_kind, target_id) pairs that saw ANY attestation
     * activity (cast / reaffirm / revoke) since the given timestamp.
     * Drives the `PolarizationTransitionNotifier::sweep()` candidate
     * set so the daily worker only re-classifies entities that
     * actually moved, not the entire universe.
     *
     * Bounded by LIMIT (50k cap — soft fence against a runaway dataset;
     * realistic daily activity is dozens to low hundreds of targets).
     *
     * @param string $sinceMysqlUtc UTC datetime "YYYY-MM-DD HH:MM:SS".
     * @return list<array{target_kind: string, target_id: int}>
     */
    public function listTargetsWithRecentActivity(string $sinceMysqlUtc): array
    {
        if ($sinceMysqlUtc === '') {
            return [];
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = "SELECT DISTINCT target_kind, target_id FROM `{$table}`"
            . ' WHERE created_at >= %s OR reaffirmed_at >= %s OR revoked_at >= %s'
            . ' LIMIT 50000';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $sinceMysqlUtc, $sinceMysqlUtc, $sinceMysqlUtc);
        if (!is_string($prepared)) {
            return [];
        }

        /** @var list<object{target_kind: string, target_id: numeric-string}>|null $rows */
        $rows = $wpdb->get_results($prepared);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->target_kind, $row->target_id)) {
                continue;
            }
            $out[] = [
                'target_kind' => (string) $row->target_kind,
                'target_id'   => (int) $row->target_id,
            ];
        }
        return $out;
    }

    /**
     * Count REVOKED attestations against a target (across all kinds).
     * Drives the `poorly_regarded` heuristic in `DivergenceStateClassifier`:
     * when the revoked-to-active ratio crosses the threshold, the target
     * is flagged as poorly regarded.
     *
     * Counts vouch + stand_behind where `revoked_at IS NOT NULL`. Bounded
     * with LIMIT — realistic revocation count per target is dozens.
     */
    public function countRevokedByTarget(string $targetKind, int $targetId): int
    {
        if (!in_array($targetKind, self::TARGET_KINDS, true)) {
            return 0;
        }
        if ($targetId <= 0) {
            return 0;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = "SELECT COUNT(*) FROM `{$table}`"
            . ' WHERE target_kind = %s'
            . ' AND target_id = %d'
            . ' AND revoked_at IS NOT NULL'
            . ' LIMIT 10000';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $targetKind, $targetId);
        if (!is_string($prepared)) {
            return 0;
        }

        $raw = $wpdb->get_var($prepared);
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Grouped variant of countByTarget's active total — one bounded
     * GROUP BY query for a whole cards-list page of targets. Same
     * WHERE predicate (target_kind + target_id IN + revoked_at IS
     * NULL); collapses the per-kind split because the only batch
     * consumer (DivergenceStateClassifier via PageCardPrefetcher)
     * reads the total only.
     *
     * Targets with zero active attestations are absent from the map —
     * consumers default with `?? 0`.
     *
     * Bounded: caller-paginated IN-list (cards per_page ≤ 50), one
     * row per target via GROUP BY.
     *
     * @param list<int> $targetIds
     * @return array<int, int> target_id => active attestation count.
     */
    public function countActiveByTargets(string $targetKind, array $targetIds): array
    {
        return $this->countByTargetsWhereRevoked($targetKind, $targetIds, false);
    }

    /**
     * Grouped variant of countRevokedByTarget — one bounded GROUP BY
     * query for a whole cards-list page of targets. Same WHERE
     * predicate (target_kind + target_id IN + revoked_at IS NOT NULL).
     *
     * Targets with zero revoked attestations are absent from the map —
     * consumers default with `?? 0`.
     *
     * @param list<int> $targetIds
     * @return array<int, int> target_id => revoked attestation count.
     */
    public function countRevokedByTargets(string $targetKind, array $targetIds): array
    {
        return $this->countByTargetsWhereRevoked($targetKind, $targetIds, true);
    }

    /**
     * Grouped count of active VOUCH attestations per target — the
     * kind-filtered sibling of {@see countActiveByTargets}. Powers the
     * `endorsements_received` member-card stat (MemberSummaryPrefetcher /
     * FeedColdStartService / UserViewService) after the legacy
     * bcc_trust_endorsements read retirement: "received" now means
     * active vouches cast ON the member (target_kind='user_profile',
     * keyed by RAW user id), replacing the old endorsements-on-owned-pages
     * join.
     *
     * Targets with zero active vouches are absent from the map —
     * consumers default with `?? 0`.
     *
     * @param list<int> $targetIds
     * @return array<int, int> target_id => active vouch count.
     */
    public function countActiveVouchesByTargets(string $targetKind, array $targetIds): array
    {
        return $this->countByTargetsWhereRevoked($targetKind, $targetIds, false, 'vouch');
    }

    /**
     * Shared SQL for the grouped count variants above — identical shape
     * except for the revoked_at IS NULL / IS NOT NULL predicate and the
     * optional kind pin.
     *
     * @param list<int> $targetIds
     * @return array<int, int> target_id => count.
     */
    private function countByTargetsWhereRevoked(string $targetKind, array $targetIds, bool $revoked, ?string $kind = null): array
    {
        if (!in_array($targetKind, self::TARGET_KINDS, true)) {
            return [];
        }

        $ids = [];
        foreach ($targetIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        if ($kind !== null && !in_array($kind, self::KINDS, true)) {
            return [];
        }

        $revokedPredicate = $revoked ? 'IS NOT NULL' : 'IS NULL';
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql = "SELECT target_id, COUNT(*) AS c"
            . " FROM `{$table}`"
            . ' WHERE target_kind = %s'
            . " AND target_id IN ({$placeholders})"
            . " AND revoked_at {$revokedPredicate}"
            . ($kind !== null ? ' AND kind = %s' : '')
            . ' GROUP BY target_id'
            . ' LIMIT 100';

        $args = array_merge([$targetKind], array_keys($ids));
        if ($kind !== null) {
            $args[] = $kind;
        }
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
            if (!is_object($row) || !isset($row->target_id, $row->c)) {
                continue;
            }
            $targetId = (int) $row->target_id;
            if ($targetId > 0) {
                $out[$targetId] = (int) $row->c;
            }
        }
        return $out;
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
        //
        // Sort mode wiring:
        //   - `recency`         → created_at DESC, id DESC
        //   - `decayed_weight`  → DecayResolver SQL CASE (Slice E.1 PR-5)
        //   - `reliability`     → collapses to `decayed_weight` for now;
        //                         PR-6 swaps in the reliability standing
        //                         + numeric Operator Reliability score
        //                         once the cache table populates.
        //
        // The DecayResolver SQL expression is safe to interpolate —
        // every numeric value is a class constant (never user input).
        // See VoteRepository::applyTimeDecay for the sibling SQL idiom.
        if ($sort === 'recency') {
            $orderBy = 'created_at DESC, id DESC';
        } else {
            $decayedExpr = \BCC\Trust\Core\Services\DecayResolver::decayedWeightSqlExpression();
            $orderBy = $decayedExpr . ' DESC, created_at DESC';
        }
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

    /** Safety valve on synthesis row load — a single target's active set is
     *  realistically dozens; this only bounds a pathological/attack case. */
    private const SYNTHESIS_MAX_ROWS = 5000;

    /**
     * Active attestation rows feeding the score synthesis for one target
     * (Slice E). Narrow projection — only what AttestationScoreSynthesis needs:
     * the immutable cast-time weight + the decay baseline inputs + the attestor
     * (for the elite-source cap + diversity count). Both kinds (vouch +
     * stand_behind) contribute; revoked rows are excluded.
     *
     * @return list<object{attestor_user_id: int, weight_at_time: float, created_at: string, reaffirmed_at: ?string}>
     */
    public function listActiveForSynthesis(string $targetKind, int $targetId): array
    {
        if (!in_array($targetKind, self::TARGET_KINDS, true) || $targetId <= 0) {
            return [];
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = "SELECT attestor_user_id, weight_at_time, created_at, reaffirmed_at"
            . " FROM `{$table}`"
            . ' WHERE target_kind = %s AND target_id = %d AND revoked_at IS NULL'
            . ' ORDER BY id ASC'
            . ' LIMIT %d';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $targetKind, $targetId, self::SYNTHESIS_MAX_ROWS);
        if (!is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }
            $out[] = (object) [
                'attestor_user_id' => (int) ($row->attestor_user_id ?? 0),
                'weight_at_time'   => (float) ($row->weight_at_time ?? 0),
                'created_at'       => (string) ($row->created_at ?? ''),
                'reaffirmed_at'    => isset($row->reaffirmed_at) ? (string) $row->reaffirmed_at : null,
            ];
        }
        return $out;
    }

    /**
     * Active attestations CAST BY one operator, ordered oldest-first — the
     * input set for the §J.3.2.1 Operator Reliability classifier (Slice 2).
     *
     * Narrow projection: only what AttestationOutcomeClassifier needs to
     * classify each row's outcome and weight it —
     *   - target_kind / target_id  → resolve the target's score-row page,
     *   - kind                      → vouch vs stand_behind weighting,
     *   - attestation_order_in_target → early-read multiplier tier,
     *   - created_at                → outcome window ("since the cast date").
     * Both kinds contribute; revoked rows are excluded (a withdrawn judgment
     * carries no reliability signal).
     *
     * ORDER BY created_at ASC (id ASC tiebreak) is load-bearing: the classifier
     * walks the list to find the operator's OWN first N stand_behind casts for
     * the §J.1 first-mover protection. A stable chronological order is required
     * for that determination to be deterministic.
     *
     * Bounded by $limit (default 5000 — a single operator's lifetime active
     * cast count is realistically dozens to low hundreds).
     *
     * @return list<object{id: int, target_kind: string, target_id: int, kind: string, attestation_order_in_target: int, created_at: string}>
     */
    public function listActiveByAttestor(int $attestorUserId, int $limit = 5000): array
    {
        if ($attestorUserId <= 0) {
            return [];
        }
        $limit = max(1, min(5000, $limit));

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = "SELECT id, target_kind, target_id, kind, attestation_order_in_target, created_at"
            . " FROM `{$table}`"
            . ' WHERE attestor_user_id = %d'
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
            if (!is_object($row)) {
                continue;
            }
            $out[] = (object) [
                'id'                          => (int) ($row->id ?? 0),
                'target_kind'                 => (string) ($row->target_kind ?? ''),
                'target_id'                   => (int) ($row->target_id ?? 0),
                'kind'                        => (string) ($row->kind ?? ''),
                'attestation_order_in_target' => (int) ($row->attestation_order_in_target ?? 0),
                'created_at'                  => (string) ($row->created_at ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Active VOUCH attestations cast by one operator, newest-first — the
     * backing read for the §4.22 / §4.30 "given endorsements" surfaces
     * (GET /endorsements/mine, GET /users/:handle/endorsements) after the
     * legacy bcc_trust_endorsements read retirement.
     *
     * Sibling of {@see listActiveByAttestor} with three deliberate
     * differences (kept separate rather than parameterised so the
     * reliability classifier's oldest-first invariant can't be broken
     * by a flag): vouch-only kind filter, created_at DESC ordering
     * (the legacy getByEndorser order), and a projection that carries
     * weight_at_time + context_note for the §J.6 row hydration.
     *
     * Bounded by $limit (endpoint max 50; the stats path reads up to
     * the 5000 defensive ceiling — a single operator's active vouch
     * count is realistically dozens).
     *
     * @return list<object{id: int, target_kind: string, target_id: int, weight_at_time: float, context_note: ?string, created_at: string}>
     */
    public function listActiveVouchesByAttestor(int $attestorUserId, int $limit = 20): array
    {
        if ($attestorUserId <= 0) {
            return [];
        }
        $limit = max(1, min(5000, $limit));

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = "SELECT id, target_kind, target_id, weight_at_time, context_note, created_at"
            . " FROM `{$table}`"
            . ' WHERE attestor_user_id = %d'
            . " AND kind = 'vouch'"
            . ' AND revoked_at IS NULL'
            . ' ORDER BY created_at DESC, id DESC'
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
            if (!is_object($row)) {
                continue;
            }
            $note = null;
            if (isset($row->context_note) && is_string($row->context_note) && trim($row->context_note) !== '') {
                $note = trim($row->context_note);
            }
            $out[] = (object) [
                'id'             => (int) ($row->id ?? 0),
                'target_kind'    => (string) ($row->target_kind ?? ''),
                'target_id'      => (int) ($row->target_id ?? 0),
                'weight_at_time' => (float) ($row->weight_at_time ?? 0),
                'context_note'   => $note,
                'created_at'     => (string) ($row->created_at ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Count DISTINCT active attestors backing a single target across one or
     * more target_kinds.
     *
     * The §J.12 elite gate's anti-ring condition. Three deliberate
     * differences from its siblings:
     *
     *  - vs {@see countActiveVouchesForTarget} — counts distinct PEOPLE, not
     *    rows, and does not filter on kind. One attestor holding both a vouch
     *    and a stand_behind on the same target is one backer, not two; the
     *    unique key is (attestor, target_kind, target_id, kind), so a
     *    row-count would over-report exactly the concentration this gate
     *    exists to detect.
     *  - vs {@see countDistinctAttestorsOnTargetSince} — no `since` window and
     *    no excluded user. Eligibility asks about the standing backer set, not
     *    about recent activity relative to one observer.
     *  - vs AttestationScoreSynthesis's in-memory `$sources` set — that one is
     *    built only from rows surviving a `weight > 0` filter, which couples
     *    the headcount to decay semantics. A backer whose weight has decayed
     *    to zero is still a distinct backer. Do not substitute one for the
     *    other.
     *
     * @param list<string> $targetKinds Subset of TARGET_KINDS.
     */
    public function countDistinctActiveAttestorsForTarget(array $targetKinds, int $targetId): int
    {
        if ($targetId <= 0) {
            return 0;
        }
        $kinds = [];
        foreach ($targetKinds as $kind) {
            if (is_string($kind) && in_array($kind, self::TARGET_KINDS, true)) {
                $kinds[$kind] = true;
            }
        }
        if ($kinds === []) {
            return 0;
        }
        $kindList = array_keys($kinds);

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $placeholders = implode(',', array_fill(0, count($kindList), '%s'));
        $sql = "SELECT COUNT(DISTINCT attestor_user_id) FROM `{$table}`"
            . " WHERE target_kind IN ({$placeholders})"
            . ' AND target_id = %d'
            . ' AND revoked_at IS NULL'
            . ' LIMIT 10000';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, ...array_merge($kindList, [$targetId]));
        if (!is_string($prepared)) {
            return 0;
        }

        $raw = $wpdb->get_var($prepared);
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Count active VOUCH attestations against a single target across one
     * or more target_kinds in a single bounded round-trip. Powers the
     * `endorsement_count` denorm refresh (VoteService::recalculateFromVotes
     * + AttestationScoreSynthesis::recomputeFor) after the legacy
     * endorsements-table count retirement. Sibling of {@see countByTarget}
     * (which splits per kind for one target_kind) — this collapses to one
     * COUNT because the denorm only wants the vouch total and a card page's
     * true kind may be any of PAGE_TARGET_KINDS.
     *
     * @param list<string> $targetKinds Subset of TARGET_KINDS.
     */
    public function countActiveVouchesForTarget(array $targetKinds, int $targetId): int
    {
        if ($targetId <= 0) {
            return 0;
        }
        $kinds = [];
        foreach ($targetKinds as $kind) {
            if (is_string($kind) && in_array($kind, self::TARGET_KINDS, true)) {
                $kinds[$kind] = true;
            }
        }
        if ($kinds === []) {
            return 0;
        }
        $kindList = array_keys($kinds);

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $placeholders = implode(',', array_fill(0, count($kindList), '%s'));
        $sql = "SELECT COUNT(*) FROM `{$table}`"
            . " WHERE target_kind IN ({$placeholders})"
            . ' AND target_id = %d'
            . " AND kind = 'vouch'"
            . ' AND revoked_at IS NULL'
            . ' LIMIT 10000';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, ...array_merge($kindList, [$targetId]));
        if (!is_string($prepared)) {
            return 0;
        }

        $raw = $wpdb->get_var($prepared);
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Directed vouch graph edges (attestor → target OWNER) for the
     * endorsement-ring DFS cycle detector. Replaces the retired
     * VoteRepository::getEndorsementEdges read of the frozen
     * bcc_trust_endorsements table; column aliases are preserved
     * (endorser_user_id / page_owner_id) so TrustGraph::detectEndorsementRings
     * consumes the rows unchanged.
     *
     * Owner resolution mirrors the locked target_id invariant:
     *   - user_profile → target_id IS the owner user id.
     *   - *_card       → owner comes from the page's score row
     *                    (bcc_trust_page_scores.page_owner_id), the same
     *                    join the legacy edge query used.
     * Card rows whose page has no score row resolve to NULL owner and are
     * filtered out (no edge to nowhere).
     *
     * Active vouch rows only — stand_behind is a scarcer, slot-bounded
     * signal with its own surfaces; the ring detector models the
     * endorse-shaped reciprocity graph.
     *
     * @return list<object{endorser_user_id: int, page_owner_id: int}>
     */
    public function getVouchEdges(int $limit = 10000): array
    {
        $limit = max(1, min(100000, $limit));

        /** @var wpdb $wpdb */
        global $wpdb;
        $table       = TableRegistry::trustAttestations();
        $scoresTable = TableRegistry::scores();

        $sql = "SELECT a.attestor_user_id AS endorser_user_id,"
            . " CASE WHEN a.target_kind = 'user_profile' THEN a.target_id ELSE s.page_owner_id END AS page_owner_id"
            . " FROM `{$table}` a"
            . " LEFT JOIN `{$scoresTable}` s ON a.target_kind != 'user_profile' AND s.page_id = a.target_id"
            . " WHERE a.kind = 'vouch'"
            . ' AND a.revoked_at IS NULL'
            . " AND (a.target_kind = 'user_profile' OR s.page_owner_id IS NOT NULL)"
            . ' LIMIT %d';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $limit);
        if (!is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->endorser_user_id, $row->page_owner_id)) {
                continue;
            }
            $out[] = (object) [
                'endorser_user_id' => (int) $row->endorser_user_id,
                'page_owner_id'    => (int) $row->page_owner_id,
            ];
        }
        return $out;
    }

    /**
     * Mutual vouch pairs within a rolling time window — the slow-ring
     * detection primitive. Replaces the retired
     * EndorsementRepository::getMutualEndorsePairsInWindow read of the
     * frozen endorsements table with the attestation-edge equivalent;
     * output aliases (user_a / user_b / mutual_count / total_weight) are
     * preserved so TrustGraph::detectSlowEndorsementRings consumes the
     * rows unchanged.
     *
     * A pair (A, B) surfaces when A holds an active vouch on a target
     * OWNED by B and B holds an active vouch on a target owned by A,
     * both cast inside the window. Owner resolution matches
     * {@see getVouchEdges}: user_profile targets ARE the owner id;
     * card targets resolve through the score row.
     *
     * Bounded: LIMIT/OFFSET caller-paginated (cron cursor walks 500 a
     * page); GROUP BY collapses per ordered pair; the window + active
     * filters bound the self-join.
     *
     * @return list<object{user_a: int, user_b: int, mutual_count: int, total_weight: float}>
     */
    public function getMutualVouchPairsInWindow(
        int $windowDays = 14,
        int $minSize = 1,
        int $pageLimit = 500,
        int $offset = 0
    ): array {
        $windowDays = max(1, $windowDays);
        $minSize    = max(1, $minSize);
        $pageLimit  = max(1, min(5000, $pageLimit));
        $offset     = max(0, $offset);

        /** @var wpdb $wpdb */
        global $wpdb;
        $table       = TableRegistry::trustAttestations();
        $scoresTable = TableRegistry::scores();

        $sql = "SELECT"
            . ' a1.attestor_user_id AS user_a,'
            . ' a2.attestor_user_id AS user_b,'
            . ' COUNT(*) AS mutual_count,'
            . ' SUM(a1.weight_at_time + a2.weight_at_time) AS total_weight'
            . " FROM `{$table}` a1"
            . " LEFT JOIN `{$scoresTable}` s1 ON a1.target_kind != 'user_profile' AND s1.page_id = a1.target_id"
            . " JOIN `{$table}` a2"
            . " ON a2.attestor_user_id = CASE WHEN a1.target_kind = 'user_profile' THEN a1.target_id ELSE s1.page_owner_id END"
            . " LEFT JOIN `{$scoresTable}` s2 ON a2.target_kind != 'user_profile' AND s2.page_id = a2.target_id"
            . " WHERE a1.kind = 'vouch' AND a2.kind = 'vouch'"
            . ' AND a1.revoked_at IS NULL AND a2.revoked_at IS NULL'
            . " AND CASE WHEN a2.target_kind = 'user_profile' THEN a2.target_id ELSE s2.page_owner_id END = a1.attestor_user_id"
            . ' AND a1.attestor_user_id != a2.attestor_user_id'
            . ' AND a1.created_at > DATE_SUB(NOW(), INTERVAL %d DAY)'
            . ' AND a2.created_at > DATE_SUB(NOW(), INTERVAL %d DAY)'
            . ' GROUP BY a1.attestor_user_id, a2.attestor_user_id'
            . ' HAVING mutual_count >= %d'
            . ' LIMIT %d OFFSET %d';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $windowDays, $windowDays, $minSize, $pageLimit, $offset);
        if (!is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->user_a, $row->user_b)) {
                continue;
            }
            $out[] = (object) [
                'user_a'       => (int) $row->user_a,
                'user_b'       => (int) $row->user_b,
                'mutual_count' => (int) ($row->mutual_count ?? 0),
                'total_weight' => (float) ($row->total_weight ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Cursor-paged list of DISTINCT attestor user ids drawn from ACTIVE
     * attestations, for ids strictly greater than $afterUserId, ascending.
     * The work-list source for the Slice 3 nightly reliability sweep:
     * the cron pages forward by attestor user_id, recomputing each
     * operator's reliability cache row.
     *
     * Bounded (§4) by $limit; deterministic ASC order so the cursor wraps
     * cleanly on a short final batch. Revoked-only operators are excluded
     * (revoked_at IS NULL) — an operator with no active casts has nothing
     * to recompute.
     *
     * @return list<int>
     */
    public function listDistinctAttestorIdsAfter(int $afterUserId, int $limit): array
    {
        $afterUserId = max(0, $afterUserId);
        $limit       = max(1, min(5000, $limit));

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = "SELECT DISTINCT attestor_user_id"
            . " FROM `{$table}`"
            . ' WHERE attestor_user_id > %d'
            . ' AND revoked_at IS NULL'
            . ' ORDER BY attestor_user_id ASC'
            . ' LIMIT %d';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $afterUserId, $limit);
        if (!is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_col($prepared);

        $out = [];
        foreach ($rows as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $out[] = $intId;
            }
        }
        return $out;
    }

    /**
     * Count DISTINCT OTHER attestors who cast an ACTIVE attestation on a
     * target AFTER a given moment — the "further attestations" outcome probe
     * for the §J.3.2.1 reliability classifier (Slice 2).
     *
     * "Other" excludes the operator being scored ($excludeUserId) so their own
     * later casts on the same target don't count as independent confirmation.
     * Both kinds count; revoked rows are excluded; the window is strict
     * (created_at > since) so a follow-on cast at the exact same second as the
     * operator's own cast isn't double-counted.
     *
     * Bounded with LIMIT — a single target's distinct-attestor count is
     * realistically dozens; the cap is a runaway-dataset fence.
     *
     * @param string $sinceMysqlUtc UTC datetime "YYYY-MM-DD HH:MM:SS".
     */
    public function countDistinctAttestorsOnTargetSince(
        string $targetKind,
        int $targetId,
        int $excludeUserId,
        string $sinceMysqlUtc
    ): int {
        if (!in_array($targetKind, self::TARGET_KINDS, true)) {
            return 0;
        }
        if ($targetId <= 0 || $sinceMysqlUtc === '') {
            return 0;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = "SELECT COUNT(DISTINCT attestor_user_id) FROM `{$table}`"
            . ' WHERE target_kind = %s'
            . ' AND target_id = %d'
            . ' AND attestor_user_id != %d'
            . ' AND created_at > %s'
            . ' AND revoked_at IS NULL'
            . ' LIMIT 5000';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $targetKind, $targetId, $excludeUserId, $sinceMysqlUtc);
        if (!is_string($prepared)) {
            return 0;
        }

        $raw = $wpdb->get_var($prepared);
        return is_numeric($raw) ? (int) $raw : 0;
    }

    /**
     * Distinct (target_kind, target_id) pairs that currently hold at least one
     * active attestation — the daily decay-recompute sweep work-list (Slice E).
     * Decay is time-dependent, so a target's score drifts even with no new
     * events; the cron re-synthesizes every active target. OFFSET-paginated
     * (daily batch, not a hot path); deterministic order for stable paging.
     *
     * @return list<object{target_kind: string, target_id: int}>
     */
    public function listTargetsWithActiveAttestations(int $offset, int $limit): array
    {
        $offset = max(0, $offset);
        $limit  = max(1, min(5000, $limit));

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $sql = "SELECT target_kind, target_id"
            . " FROM `{$table}`"
            . ' WHERE revoked_at IS NULL'
            . ' GROUP BY target_kind, target_id'
            . ' ORDER BY target_kind ASC, target_id ASC'
            . ' LIMIT %d OFFSET %d';

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $limit, $offset);
        if (!is_string($prepared)) {
            return [];
        }

        $rows = $wpdb->get_results($prepared);
        if (!is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (!is_object($row) || !isset($row->target_kind, $row->target_id)) {
                continue;
            }
            $out[] = (object) [
                'target_kind' => (string) $row->target_kind,
                'target_id'   => (int) $row->target_id,
            ];
        }
        return $out;
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

    // ── Delete-cascade (page / user deletion) ─────────────────────────────

    /**
     * Hard-delete every attestation cast AGAINST a deleted page — i.e. rows
     * whose `target_id` is the page and whose `target_kind` is one of the
     * card kinds (validator/project/creator). Closes the §J orphan gap when
     * a peepso-page is permanently deleted.
     *
     * A hard delete (not a soft revoke) is correct here: revoke models the
     * attestor withdrawing judgment on a live target, whereas the target
     * itself ceasing to exist leaves the row pointing at nothing. The
     * sibling vote/endorsement soft-delete-on-page-delete policy does not
     * apply — those carry audit value for the page's score history;
     * card-attestations against a now-gone card do not.
     *
     * Called from UserLifecycleService::onPageDelete (before_delete_post).
     */
    public function deleteForPageTarget(int $pageId): void
    {
        if ($pageId <= 0) {
            return;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        $kindPlaceholders = implode(',', array_fill(0, count(self::PAGE_TARGET_KINDS), '%s'));
        $sql = "DELETE FROM `{$table}`"
            . ' WHERE target_id = %d'
            . " AND target_kind IN ({$kindPlaceholders})";

        /** @phpstan-ignore-next-line argument.type */
        $prepared = $wpdb->prepare($sql, $pageId, ...self::PAGE_TARGET_KINDS);
        if (!is_string($prepared)) {
            return;
        }
        $wpdb->query($prepared);
    }

    /**
     * Hard-delete every attestation tied to a deleted user — both the rows
     * the user CAST (attestor_user_id) and the rows cast AGAINST the user's
     * profile (target_kind='user_profile' AND target_id=userId). Closes the
     * §J orphan gap on hard account deletion.
     *
     * Two scoped DELETEs rather than one OR'd predicate so each uses its own
     * index cleanly and the intent is explicit. Card-attestations the user
     * cast on still-living pages are removed via attestor_user_id; the page
     * itself (if also deleted) is handled by deleteForPageTarget.
     *
     * Called from UserLifecycleService::onUserDelete (delete_user).
     */
    public function deleteForUser(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        /** @var wpdb $wpdb */
        global $wpdb;
        $table = TableRegistry::trustAttestations();

        // Rows the user cast.
        $wpdb->delete($table, ['attestor_user_id' => $userId], ['%d']);

        // Rows cast against the user's profile.
        $wpdb->delete(
            $table,
            ['target_kind' => 'user_profile', 'target_id' => $userId],
            ['%s', '%d']
        );
    }
}
