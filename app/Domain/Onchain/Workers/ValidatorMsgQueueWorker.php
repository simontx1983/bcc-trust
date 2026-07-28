<?php
/**
 * ValidatorMsgQueueWorker — first-claim backlog delivery.
 *
 * Delivers messages queued against a validator page BEFORE it had an
 * operator, once that page gains its FIRST verified operator claim.
 *
 * Why a worker and not the claim request: the claim REST call does
 * four bounded things (resolve, atomically create the activation
 * record, schedule this job, return). It never loops the queue and
 * never writes PeepSo messages, so claiming stays fast and a large
 * backlog cannot time out the user's request.
 *
 * ── At-most-once delivery (the crash-boundary proof) ───────────────
 * PeepSo's message storage is ordinary wp_posts/side-table writes on
 * the SAME $wpdb connection as the queue table, so ONE row's delivery
 * is ONE InnoDB transaction:
 *
 *     lease row (atomic UPDATE, outside the txn)
 *     TransactionManager::run(function () {
 *         re-check FIRST_CLAIM_RELEASE safety rules
 *         PeepSoMessageWriter::send*            // PeepSo inserts join this txn
 *         add_post_meta(msg, '_bcc_vmq_idem', key)   // forensic mapping
 *         markDelivered(row, leaseToken, ...)   // same txn
 *     })
 *
 * Therefore:
 *   - two concurrent workers  → the lease UPDATE admits exactly one
 *     (rows_affected===1); the loser skips. A per-page advisory lock
 *     additionally serialises workers so per-sender order is kept.
 *   - duplicate claim hooks   → the activation INSERT … ON DUPLICATE
 *     KEY admits one scheduler; a duplicate job finds nothing leasable.
 *   - death AFTER the PeepSo write but BEFORE the status update →
 *     both are in the same uncommitted txn, so both roll back. The
 *     message never existed; the row returns via lease expiry and
 *     retry is safe. This is the boundary a "send then mark" design
 *     cannot survive.
 *   - DB timeout during the status update → same rollback.
 *   - manual/self-heal retry  → delivered rows are terminal (the
 *     transition guard refuses re-lease) and the uq_idem key +
 *     `_bcc_vmq_idem` post_meta let the CLI prove whether a message
 *     already exists before ever re-sending.
 *
 * ── Policy ────────────────────────────────────────────────────────
 * Delivery runs under MessagingPolicy::VALIDATOR_FIRST_CLAIM_RELEASE:
 * the operator's chat_enabled + friends_only are bypassed for this
 * one-time release ONLY. Mutual block, deleted/suspended/banned
 * sender, self-message and body validity are all re-checked here and
 * send the row to a terminal `suppressed` state — never delivered,
 * never retried forever, never reported as delivered.
 *
 * PRIVACY: message bodies never enter logs, metrics, audit meta, or
 * error text. Only ids, codes and counts.
 *
 * @package BCC\Trust\Onchain\Workers
 */

namespace BCC\Trust\Onchain\Workers;

