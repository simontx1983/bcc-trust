<?php
/**
 * Holder-group provisioning — now driven by RECORDED INTENT, never by
 * verification alone.
 *
 * ── WHAT CHANGED IN PR 6, AND WHY ───────────────────────────────────────
 * `provisionAll()` used to enumerate `CollectionRepository::listVerified()`,
 * and that enumeration WAS the authorization decision: ticking Verify created
 * a live PeepSo community within ~24h with no second approval (issue #215).
 * It now enumerates `listRequested()` — collections an administrator
 * explicitly asked for. Verification is still REQUIRED and is re-checked at
 * the moment of provisioning; it is no longer SUFFICIENT.
 *
 * ── THE ORDERING FIX ────────────────────────────────────────────────────
 * The old sequence created the PeepSo group FIRST and then called
 * `writeGateConfig()`, which can refuse. On refusal the group was left
 * orphaned and ungated — and because `findGroupForCollection()` matches on
 * gate meta, the next run could not see it and created a DUPLICATE. Both
 * defects are closed by validating the identity BEFORE anything is created:
 * `writeGateConfig()` refuses on exactly one condition (the identity not
 * canonicalizing), so performing that same check up front makes its refusal
 * branch unreachable rather than merely recoverable.
 *
 * ── WHY CREATION IS NOT IN THE TRANSACTION ──────────────────────────────
 * It cannot be. `new PeepSoGroup(null, $data)` runs `wp_insert_post()` →
 * `member_join()` → ~20 `add_post_meta()` → `do_action('peepso_action_group_create')`.
 * The rows would roll back; the album DIRECTORY it mkdirs, the admin
 * notification e-mail a subscriber may send, the primed Redis object cache,
 * and the `save_post` / `transition_post_status` fan-out to arbitrary
 * subscribers would not. Wrapping it would buy database atomicity while
 * leaving all of that behind — a worse lie than not claiming atomicity.
 *
 * ── STAGED CREATION WAS INVESTIGATED FIRST, AND IS NOT SUPPORTED ────────
 * The obvious safer shape is "create unpublished, gate it, publish last".
 * PeepSo does not support it:
 *
 *   - `PeepSoGroup::create()` hardcodes `post_status => 'publish'` in its
 *     `wp_insert_post()` call and takes no status parameter
 *     (peepso-groups/classes/models/group.php:305-308).
 *   - The group is published, and `member_join()` + `member_modify()` run,
 *     INSIDE `create()` — before any `$data` the caller passed has been
 *     applied. So the publish transition and its `save_post` /
 *     `transition_post_status` fan-out have already fired by the time a
 *     status could be changed.
 *   - `update()` can write `post_status` via the `published` key, but the
 *     loader immediately coerces that property to a BOOLEAN
 *     (`group.php:261`), so passing a status string through it is abusing an
 *     internal whose semantics are not a status.
 *   - There is no filter, parameter or documented hook to defer the publish.
 *
 * Bypassing the model with a bare `wp_insert_post()` would skip
 * `member_owner` assignment and `peepso_action_group_create`, which is what
 * leaves orphaned groups — the original reason this service goes through the
 * constructor at all. So: create through the supported path, and COMPENSATE.
 *
 * @package BCC\Trust\Onchain\Services
 */

namespace BCC\Trust\Onchain\Services;

use BCC\Core\Log\Logger;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\GatedGroupRepository;
use BCC\Trust\Onchain\Support\NftCollectionIdentifier;
use BCC\Trust\Onchain\ValueObjects\ProvisioningFailureCode;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;

if (!defined('ABSPATH')) {
    exit;
}

final class GatedGroupProvisioningService {

    private const DEFAULT_MIN_BALANCE = 1;
    private const TITLE_PREFIX        = 'Holders: ';

    /** Audit actions. Both fit VARCHAR(50). */
    public const AUDIT_PROVISIONED  = 'admin_vc_community_provisioned';
    public const AUDIT_FAILED       = 'admin_vc_community_provision_failed';
    public const AUDIT_COMPENSATED  = 'admin_vc_provisioning_compensated';

