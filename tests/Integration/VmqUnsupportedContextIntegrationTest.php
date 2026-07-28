<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Observability\DegradationMetrics;
use BCC\Core\PeepSo\PeepSoMessageWriter;
use BCC\Trust\Onchain\Repositories\ValidatorMsgActivationRepository;
use BCC\Trust\Onchain\Repositories\ValidatorMsgQueueRepository;
use BCC\Trust\Onchain\Workers\ValidatorMsgQueueWorker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pins the fail-loud unsupported-execution-context behaviour of the
 * validator-message delivery worker against a real MySQL.
 *
 * The test harness has NO PeepSo classes loaded, so it IS the unsupported
 * context (`PeepSoMessageWriter::isReady() === false`) — exactly the WP-CLI
 * situation in production. The worker must, in that context:
 *   - deliver nothing and create no conversation (there is no PeepSo here);
 *   - touch NO queue row (no lease, zero attempts consumed, no backoff);
 *   - advance NO activation state;
 *   - record the distinct `delivery_context_unsupported` degradation event
 *     (NOT a `delivery_failed_terminal`), throttled by a cooldown so a
 *     frequently-firing CLI cron cannot flood the signal;
 *   - leave the backlog fully recoverable by a later HTTP-context run.
 */
#[Group('integration')]
final class VmqUnsupportedContextIntegrationTest extends TestCase
{
    private const PAGE_ID     = 91001;
    private const ENTITY_ID   = 55001;
    private const OPERATOR_ID = 4201;
    private const SENDER_ID   = 4202;

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_validator_msg_queue`');
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_validator_msg_activation`');

        // Per-test isolation of options, the cooldown transient, and the
        // metric store.
        $GLOBALS['__bcc_test_options']      = [];
        $GLOBALS['__bcc_test_object_cache'] = [];
        $GLOBALS['__bcc_test_transients']   = [];

        // The kill-switch must be ON, else handleDeliver early-returns before
        // the context preflight ever runs.
        update_option('bcc_validator_messaging_enabled', 1);
        delete_transient('bcc_vmq_ctx_unsupported_cooldown');
    }

    public function testHarnessPresentsAsUnsupportedContext(): void
    {
        self::assertFalse(
            PeepSoMessageWriter::isReady(),
            'the harness must present as an unsupported context (no PeepSo classes)'
        );
    }

    public function testUnsupportedContextLeavesQueueAndActivationUntouched(): void
    {
        self::assertTrue(
            ValidatorMsgActivationRepository::createFirstActivation(self::PAGE_ID, self::ENTITY_ID, self::OPERATOR_ID)
        );
        $rowId = ValidatorMsgQueueRepository::enqueue(self::PAGE_ID, self::SENDER_ID, 'ctx test body');
        self::assertGreaterThan(0, $rowId);

        $before           = $this->row($rowId);
        $activationBefore = ValidatorMsgActivationRepository::getByPageId(self::PAGE_ID);

        ValidatorMsgQueueWorker::handleDeliver(self::PAGE_ID);

        $after = $this->row($rowId);
        self::assertSame('queued', $after->status, 'status must stay queued');
        self::assertSame('0', (string) $after->attempt_count, 'no delivery attempt may be consumed');
        self::assertNull($after->lease_token, 'no lease may be taken');
        self::assertNull($after->next_retry_at, 'backoff must not advance');
        self::assertNull($after->delivered_conversation_id);
        self::assertNull($after->delivered_message_id);
        self::assertNull($after->reason_code, 'the row must not be marked failed/retryable');
        self::assertSame($before->updated_at, $after->updated_at, 'the row must not be rewritten at all');

        $activationAfter = ValidatorMsgActivationRepository::getByPageId(self::PAGE_ID);
        self::assertSame(
            $activationBefore->backlog_status,
            $activationAfter->backlog_status,
            'activation must not advance to running/failed/completed'
        );

        self::assertSame(1, ValidatorMsgQueueRepository::countPendingForPage(self::PAGE_ID));
    }

    public function testUnsupportedContextRecordsDistinctEventNotFailure(): void
    {
        ValidatorMsgActivationRepository::createFirstActivation(self::PAGE_ID, self::ENTITY_ID, self::OPERATOR_ID);
        ValidatorMsgQueueRepository::enqueue(self::PAGE_ID, self::SENDER_ID, 'ctx test body');

        $now = time();
        ValidatorMsgQueueWorker::handleDeliver(self::PAGE_ID);

        self::assertGreaterThanOrEqual(
            1,
            DegradationMetrics::readEvent('validator_messaging', 'delivery_context_unsupported', $now),
            'the distinct unsupported-context event must be recorded'
        );
        self::assertSame(
            0,
            DegradationMetrics::readEvent('validator_messaging', 'delivery_failed_terminal', $now),
            'an unsupported context must NOT masquerade as a delivery failure'
        );
    }

    public function testCooldownCollapsesRepeatedUnsupportedInvocations(): void
    {
        ValidatorMsgActivationRepository::createFirstActivation(self::PAGE_ID, self::ENTITY_ID, self::OPERATOR_ID);
        ValidatorMsgQueueRepository::enqueue(self::PAGE_ID, self::SENDER_ID, 'ctx test body');

        $now = time();
        ValidatorMsgQueueWorker::handleDeliver(self::PAGE_ID);
        ValidatorMsgQueueWorker::handleDeliver(self::PAGE_ID);
        ValidatorMsgQueueWorker::handleDeliver(self::PAGE_ID);

        self::assertSame(
            1,
            DegradationMetrics::readEvent('validator_messaging', 'delivery_context_unsupported', $now),
            'the cooldown must collapse repeated unsupported invocations to a single signal'
        );
    }

    private function row(int $id): object
    {
        global $wpdb;
        $r = $wpdb->get_row('SELECT * FROM `' . $wpdb->prefix . 'bcc_validator_msg_queue` WHERE id = ' . (int) $id);
        self::assertNotNull($r, 'queue row must exist');
        return $r;
    }
}
