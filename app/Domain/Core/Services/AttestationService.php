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
use BCC\Trust\Core\Repositories\ReputationRepository;

final class AttestationService
{
    /**
     * Reputation tiers that satisfy the "good standing" gate. Sourced
     * from UserViewService::GOOD_STANDING_TIERS per the §G2 single-
     * source-of-truth rule — duplicated here as a class constant only
     * for clarity. Vouch / Stand Behind / Report all gate on this set.
     *
     * @var list<string>
     */
    private const NEUTRAL_PLUS_TIERS = ['neutral', 'trusted', 'elite'];

    /**
     * Reputation tiers that satisfy the Dispute gate per §J.1
     * "must be ≥ trusted standing to file disputes."
     *
     * @var list<string>
     */
    private const TRUSTED_PLUS_TIERS = ['trusted', 'elite'];

    public function __construct(
        private readonly AttestationRepository $repo,
        private readonly ReputationRepository $reputationRepo
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
     * Compute the four §J.6 action-permission entries the viewer
     * sees on this target. Each entry is the locked
     * `{allowed, unlock_hint}` shape compatible with both
     * MemberPermission and CardPermissionEntry surfaces.
     *
     * §N7 visibility semantics:
     *   - `allowed=true`                          → button renders enabled
     *   - `allowed=false, unlock_hint=<text>`     → button renders disabled
     *                                               with inline aspirational
     *                                               copy (path forward visible)
     *   - `allowed=false, unlock_hint=null`       → button HIDDEN (FE checks
     *                                               for this and omits the
     *                                               slot per §N7 structural-
     *                                               impossible rule)
     *
     * unlock_hint tone (per the Phase 1 plan + risk-assessment
     * emotional calibration):
     *   - Aspirational, not exclusionary
     *   - Anti-shame: no language like "you lack" or "you can't"
     *   - Anti-elitism: no tier comparisons ("Elite operators can…")
     *   - Single sentence, ends with a period
     *
     * Self-target prevention: the viewer cannot attest against their
     * own profile or against an entity they own (post_author).
     * Returns hidden — there's no aspirational path; it's structurally
     * impossible.
     *
     * Tier gates: anon → "Sign in to <verb>." Below threshold →
     * "Reach <Tier> standing to <verb>." Threshold reached → allowed.
     *
     * Stand Behind in Phase 1 Slice B uses the same gate as Vouch.
     * Bandwidth slot enforcement lands in Slice C alongside mutation
     * endpoints; until then, eligible operators see plain "STAND
     * BEHIND" rather than the "STAND BEHIND · N OF M" allocation
     * indicator.
     *
     * Phase 1 Slice B intentionally omits can_dispute on profile
     * surfaces — profile-scoped disputes are Phase 1.5 per §J.1.
     * On card surfaces, can_dispute is the existing §D5 gate (not
     * computed here; this method only emits can_vouch + can_stand_
     * behind + can_report on card surfaces; the card composer keeps
     * its existing can_dispute resolution).
     *
     * @return array{
     *   can_vouch: array{allowed: bool, unlock_hint: string|null},
     *   can_stand_behind: array{allowed: bool, unlock_hint: string|null},
     *   can_report: array{allowed: bool, unlock_hint: string|null}
     * }
     */
    public function getViewerActionPermissions(
        int $viewerUserId,
        int $targetOwnerUserId
    ): array {
        // Anon viewer: visible-but-disabled with sign-in copy across
        // all actions. Aspirational visibility per §N7 — never hide,
        // always invite.
        if ($viewerUserId <= 0) {
            return [
                'can_vouch' => [
                    'allowed'     => false,
                    'unlock_hint' => 'Sign in to vouch for operators.',
                ],
                'can_stand_behind' => [
                    'allowed'     => false,
                    'unlock_hint' => 'Sign in to stand behind operators.',
                ],
                'can_report' => [
                    'allowed'     => false,
                    'unlock_hint' => 'Sign in to report.',
                ],
            ];
        }

        // Self-target: structurally impossible. Hide per §N7
        // (allowed=false, unlock_hint=null → FE omits the slot).
        // The viewer cannot attest about themselves; there is no
        // aspirational path forward.
        if ($targetOwnerUserId > 0 && $viewerUserId === $targetOwnerUserId) {
            $hidden = ['allowed' => false, 'unlock_hint' => null];
            return [
                'can_vouch'        => $hidden,
                'can_stand_behind' => $hidden,
                'can_report'       => $hidden,
            ];
        }

        // Tier gate. Below Neutral standing → aspirational hint that
        // names the threshold without explaining the math (per §J.4.1
        // synthesis invisibility + §J.7 heuristic #2 "no synthesis
        // mechanics in copy").
        $viewerTier = $this->reputationRepo->getTier($viewerUserId);
        $isNeutralPlus = in_array($viewerTier, self::NEUTRAL_PLUS_TIERS, true);

        if (!$isNeutralPlus) {
            return [
                'can_vouch' => [
                    'allowed'     => false,
                    'unlock_hint' => 'Reach Neutral standing to vouch.',
                ],
                'can_stand_behind' => [
                    'allowed'     => false,
                    'unlock_hint' => 'Reach Neutral standing to stand behind operators.',
                ],
                'can_report' => [
                    'allowed'     => false,
                    'unlock_hint' => 'Reach Neutral standing to report.',
                ],
            ];
        }

        // All gates pass for Phase 1 Slice B. Stand Behind slot
        // enforcement lands in Slice C; for now, eligible operators
        // see plain STAND BEHIND (the FE's allocation indicator
        // "STAND BEHIND · N OF M" stays plain "STAND BEHIND" when
        // the slot fields aren't yet supplied).
        $allowed = ['allowed' => true, 'unlock_hint' => null];
        return [
            'can_vouch'        => $allowed,
            'can_stand_behind' => $allowed,
            'can_report'       => $allowed,
        ];
    }

    /**
     * Profile-scoped Dispute permission per §J.1 Phase 1.5. Returns
     * the hidden shape for Phase 1 — profile-scoped disputes ship in
     * Phase 1.5 per the constitution + Phase 1 plan. Composers can
     * still call this; the FE will hide the action via §N7 (allowed=
     * false, unlock_hint=null → no button rendered).
     *
     * When Phase 1.5 ships, this method's body changes to apply the
     * Trusted+ tier gate. The signature stays stable so composers
     * don't need to re-wire.
     *
     * @return array{allowed: bool, unlock_hint: string|null}
     */
    public function getViewerCanDisputeProfile(
        int $viewerUserId,
        int $targetUserId
    ): array {
        // Phase 1: hide. Phase 1.5 enables this with the Trusted-plus
        // gate and the existing dispute panel mechanic extended to
        // user_profile target_kind.
        return ['allowed' => false, 'unlock_hint' => null];
    }

    /**
     * Tier-comparison utility: is the viewer at Trusted+ standing
     * (the §J.1 Dispute gate)? Page-card disputes already gate on
     * this via the existing CardPermissions.can_dispute pathway;
     * this method exists for future profile-scoped use (Phase 1.5).
     *
     * @phpstan-impure The tier is read from the reputation repo;
     *                 functionally pure across one request, but
     *                 changes between requests as scores recompute.
     */
    public function viewerIsTrustedPlus(int $viewerUserId): bool
    {
        if ($viewerUserId <= 0) {
            return false;
        }
        $tier = $this->reputationRepo->getTier($viewerUserId);
        return in_array($tier, self::TRUSTED_PLUS_TIERS, true);
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