    /**
     * Process collections with RECORDED INTENT, in bounded batches.
     *
     * ── WHY THIS IS NOT `provisionAll()` ANY MORE ───────────────────────
     * The name promised "provision everything", and what it did was
     * "provision everything verified" — which is exactly the coupling PR 6
     * removes. The name now says what the method does: it drains a queue of
     * requests. A collection that nobody requested is not in the queue and
     * cannot be reached from here at all.
     *
     * Cursored, so the queue genuinely drains. `listVerified()` ordered by id
     * with no cursor, which meant the daily sweep re-read the same first 200
     * rows forever and anything past id-rank 200 was never reached.
     *
     * @return array{created: int, skipped: int, failed: int, errors: list<string>}
     */
    public function processRequested(int $limit = 200): array {
        $created = 0;
        $skipped = 0;
        $failed  = 0;
        $errors  = [];

        if (!class_exists('\\PeepSoGroup')) {
            $errors[] = 'PeepSoGroup class not available; PeepSo Groups inactive?';
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'peepso_absent');
            return ['created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => $errors];
        }

        $limit     = max(1, min(200, $limit));
        $cursor    = 0;
        $processed = 0;
        $batchSize = min(50, $limit);

        // Bounded outer loop: the cursor advances monotonically and the
        // budget caps total work, so neither a stuck row nor a growing queue
        // can turn one cron tick into an unbounded run.
        while ($processed < $limit) {
            $rows = CollectionRepository::listRequested($cursor, min($batchSize, $limit - $processed));
            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $cursor = max($cursor, (int) $row->id);
                $processed++;

                $result = $this->provisionOne((int) $row->id);

                switch ($result['status']) {
                    case 'created':
                        $created++;
                        break;
                    case 'exists':
                        $skipped++;
                        break;
                    case 'failed':
                        $failed++;
                        // Bounded: the failure CODE, never the message.
                        $errors[] = sprintf(
                            'collection %d: %s',
                            (int) $row->id,
                            (string) ($result['failure_code'] ?? 'unknown')
                        );
                        break;
                    default:
                        $skipped++;
                }
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'failed' => $failed, 'errors' => $errors];
    }

    /**
     * Provision the holder community for ONE collection that has a recorded
     * request.
     *
     * ── THE INTENT GATE ─────────────────────────────────────────────────
     * The very first thing checked after loading the row is
     * `provisioning_state === 'requested'`. There is no parameter, flag or
     * caller that can skip it. That single guard is what makes "verified
     * alone never provisions" true for every entry point at once, rather
     * than a property each caller has to remember to preserve.
     *
     * @return array{status: string, group_id: int, message: string, failure_code?: string}
     *         status ∈ {created, exists, skipped, failed}
     */
    public function provisionOne(int $collectionId): array {
        if (!class_exists('\\PeepSoGroup')) {
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'peepso_absent');
            return $this->fail($collectionId, ProvisioningFailureCode::PEEPSO_ABSENT, 'PeepSo Groups inactive.', 0);
        }

        $row = CollectionRepository::readProvisioningRow($collectionId);
        if ($row === null) {
            return ['status' => 'skipped', 'group_id' => 0, 'message' => 'Collection not found.'];
        }

        // ── Gate 1: RECORDED INTENT ─────────────────────────────────────
        if ((string) $row->provisioning_state !== ProvisioningState::REQUESTED) {
            return [
                'status'   => 'skipped',
                'group_id' => 0,
                'message'  => 'No community has been requested for this collection.',
            ];
        }

        // ── Gate 2: verification is necessary, and re-checked NOW ───────
        // Verification may have been withdrawn between the request and this
        // attempt. A withdrawal normally resets the state to `none`, but the
        // two writes are not one transaction across every possible caller,
        // so the value is re-read here rather than assumed.
        if ((int) $row->is_verified !== 1) {
            return $this->fail($collectionId, ProvisioningFailureCode::NOT_VERIFIED,
                'Collection is not verified.', 0);
        }

        $chainId = (int) $row->chain_id;
        $name    = trim((string) ($row->collection_name ?? ''));

        $chain = \BCC\Trust\Onchain\Repositories\ChainRepository::getById($chainId);
        if ($chain === null) {
            return $this->fail($collectionId, ProvisioningFailureCode::IDENTITY_UNRESOLVED,
                'Collection has no resolvable chain.', 0);
        }
        $chainFamily = (string) ($chain->chain_type ?? '');

