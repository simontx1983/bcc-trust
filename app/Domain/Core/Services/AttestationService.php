<?php
/**
 * AttestationService — §J wire contract orchestrator.
 *
 * Generalized successor to EndorsementService per the §J.11
 * "endorse collapses into vouch" migration. EndorsementService
 * remains in place during Phase 1 — it owns the legacy
 * bcc_trust_endorsements row writes that current entity cards still
 * depend on. AttestationService owns the new bcc_trust_attestations
 * row reads + (in later slices) writes.
 *
 * Phase 1 scope (this slice): viewer-attestation read path only.
 * Service exposes a single method that the entity view-model
 * composers call to populate the FE's `viewer_attestation` field.
 *
 * Subsequent slices add:
 *   - Slice B: eligibility checks + permissions population
 *   - Slice C: mutation methods (vouch, standBehind, revoke, reaffirm)
 *   - Slice D: roster query + REST endpoints
 *   - Slice E: synthesis math (decay, Reputation Score, Reliability
 *     Standing classification)
 *
 * Per the constitution and Phase 1 plan §6.1, the eventual full
 * method surface is:
 *   - cast(int $attestorUserId, string $targetKind, int $targetId,
 *          string $kind, ?string $contextNote): array
 *   - revoke(int $attestorUserId, int $attestationId): array
 *   - reaffirm(int $attestorUserId, int $attestationId): array
 *   - getForTarget(string $targetKind, int $targetId,
 *                  array $options): array
 *   - getReliabilityFor(int $attestorUserId): array
 *
 * Constitutional alignment:
 *   - §J.4.1 synthesis invisibility: this service never returns
 *     numeric weights, multipliers, or decay outputs. The
 *     viewer-attestation shape carries only id + created_at per the
 *     locked §J.6 view-model.
 *   - §J.3.2 asymmetric display: never returns numeric reliability;
 *     that's the per-attestor self-mirror surface (`/me/reliability`)
 *     in a later slice.
 *
 * @package BCC\Trust\Core\Services
 * @since V2 Trust Attestation Layer (2026-05-13)
 */

namespace BCC\Trust\Core\Services;

if (!defined('ABSPATH')) {
    exit;
}

use BCC\Trust\Core\Repositories\AttestationRepository;

final class AttestationService
{
    public function __construct(
        private readonly AttestationRepository $repo
    ) {
    }

    /**
     * Build the `viewer_attestation` view-model shape per
     * api-contract-v1.md §4.20 §J.6.
     *
     * Returns the locked shape:
     *   {
     *     "vouch":        { "id": int, "created_at": "ISO" } | null,
     *     "stand_behind": { "id": int, "created_at": "ISO" } | null
     *   }
     *
     * Composers call this to populate the FE's
     * `MemberProfile.viewer_attestation` / `Card.viewer_attestation`
     * field. The FE's AttestationActionCluster reads this to render
     * cast states ("VOUCHED" / "STANDING BEHIND") on the action
     * buttons.
     *
     * Anon viewers (viewerUserId === 0) get null returned —
     * composers should omit the field entirely from anon responses
     * rather than emit nulls.
     *
     * @return array{
     *   vouch: array{id: int, created_at: string}|null,
     *   stand_behind: array{id: int, created_at: string}|null
     * }|null
     */
    public function getViewerAttestation(
        int $viewerUserId,
        string $targetKind,
        int $targetId
    ): ?array {
        if ($viewerUserId <= 0) {
            return null;
        }

        $rows = $this->repo->findActiveByAttestorAndTarget(
            $viewerUserId,
            $targetKind,
            $targetId
        );

        return [
            'vouch'        => self::shapeViewerSlot($rows['vouch']),
            'stand_behind' => self::shapeViewerSlot($rows['stand_behind']),
        ];
    }

    /**
     * Shape a raw attestation row into the locked viewer-attestation
     * slot shape. Returns null when the row is null (no attestation
     * cast for this kind).
     *
     * Only `id` and `created_at` leak per §J.6 — the view-model
     * intentionally omits `weight_at_time`, `attestation_order_in_target`,
     * and other internal fields per §J.4.1 synthesis invisibility.
     *
     * @return array{id: int, created_at: string}|null
     */
    private static function shapeViewerSlot(?object $row): ?array
    {
        if ($row === null) {
            return null;
        }
        if (!isset($row->id, $row->created_at)) {
            return null;
        }
        $id = (int) $row->id;
        $createdAt = (string) $row->created_at;
        if ($id <= 0 || $createdAt === '') {
            return null;
        }
        return [
            'id'         => $id,
            'created_at' => $createdAt,
        ];
    }
}
