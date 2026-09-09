<?php
/**
 * Hall creation — ADMINISTRATOR-INITIATED, one named chain at a time.
 *
 * ── WHAT CHANGED, AND WHY ───────────────────────────────────────────────
 * Halls used to be auto-provisioned: a daily `bcc_hall_provision` cron
 * swept `ChainRepository::getActive()` and created a public group for every
 * chain that lacked one. Adding a chain therefore created a public space
 * within ~24h with nobody deciding that it should exist. The cron, its
 * activation schedule, its self-heal registration and the bulk "Provision
 * Halls now" button are all retired; `provisionOne()` is the only way a
 * Hall comes into being, and it is reachable only from a capability-gated,
 * nonce-checked, POST-only admin action.
 *
 * A Hall is an official, administrator-created, chain-connected public
 * group. Exactly one canonical chain; a chain has zero or one Hall.
 *
 * ── WHY CREATION IS NOT IN A TRANSACTION ────────────────────────────────
 * The same reason {@see GatedGroupProvisioningService} gives, and it applies
 * verbatim: `new PeepSoGroup(null, $data)` runs `wp_insert_post()` →
 * `member_join()` → ~20 `add_post_meta()` → `do_action('peepso_action_group_create')`.
 * Database rows would roll back; the album directory it mkdirs, the admin
 * notification e-mail a subscriber may send, the primed object cache and the
 * `save_post` fan-out would not. So: create through the supported path, then
 * verify, then compensate or mark.
 *
 * ── OWNERSHIP IS DETERMINISTIC, AND IT IS NOT WHOEVER CLICKED ───────────
 * PeepSo's `create()` calls `new PeepSoGroupUser($id)` with no user id,
 * which defaults to `get_current_user_id()` — so the CREATOR is joined and
 * made `member_owner`, whatever `owner_id` the caller passed (that only ever
 * became `post_author`). A Hall is an official platform space, so its owner
 * must not depend on which administrator happened to press the button.
 *
 * This diverges deliberately from the holder-group path, where PR 6 made the
 * owner the administrator who requested the community: there, ownership
 * follows a recorded authorization for a space about someone's collection.
 * A Hall belongs to the platform, so it is owned by the canonical first
 * administrator instead.
 *
 * The two books (post_author and the member_owner row) are made to AGREE at
 * create time by passing the creator as `owner_id` — without that they
 * disagree by construction, and `PeepSoGroupWriter::transferOwnership()`
 * refuses every argument order. Ownership then moves through the supported
 * writer, and the postcondition is PROVEN before the Hall is reported.
 *
 * ── TWO FAILURE SHAPES, TWO RESPONSES ───────────────────────────────────
 * They are not the same problem and must not get the same answer:
 *
 *   - The MARKER did not stick. `findHallForChain()` cannot see the group,
 *     so the next attempt would create a DUPLICATE. Compensated: the group
 *     is removed, exactly as GatedGroupProvisioningService compensates an
 *     ungatable community.
 *   - OWNERSHIP could not be reconciled. The Hall is correct in every other
 *     respect and is discoverable, so a retry is idempotent and creates
 *     nothing. Removing a correct public space because ownership could not
 *     move between two administrators would be the more destructive answer.
 *     It is MARKED (`_bcc_hall_owner_incomplete`), audited, and reported as
 *     `incomplete` — never as a successful provision.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Log\Logger;
use BCC\Core\PeepSo\PeepSoGroupWriter;
use BCC\Core\Repositories\PeepSoGroupRepository;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\HallRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class HallProvisioningService {

    /** Audit actions. All four fit VARCHAR(50). */
    public const AUDIT_CREATED      = 'admin_hall_created';
    public const AUDIT_FAILED       = 'admin_hall_create_failed';
    public const AUDIT_INCOMPLETE   = 'admin_hall_owner_incomplete';
    public const AUDIT_COMPENSATED  = 'admin_hall_create_compensated';

    /**
     * Bounded failure codes. Durable, machine-readable, and never prose —
     * `AuditMeta` replaces free text under keys like `message` with a
     * `{omitted, len}` descriptor, so a reason that matters must travel as a
     * code under a key that is not on that list.
     */
    public const FAIL_PEEPSO_ABSENT    = 'peepso_absent';
    public const FAIL_CHAIN_UNRESOLVED = 'chain_unresolved';
    public const FAIL_CHAIN_UNNAMED    = 'chain_unnamed';
    public const FAIL_OWNER_UNRESOLVED = 'owner_unresolved';
    public const FAIL_NO_ACTING_ADMIN  = 'no_acting_administrator';
    public const FAIL_CREATE_FAILED    = 'group_create_failed';
    public const FAIL_MARKER_REFUSED   = 'hall_marker_refused';

    /**
     * Create the Hall for one chain.
     *
     * Idempotent: a chain that already has a Hall returns `exists` and
     * creates nothing, so a double submit or a browser refresh cannot mint a
     * second one. A Hall whose ownership is marked incomplete is RETRIED
     * here rather than duplicated — the repair path is the same action.
     *
     * @return array{status: string, group_id: int, message: string,
     *               failure_code: string|null, owner_id: int,
     *               audit_degraded: bool}
     *         status ∈ {created, exists, incomplete, skipped, error}.
     *         `incomplete` means the Hall EXISTS but its ownership could not
     *         be reconciled — it is marked and audited, and must never be
     *         reported to an operator as a successful provision.
     */
    public function provisionOne(int $chainId): array {
        if (!class_exists('\\PeepSoGroup')) {
            return $this->fail(0, $chainId, '', self::FAIL_PEEPSO_ABSENT, 'PeepSo Groups inactive.');
        }
        if ($chainId <= 0) {
            return $this->refuse('Invalid chain id.', self::FAIL_CHAIN_UNRESOLVED);
        }

        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return $this->refuse('Chain not found.', self::FAIL_CHAIN_UNRESOLVED);
        }

        $name = trim((string) $chain->name);
        if ($name === '') {
            return $this->refuse(
                'This chain has no display name yet, so its Hall cannot be named.',
                self::FAIL_CHAIN_UNNAMED
            );
        }

        $chainSlug = (string) $chain->slug;

        // ── Idempotency, checked BEFORE anything is created ─────────────
        $existing = HallRepository::findHallForChain($chainId);
        if ($existing !== null) {
            return $this->reconcileExistingHall((int) $existing, $chainId, $chainSlug);
        }

        // ── The acting administrator ────────────────────────────────────
        // Creation is admin-initiated only, so there is always a current
        // user. Without one the two ownership books cannot be made to agree
        // and `transferOwnership()` would refuse — fail closed rather than
        // create a public group owned by user 0.
        $creatorId = get_current_user_id();
        if ($creatorId <= 0) {
            return $this->fail(0, $chainId, $chainSlug, self::FAIL_NO_ACTING_ADMIN,
                'Hall creation requires a signed-in administrator.');
        }

        // ── The intended, deterministic owner ───────────────────────────
        $ownerId = $this->resolveOwnerId();
        if ($ownerId === 0) {
            return $this->fail(0, $chainId, $chainSlug, self::FAIL_OWNER_UNRESOLVED,
                'No administrator account could be resolved to own the Hall.');
        }

        // ── Create. NOT transactional; compensated below. ───────────────
        $groupId = $this->createHallGroup($creatorId, $name);
        if ($groupId === 0) {
            return $this->fail(0, $chainId, $chainSlug, self::FAIL_CREATE_FAILED,
                'Hall creation failed; see the bcc-trust error log.');
        }

        // ── The marker, and PROOF that it stuck ─────────────────────────
        // This is the duplicate-preventing write: until the chain resolves
        // to this group, another attempt would create a second Hall. A
        // marker that did not take is therefore compensated, not marked.
        HallRepository::writeHallMeta($groupId, $chainId);
        $this->invalidateGroupCaches($groupId);

        if (HallRepository::findHallForChain($chainId) !== $groupId) {
            Logger::error('[bcc-trust] Hall marker did not survive its re-read; compensating', [
                'group_id' => $groupId,
                'chain_id' => $chainId,
            ]);

            $auditOk = $this->compensate($groupId, $chainId, $chainSlug, $creatorId);

            return $this->fail(
                $groupId,
                $chainId,
                $chainSlug,
                self::FAIL_MARKER_REFUSED,
                'The Hall could not be linked to its chain and was rolled back.',
                $auditOk === false
            );
        }

        // ── Ownership reconciliation is PART OF CREATION ────────────────
        $reconciled = $this->reconcileOwnership($groupId, $creatorId, $ownerId);

        if (!$reconciled['ok']) {
            return $this->recordIncomplete(
                $groupId,
                $chainId,
                $chainSlug,
                $creatorId,
                $ownerId,
                (string) $reconciled['step']
            );
        }

        Logger::info('[bcc-trust] Hall created', [
            'group_id'   => $groupId,
            'chain_id'   => $chainId,
            'chain_slug' => $chainSlug,
            'owner_id'   => $ownerId,
            'operator'   => $creatorId,
        ]);

        $auditId = AuditLogger::logChecked(
            self::AUDIT_CREATED,
            $chainId,
            [
                'chain_id'         => $chainId,
                'chain_slug'       => $chainSlug,
                'group_id'         => $groupId,
                'operator_user_id' => $creatorId,
                'owner_user_id'    => $ownerId,
                'reconcile_step'   => (string) $reconciled['step'],
            ],
            'chain',
            $creatorId
        );

        // The Hall is correct and discoverable; only its record is missing.
        // Surfaced rather than swallowed, and NOT compensated — deleting a
        // correct public space over a lost audit row would be the worse
        // trade. The operator is told.
        if ($auditId === null) {
            Logger::error('[bcc-trust] Hall created but its checked audit could not be written', [
                'group_id' => $groupId,
                'chain_id' => $chainId,
            ]);
        }

        // Fan-out hook for downstream subscribers. Stable signature:
        // (groupId, chainId, chainSlug).
        do_action('bcc_hall_provisioned', $groupId, $chainId, $chainSlug);

        return [
            'status'         => 'created',
            'group_id'       => $groupId,
            'message'        => 'Hall created.',
            'failure_code'   => null,
            'owner_id'       => $ownerId,
            'audit_degraded' => $auditId === null,
        ];
    }

    /**
     * A Hall already exists for this chain.
     *
     * Nothing is created. If a previous attempt left the ownership marker
     * behind, the reconciliation is RETRIED here — that is what makes the
     * same button the repair path, and why a marked Hall is never
     * duplicated to work around it.
     *
     * @return array{status: string, group_id: int, message: string,
     *               failure_code: string|null, owner_id: int,
     *               audit_degraded: bool}
     */
    private function reconcileExistingHall(int $groupId, int $chainId, string $chainSlug): array {
        if (!HallRepository::isOwnerIncomplete($groupId)) {
            return [
                'status'         => 'exists',
                'group_id'       => $groupId,
                'message'        => 'This chain already has a Hall.',
                'failure_code'   => null,
                'owner_id'       => 0,
                'audit_degraded' => false,
            ];
        }

        $creatorId = get_current_user_id();
        $ownerId   = $this->resolveOwnerId();

        if ($ownerId === 0) {
            return $this->incompleteResult($groupId, 'owner_unresolved',
                'The Hall exists, but no administrator account could be resolved to own it.');
        }

        // Whoever currently holds member_owner is the transfer's from-user.
        $currentOwner = $this->currentOwnerId($groupId);
        if ($currentOwner === 0) {
            return $this->incompleteResult($groupId, 'owner_row_missing',
                'The Hall exists, but its ownership row could not be read.');
        }

        $reconciled = $this->reconcileOwnership($groupId, $currentOwner, $ownerId);
        if (!$reconciled['ok']) {
            return $this->incompleteResult($groupId, (string) $reconciled['step'],
                'The Hall exists, but its ownership still could not be repaired.');
        }

        HallRepository::clearOwnerIncomplete($groupId);
        $this->invalidateGroupCaches($groupId);

        $auditId = AuditLogger::logChecked(
            self::AUDIT_CREATED,
            $chainId,
            [
                'chain_id'         => $chainId,
                'chain_slug'       => $chainSlug,
                'group_id'         => $groupId,
                'operator_user_id' => $creatorId,
                'owner_user_id'    => $ownerId,
                'reconcile_step'   => 'repaired',
            ],
            'chain',
            $creatorId ?: null
        );

        return [
            'status'         => 'created',
            'group_id'       => $groupId,
            'message'        => 'Hall ownership repaired.',
            'failure_code'   => null,
            'owner_id'       => $ownerId,
            'audit_degraded' => $auditId === null,
        ];
    }

    /**
     * Move ownership from the creator to the intended owner, and PROVE it.
     *
     * Every write goes through {@see PeepSoGroupWriter} — §E3 single-graph:
     * BCC never touches `peepso_group_members` directly.
     *
     * ── ⚠ PEEPSO-WRITE-GUARD: A NEW `join()` CALL SITE, AND WHY IT PASSES ──
     * The guard exists because `PeepSoGroupUser::member_join()` writes
     * `gm_user_status = 'member'` UNCONDITIONALLY — it does not branch on
     * `is_closed` / `is_secret` — so an ungated call lands somebody as a full
     * member of a group they should never reach. Three facts retire that risk
     * here, and all three are load-bearing:
     *
     *   1. THE GROUP IS OPEN. A Hall is created with `privacy => 0`,
     *      `is_joinable => true`, `is_auto_accept_join_request => true`. There
     *      is no closed/secret gating to bypass: anyone may join a Hall, so
     *      this join grants nothing that is not already public.
     *   2. THE ONLY JOINABLE USER IS THE GROUP'S OWN OWNER. `$intendedOwnerId`
     *      comes from {@see resolveOwnerId()} and from nowhere else — never
     *      from request input. It is not a third party.
     *   3. ELIGIBILITY IS RUNTIME, NOT HISTORICAL. `resolveOwnerId()` re-reads
     *      `get_userdata()` AND `user_can($id, 'manage_options')` on every
     *      call, so a deleted or demoted account fails closed rather than
     *      inheriting a stale approval.
     *
     * The request-boundary gate is upstream and total:
     * {@see \BCC\Trust\Onchain\Admin\ChainsPage::handle_hall_create()} runs
     * `requireCapability()` (manage_options) → `requirePost()` (405) →
     * `requireNonce(ACTION_HALL_CREATE . '_' . $chainId)` → the chain must
     * resolve through `ChainRepository`. No cron, hook, shortcode, CLI command
     * or bulk sweep can reach this method — that is asserted by
     * HallProvisionCronRetiredTest::testOnlyTheAdminActionCallsTheHallCreator.
     *
     * This is NOT the "admin fix-membership button" the guard rejects: that
     * pattern re-joins ARBITRARY users on stale approval. This joins one
     * deterministic owner to an open group, checked at the moment of the write.
     *
     * Every writer's return value is checked, including `leave()` — an ignored
     * `false` there would silently corrupt owner state.
     *
     * @return array{ok: bool, step: string}
     */
    private function reconcileOwnership(int $groupId, int $creatorId, int $intendedOwnerId): array {
        if ($creatorId === $intendedOwnerId) {
            // The acting administrator IS the canonical owner. PeepSo's
            // create already produced exactly the state we want.
            return ['ok' => true, 'step' => 'already_owned'];
        }

        if (!PeepSoGroupWriter::join($intendedOwnerId, $groupId)) {
            return ['ok' => false, 'step' => 'join'];
        }

        if (!PeepSoGroupWriter::transferOwnership($groupId, $creatorId, $intendedOwnerId)) {
            return ['ok' => false, 'step' => 'transfer'];
        }

        // transferOwnership demotes the previous owner to member_manager,
        // so leave() — which refuses only member_owner — now applies.
        if (!PeepSoGroupWriter::leave($creatorId, $groupId)) {
            return ['ok' => false, 'step' => 'leave'];
        }

        // ── The postcondition, asked of the ledger ──────────────────────
        // Not inferred from three true return values: each writer reports
        // what it attempted, which is not the same claim as "the ledger now
        // reads this way".
        if (PeepSoGroupRepository::getMembershipStatus($intendedOwnerId, $groupId) !== 'member_owner') {
            return ['ok' => false, 'step' => 'postcondition_owner'];
        }

        if (PeepSoGroupRepository::getMembershipStatus($creatorId, $groupId) !== null) {
            return ['ok' => false, 'step' => 'postcondition_creator'];
        }

        return ['ok' => true, 'step' => 'reconciled'];
    }

    /**
     * The Hall exists but its ownership does not. Mark it, record it, and
     * report `incomplete` — never `created`.
     *
     * @return array{status: string, group_id: int, message: string,
     *               failure_code: string|null, owner_id: int,
     *               audit_degraded: bool}
     */
    private function recordIncomplete(
        int $groupId,
        int $chainId,
        string $chainSlug,
        int $creatorId,
        int $ownerId,
        string $step
    ): array {
        HallRepository::markOwnerIncomplete($groupId);
        $this->invalidateGroupCaches($groupId);

        Logger::error('[bcc-trust] Hall created but ownership could not be reconciled', [
            'group_id'       => $groupId,
            'chain_id'       => $chainId,
            'chain_slug'     => $chainSlug,
            'operator'       => $creatorId,
            'intended_owner' => $ownerId,
            'reconcile_step' => $step,
        ]);

        $auditId = AuditLogger::logChecked(
            self::AUDIT_INCOMPLETE,
            $chainId,
            [
                'chain_id'         => $chainId,
                'chain_slug'       => $chainSlug,
                'group_id'         => $groupId,
                'operator_user_id' => $creatorId,
                'owner_user_id'    => $ownerId,
                'reconcile_step'   => $step,
                'error_code'       => 'ownership_unreconciled',
            ],
            'chain',
            $creatorId
        );

        do_action('bcc_hall_provisioned', $groupId, $chainId, $chainSlug);

        return [
            'status'         => 'incomplete',
            'group_id'       => $groupId,
            'message'        => 'The Hall was created, but its ownership could not be set. '
                . 'It is flagged for repair — run Create Chain Hall again to retry.',
            'failure_code'   => 'ownership_unreconciled',
            'owner_id'       => $ownerId,
            'audit_degraded' => $auditId === null,
        ];
    }

    /**
     * @return array{status: string, group_id: int, message: string,
     *               failure_code: string|null, owner_id: int,
     *               audit_degraded: bool}
     */
    private function incompleteResult(int $groupId, string $step, string $message): array {
        HallRepository::markOwnerIncomplete($groupId);
        $this->invalidateGroupCaches($groupId);

        return [
            'status'         => 'incomplete',
            'group_id'       => $groupId,
            'message'        => $message,
            'failure_code'   => 'ownership_unreconciled',
            'owner_id'       => 0,
            'audit_degraded' => false,
        ];
    }

    /**
     * Remove a Hall that could not be linked to its chain.
     *
     * Mirrors {@see GatedGroupProvisioningService::compensate()} and PeepSo's
     * own delete cascade: drop the marker meta, remove the owner membership
     * through the supported per-member model, hard-delete the post, then
     * PROVE the chain no longer resolves to it.
     *
     * ⚠ `peepso_action_group_create` has already fired. If
     * `groups_create_notify_admin` is on, an administrator e-mail was sent
     * and cannot be recalled — recorded, not hidden.
     *
     * @return bool whether the compensation's own audit row committed
     */
    private function compensate(int $groupId, int $chainId, string $chainSlug, int $creatorId): bool {
        $notes = [];

        foreach ([HallRepository::META_KIND, HallRepository::META_CHAIN_TAG, HallRepository::META_OWNER_INCOMPLETE] as $key) {
            delete_post_meta($groupId, $key);
        }

        if (class_exists('\\PeepSoGroupUser')) {
            try {
                $member = new \PeepSoGroupUser($groupId, $creatorId);
                $member->member_leave();
            } catch (\Throwable $e) {
                $notes[] = 'member_leave_threw';
            }
        }

        if (!wp_delete_post($groupId, true)) {
            $notes[] = 'wp_delete_post_returned_falsy';
        }

        $this->invalidateGroupCaches($groupId);

        // PROVE it: compensation that cannot show the Hall is gone is a hope.
        $residue = HallRepository::findHallForChain($chainId) === $groupId ? 'chain_still_resolves' : 'clean';
        if ($residue !== 'clean') {
            Logger::error('[bcc-trust] Hall compensation left residue; a live Hall may remain', [
                'group_id' => $groupId,
                'chain_id' => $chainId,
            ]);
        }

        $notifyOn = false;
        if (class_exists('\\PeepSo')) {
            $notifyOn = (bool) \PeepSo::get_option('groups_create_notify_admin', 0);
        }

        $auditId = AuditLogger::logChecked(
            self::AUDIT_COMPENSATED,
            $chainId,
            [
                'chain_id'         => $chainId,
                'chain_slug'       => $chainSlug,
                'group_id'         => $groupId,
                'operator_user_id' => $creatorId,
                'failure_code'     => self::FAIL_MARKER_REFUSED,
                'error_code'       => $residue,
                'cleanup_markers'  => $notes === [] ? 'none' : implode('|', $notes),
                'admin_email_sent' => $notifyOn ? 'possible' : 'no',
            ],
            'chain',
            $creatorId ?: null
        );

        if ($auditId === null) {
            Logger::error(
                '[bcc-trust] Hall compensation completed but its checked audit could not be written',
                ['chain_id' => $chainId, 'group_id' => $groupId]
            );

            return false;
        }

        return true;
    }

    /**
     * A refusal that never touched anything — bad input, no chain, no name.
     *
     * No audit row: nothing changed, and nothing was authorized to change.
     * Recording every rejected form post would bury the rows that matter.
     *
     * @return array{status: string, group_id: int, message: string,
     *               failure_code: string|null, owner_id: int,
     *               audit_degraded: bool}
     */
    private function refuse(string $message, string $code): array {
        return [
            'status'         => 'skipped',
            'group_id'       => 0,
            'message'        => $message,
            'failure_code'   => $code,
            'owner_id'       => 0,
            'audit_degraded' => false,
        ];
    }

    /**
     * An operation that was authorized and did not complete. Durably
     * recorded, with a bounded code.
     *
     * @return array{status: string, group_id: int, message: string,
     *               failure_code: string|null, owner_id: int,
     *               audit_degraded: bool}
     */
    private function fail(
        int $groupId,
        int $chainId,
        string $chainSlug,
        string $code,
        string $message,
        bool $auditDegraded = false
    ): array {
        $operator = get_current_user_id();

        Logger::warning('[bcc-trust] Hall creation refused', [
            'chain_id'     => $chainId,
            'chain_slug'   => $chainSlug,
            'group_id'     => $groupId,
            'failure_code' => $code,
            'operator'     => $operator,
        ]);

        $auditId = AuditLogger::logChecked(
            self::AUDIT_FAILED,
            $chainId,
            [
                'chain_id'         => $chainId,
                'chain_slug'       => $chainSlug,
                'group_id'         => $groupId,
                'operator_user_id' => $operator,
                'failure_code'     => $code,
            ],
            'chain',
            $operator ?: null
        );

        return [
            'status'         => 'error',
            'group_id'       => $groupId,
            'message'        => $message,
            'failure_code'   => $code,
            'owner_id'       => 0,
            'audit_degraded' => $auditDegraded || $auditId === null,
        ];
    }

    /**
     * Who currently holds `member_owner`, per PeepSo's own pointer.
     *
     * Used only by the repair path, where the previous attempt's creator is
     * not knowable from the request.
     */
    private function currentOwnerId(int $groupId): int {
        if (!class_exists('\\PeepSoGroup')) {
            return 0;
        }

        try {
            $group = new \PeepSoGroup($groupId);
            return (int) $group->get('owner_id');
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * The canonical Hall owner: the first administrator by id.
     *
     * Deterministic BY DESIGN — an official platform space must not change
     * hands based on which administrator pressed a button. Existence and
     * capability are both re-checked, so a deleted or demoted account fails
     * closed instead of silently handing the Hall to nobody.
     */
    private function resolveOwnerId(): int {
        $admins = get_users([
            'role'    => 'administrator',
            'number'  => 1,
            'orderby' => 'ID',
            'order'   => 'ASC',
            'fields'  => 'ID',
        ]);
        if (!is_array($admins) || $admins === []) {
            return 0;
        }

        $ownerId = (int) $admins[0];
        if ($ownerId <= 0) {
            return 0;
        }

        // Checked on the NAMED user, never `current_user_can()` — the owner
        // is not necessarily the caller.
        if (get_userdata($ownerId) === false || !user_can($ownerId, 'manage_options')) {
            return 0;
        }

        return $ownerId;
    }

    /**
     * WP's meta cache is not maintained for us across the raw meta writes
     * and the compensation delete. Cleared on every path that touches them.
     */
    private function invalidateGroupCaches(int $groupId): void {
        if ($groupId <= 0) {
            return;
        }

        wp_cache_delete($groupId, 'post_meta');
        clean_post_cache($groupId);
    }

    /**
     * Returns 0 on failure.
     *
     * `owner_id` is the CREATOR, deliberately. PeepSo's `create()` joins and
     * owns `get_current_user_id()` regardless of what is passed here, so
     * passing anyone else would leave `post_author` and the `member_owner`
     * row naming different people — and `transferOwnership()` refuses to act
     * on books that disagree. Ownership is moved afterwards, through the
     * supported writer, and proven.
     *
     * privacy = 0 (OPEN) — a Hall is joinable by anyone.
     */
    private function createHallGroup(int $creatorId, string $chainName): int {
        $title = $chainName . ' Hall';

        $data = [
            'owner_id'    => $creatorId,
            'name'        => $title,
            'description' => sprintf(
                'The %s union hall — an open floor for everyone on %s.',
                $chainName,
                $chainName
            ),
            'meta' => [
                'privacy'                     => 0, // PeepSoGroupPrivacy::PRIVACY_PUBLIC (OPEN)
                'is_joinable'                 => true,
                'is_invitable'                => false,
                'is_readonly'                 => false,
                'is_auto_accept_join_request' => true,
            ],
        ];

        try {
            /** @phpstan-ignore-next-line — PeepSo classes are runtime-only. */
            $group = new \PeepSoGroup(null, $data);
            /** @phpstan-ignore-next-line */
            $id = (int) $group->get('id');
            return $id > 0 ? $id : 0;
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] PeepSoGroup constructor threw (Hall)', [
                'chain' => $chainName,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