        if ($name === '') {
            return $this->fail($collectionId, ProvisioningFailureCode::AWAITING_METADATA,
                'Collection is still awaiting a name; cannot name the community.', 0);
        }

        // ── Gate 3: IDENTITY VALIDATED BEFORE ANYTHING IS CREATED ───────
        // This is the ordering fix. `writeGateConfig()` refuses on exactly
        // this condition, so checking it here makes its refusal branch
        // unreachable — no group can be created that the gate would then
        // decline to configure.
        $canonical = $row->canonical_identifier ?? null;
        if (!is_string($canonical) || $canonical === '') {
            return $this->fail($collectionId, ProvisioningFailureCode::IDENTITY_UNRESOLVED,
                'Collection has no resolved on-chain identity yet; a gate built on it could never be satisfied.', 0);
        }

        $identity = NftCollectionIdentifier::canonicalize($chainFamily, $canonical);
        if (!$identity->isAccepted()) {
            return $this->fail($collectionId, ProvisioningFailureCode::IDENTITY_UNRESOLVED,
                'The stored identity does not validate for this collection\'s chain.', 0);
        }
        $canonical = $identity->canonical();

        // ── Gate 4: idempotency ─────────────────────────────────────────
        // Only a PUBLISHED holder group counts (PR 6 tightened this lookup),
        // so a trashed or compensated group cannot masquerade as an existing
        // community and suppress a legitimate retry.
        $existing = GatedGroupRepository::findGroupForCollection($chainId, $canonical);
        if ($existing !== null) {
            // Backfill semantics: the community exists, so the row should say
            // so. Recording it is idempotent and closes the window where a
            // crash between creation and the state write left them disagreeing.
            $this->markProvisioned($collectionId, (int) $row->provisioning_requested_by ?: null,
                (string) $row->provisioning_requested_at);

            return ['status' => 'exists', 'group_id' => (int) $existing, 'message' => 'Community already exists.'];
        }

