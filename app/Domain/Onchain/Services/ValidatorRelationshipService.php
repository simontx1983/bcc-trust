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
 * The writer of validator owner relationships: a staff member links, revokes
 * or cancels a page owner, and a member's wallet proof claims a validator
 * (claimByWallet), superseding a staff-approved owner when there is one.
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
        try {
            $lockedPages = ClaimRepository::boundPagesFor('validator', $validatorId);
        } catch (\BCC\Trust\Onchain\Repositories\RepositoryReadFailure) {
            return self::refused('unavailable', 503);
        }

        $result = self::underLocks($validatorId, static function () use ($staffId, $pageId, $userId, $validatorId, $evidenceType, $evidenceNote, $evidenceRef, $stepUpId, $lockedPages): array {
            // The member staff are about to make an owner must be a real,
            // known-not-suspended account with member powers (fail-closed).
            if (!ValidatorLinkTargetCheck::eligible($userId)) {
                throw new RelationshipRefusal('target_not_eligible', 422);
            }
            if (!ValidatorStepUpService::consumeInTransaction($staffId, $stepUpId, 'link_owner', $pageId, $userId, 'owner')) {
                throw new RelationshipRefusal('step_up_invalid', 403);
            }
            $owners = self::incumbentOwners($validatorId, $pageId);
            foreach ($owners as $owner) {
                if (self::lockedForStaff($owner['verification_method'])) {
                    throw new RelationshipRefusal('wallet_owner_present', 409);
                }
            }
            if ($owners !== []) {
                throw new RelationshipRefusal('owner_exists', 409);
            }

            // Mirrors only on pages still bound now AND locked by this run.
            $write = ClaimRepository::writeRelationshipClaim(
                $userId, 'validator', $validatorId, 'operator', 'staff', $staffId, '', 0,
                array_values(array_intersect(ClaimRepository::boundPagesFor('validator', $validatorId), $lockedPages))
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
            self::safely('audit', static fn () => AuditLogger::log('validator_owner_linked', $pageId, ['user_id' => $userId, 'link_id' => $done['link_id']], 'page', $staffId));
            self::afterOwnerGained($userId, $done['pages']);
            self::changed('owner_linked', $pageId, $userId, $staffId, (int) $done['link_id']);

            return ['ok' => true, 'link_id' => (int) $done['link_id']];
        }, $lockedPages);

        if (($result['ok'] ?? false) !== true) {
            return self::refused((string) ($result['reason'] ?? 'unavailable'), (int) ($result['status'] ?? 503));
        }

        return ['ok' => true, 'link_id' => (int) ($result['link_id'] ?? 0)];
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

    /**
     * A member's WALLET PROOF claims validator $validatorId as its operator
     * (the page-claim flow, ClaimService::claim()), and mirrors it onto
     * $pageId, all in one run under the lock order, with the log rows.
     *
     * Precedence (Phillip, D3): a proof outranks a staff approval. So an
     * incumbent owner approved by STAFF is superseded in the same run: their
     * claim (and its page mirrors) is revoked, `owner_superseded` and
     * `owner_linked` are logged, and the page ownership swap is left to the
     * aligner. An incumbent proven by a wallet or key, or whose method is
     * unknown, is never superseded: `already_claimed`, as before.
     *
     * The same user re-claiming is idempotent (no write, no log row). A team
     * row of theirs on the page becomes the owner row, and that membership's
     * end is logged (team_member_removed, became_owner). The page mirror is a
     * wallet claim like the claim it mirrors; it is skipped (logged) when
     * another user still holds an owner row on the page, as before.
     *
     * @return array{success: true, claim_id: int, mirror_written: bool, superseded_user_ids: list<int>}|array{success: false, error: string, message: string}
     */
    public static function claimByWallet(int $userId, int $validatorId, string $walletAddress, int $chainId, int $pageId): array
    {
        if ($userId <= 0 || $validatorId <= 0 || trim($walletAddress) === '' || $chainId <= 0) {
            return ['success' => false, 'error' => 'invalid_request', 'message' => 'Invalid claim.'];
        }

        $result = self::underLocks($validatorId, static function () use ($userId, $validatorId, $walletAddress, $chainId, $pageId): array {
            // Belt: the page is mirrored (and named in the log) only when it
            // is bound to this validator, read strictly inside the run.
            $page = ($pageId > 0 && in_array($pageId, ClaimRepository::boundPagesFor('validator', $validatorId), true)) ? $pageId : 0;

            $mine   = null;
            $others = [];
            foreach (ClaimRepository::verifiedOwnerRowsForEntityStrict('validator', $validatorId) as $row) {
                if ($row['user_id'] === $userId) {
                    $mine = $row;
                } else {
                    $others[] = $row;
                }
            }
            if ($mine !== null) {
                return ['claim_id' => $mine['claim_id'], 'idempotent' => true, 'page' => $page];
            }
            foreach ($others as $other) {
                if (self::lockedForStaff($other['verification_method'])) {
                    throw new RelationshipRefusal('already_claimed', 409); // a proof owner is never superseded
                }
            }

            $superseded = [];
            $prev       = null;
            foreach ($others as $other) {
                $revoked = ClaimRepository::revokeInTransaction($other['claim_id']);
                if (!$revoked['revoked']) {
                    throw new RelationshipRefusal('transient', 503); // changed under us: retry
                }
                $prev = ['user_id' => $other['user_id'], 'method' => $other['verification_method']];
                Log::append([
                    'action'          => 'owner_superseded',
                    'page_id'         => $page,
                    'subject_user_id' => $other['user_id'],
                    'actor_kind'      => 'member',
                    'actor_user_id'   => $userId,
                    'payload'         => [
                        'prev'         => $prev,
                        'new'          => ['user_id' => $userId, 'method' => 'wallet'],
                        'validator_id' => $validatorId,
                        'reason'       => 'superseded_by_proof',
                    ],
                ]);
                $superseded[] = ['claim_id' => $other['claim_id'], 'user_id' => $other['user_id'], 'result' => $revoked];
            }

            $claim = ClaimRepository::upsert($userId, 'validator', $validatorId, $walletAddress, $chainId, 'operator');
            if ($claim === false) {
                throw new \RuntimeException('wallet claim write failed');
            }

            $mirror    = false;
            $converted = [];
            if ($page > 0) {
                $otherOwnerOnPage = false;
                foreach (ClaimRepository::verifiedOwnerRowsForEntityStrict('page', $page) as $row) {
                    $otherOwnerOnPage = $otherOwnerOnPage || $row['user_id'] !== $userId;
                }
                if (!$otherOwnerOnPage) {
                    $teamRole = ClaimRepository::teamRoleOnPageForUpdate($userId, $page);
                    if ($teamRole !== null) {
                        $converted[$page] = $teamRole;
                    }
                    if (ClaimRepository::upsert($userId, 'page', $page, $walletAddress, $chainId, 'operator') === false) {
                        throw new \RuntimeException('page mirror write failed');
                    }
                    $mirror = true;
                }
            }
            self::appendMembershipEnds($converted, $userId, $userId, 'member');

            $appended = Log::append([
                'action'          => 'owner_linked',
                'page_id'         => $page,
                'subject_user_id' => $userId,
                'actor_kind'      => 'member',
                'actor_user_id'   => $userId,
                'payload'         => [
                    'prev'           => $prev,
                    'new'            => ['user_id' => $userId, 'method' => 'wallet'],
                    'validator_id'   => $validatorId,
                    'page_mirrored'  => $mirror,
                ],
            ]);

            return [
                'claim_id'   => $claim['id'],
                'appended'   => $appended,
                'superseded' => $superseded,
                'mirror'     => $mirror,
                'page'       => $page,
            ];
        }, static function (array $done) use ($userId, $validatorId): array {
            $pageId = (int) ($done['page'] ?? 0);
            /** @var list<array{claim_id: int, user_id: int, result: array{revoked: bool, user_id: int, entity_type: string, entity_id: int, claim_role: string, mirror_page_ids: list<int>, bound_page_ids: list<int>}}> $superseded */
            $superseded = $done['superseded'] ?? [];
            $pages      = $pageId > 0 ? [$pageId] : [];
            foreach ($superseded as $old) {
                self::safely('revoke_followups', static fn () => ClaimService::afterRevokeCommitted($old['claim_id'], $old['result'], 'superseded_by_proof', false));
                $pages = array_merge($pages, $old['result']['mirror_page_ids'], $old['result']['bound_page_ids']);
                self::changed('owner_superseded', $pageId > 0 ? $pageId : (int) ($old['result']['mirror_page_ids'][0] ?? 0), $old['user_id'], $userId, $old['claim_id']);
            }
            if (!($done['idempotent'] ?? false)) {
                foreach (array_values(array_unique(array_map('intval', $pages))) as $page) {
                    self::safely('align_schedule', static fn () => PageOwnershipAligner::schedule($page));
                }
            }
            if ($superseded !== []) {
                self::safely('audit', static fn () => AuditLogger::log('validator_owner_superseded', $pageId, [
                    'validator_id' => $validatorId,
                    'superseded'   => array_map(static fn (array $o): int => $o['user_id'], $superseded),
                    'reason'       => 'superseded_by_proof',
                ], 'page', $userId));
            }
            if ($pageId > 0 && !($done['idempotent'] ?? false) && !($done['mirror'] ?? false)) {
                \BCC\Core\Log\Logger::warning('[bcc-trust] page-claim mirror skipped: another user holds an owner row on the page; the claim stands', [
                    'user_id' => $userId,
                    'page_id' => $pageId,
                ]);
            }

            return [
                'ok'                  => true,
                'claim_id'            => (int) $done['claim_id'],
                'mirror_written'      => (bool) ($done['mirror'] ?? false),
                'superseded_user_ids' => array_map(static fn (array $o): int => $o['user_id'], $superseded),
            ];
        }, $pageId > 0 ? [$pageId] : []);

        if (($result['ok'] ?? false) !== true) {
            $reason = (string) ($result['reason'] ?? 'unavailable');
            if ($reason === 'already_claimed') {
                return ['success' => false, 'error' => 'already_claimed', 'message' => 'This project already has a verified Operator.'];
            }

            return ['success' => false, 'error' => 'transient', 'message' => 'Could not complete the claim right now. Please try again.'];
        }

        /** @var list<int> $supersededIds */
        $supersededIds = $result['superseded_user_ids'] ?? [];

        return [
            'success'             => true,
            'claim_id'            => (int) ($result['claim_id'] ?? 0),
            'mirror_written'      => (bool) ($result['mirror_written'] ?? false),
            'superseded_user_ids' => $supersededIds,
        ];
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

        $result = self::underLocks($validatorId, static function () use ($staffId, $pageId, $linkId, $reason, $note, $stepUpId, $stepUpAction, $logAction): array {
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
            self::safely('audit', static fn () => AuditLogger::log('validator_' . $logAction, $pageId, ['user_id' => $done['user_id'], 'link_id' => $linkId, 'reason' => $reason], 'page', $staffId));
            self::safely('revoke_followups', static fn () => ClaimService::afterRevokeCommitted($linkId, $done['result'], $reason, false));
            foreach ($done['result']['mirror_page_ids'] as $lostPage) {
                self::safely('align_schedule', static fn () => PageOwnershipAligner::schedule((int) $lostPage));
            }
            self::changed($logAction, $pageId, (int) $done['user_id'], $staffId, $linkId);

            return ['ok' => true];
        });

        if (($result['ok'] ?? false) !== true) {
            return self::refused((string) ($result['reason'] ?? 'unavailable'), (int) ($result['status'] ?? 503));
        }

        return ['ok' => true];
    }

    /**
     * Run $work under the lock order with the claim lock of $validatorId,
     * then the page claim lock of each of $pageIds (ascending: the lock a
     * collection's page mirror takes, so page rows are written under the
     * same page lock wherever they come from), inside one transaction; after
     * the COMMIT, advance the anchor while the vrl lock is still held, then
     * (locks released) run $after.
     *
     *   vrl → bcc_claim:validator:{V}:operator → bcc_claim:page:{P}:operator…
     *
     * @param callable(): array<string, mixed> $work returns ['appended' => array{seq: int, row_hash: string}] when it appended
     * @param callable(array<string, mixed>): array<string, mixed> $after
     * @param list<int> $pageIds pages whose rows $work may write
     * @return array<string, mixed> $after's result, or a refusal ['ok' => false, 'reason', 'status']
     */
    private static function underLocks(int $validatorId, callable $work, callable $after, array $pageIds = []): array
    {
        $claimLock = 'bcc_claim:validator:' . $validatorId . ':operator';
        $pages     = array_values(array_unique(array_filter(array_map('intval', $pageIds), static fn (int $p): bool => $p > 0)));
        sort($pages);
        if (!AdvisoryLock::acquire(Log::LOCK_NAME, self::LOCK_TIMEOUT)) {
            \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'log_lock_unavailable');
            return self::refused('busy', 503);
        }
        try {
            if (!AdvisoryLock::acquire($claimLock, self::LOCK_TIMEOUT)) {
                \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'claim_lock_unavailable');
                return self::refused('busy', 503);
            }
            $heldPageLocks = [];
            try {
                foreach ($pages as $page) {
                    $pageLock = 'bcc_claim:page:' . $page . ':operator';
                    if (!AdvisoryLock::acquire($pageLock, self::LOCK_TIMEOUT)) {
                        \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'claim_lock_unavailable');
                        return self::refused('busy', 503);
                    }
                    $heldPageLocks[] = $pageLock;
                }
                /** @var array<string, mixed> $done */
                $done = TransactionManager::run($work);
            } catch (RelationshipRefusal $refusal) {
                return self::refused($refusal->reason, $refusal->status);
            } catch (\Throwable $e) {
                \BCC\Core\Log\Logger::error('[bcc-trust] validator relationship change failed', ['error' => $e->getMessage()]);
                return self::refused('unavailable', 503);
            } finally {
                foreach (array_reverse($heldPageLocks) as $heldPageLock) {
                    AdvisoryLock::release($heldPageLock);
                }
                AdvisoryLock::release($claimLock);
            }

            // Committed; the vrl lock is still held. (An idempotent run
            // appended nothing and moves nothing.)
            if (isset($done['appended'])) {
                /** @var array{seq: int, row_hash: string} $appended */
                $appended = $done['appended'];
                RelationshipLogAnchor::advance($appended['seq'], $appended['row_hash']);
            }
        } finally {
            AdvisoryLock::release(Log::LOCK_NAME);
        }

        return $after($done);
    }

    /**
     * Every verified owner of the validator, read under its claim lock (the
     * lock createExclusiveClaim() takes): the validator's own owner rows, and
     * for the requested page and EVERY page bound to the validator, the
     * owners the resolvers see plus the page's own owner rows. Fail-closed.
     *
     * @return list<array{claim_id: int, user_id: int, verification_method: string}>
     * @throws \BCC\Trust\Onchain\Repositories\RepositoryReadFailure
     */
    private static function incumbentOwners(int $validatorId, int $pageId): array
    {
        $byClaim = [];
        foreach (ClaimRepository::verifiedOwnerRowsForEntityStrict('validator', $validatorId) as $row) {
            $byClaim[$row['claim_id']] = $row;
        }
        $pages   = ClaimRepository::boundPagesFor('validator', $validatorId);
        $pages[] = $pageId;
        foreach (array_values(array_unique($pages)) as $page) {
            foreach (ClaimRepository::ownerClaimsForPageStrict($page) as $row) {
                $byClaim[$row['claim_id']] = $row;
            }
            foreach (ClaimRepository::verifiedOwnerRowsForEntityStrict('page', $page) as $row) {
                $byClaim[$row['claim_id']] = $row;
            }
        }

        return array_values($byClaim);
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
        self::safely('bonus', static function () use ($userId): void {
            $ownPage = \BCC\Core\ServiceLocator::resolvePageOwnerResolver()->getPageForOwner($userId);
            if ($ownPage) {
                BonusService::recomputeAndApply((int) $ownPage, $userId);
            }
        });
        foreach ($pages as $pageId) {
            self::safely('read_model', static fn () => \BCC\Trust\Core\Services\PageReadModelSync::markDirty((int) $pageId));
            self::safely('align_schedule', static fn () => PageOwnershipAligner::schedule((int) $pageId));
        }
        self::safely('verification_changed', static fn () => do_action('bcc_trust_verification_changed', $userId));
    }

    /**
     * One post-commit step: the change is COMMITTED, so a failure here is
     * logged and counted, never thrown (it must not report the change as
     * failed).
     */
    private static function safely(string $step, callable $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            \BCC\Core\Observability\DegradationMetrics::record('validator_relationships', 'followup_failed');
            \BCC\Core\Log\Logger::error('[bcc-trust] validator relationship follow-up failed', ['step' => $step, 'error' => $e->getMessage()]);
        }
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
