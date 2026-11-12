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
use BCC\Core\Repositories\PeepSoGroupRepository;
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

/**
 * @phpstan-import-type CollectionProvisioningRow from CollectionRepository
 */
final class GatedGroupProvisioningService {

    private const DEFAULT_MIN_BALANCE = 1;
    private const TITLE_PREFIX        = 'Holders: ';

    /** Audit actions. All three fit VARCHAR(50). */
    public const AUDIT_PROVISIONED  = 'admin_vc_community_provisioned';
    public const AUDIT_FAILED       = 'admin_vc_community_provision_failed';
    public const AUDIT_COMPENSATED  = 'admin_vc_provisioning_compensated';

    /**
     * Did the durable record of this outcome actually commit?
     *
     * ── WHY THIS IS THREE VALUES AND NOT A BOOL ─────────────────────────
     * "The failure is on the record" and "the failure record could not be
     * committed" call for different operator responses — the second is a
     * data-loss window, the first is an ordinary refusal. A bool collapses
     * them, and a bool also cannot say "there was nothing to record", which
     * is the honest answer when the row was never `requested` in the first
     * place (PeepSo absent, collection gone). Reporting that third case as
     * "could not commit" would invent an incident.
     */
    public const RECORD_COMMITTED      = 'committed';
    public const RECORD_UNCOMMITTED    = 'uncommitted';
    public const RECORD_NOT_APPLICABLE = 'not_applicable';

    /**
     * Bounded operational errors — infrastructure faults, not collection
     * outcomes.
     *
     * ── WHY THESE ARE NOT ProvisioningFailureCode VALUES ────────────────
     * A `ProvisioningFailureCode` is a durable statement ABOUT A COLLECTION:
     * it goes in the row and tells an operator what is wrong with that
     * collection. "The database did not answer" is not a fact about any
     * collection — attributing it to one would send someone to inspect a row
     * that is perfectly fine, and would mark it `failed` for something it did
     * not do. These stay in the run report and never touch a row.
     */
    public const ERROR_QUEUE_UNREADABLE = 'provisioning_queue_unreadable';
    public const ERROR_ROW_UNREADABLE   = 'provisioning_row_unreadable';

    /**
     * Another run held this collection's provisioning lock for longer than
     * {@see LOCK_WAIT_SECONDS}. Nothing was created, and nothing is wrong.
     */
    public const FAIL_LOCK_CONTENDED = 'gated_group_provision_contended';

    /**
     * Seconds a second run waits for the per-collection lock before giving up.
     *
     * Not zero, deliberately, and the same five as the Hall lock. The common
     * case is a double submit or the daily cron overlapping an admin press,
     * and five seconds comfortably outlasts one group create — so the loser
     * WAITS, then runs the existence check INSIDE the lock, sees the community
     * the winner just made, and gets `exists`. That is the honest answer and
     * the useful one. `busy` is for the rare case where the winner is still
     * working after five seconds.
     */
    private const LOCK_WAIT_SECONDS = 5;

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
     * @return array{created: int, skipped: int, failed: int, contended: int,
     *               errors: list<string>, available: bool}
     *         `available` is false when a provisioning read FAILED. It is
     *         the difference between "the queue is empty" and "the queue
     *         could not be asked", and the caller must not report the
     *         second as a clean run.
     *
     *         ⚠ `contended` is its own counter and NOT folded into `skipped`.
     *         `skipped` means "nothing to do for this row" — already
     *         provisioned, never requested, gone. A contended row is the
     *         opposite: there IS work, somebody else is doing it right now,
     *         and the row stays `requested` so the next tick picks it up.
     *         Counting it as skipped would be the same laundering the
     *         `unavailable` arm below exists to prevent, and it would make a
     *         sweep that provisioned nothing at all read as a clean pass.
     */
    public function processRequested(int $limit = 200): array {
        $created   = 0;
        $skipped   = 0;
        $failed    = 0;
        $contended = 0;
        $errors    = [];

        if (!class_exists('\\PeepSoGroup')) {
            $errors[] = 'PeepSoGroup class not available; PeepSo Groups inactive?';
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'peepso_absent');
            return [
                'created'   => 0,
                'skipped'   => 0,
                'failed'    => 0,
                'contended' => 0,
                'errors'    => $errors,
                'available' => true,
            ];
        }

        $limit     = max(1, min(200, $limit));
        $cursor    = 0;
        $processed = 0;
        $batchSize = min(50, $limit);