        // ── Gate 5: the OWNER is the administrator who asked ────────────
        // Not the lowest-id administrator. Ownership now follows the recorded
        // authorization, so there is no guess anywhere in this path, and the
        // person who holds PeepSo admin rights on the community is the person
        // who asked for it. If they no longer resolve, this fails closed
        // rather than silently substituting somebody else.
        $ownerId = $this->resolveRequesterAsOwner($row);
        if ($ownerId === 0) {
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'no_admin_owner');
            return $this->fail($collectionId, ProvisioningFailureCode::OWNER_UNRESOLVED,
                'The administrator who requested this community could not be resolved.', 0);
        }

        // ── Create. NOT transactional; compensated below. ───────────────
        $groupId = $this->createPeepSoGroup($ownerId, $name);
        if ($groupId === 0) {
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'group_create_failed');
            return $this->fail($collectionId, ProvisioningFailureCode::GROUP_CREATE_FAILED,
                'Community creation failed; see the bcc-trust error log.', 0);
        }

        // ── Finalize: gate meta + state + checked audit, atomically ─────
        try {
            TransactionManager::run(function () use (
                $groupId, $chainId, $chainFamily, $canonical, $collectionId, $ownerId, $row
            ) {
                $wrote = GatedGroupRepository::writeGateConfig(
                    $groupId, $chainId, $chainFamily, $canonical,
                    self::DEFAULT_MIN_BALANCE, $collectionId
                );

                if (!$wrote) {
                    // Unreachable by construction — Gate 3 already validated
                    // the same identity through the same service. Kept as a
                    // hard stop rather than an assumption: if it ever fires,
                    // something changed underneath and a rollback is right.
                    throw new \RuntimeException('gate config refused after identity validation');
                }

                $moved = CollectionRepository::setProvisioningState(
                    $collectionId,
                    ProvisioningState::REQUESTED,
                    ProvisioningState::PROVISIONED,
                    (int) $row->provisioning_requested_by ?: null,
                    $row->provisioning_requested_at !== null ? (string) $row->provisioning_requested_at : null,
                    null
                );

                if (!$moved) {
                    throw new \RuntimeException('provisioning state did not move from requested');
                }

                // Checked audit: a lost $meta must fail the operation, not
                // silently record less. `log()` would have written the row
                // with meta = NULL and returned void.
                $auditId = AuditLogger::logChecked(
                    self::AUDIT_PROVISIONED,
                    $collectionId,
                    [
                        'collection_id'    => $collectionId,
                        'chain_id'         => $chainId,
                        'chain_family'     => $chainFamily,
                        'group_id'         => $groupId,
                        'operator_user_id' => $ownerId,
                        'previous_state'   => ProvisioningState::REQUESTED,
                        'new_state'        => ProvisioningState::PROVISIONED,
                    ],
                    'collection',
                    $ownerId
                );

                if ($auditId === null) {
                    throw new \RuntimeException('checked audit write failed; rolling back the provisioning');
                }

                // ── Postconditions, re-read before commit ───────────────
                $config = GatedGroupRepository::getGateConfig($groupId);
                if ($config === null
                    || $config->collectionId !== $collectionId
                    || $config->chainId !== $chainId
                    || strcmp($config->contractAddress, $canonical) !== 0
                ) {
                    throw new \RuntimeException('gate config did not survive its postcondition re-read');
                }

                $resolved = GatedGroupRepository::findGroupForCollection($chainId, $canonical);
                if ($resolved !== $groupId) {
                    throw new \RuntimeException('the collection does not resolve to the group just created');
                }

                $after = CollectionRepository::readProvisioningRow($collectionId);
                if ($after === null || (string) $after->provisioning_state !== ProvisioningState::PROVISIONED) {
                    throw new \RuntimeException('provisioning state did not survive its postcondition re-read');
                }

                // A result object, never bare `false`: TransactionManager
                // treats `false` as its legacy roll-back sentinel and converts
                // it into a fixed exception the caller cannot interpret.
                return ['ok' => true, 'audit_id' => $auditId];
            });
        } catch (\Throwable $e) {
            // The transaction rolled back the gate meta and the state write.
            // The GROUP is still there, and only compensation can remove it.
            Logger::error('[bcc-trust] provisioning finalization failed; compensating', [
                'collection_id' => $collectionId,
                'group_id'      => $groupId,
                'error'         => $e->getMessage(),
            ]);

            $this->invalidateGroupCaches($groupId);
            $this->compensate($groupId, $collectionId, $ownerId, $chainId);

            return $this->fail($collectionId, ProvisioningFailureCode::GATE_WRITE_REFUSED,
                'Community creation was rolled back; the gate could not be configured.', 0);
        }

        // Raw locked/meta writes do not maintain WP's meta cache for us.
        $this->invalidateGroupCaches($groupId);

        Logger::info('[bcc-trust] Provisioned holder group', [
            'group_id'      => $groupId,
            'collection_id' => $collectionId,
            'chain_id'      => $chainId,
        ]);

        do_action('bcc_gated_group_provisioned', $groupId, $collectionId, $chainId, $canonical);

        return ['status' => 'created', 'group_id' => $groupId, 'message' => 'Community created.'];
    }

    /**
     * Remove a community that was created but could not be gated.
     *
     * ── WHY THIS IS NOT JUST `wp_trash_post()` ──────────────────────────
     * Trashing leaves the post, its meta, the membership row and the album
     * directory in place, and PeepSo's own `findGroupForCollection()` used to
     * match a trashed group — so "trashed" was not provably "gone". This
     * mirrors PeepSo's OWN supported delete cascade
     * (peepso-groups/classes/api/groupajax.php:169-185 and
     * classes/admin/groupslisttable.php:257-270): remove the uploads
     * directory, hard-delete the post, then clean the orphan the cascade
     * leaves behind.
     *
     * ── THE ONE THING THAT CANNOT BE UNDONE ─────────────────────────────
     * `peepso_action_group_create` has already fired. If the site has
     * `groups_create_notify_admin` enabled, an administrator notification
     * e-mail was sent and cannot be recalled. That is reported, not hidden:
     * the compensation audit records whether the notify option was on, so an
     * operator reading the trail knows an e-mail exists for a community that
     * does not.
     */
    private function compensate(int $groupId, int $collectionId, int $ownerId, int $chainId): void
    {
        $notes = [];

        // 1. Gate meta. The transaction should already have rolled these back;
        //    deleting them explicitly means compensation does not DEPEND on
        //    that having worked, and a partial gate cannot survive.
        foreach ([
            GatedGroupRepository::META_KIND,
            GatedGroupRepository::META_CHAIN_ID,
            GatedGroupRepository::META_CONTRACT,
            GatedGroupRepository::META_MIN_BAL,
            GatedGroupRepository::META_COLLECTION,
        ] as $key) {
            delete_post_meta($groupId, $key);
        }

        // 2. The owner membership, through the supported per-member model.
        //    PeepSo's own delete cascade does NOT do this — it relies on a
        //    later `deleteMembersForDeletedGroups()` maintenance sweep, which
        //    is an unbounded global DELETE we must not trigger for one group.
        if (class_exists('\\PeepSoGroupUser')) {
            try {
                $member = new \PeepSoGroupUser($groupId, $ownerId);
                $member->member_leave();
            } catch (\Throwable $e) {
                $notes[] = 'member_leave_threw';
            }
        }

        // 3. The uploads directory the album subscriber mkdir'd.
        try {
            if (class_exists('\\PeepSoGroup')) {
                $group = new \PeepSoGroup($groupId);
                $dir = (string) $group->get_image_dir();
                if ($dir !== '' && is_dir($dir)) {
                    // Guarded: the two admin includes are only pulled in when
                    // the class is genuinely absent. `require_once` on a
                    // hard-coded ABSPATH path is also the one line here that
                    // cannot run outside a full WordPress load, so skipping it
                    // when the class already exists keeps the compensation
                    // path exercisable.
                    if (!class_exists('\\WP_Filesystem_Direct')) {
                        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
                        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
                    }
                    $filesystem = new \WP_Filesystem_Direct([]);
                    $filesystem->rmdir($dir, true);
                }
            }
        } catch (\Throwable $e) {
            $notes[] = 'image_dir_cleanup_threw';
        }

        // 4. The post itself, hard-deleted exactly as PeepSo's own path does.
        $deleted = wp_delete_post($groupId, true);
        if (!$deleted) {
            $notes[] = 'wp_delete_post_returned_falsy';
        }

        $this->invalidateGroupCaches($groupId);

        // 5. PROVE it. Compensation that cannot demonstrate the group is gone
        //    is not compensation, it is a hope.
        $residue = GatedGroupRepository::compensationResidue($groupId);

        if ($residue !== []) {
            Logger::error('[bcc-trust] compensation left residue; a live ungated community may remain', [
                'group_id'      => $groupId,
                'collection_id' => $collectionId,
                'residue'       => $residue,
            ]);
        }

        $notifyOn = false;
        if (class_exists('\\PeepSo')) {
            $notifyOn = (bool) \PeepSo::get_option('groups_create_notify_admin', 0);
        }

        AuditLogger::log(
            self::AUDIT_COMPENSATED,
            $collectionId,
            [
                'collection_id'      => $collectionId,
                'chain_id'           => $chainId,
                'group_id'           => $groupId,
                'operator_user_id'   => $ownerId,
                'failure_code'       => ProvisioningFailureCode::GATE_WRITE_REFUSED,
                // Bounded machine-readable residue markers, never prose.
                'error_code'         => $residue === [] ? 'clean' : implode('|', $residue),
                'notes'              => $notes === [] ? 'none' : implode('|', $notes),
                'admin_email_sent'   => $notifyOn ? 'possible' : 'no',
            ],
            'collection',
            $ownerId
        );
    }


    /**
     * Record a failure durably, as a BOUNDED CODE.
     *
     * The row keeps its requester and its request timestamp, so the failure
     * stays attributable and a retry knows who authorized it.
     *
     * @return array{status: string, group_id: int, message: string, failure_code: string}
     */
    private function fail(int $collectionId, string $code, string $message, int $groupId): array
    {
        $row = CollectionRepository::readProvisioningRow($collectionId);

        if ($row !== null && (string) $row->provisioning_state === ProvisioningState::REQUESTED) {
            $moved = CollectionRepository::setProvisioningState(
                $collectionId,
                ProvisioningState::REQUESTED,
                ProvisioningState::FAILED,
                (int) $row->provisioning_requested_by ?: null,
                $row->provisioning_requested_at !== null ? (string) $row->provisioning_requested_at : null,
                $code
            );

            if ($moved) {
                AuditLogger::log(
                    self::AUDIT_FAILED,
                    $collectionId,
                    [
                        'collection_id'    => $collectionId,
                        'chain_id'         => (int) $row->chain_id,
                        'group_id'         => $groupId,
                        'operator_user_id' => (int) $row->provisioning_requested_by,
                        'previous_state'   => ProvisioningState::REQUESTED,
                        'new_state'        => ProvisioningState::FAILED,
                        'failure_code'     => $code,
                    ],
                    'collection',
                    (int) $row->provisioning_requested_by ?: null
                );
            }
        }

        // The MESSAGE is returned for the operator's screen and logged; it is
        // never persisted. Only the code is durable.
        Logger::warning('[bcc-trust] provisioning refused', [
            'collection_id' => $collectionId,
            'failure_code'  => $code,
        ]);

        return [
            'status'       => 'failed',
            'group_id'     => $groupId,
            'message'      => $message,
            'failure_code' => $code,
        ];
    }

    /**
     * Idempotently record that a community exists for this collection.
     *
     * Used when a community is discovered during a retry. Preserves the
     * requester so attribution survives.
     */
    private function markProvisioned(int $collectionId, ?int $requestedBy, string $requestedAt): void
    {
        CollectionRepository::setProvisioningState(
            $collectionId,
            ProvisioningState::REQUESTED,
            ProvisioningState::PROVISIONED,
            $requestedBy,
            $requestedAt !== '' ? $requestedAt : null,
            null
        );
    }

    /**
     * The community's owner is the administrator who asked for it.
     *
     * ── WHY NOT THE LOWEST-ID ADMINISTRATOR ─────────────────────────────
     * That was the old `resolveOwnerId()`: `get_users(role=administrator,
     * number=1, orderby=ID ASC)`. It is a guess, and PR 6 removes guesses
     * from this path entirely. The requester is recorded on the row, is the
     * person who exercised the capability, and is therefore the honest owner.
     * If they no longer exist or no longer hold `manage_options`, this
     * returns 0 and provisioning fails closed rather than handing the
     * community to somebody who never asked for it.
     */
    private function resolveRequesterAsOwner(object $row): int
    {
        $requestedBy = (int) ($row->provisioning_requested_by ?? 0);
        if ($requestedBy <= 0) {
            return 0;
        }

        if (get_userdata($requestedBy) === false) {
            return 0;
        }

        // Checked on the NAMED user, never `current_user_can()` — the cron
        // has no current user, and an implicit actor must not satisfy this.
        if (!user_can($requestedBy, 'manage_options')) {
            return 0;
        }

        return $requestedBy;
    }

    /**
     * WP's meta cache is not maintained across a rolled-back transaction, and
     * `wp_insert_post()` primes a post cache entry that compensation
     * invalidates. Both are cleared on every exit path.
     */
    private function invalidateGroupCaches(int $groupId): void
    {
        if ($groupId <= 0) {
            return;
        }

        wp_cache_delete($groupId, 'post_meta');
        clean_post_cache($groupId);
    }

    /**
     * Returns 0 on failure. Going through PeepSoGroup's constructor
     * ensures member_owner assignment + peepso_action_group_create.
     */
    private function createPeepSoGroup(int $ownerId, string $collectionName): int {
        $title = self::TITLE_PREFIX . $collectionName;

        $data = [
            'owner_id'    => $ownerId,
            'name'        => $title,
            'description' => sprintf(
                'On-chain verified holders of %s. Auto-managed.',
                $collectionName
            ),
            'meta' => [
                'privacy'                     => 1, // PeepSoGroupPrivacy::PRIVACY_CLOSED
                'is_joinable'                 => true,
                'is_invitable'                => false,
                'is_readonly'                 => false,
                'is_auto_accept_join_request' => false,
            ],
        ];

        try {
            /** @phpstan-ignore-next-line — PeepSo classes are runtime-only. */
            $group = new \PeepSoGroup(null, $data);
            /** @phpstan-ignore-next-line */
            $id = (int) $group->get('id');
            return $id > 0 ? $id : 0;
        } catch (\Throwable $e) {
            Logger::error('[bcc-trust] PeepSoGroup constructor threw', [
                'collection' => $collectionName,
                'error'      => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
