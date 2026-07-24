<?php
/**
 * Validator Msg Queue Repository
 *
 * The pre-claim message queue for validator pages. Every state
 * transition below is a WHERE-guarded UPDATE that asserts its own
 * precondition — an illegal transition affects 0 rows and returns
 * false rather than corrupting the row.
 *
 * State machine:
 *   queued          → processing        (lease acquired)
 *   retryable       → processing        (lease acquired, next_retry_at due)
 *   processing      → delivered         (delivery transaction committed)
 *   processing      → suppressed        (safety rule, reason_code set)
 *   processing      → retryable         (transient failure, backoff)
 *   processing      → failed_terminal   (attempts exhausted)
 *   processing(exp) → retryable         (lease reaper — no attempt bump)
 * Terminal: delivered, suppressed, failed_terminal.
 *
 * Leasing is a SINGLE atomic UPDATE (never SELECT-then-UPDATE): the
 * WHERE clause admits queued rows, due retryable rows, and rows whose
 * processing lease has EXPIRED. rows_affected === 1 means this worker
 * owns the row; every later write carries `AND lease_token = %s` so a
 * revived zombie worker cannot write over a re-leased row.
 *
 * PRIVACY: `body` never leaves this table except into the delivered
 * PeepSo message. Never log it, never put it in metrics/audit meta.
 *
 * @package BCC\Trust\Onchain\Repositories
 */

namespace BCC\Trust\Onchain\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @phpstan-type QueueRow object{
 *     id: string,
 *     page_id: string,
 *     sender_user_id: string,
 *     body: string,
 *     created_at: string,
 *     status: string,
 *     lease_token: string|null,
 *     lease_expires_at: string|null,
 *     attempt_count: string,
 *     next_retry_at: string|null,
 *     resolved_operator_user_id: string|null,
 *     delivered_conversation_id: string|null,
 *     delivered_message_id: string|null,
 *     delivered_at: string|null,
 *     suppressed_at: string|null,
 *     reason_code: string|null,
 *     idempotency_key: string,
 *     updated_at: string
 * }
 */
class ValidatorMsgQueueRepository {

    /** @var string Explicit column list — must match schema-validator-msg-queue.php. */
    private const COLUMNS = 'id, page_id, sender_user_id, body, created_at, status,
                 lease_token, lease_expires_at, attempt_count, next_retry_at,
                 resolved_operator_user_id, delivered_conversation_id,
                 delivered_message_id, delivered_at, suppressed_at, reason_code,
                 idempotency_key, updated_at';

    public const STATUS_QUEUED          = 'queued';
    public const STATUS_PROCESSING      = 'processing';
    public const STATUS_DELIVERED       = 'delivered';
    public const STATUS_RETRYABLE       = 'retryable';
    public const STATUS_SUPPRESSED      = 'suppressed';
    public const STATUS_FAILED_TERMINAL = 'failed_terminal';

    /** Seconds a worker may hold a row before the sweep may reclaim it. */
    public const LEASE_SECONDS = 120;

    public static function table(): string {
        return \BCC\Core\DB\DB::table('validator_msg_queue');
    }