use BCC\Core\Cron\AsyncDispatcher;
use BCC\Core\DB\AdvisoryLock;
use BCC\Core\Log\Logger;
use BCC\Core\Observability\DegradationMetrics;
use BCC\Core\PeepSo\PeepSoMessageWriter;
use BCC\Trust\Core\Security\AuditLogger;
use BCC\Trust\Core\Services\MessagesService;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\ValidatorMsgActivationRepository;
use BCC\Trust\Onchain\Repositories\ValidatorMsgQueueRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class ValidatorMsgQueueWorker
{
    public const CRON_DELIVER = 'bcc_vmq_deliver';
    public const CRON_SWEEP   = 'bcc_vmq_sweep';

    /** Rows leased per invocation; the job reschedules while work remains. */
    private const BATCH_SIZE = 20;

    /** Wall-clock budget per invocation (checked before each row). */
    private const RUNTIME_BUDGET_SECONDS = 20;

    /** Delay before the continuation job when a batch fills up. */
    private const CONTINUATION_DELAY_SECONDS = 60;

    /** Attempts before a row parks as failed_terminal for manual recovery. */
    private const MAX_ATTEMPTS = 8;

    /** Backoff base and ceiling: 300s · 2^(n-1), capped at 6h. */
    private const BACKOFF_BASE_SECONDS = 300;
    private const BACKOFF_MAX_SECONDS  = 21600;

    /** Pages inspected per sweep pass. */
    private const SWEEP_PAGE_LIMIT = 20;

    /**
     * Cooldown (seconds) between `delivery_context_unsupported` signals so
     * a misconfigured, frequently-firing WP-CLI cron cannot flood the log
     * or the degradation counter. 5 min still lets the counter accumulate
     * well past the alert threshold within an alert window, so a sustained
     * misconfiguration stays loud without spamming.
     */
    private const CTX_UNSUPPORTED_COOLDOWN_SECONDS = 300;

    /** Transient key backing the cooldown above. */
    private const CTX_UNSUPPORTED_COOLDOWN_KEY = 'bcc_vmq_ctx_unsupported_cooldown';

    /**
     * Registered from plugins_loaded so a cron hook added by an update
     * self-heals without a reactivation (same pattern as the NFT
     * indexer worker).
     */
    public static function register(): void
    {
        add_action(self::CRON_DELIVER, [self::class, 'handleDeliver'], 10, 1);
        add_action(self::CRON_SWEEP, [self::class, 'handleSweep'], 10, 0);

        // bcc_five_minutes is an already-registered interval (see
        // CronService::addCronIntervals) — the sweep is ≤5 bounded
        // queries and mostly no-ops, so the cheap cadence buys fast
        // recovery without adding a new schedule.
        AsyncDispatcher::registerRecurring(self::CRON_SWEEP, 'bcc_five_minutes');
    }

    /**
     * Subscriber for `bcc_page_claimed`. Does the four bounded things
     * and returns — no queue loop, no PeepSo writes inside the claim
     * request.
     */
    public static function onPageClaimed(int $userId, int $pageId, string $entityType, int $entityId): void
    {
        if ($entityType !== 'validator' || $pageId <= 0 || $entityId <= 0) {
            return;
        }
        if (!MessagesService::validatorMessagingEnabled()) {
            return;
        }

        // 1. Confirm EXACTLY one eligible operator. Ambiguous or
        //    unresolved → schedule nothing (the resolver already
        //    recorded the metric + audit row).
        $resolution = ClaimRepository::resolveVerifiedOperatorForPage($pageId);
        if ($resolution['state'] !== ClaimRepository::OPERATOR_RESOLVED || $resolution['user_id'] === null) {
            return;
        }

        // 2. Atomically create the first-activation record. Only the
        //    call that actually performed first activation proceeds —
        //    repeated hook deliveries and later transfers no-op here.
        if (!ValidatorMsgActivationRepository::createFirstActivation($pageId, $entityId, $resolution['user_id'])) {
            return;
        }

        // 3. Schedule the bounded delivery job.
        if (!AsyncDispatcher::enqueueAsync(self::CRON_DELIVER, [$pageId], 'bcc-vmq')) {
            // Recoverable: the recurring sweep re-enqueues. Recorded so
            // operators can see the enqueue surface is unhealthy.
            DegradationMetrics::record('validator_messaging', 'schedule_failed');
        }
        // 4. Return.
    }

    /**
     * Deliver one bounded batch for a page, then reschedule while
     * deliverable rows remain.
     */
    public static function handleDeliver(int $pageId): void
    {
        $pageId = (int) $pageId;
        if ($pageId <= 0 || !MessagesService::validatorMessagingEnabled()) {
            return;
        }

        // Execution-context preflight — BEFORE the lock, the activation
        // state change, and any row lease. If PeepSo's Chat writer is not
        // available in THIS context (canonically WP-CLI, where PeepSo
        // disables itself), delivery cannot run: bail without touching a
        // single row so the queue stays intact and the normal HTTP Action
        // Scheduler / WP-Cron runner delivers it later. This is a context
        // condition, not a per-message failure — it must NOT lease rows,
        // consume attempts, or advance backoff. See
        // PeepSoMessageWriter::isReady().
        if (!PeepSoMessageWriter::isReady()) {
            self::reportUnsupportedContext();
            return;
        }

        // One worker per page: preserves per-sender ordering and stops
        // cron overlap from interleaving batches. Non-blocking — a
        // second worker simply lets the holder finish.
        $lockKey = 'bcc_vmq_page:' . $pageId;
        if (!AdvisoryLock::acquire($lockKey, 0)) {
            return;
        }

        try {
            $activation = ValidatorMsgActivationRepository::getByPageId($pageId);
            if ($activation === null) {
                // No activation record ⇒ nothing was ever released for
                // this page; the queue (if any) waits for a real claim.
                return;
            }
            if ($activation->backlog_status === 'completed') {
                return;
            }

            // Re-resolve: the claim may have transferred or gone
            // ambiguous between scheduling and running. The backlog
            // belongs to the FIRST operator only.
            $resolution = ClaimRepository::resolveVerifiedOperatorForPage($pageId);
            $operatorId = (int) $activation->first_operator_user_id;
            if ($resolution['state'] !== ClaimRepository::OPERATOR_RESOLVED
                || $resolution['user_id'] !== $operatorId) {
                ValidatorMsgActivationRepository::markBacklogFailed($pageId, 'operator_unresolved');
                return;
            }
            if (get_userdata($operatorId) === false) {
                ValidatorMsgActivationRepository::markBacklogFailed($pageId, 'operator_missing');
                return;
            }

            if (!ValidatorMsgActivationRepository::markBacklogRunning($pageId)) {
                return;
            }

            $startedAt = time();
            $rows = ValidatorMsgQueueRepository::findDeliverableForPage($pageId, self::BATCH_SIZE);

            foreach ($rows as $row) {
                if ((time() - $startedAt) >= self::RUNTIME_BUDGET_SECONDS) {
                    break;
                }
                self::processRow($row, $operatorId);
            }

            if (ValidatorMsgQueueRepository::hasUnfinishedForPage($pageId)) {
                AsyncDispatcher::scheduleSingle(
                    time() + self::CONTINUATION_DELAY_SECONDS,
                    self::CRON_DELIVER,
                    [$pageId],
                    'bcc-vmq'
                );
                return;
            }

            ValidatorMsgActivationRepository::markBacklogCompleted($pageId);
        } finally {
            AdvisoryLock::release($lockKey);
        }
    }

    /**
     * Emit the loud-but-throttled "delivery ran in an unsupported
     * execution context" signal. Records the distinct degradation event
     * and a SANITIZED warning (execution-context flags only — never a
     * body, token, address, email, or identity) so the condition is
     * visible on the health surface and in logs, while a transient
     * cooldown stops a frequently-firing CLI cron from flooding either.
     */
    private static function reportUnsupportedContext(): void
    {
        if (function_exists('get_transient') && get_transient(self::CTX_UNSUPPORTED_COOLDOWN_KEY)) {
            return;
        }
        if (function_exists('set_transient')) {
            set_transient(
                self::CTX_UNSUPPORTED_COOLDOWN_KEY,
                1,
                self::CTX_UNSUPPORTED_COOLDOWN_SECONDS
            );
        }

        DegradationMetrics::record('validator_messaging', 'delivery_context_unsupported');
        Logger::warning(
            '[bcc-trust] validator-message delivery skipped: PeepSo Chat writer is not '
            . 'available in this execution context (PeepSo disables itself under WP-CLI). '
            . 'Queue delivery must run via the HTTP Action Scheduler / WP-Cron runner; no '
            . 'rows were touched and the backlog remains recoverable.',
            [
                'sapi'       => php_sapi_name(),
                'wp_cli'     => defined('WP_CLI') && constant('WP_CLI'),
                'doing_cron' => function_exists('wp_doing_cron') && wp_doing_cron(),
            ]
        );
    }

    /**
     * Lease one row and attempt delivery inside a single transaction.
     *
     * @param object{id: string, sender_user_id: string, body: string, attempt_count: string, idempotency_key: string} $row
     */
    private static function processRow(object $row, int $operatorId): void
    {
        $rowId    = (int) $row->id;
        $senderId = (int) $row->sender_user_id;

        $leaseToken = ValidatorMsgQueueRepository::acquireLease($rowId);
        if ($leaseToken === null) {
            // Another worker won this row.
            return;
        }

        // Safety rules that survive the preference bypass. Each maps to
        // a terminal suppression — a VISIBLE operational state, never a
        // silent drop: it is audited (below), counted in the health
        // gauge (healthCounters), and printed by `wp bcc-trust vmq
        // status`. Suppression is correct policy enforcement, not a
        // subsystem degradation, so it carries an AUDIT row (forensic,
        // reason-code only — never the body) rather than a
        // DegradationMetrics event (which would false-alarm whenever
        // anyone is legitimately blocked).
        $suppressReason = self::resolveSuppression($senderId, $operatorId, (string) $row->body);
        if ($suppressReason !== null) {
            if (ValidatorMsgQueueRepository::markSuppressed($rowId, $leaseToken, $suppressReason)) {
                AuditLogger::log(
                    'vmq_suppressed',
                    $rowId,
                    ['reason' => $suppressReason],
                    'vmq_row',
                    null
                );
            }
            return;
        }

        $attempts = (int) $row->attempt_count;

        try {
            // Suppress PeepSo's per-message email for the backlog
            // release so a claim doesn't produce a notification storm.
            // This is PeepSo's own supported veto point — the canonical
            // write path is NOT bypassed.
            $veto = static fn (): bool => false;
            add_filter('peepso_mailqueue_allowed', $veto, 99);

            try {
                \BCC\Trust\Core\Security\TransactionManager::run(
                    static function () use ($rowId, $leaseToken, $senderId, $operatorId, $row): void {
                        $result = \BCC\Core\PeepSo\PeepSoMessageWriter::sendNewMessage(
                            $senderId,
                            $operatorId,
                            (string) $row->body
                        );
                        if ($result === null) {
                            // Rolls the whole transaction back.
                            throw new \RuntimeException('writer_failed');
                        }

                        // Forensic mapping: proves at-most-once across
                        // any future manual recovery.
                        add_post_meta(
                            (int) $result['message_id'],
                            '_bcc_vmq_idem',
                            (string) $row->idempotency_key,
                            true
                        );

                        $ok = ValidatorMsgQueueRepository::markDelivered(
                            $rowId,
                            $leaseToken,
                            $operatorId,
                            (int) $result['conversation_id'],
                            (int) $result['message_id']
                        );
                        if (!$ok) {
                            // Lost the lease mid-flight — roll back the
                            // message rather than deliver un-recorded.
                            throw new \RuntimeException('lease_lost');
                        }
                    }
                );
            } finally {
                remove_filter('peepso_mailqueue_allowed', $veto, 99);
            }
        } catch (\Throwable $e) {
            self::handleFailure($rowId, $leaseToken, $attempts, $e);
            return;
        }

        // Badge bumps AFTER commit — cache-only, must not run inside
        // the transaction (a rollback can't undo them).
        \BCC\Trust\Core\Services\BadgesService::bumpForUsers([$senderId, $operatorId]);
    }

    /**
     * FIRST_CLAIM_RELEASE safety rules. Returns a machine reason code
     * when the row must be suppressed, or null to proceed.
     *
     * The recipient-side release policy (bypass operator chat_enabled +
     * friends_only, but STILL shield a mutual block) is delegated to
     * MessagesService's single policy evaluator via
     * firstClaimReleaseBlocked — so the delivery worker and the policy
     * definition cannot drift on what "the one-time bypass" means. The
     * sender-side and content rules below are delivery-time specific
     * (they have no recipient-context equivalent in the DM send gates)
     * and are checked here.
     */
    private static function resolveSuppression(int $senderId, int $operatorId, string $body): ?string
    {
        if ($senderId <= 0 || get_userdata($senderId) === false) {
            return 'sender_deleted';
        }
        if ($senderId === $operatorId) {
            return 'self_message';
        }
        if (!\BCC\Core\Permissions\Permissions::is_not_suspended($senderId, false)) {
            return 'sender_suspended';
        }
        if (class_exists('PeepSoUser') && \PeepSoUser::get_instance($senderId)->get_user_role() === 'ban') {
            return 'sender_banned';
        }
        // Recipient-side gate under the one-time release: chat_enabled +
        // friends_only bypassed, mutual block still shields. Single-
        // sourced through the policy evaluator (VALIDATOR_FIRST_CLAIM_RELEASE).
        if (MessagesService::firstClaimReleaseBlocked($senderId, $operatorId)) {
            return 'mutual_block';
        }
        $trimmed = trim($body);
        if ($trimmed === '' || mb_strlen($trimmed) > MessagesService::MESSAGE_BODY_MAX_LENGTH) {
            return 'body_invalid';
        }
        return null;
    }

    /**
     * Transient failure → retryable with exponential backoff; once the
     * attempt budget is spent → failed_terminal, which is an explicit
     * operational state (metric + audit + `wp bcc-trust vmq status`),
     * never a silent drop. No message body in any of it.
     */
    private static function handleFailure(int $rowId, string $leaseToken, int $attempts, \Throwable $e): void
    {
        $reason = $e->getMessage() === 'writer_failed' ? 'writer_failed' : 'delivery_error';

        if (($attempts + 1) >= self::MAX_ATTEMPTS) {
            ValidatorMsgQueueRepository::markFailedTerminal($rowId, $leaseToken, $reason);
            DegradationMetrics::record('validator_messaging', 'delivery_failed_terminal');
            AuditLogger::log(
                'vmq_delivery_failed',
                $rowId,
                ['reason' => $reason, 'attempts' => $attempts + 1],
                'vmq_row',
                null
            );
            return;
        }

        $delay = (int) min(
            self::BACKOFF_MAX_SECONDS,
            self::BACKOFF_BASE_SECONDS * (2 ** max(0, $attempts))
        );
        ValidatorMsgQueueRepository::markRetryable($rowId, $leaseToken, $delay, $reason);
    }

    /**
     * Self-heal sweep: reclaim expired leases and re-enqueue pages
     * whose delivery job was lost (soft enqueue failure, killed
     * worker, cron outage).
     */
    public static function handleSweep(): void
    {
        if (!MessagesService::validatorMessagingEnabled()) {
            return;
        }

        $reaped = ValidatorMsgQueueRepository::reapExpiredLeases();
        if ($reaped > 0) {
            DegradationMetrics::record('validator_messaging', 'lease_reaped');
        }

        foreach (ValidatorMsgQueueRepository::pageIdsWithDeliverable(self::SWEEP_PAGE_LIMIT) as $pageId) {
            $activation = ValidatorMsgActivationRepository::getByPageId($pageId);
            if ($activation === null || $activation->backlog_status === 'completed') {
                // Never activated (still waiting for a real first
                // claim) or already finished — nothing to recover.
                continue;
            }
            ValidatorMsgActivationRepository::bumpSweepAttempts($pageId);
            AsyncDispatcher::enqueueAsync(self::CRON_DELIVER, [$pageId], 'bcc-vmq');
        }
    }

    /**
     * `bcc_system_health` contributor — aggregate counters only.
     *
     * @param array<string, mixed> $health
     * @return array<string, mixed>
     */
    public static function contributeHealth(array $health): array
    {
        $health['validator_msg_queue'] = ValidatorMsgQueueRepository::healthCounters();
        return $health;
    }
}
