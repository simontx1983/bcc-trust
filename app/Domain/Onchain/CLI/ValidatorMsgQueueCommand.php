<?php
/**
 * `wp bcc-trust vmq` — operator recovery for the validator message
 * queue.
 *
 * The queue is designed so nothing is ever silently lost: rows park in
 * visible states (`retryable`, `failed_terminal`) rather than
 * disappearing. This command is how an operator inspects and clears
 * those states.
 *
 * PRIVACY: never prints message bodies. Ids, states, codes and counts
 * only.
 *
 * @package BCC\Trust\Onchain\CLI
 */

namespace BCC\Trust\Onchain\CLI;

use BCC\Core\PeepSo\PeepSoMessageWriter;
use BCC\Trust\Onchain\Repositories\ValidatorMsgActivationRepository;
use BCC\Trust\Onchain\Repositories\ValidatorMsgQueueRepository;
use BCC\Trust\Onchain\Workers\ValidatorMsgQueueWorker;

if (!defined('ABSPATH')) {
    exit;
}

class ValidatorMsgQueueCommand
{
    /**
     * Queue health at a glance, optionally for one page.
     *
     * ## OPTIONS
     *
     * [--page_id=<page_id>]
     * : Restrict the report to one validator page.
     *
     * ## EXAMPLES
     *
     *     wp bcc-trust vmq status
     *     wp bcc-trust vmq status --page_id=8821
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $counters = ValidatorMsgQueueRepository::healthCounters();
        \WP_CLI::log(sprintf(
            'pending=%d  delivered=%d  suppressed=%d  failed_terminal=%d  oldest_pending_age=%ds',
            $counters['pending'],
            $counters['delivered'],
            $counters['suppressed'],
            $counters['failed_terminal'],
            $counters['oldest_pending_age_seconds']
        ));

        $pageId = (int) ($assoc['page_id'] ?? 0);
        if ($pageId <= 0) {
            $unfinished = ValidatorMsgActivationRepository::getUnfinished(50);
            if ($unfinished === []) {
                \WP_CLI::success('No unfinished backlog releases.');
                return;
            }
            \WP_CLI::log('Unfinished backlog releases:');
            foreach ($unfinished as $row) {
                \WP_CLI::log(sprintf(
                    '  page %d  status=%s  operator=%d  sweeps=%d  last_error=%s',
                    (int) $row->page_id,
                    (string) $row->backlog_status,
                    (int) $row->first_operator_user_id,
                    (int) $row->sweep_attempts,
                    $row->last_error_code ?? '-'
                ));
            }
            return;
        }

        $activation = ValidatorMsgActivationRepository::getByPageId($pageId);
        \WP_CLI::log($activation === null
            ? sprintf('page %d: never activated (no first verified operator yet)', $pageId)
            : sprintf(
                'page %d: backlog=%s operator=%d activated_at=%s last_error=%s',
                $pageId,
                (string) $activation->backlog_status,
                (int) $activation->first_operator_user_id,
                (string) $activation->first_activated_at,
                $activation->last_error_code ?? '-'
            ));
        \WP_CLI::log(sprintf(
            '  pending on page: %d',
            ValidatorMsgQueueRepository::countPendingForPage($pageId)
        ));
    }

    /**
     * Run a bounded delivery pass for one page now (same code path the
     * cron uses — leases, transactions and idempotency all apply).
     *
     * NOTE: this CANNOT actually deliver under WP-CLI. PeepSo disables its
     * Chat module in the CLI SAPI, so its message writer is unavailable
     * here; real delivery only runs through the HTTP Action Scheduler /
     * WP-Cron runner. When run in an unsupported context this command
     * fails loudly (non-zero exit) and mutates nothing, rather than
     * reporting a hollow "complete". Use `vmq status` (read-only) to
     * inspect the queue from the CLI.
     *
     * ## OPTIONS
     *
     * <page_id>
     * : The validator page id.
     *
     * ## EXAMPLES
     *
     *     wp bcc-trust vmq drain 8821
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc
     */
    public function drain(array $args, array $assoc): void
    {
        $pageId = (int) ($args[0] ?? 0);
        if ($pageId <= 0) {
            \WP_CLI::error('A positive page_id is required.');
            return;
        }

        // Run the same worker path the cron uses. In an unsupported context
        // (WP-CLI, where PeepSo's Chat writer is not booted) handleDeliver
        // safely no-ops — it leases no row and consumes no attempt — AND
        // emits the distinct `delivery_context_unsupported` health signal
        // (cooldown-throttled). Routing through it means a `vmq drain`
        // misused as a delivery runner is OBSERVABLE, not silent, and the
        // signal has a single source of truth (the worker).
        ValidatorMsgQueueWorker::handleDeliver($pageId);

        // Then fail loudly if this context cannot actually deliver, rather
        // than printing a hollow "complete" for a pass that delivered nothing
        // (the row/attempt state is unchanged — see handleDeliver's preflight).
        if (!PeepSoMessageWriter::isReady()) {
            \WP_CLI::error(
                'PeepSo Chat is not available in the WP-CLI context, so validator-message '
                . 'delivery cannot run here (PeepSo disables its messaging module under '
                . 'WP-CLI). No rows were modified. Delivery must run through the supported '
                . 'HTTP Action Scheduler / WP-Cron runner; do not use `wp cron event run` or '
                . '`wp action-scheduler run` as the delivery runner. `vmq status` (read-only) '
                . 'still works from the CLI.'
            );
            return; // WP_CLI::error halts with a non-zero exit code.
        }

        \WP_CLI::success(sprintf(
            'Delivery pass complete for page %d. Pending now: %d',
            $pageId,
            ValidatorMsgQueueRepository::countPendingForPage($pageId)
        ));
    }

