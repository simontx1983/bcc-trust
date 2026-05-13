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
}
