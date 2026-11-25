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
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Support\HallTopicRules;
use BCC\Core\PeepSo\PeepSoAddPostGuard;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What a Hall is created for: a chain (`id` = the chain id, `slug` = the
 * chain slug) or a topic (`id` = 0, `slug` = the topic slug, which is also
 * the Hall's address, and `label` = the topic's display name). One creation
 * pipeline serves both; only the marker, its proof and the audit target
 * differ.
 *
 * @phpstan-type HallTarget array{kind: 'chain'|'topic', id: int, slug: string, label: string}
 */
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
     * Another request held this chain's provisioning lock for longer than
     * {@see LOCK_WAIT_SECONDS}. Nothing was created. 24 chars.
     */
    public const FAIL_LOCK_CONTENDED   = 'hall_provision_contended';

    /** Topic Halls: the input is not a valid topic Hall. Nothing was touched. */
    public const FAIL_TOPIC_INVALID    = 'topic_hall_invalid';

    /** Topic Halls: another group already has the topic's address. Nothing was created. */
    public const FAIL_SLUG_TAKEN       = 'hall_slug_taken';

    /** Topic Halls: the new group did not keep the topic's address, so it was rolled back. */
    public const FAIL_SLUG_REFUSED     = 'hall_slug_refused';

    /** A check that could not be read. Before the create nothing was made; after it, the Hall was rolled back. */
    public const FAIL_READ_UNVERIFIED  = 'hall_read_unverified';

    /**
     * Seconds a second request waits for the per-chain lock before giving up.
     *
     * Not zero, deliberately. A double-submit or a browser refresh is the
     * common case, and five seconds comfortably outlasts one group create --
     * so the loser WAITS, then runs the existence check inside the lock, sees
     * the Hall the winner just made, and gets `exists`. That is the honest
     * answer and the useful one. `busy` is reserved for the rare case where
     * the winner is genuinely still working after five seconds.
     */
    private const LOCK_WAIT_SECONDS    = 5;

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
     *         status ∈ {created, exists, incomplete, skipped, busy, error}.
     *         `busy` means another request held this chain's provisioning
     *         lock: nothing was created, and nothing is wrong.
     *         `incomplete` means the Hall EXISTS but its ownership could not
     *         be reconciled — it is marked and audited, and must never be
     *         reported to an operator as a successful provision.
     */
    public function provisionOne(int $chainId): array {
        if (!class_exists('\\PeepSoGroup')) {
            return $this->fail(0, self::chainTarget($chainId, ''), self::FAIL_PEEPSO_ABSENT, 'PeepSo Groups inactive.');
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
        $target    = self::chainTarget($chainId, $chainSlug);

        // ── THE CRITICAL SECTION, AND WHY IT STARTS HERE ────────────────
        // Everything above this line creates nothing, so it needs no lock --
        // and holding one across a refusal would let a bad chain id contend
        // with a real provision.
        //
        // Everything BELOW is check-then-create. `findHallForChain()` is a
        // bounded SELECT and the marker is a `wp_postmeta` write with NO
        // UNIQUE INDEX behind it, so the database cannot reject a second Hall
        // carrying the same `_bcc_chain_tag`. Two overlapping runs could both
        // read "no Hall for this chain" and both go on to create one, and a
        // duplicate Hall is not a transient glitch: it is a real public group
        // with its own id, members and content, and `findHallForChain()` then
        // resolves ambiguously for ever. See #123.
        //
        // Sequential retries were always safe — the second run reads the
        // marker and skips. It is the OVERLAP that had nothing guarding it,
        // and nothing proved otherwise: there was no lock, no lease and no
        // constraint anywhere on this path.
        //
        // ⚠ The lock is PER CHAIN, not global. Two administrators creating
        // Halls for two different chains must not block each other; only the
        // pair that could collide is serialized.
        $lock = self::lockName($chainId);
        if (!self::acquire($lock)) {
            return $this->contended($target);
        }

        // ⚠ EXACTLY ONE RELEASE FOR THE ONE ACQUIRE ABOVE. MySQL's GET_LOCK
        // is reentrant per session, so an unmatched pair leaks the lock for
        // the rest of the request. The `finally` covers every return path
        // below AND anything thrown out of the create — which matters here,
        // because `createHall()` reaches PeepSo, `wp_insert_post()` and a
        // `save_post` fan-out, any of which can throw.
        try {
            // ── Idempotency, checked BEFORE anything is created, and now
            //    INSIDE the lock, which is the whole point ────────────────
            $existing = HallRepository::findHallForChain($chainId);
            if ($existing !== null) {
                return $this->reconcileExistingHall((int) $existing, $target);
            }

            return $this->createHall(
                $target,
                $name . ' Hall',
                sprintf('The %s union hall — an open floor for everyone on %s.', $name, $name),
                null
            );
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lock);
        }
    }

    /**
     * Create a topic Hall: an official, open Hall about a subject rather than
     * a chain (DeFi, NFTs, …). The same pipeline as a chain Hall: a per-topic
     * lock, the idempotency check inside it, PeepSo's create, the marker and
     * PROOF that it stuck (compensated if not), the deterministic owner, and a
     * checked audit.
     *
     * The topic's slug is also the Hall's address (/communities/{slug}), set
     * at create and never changed. It must be free: a group already at that
     * address is a refusal, never a silently suffixed slug.
     *
     * Idempotent per topic: a topic that already has a Hall returns `exists`
     * (or repairs a Hall marked owner-incomplete) and creates nothing.
     *
     * Inputs are trimmed here; the REST layer has already sanitized them.
     *
     * @return array{status: string, group_id: int, message: string,
     *               failure_code: string|null, owner_id: int,
     *               audit_degraded: bool}
     *         status as provisionOne().
     */
    public function provisionTopic(string $name, string $topicSlug, string $topicLabel, string $description = ''): array {
        $name        = trim($name);
        $topicSlug   = trim($topicSlug);
        $topicLabel  = trim($topicLabel);
        $description = trim($description);

        if (!class_exists('\\PeepSoGroup')) {
            return $this->fail(0, self::topicTarget($topicSlug, $topicLabel), self::FAIL_PEEPSO_ABSENT, 'PeepSo Groups inactive.');
        }

        $invalid = self::invalidTopicInput($name, $topicSlug, $topicLabel, $description);
        if ($invalid !== null) {
            return $this->refuse($invalid, self::FAIL_TOPIC_INVALID);
        }

        $target = self::topicTarget($topicSlug, $topicLabel);

        // The same critical section as a chain's, keyed on the topic: the
        // existence check, the address check and the marker write must not
        // interleave with another create for the same topic.
        $lock = self::topicLockName($topicSlug);
        if (!self::acquire($lock)) {
            return $this->contended($target);
        }

        // ⚠ EXACTLY ONE RELEASE FOR THE ONE ACQUIRE ABOVE (see provisionOne).
        try {
            try {
                $existing = HallRepository::findHallForTopic($topicSlug);
                $taken    = $existing === null && HallRepository::groupSlugInUse($topicSlug);
            } catch (RepositoryReadFailure) {
                return $this->fail(0, $target, self::FAIL_READ_UNVERIFIED,
                    'The topic could not be checked just now; nothing was created.');
            }

            if ($existing !== null) {
                return $this->reconcileExistingHall($existing, $target);
            }
            if ($taken) {
                return $this->refuse('Another group already uses this address.', self::FAIL_SLUG_TAKEN);
            }

            return $this->createHall($target, $name, $description, $topicSlug);
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lock);
        }
    }

    /**
     * Why these topic Hall inputs are refused, or null when they are valid
     * (HallTopicRules, the same rules the admin edit applies).
     */
    private static function invalidTopicInput(string $name, string $slug, string $label, string $description): ?string {
        return HallTopicRules::nameError($name)
            ?? HallTopicRules::labelError($label)
            ?? HallTopicRules::descriptionError($description)
            ?? HallTopicRules::slugError($slug);
    }

    /**
     * Create the Hall for a target that has none, WITH THE TARGET'S LOCK
     * ALREADY HELD.
     *
     * Private and lock-dependent on purpose: the existence check and the
     * marker write have to sit inside one critical section, so there is no
     * entry point that performs the second without the first.
     *
     * @return array{status: string, group_id: int, message: string,
     *               failure_code: string|null, owner_id: int,
     *               audit_degraded: bool}
     *
     * @param HallTarget $target
     */
    private function createHall(array $target, string $title, string $description, ?string $slug): array {
        // ── The acting administrator ────────────────────────────────────
        // Creation is admin-initiated only, so there is always a current
        // user. Without one the two ownership books cannot be made to agree
        // and `transferOwnership()` would refuse — fail closed rather than
        // create a public group owned by user 0.
        $creatorId = get_current_user_id();
        if ($creatorId <= 0) {
            return $this->fail(0, $target, self::FAIL_NO_ACTING_ADMIN,
                'Hall creation requires a signed-in administrator.');
        }

        // ── The intended, deterministic owner ───────────────────────────
        $ownerId = $this->resolveOwnerId();
        if ($ownerId === 0) {
            return $this->fail(0, $target, self::FAIL_OWNER_UNRESOLVED,
                'No administrator account could be resolved to own the Hall.');
        }

        // ── Create. NOT transactional; compensated below. ───────────────
        $groupId = $this->createHallGroup($creatorId, $title, $description, $slug);
        if ($groupId === 0) {
            return $this->fail(0, $target, self::FAIL_CREATE_FAILED,
                'Hall creation failed; see the bcc-trust error log.');
        }

        // ── The marker, and PROOF that it stuck ─────────────────────────
        // This is the duplicate-preventing write: until the chain resolves
        // to this group, another attempt would create a second Hall. A
        // marker that did not take is therefore compensated, not marked.
        self::writeMarker($target, $groupId);
        $this->invalidateGroupCaches($groupId);

        $unproven = self::unprovenMarker($target, $groupId);
        if ($unproven !== null) {
            Logger::error('[bcc-trust] Hall marker did not survive its re-read; compensating', [
                'group_id' => $groupId,
                ...self::targetLog($target),
            ]);

            $auditOk = $this->compensate($groupId, $target, $creatorId, $unproven);

            return $this->fail(
                $groupId,
                $target,
                $unproven,
                match ($unproven) {
                    self::FAIL_SLUG_REFUSED    => 'The Hall could not take its address and was rolled back.',
                    self::FAIL_READ_UNVERIFIED => 'The new Hall could not be checked, so it was rolled back.',
                    default                    => 'The Hall could not be linked to its ' . $target['kind'] . ' and was rolled back.',
                },
                $auditOk === false
            );
        }

        // ── Ownership reconciliation is PART OF CREATION ────────────────
        $reconciled = $this->reconcileOwnership($groupId, $creatorId, $ownerId);

        if (!$reconciled['ok']) {
            return $this->recordIncomplete(
                $groupId,
                $target,
                $creatorId,
                $ownerId,
                (string) $reconciled['step']
            );
        }

        Logger::info('[bcc-trust] Hall created', [
            'group_id'   => $groupId,
            ...self::targetLog($target),
            'owner_id'   => $ownerId,
            'operator'   => $creatorId,
        ]);

        [$auditTargetId, $auditTargetType] = self::auditTarget($target, $groupId);
        $auditId = AuditLogger::logChecked(
            self::AUDIT_CREATED,
            $auditTargetId,
            [
                ...self::targetMeta($target),
                'group_id'         => $groupId,
                'operator_user_id' => $creatorId,
                'owner_user_id'    => $ownerId,
                'reconcile_step'   => (string) $reconciled['step'],
            ],
            $auditTargetType,
            $creatorId
        );

        // The Hall is correct and discoverable; only its record is missing.
        // Surfaced rather than swallowed, and NOT compensated — deleting a
        // correct public space over a lost audit row would be the worse
        // trade. The operator is told.
        if ($auditId === null) {
            Logger::error('[bcc-trust] Hall created but its checked audit could not be written', [
                'group_id' => $groupId,
                ...self::targetLog($target),
            ]);
        }

        // Fan-out hook for downstream subscribers. Stable signature:
        // (groupId, chainId, chainSlug), so it fires for a chain Hall only.
        if ($target['kind'] === 'chain') {
            do_action('bcc_hall_provisioned', $groupId, $target['id'], $target['slug']);
        }

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
     *
     * @param HallTarget $target
     */
    private function reconcileExistingHall(int $groupId, array $target): array {
        if (!HallRepository::isOwnerIncomplete($groupId)) {
            return [
                'status'         => 'exists',
                'group_id'       => $groupId,
                'message'        => 'This ' . $target['kind'] . ' already has a Hall.',
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

        [$auditTargetId, $auditTargetType] = self::auditTarget($target, $groupId);
        $auditId = AuditLogger::logChecked(
            self::AUDIT_CREATED,
            $auditTargetId,
            [
                ...self::targetMeta($target),
                'group_id'         => $groupId,
                'operator_user_id' => $creatorId,
                'owner_user_id'    => $ownerId,
                'reconcile_step'   => 'repaired',
            ],
            $auditTargetType,
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
     * resolve through `ChainRepository`. A topic Hall (F-597) arrives through
     * {@see \BCC\Trust\Core\REST\AdminHallsEndpoint}: `BearerAuth::requireAdmin`
     * (manage_options) as the permission_callback and again in the handler,
     * then provisionTopic()'s input checks; a topic Hall is created exactly as
     * open as a chain Hall, so facts 1-3 hold unchanged. No cron, hook,
     * shortcode, CLI command or bulk sweep can reach this method — that is
     * asserted by HallProvisionCronRetiredTest::testOnlyTheAdminActionCallsTheHallCreator.
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
     *
     * @param HallTarget $target
     */
    private function recordIncomplete(
        int $groupId,
        array $target,
        int $creatorId,
        int $ownerId,
        string $step
    ): array {
        HallRepository::markOwnerIncomplete($groupId);
        $this->invalidateGroupCaches($groupId);

        Logger::error('[bcc-trust] Hall created but ownership could not be reconciled', [
            'group_id'       => $groupId,
            ...self::targetLog($target),
            'operator'       => $creatorId,
            'intended_owner' => $ownerId,
            'reconcile_step' => $step,
        ]);

        [$auditTargetId, $auditTargetType] = self::auditTarget($target, $groupId);
        $auditId = AuditLogger::logChecked(
            self::AUDIT_INCOMPLETE,
            $auditTargetId,
            [
                ...self::targetMeta($target),
                'group_id'         => $groupId,
                'operator_user_id' => $creatorId,
                'owner_user_id'    => $ownerId,
                'reconcile_step'   => $step,
                'error_code'       => 'ownership_unreconciled',
            ],
            $auditTargetType,
            $creatorId
        );

        if ($target['kind'] === 'chain') {
            do_action('bcc_hall_provisioned', $groupId, $target['id'], $target['slug']);
        }

        return [
            'status'         => 'incomplete',
            'group_id'       => $groupId,
            'message'        => 'The Hall was created, but its ownership could not be set. '
                . ($target['kind'] === 'chain'
                    ? 'It is flagged for repair — run Create Chain Hall again to retry.'
                    : 'It is flagged for repair — create the same topic Hall again to retry.'),
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
     *
     * @param HallTarget $target
     */
    private function compensate(int $groupId, array $target, int $creatorId, string $failureCode): bool {
        $notes = [];

        $markers = $target['kind'] === 'chain'
            ? [HallRepository::META_KIND, HallRepository::META_CHAIN_TAG, HallRepository::META_OWNER_INCOMPLETE]
            : [HallRepository::META_KIND, HallRepository::META_TOPIC, HallRepository::META_TOPIC_LABEL, HallRepository::META_OWNER_INCOMPLETE];
        foreach ($markers as $key) {
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
        $residue = self::compensationResidue($target, $groupId);
        if ($residue !== 'clean') {
            Logger::error('[bcc-trust] Hall compensation left residue; a live Hall may remain', [
                'group_id' => $groupId,
                ...self::targetLog($target),
            ]);
        }

        $notifyOn = false;
        if (class_exists('\\PeepSo')) {
            $notifyOn = (bool) \PeepSo::get_option('groups_create_notify_admin', 0);
        }

        [$auditTargetId, $auditTargetType] = self::auditTarget($target, $groupId);
        $auditId = AuditLogger::logChecked(
            self::AUDIT_COMPENSATED,
            $auditTargetId,
            [
                ...self::targetMeta($target),
                'group_id'         => $groupId,
                'operator_user_id' => $creatorId,
                'failure_code'     => $failureCode,
                'error_code'       => $residue,
                'cleanup_markers'  => $notes === [] ? 'none' : implode('|', $notes),
                'admin_email_sent' => $notifyOn ? 'possible' : 'no',
            ],
            $auditTargetType,
            $creatorId ?: null
        );

        if ($auditId === null) {
            Logger::error(
                '[bcc-trust] Hall compensation completed but its checked audit could not be written',
                [...self::targetLog($target), 'group_id' => $groupId]
            );

            return false;
        }

        return true;
    }

    /** The advisory-lock name for one chain. Well under MySQL's 64 chars. */
    private static function lockName(int $chainId): string {
        return 'bcc_hall_provision:' . $chainId;
    }

    /** The advisory-lock name for one topic: a fixed-length hash, so any slug fits MySQL's 64 chars. */
    private static function topicLockName(string $topicSlug): string {
        return 'bcc_hall_provision_topic:' . substr(hash('sha256', $topicSlug), 0, 32);
    }

    /** @return HallTarget */
    private static function chainTarget(int $chainId, string $chainSlug): array {
        return ['kind' => 'chain', 'id' => $chainId, 'slug' => $chainSlug, 'label' => ''];
    }

    /** @return HallTarget */
    private static function topicTarget(string $topicSlug, string $topicLabel): array {
        return ['kind' => 'topic', 'id' => 0, 'slug' => $topicSlug, 'label' => $topicLabel];
    }

    /**
     * The audit meta naming the target. For a chain, exactly the keys this
     * service has always written (`chain_id`, `chain_slug`).
     *
     * @param HallTarget $target
     * @return array<string, int|string>
     */
    private static function targetMeta(array $target): array {
        return $target['kind'] === 'chain'
            ? ['chain_id' => $target['id'], 'chain_slug' => $target['slug']]
            : ['topic_slug' => $target['slug']];
    }

    /**
     * The log context naming the target (as the audit meta).
     *
     * @param HallTarget $target
     * @return array<string, int|string>
     */
    private static function targetLog(array $target): array {
        return self::targetMeta($target);
    }

    /**
     * The audit row's target: the chain for a chain Hall (as always), the
     * Hall itself for a topic Hall (null before it exists).
     *
     * @param HallTarget $target
     * @return array{0: int|null, 1: string}
     */
    private static function auditTarget(array $target, int $groupId): array {
        return $target['kind'] === 'chain'
            ? [$target['id'], 'chain']
            : [$groupId > 0 ? $groupId : null, 'hall'];
    }

    /** @param HallTarget $target */
    private static function writeMarker(array $target, int $groupId): void {
        if ($target['kind'] === 'chain') {
            HallRepository::writeHallMeta($groupId, $target['id']);
            return;
        }
        HallRepository::writeTopicHallMeta($groupId, $target['slug'], $target['label']);
    }

    /**
     * Null when the target now resolves to this group (and a topic Hall kept
     * its address); otherwise the failure code to compensate with.
     *
     * @param HallTarget $target
     */
    private static function unprovenMarker(array $target, int $groupId): ?string {
        if ($target['kind'] === 'chain') {
            return HallRepository::findHallForChain($target['id']) === $groupId ? null : self::FAIL_MARKER_REFUSED;
        }

        try {
            if (HallRepository::findHallForTopic($target['slug']) !== $groupId) {
                return self::FAIL_MARKER_REFUSED;
            }

            return HallRepository::groupSlug($groupId) === $target['slug'] ? null : self::FAIL_SLUG_REFUSED;
        } catch (RepositoryReadFailure) {
            return self::FAIL_READ_UNVERIFIED;
        }
    }

    /**
     * Whether a compensated Hall still resolves: `clean` when it does not.
     *
     * @param HallTarget $target
     */
    private static function compensationResidue(array $target, int $groupId): string {
        if ($target['kind'] === 'chain') {
            return HallRepository::findHallForChain($target['id']) === $groupId ? 'chain_still_resolves' : 'clean';
        }

        try {
            return HallRepository::findHallForTopic($target['slug']) === $groupId ? 'topic_still_resolves' : 'clean';
        } catch (RepositoryReadFailure) {
            return 'residue_unverified';
        }
    }

    /**
     * Claim the per-chain lock, or FAIL CLOSED.
     *
     * A false return means "do not create anything". That direction is the
     * only safe one: a failed acquisition means either a peer is already
     * provisioning this chain, or we cannot serialize at all — and creating
     * unlocked in either case is exactly the duplicate this exists to stop.
     *
     * ⚠ The two cases are logged differently because only one of them is a
     * defect. `AdvisoryLock::acquire()` already distinguishes a driver error
     * (GET_LOCK returned NULL) from a peer holding the lock, and logs the
     * former itself. A MISSING primitive it cannot report, so that is
     * reported here — otherwise an environment without bcc-core would make
     * every Hall creation read as "someone else is busy", for ever.
     */
    private static function acquire(string $lock): bool {
        if (!class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            Logger::error('[bcc-trust] Hall creation cannot be serialized: AdvisoryLock is unavailable', [
                'lock' => $lock,
            ]);

            return false;
        }

        return \BCC\Core\DB\AdvisoryLock::acquire($lock, self::LOCK_WAIT_SECONDS);
    }

    /**
     * Another request holds this chain's lock. NOTHING was created.
     *
     * Deliberately NOT audited. An audit row is for an authorized operation
     * that did not complete; a double-click that waited its turn and found
     * someone ahead of it is normal operation, and recording every one would
     * bury the rows that matter — the same reasoning {@see refuse()} gives.
     * It is logged, so an operator chasing a confusing notice can find it.
     *
     * @return array{status: string, group_id: int, message: string,
     *               failure_code: string|null, owner_id: int,
     *               audit_degraded: bool}
     *
     * @param HallTarget $target
     */
    private function contended(array $target): array {
        Logger::info('[bcc-trust] Hall creation skipped: another run holds this ' . $target['kind'], [
            ...self::targetLog($target),
            'operator'   => get_current_user_id(),
        ]);

        return [
            'status'         => 'busy',
            'group_id'       => 0,
            'message'        => 'Another Hall creation for this ' . $target['kind'] . ' is already running; nothing was created.',
            'failure_code'   => self::FAIL_LOCK_CONTENDED,
            'owner_id'       => 0,
            'audit_degraded' => false,
        ];
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
     *
     * @param HallTarget $target
     */
    private function fail(
        int $groupId,
        array $target,
        string $code,
        string $message,
        bool $auditDegraded = false
    ): array {
        $operator = get_current_user_id();

        Logger::warning('[bcc-trust] Hall creation refused', [
            ...self::targetLog($target),
            'group_id'     => $groupId,
            'failure_code' => $code,
            'operator'     => $operator,
        ]);

        [$auditTargetId, $auditTargetType] = self::auditTarget($target, $groupId);
        $auditId = AuditLogger::logChecked(
            self::AUDIT_FAILED,
            $auditTargetId,
            [
                ...self::targetMeta($target),
                'group_id'         => $groupId,
                'operator_user_id' => $operator,
                'failure_code'     => $code,
            ],
            $auditTargetType,
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
    private function createHallGroup(int $creatorId, string $title, string $description, ?string $slug): int {
        $data = [
            'owner_id'    => $creatorId,
            'name'        => $title,
            'description' => $description,
            'meta' => [
                'privacy'                     => 0, // PeepSoGroupPrivacy::PRIVACY_PUBLIC (OPEN)
                'is_joinable'                 => true,
                'is_invitable'                => false,
                'is_readonly'                 => false,
                'is_auto_accept_join_request' => true,
            ],
        ];
        // A topic Hall's address. PeepSo's create applies it in the same
        // pinned write (`update(['slug' => …])` maps to `post_name`), and
        // createHall() proves it stuck.
        if ($slug !== null) {
            $data['slug'] = $slug;
        }

        try {
            // ⚠⚠ F-361 — A PEEPSO MODEL WRITE, and this file already said so
            // at the top: `new PeepSoGroup(null, $data)` runs
            // `wp_insert_post()` (peepso-groups group.php:308). TWO arguments
            // CREATE; one argument only loads, which is why the
            // `new \PeepSoGroup($groupId)` reads elsewhere in this file are
            // left alone.
            //
            // The constructor therefore reaches the `wp_insert_post`
            // action, where third-party listeners run, so it must stay
            // inside `pinOnly`. Rationale: F-361 (private tracker).
            $group = PeepSoAddPostGuard::pinOnly(
                [], // provisioning drives nothing through the request
                /** @phpstan-ignore-next-line — PeepSo classes are runtime-only. */
                static fn () => new \PeepSoGroup(null, $data)
            );
            /** @phpstan-ignore-next-line */
            $id = (int) $group->get('id');
            return $id > 0 ? $id : 0;
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] PeepSoGroup constructor threw (Hall)', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
