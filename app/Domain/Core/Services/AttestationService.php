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

use BCC\Core\DB\AdvisoryLock;
use BCC\Trust\Core\Repositories\AttestationRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Core\Support\CacheManager;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

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

    /**
     * §J.1 Stand Behind bandwidth model — concurrent active-attestation
     * slots per attestor tier. Graduated bonus slots (§J.1 long-term
     * graph health refinements) ship in Slice E; V1 returns 0 for
     * `graduated`, so effective_total === baseline here.
     *
     * Caution + Risky = 0 means those tiers cannot cast new
     * stand_behind attestations at all (existing ones still revocable).
     * Vouch is abundant — no slot model. Surface in the §J.6 view-model
     * via `attestor_summary.stand_behind_slots_total` / `_used`.
     *
     * @var array<string, int>
     */
    private const STAND_BEHIND_BASELINE_SLOTS = [
        'elite'   => 7,
        'trusted' => 5,
        'neutral' => 3,
        'caution' => 0,
        'risky'   => 0,
    ];

    /** §J.2 contract: context_note ≤ 280 chars (Twitter-prior). */
    private const CONTEXT_NOTE_MAX_LENGTH = 280;

    /** §J.4.1 weight_at_time default for V1; synthesis hardening Slice E. */
    private const DEFAULT_WEIGHT = 1.0;

    /**
     * Per-attestor MySQL advisory-lock prefix. Serializes all cast /
     * revoke / reaffirm operations by the same attestor so concurrent
     * mutations can't (a) double-spend a stand_behind slot, (b) race
     * past an idempotency check, or (c) interleave revoke + cast for
     * the same row.
     */
    private const LOCK_PREFIX_ATTESTOR = 'bcc_attestation_a_';

    /**
     * Per-target advisory lock prefix. Serializes order_in_target
     * computation so two attestors casting on the same target can't
     * collide on attestation_order_in_target (used by the FE to
     * surface §J.1 first-mover protection).
     */
    private const LOCK_PREFIX_TARGET = 'bcc_attestation_t_';

    /** Advisory lock wait timeout (seconds). */
    private const LOCK_WAIT_SECS = 5;

    public function __construct(
        private readonly AttestationRepository $repo,
        private readonly ReputationRepository $reputationRepo,
        private readonly UserInfoRepository $userInfoRepo
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

    // ──────────────────────────────────────────────────────────────────
    // Mutation orchestrators — §4.20 §J.2 / §J.2.1 / §J.3
    // ──────────────────────────────────────────────────────────────────

    /**
     * Cast a new attestation per `POST /bcc/v1/me/attestations` (§J.2).
     *
     * Idempotent on (attestor, target_kind, target_id, kind): an
     * existing active row is returned with `status: "existing"` rather
     * than erroring. This is what makes the action surface safe under
     * double-click / network retry — the FE can call cast() twice and
     * still see one row.
     *
     * Race-safety boundary: cast()'s critical section is wrapped in
     *   (a) a per-attestor MySQL advisory lock (serializes all this
     *       operator's mutations — prevents double-spending a
     *       stand_behind slot)
     *   (b) a per-target MySQL advisory lock (serializes
     *       attestation_order_in_target computation so two operators
     *       casting on the same target don't collide on the first-mover
     *       position)
     *   (c) TransactionManager::run with FOR UPDATE on the unique-key
     *       range (prevents the idempotency check from racing the
     *       insert)
     * Locks released after COMMIT (finally block) so waiters see the
     * committed insert under any isolation level.
     *
     * @return array<string, mixed> §J.2 response shape
     * @throws AttestationException on validation / eligibility / race
     */
    public function cast(
        int $attestorUserId,
        string $targetKind,
        int $targetId,
        string $kind,
        ?string $contextNote
    ): array {
        if ($attestorUserId <= 0) {
            throw new AttestationException(
                AttestationException::CODE_INVALID_REQUEST,
                'Attestor required.',
                400
            );
        }
        if (!in_array($targetKind, AttestationRepository::TARGET_KINDS, true)) {
            throw new AttestationException(
                AttestationException::CODE_INVALID_REQUEST,
                'Invalid target_kind.',
                400
            );
        }
        if (!in_array($kind, AttestationRepository::KINDS, true)) {
            throw new AttestationException(
                AttestationException::CODE_INVALID_REQUEST,
                'Invalid attestation kind.',
                400
            );
        }
        if ($targetId <= 0) {
            throw new AttestationException(
                AttestationException::CODE_INVALID_REQUEST,
                'Invalid target_id.',
                400
            );
        }
        $cleanNote = self::sanitizeContextNote($contextNote);

        $targetOwnerId = self::resolveTargetOwnerUserId($targetKind, $targetId);
        if ($targetOwnerId <= 0) {
            throw new AttestationException(
                AttestationException::CODE_NOT_FOUND,
                'Target not found.',
                404
            );
        }
        if ($targetOwnerId === $attestorUserId) {
            // Structural — §N7 self-target rule. The FE already hides
            // the button (allowed=false, unlock_hint=null), so reaching
            // here means a hand-crafted request. 422 with a flat code.
            throw new AttestationException(
                AttestationException::CODE_SELF,
                'You cannot attest on yourself.',
                422
            );
        }

        $attestorTier = $this->reputationRepo->getTier($attestorUserId);
        if (!in_array($attestorTier, self::NEUTRAL_PLUS_TIERS, true)) {
            $verb = $kind === 'vouch'
                ? 'vouch'
                : 'stand behind operators';
            $hint = sprintf('Reach Neutral standing to %s.', $verb);
            throw new AttestationException(
                AttestationException::CODE_INELIGIBLE,
                $hint,
                403,
                ['unlock_hint' => $hint]
            );
        }

        $this->assertFraudClear($attestorUserId);

        $attestorLockKey = self::LOCK_PREFIX_ATTESTOR . $attestorUserId;
        $targetLockKey   = self::LOCK_PREFIX_TARGET . $targetKind . '_' . $targetId;

        if (!AdvisoryLock::acquire($attestorLockKey, self::LOCK_WAIT_SECS)) {
            throw new AttestationException(
                AttestationException::CODE_RATE_LIMITED,
                'Attestation system is busy. Please try again in a moment.',
                429
            );
        }
        $targetLocked = AdvisoryLock::acquire($targetLockKey, self::LOCK_WAIT_SECS);
        if (!$targetLocked) {
            AdvisoryLock::release($attestorLockKey);
            throw new AttestationException(
                AttestationException::CODE_RATE_LIMITED,
                'Attestation system is busy. Please try again in a moment.',
                429
            );
        }

        try {
            /** @var array{row: object|null, status: string} $result */
            $result = TransactionManager::run(function () use (
                $attestorUserId, $targetKind, $targetId, $kind, $cleanNote
            ): array {
                $existing = $this->repo->findActiveOneByAttestorTargetKind(
                    $attestorUserId,
                    $targetKind,
                    $targetId,
                    $kind,
                    true
                );
                if ($existing !== null) {
                    return ['row' => $existing, 'status' => 'existing'];
                }

                if ($kind === 'stand_behind') {
                    $this->assertStandBehindSlotAvailable($attestorUserId);
                }

                $orderInTarget = $this->repo->nextOrderInTarget($targetKind, $targetId);

                $id = $this->repo->insert(
                    $attestorUserId,
                    $targetKind,
                    $targetId,
                    $kind,
                    self::DEFAULT_WEIGHT,
                    $cleanNote,
                    $orderInTarget
                );

                $action = $kind === 'vouch'
                    ? 'attestation_vouch_created'
                    : 'attestation_stand_behind_created';
                AuditLogger::log(
                    $action,
                    $id,
                    [
                        'attestor_user_id' => $attestorUserId,
                        'target_kind'      => $targetKind,
                        'target_id'        => $targetId,
                        'kind'             => $kind,
                        'order_in_target'  => $orderInTarget,
                    ],
                    'attestation',
                    $attestorUserId
                );

                $row = $this->repo->findOneById($id);
                return ['row' => $row, 'status' => 'created'];
            });
        } finally {
            AdvisoryLock::release($targetLockKey);
            AdvisoryLock::release($attestorLockKey);
        }

        $row    = $result['row'];
        $status = $result['status'];
        if (!is_object($row)) {
            throw new AttestationException(
                AttestationException::CODE_INTERNAL,
                'Attestation created but row read failed.',
                500
            );
        }

        if ($status === 'created') {
            $rowId = (int) ($row->id ?? 0);
            CacheManager::invalidateAttestationTargetCaches(
                $targetKind,
                $targetId,
                $attestorUserId,
                $targetOwnerId,
                'attestation_cast'
            );
            if ($rowId > 0) {
                do_action(
                    'bcc_attestation_created',
                    $attestorUserId,
                    $rowId,
                    $targetKind,
                    $targetId,
                    $kind,
                    $targetOwnerId
                );
            }
        }

        return $this->buildCastResponse($row, $status);
    }

    /**
     * Revoke an attestation per `DELETE /bcc/v1/me/attestations/:id`
     * (§J.3). Soft-delete (sets revoked_at) — preserves historical
     * continuity per §J.4 audit integrity rule.
     *
     * Idempotent: re-DELETE on an already-revoked row returns 200 with
     * the existing revoked_at — no audit row, no notification, no
     * cache invalidation. Per §I1 destructive-mutation-hardening
     * invariant 1, "audit on real state transition only."
     *
     * Per §J.3 — no reputation-score impact on the attestor. Revoking
     * is signal of changing assessment, not punishment.
     *
     * @return array<string, mixed> §J.3 response shape
     */
    public function revoke(int $attestorUserId, int $attestationId): array
    {
        if ($attestorUserId <= 0 || $attestationId <= 0) {
            throw new AttestationException(
                AttestationException::CODE_INVALID_REQUEST,
                'Invalid request.',
                400
            );
        }

        $row = $this->repo->findOneById($attestationId);
        if ($row === null) {
            throw new AttestationException(
                AttestationException::CODE_NOT_FOUND,
                'Attestation not found.',
                404
            );
        }
        if ((int) ($row->attestor_user_id ?? 0) !== $attestorUserId) {
            throw new AttestationException(
                AttestationException::CODE_FORBIDDEN,
                'You can only revoke your own attestations.',
                403
            );
        }

        $targetKind = (string) ($row->target_kind ?? '');
        $targetId   = (int) ($row->target_id ?? 0);
        $kind       = (string) ($row->kind ?? '');

        $existingRevokedAt = $row->revoked_at ?? null;
        if (is_string($existingRevokedAt) && $existingRevokedAt !== '') {
            // Already revoked — return existing without re-firing side
            // effects. Idempotency per §J.3.
            return $this->buildRevokeResponse($row, $attestorUserId);
        }

        $attestorLockKey = self::LOCK_PREFIX_ATTESTOR . $attestorUserId;
        if (!AdvisoryLock::acquire($attestorLockKey, self::LOCK_WAIT_SECS)) {
            throw new AttestationException(
                AttestationException::CODE_RATE_LIMITED,
                'Attestation system is busy. Please try again in a moment.',
                429
            );
        }

        try {
            $transitioned = TransactionManager::run(function () use (
                $attestationId, $attestorUserId, $targetKind, $targetId, $kind
            ): bool {
                $changed = $this->repo->softRevoke($attestationId, $attestorUserId);
                if ($changed) {
                    AuditLogger::log(
                        'attestation_revoked',
                        $attestationId,
                        [
                            'target_kind' => $targetKind,
                            'target_id'   => $targetId,
                            'kind'        => $kind,
                        ],
                        'attestation',
                        $attestorUserId
                    );
                }
                return $changed;
            });
        } finally {
            AdvisoryLock::release($attestorLockKey);
        }

        if ($transitioned) {
            $targetOwnerId = self::resolveTargetOwnerUserId($targetKind, $targetId);
            CacheManager::invalidateAttestationTargetCaches(
                $targetKind,
                $targetId,
                $attestorUserId,
                $targetOwnerId,
                'attestation_revoke'
            );
            do_action(
                'bcc_attestation_revoked',
                $attestorUserId,
                $attestationId,
                $targetKind,
                $targetId,
                $kind,
                $targetOwnerId
            );
        }

        $fresh = $this->repo->findOneById($attestationId);
        return $this->buildRevokeResponse($fresh ?? $row, $attestorUserId);
    }

    /**
     * Reaffirm an attestation per `POST /bcc/v1/me/attestations/:id/reaffirm`
     * (§J.2.1). Refreshes `reaffirmed_at` — Slice E uses this as the
     * decay-curve baseline so the attestation's effective age resets
     * to zero. V1 Slice C just records the timestamp + audit; the
     * synthesis math lands in Slice E.
     *
     * Cannot reaffirm a revoked attestation — 409 with the locked
     * `bcc_attestation_revoked` code.
     *
     * @return array<string, mixed> §J.2.1 response shape
     */
    public function reaffirm(int $attestorUserId, int $attestationId): array
    {
        if ($attestorUserId <= 0 || $attestationId <= 0) {
            throw new AttestationException(
                AttestationException::CODE_INVALID_REQUEST,
                'Invalid request.',
                400
            );
        }

        $row = $this->repo->findOneById($attestationId);
        if ($row === null) {
            throw new AttestationException(
                AttestationException::CODE_NOT_FOUND,
                'Attestation not found.',
                404
            );
        }
        if ((int) ($row->attestor_user_id ?? 0) !== $attestorUserId) {
            throw new AttestationException(
                AttestationException::CODE_FORBIDDEN,
                'You can only reaffirm your own attestations.',
                403
            );
        }
        $existingRevokedAt = $row->revoked_at ?? null;
        if (is_string($existingRevokedAt) && $existingRevokedAt !== '') {
            throw new AttestationException(
                AttestationException::CODE_REVOKED,
                'Cannot reaffirm a revoked attestation.',
                409
            );
        }

        $targetKind = (string) ($row->target_kind ?? '');
        $targetId   = (int) ($row->target_id ?? 0);
        $kind       = (string) ($row->kind ?? '');

        $attestorLockKey = self::LOCK_PREFIX_ATTESTOR . $attestorUserId;
        if (!AdvisoryLock::acquire($attestorLockKey, self::LOCK_WAIT_SECS)) {
            throw new AttestationException(
                AttestationException::CODE_RATE_LIMITED,
                'Attestation system is busy. Please try again in a moment.',
                429
            );
        }

        try {
            $transitioned = TransactionManager::run(function () use (
                $attestationId, $attestorUserId, $targetKind, $targetId, $kind
            ): bool {
                $changed = $this->repo->markReaffirmed($attestationId, $attestorUserId);
                if ($changed) {
                    AuditLogger::log(
                        'attestation_reaffirmed',
                        $attestationId,
                        [
                            'target_kind' => $targetKind,
                            'target_id'   => $targetId,
                            'kind'        => $kind,
                        ],
                        'attestation',
                        $attestorUserId
                    );
                }
                return $changed;
            });
        } finally {
            AdvisoryLock::release($attestorLockKey);
        }

        if (!$transitioned) {
            // Race: revoke landed between the pre-check and the UPDATE.
            // Surface as 409 — the row is now revoked, no new state.
            throw new AttestationException(
                AttestationException::CODE_REVOKED,
                'Attestation was revoked while you were reaffirming it.',
                409
            );
        }

        $targetOwnerId = self::resolveTargetOwnerUserId($targetKind, $targetId);
        CacheManager::invalidateAttestationTargetCaches(
            $targetKind,
            $targetId,
            $attestorUserId,
            $targetOwnerId,
            'attestation_reaffirm'
        );
        do_action(
            'bcc_attestation_reaffirmed',
            $attestorUserId,
            $attestationId,
            $targetKind,
            $targetId,
            $kind,
            $targetOwnerId
        );

        $fresh = $this->repo->findOneById($attestationId);
        return $this->buildReaffirmResponse($fresh ?? $row);
    }

    // ──────────────────────────────────────────────────────────────────
    // Slot enforcement (private) — §J.1 bandwidth model
    // ──────────────────────────────────────────────────────────────────

    /**
     * Throw `bcc_attestation_bandwidth_exhausted` when the attestor has
     * no Stand Behind slot available. Caller (cast()) is responsible
     * for being inside the per-attestor advisory lock — that's what
     * makes "count_used vs. limit" a serializable read.
     *
     * The body's `slot_holders[]` carries the current active rows so
     * the FE can render a "drop one to add one" picker without an
     * extra round-trip. V1 emits a lean shape (id, kind, target_kind,
     * target_id, created_at, context_note); the FE resolves the
     * target's display info via existing entity endpoints.
     */
    private function assertStandBehindSlotAvailable(int $attestorUserId): void
    {
        $tier      = $this->reputationRepo->getTier($attestorUserId);
        $baseline  = self::standBehindBaselineFor($tier);
        $graduated = 0; // V1 — Slice E ships graduation.
        $total     = $baseline + $graduated;
        $used      = $this->repo->countActiveStandBehindByAttestor($attestorUserId);

        if ($used < $total) {
            return;
        }

        // Exhausted — build the picker payload from the active set.
        $holders = $this->buildSlotHolders($attestorUserId);

        $message = $total === 0
            ? 'Reach Neutral standing to stand behind operators.'
            : sprintf(
                "You're standing behind your maximum of %d operators. Free a slot by revoking one to add another.",
                $total
            );

        throw new AttestationException(
            AttestationException::CODE_BANDWIDTH_EXHAUSTED,
            $message,
            409,
            [
                'slot_holders'           => $holders,
                'slots_total'            => $total,
                'slots_used'             => $used,
            ]
        );
    }

    /**
     * Read the active Stand Behind rows for an attestor, shaped for
     * the §J.2 `slot_holders[]` picker. Bounded by the §J.1 tier max
     * (Elite=7, plus future graduated cap → 10); reads at most that
     * many rows.
     *
     * Lean shape — the FE resolves target display info via existing
     * entity surfaces rather than threading display data through the
     * error envelope.
     *
     * @return list<array{
     *   id: int,
     *   kind: string,
     *   target_kind: string,
     *   target_id: int,
     *   created_at: string,
     *   context_note: string|null
     * }>
     */
    private function buildSlotHolders(int $attestorUserId): array
    {
        // Until we add a dedicated repo helper this is read-time cheap
        // (the attestor's active set is bounded ≤ 10 rows by definition).
        // We pull both kinds and filter to stand_behind below — the
        // existing findActiveByAttestorAndTarget is per-target; we need
        // a per-attestor read. Use the bounded count helper's twin —
        // for V1 minimal we synthesize via a per-row repo call would
        // be wrong (no list helper). So emit the locked shape with
        // just the count's worth of info that's already in scope; FE
        // uses the picker as a hint, not as authoritative state. This
        // is acceptable because the FE always re-reads the attestor's
        // own surfaces post-409 to refresh.
        //
        // Slice C ships with `slot_holders = []` as the contract-safe
        // V1 baseline; the picker payload lands when the dedicated
        // repository helper is added (Slice D — same query as the
        // /me/attestations list endpoint).
        unset($attestorUserId);
        return [];
    }

    /**
     * Tier → stand_behind baseline slot count. §J.1 bandwidth model.
     */
    private static function standBehindBaselineFor(string $tier): int
    {
        return self::STAND_BEHIND_BASELINE_SLOTS[$tier] ?? 0;
    }

    // ──────────────────────────────────────────────────────────────────
    // Pre-mutation gates (private)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Block when the attestor's fraud_score is at or above the HIGH
     * threshold. Mirror of the EndorsementService pre-tx gate (line
     * 147-152) — single fraud entry point per §11 reuse rule.
     */
    private function assertFraudClear(int $attestorUserId): void
    {
        // Test-mode bypass mirrors EndorsementService — automated tests
        // create fresh accounts whose fraud_score may not yet be
        // computed; we let those through deliberately.
        if (defined('BCC_TRUST_TEST_MODE') && \BCC_TRUST_TEST_MODE) {
            return;
        }
        $info = $this->userInfoRepo->getByUserId($attestorUserId);
        if ($info === null) {
            return;
        }
        $fraudScore = isset($info->fraud_score) ? (int) $info->fraud_score : 0;
        if (defined('BCC_TRUST_FRAUD_HIGH') && $fraudScore >= (int) BCC_TRUST_FRAUD_HIGH) {
            throw new AttestationException(
                AttestationException::CODE_FRAUD_BLOCKED,
                'Your account has been flagged for unusual activity. Attestations are temporarily restricted.',
                403
            );
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Resolution + sanitization (private)
    // ──────────────────────────────────────────────────────────────────

    /**
     * Resolve target owner for self-target detection.
     *   - user_profile → target_id IS the user_id
     *   - *_card       → wp_posts.post_author of the CPT row
     *
     * Returns 0 when the target doesn't exist (caller surfaces 404).
     */
    private static function resolveTargetOwnerUserId(string $targetKind, int $targetId): int
    {
        if ($targetId <= 0) {
            return 0;
        }
        if ($targetKind === 'user_profile') {
            $user = get_userdata($targetId);
            return $user instanceof \WP_User ? (int) $user->ID : 0;
        }
        $post = get_post($targetId);
        if (!($post instanceof \WP_Post) || $post->post_status !== 'publish') {
            return 0;
        }
        return (int) $post->post_author;
    }

    /**
     * Trim + length-cap the context_note. Returns null for empty/whitespace.
     * §J.2 contract: ≤ 280 chars, optional free-text rationale.
     */
    private static function sanitizeContextNote(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }
        $trim = trim($note);
        if ($trim === '') {
            return null;
        }
        // Strip control chars + sanitize. Use sanitize_textarea_field
        // to preserve newlines (note may be multi-line rationale) while
        // stripping HTML tags / script injection.
        $clean = sanitize_textarea_field($trim);
        if (function_exists('mb_substr')) {
            $clean = mb_substr($clean, 0, self::CONTEXT_NOTE_MAX_LENGTH);
        } else {
            $clean = substr($clean, 0, self::CONTEXT_NOTE_MAX_LENGTH);
        }
        return $clean === '' ? null : $clean;
    }

    // ──────────────────────────────────────────────────────────────────
    // Response builders (private)
    // ──────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed> §J.2 response shape
     */
    private function buildCastResponse(object $row, string $status): array
    {
        $createdAtRaw = isset($row->created_at) ? (string) $row->created_at : '';
        $createdAtIso = self::formatIso($createdAtRaw);
        $reaffirmedAtRaw = isset($row->reaffirmed_at) ? (string) $row->reaffirmed_at : '';
        $weight = isset($row->weight_at_time) ? (float) $row->weight_at_time : self::DEFAULT_WEIGHT;
        $contextNote = isset($row->context_note) && is_string($row->context_note) && $row->context_note !== ''
            ? (string) $row->context_note
            : null;

        $attestorUserId = isset($row->attestor_user_id) ? (int) $row->attestor_user_id : 0;

        return [
            'id'             => isset($row->id) ? (int) $row->id : 0,
            'status'         => $status,
            'kind'           => isset($row->kind) ? (string) $row->kind : '',
            'target_kind'    => isset($row->target_kind) ? (string) $row->target_kind : '',
            'target_id'      => isset($row->target_id) ? (int) $row->target_id : 0,
            'weight_at_time' => $weight,
            'context_note'   => $contextNote,
            'created_at'     => $createdAtIso,
            'reaffirmed_at'  => $reaffirmedAtRaw !== '' ? self::formatIso($reaffirmedAtRaw) : null,
            // Slice E ships real decay synthesis; V1 returns weight_at_time
            // unmodified (decay age = 0 the moment we just inserted).
            'decay' => [
                'current_weight' => $weight,
                'as_of'          => $createdAtIso,
            ],
            'attestor_summary' => $this->buildAttestorSummary($attestorUserId, true),
        ];
    }

    /**
     * @return array<string, mixed> §J.3 response shape
     */
    private function buildRevokeResponse(object $row, int $attestorUserId): array
    {
        $revokedAtRaw = isset($row->revoked_at) ? (string) $row->revoked_at : '';
        return [
            'id'               => isset($row->id) ? (int) $row->id : 0,
            'revoked_at'       => $revokedAtRaw !== '' ? self::formatIso($revokedAtRaw) : null,
            'attestor_summary' => $this->buildAttestorSummary($attestorUserId, true),
        ];
    }

    /**
     * @return array<string, mixed> §J.2.1 response shape
     */
    private function buildReaffirmResponse(object $row): array
    {
        $reaffirmedAtRaw = isset($row->reaffirmed_at) ? (string) $row->reaffirmed_at : '';
        return [
            'id'             => isset($row->id) ? (int) $row->id : 0,
            'reaffirmed_at'  => $reaffirmedAtRaw !== '' ? self::formatIso($reaffirmedAtRaw) : null,
            // Slice E ships decay reset — V1 advertises the new baseline.
            'decay_reset_to' => self::DEFAULT_WEIGHT,
        ];
    }

    /**
     * Build the §J.2 `attestor_summary` view-model. When $selfView is
     * true (cast/revoke/reaffirm responses are always self-views), the
     * payload includes the operator-private fields (graduated slots
     * count + reliability sub-tracks). Per §J.3.2 asymmetric display,
     * those fields are omitted from third-party roster responses.
     *
     * V1 Slice C emits the contract-stable shape with V1 baseline
     * values; Slice E replaces null/zero placeholders with the
     * computed synthesis outputs (operator_reliability, badges,
     * standing classification, dormancy detection).
     *
     * @return array<string, mixed>
     */
    private function buildAttestorSummary(int $attestorUserId, bool $selfView): array
    {
        $tier      = $this->reputationRepo->getTier($attestorUserId);
        $baseline  = self::standBehindBaselineFor($tier);
        $graduated = 0; // V1 baseline; Slice E.
        $used      = $this->repo->countActiveStandBehindByAttestor($attestorUserId);

        $summary = [
            'stand_behind_slots_used'  => $used,
            'stand_behind_slots_total' => $baseline + $graduated,
            'is_dormant'               => false,         // V1 baseline; Slice E.
            'reliability_standing'     => 'newly_active', // V1 baseline; Slice E.
            'badges'                   => [],            // V1 baseline; Slice E.
        ];

        if ($selfView) {
            // Self-only fields per §J.3.2 — never exposed to
            // third-party queries. V1 emits the keys with null/zero so
            // the contract shape stays stable for FE consumers; Slice E
            // populates real values.
            $summary['stand_behind_slots_graduated'] = $graduated;
            $summary['operator_reliability']  = null;
            $summary['consensus_reliability'] = null;
            $summary['early_read_accuracy']   = null;
        }

        return $summary;
    }

    /**
     * Convert a MySQL DATETIME string (stored in site-local timezone
     * by `current_time('mysql')`) into ISO 8601 UTC. Returns the input
     * verbatim if parsing fails — the FE renders any non-empty string
     * gracefully and we never want a malformed timestamp to break the
     * envelope.
     */
    private static function formatIso(string $mysqlDatetime): string
    {
        if ($mysqlDatetime === '') {
            return '';
        }
        try {
            $tz = function_exists('wp_timezone')
                ? wp_timezone()
                : new DateTimeZone(date_default_timezone_get() ?: 'UTC');
            $dt = new DateTimeImmutable($mysqlDatetime, $tz);
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable $_) {
            return $mysqlDatetime;
        }
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
