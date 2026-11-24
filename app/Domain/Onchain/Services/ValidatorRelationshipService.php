<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Core\DB\AdvisoryLock;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\ValidatorRelationshipLogRepository as Log;
use BCC\Trust\Onchain\Repositories\ValidatorRepository;
use BCC\Trust\Onchain\Support\RelationshipLogAnchor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The ONLY writer of validator owner relationships made outside the wallet
 * claim flow: a staff member links, revokes or cancels a page owner. (The
 * system's replacement of an owner by a stronger proof comes with F-550,
 * with the ownership-swap step it needs.)
 *
 * ## One lock order, everywhere
 *
 *   GET_LOCK bcc_vrl_append → GET_LOCK bcc_claim:validator:{id}:operator
 *   → START TRANSACTION → step-up consume, owner read, claim rows, log rows
 *   → COMMIT / ROLLBACK → RELEASE the claim lock → advance the log anchor
 *   (only after a COMMIT) → RELEASE vrl (finally)
 *
 * A lock that cannot be taken means no write (503). Only claim rows and log
 * rows are written inside the transaction; the page OWNERSHIP move (PeepSo
 * owner role, post_author, score rows) is left to the PageOwnershipAligner,
 * scheduled after COMMIT and run asynchronously with no current user.
 *
 * ## D3 (permanent)
 *
 * Staff may act only on a STAFF-APPROVED owner. An owner proven by a wallet
 * or a key proof, or whose method cannot be identified, is locked for staff:
 * no link over them, no revoke, no cancel — `wallet_owner_present`. The
 * system path, the owner's own actions and suspension are not staff actions
 * and are not refused here.
 *
 * Every method takes the acting user ids explicitly; nothing reads the
 * current user.
 *
 * @package BCC\Trust\Onchain\Services
 */
final class ValidatorRelationshipService
{
    public const EVIDENCE_TYPES       = ['website', 'keybase', 'manual'];
    public const STAFF_REVOKE_REASONS = ['linked_in_error', 'evidence_withdrawn', 'owner_requested', 'ownership_disputed'];

    public const MAX_EVIDENCE_NOTE = 2000;
    public const MAX_EVIDENCE_REF  = 2048;

    private const LOCK_TIMEOUT = 5;

    /**
     * D3: is an owner proven by $method locked for staff? Everything except a
     * staff approval is; an unknown or missing method counts as locked.
     */
    public static function lockedForStaff(?string $method): bool
    {
        return $method !== 'staff';
    }

    /**
     * A staff member links $userId as the owner of $pageId.
     *
     * @return array{ok: true, link_id: int}|array{ok: false, reason: string, status: int}
     */
    public static function linkOwnerByStaff(
        int $staffId,
        int $pageId,
        int $userId,
        string $evidenceType,
        string $evidenceNote,
        ?string $evidenceRef,
        string $stepUpId,
        ?string $code
    ): array {
        if ($staffId <= 0 || $userId <= 0 || $pageId <= 0) {
            return self::refused('invalid_request', 400);
        }
        if (!in_array($evidenceType, self::EVIDENCE_TYPES, true) || !self::evidenceIsValid($evidenceNote, $evidenceRef)) {
            return self::refused('invalid_evidence', 422);
        }
        $validator = ValidatorRepository::findFirstByPageIdStrict($pageId);
        if ($validator === null) {
            return self::refused('not_a_validator_page', 404);
        }
        $validatorId = (int) $validator->validator_id;

        $stepUpError = ValidatorStepUpService::checkCode($staffId, $stepUpId, $code);
        if ($stepUpError !== null) {
            return self::refused($stepUpError, 403);
        }

        return self::underLocks($validatorId, static function () use ($staffId, $pageId, $userId, $validatorId, $evidenceType, $evidenceNote, $evidenceRef, $stepUpId): array {
            if (!ValidatorStepUpService::consumeInTransaction($staffId, $stepUpId, 'link_owner', $pageId, $userId, 'owner')) {
                throw new RelationshipRefusal('step_up_invalid', 403);
            }
            $owners = ClaimRepository::ownerClaimsForPageStrict($pageId);
            foreach ($owners as $owner) {
                if (self::lockedForStaff($owner['verification_method'])) {
                    throw new RelationshipRefusal('wallet_owner_present', 409);
                }
            }
            if ($owners !== []) {
                throw new RelationshipRefusal('owner_exists', 409);
            }

            $write = ClaimRepository::writeRelationshipClaim(
                $userId, 'validator', $validatorId, 'operator', 'staff', $staffId, '', 0,
                ClaimRepository::boundPagesFor('validator', $validatorId)
            );
            if (!$write['ok']) {
                throw new RelationshipRefusal('owner_exists', 409);
            }

            $appended = Log::append([
                'action'          => 'owner_linked',
                'page_id'         => $pageId,
                'subject_user_id' => $userId,
                'actor_kind'      => 'staff',
                'actor_user_id'   => $staffId,
                'payload'         => [
                    'prev'          => null,
                    'new'           => ['user_id' => $userId, 'method' => 'staff'],
                    'validator_id'  => $validatorId,
                    'evidence_type' => $evidenceType,
                    'step_up'       => 'password+code',
                ],
                'evidence_note'   => $evidenceNote,
                'evidence_ref'    => $evidenceRef,
            ]);
            $appended = self::appendMembershipEnds($write['converted_team_roles'], $userId, $staffId, 'staff') ?? $appended;

            return [
                'link_id'  => $write['claim_id'],
                'appended' => $appended,
                'pages'    => $write['mirror_page_ids'],
            ];
        }, static function (array $done) use ($staffId, $pageId, $userId): array {
            AuditLogger::log('validator_owner_linked', $pageId, ['user_id' => $userId, 'link_id' => $done['link_id']], 'page', $staffId);
            self::afterOwnerGained($userId, $done['pages']);
            self::changed('owner_linked', $pageId, $userId, $staffId, (int) $done['link_id']);

            return ['ok' => true, 'link_id' => (int) $done['link_id']];
        });
    }