        // Bounded outer loop: the cursor advances monotonically and the
        // budget caps total work, so neither a stuck row nor a growing queue
        // can turn one cron tick into an unbounded run.
        while ($processed < $limit) {
            $queue = CollectionRepository::listRequested($cursor, min($batchSize, $limit - $processed));

            // ⚠ A queue that could not be READ is not an empty queue.
            //
            // Breaking out here as if it were would report a clean zero for a
            // sweep whose SELECT never executed. The run stops, says so, and
            // makes no PeepSo call on the strength of an answer it never got.
            if (!$queue['available']) {
                $errors[] = self::ERROR_QUEUE_UNREADABLE;

                // The repository logs the database's own complaint. This logs
                // the OPERATIONAL consequence — a sweep abandoned part-way,
                // with the work already done named so an operator can tell a
                // partial run from a run that never started. Short-retention
                // application log only; the durable record stays bounded, and
                // no collection is named because none is implicated.
                Logger::error('[bcc-trust] provisioning sweep ABANDONED — the request queue could not be read', [
                    'error_code' => self::ERROR_QUEUE_UNREADABLE,
                    'cursor'     => $cursor,
                    'processed'  => $processed,
                    'created'    => $created,
                ]);

                return [
                    'created'   => $created,
                    'skipped'   => $skipped,
                    'failed'    => $failed,
                    'contended' => $contended,
                    'errors'    => $errors,
                    'available' => false,
                ];
            }

            $rows = $queue['rows'];
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
                        // Bounded: the failure CODE, never the message. The
                        // record state rides along because "refused, and on
                        // the record" and "refused, and the record did not
                        // commit" are different operational situations.
                        $errors[] = sprintf(
                            'collection %d: %s (%s)',
                            (int) $row->id,
                            (string) ($result['failure_code'] ?? 'unknown'),
                            (string) ($result['failure_record'] ?? self::RECORD_NOT_APPLICABLE)
                        );
                        break;
                    case 'unconfirmed':
                        // A community exists but reconciling the row could not
                        // be committed. Counted with the failures so the sweep
                        // never reports it as clean, and the row stays
                        // `requested` so the next tick retries it.
                        $failed++;
                        $errors[] = sprintf(
                            'collection %d: reconciliation %s',
                            (int) $row->id,
                            self::RECORD_UNCOMMITTED
                        );
                        break;
                    case 'unavailable':
                        // ⚠ NOT created, NOT skipped, NOT failed — and the
                        // sweep stops here.
                        //
                        // `default: $skipped++` used to swallow this, which
                        // reproduced the exact defect the producer side was
                        // fixed for, one layer out: a row the database
                        // declined to hand back was counted as "nothing to do
                        // for this one" and the run went on to report
                        // `available => true`. An honest repository is worth
                        // nothing if its caller launders the answer.
                        //
                        // Continuing would be worse than stopping. The fault
                        // is almost certainly not specific to this row, so
                        // the next iterations would keep calling PeepSo on
                        // rows whose state was equally unreadable — and the
                        // cursor would advance past all of them, so the queue
                        // would quietly lose them until someone re-requested.
                        //
                        // Counts for work ALREADY completed in this run are
                        // preserved and returned: those communities really
                        // were created, and discarding that would misreport a
                        // partial run as a run that never started.
                        $errors[] = self::ERROR_ROW_UNREADABLE;

                        // The collection is deliberately NOT named. Nothing
                        // is known to be wrong with it — the database did not
                        // answer — and putting an id here would send an
                        // operator to inspect a row that is fine. The
                        // repository has already logged the id it could not
                        // read, which is where a diagnostic belongs.
                        Logger::error('[bcc-trust] provisioning sweep ABANDONED — a queued row could not be read', [
                            'error_code' => self::ERROR_ROW_UNREADABLE,
                            'processed'  => $processed,
                            'created'    => $created,
                            'skipped'    => $skipped,
                            'failed'     => $failed,
                            'contended'  => $contended,
                        ]);

                        return [
                            'created'   => $created,
                            'skipped'   => $skipped,
                            'failed'    => $failed,
                            'contended' => $contended,
                            'errors'    => $errors,
                            'available' => false,
                        ];

                    // ⚠ A contended row is NOT skipped, NOT failed and NOT
                    // created, and it gets its own arm for the same reason the
                    // `unavailable` arm above has one: `default: $skipped++`
                    // would launder it into "nothing to do for this one".
                    //
                    // It is not an error either, so it does NOT go into
                    // `$errors` — a peer provisioning the same collection is
                    // normal, and the admin sweep must not report it as a
                    // problem. The row stays `requested`, so the next tick
                    // retries it and the work is not lost.
                    //
                    // The sweep CONTINUES. Contention is per collection, so
                    // one busy row says nothing about the next one. See #368.
                    case 'busy':
                        $contended++;
                        break;

                    default:
                        $skipped++;
                }
            }
        }

        return [
            'created'   => $created,
            'skipped'   => $skipped,
            'failed'    => $failed,
            'contended' => $contended,
            'errors'    => $errors,
            'available' => true,
        ];
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
     * @return array{status: string, group_id: int, message: string,
     *               error?: string, failure_code?: string|null,
     *               failure_record: string,
     *               failure_recorded: bool, audit_degraded: bool}
     *         status ∈ {created, exists, skipped, failed, unconfirmed,
     *         unavailable, busy}. `unconfirmed` means a community exists but
     *         recording that could not be committed — nothing was created or
     *         removed. `unavailable` means the database did not answer: we
     *         learned NOTHING about this collection, and said so. `busy`
     *         means another run held this collection's provisioning lock:
     *         nothing was created, nothing is wrong, the row stays
     *         `requested`, and the next attempt will pick it up (#368).
     */
    public function provisionOne(int $collectionId): array {
        if (!class_exists('\\PeepSoGroup')) {
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'peepso_absent');
            return $this->fail($collectionId, ProvisioningFailureCode::PEEPSO_ABSENT, 'PeepSo Groups inactive.', 0);
        }

        $read = CollectionRepository::readProvisioningRow($collectionId);

        // ⚠ AN UNREADABLE ROW IS NOT AN ABSENT COLLECTION.
        //
        // Both used to arrive here as `null`. Reporting the fault as
        // `skipped`/"Collection not found" tells an operator the row is gone
        // when it is sitting there perfectly intact, and lets a sweep walk
        // past every row a flaky database declined to hand back — silently,
        // and counted as a clean pass. Nothing below this point may run: no
        // PeepSo call, no state write, no audit row, because every one of
        // them would be a conclusion drawn from an answer we never got.
        if (!$read['available']) {
            return [
                'status'           => 'unavailable',
                'group_id'         => 0,
                'message'          => 'The provisioning row could not be read; no conclusion was drawn.',
                'error'            => self::ERROR_ROW_UNREADABLE,
                'failure_code'     => null,
                'failure_record'   => self::RECORD_NOT_APPLICABLE,
                'failure_recorded' => false,
                'audit_degraded'   => false,
            ];
        }

        $row = $read['row'];
        if ($row === null) {
            return ['status' => 'skipped', 'group_id' => 0, 'message' => 'Collection not found.',
                'failure_record' => self::RECORD_NOT_APPLICABLE,
                'failure_recorded' => false, 'audit_degraded' => false];
        }

        // ── Gate 1: RECORDED INTENT ─────────────────────────────────────
        if ((string) $row->provisioning_state !== ProvisioningState::REQUESTED) {
            return [
                'status'           => 'skipped',
                'group_id'         => 0,
                'message'          => 'No community has been requested for this collection.',
                'failure_record'   => self::RECORD_NOT_APPLICABLE,
                'failure_recorded' => false,
                'audit_degraded'   => false,
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

        // ── THE CRITICAL SECTION, AND WHY IT STARTS HERE ────────────────
        // Every gate above creates nothing, so none of them needs the lock --
        // and holding one across a refusal would let a stream of malformed or
        // unverified rows contend with a real provision.
        //
        // Everything BELOW is check-then-create. `findGroupForCollection()` is
        // a bounded SELECT and the gate config is five plain `wp_postmeta`
        // rows with NO UNIQUE INDEX behind them, so the database cannot reject
        // a second community for one collection. Two overlapping runs could
        // both read "no community for this collection" and both go on to call
        // `createPeepSoGroup()`. See #368.
        //
        // ⚠ The realistic outcome was a TRANSIENT duplicate, not a permanent
        // one: `setProvisioningState()` is a genuine compare-and-swap, so the
        // loser's transaction throws and `compensate()` tears its group down.
        // That is why this is a lock and not an incident. It is still worth
        // locking, because `compensate()` returns bool -- if it fails, the
        // stray group PERSISTS with its gate meta already deleted -- and
        // because preventing the race is testable while relying on the
        // loser's cleanup routes the correctness argument through the error
        // handler.
        //
        // ⚠ PER COLLECTION, not global. The daily cron drains a queue one row
        // at a time through this method, so a global lock would serialize the
        // whole sweep behind a single slow create. Only the pair that could
        // collide is serialized.
        //
        // ⚠⚠ NO DEADLOCK WITH compensate(). Checked rather than assumed: there
        // is no lock primitive anywhere else in this class, `TransactionManager`
        // takes none, and `fail()`, `reconcileExistingCommunity()` and
        // `compensate()` are all reached only from inside this critical
        // section. So the advisory lock is always acquired BEFORE any DB
        // transaction, by every caller, and nothing inside waits on a lock the
        // holder holds. Compensation running inside the lock is a bonus: a
        // peer cannot start creating while a duplicate is being torn down.
        $lock = self::lockName($collectionId);
        if (!self::acquire($lock)) {
            return $this->contended($collectionId, $chainId);
        }

        // ⚠ EXACTLY ONE RELEASE FOR THE ONE ACQUIRE ABOVE, and OUTSIDE the
        // transaction. MySQL's GET_LOCK is reentrant per session, so an
        // unmatched pair leaks the lock for the rest of the request; and
        // RELEASE_LOCK is NOT transactional, so releasing inside the
        // `TransactionManager::run()` callback would drop it early and a
        // rollback would not restore it. The `finally` covers every return
        // path below and anything thrown out of the create -- which matters,
        // because that path reaches PeepSo, `wp_insert_post()` and an uploads
        // mkdir, any of which can throw.
        try {
            return $this->provisionOneLocked(
                $collectionId,
                $chainId,
                $chainFamily,
                $canonical,
                $name,
                $row
            );
        } finally {
            \BCC\Core\DB\AdvisoryLock::release($lock);
        }
    }

    /**
     * Provision one collection's community WITH THE PER-COLLECTION LOCK
     * ALREADY HELD.
     *
     * Private and lock-dependent on purpose: the existence check and the
     * create have to sit inside one critical section, so there is no entry
     * point that performs the second without the first.
     *
     * ⚠ `$row` carries the IMPORTED SHAPE, not a bare `object`. Declaring it
     * loosely compiles and passes every test, and PHPStan then rejects three
     * things at once: handing it to `reconcileExistingCommunity()`, which
     * declares the shape, and the two `provisioning_requested_*` reads below.
     * The shape is named once in `CollectionRepository` and imported at the
     * top of this class; extracting a method must carry it through rather
     * than widening it.
     *
     * @param CollectionProvisioningRow $row the provisioning row read and
     *                                       gated by {@see provisionOne()}
     *
     * @return array{status: string, group_id: int, message: string,
     *               error?: string, failure_code?: string|null,
     *               failure_record: string,
     *               failure_recorded: bool, audit_degraded: bool}
     */
    private function provisionOneLocked(
        int $collectionId,
        int $chainId,
        string $chainFamily,
        string $canonical,
        string $name,
        object $row
    ): array {
        // ── Gate 4: idempotency ─────────────────────────────────────────
        // Only a PUBLISHED holder group counts (PR 6 tightened this lookup),
        // so a trashed or compensated group cannot masquerade as an existing
        // community and suppress a legitimate retry.
        $existing = GatedGroupRepository::findGroupForCollection($chainId, $canonical);
        if ($existing !== null) {
            return $this->reconcileExistingCommunity(
                $collectionId,
                (int) $existing,
                $chainId,
                $chainFamily,
                $canonical,
                $row
            );
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
        // Who RAN this — 0 under the daily cron, or an admin acting on
        // someone else's request. Read before the create, which runs as the
        // requester for the constructor alone (F-160).
        $actingUserId = get_current_user_id();

        $groupId = $this->createPeepSoGroup($ownerId, $name);
        if ($groupId === 0) {
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'group_create_failed');
            return $this->fail($collectionId, ProvisioningFailureCode::GROUP_CREATE_FAILED,
                'Community creation failed; see the bcc-trust error log.', 0);
        }

        // ── F-160: the requester must hold member_owner, read back ──────
        // Asked of PeepSo's membership ledger, not inferred from the create
        // returning an id. A failed read returns null, so an unconfirmable
        // ownership fails closed exactly like a wrong one.
        $ownerStatus = PeepSoGroupRepository::getMembershipStatus($ownerId, $groupId);
        if ($ownerStatus !== 'member_owner') {
            Logger::error('[bcc-trust] holder group created without the requester as owner; compensating', [
                'collection_id' => $collectionId,
                'group_id'      => $groupId,
                'owner_id'      => $ownerId,
                'owner_status'  => $ownerStatus,
            ]);

            $this->invalidateGroupCaches($groupId);
            $auditOk = $this->compensate($groupId, $collectionId, $ownerId, $chainId);
            \BCC\Core\Observability\DegradationMetrics::record('gated_group_provision', 'group_create_failed');

            return $this->fail(
                $collectionId,
                ProvisioningFailureCode::GROUP_CREATE_FAILED,
                'Community creation was rolled back; its owner could not be set.',
                0,
                $auditOk === false
            );
        }

        // ── Finalize: gate meta + state + checked audit, atomically ─────
        try {
            TransactionManager::run(function () use (
                $groupId, $chainId, $chainFamily, $canonical, $collectionId, $ownerId, $row, $actingUserId
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
                        'acting_user_id'   => $actingUserId,
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

                // An unconfirmable postcondition is not a confirmed one. This
                // runs INSIDE the transaction, so throwing rolls the whole
                // write back — which is the only honest response to "we could
                // not check". Treating an unreadable re-read as a pass would
                // commit a claim on the strength of a query that never ran.
                if (!$after['available']) {
                    throw new \RuntimeException('the postcondition re-read could not be performed');
                }

                $afterRow = $after['row'];
                if ($afterRow === null
                    || (string) $afterRow->provisioning_state !== ProvisioningState::PROVISIONED) {
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
            $auditOk = $this->compensate($groupId, $collectionId, $ownerId, $chainId);

            return $this->fail(
                $collectionId,
                ProvisioningFailureCode::GATE_WRITE_REFUSED,
                'Community creation was rolled back; the gate could not be configured.',
                0,
                // A compensation whose own record could not be committed is
                // surfaced, not swallowed: the community is gone and nothing
                // durable says why.
                $auditOk === false
            );
        }

        // Raw locked/meta writes do not maintain WP's meta cache for us.
        $this->invalidateGroupCaches($groupId);

        Logger::info('[bcc-trust] Provisioned holder group', [
            'group_id'      => $groupId,
            'collection_id' => $collectionId,
            'chain_id'      => $chainId,
        ]);

        do_action('bcc_gated_group_provisioned', $groupId, $collectionId, $chainId, $canonical);

        return [
            'status'           => 'created',
            'group_id'         => $groupId,
            'message'          => 'Community created.',
            'failure_record'   => self::RECORD_NOT_APPLICABLE,
            'failure_recorded' => false,
            'audit_degraded'   => false,
        ];
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
    /**
     * The per-collection advisory lock name.
     *
     * Keyed by collection, not by chain and not globally: two administrators
     * provisioning two different collections must not block each other, and
     * the daily sweep must not hold one lock across a whole queue drain.
     */
    private static function lockName(int $collectionId): string
    {
        return 'bcc_gated_group_provision:' . $collectionId;
    }

    /**
     * Claim the per-collection lock, or FAIL CLOSED.
     *
     * A false return means "do not create anything". That direction is the
     * only safe one: a failed acquisition means either a peer is already
     * provisioning this collection, or we cannot serialize at all — and
     * creating unlocked in either case is exactly the duplicate this exists
     * to stop.
     *
     * ⚠ The two cases are logged differently because only one of them is a
     * defect. `AdvisoryLock::acquire()` already distinguishes a driver error
     * (GET_LOCK returned NULL) from a peer holding the lock and logs it, so
     * the only case this method has to report itself is the primitive being
     * absent entirely — which is a wiring fault, not contention.
     */
    private static function acquire(string $lock): bool
    {
        if (!class_exists('\\BCC\\Core\\DB\\AdvisoryLock')) {
            Logger::error('[bcc-trust] Community provisioning cannot be serialized: AdvisoryLock is unavailable', [
                'lock' => $lock,
            ]);

            return false;
        }

        return \BCC\Core\DB\AdvisoryLock::acquire($lock, self::LOCK_WAIT_SECONDS);
    }

    /**
     * Another run holds this collection's lock. Nothing was created.
     *
     * ⚠ NOT a failure, and deliberately not routed through {@see fail()}:
     * `fail()` writes a failure record and an audit row, and there is nothing
     * to record — no decision was taken about this collection, and the row
     * stays `requested` so the next tick retries it. Recording a `failed`
     * state here would also make the row ineligible on that retry.
     *
     * @return array{status: string, group_id: int, message: string,
     *               failure_code: string, failure_record: string,
     *               failure_recorded: bool, audit_degraded: bool}
     */
    private function contended(int $collectionId, int $chainId): array
    {
        Logger::info('[bcc-trust] Community provisioning skipped: another run holds this collection', [
            'collection_id' => $collectionId,
            'chain_id'      => $chainId,
            'operator'      => get_current_user_id(),
        ]);

        return [
            'status'           => 'busy',
            'group_id'         => 0,
            'message'          => 'Another provisioning run for this collection is already going; nothing was created.',
            'failure_code'     => self::FAIL_LOCK_CONTENDED,
            'failure_record'   => self::RECORD_NOT_APPLICABLE,
            'failure_recorded' => false,
            'audit_degraded'   => false,
        ];
    }

    private function compensate(int $groupId, int $collectionId, int $ownerId, int $chainId): bool
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

        // ── CHECKED, but NOT rollback-able ──────────────────────────────
        //
        // Every other state-changing PR 6 path uses `logChecked()` inside a
        // transaction, so a lost record undoes the change. Compensation
        // cannot do that: the PeepSo group is already deleted, the uploads
        // directory is already removed, and no database rollback reaches
        // either. Pretending otherwise would be the worse lie.
        //
        // So it is checked for its METADATA — the bounded residue markers are
        // the whole value of this row — and a failure is SURFACED to the
        // caller as an audit degradation rather than swallowed. The
        // provisioning result carries `audit_degraded`, and the operator
        // sees it. `logChecked()`'s own DegradationMetrics behaviour covers
        // the subsystem-level signal; PR 6 adds no new event.
        //
        // ⚠ `notes` was the original key for the cleanup markers. It is one of
        // AuditMeta's 22 free-text keys, so its VALUE would have been replaced
        // by `{omitted, len}` — the markers would have been destroyed, not
        // stored. Bounded facts need a key that is not on that list.
        $auditId = AuditLogger::logChecked(
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
                'cleanup_markers'    => $notes === [] ? 'none' : implode('|', $notes),
                'admin_email_sent'   => $notifyOn ? 'possible' : 'no',
            ],
            'collection',
            $ownerId
        );

        if ($auditId === null) {
            Logger::error(
                '[bcc-trust] compensation completed but its checked audit could not be written; '
                . 'the community was removed and there is no durable record of it',
                ['collection_id' => $collectionId, 'group_id' => $groupId]
            );

            return false;
        }

        return true;
    }


    /**
     * Record a failure durably, as a BOUNDED CODE, with a CHECKED audit.
     *
     * ── WHY THE TRANSITION AND ITS AUDIT ARE ONE TRANSACTION ────────────
     * The first cut moved `requested -> failed` and then called the
     * UNCHECKED `AuditLogger::log()`. That is exactly backwards for this
     * path: a `failed` row is a durable statement about a person's request,
     * and it is the state an operator is most likely to have to explain.
     * A lost `$meta` encode or a failed insert left that statement standing
     * with nothing behind it — no requester, no code, no time — precisely
     * when the trail matters most.
     *
     * Now the state write and `logChecked()` commit together or not at all.
     * If the record cannot be committed the collection stays `requested`,
     * which is the honest outcome: as far as anything durable is concerned
     * the attempt never happened, so the queue will try again.
     *
     * ── WHY THE CALLER IS TOLD WHICH ───────────────────────────────────
     * "The failure is on the record" and "the failure record could not be
     * committed" call for different operator responses, and collapsing them
     * is how a data-loss window gets reported as an ordinary refusal. The
     * result carries a bounded `failure_record` and a `failure_recorded`
     * bool derived from it.
     *
     * The MESSAGE is returned for the operator's screen and logged. It is
     * never persisted — only the bounded code is durable.
     *
     * @param bool $auditDegraded set by a compensation whose own checked
     *        audit could not be written; surfaced, never swallowed.
     * @return array{status: string, group_id: int, message: string,
     *               failure_code: string, failure_record: string,
     *               failure_recorded: bool, audit_degraded: bool}
     */
    private function fail(
        int $collectionId,
        string $code,
        string $message,
        int $groupId,
        bool $auditDegraded = false
    ): array {
        $record = self::RECORD_NOT_APPLICABLE;

        $read = CollectionRepository::readProvisioningRow($collectionId);

        // ⚠ NOT_APPLICABLE WOULD BE A LIE HERE.
        //
        // "There was nothing to record" and "we could not find out whether
        // there was anything to record" are different answers, and only the
        // first is `not_applicable`. Without the row we do not know the
        // current state, so there is no state to compare-and-swap from and no
        // requester to attribute — the write is impossible, not inapplicable.
        // Report it as UNCOMMITTED and write nothing.
        if (!$read['available']) {
            Logger::error('[bcc-trust] the provisioning row could not be read; this refusal is NOT durably recorded', [
                'collection_id' => $collectionId,
                'failure_code'  => $code,
                'error_code'    => self::ERROR_ROW_UNREADABLE,
            ]);

            return [
                'status'           => 'failed',
                'group_id'         => $groupId,
                'message'          => $message,
                'failure_code'     => $code,
                'failure_record'   => self::RECORD_UNCOMMITTED,
                'failure_recorded' => false,
                'audit_degraded'   => $auditDegraded,
            ];
        }

        $row = $read['row'];

        if ($row !== null && (string) $row->provisioning_state === ProvisioningState::REQUESTED) {
            $requestedBy = (int) $row->provisioning_requested_by ?: null;
            $requestedAt = $row->provisioning_requested_at !== null
                ? (string) $row->provisioning_requested_at
                : null;
            $chainId     = (int) $row->chain_id;

            try {
                TransactionManager::run(function () use (
                    $collectionId, $code, $groupId, $chainId, $requestedBy, $requestedAt
                ) {
                    $moved = CollectionRepository::setProvisioningState(
                        $collectionId,
                        ProvisioningState::REQUESTED,
                        ProvisioningState::FAILED,
                        $requestedBy,
                        $requestedAt,
                        $code
                    );

                    if (!$moved) {
                        // Either an illegal transition, a refused write, or a
                        // race someone else won. None of them is a failure
                        // this run may claim to have recorded.
                        throw new \RuntimeException('provisioning state did not move to failed');
                    }

                    $auditId = AuditLogger::logChecked(
                        self::AUDIT_FAILED,
                        $collectionId,
                        [
                            'collection_id'    => $collectionId,
                            'chain_id'         => $chainId,
                            'group_id'         => $groupId,
                            'operator_user_id' => (int) $requestedBy,
                            'previous_state'   => ProvisioningState::REQUESTED,
                            'new_state'        => ProvisioningState::FAILED,
                            'failure_code'     => $code,
                        ],
                        'collection',
                        $requestedBy
                    );

                    if ($auditId === null) {
                        throw new \RuntimeException('checked audit write failed; rolling back the failure transition');
                    }

                    return ['ok' => true, 'audit_id' => $auditId];
                });

                $record = self::RECORD_COMMITTED;
            } catch (\Throwable $e) {
                // The state write is rolled back with the audit, so the row is
                // still `requested`. Say so rather than reporting a durable
                // failure that does not exist.
                $record = self::RECORD_UNCOMMITTED;

                Logger::error('[bcc-trust] failure transition rolled back; the refusal is NOT durably recorded', [
                    'collection_id' => $collectionId,
                    'failure_code'  => $code,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        Logger::warning('[bcc-trust] provisioning refused', [
            'collection_id'  => $collectionId,
            'failure_code'   => $code,
            'failure_record' => $record,
        ]);

        return [
            'status'           => 'failed',
            'group_id'         => $groupId,
            'message'          => $message,
            'failure_code'     => $code,
            'failure_record'   => $record,
            'failure_recorded' => $record === self::RECORD_COMMITTED,
            'audit_degraded'   => $auditDegraded,
        ];
    }

    /**
     * A community already exists for this collection: reconcile the row to
     * `provisioned`, with a checked audit, or claim nothing.
     *
     * ── WHY THIS IS NOT A ONE-LINE BACKFILL ────────────────────────────
     * The first cut called a fire-and-forget `markProvisioned()`, ignored
     * whether the transition happened, wrote no audit, and returned `exists`
     * regardless. That reported success for a lost race and for a refused
     * write, and it left a state change with no record of who or when.
     *
     * Reconciliation IS a state change — the row moves `requested ->
     * provisioned` and stops being a queue entry — so it gets the same
     * treatment as any other: compare-and-swap, checked audit, postcondition
     * re-read, all in one transaction.
     *
     * ── WHAT IT DELIBERATELY DOES NOT DO ───────────────────────────────
     * It never deletes or modifies the pre-existing community. The community
     * is the thing that is RIGHT here; only the row is behind. On any
     * failure the row simply stays `requested` and the next sweep tries
     * again, which is idempotent because the community is still found.
     *
     * @param CollectionProvisioningRow $row the provisioning row read at the
     *        start of this run
     * @return array{status: string, group_id: int, message: string,
     *               failure_code: string|null, failure_record: string,
     *               failure_recorded: bool, audit_degraded: bool}
     */
    private function reconcileExistingCommunity(
        int $collectionId,
        int $groupId,
        int $chainId,
        string $chainFamily,
        string $canonical,
        object $row
    ): array {
        // F-161: `$row` was read BEFORE the per-collection lock. A run that
        // waited out the lock enters here with that stale row while the
        // winner has already created the community AND moved the row to
        // provisioned. Re-read under the lock: if the winner finished, this
        // run's correct answer is `exists`, exactly as the class docblock
        // promises the loser — not an `unconfirmed` from a requested →
        // provisioned compare-and-swap that can no longer match (which used
        // to write a provision_failed audit row for a correctly provisioned
        // collection and count it as a sweep failure).
        $fresh = CollectionRepository::readProvisioningRow($collectionId);
        if ($fresh['available']
            && $fresh['row'] !== null
            && (string) $fresh['row']->provisioning_state === ProvisioningState::PROVISIONED
        ) {
            return [
                'status'           => 'exists',
                'group_id'         => $groupId,
                'message'          => 'Community already exists.',
                'failure_code'     => null,
                'failure_record'   => self::RECORD_NOT_APPLICABLE,
                'failure_recorded' => false,
                'audit_degraded'   => false,
            ];
        }

        $requestedBy = (int) $row->provisioning_requested_by ?: null;
        $requestedAt = $row->provisioning_requested_at !== null
            ? (string) $row->provisioning_requested_at
            : null;

        try {
            TransactionManager::run(function () use (
                $collectionId, $groupId, $chainId, $chainFamily, $canonical, $requestedBy, $requestedAt
            ) {
                $moved = CollectionRepository::setProvisioningState(
                    $collectionId,
                    ProvisioningState::REQUESTED,
                    ProvisioningState::PROVISIONED,
                    $requestedBy,
                    $requestedAt,
                    null
                );

                if (!$moved) {
                    throw new \RuntimeException('provisioning state did not move from requested');
                }

                $auditId = AuditLogger::logChecked(
                    self::AUDIT_PROVISIONED,
                    $collectionId,
                    [
                        'collection_id'    => $collectionId,
                        'chain_id'         => $chainId,
                        'chain_family'     => $chainFamily,
                        // The EXISTING group, not one this run created.
                        'group_id'         => $groupId,
                        'operator_user_id' => (int) $requestedBy,
                        'previous_state'   => ProvisioningState::REQUESTED,
                        'new_state'        => ProvisioningState::PROVISIONED,
                    ],
                    'collection',
                    $requestedBy
                );

                if ($auditId === null) {
                    throw new \RuntimeException('checked audit write failed; rolling back the reconciliation');
                }

                // ── Postconditions, re-read before commit ───────────────
                $after = CollectionRepository::readProvisioningRow($collectionId);

                // An unconfirmable postcondition is not a confirmed one. This
                // runs INSIDE the transaction, so throwing rolls the whole
                // write back — which is the only honest response to "we could
                // not check". Treating an unreadable re-read as a pass would
                // commit a claim on the strength of a query that never ran.
                if (!$after['available']) {
                    throw new \RuntimeException('the postcondition re-read could not be performed');
                }

                $afterRow = $after['row'];
                if ($afterRow === null
                    || (string) $afterRow->provisioning_state !== ProvisioningState::PROVISIONED) {
                    throw new \RuntimeException('provisioning state did not survive its postcondition re-read');
                }

                $resolved = GatedGroupRepository::findGroupForCollection($chainId, $canonical);
                if ($resolved !== $groupId) {
                    throw new \RuntimeException('the collection no longer resolves to the community being reconciled');
                }

                return ['ok' => true, 'audit_id' => $auditId];
            });
        } catch (\Throwable $e) {
            // Nothing was created and nothing is deleted — the community
            // stands, the row stays `requested`, and the next sweep retries.
            Logger::error('[bcc-trust] reconciliation of an existing community rolled back', [
                'collection_id' => $collectionId,
                'group_id'      => $groupId,
                'error'         => $e->getMessage(),
            ]);

            return [
                'status'           => 'unconfirmed',
                'group_id'         => $groupId,
                'message'          => 'A community already exists, but recording that could not be committed. '
                    . 'Nothing was created or removed; this will be retried.',
                'failure_code'     => null,
                'failure_record'   => self::RECORD_UNCOMMITTED,
                'failure_recorded' => false,
                'audit_degraded'   => false,
            ];
        }

        return [
            'status'           => 'exists',
            'group_id'         => $groupId,
            'message'          => 'Community already exists.',
            'failure_code'     => null,
            'failure_record'   => self::RECORD_NOT_APPLICABLE,
            'failure_recorded' => false,
            'audit_degraded'   => false,
        ];
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

        // F-160: `owner_id` sets only post_author. PeepSo's create() makes
        // the CURRENT user `member_owner` — the admin who happened to press
        // the button, or user 0 under the daily cron, where it writes a
        // gm_user_id = 0 owner row nobody can ever act as. So the requester
        // is made the current user for the constructor alone, and the
        // previous identity is restored on every path, including a throw.
        //
        // ⚠ Safe HERE only because $ownerId is a re-verified administrator
        // (resolveRequesterAsOwner: get_userdata + user_can manage_options)
        // and the swap spans one PeepSo constructor. Do not copy this pattern
        // for a non-admin owner: every hook fired inside the window runs AS
        // that user, with their capabilities (#457 review L2).
        $previousUserId = get_current_user_id();
        $swapped        = $previousUserId !== $ownerId;

        try {
            if ($swapped) {
                wp_set_current_user($ownerId);
            }
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
        } finally {
            if ($swapped) {
                wp_set_current_user($previousUserId);
            }
        }
    }
}
