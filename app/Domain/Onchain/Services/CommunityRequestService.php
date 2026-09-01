<?php
/**
 * The only place community-provisioning INTENT is created, withdrawn, or
 * affected by a verification change.
 *
 * ── WHY A SERVICE AND NOT TWO ADMIN HANDLERS ────────────────────────────
 * Three writes have to agree or none of them may claim success:
 *
 *   1. `is_verified` on the collection row
 *   2. `provisioning_state` (a withdrawal, when verification is removed)
 *   3. the checked audit row that says a person did it
 *
 * Spread across handlers, each one reports its own success, and the failure
 * mode is silent: verification flips, the pending request survives, and the
 * daily sweep then provisions a community for a collection nobody verifies
 * any more. They are one transaction here, so a partial outcome is not
 * representable.
 *
 * ── WHY VERIFICATION LIVES HERE TOO ─────────────────────────────────────
 * It would be tidier for this class to own only requests. It cannot:
 * removing verification MUST withdraw a pending request, and that coupling
 * has to be enforced at the write, not remembered by each caller. Both the
 * bulk save and the AJAX toggle route through {@see applyVerification()} so
 * there is exactly one implementation of the rule.
 *
 * ── WHAT THIS CLASS DELIBERATELY CANNOT DO ──────────────────────────────
 * It cannot create a community, cannot delete one, and cannot reach
 * `provisioned`. Provisioning is the sweep's job and runs from recorded
 * intent; un-provisioning is not a thing an operator checkbox may do.
 *
 * @package BCC\Trust\Onchain\Services
 * @since PR 6 — collection administration and explicit provisioning
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Support\NftCollectionIdentifier;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;

if (!defined('ABSPATH')) {
    exit;
}

final class CommunityRequestService
{
    public const AUDIT_REQUESTED = 'admin_vc_community_requested';
    public const AUDIT_WITHDRAWN = 'admin_vc_community_request_withdrawn';

    /** Machine-readable refusal reasons. Bounded; safe to render. */
    public const REFUSED_NOT_FOUND          = 'collection_not_found';
    public const REFUSED_NOT_VERIFIED       = 'collection_not_verified';
    public const REFUSED_IDENTITY           = 'identity_unresolved';
    public const REFUSED_COMMUNITY_EXISTS   = 'community_already_exists';
    public const REFUSED_ILLEGAL_TRANSITION = 'illegal_transition';
    public const REFUSED_WRITE_FAILED       = 'write_failed';
    public const REFUSED_BAD_OPERATOR       = 'operator_unresolved';

    /**
     * Record an administrator's request for a holder community.
     *
     * Idempotent in both directions that matter: asking twice leaves one
     * `requested` row and writes one audit trail entry per real transition;
     * asking for a collection that already has a community reports `exists`
     * and creates nothing.
     *
     * Retry of a failed provisioning is THIS method — `failed -> requested`.
     * There is deliberately no separate retry mutation, because a second
     * path to the same transition is a second place for the guards to drift.
     *
     * @return array{ok: bool, status: string, reason?: string}
     *         status ∈ {requested, already_requested, exists, refused}
     */
    public function request(int $collectionId, int $operatorId): array
    {
        if ($collectionId <= 0) {
            return ['ok' => false, 'status' => 'refused', 'reason' => self::REFUSED_NOT_FOUND];
        }

        // The operator is checked on the NAMED user, never via
        // `current_user_can()`: this service is also reachable from contexts
        // with no current user, and an implicit actor must never satisfy an
        // authorization check.
        if ($operatorId <= 0 || get_userdata($operatorId) === false || !user_can($operatorId, 'manage_options')) {
            return ['ok' => false, 'status' => 'refused', 'reason' => self::REFUSED_BAD_OPERATOR];
        }

        $row = CollectionRepository::readProvisioningRow($collectionId);
        if ($row === null) {
            return ['ok' => false, 'status' => 'refused', 'reason' => self::REFUSED_NOT_FOUND];
        }

        if ((int) $row->is_verified !== 1) {
            return ['ok' => false, 'status' => 'refused', 'reason' => self::REFUSED_NOT_VERIFIED];
        }

        // The identity must validate BEFORE intent is recorded. Recording a
        // request the sweep is guaranteed to refuse would manufacture a
        // `failed` row on a schedule, and tell the operator their request was
        // accepted when it never could be.
        $chain = ChainRepository::getById((int) $row->chain_id);
        if ($chain === null) {
            return ['ok' => false, 'status' => 'refused', 'reason' => self::REFUSED_IDENTITY];
        }

        $canonical = $row->canonical_identifier ?? null;
        if (!is_string($canonical) || $canonical === '') {
            return ['ok' => false, 'status' => 'refused', 'reason' => self::REFUSED_IDENTITY];
        }

        $identity = NftCollectionIdentifier::canonicalize((string) ($chain->chain_type ?? ''), $canonical);
        if (!$identity->isAccepted()) {
            return ['ok' => false, 'status' => 'refused', 'reason' => self::REFUSED_IDENTITY];
        }

        $current = (string) $row->provisioning_state;

        // Already provisioned, or a live community exists that the row has
        // not caught up with. Either way there is nothing to request.
        if ($current === ProvisioningState::PROVISIONED) {
            return ['ok' => true, 'status' => 'exists'];
        }

        if (GatedGroupRepository::findGroupForCollection((int) $row->chain_id, $identity->canonical()) !== null) {
            return ['ok' => true, 'status' => 'exists', 'reason' => self::REFUSED_COMMUNITY_EXISTS];
        }

        // Asking again while a request is pending is a no-op, not an error,
        // and must not re-stamp the timestamp or re-attribute the request to
        // whoever clicked most recently.
        if ($current === ProvisioningState::REQUESTED) {
            return ['ok' => true, 'status' => 'already_requested'];
        }

        if (!ProvisioningState::canTransition($current, ProvisioningState::REQUESTED)) {
            return ['ok' => false, 'status' => 'refused', 'reason' => self::REFUSED_ILLEGAL_TRANSITION];
        }

        $requestedAt = gmdate('Y-m-d H:i:s');

        try {
            TransactionManager::run(function () use ($collectionId, $current, $operatorId, $requestedAt, $row) {
                $moved = CollectionRepository::setProvisioningState(
                    $collectionId,
                    $current,
                    ProvisioningState::REQUESTED,
                    $operatorId,
                    $requestedAt,
                    null
                );

                if (!$moved) {
                    throw new \RuntimeException('provisioning state did not move to requested');
                }

                $auditId = AuditLogger::logChecked(
                    self::AUDIT_REQUESTED,
                    $collectionId,
                    [
                        'collection_id'    => $collectionId,
                        'chain_id'         => (int) $row->chain_id,
                        'operator_user_id' => $operatorId,
                        'previous_state'   => $current,
                        'new_state'        => ProvisioningState::REQUESTED,
                    ],
                    'collection',
                    $operatorId
                );

                if ($auditId === null) {
                    // A request nobody can prove was made is not a request.
                    throw new \RuntimeException('checked audit write failed; rolling back the request');
                }

                return ['ok' => true, 'audit_id' => $auditId];
            });
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] community request rolled back', [
                'collection_id' => $collectionId,
                'error'         => $e->getMessage(),
            ]);
            return ['ok' => false, 'status' => 'refused', 'reason' => self::REFUSED_WRITE_FAILED];
        }

        return ['ok' => true, 'status' => 'requested'];
    }

    /**
     * Withdraw a PENDING request.
     *
     * A `provisioned` collection is untouched and reported as such — the
     * community exists, people may be in it, and an operator withdrawing a
     * request must never be a way to remove one.
     *
     * @return array{ok: bool, status: string, reason?: string}
     *         status ∈ {withdrawn, nothing_pending, provisioned, refused}
     */
    public function withdraw(int $collectionId, int $operatorId): array
    {
        if ($collectionId <= 0) {
            return ['ok' => false, 'status' => 'refused', 'reason' => self::REFUSED_NOT_FOUND];
        }

        if ($operatorId <= 0 || get_userdata($operatorId) === false || !user_can($operatorId, 'manage_options')) {
            return ['ok' => false, 'status' => 'refused', 'reason' => self::REFUSED_BAD_OPERATOR];
        }

        $row = CollectionRepository::readProvisioningRow($collectionId);
        if ($row === null) {
            return ['ok' => false, 'status' => 'refused', 'reason' => self::REFUSED_NOT_FOUND];
        }

        $current = (string) $row->provisioning_state;

        if ($current === ProvisioningState::PROVISIONED) {
            return ['ok' => true, 'status' => 'provisioned'];
        }

        if ($current === ProvisioningState::NONE) {
            return ['ok' => true, 'status' => 'nothing_pending'];
        }

        try {
            TransactionManager::run(function () use ($collectionId, $current, $operatorId, $row) {
                // The narrow repository method, not setProvisioningState():
                // its WHERE clause names the only two withdrawable states, so
                // `provisioned` cannot be reached by this path whatever the
                // caller passes.
                $withdrawn = CollectionRepository::withdrawPendingProvisioning($collectionId);
                if ($withdrawn !== 1) {
                    throw new \RuntimeException('no pending request was withdrawn');
                }

                $auditId = AuditLogger::logChecked(
                    self::AUDIT_WITHDRAWN,
                    $collectionId,
                    [
                        'collection_id'    => $collectionId,
                        'chain_id'         => (int) $row->chain_id,
                        'operator_user_id' => $operatorId,
                        'previous_state'   => $current,
                        'new_state'        => ProvisioningState::NONE,
                    ],
                    'collection',
                    $operatorId
                );

                if ($auditId === null) {
                    throw new \RuntimeException('checked audit write failed; rolling back the withdrawal');
                }

                return ['ok' => true, 'audit_id' => $auditId];
            });
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] community request withdrawal rolled back', [
                'collection_id' => $collectionId,
                'error'         => $e->getMessage(),
            ]);
            return ['ok' => false, 'status' => 'refused', 'reason' => self::REFUSED_WRITE_FAILED];
        }

        return ['ok' => true, 'status' => 'withdrawn'];
    }

    /**
     * Apply a verification change and reconcile provisioning intent in the
     * SAME transaction.
     *
     * ── THE RULE THIS ENFORCES ──────────────────────────────────────────
     * Removing verification withdraws a PENDING request (`requested` or
     * `failed` -> `none`) and NEVER touches `provisioned`. The community, its
     * members and its gate metadata all survive an unverify — issue #215
     * settles that asymmetry, and it is enforced by
     * `withdrawPendingProvisioning()`'s WHERE clause rather than by remembering
     * to check.
     *
     * Verifying a collection changes no provisioning state at all. That is
     * the entire point: verification is necessary and never sufficient.
     *
     * @param list<int> $verifyIds
     * @param list<int> $unverifyIds
     * @return array{ok: bool, changed: int, withdrawn: int}
     */
    public function applyVerification(array $verifyIds, array $unverifyIds, int $operatorId): array
    {
        $verify   = array_values(array_unique(array_filter(array_map('intval', $verifyIds),   static fn ($id) => $id > 0)));
        $unverify = array_values(array_unique(array_filter(array_map('intval', $unverifyIds), static fn ($id) => $id > 0)));

        if ($verify === [] && $unverify === []) {
            return ['ok' => true, 'changed' => 0, 'withdrawn' => 0];
        }

        if ($operatorId <= 0 || get_userdata($operatorId) === false || !user_can($operatorId, 'manage_options')) {
            return ['ok' => false, 'changed' => 0, 'withdrawn' => 0];
        }

        try {
            /** @var array{changed: int, withdrawn: int} $result */
            $result = TransactionManager::run(function () use ($verify, $unverify, $operatorId) {
                $changed = CollectionRepository::setVerifiedBulk($verify, $unverify);

                // Withdraw pending intent for everything just unverified.
                // Done per-id rather than as one UPDATE ... IN (...) so the
                // count is exact and a row already at `none` is not counted
                // as a withdrawal that did not happen.
                $withdrawn = 0;
                foreach ($unverify as $id) {
                    $withdrawn += CollectionRepository::withdrawPendingProvisioning($id);
                }

                $auditId = AuditLogger::logChecked(
                    'admin_vc_verification_saved',
                    null,
                    [
                        'operator_user_id' => $operatorId,
                        'verified_count'   => count($verify),
                        'unverified_count' => count($unverify),
                        'changed'          => $changed,
                        'withdrawn'        => $withdrawn,
                    ],
                    'collection',
                    $operatorId
                );

                if ($auditId === null) {
                    throw new \RuntimeException('checked audit write failed; rolling back the verification change');
                }

                return ['changed' => $changed, 'withdrawn' => $withdrawn];
            });
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] verification change rolled back', [
                'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'changed' => 0, 'withdrawn' => 0];
        }

        return ['ok' => true, 'changed' => $result['changed'], 'withdrawn' => $result['withdrawn']];
    }
}