    /**
     * A staff member revokes the staff-approved owner link $linkId on $pageId.
     *
     * @return array{ok: true}|array{ok: false, reason: string, status: int}
     */
    public static function revokeOwnerByStaff(int $staffId, int $pageId, int $linkId, string $reason, string $note, string $stepUpId, ?string $code): array
    {
        if (!in_array($reason, self::STAFF_REVOKE_REASONS, true)) {
            return self::refused('invalid_reason', 422);
        }

        return self::endLinkByStaff($staffId, $pageId, $linkId, $reason, $note, $stepUpId, $code, 'revoke_owner', 'owner_revoked');
    }

    /**
     * Any staff member cancels the staff-approved owner link $linkId on
     * $pageId (an undo). Delivered messages cannot be recalled; the page goes
     * back to whatever the claims now say (no other owner can exist: a link
     * is refused while one does).
     *
     * @return array{ok: true}|array{ok: false, reason: string, status: int}
     */
    public static function cancelLinkByStaff(int $staffId, int $pageId, int $linkId, string $note, string $stepUpId, ?string $code): array
    {
        return self::endLinkByStaff($staffId, $pageId, $linkId, 'link_cancelled', $note, $stepUpId, $code, 'cancel_link', 'owner_link_cancelled');
    }

    // ── internals ───────────────────────────────────────────────────────

    /**
     * Revoke or cancel a staff-approved owner link, as staff.
     *
     * @return array{ok: true}|array{ok: false, reason: string, status: int}
     */
    private static function endLinkByStaff(int $staffId, int $pageId, int $linkId, string $reason, string $note, string $stepUpId, ?string $code, string $stepUpAction, string $logAction): array
    {
        if ($staffId <= 0 || $pageId <= 0 || $linkId <= 0) {
            return self::refused('invalid_request', 400);
        }
        if (!self::evidenceIsValid($note, null)) {
            return self::refused('invalid_evidence', 422);
        }
        $validator = ValidatorRepository::findFirstByPageIdStrict($pageId);
        if ($validator === null) {
            return self::refused('not_a_validator_page', 404);
        }
        $validatorId = (int) $validator->validator_id;

        $stepUpError = ValidatorStepUpService::checkCode($staffId, $stepUpId, $code);
        if ($stepUpError !== null) {
            return self::refused($stepUpError, 403);
        }

        return self::underLocks($validatorId, static function () use ($staffId, $pageId, $linkId, $reason, $note, $stepUpId, $stepUpAction, $logAction): array {
            $link = null;
            foreach (ClaimRepository::ownerClaimsForPageStrict($pageId) as $owner) {
                if ($owner['claim_id'] === $linkId) {
                    $link = $owner;
                }
            }
            if ($link === null) {
                throw new RelationshipRefusal($stepUpAction === 'cancel_link' ? 'already_cancelled' : 'link_not_found', $stepUpAction === 'cancel_link' ? 409 : 404);
            }
            if (self::lockedForStaff($link['verification_method'])) {
                throw new RelationshipRefusal('wallet_owner_present', 409);
            }
            if (!ValidatorStepUpService::consumeInTransaction($staffId, $stepUpId, $stepUpAction, $pageId, $link['user_id'], 'owner')) {
                throw new RelationshipRefusal('step_up_invalid', 403);
            }

            $result = ClaimRepository::revokeInTransaction($linkId);
            if (!$result['revoked']) {
                throw new RelationshipRefusal('already_cancelled', 409);
            }
            $appended = Log::append([
                'action'          => $logAction,
                'page_id'         => $pageId,
                'subject_user_id' => $link['user_id'],
                'actor_kind'      => 'staff',
                'actor_user_id'   => $staffId,
                'payload'         => [
                    'prev'    => ['user_id' => $link['user_id'], 'method' => $link['verification_method']],
                    'new'     => null,
                    'reason'  => $reason,
                    'step_up' => 'password+code',
                ],
                'evidence_note'   => $note === '' ? null : $note,
            ]);

            return ['result' => $result, 'appended' => $appended, 'user_id' => $link['user_id']];
        }, static function (array $done) use ($staffId, $pageId, $linkId, $reason, $logAction): array {
            AuditLogger::log('validator_' . $logAction, $pageId, ['user_id' => $done['user_id'], 'link_id' => $linkId, 'reason' => $reason], 'page', $staffId);
            ClaimService::afterRevokeCommitted($linkId, $done['result'], $reason, false);
            foreach ($done['result']['mirror_page_ids'] as $lostPage) {
                PageOwnershipAligner::schedule((int) $lostPage);
            }
            self::changed($logAction, $pageId, (int) $done['user_id'], $staffId, $linkId);

            return ['ok' => true];
        });
    }

