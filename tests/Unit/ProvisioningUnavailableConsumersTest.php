<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use BCC\Trust\Onchain\Services\GatedGroupProvisioningService;
use BCC\Trust\Onchain\ValueObjects\ProvisioningFailureCode;
use BCC\Trust\Onchain\ValueObjects\ProvisioningState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * An honest producer is worth nothing if its consumers launder the answer.
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────
 * `provisionOne()` was fixed to return `status = 'unavailable'` when the
 * provisioning row could not be read. Both callers then mishandled it, each
 * re-creating the original defect one layer out:
 *
 *   - `processRequested()` had no `unavailable` case, so it fell into
 *     `default: $skipped++`, kept iterating, advanced the cursor past the
 *     row, and returned `available => true`. A sweep that learned nothing
 *     reported a clean run — the exact failure the producer fix removed.
 *
 *   - `ajax_provision_one()` matched neither its failed/skipped/unconfirmed
 *     branch nor anything else, and fell through to
 *     `wp_send_json_success()`. An operator whose database had just declined
 *     to answer was told the action had worked.
 *
 * ── WHY THE QUEUE READ MUST SUCCEED IN THESE TESTS ──────────────────────
 * The scenario is a database that degrades DURING a sweep: the queue SELECT
 * returns rows, and the per-row re-read then fails. If the queue read failed
 * too, `processRequested()` would bail at the earlier guard and never reach
 * the switch — so the bug under test would be unobservable and these tests
 * would pass against the broken code. The repository fake was changed to
 * keep the two reads independent, which is how they already are in
 * production (two separate SELECTs).
 */