    /**
     * Accept a message into the queue. Caller has already run every
     * submission gate (auth, suspension/ban, sender chat, body
     * validation, throttles, caps) — this is the durable write.
     *
     * @return int inserted row id, or 0 on failure
     */
    public static function enqueue(int $pageId, int $senderUserId, string $body): int {
        if ($pageId <= 0 || $senderUserId <= 0 || $body === '') {
            return 0;
        }
        global $wpdb;

        $ok = $wpdb->insert(
            self::table(),
            [
                'page_id'         => $pageId,
                'sender_user_id'  => $senderUserId,
                'body'            => $body,
                'created_at'      => gmdate('Y-m-d H:i:s'),
                'status'          => self::STATUS_QUEUED,
                'attempt_count'   => 0,
                'idempotency_key' => wp_generate_uuid4(),
                'updated_at'      => gmdate('Y-m-d H:i:s'),
            ],
            ['%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        return $ok ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Pending (not yet terminal) rows a sender holds against one page —
     * the per-sender-per-page cap read.
     */
    public static function countPendingForPageBySender(int $pageId, int $senderUserId): int {
        if ($pageId <= 0 || $senderUserId <= 0) {
            return 0;
        }
        global $wpdb;
        $table = self::table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE page_id = %d AND sender_user_id = %d
                AND status IN ('queued', 'processing', 'retryable')",
            $pageId,
            $senderUserId
        ));
    }

    /** Pending rows across all senders for one page — the queue-depth cap read. */
    public static function countPendingForPage(int $pageId): int {
        if ($pageId <= 0) {
            return 0;
        }
        global $wpdb;
        $table = self::table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE page_id = %d
                AND status IN ('queued', 'processing', 'retryable')",
            $pageId
        ));
    }

    /**
     * Queue inserts by one sender inside the rate-limit window. Added
     * to the PeepSo message count so the queue is not a throttle
     * escape hatch (the shared 30/300s budget covers both surfaces).
     */
    public static function countRecentBySender(int $senderUserId, int $windowSeconds): int {
        if ($senderUserId <= 0) {
            return 0;
        }
        global $wpdb;
        $table = self::table();

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE sender_user_id = %d
                AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL %d SECOND)",
            $senderUserId,
            max(1, $windowSeconds)
        ));
    }

    /**
     * Deliverable rows for a page, oldest-first per sender so a single
     * sender's messages land in the order they were written.
     * Bounded by $limit (worker batch size).
     *
     * @return list<QueueRow>
     */
    public static function findDeliverableForPage(int $pageId, int $limit = 20): array {
        if ($pageId <= 0) {
            return [];
        }
        global $wpdb;
        $table   = self::table();
        $columns = self::COLUMNS;
        $limit   = max(1, min($limit, 100));

        /** @var list<QueueRow>|null $rows */
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$columns} FROM {$table}
              WHERE page_id = %d
                AND (status = 'queued'
                     OR (status = 'retryable' AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP()))
                     OR (status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < UTC_TIMESTAMP()))
              ORDER BY sender_user_id ASC, created_at ASC, id ASC
              LIMIT %d",
            $pageId,
            $limit
        ));
        return $rows ?: [];
    }

    /** Any row on this page still needing work (drives backlog completion). */
    public static function hasUnfinishedForPage(int $pageId): bool {
        if ($pageId <= 0) {
            return false;
        }
        global $wpdb;
        $table = self::table();

        return $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$table}
              WHERE page_id = %d
                AND status IN ('queued', 'processing', 'retryable')
              LIMIT 1",
            $pageId
        )) !== null;
    }

    /**
     * Atomically claim one row for this worker. The WHERE clause is the
     * whole concurrency story: exactly one caller can flip a given row
     * into processing, and an expired lease is reclaimable.
     *
     * @return string|null lease token on success, null when another
     *                     worker won the race (caller skips the row)
     */
    public static function acquireLease(int $rowId): ?string {
        if ($rowId <= 0) {
            return null;
        }
        global $wpdb;
        $table = self::table();
        $token = wp_generate_uuid4();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = 'processing',
                    lease_token = %s,
                    lease_expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d
                AND (status = 'queued'
                     OR (status = 'retryable' AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP()))
                     OR (status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at < UTC_TIMESTAMP()))",
            $token,
            self::LEASE_SECONDS,
            $rowId
        ));

        return $wpdb->rows_affected === 1 ? $token : null;
    }

    /**
     * processing → delivered. Runs INSIDE the delivery transaction
     * (same connection as the PeepSo message write), so a crash before
     * COMMIT rolls back the message too — that atomicity is what makes
     * retry safe.
     */
    public static function markDelivered(
        int $rowId,
        string $leaseToken,
        int $operatorUserId,
        int $conversationId,
        int $messageId
    ): bool {
        if ($rowId <= 0 || $leaseToken === '') {
            return false;
        }
        global $wpdb;
        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = 'delivered',
                    resolved_operator_user_id = %d,
                    delivered_conversation_id = %d,
                    delivered_message_id = %d,
                    delivered_at = UTC_TIMESTAMP(),
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    reason_code = NULL,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d AND lease_token = %s AND status = 'processing'",
            $operatorUserId,
            $conversationId,
            $messageId,
            $rowId,
            $leaseToken
        ));
        return $wpdb->rows_affected === 1;
    }

    /**
     * processing → suppressed (terminal). An explicit safety decision —
     * mutual block, deleted/suspended/banned sender, self-message,
     * invalid body. Never delivered, never retried, never reported as
     * delivered. reason_code is a machine code only.
     */
    public static function markSuppressed(int $rowId, string $leaseToken, string $reasonCode): bool {
        if ($rowId <= 0 || $leaseToken === '') {
            return false;
        }
        global $wpdb;
        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = 'suppressed',
                    suppressed_at = UTC_TIMESTAMP(),
                    reason_code = %s,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d AND lease_token = %s AND status = 'processing'",
            substr($reasonCode, 0, 40),
            $rowId,
            $leaseToken
        ));
        return $wpdb->rows_affected === 1;
    }

    /**
     * processing → retryable with exponential backoff, or →
     * failed_terminal once attempts are exhausted. Caller supplies the
     * computed delay so the policy stays in the worker.
     */
    public static function markRetryable(int $rowId, string $leaseToken, int $delaySeconds, string $reasonCode): bool {
        if ($rowId <= 0 || $leaseToken === '') {
            return false;
        }
        global $wpdb;
        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = 'retryable',
                    attempt_count = attempt_count + 1,
                    next_retry_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL %d SECOND),
                    reason_code = %s,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d AND lease_token = %s AND status = 'processing'",
            max(1, $delaySeconds),
            substr($reasonCode, 0, 40),
            $rowId,
            $leaseToken
        ));
        return $wpdb->rows_affected === 1;
    }

    /** processing → failed_terminal (attempts exhausted; manual recovery). */
    public static function markFailedTerminal(int $rowId, string $leaseToken, string $reasonCode): bool {
        if ($rowId <= 0 || $leaseToken === '') {
            return false;
        }
        global $wpdb;
        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = 'failed_terminal',
                    attempt_count = attempt_count + 1,
                    reason_code = %s,
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    updated_at = UTC_TIMESTAMP()
              WHERE id = %d AND lease_token = %s AND status = 'processing'",
            substr($reasonCode, 0, 40),
            $rowId,
            $leaseToken
        ));
        return $wpdb->rows_affected === 1;
    }

    /**
     * Lease reaper — expired processing rows return to retryable
     * WITHOUT an attempt bump (the attempt may never have run; a
     * killed worker must not burn the row's retry budget).
     *
     * @return int rows reaped
     */
    public static function reapExpiredLeases(int $limit = 100): int {
        global $wpdb;
        $table = self::table();

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
                SET status = 'retryable',
                    lease_token = NULL,
                    lease_expires_at = NULL,
                    next_retry_at = UTC_TIMESTAMP(),
                    updated_at = UTC_TIMESTAMP()
              WHERE status = 'processing'
                AND lease_expires_at IS NOT NULL
                AND lease_expires_at < UTC_TIMESTAMP()
              LIMIT %d",
            max(1, min($limit, 500))
        ));
        return (int) $wpdb->rows_affected;
    }

    /**
     * @return QueueRow|null
     */
    public static function findById(int $rowId): ?object {
        if ($rowId <= 0) {
            return null;
        }
        global $wpdb;
        $table   = self::table();
        $columns = self::COLUMNS;

        /** @var QueueRow|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT {$columns} FROM {$table} WHERE id = %d LIMIT 1",
            $rowId
        ));
        return $row;
    }

    /**
     * Page ids with deliverable rows — the sweep's work-list (pages
     * whose backlog job was lost or never fired).
     *
     * @return list<int>
     */
    public static function pageIdsWithDeliverable(int $limit = 20): array {
        global $wpdb;
        $table = self::table();

        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT page_id FROM {$table}
              WHERE status IN ('queued', 'retryable')
              ORDER BY page_id ASC
              LIMIT %d",
            max(1, min($limit, 100))
        ));

        $out = [];
        foreach ($rows ?: [] as $raw) {
            $id = (int) $raw;
            if ($id > 0) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /**
     * Operational counters for /system/health + `wp bcc-trust vmq
     * status`. Aggregate, no bodies.
     *
     * @return array{pending: int, failed_terminal: int, oldest_pending_age_seconds: int}
     */
    public static function healthCounters(): array {
        global $wpdb;
        $table = self::table();

        /** @var object{pending: string|null, failed_terminal: string|null, oldest: string|null}|null $row */
        $row = $wpdb->get_row(
            "SELECT
                SUM(status IN ('queued','processing','retryable')) AS pending,
                SUM(status = 'failed_terminal') AS failed_terminal,
                MIN(CASE WHEN status IN ('queued','processing','retryable') THEN created_at END) AS oldest
             FROM {$table}"
        );

        $oldestAge = 0;
        if ($row !== null && $row->oldest !== null) {
            $oldestAge = max(0, time() - (int) strtotime($row->oldest . ' UTC'));
        }

        return [
            'pending'                    => $row !== null ? (int) $row->pending : 0,
            'failed_terminal'            => $row !== null ? (int) $row->failed_terminal : 0,
            'oldest_pending_age_seconds' => $oldestAge,
        ];
    }
}