    /**
     * Run $work under the lock order with the claim lock of $validatorId,
     * inside one transaction; after the COMMIT, advance the anchor while the
     * vrl lock is still held, then (locks released) run $after.
     *
     * @param callable(): array<string, mixed> $work returns at least ['appended' => array{seq: int, row_hash: string}]
     * @param callable(array<string, mixed>): array<string, mixed> $after
     * @return array<string, mixed>
     */
    private static function underLocks(int $validatorId, callable $work, callable $after): array
    {
        $claimLock = 'bcc_claim:validator:' . $validatorId . ':operator';
        if (!AdvisoryLock::acquire(Log::LOCK_NAME, self::LOCK_TIMEOUT)) {
            \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'log_lock_unavailable');
            return self::refused('busy', 503);
        }
        try {
            if (!AdvisoryLock::acquire($claimLock, self::LOCK_TIMEOUT)) {
                \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'claim_lock_unavailable');
                return self::refused('busy', 503);
            }
            try {
                /** @var array<string, mixed> $done */
                $done = TransactionManager::run($work);
            } catch (RelationshipRefusal $refusal) {
                return self::refused($refusal->reason, $refusal->status);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] validator relationship change failed', ['error' => $e->getMessage()]);
                return self::refused('unavailable', 503);
            } finally {
                AdvisoryLock::release($claimLock);
            }

            // Committed; the vrl lock is still held.
            /** @var array{seq: int, row_hash: string} $appended */
            $appended = $done['appended'];
            RelationshipLogAnchor::advance($appended['seq'], $appended['row_hash']);
        } finally {
            AdvisoryLock::release(Log::LOCK_NAME);
        }

        return $after($done);
    }

    /**
     * C-a: a team row that became the owner row ends that membership, on the
     * record.
     *
     * @param array<int, string> $converted page id => the team role it had
     * @return array{id: int, seq: int, row_hash: string}|null the last row appended
     */
    private static function appendMembershipEnds(array $converted, int $userId, ?int $actorId, string $actorKind): ?array
    {
        $last = null;
        foreach ($converted as $pageId => $role) {
            $last = Log::append([
                'action'          => 'team_member_removed',
                'page_id'         => (int) $pageId,
                'subject_user_id' => $userId,
                'actor_kind'      => $actorKind,
                'actor_user_id'   => $actorId,
                'payload'         => ['role' => $role, 'reason' => 'became_owner'],
            ]);
        }

        return $last;
    }

    /**
     * After an owner claim commits: their bonus (a staff-approved owner earns
     * the operator bonus), the bound pages' read model, the verification hook,
     * and the ownership move for each page.
     *
     * @param list<int> $pages
     */
    private static function afterOwnerGained(int $userId, array $pages): void
    {
        try {
            $ownPage = \BCC\Core\ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($userId);
            if ($ownPage) {
                BonusService::recomputeAndApply((int) $ownPage, $userId);
            }
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] owner link: bonus recompute failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
        foreach ($pages as $pageId) {
            \BCC\Trust\Core\Services\PageReadModelSync::markDirty((int) $pageId);
            PageOwnershipAligner::schedule((int) $pageId);
        }
        do_action('bcc_trust_verification_changed', $userId);
    }

    /**
     * Announce a committed relationship change (notifications listen).
     *
     * @param list<int> $replaced
     */
    private static function changed(string $action, int $pageId, int $subjectUserId, ?int $actorId, int $linkId, array $replaced = []): void
    {
        try {
            do_action('bcc_validator_relationship_changed', $action, $pageId, $subjectUserId, $actorId, $linkId, $replaced);
        } catch (\Throwable $e) {
            \BCC\Core\Log\Logger::warning('[bcc-trust] relationship change listener failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }

    /** Evidence is text: bounded, valid UTF-8; a reference is an http(s) link. */
    private static function evidenceIsValid(string $note, ?string $ref): bool
    {
        if (!mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > self::MAX_EVIDENCE_NOTE) {
            return false;
        }
        if ($ref === null || $ref === '') {
            return true;
        }
        if (!mb_check_encoding($ref, 'UTF-8') || mb_strlen($ref) > self::MAX_EVIDENCE_REF) {
            return false;
        }
        $parts = parse_url($ref);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && (string) ($parts['host'] ?? '') !== '';
    }

    /** @return array{ok: false, reason: string, status: int} */
    private static function refused(string $reason, int $status): array
    {
        return ['ok' => false, 'reason' => $reason, 'status' => $status];
    }
}
