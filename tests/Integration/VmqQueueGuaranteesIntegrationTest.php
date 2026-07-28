<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ValidatorMsgQueueRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pins the durable queue guarantees the delivery worker relies on — the
 * retry/backoff advance, the eight-attempt terminal cap's building blocks,
 * and the at-most-once / terminal-state guards — against a real MySQL.
 *
 * These are the invariants the unsupported-context preflight must NOT
 * weaken: a GENUINE writer failure (writer available, send failed) still
 * leases, increments the attempt, backs off, and can terminalise; a
 * delivered/suppressed/failed_terminal row is terminal and cannot be
 * re-leased (no duplicate delivery). The lease is recoverable on expiry.
 */
#[Group('integration')]
final class VmqQueueGuaranteesIntegrationTest extends TestCase
{
    private const PAGE_ID   = 92002;
    private const SENDER_ID = 4303;

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_validator_msg_queue`');
    }

    /** Genuine failure path: lease → markRetryable increments attempt + sets backoff. */
    public function testLeaseThenRetryableIncrementsAttemptAndBacksOff(): void
    {
        $rowId = ValidatorMsgQueueRepository::enqueue(self::PAGE_ID, self::SENDER_ID, 'body');
        self::assertGreaterThan(0, $rowId);

        $token = ValidatorMsgQueueRepository::acquireLease($rowId);
        self::assertNotNull($token, 'a queued row must be leasable');
        self::assertSame('processing', $this->row($rowId)->status);

        self::assertTrue(
            ValidatorMsgQueueRepository::markRetryable($rowId, $token, 300, 'writer_failed')
        );

        $r = $this->row($rowId);
        self::assertSame('retryable', $r->status);
        self::assertSame('1', (string) $r->attempt_count, 'a genuine failure MUST consume one attempt');
        self::assertNotNull($r->next_retry_at, 'backoff must be set');
        self::assertSame('writer_failed', $r->reason_code);
        self::assertNull($r->lease_token, 'lease released on retryable');
    }

    /** At-most-once: a delivered row is terminal and refuses re-lease. */
    public function testDeliveredIsTerminalAndRefusesReLease(): void
    {
        $rowId = ValidatorMsgQueueRepository::enqueue(self::PAGE_ID, self::SENDER_ID, 'body');
        $token = ValidatorMsgQueueRepository::acquireLease($rowId);
        self::assertNotNull($token);

        self::assertTrue(
            ValidatorMsgQueueRepository::markDelivered($rowId, $token, 777, 6001, 6001)
        );
        $r = $this->row($rowId);
        self::assertSame('delivered', $r->status);
        self::assertSame('6001', (string) $r->delivered_conversation_id);
        self::assertSame('6001', (string) $r->delivered_message_id);

        // The core at-most-once guard: a terminal row can never be re-leased,
        // so a re-run/duplicate worker cannot deliver it twice.
        self::assertNull(
            ValidatorMsgQueueRepository::acquireLease($rowId),
            'a delivered row must not be re-leasable (no duplicate delivery)'
        );
    }

    /** Suppressed + failed_terminal are terminal and refuse re-lease. */
    public function testSuppressedAndFailedTerminalAreTerminal(): void
    {
        foreach (['suppress', 'terminal'] as $mode) {
            $rowId = ValidatorMsgQueueRepository::enqueue(self::PAGE_ID, self::SENDER_ID, 'body-' . $mode);
            $token = ValidatorMsgQueueRepository::acquireLease($rowId);
            self::assertNotNull($token);

            if ($mode === 'suppress') {
                self::assertTrue(ValidatorMsgQueueRepository::markSuppressed($rowId, $token, 'mutual_block'));
                self::assertSame('suppressed', $this->row($rowId)->status);
            } else {
                self::assertTrue(ValidatorMsgQueueRepository::markFailedTerminal($rowId, $token, 'writer_failed'));
                self::assertSame('failed_terminal', $this->row($rowId)->status);
            }

            self::assertNull(
                ValidatorMsgQueueRepository::acquireLease($rowId),
                "a {$mode} row must be terminal"
            );
        }
    }

    /** Crash recovery: an expired processing lease is re-leasable (a new token). */
    public function testExpiredLeaseIsReLeasable(): void
    {
        global $wpdb;
        $rowId = ValidatorMsgQueueRepository::enqueue(self::PAGE_ID, self::SENDER_ID, 'body');
        $first = ValidatorMsgQueueRepository::acquireLease($rowId);
        self::assertNotNull($first);

        // Simulate a worker that died mid-row: force the lease to be expired.
        $wpdb->query(
            'UPDATE `' . $wpdb->prefix . 'bcc_validator_msg_queue`'
            . ' SET lease_expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE id = ' . (int) $rowId
        );

        $second = ValidatorMsgQueueRepository::acquireLease($rowId);
        self::assertNotNull($second, 'an expired processing lease must be re-leasable');
        self::assertNotSame($first, $second, 'the re-lease must mint a fresh token');
    }

    private function row(int $id): object
    {
        global $wpdb;
        $r = $wpdb->get_row('SELECT * FROM `' . $wpdb->prefix . 'bcc_validator_msg_queue` WHERE id = ' . (int) $id);
        self::assertNotNull($r);
        return $r;
    }
}
