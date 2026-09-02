<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\GatedGroupProvisioningService;
use BCC\Trust\Onchain\ValueObjects\ProvisioningFailureCode;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A database that did not answer is not an empty queue.
 *
 * ── THE FAILURE CLASS ───────────────────────────────────────────────────
 * `wpdb::get_results()` returns `[]` for a genuine no-rows result AND for a
 * failed query — it hands back `last_result`, and returns null only for an
 * empty query string. So `return $rows ?: [];` collapses "nothing to do"
 * into "could not ask", and a sweep whose SELECT never executed reports a
 * clean zero. Nothing errors, nothing alerts, and the queue silently stops
 * draining.
 *
 * This is the same defect issue #225 describes for collection discovery,
 * one layer in: seven outcomes collapsing into one empty array. It matters
 * more here because the caller's next move is to create communities.
 *
 * ── WHAT THE FIX HAS TO ACHIEVE ─────────────────────────────────────────
 * Not "log it" — the sweep must not proceed AS IF the answer were zero. No
 * PeepSo call, no provider call, and a bounded operational error the caller
 * can distinguish from "no request" and from a substantive refusal.
 */
#[CoversClass(GatedGroupProvisioningService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProvisioningReadFailureTest extends TestCase
{
    private const CID      = 42;
    private const OPERATOR = 7;
    private const ADDRESS  = '0x1234567890abcdef1234567890abcdef12345678';

    private GatedGroupProvisioningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/provisioning-intent-stubs.php';
        require_once __DIR__ . '/../Stubs/peepso-group-fakes.php';

        \BccAdminTestState::reset();
        \BccObjectCacheSpy::reset();
        \BccPeepSoSpy::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Core\Observability\DegradationMetrics::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Core\Security\TransactionManager::reset();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::reset();
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();

        $this->service = new GatedGroupProvisioningService();
    }

    private function seedRequested(): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(4, 'ethereum', 'evm');
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID] = (object) [
            'id'                   => self::CID,
            'chain_id'             => 4,
            'contract_address'     => self::ADDRESS,
            'canonical_identifier' => self::ADDRESS,
            'collection_name'      => 'Seeded Collection',
            'is_verified'          => 1,
            'chain_type'           => 'evm',
            'chain_slug'           => 'ethereum',
        ];
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID] = [
            'state' => ProvisioningState::REQUESTED,
            'at'    => '2026-09-01 00:00:00',
            'by'    => self::OPERATOR,
            'code'  => null,
        ];
    }

    // ── The queue read ──────────────────────────────────────────────────

    /**
     * The headline case. A failed queue read must not look like a clean run.
     */
    public function testAFailedQueueReadIsNotReportedAsAnEmptySuccessfulSweep(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$listRequestedUnavailable = true;

        $result = $this->service->processRequested();

        self::assertFalse(
            $result['available'],
            'a sweep whose SELECT never executed must not report success'
        );
        self::assertNotSame(
            [],
            $result['errors'],
            'the run has to say something happened'
        );
        self::assertSame(0, $result['created']);
    }

    public function testAFailedQueueReadMakesNoPeepSoCall(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$listRequestedUnavailable = true;

        $this->service->processRequested();

        self::assertSame(
            0,
            \BccPeepSoSpy::$created,
            'nothing may be created on the strength of an answer we never got'
        );
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
    }

    /**
     * The bounded error names the READ, not a collection. Attributing an
     * infrastructure failure to a specific collection would send an operator
     * to inspect a row that is fine.
     */
    public function testTheQueueReadFailureIsReportedAsABoundedOperationalError(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$listRequestedUnavailable = true;

        $result = $this->service->processRequested();

        self::assertContains(
            GatedGroupProvisioningService::ERROR_QUEUE_UNREADABLE,
            $result['errors']
        );
        foreach ($result['errors'] as $error) {
            self::assertStringNotContainsString('collection ' . self::CID, (string) $error);
        }
    }

    /** A genuinely empty queue is still a clean, available run. */
    public function testAGenuinelyEmptyQueueIsAvailableAndClean(): void
    {
        // Nothing seeded: no requested rows exist.
        $result = $this->service->processRequested();

        self::assertTrue($result['available'], 'an empty queue is a successful read');
        self::assertSame([], $result['errors']);
        self::assertSame(0, $result['created']);
    }

    public function testTheDatabaseFailureIsLoggedToTheApplicationLog(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$listRequestedUnavailable = true;

        $this->service->processRequested();

        self::assertNotSame(
            [],
            \BCC\Core\Log\Logger::ofLevel('error'),
            'a read failure belongs in the error log'
        );
    }

    // ── The single-row read ─────────────────────────────────────────────

    /**
     * `readProvisioningRow()` returning null meant BOTH "no such collection"
     * and "the query failed". The first is a substantive answer about a
     * collection; the second is an infrastructure fault, and treating it as
     * "not found" would let a sweep quietly skip rows a flaky database
     * refused to hand back.
     */
    public function testAFailedRowReadIsNotReportedAsCollectionNotFound(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$readRowUnavailable = true;

        $result = $this->service->provisionOne(self::CID);

        self::assertNotSame(
            'skipped',
            $result['status'],
            'an unreadable row must not be reported as an absent collection'
        );
        self::assertSame(0, \BccPeepSoSpy::$created);
    }

    public function testAFailedRowReadMakesNoStateWriteAndNoAudit(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$readRowUnavailable = true;

        $this->service->provisionOne(self::CID);

        self::assertSame(
            [],
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$stateWrites,
            'nothing may be written about a row we could not read'
        );
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::$rows);
    }

    /**
     * And it is NOT recorded as a substantive collection failure. Marking a
     * perfectly good collection `failed` because the database hiccupped
     * would need an operator to notice and undo it.
     */
    public function testAFailedRowReadDoesNotMarkTheCollectionFailed(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$readRowUnavailable = true;

        $result = $this->service->provisionOne(self::CID);

        self::assertSame(
            ProvisioningState::REQUESTED,
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[self::CID]['state'],
            'the collection stays queued; nothing about it went wrong'
        );

        if (isset($result['failure_code']) && $result['failure_code'] !== null) {
            self::assertNotContains(
                $result['failure_code'],
                ProvisioningFailureCode::all(),
                'an infrastructure fault must not borrow a collection-level failure code'
            );
        }
    }

    /** PR 6 adds no degradation metric for this — the brief forbids it. */
    public function testNoNewDegradationMetricIsEmittedForAReadFailure(): void
    {
        $this->seedRequested();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$listRequestedUnavailable = true;

        $this->service->processRequested();

        self::assertSame(
            [],
            \BCC\Core\Observability\DegradationMetrics::$recorded,
            'PR 6 adds no event; the taxonomy is bcc-core-owned'
        );
    }
}