#[CoversClass(GatedGroupProvisioningService::class)]
#[CoversClass(VerifyCollectionsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProvisioningUnavailableConsumersTest extends TestCase
{
    private const CID       = 42;
    private const OTHER_CID = 43;
    private const OPERATOR  = 7;
    private const ADDRESS   = '0x1234567890abcdef1234567890abcdef12345678';
    private const ADDRESS_2 = '0xabcdefabcdefabcdefabcdefabcdefabcdefabcd';

    private GatedGroupProvisioningService $service;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/provisioning-intent-stubs.php';
        require_once __DIR__ . '/../Stubs/peepso-group-fakes.php';
        require_once __DIR__ . '/../Stubs/onchain-admin-render-stubs.php';

        \BccAdminTestState::reset();
        \BccAjaxRecorder::reset();
        \BccObjectCacheSpy::reset();
        \BccPeepSoSpy::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Core\Observability\DegradationMetrics::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Core\Security\TransactionManager::reset();
        \BCC\Trust\Onchain\Repositories\CollectionRepository::reset();
        \BCC\Trust\Onchain\Repositories\GatedGroupRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();

        $_POST = [];
        $_GET  = [];

        $this->service = new GatedGroupProvisioningService();
    }

    private function seedRequested(int $id, string $address): void
    {
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(4, 'ethereum', 'evm');
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[$id] = (object) [
            'id'                   => $id,
            'chain_id'             => 4,
            'contract_address'     => $address,
            'canonical_identifier' => $address,
            'collection_name'      => 'Seeded Collection ' . $id,
            'is_verified'          => 1,
            'chain_type'           => 'evm',
            'chain_slug'           => 'ethereum',
        ];
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$provisioning[$id] = [
            'state' => ProvisioningState::REQUESTED,
            'at'    => '2026-09-01 00:00:00',
            'by'    => self::OPERATOR,
            'code'  => null,
        ];
    }

    // ────────────────────────────────────────────────────────────────────
    // 1. The batch consumer: processRequested()
    // ────────────────────────────────────────────────────────────────────

    /**
     * The control FIRST. Without proving the queue still hands out this row
     * while the row-read fault is armed, every assertion below could be
     * passing because the sweep found nothing to do.
     */
    public function testTheQueueStillYieldsTheRowWhileTheRowReadIsFaulted(): void
    {
        $this->seedRequested(self::CID, self::ADDRESS);
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$readRowUnavailable = true;

        $queue = \BCC\Trust\Onchain\Repositories\CollectionRepository::listRequested(0, 50);

        self::assertTrue($queue['available'], 'the QUEUE read is fine; only the per-row read fails');
        self::assertCount(1, $queue['rows'], 'the sweep must actually reach a row');
        self::assertSame(self::CID, (int) $queue['rows'][0]->id);
    }

    public function testAnUnreadableRowStopsTheSweepAndReportsUnavailable(): void
    {
        $this->seedRequested(self::CID, self::ADDRESS);
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$readRowUnavailable = true;

        $result = $this->service->processRequested();

        self::assertFalse(
            $result['available'],
            'a sweep that could not read a queued row has not completed'
        );
    }

    public function testTheRowReadFailureIsReportedAsABoundedOperationalError(): void
    {
        $this->seedRequested(self::CID, self::ADDRESS);
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$readRowUnavailable = true;

        $result = $this->service->processRequested();

        self::assertContains(
            GatedGroupProvisioningService::ERROR_ROW_UNREADABLE,
            $result['errors']
        );

        // The collection is not named, and not blamed. Nothing is known to be
        // wrong with it, and an id here would send someone to inspect a row
        // that is fine.
        foreach ($result['errors'] as $error) {
            self::assertStringNotContainsString('collection ' . self::CID, (string) $error);
            self::assertStringNotContainsString((string) self::CID, (string) $error);
        }
    }

    /**
     * Not created, not skipped, not failed. `default: $skipped++` used to
     * absorb it, which is how a row nobody could read became "nothing to do".
     */
    public function testTheUnreadableRowIsCountedInNoBucket(): void
    {
        $this->seedRequested(self::CID, self::ADDRESS);
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$readRowUnavailable = true;

        $result = $this->service->processRequested();

        self::assertSame(0, $result['created'], 'nothing was created');
        self::assertSame(0, $result['skipped'], 'an unreadable row is NOT a skip');
        self::assertSame(0, $result['failed'], 'and nothing about the collection failed');
    }

    public function testNothingIsWrittenOrCalledForAnUnreadableRow(): void
    {
        $this->seedRequested(self::CID, self::ADDRESS);
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$readRowUnavailable = true;

        $this->service->processRequested();

        self::assertSame(0, \BccPeepSoSpy::$created, 'no community may be created');
        self::assertSame(0, \BccPeepSoSpy::$deleted);
        self::assertSame(0, \BccPeepSoSpy::$lastGroupId, 'no group was even touched');
        self::assertSame(
            [],
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$stateWrites,
            'no state may be written about a row we could not read'
        );
        self::assertSame(
            [],
            \BCC\Trust\Core\Security\AuditLogger::$rows,
            'and nothing durable may be recorded'
        );
        self::assertSame(
            [],
            \BCC\Trust\Onchain\Repositories\GatedGroupRepository::$groups,
            'no gate may be written'
        );

        // ── The provider boundary ───────────────────────────────────────
        // Asserted at the harness level rather than with a counter, because
        // this stub set declares NO HTTP function at all. Any outbound call
        // on this path would therefore be an undefined-function fatal and
        // this test would error rather than pass — which is what makes "no
        // provider was contacted" enforceable here instead of decorative.
        // Pinning that property stops a future stub from quietly adding
        // `wp_remote_get()` and turning the guarantee back into a comment.
        foreach (['wp_remote_get', 'wp_remote_post', 'wp_remote_request'] as $http) {
            self::assertFalse(
                function_exists($http),
                $http . '() is declared, so a provider call here would no longer fatal — '
                    . 'this test needs a real spy before that stub is added'
            );
        }
    }

    /** PR 6 adds no degradation metric for an infrastructure fault. */
    public function testNoDegradationMetricIsEmittedForAnUnreadableRow(): void
    {
        $this->seedRequested(self::CID, self::ADDRESS);
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$readRowUnavailable = true;

        $this->service->processRequested();

        self::assertSame([], \BCC\Core\Observability\DegradationMetrics::$recorded);
    }

    /**
     * Work already completed in this run is REPORTED, not discarded.
     *
     * Those communities really were created. Zeroing the counts because a
     * later row could not be read would misreport a partial run as one that
     * never started, and would send an operator looking for communities the
     * sweep had in fact made.
     */
    public function testCountsForEarlierCompletedWorkArePreserved(): void
    {
        $this->seedRequested(self::CID, self::ADDRESS);
        $this->seedRequested(self::OTHER_CID, self::ADDRESS_2);

        // The first row provisions normally; the fault arms only once the
        // sweep asks for the second.
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$readRowUnavailableAfter = self::CID;

        $result = $this->service->processRequested();

        self::assertSame(1, \BccPeepSoSpy::$created, 'the first row really did get a community');
        self::assertSame(1, $result['created'], 'and the run says so');
        self::assertFalse($result['available'], 'while still reporting it did not finish');
        self::assertContains(
            GatedGroupProvisioningService::ERROR_ROW_UNREADABLE,
            $result['errors']
        );
        self::assertSame(0, $result['failed'], 'the second row did not fail — it was unreadable');
    }

    /** The healthy path is unchanged: a readable queue drains and reports available. */
    public function testAHealthySweepStillCompletesAndReportsAvailable(): void
    {
        $this->seedRequested(self::CID, self::ADDRESS);

        $result = $this->service->processRequested();

        self::assertTrue($result['available']);
        self::assertSame(1, $result['created']);
        self::assertSame([], $result['errors']);
    }

    // ────────────────────────────────────────────────────────────────────
    // 2. The interactive consumer: ajax_provision_one()
    // ────────────────────────────────────────────────────────────────────

    /**
     * Run an AJAX handler to completion.
     *
     * In production `wp_send_json_*()` calls `wp_die()`, which EXITS. The
     * in-process shim can only throw, so the terminator is absorbed here and
     * assertions run against what production would have sent.
     */
    private function invokeAjax(): \BccAjaxResponse
    {
        try {
            VerifyCollectionsPage::ajax_provision_one();
        } catch (\BccAjaxResponse $r) {
            return $r;
        }

        self::fail('the handler returned without sending a JSON response');
    }

    private function armAjax(int $collectionId): void
    {
        \BccAdminTestState::$can = true;
        \BccAdminTestState::$validNonceAction =
            VerifyCollectionsPage::AJAX_ACTION_PROVISION . '_' . $collectionId;
        $_POST['collection_id'] = $collectionId;
        $_POST['nonce']         = 'x';
    }

    public function testTheAjaxRouteReturnsAnErrorWhenTheRowCannotBeRead(): void
    {
        $this->seedRequested(self::CID, self::ADDRESS);
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$readRowUnavailable = true;
        $this->armAjax(self::CID);

        $response = $this->invokeAjax();

        self::assertFalse(
            $response->success,
            'an unreadable row must never be reported to an operator as success'
        );
    }

    /**
     * And the message tells the operator the two things that matter: nothing
     * happened, and it is worth retrying. A bounded string — no SQL, no
     * driver text, no path.
     */
    public function testTheAjaxErrorMessageIsBoundedAndSaysNothingHappened(): void
    {
        $this->seedRequested(self::CID, self::ADDRESS);
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$readRowUnavailable = true;
        $this->armAjax(self::CID);

        $message = $this->invokeAjax()->message();

        self::assertStringContainsString('try again', strtolower($message));
        self::assertStringNotContainsString('SELECT', $message);
        self::assertStringNotContainsString('injected', $message);
        self::assertStringNotContainsString('\\', $message, 'no namespace or path may leak');
    }

    /**
     * ⚠ THE ONE THAT MATTERS MOST HERE.
     *
     * `admin_vc_community_provision_failed` is a durable claim that
     * provisioning was attempted and refused FOR THIS COLLECTION. The read
     * established nothing of the sort — no PeepSo call, no state write — and
     * the collection may be in perfect order. That row is what someone reads
     * months later when asking why a community was refused, so writing it for
     * a database hiccup puts a permanent lie in the activity log.
     */
    public function testTheAjaxRouteWritesNoDurableAuditRowForAnInfrastructureFailure(): void
    {
        $this->seedRequested(self::CID, self::ADDRESS);
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$readRowUnavailable = true;
        $this->armAjax(self::CID);

        $this->invokeAjax();

        self::assertSame(
            [],
            \BCC\Trust\Core\Security\AuditLogger::$rows,
            'no durable row may be written for a read that reached no conclusion'
        );
        self::assertSame(0, \BccPeepSoSpy::$created);
        self::assertSame(
            [],
            \BCC\Trust\Onchain\Repositories\CollectionRepository::$stateWrites
        );
    }

    /**
     * The contrast case, so the assertion above is about `unavailable` and
     * not about auditing being broken everywhere: a genuine collection-level
     * refusal STILL writes its durable row.
     */
    public function testAGenuineRefusalStillWritesItsDurableAuditRow(): void
    {
        $this->seedRequested(self::CID, self::ADDRESS);

        // Verified is re-checked at provision time; withdrawing it produces a
        // real, collection-level refusal rather than an infrastructure fault.
        \BCC\Trust\Onchain\Repositories\CollectionRepository::$rows[self::CID]->is_verified = 0;
        $this->armAjax(self::CID);

        $response = $this->invokeAjax();

        self::assertFalse($response->success);

        $actions = \BCC\Trust\Core\Security\AuditLogger::actions();
        self::assertContains(
            'admin_vc_community_provision_failed',
            $actions,
            'a real refusal IS a durable statement about this collection'
        );

        $failed = array_values(array_filter(
            \BCC\Trust\Core\Security\AuditLogger::$rows,
            static fn (array $r): bool => $r['action'] === 'admin_vc_community_provision_failed'
        ));
        self::assertSame(
            ProvisioningFailureCode::NOT_VERIFIED,
            $failed[0]['meta']['failure_code']
        );
    }

    /** The healthy AJAX path is unchanged. */
    public function testAHealthyAjaxProvisionStillReturnsSuccess(): void
    {
        $this->seedRequested(self::CID, self::ADDRESS);
        $this->armAjax(self::CID);

        $response = $this->invokeAjax();

        self::assertTrue($response->success);
        self::assertSame('created', $response->data['status'] ?? null);
    }
}
