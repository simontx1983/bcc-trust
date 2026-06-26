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
use BCC\Trust\Core\Support\PageTypeMap;
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
        private readonly UserInfoRepository $userInfoRepo,
        private readonly WalletAgeWeighter $walletAgeWeighter,
        private readonly ReciprocityPenaltyResolver $reciprocityResolver,
        private readonly DormancyDetector $dormancyDetector,
        private readonly ReliabilityStandingComputer $reliabilityComputer,
        private readonly CohortOverlapDampener $cohortOverlapDampener,
        private readonly DivergenceStateClassifier $divergenceClassifier,
        private readonly ContestedStateExplainer $contestedStateExplainer
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

        return $this->shapeViewerAttestationFromRows($rows);
    }

    /**
     * Shape a pair of raw active-attestation rows into the locked
     * §4.20 viewer_attestation wire shape. The ONE place the shaping
     * lives: getViewerAttestation routes its single-target repo read
     * through here, and the cards-list path shapes
     * PageCardPrefetcher's batched `viewer_attestations[$pageId]`
     * rows through the same method — so the two paths can never emit
     * divergent shapes.
     *
     * @param array{vouch: object|null, stand_behind: object|null} $rows
     * @return array{
     *   vouch: array{id: int, created_at: string}|null,
     *   stand_behind: array{id: int, created_at: string}|null
     * }
     */
    public function shapeViewerAttestationFromRows(array $rows): array
    {
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
    public function getViewerActionPermissions(int $viewerUserId, int $targetOwnerUserId): array
    {
        // Per-request memo: this (viewer,target) resolution is queried up to 3×
        // while composing one /users/:handle response (resolvePermissions, the
        // profile composer's direct call, and the member-card summary path).
        // Stable within a request, so cache it (Phase 7 dedup).
        $key = $viewerUserId . ':' . $targetOwnerUserId;
        return $this->viewerActionPermsCache[$key]
            ??= $this->computeViewerActionPermissions($viewerUserId, $targetOwnerUserId);
    }

    /**
     * @var array<string, array{can_vouch: array{allowed: bool, unlock_hint: string|null}, can_stand_behind: array{allowed: bool, unlock_hint: string|null}, can_report: array{allowed: bool, unlock_hint: string|null}}>
     */
    private array $viewerActionPermsCache = [];

    /**
     * @return array{
     *   can_vouch: array{allowed: bool, unlock_hint: string|null},
     *   can_stand_behind: array{allowed: bool, unlock_hint: string|null},
     *   can_report: array{allowed: bool, unlock_hint: string|null}
     * }
     */
    private function computeViewerActionPermissions(
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
     * Batched per-author vouch state + can-vouch permission for a list of
     * post/comment authors (the feed-byline + commenter Vouch toggle).
     *
     * One bounded IN-list query resolves the viewer's active attestations
     * across every distinct author on the page (the §4 no-N+1 guarantee);
     * `can_vouch` reuses the per-request-memoised permission resolver, whose
     * only DB read is the VIEWER's tier — resolved once and shared across all
     * authors. Self-target authors come back with `can_vouch.allowed=false`
     * (the FE omits the slot — you cannot vouch yourself).
     *
     * Anon viewers (id ≤ 0) get an EMPTY map — zero queries, and the author
     * blocks stay byte-identical to the pre-vouch shape (the byline button is
     * authed-only; the aspirational sign-in CTA lives on the profile cluster).
     *
     * @param list<int> $authorUserIds
     * @return array<int, array{
     *   viewer_attestation: array{vouch: array{id:int,created_at:string}|null, stand_behind: array{id:int,created_at:string}|null},
     *   can_vouch: array{allowed: bool, unlock_hint: string|null}
     * }>
     */
    public function getViewerVouchStateForAuthors(int $viewerUserId, array $authorUserIds): array
    {
        if ($viewerUserId <= 0) {
            return [];
        }

        $ids = [];
        foreach ($authorUserIds as $id) {
            $intId = (int) $id;
            if ($intId > 0) {
                $ids[$intId] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $distinct = array_keys($ids);

        // One batched IN-list read of the viewer's active attestations across
        // every author, pre-grouped per target into {vouch, stand_behind} rows.
        $rowsByTarget = $this->repo->findActiveByAttestorForTargets(
            $viewerUserId,
            'user_profile',
            $distinct
        );

        $out = [];
        foreach ($distinct as $authorId) {
            $grouped = $rowsByTarget[$authorId] ?? ['vouch' => null, 'stand_behind' => null];
            $out[$authorId] = [
                'viewer_attestation' => $this->shapeViewerAttestationFromRows($grouped),
                // Memoised; the viewer tier is read once and reused for all authors.
                'can_vouch'          => $this->getViewerActionPermissions($viewerUserId, $authorId)['can_vouch'],
            ];
        }

        return $out;
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

                // Cast-time weight composition (PR-4 + PR-7). All three
                // multipliers are pure read-side functions over committed
                // state; computed inside the same transaction-locked
                // region as the insert so a concurrent reverse-cast can't
                // slip in between the multiplier checks and the row write.
                // Captured in weight_at_time per §J.4 ledger semantics —
                // later wallet aging, reverse revokes, or cohort changes
                // do NOT retroactively reweight this row.
                $walletAgeMult   = $this->walletAgeWeighter->weightFor($attestorUserId);
                $reciprocityMult = $this->reciprocityResolver->weightFor(
                    $attestorUserId,
                    $targetKind,
                    $targetId
                );
                $cohortOverlapMult = $this->cohortOverlapDampener->weightFor(
                    $attestorUserId,
                    $targetKind,
                    $targetId
                );
                $weightAtTime = self::DEFAULT_WEIGHT
                    * $walletAgeMult
                    * $reciprocityMult
                    * $cohortOverlapMult;

                $id = $this->repo->insert(
                    $attestorUserId,
                    $targetKind,
                    $targetId,
                    $kind,
                    $weightAtTime,
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
                        'attestor_user_id'    => $attestorUserId,
                        'target_kind'         => $targetKind,
                        'target_id'           => $targetId,
                        'kind'                => $kind,
                        'order_in_target'      => $orderInTarget,
                        // Cast-time weight components — observability into
                        // the multipliers so closed-network testing can
                        // audit what fed into each row's weight. PR-4 +
                        // PR-7 (cohort_overlap).
                        'weight_at_time'       => $weightAtTime,
                        'wallet_age_mult'      => $walletAgeMult,
                        'reciprocity_mult'     => $reciprocityMult,
                        'cohort_overlap_mult'  => $cohortOverlapMult,
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

    /**
     * Revoke the attestor's active attestation of a given kind on a
     * target, resolved by (attestor, target_kind, target_id, kind)
     * rather than by attestation id. Convenience for callers that hold
     * the target identity but not the row id (e.g. the legacy /revoke-
     * endorsement REST surface, which only carries page_id).
     *
     * Resolves the active row, then routes through the full revoke() so
     * the soft-delete, audit row, cache invalidation, and
     * bcc_attestation_revoked event (which re-folds the entity score)
     * all fire identically to an id-addressed revoke. Returns false when
     * there is no active row to revoke (idempotent no-op).
     *
     * @return bool True iff an active attestation was revoked.
     */
    public function revokeByTarget(
        int $attestorUserId,
        string $targetKind,
        int $targetId,
        string $kind
    ): bool {
        if ($attestorUserId <= 0 || $targetId <= 0) {
            return false;
        }
        $existing = $this->repo->findActiveOneByAttestorTargetKind(
            $attestorUserId,
            $targetKind,
            $targetId,
            $kind,
            false
        );
        if ($existing === null) {
            return false;
        }
        $id = isset($existing->id) ? (int) $existing->id : 0;
        if ($id <= 0) {
            return false;
        }
        $this->revoke($attestorUserId, $id);
        return true;
    }

    /**
     * Resolve the §J.1 entity-card target_kind for a peepso-page id by
     * reading its `_bcc_page_type` meta and mapping through the canonical
     * PageTypeMap (single source of truth shared with CardViewService /
     * WatchingService). Returns one of validator_card / project_card /
     * creator_card, or null when the page isn't an entity card kind
     * (member self-pages and unrecognized types fall through to null).
     *
     * Entity endorse/vouch only ever targets entity cards — the REST
     * /endorse handler uses this to reject non-entity page ids.
     */
    public static function targetKindForPage(int $pageId): ?string
    {
        if ($pageId <= 0) {
            return null;
        }
        $pageType = (string) get_post_meta($pageId, '_bcc_page_type', true);
        $cardKind = PageTypeMap::kindForPageType($pageType);
        if ($cardKind === null) {
            return null;
        }
        return match ($cardKind) {
            'validator' => 'validator_card',
            'project'   => 'project_card',
            'creator'   => 'creator_card',
            default     => null,
        };
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
     * many rows via AttestationRepository::listActiveStandBehindByAttestor.
     *
     * Each row carries a server-rendered `target_label` + `target_link`
     * (per §A2) so the FE picker renders without a follow-up display
     * fetch. The label + link are intentionally minimal — the picker's
     * emotional frame is "Which of these commitments still reflects my
     * assessment?" not a tactical overview, so the FE doesn't need
     * dense metadata to support the decision.
     *
     * @return list<array{
     *   id: int,
     *   kind: string,
     *   target_kind: string,
     *   target_id: int,
     *   target_label: string,
     *   target_link: string,
     *   created_at: string,
     *   context_note: string|null
     * }>
     */
    private function buildSlotHolders(int $attestorUserId): array
    {
        if ($attestorUserId <= 0) {
            return [];
        }
        $rows = $this->repo->listActiveStandBehindByAttestor($attestorUserId);
        $out  = [];
        foreach ($rows as $row) {
            $id          = isset($row->id) ? (int) $row->id : 0;
            $kind        = isset($row->kind) ? (string) $row->kind : '';
            $targetKind  = isset($row->target_kind) ? (string) $row->target_kind : '';
            $targetId    = isset($row->target_id) ? (int) $row->target_id : 0;
            $createdAt   = isset($row->created_at) ? (string) $row->created_at : '';
            $contextNote = isset($row->context_note) && is_string($row->context_note) && $row->context_note !== ''
                ? (string) $row->context_note
                : null;

            if ($id <= 0 || $targetId <= 0 || $kind !== 'stand_behind') {
                continue;
            }
            $display = self::resolveTargetDisplay($targetKind, $targetId);
            $out[] = [
                'id'           => $id,
                'kind'         => $kind,
                'target_kind'  => $targetKind,
                'target_id'    => $targetId,
                'target_label' => $display['label'],
                'target_link'  => $display['link'],
                'created_at'   => $createdAt !== '' ? self::formatIso($createdAt) : '',
                'context_note' => $contextNote,
            ];
        }
        return $out;
    }

    /**
     * Server-rendered (per §A2) display label + relative link for a
     * (target_kind, target_id) pair. Used by the §J.2 slot_holders[]
     * picker payload so the FE renders the picker without a follow-up
     * display fetch.
     *
     * Conventions:
     *   - user_profile → label = "@{handle}"; link = "/u/{handle}"
     *   - validator_card → label = post_title; link = "/v/{slug}"
     *   - project_card   → label = post_title; link = "/p/{slug}"
     *   - creator_card   → label = post_title; link = "/c/{slug}"
     *
     * Missing target (deleted user / unpublished post) returns empty
     * strings — the FE renders the row with the kind label only,
     * never crashes.
     *
     * @return array{label: string, link: string}
     */
    private static function resolveTargetDisplay(string $targetKind, int $targetId): array
    {
        $empty = ['label' => '', 'link' => ''];
        if ($targetId <= 0) {
            return $empty;
        }

        if ($targetKind === 'user_profile') {
            $user = get_userdata($targetId);
            if (!($user instanceof \WP_User)) {
                return $empty;
            }
            $handleMeta = (string) get_user_meta($targetId, 'bcc_handle', true);
            $handle     = $handleMeta !== '' ? $handleMeta : (string) $user->user_login;
            if ($handle === '') {
                return $empty;
            }
            return [
                'label' => '@' . $handle,
                'link'  => '/u/' . $handle,
            ];
        }

        $post = get_post($targetId);
        if (!($post instanceof \WP_Post) || $post->post_status !== 'publish') {
            return $empty;
        }
        $slug  = (string) $post->post_name;
        $title = (string) $post->post_title;
        $prefix = match ($targetKind) {
            'validator_card' => '/v/',
            'project_card'   => '/p/',
            'creator_card'   => '/c/',
            default          => null,
        };
        if ($prefix === null || $slug === '') {
            return ['label' => $title, 'link' => ''];
        }
        return [
            'label' => $title !== '' ? $title : ('#' . $targetId),
            'link'  => $prefix . $slug,
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Roster read — §J.4 GET /entities/:target_kind/:target_id/attestations
    // ──────────────────────────────────────────────────────────────────

    /**
     * §J.4 roster view-model composer.
     *
     * Three locked discipline points the composer enforces:
     *
     *   - §J.4.1 synthesis invisibility: per-attestation `weight_at_time`
     *     and `decayed_weight` are stripped from the response. The
     *     server uses them for ORDER BY at the repo layer; the wire
     *     surface NEVER sees the number.
     *   - §J.3.2 asymmetric display: public roster rows carry only
     *     the categorical `reliability_standing` enum + the
     *     positive-only `badges[]` catalogue. No numeric reliability,
     *     no negative-tier badges (V1 catalogue is positive-only by
     *     construction; even an attestor in Caution tier renders with
     *     a "newly_active" baseline standing rather than a stigma
     *     marker).
     *   - Equal row treatment: the composer does NOT pre-sort or
     *     up-weight Highly-Reliable attestors. The FE renders rows
     *     in the order received; server-side ORDER BY by sort mode
     *     produces the ranking transparently without elevating
     *     individual rows visually.
     *
     * V1 baselines (Slice E computes real values, contract-stable shape):
     *   - attestor.is_dormant = false
     *   - attestor.reliability_standing = 'newly_active'
     *   - attestor.badges = []
     *
     * Deleted-attestor rows are silently dropped — the attestation
     * history is preserved at the DB layer (revoked_at is the audit
     * marker; account deletion is separate), but rendering a
     * "[deleted]" row would create UX awkwardness and the contract
     * doesn't model that state. Slice E reconsiders.
     *
     * @param array{
     *   kind?: string,
     *   include_revoked?: bool,
     *   sort?: string,
     *   page?: int,
     *   per_page?: int
     * } $options
     * @return array{
     *   items: list<array<string, mixed>>,
     *   summary: array{vouch_count: int, stand_behind_count: int},
     *   pagination: array{page: int, per_page: int, total_pages: int, has_more: boolean}
     * }
     */
    public function getForTarget(
        string $targetKind,
        int $targetId,
        array $options = []
    ): array {
        $empty = [
            'items'      => [],
            'summary'    => ['vouch_count' => 0, 'stand_behind_count' => 0],
            'pagination' => [
                'page'        => 1,
                'per_page'    => 24,
                'total_pages' => 0,
                'has_more'    => false,
            ],
        ];

        if (!in_array($targetKind, AttestationRepository::TARGET_KINDS, true)) {
            return $empty;
        }
        if ($targetId <= 0) {
            return $empty;
        }

        $page    = isset($options['page']) ? max(1, min(20, (int) $options['page'])) : 1;
        $perPage = isset($options['per_page']) ? max(1, min(50, (int) $options['per_page'])) : 24;

        $rows = $this->repo->listByTarget($targetKind, $targetId, [
            'kind'            => isset($options['kind']) && is_string($options['kind'])
                ? $options['kind']
                : 'all',
            'include_revoked' => !empty($options['include_revoked']),
            'sort'            => isset($options['sort']) && is_string($options['sort'])
                ? $options['sort']
                : 'decayed_weight',
            'page'            => $page,
            'per_page'        => $perPage,
        ]);

        $items = [];
        foreach ($rows as $row) {
            $attestorId = isset($row->attestor_user_id) ? (int) $row->attestor_user_id : 0;
            if ($attestorId <= 0) {
                continue;
            }
            $attestor = self::buildAttestorMiniView(
                $attestorId,
                $this->reputationRepo,
                $this->dormancyDetector
            );
            if ($attestor === null) {
                // Deleted attestor — drop the row (the underlying
                // attestation stays in DB; we just don't render it).
                continue;
            }

            $id            = isset($row->id) ? (int) $row->id : 0;
            $kindStr       = isset($row->kind) ? (string) $row->kind : '';
            $orderInTarget = isset($row->attestation_order_in_target)
                ? (int) $row->attestation_order_in_target
                : 0;
            $createdAtRaw  = isset($row->created_at) ? (string) $row->created_at : '';
            $revokedAtRaw  = isset($row->revoked_at) && is_string($row->revoked_at) && $row->revoked_at !== ''
                ? (string) $row->revoked_at
                : null;
            $contextNote   = isset($row->context_note) && is_string($row->context_note) && $row->context_note !== ''
                ? (string) $row->context_note
                : null;

            $items[] = [
                'id'                    => $id,
                'kind'                  => $kindStr,
                'attestor'              => $attestor,
                'is_pre_consensus_pick' => self::isPreConsensusPick($kindStr, $orderInTarget),
                'attestation_order'     => $orderInTarget,
                'context_note'          => $contextNote,
                'created_at'            => $createdAtRaw !== '' ? self::formatIso($createdAtRaw) : '',
                'revoked_at'            => $revokedAtRaw !== null ? self::formatIso($revokedAtRaw) : null,
            ];
        }

        $counts    = $this->repo->countByTarget($targetKind, $targetId);
        $totalRows = (int) $counts['total'];
        // total_pages: ceil(total / per_page), at least 1 page when
        // there's at least one row, 0 when the target has nothing.
        // Note: total counts ACTIVE rows only — when include_revoked
        // is true and revoked rows return on later pages, the
        // pagination is a soft over-estimate. Acceptable V1; Slice E
        // can split count when needed.
        $totalPages = $totalRows > 0 ? (int) ceil($totalRows / $perPage) : 0;
        $hasMore    = ($page * $perPage) < $totalRows;

        return [
            'items'   => $items,
            'summary' => [
                'vouch_count'        => (int) $counts['vouch_count'],
                'stand_behind_count' => (int) $counts['stand_behind_count'],
            ],
            'pagination' => [
                'page'        => $page,
                'per_page'    => $perPage,
                'total_pages' => $totalPages,
                'has_more'    => $hasMore,
            ],
        ];
    }

    /**
     * §J.4 attestor mini-view per contract row schema. Returns null
     * when the user no longer exists (caller drops the row).
     *
     * Slice E activations (PR-6):
     *   - `is_dormant` now reflects the real `DormancyDetector` output
     *     (60-day window over last_login + attestation activity). The
     *     FE renders the INACTIVE marker conditionally — purely a
     *     backend swap-in.
     *
     * V1 baselines (still hardcoded — gated by §J.10 q14 "public
     * surface SELF-ONLY in V1" until V2 expansion opens them):
     *   - `reliability_standing = 'newly_active'` for every row. The
     *     positive-only catalogue per §J.3.2 means an operator in a
     *     low-tier reliability state renders as "newly_active" anyway,
     *     so the public roster carries no information leak. The real
     *     standing is computed by `ReliabilityStandingComputer` and
     *     surfaced on `/me/reliability` (self-only) per PR-6.
     *   - `badges = []` for every row. Per-attestor badges
     *     (e.g. Early Read) ship with the §J.3.2.1 outcome
     *     classifier in Slice E.4+.
     *
     * The numeric `reputation_score` IS surfaced (per the locked
     * §J.4 row shape) — that's the existing public score, distinct
     * from the operator_reliability sub-track which stays self-only.
     *
     * @return array{
     *   id: int,
     *   handle: string,
     *   display_name: string,
     *   avatar_url: string,
     *   reputation_score: int,
     *   reliability_standing: string,
     *   badges: list<string>,
     *   is_dormant: bool
     * }|null
     */
    private static function buildAttestorMiniView(
        int $userId,
        ReputationRepository $reputationRepo,
        DormancyDetector $dormancyDetector
    ): ?array {
        if ($userId <= 0) {
            return null;
        }
        $user = get_userdata($userId);
        if (!($user instanceof \WP_User)) {
            return null;
        }

        $handleMeta = (string) get_user_meta($userId, 'bcc_handle', true);
        $handle     = $handleMeta !== '' ? $handleMeta : (string) $user->user_login;

        $displayName = (string) $user->display_name;
        if ($displayName === '') {
            $displayName = (string) $user->user_login;
        }

        $avatarRaw = get_avatar_url($userId);
        $avatarUrl = is_string($avatarRaw) ? $avatarRaw : '';

        $score = (int) round($reputationRepo->getScore($userId));

        // Dormancy: real value as of PR-6. Tracks last_login + recent
        // attestation activity over a 60-day window (plan §12 q9).
        $isDormant = $dormancyDetector->isDormant($userId);

        return [
            'id'                   => $userId,
            'handle'               => $handle,
            'display_name'         => $displayName,
            'avatar_url'           => $avatarUrl,
            'reputation_score'     => $score,
            // Still hardcoded — §J.10 q14 gates public reliability_standing
            // + badges to SELF-ONLY in V1. Real values surface on
            // /me/reliability via ReliabilityStandingComputer. V2 expansion
            // opens these to third-party rows.
            'reliability_standing' => 'newly_active',
            'badges'               => [],
            'is_dormant'           => $isDormant,
        ];
    }

    /**
     * §J.3.2.1 pre-consensus pick marker. V1 heuristic: the first 3
     * stand_behind attestations on a target are early reads. Vouch
     * is abundant; the marker is reserved for the scarcer signal
     * where ordering actually matters.
     *
     * Slice E refines using the §J.3.2.1 Early Read sub-track
     * synthesis (compares the attestor's call to later consensus).
     */
    private static function isPreConsensusPick(string $kind, int $orderInTarget): bool
    {
        if ($kind !== 'stand_behind') {
            return false;
        }
        return $orderInTarget > 0 && $orderInTarget <= 3;
    }

    /**
     * Tier → stand_behind baseline slot count. §J.1 bandwidth model.
     */
    private static function standBehindBaselineFor(string $tier): int
    {
        return self::STAND_BEHIND_BASELINE_SLOTS[$tier] ?? 0;
    }

    // ──────────────────────────────────────────────────────────────────
    // §J.5 self-mirror reliability — GET /me/reliability
    // ──────────────────────────────────────────────────────────────────

    /**
     * Build the §J.5 self-mirror payload for the signed-in operator.
     *
     * Three buckets of fields:
     *
     *   1. **Live-counted** (real values today):
     *      - `since_attestation_count` — lifetime cast count
     *        from `bcc_trust_attestations`.
     *      - `stand_behind_allocation.slots_total` — tier baseline.
     *      - `stand_behind_allocation.slots_used` — active stand_behind
     *        rows for this attestor.
     *
     *   2. **V1 baselines** (Slice E populates):
     *      - `operator_reliability = 0.0`
     *      - `reliability_standing = 'newly_active'`
     *      - `track_record.total_attestations = 0` (outcome classifier
     *        is Slice E)
     *      - `track_record.outcomes.*  = 0` (same)
     *      - `trends.reliability_30d_ago = 0.0`
     *      - `trends.reliability_90d_ago = 0.0`
     *      - `trends.direction = 'steady'`
     *      - `stand_behind_allocation.slots_recyclable_count = 0`
     *        (decay-curve crossing is Slice E)
     *      - `stand_behind_allocation.next_slot_unlocks_at = null`
     *        (graduated bonus tracker is Slice E)
     *
     *   3. **Self-only**: caller (MeReliabilityEndpoint) enforces
     *      `viewerId === $userId`. The shape includes the numeric
     *      `operator_reliability` per §J.3.2 asymmetric-display rule
     *      — this surface IS the asymmetry, the operator sees their
     *      own number while it stays out of third-party responses.
     *
     * Slice E will refactor this method to read-cache-first via
     * `bcc_attestor_reliability_cache` and fall through to a live
     * compute on cache miss. The current implementation is the
     * fall-through path's V1 baseline.
     *
     * @return array{
     *   operator_reliability: float,
     *   reliability_standing: string,
     *   since_attestation_count: int,
     *   stand_behind_allocation: array{
     *     slots_total: int,
     *     slots_used: int,
     *     slots_recyclable_count: int,
     *     next_slot_unlocks_at: string|null
     *   },
     *   track_record: array{
     *     total_attestations: int,
     *     outcomes: array{
     *       targets_disputed_and_upheld: int,
     *       targets_disputed_and_dismissed: int,
     *       targets_received_further_attestations: int,
     *       targets_clean_and_active: int
     *     }
     *   },
     *   trends: array{
     *     reliability_30d_ago: float,
     *     reliability_90d_ago: float,
     *     direction: string
     *   },
     *   divergence_state: string,
     *   explainer: array{state: string, headline: string, body: string}
     * }
     */
    public function getReliabilityFor(int $userId): array
    {
        if ($userId <= 0) {
            return self::emptyReliabilityPayload();
        }

        $tier         = $this->reputationRepo->getTier($userId);
        $slotsTotal   = self::standBehindBaselineFor($tier);
        $slotsUsed    = $this->repo->countActiveStandBehindByAttestor($userId);
        $totalCasts   = $this->repo->countAllByAttestor($userId);

        // Reliability standing: PR-6 activates the real computer.
        // Self-only per §J.10 q14 — the public roster keeps emitting
        // 'newly_active' regardless of what the computer returns,
        // gated upstream in buildAttestorMiniView.
        $standing = $this->reliabilityComputer->compute($userId);

        // §J.5 divergence_state + explainer (PR-8a). Classifier runs
        // against the operator's own user_profile target — disputes
        // don't fire on user_profile in V1 (Phase 1.5), so the slot
        // resolves via the active/revocation heuristic only. Explainer
        // returns server-pinned headline+body per the §2.7 mitigation.
        $divergenceState = $this->divergenceClassifier->classify('user_profile', $userId);
        $explainer       = $this->contestedStateExplainer->explain($divergenceState);

        return [
            // Numeric stays 0.0 until Slice E.4+ ships the outcome
            // classifier. The categorical standing above is the V1
            // signal operators see on /me/reliability.
            'operator_reliability'   => 0.0,
            'reliability_standing'   => $standing,
            'since_attestation_count' => $totalCasts,
            'stand_behind_allocation' => [
                'slots_total'             => $slotsTotal,
                'slots_used'              => $slotsUsed,
                'slots_recyclable_count'  => 0,
                'next_slot_unlocks_at'    => null,
            ],
            'track_record' => [
                'total_attestations' => 0,
                'outcomes' => [
                    'targets_disputed_and_upheld'           => 0,
                    'targets_disputed_and_dismissed'        => 0,
                    'targets_received_further_attestations' => 0,
                    'targets_clean_and_active'              => 0,
                ],
            ],
            'trends' => [
                'reliability_30d_ago' => 0.0,
                'reliability_90d_ago' => 0.0,
                // Three-state direction per §J.5 contract: softening
                // / steady / improving. V1 baseline is steady — every
                // operator sits at 0.0 across the rolling window until
                // Slice E populates real reliability history.
                'direction'           => 'steady',
            ],
            'divergence_state'        => $divergenceState,
            'explainer'               => $explainer,
        ];
    }

    /**
     * @return array{
     *   operator_reliability: float,
     *   reliability_standing: string,
     *   since_attestation_count: int,
     *   stand_behind_allocation: array{
     *     slots_total: int,
     *     slots_used: int,
     *     slots_recyclable_count: int,
     *     next_slot_unlocks_at: string|null
     *   },
     *   track_record: array{
     *     total_attestations: int,
     *     outcomes: array{
     *       targets_disputed_and_upheld: int,
     *       targets_disputed_and_dismissed: int,
     *       targets_received_further_attestations: int,
     *       targets_clean_and_active: int
     *     }
     *   },
     *   trends: array{
     *     reliability_30d_ago: float,
     *     reliability_90d_ago: float,
     *     direction: string
     *   },
     *   divergence_state: string,
     *   explainer: array{state: string, headline: string, body: string}
     * }
     */
    private static function emptyReliabilityPayload(): array
    {
        return [
            'operator_reliability'   => 0.0,
            'reliability_standing'   => 'newly_active',
            'since_attestation_count' => 0,
            'stand_behind_allocation' => [
                'slots_total'             => 0,
                'slots_used'              => 0,
                'slots_recyclable_count'  => 0,
                'next_slot_unlocks_at'    => null,
            ],
            'track_record' => [
                'total_attestations' => 0,
                'outcomes' => [
                    'targets_disputed_and_upheld'           => 0,
                    'targets_disputed_and_dismissed'        => 0,
                    'targets_received_further_attestations' => 0,
                    'targets_clean_and_active'              => 0,
                ],
            ],
            'trends' => [
                'reliability_30d_ago' => 0.0,
                'reliability_90d_ago' => 0.0,
                'direction'           => 'steady',
            ],
            // §J.5 PR-8a — anonymous viewers / invalid userIds get the
            // V1 baseline divergence + explainer too. The empty payload
            // is contract-stable so the FE renders without conditional
            // null checks.
            'divergence_state'        => DivergenceStateClassifier::STATE_UNTESTED,
            'explainer'               => [
                'state'    => DivergenceStateClassifier::STATE_UNTESTED,
                'headline' => 'No reads yet.',
                'body'     => "The floor hasn't formed a read on you yet — most operators sit "
                            . "here for a while. The graph doesn't grade silence as either "
                            . "good or bad; it just waits for evidence.",
            ],
        ];
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