    /**
     * Return a failed_terminal row to the retry pool.
     *
     * Refuses when the row's idempotency key is already stamped on a
     * delivered PeepSo message — that proves the message DID land and
     * re-sending would duplicate it. This is the forensic check that
     * makes manual recovery safe.
     *
     * ## OPTIONS
     *
     * <row_id>
     * : The queue row id.
     *
     * ## EXAMPLES
     *
     *     wp bcc-trust vmq retry 4412
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc
     */
    public function retry(array $args, array $assoc): void
    {
        $rowId = (int) ($args[0] ?? 0);
        $row   = $rowId > 0 ? ValidatorMsgQueueRepository::findById($rowId) : null;
        if ($row === null) {
            \WP_CLI::error('Queue row not found.');
            return;
        }
        if ((string) $row->status === ValidatorMsgQueueRepository::STATUS_DELIVERED) {
            \WP_CLI::error('Row is already delivered — nothing to retry.');
            return;
        }

        // Idempotency cross-check: does a message already carry this key?
        $existing = get_posts([
            'post_type'      => 'peepso-message',
            'post_status'    => 'any',
            'meta_key'       => '_bcc_vmq_idem',
            'meta_value'     => (string) $row->idempotency_key,
            'fields'         => 'ids',
            'posts_per_page' => 1,
        ]);
        if (!empty($existing)) {
            \WP_CLI::error(sprintf(
                'Refusing: message %d already carries this row\'s idempotency key. '
                . 'The delivery landed; mark the row delivered manually instead of re-sending.',
                (int) $existing[0]
            ));
            return;
        }

        if (!ValidatorMsgQueueRepository::requeueForRetry($rowId)) {
            \WP_CLI::error('Row is not in a retryable state.');
            return;
        }
        \WP_CLI::success(sprintf('Row %d returned to the retry pool.', $rowId));
    }

    /**
     * Terminally suppress a row (operator moderation decision).
     *
     * ## OPTIONS
     *
     * <row_id>
     * : The queue row id.
     *
     * <reason>
     * : Short machine reason code recorded on the row (no free text /
     *   no message content).
     *
     * ## EXAMPLES
     *
     *     wp bcc-trust vmq suppress 4412 operator_request
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc
     */
    public function suppress(array $args, array $assoc): void
    {
        $rowId  = (int) ($args[0] ?? 0);
        $reason = (string) ($args[1] ?? '');
        if ($rowId <= 0 || $reason === '') {
            \WP_CLI::error('Usage: wp bcc-trust vmq suppress <row_id> <reason>');
            return;
        }
        if (!ValidatorMsgQueueRepository::suppressAdministratively($rowId, $reason)) {
            \WP_CLI::error('Row not found or already terminal.');
            return;
        }
        \WP_CLI::success(sprintf('Row %d suppressed (%s).', $rowId, $reason));
    }
}
