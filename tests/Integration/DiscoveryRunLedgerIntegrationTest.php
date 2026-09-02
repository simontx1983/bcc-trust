<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunStatus;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;
use PHPUnit\Framework\TestCase;

/**
 * The ledger against a REAL MySQL.
 *
 * ── WHY THIS CANNOT BE A UNIT TEST ──────────────────────────────────────
 * Every claim here is about what the SERVER does. `uq_active` treating
 * NULLs as distinct, a compare-and-swap UPDATE matching exactly one row,
 * `lease_expires_at < UTC_TIMESTAMP()` on the server's clock rather than
 * PHP's, and a DELETE that must spare the newest success and failure — none
 * of that is observable through a double, which will happily agree with
 * whatever the code believes.
 */
final class DiscoveryRunLedgerIntegrationTest extends TestCase
{
    private const CHAIN = 4242;
    private const OP    = 7;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wpdb']->query('DELETE FROM `' . DiscoveryRunRepository::table() . '`');
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->query('DELETE FROM `' . DiscoveryRunRepository::table() . '`');
        parent::tearDown();
    }

    /** @return array{id: int, run_uuid: string} */
    private function insert(int $chainId = self::CHAIN, string $mode = DiscoveryScanMode::HISTORICAL): array
    {
        $created = DiscoveryRunRepository::insertQueued(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            $mode,
            $chainId,
            self::OP
        );

        self::assertNotNull($created, 'the fixture insert must succeed');

        return $created;
    }

    private function expireLease(int $runId): void
    {
        $t = DiscoveryRunRepository::table();
        $GLOBALS['wpdb']->query(
            "UPDATE `{$t}` SET lease_expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) WHERE id = {$runId}"
        );
    }

    // ── Schema shape ────────────────────────────────────────────────────

    public function testTheLedgerCarriesNoMarketColumn(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        // The integration shim's get_results() takes SQL only and returns
        // objects — no ARRAY_N mode.
        $cols = $wpdb->get_results($wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s",
            DiscoveryRunRepository::table()
        ));

        $names = array_map(
            static fn(object $r): string => strtolower((string) $r->COLUMN_NAME),
            (array) $cols
        );
        self::assertNotSame([], $names, 'the table must exist');

        // BCC is never a price platform. The ledger records counts of WORK.
        foreach (['floor', 'price', 'volume', 'listed', 'royalty', 'sale', 'currency', 'usd'] as $forbidden) {
            foreach ($names as $name) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $name,
                    "the run ledger must contain no market column, found: {$name}"
                );
            }
        }
    }

    public function testTheAuditDegradedColumnExistsAndDefaultsToZero(): void
    {
        $created = $this->insert();
        $row = DiscoveryRunRepository::findById($created['id']);

        self::assertNotNull($row);
        self::assertSame(0, (int) $row->audit_degraded);

        self::assertTrue(DiscoveryRunRepository::markAuditDegraded($created['id']));
        self::assertSame(1, (int) DiscoveryRunRepository::findById($created['id'])->audit_degraded);
    }

    /** A non-retry run must carry SQL NULL, not the integer 0. */
    public function testRetryOfRunIdIsNullNotZeroForANormalRun(): void
    {
        $created = $this->insert();
        $t = DiscoveryRunRepository::table();

        $isNull = (int) $GLOBALS['wpdb']->get_var(
            "SELECT retry_of_run_id IS NULL FROM `{$t}` WHERE id = {$created['id']}"
        );

        self::assertSame(1, $isNull, 'prepare() renders null as 0 for %d — the helper must prevent that');
    }

    // ── Active-run uniqueness ───────────────────────────────────────────

    public function testOnlyOneActiveRunPerChainAndJobKind(): void
    {
        $this->insert();

        $second = DiscoveryRunRepository::insertQueued(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            DiscoveryScanMode::HISTORICAL,
            self::CHAIN,
            self::OP
        );

        self::assertNull($second, 'uq_active must refuse the second active run');
    }

    /** Historical and incremental must exclude each other — same checkpoint columns. */
    public function testTheTwoScanModesCannotBeActiveTogether(): void
    {
        $this->insert(self::CHAIN, DiscoveryScanMode::HISTORICAL);

        $second = DiscoveryRunRepository::insertQueued(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            DiscoveryScanMode::INCREMENTAL,
            self::CHAIN,
            self::OP
        );

        self::assertNull($second, 'scan_mode must NOT be part of uq_active');
    }

    /** Different job kinds are independent — they own different columns. */
    public function testDifferentJobKindsDoNotExcludeEachOther(): void
    {
        $this->insert();

        $other = DiscoveryRunRepository::insertQueued(
            DiscoveryJobKind::EVM_INDEXER,
            DiscoveryScanMode::INCREMENTAL,
            self::CHAIN,
            self::OP
        );

        self::assertNotNull($other, 'job kinds are isolated by the unique key');
    }

    /** Terminal history is unlimited — NULLs are distinct in a unique index. */
    public function testManyTerminalRunsCoexistForOneTarget(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $run = $this->insert();
            self::assertTrue(DiscoveryRunRepository::markCancelled($run['id']));
        }

        $t = DiscoveryRunRepository::table();
        $count = (int) $GLOBALS['wpdb']->get_var(
            "SELECT COUNT(*) FROM `{$t}` WHERE chain_id = " . self::CHAIN
        );

        self::assertSame(4, $count);
        self::assertNull(DiscoveryRunRepository::findActive(DiscoveryJobKind::COSMWASM_DISCOVERY, self::CHAIN));
    }

    // ── Claiming ────────────────────────────────────────────────────────

    public function testAClaimSucceedsOnceAndBumpsTheAttempt(): void
    {
        $run = $this->insert();

        $token = DiscoveryRunRepository::claim($run['id']);
        self::assertNotNull($token);

        $row = DiscoveryRunRepository::findById($run['id']);
        self::assertSame(DiscoveryRunStatus::RUNNING, (string) $row->status);
        self::assertSame(1, (int) $row->attempt_count);
        self::assertNotNull($row->started_at);
    }

    public function testASecondClaimOnARunningRunLoses(): void
    {
        $run = $this->insert();
        DiscoveryRunRepository::claim($run['id']);

        self::assertNull(
            DiscoveryRunRepository::claim($run['id']),
            'the compare-and-swap must admit exactly one claimant'
        );
    }

    /**
     * ⚠ The EXECUTOR never reclaims an expired lease — that is the reaper's
     * job. Folding them together would make `attempt_count` mean two things.
     */
    public function testTheClaimDoesNotReclaimAnExpiredLease(): void
    {
        $run = $this->insert();
        DiscoveryRunRepository::claim($run['id']);
        $this->expireLease($run['id']);

        self::assertNull(
            DiscoveryRunRepository::claim($run['id']),
            'an expired running row is for the reaper, not a second claim'
        );
    }

    public function testAnExhaustedRunCannotBeClaimedAgain(): void
    {
        $run = $this->insert();
        $t = DiscoveryRunRepository::table();
        $GLOBALS['wpdb']->query(
            "UPDATE `{$t}` SET attempt_count = " . DiscoveryRunRepository::MAX_ATTEMPTS . " WHERE id = {$run['id']}"
        );

        self::assertNull(DiscoveryRunRepository::claim($run['id']));
    }

    // ── Lease expiry and recovery ───────────────────────────────────────

    /** A lease expiry is NOT an attempt. The claim already counted it. */
    public function testRequeueingAnExpiredLeaseDoesNotBumpTheAttempt(): void
    {
        $run = $this->insert();
        DiscoveryRunRepository::claim($run['id']);
        $before = (int) DiscoveryRunRepository::findById($run['id'])->attempt_count;

        $this->expireLease($run['id']);
        self::assertTrue(DiscoveryRunRepository::requeueExpiredLease($run['id']));

        $row = DiscoveryRunRepository::findById($run['id']);
        self::assertSame(DiscoveryRunStatus::QUEUED, (string) $row->status);
        self::assertSame($before, (int) $row->attempt_count, 'a dead worker is not the run\'s fault');
        self::assertNotNull($row->next_retry_at, 'backoff must be set');
    }

    public function testALiveLeaseIsNotRequeued(): void
    {
        $run = $this->insert();
        DiscoveryRunRepository::claim($run['id']);

        self::assertFalse(
            DiscoveryRunRepository::requeueExpiredLease($run['id']),
            'a lease that has not expired must be left alone'
        );
    }

    /** Backoff must actually delay the next claim. */
    public function testAReQueuedRunIsNotImmediatelyDispatchable(): void
    {
        $run = $this->insert();
        DiscoveryRunRepository::claim($run['id']);
        $this->expireLease($run['id']);
        DiscoveryRunRepository::requeueExpiredLease($run['id']);

        $ids = array_map(
            static fn(object $r): int => (int) $r->id,
            DiscoveryRunRepository::findDispatchable(50)
        );

        self::assertNotContains($run['id'], $ids, 'next_retry_at must hold it back');
        self::assertNull(DiscoveryRunRepository::claim($run['id']));
    }

    public function testAnExhaustedExpiredRunIsTerminalized(): void
    {
        $run = $this->insert();
        $t = DiscoveryRunRepository::table();
        DiscoveryRunRepository::claim($run['id']);
        $GLOBALS['wpdb']->query(
            "UPDATE `{$t}` SET attempt_count = " . DiscoveryRunRepository::MAX_ATTEMPTS . " WHERE id = {$run['id']}"
        );
        $this->expireLease($run['id']);

        self::assertTrue(DiscoveryRunRepository::terminalizeExhausted($run['id']));

        $row = DiscoveryRunRepository::findById($run['id']);
        self::assertSame(DiscoveryRunStatus::FAILED, (string) $row->status);
        self::assertSame(DiscoveryRunError::MAX_ATTEMPTS_EXHAUSTED, (string) $row->error_code);
        self::assertNull($row->active_marker, 'the slot must be freed');
    }

    public function testANonExhaustedExpiredRunIsNotTerminalized(): void
    {
        $run = $this->insert();
        DiscoveryRunRepository::claim($run['id']);
        $this->expireLease($run['id']);

        self::assertFalse(
            DiscoveryRunRepository::terminalizeExhausted($run['id']),
            'a run with attempts left must be requeued, not failed'
        );
    }

    // ── Terminal writes ─────────────────────────────────────────────────

    public function testASuccessfulTerminalWriteFreesTheSlotAndStoresCounts(): void
    {
        $run = $this->insert();
        $token = DiscoveryRunRepository::claim($run['id']);

        self::assertTrue(DiscoveryRunRepository::markSucceeded(
            $run['id'],
            (string) $token,
            'pass_completed',
            false,
            ['collections_emitted' => 5, 'families_seen' => 3]
        ));

        $row = DiscoveryRunRepository::findById($run['id']);
        self::assertSame(DiscoveryRunStatus::SUCCEEDED, (string) $row->status);
        self::assertNull($row->active_marker);
        self::assertSame(5, (int) $row->collections_emitted);
        self::assertSame(3, (int) $row->families_seen);
        self::assertSame(0, (int) $row->partial);
    }

    /**
     * ⚠ THE ONE THAT MUST NEVER BE REPORTED AS SUCCESS.
     * A stale token means we no longer own the row. The write must not land
     * and the caller must be told so, or a run someone else is executing
     * gets a terminal result written over it.
     */
    public function testATerminalWriteWithAStaleLeaseTokenIsRefused(): void
    {
        $run = $this->insert();
        DiscoveryRunRepository::claim($run['id']);

        self::assertFalse(
            DiscoveryRunRepository::markSucceeded($run['id'], 'not-our-token', 'pass_completed', false),
            'a stale token must not write a terminal result'
        );

        self::assertSame(
            DiscoveryRunStatus::RUNNING,
            (string) DiscoveryRunRepository::findById($run['id'])->status,
            'and the run must stay running so its lease can expire'
        );
    }

    public function testABudgetStopIsRecordedAsSuccessWithPartial(): void
    {
        $run = $this->insert();
        $token = DiscoveryRunRepository::claim($run['id']);

        DiscoveryRunRepository::markSucceeded($run['id'], (string) $token, 'runtime_deadline_reached', true);

        $row = DiscoveryRunRepository::findById($run['id']);
        self::assertSame(DiscoveryRunStatus::SUCCEEDED, (string) $row->status, 'a ceiling is not a failure');
        self::assertSame(1, (int) $row->partial);
        self::assertSame('runtime_deadline_reached', (string) $row->stop_reason);
    }

    public function testAnInvalidErrorCodeIsRefused(): void
    {
        $run = $this->insert();
        $token = DiscoveryRunRepository::claim($run['id']);

        self::assertFalse(
            DiscoveryRunRepository::markFailed($run['id'], (string) $token, 'something_invented'),
            'only the closed vocabulary may become durable'
        );
    }

    public function testCancellingARunningRunIsRefused(): void
    {
        $run = $this->insert();
        DiscoveryRunRepository::claim($run['id']);

        self::assertFalse(
            DiscoveryRunRepository::markCancelled($run['id']),
            'a leased run may be mid-provider-call; only a queued run may be withdrawn'
        );
    }

    // ── Retention ───────────────────────────────────────────────────────

    /**
     * Pruning must spare an ACTIVE run and the newest success and failure —
     * otherwise the status read model's last-success/last-failure go blank.
     */
    public function testPruningSparesActiveRunsAndTheNewestTerminalOfEachKind(): void
    {
        $t = DiscoveryRunRepository::table();

        // Three old successes and two old failures, all beyond retention.
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $run = $this->insert();
            $token = DiscoveryRunRepository::claim($run['id']);
            DiscoveryRunRepository::markSucceeded($run['id'], (string) $token, 'pass_completed', false);
            $ids[] = $run['id'];
        }
        for ($i = 0; $i < 2; $i++) {
            $run = $this->insert();
            $token = DiscoveryRunRepository::claim($run['id']);
            DiscoveryRunRepository::markFailed($run['id'], (string) $token, DiscoveryRunError::EXECUTION_FAILED);
            $ids[] = $run['id'];
        }

        $GLOBALS['wpdb']->query(
            "UPDATE `{$t}` SET finished_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 400 DAY) WHERE id IN ("
            . implode(',', $ids) . ')'
        );

        // And one live run that must survive regardless of age.
        $active = $this->insert();

        DiscoveryRunRepository::pruneTerminal(200);

        self::assertNotNull(
            DiscoveryRunRepository::findById($active['id']),
            'an active run must never be pruned'
        );
        self::assertNotNull(
            DiscoveryRunRepository::findLatestByStatus(DiscoveryJobKind::COSMWASM_DISCOVERY, self::CHAIN, DiscoveryRunStatus::SUCCEEDED),
            'the newest success must survive so last_succeeded never goes blank'
        );
        self::assertNotNull(
            DiscoveryRunRepository::findLatestByStatus(DiscoveryJobKind::COSMWASM_DISCOVERY, self::CHAIN, DiscoveryRunStatus::FAILED),
            'the newest failure must survive'
        );

        $remaining = (int) $GLOBALS['wpdb']->get_var("SELECT COUNT(*) FROM `{$t}`");
        self::assertLessThan(6, $remaining, 'something must actually have been pruned');
    }

    public function testRecentTerminalRunsAreNotPruned(): void
    {
        $run = $this->insert();
        $token = DiscoveryRunRepository::claim($run['id']);
        DiscoveryRunRepository::markSucceeded($run['id'], (string) $token, 'pass_completed', false);

        DiscoveryRunRepository::pruneTerminal(200);

        self::assertNotNull(DiscoveryRunRepository::findById($run['id']));
    }

    // ── Dispatchability ─────────────────────────────────────────────────

    public function testOnlyQueuedDueRunsAreDispatchable(): void
    {
        $queued  = $this->insert();
        $running = $this->insert(self::CHAIN + 1);
        DiscoveryRunRepository::claim($running['id']);

        $ids = array_map(
            static fn(object $r): int => (int) $r->id,
            DiscoveryRunRepository::findDispatchable(50)
        );

        self::assertContains($queued['id'], $ids);
        self::assertNotContains($running['id'], $ids);
    }

    // ── Last success / last failure ordering ────────────────────────────

    /**
     * Ordered by `finished_at`, not by id. A run that started earlier can
     * finish later — exactly what happens when one is retried while a newer
     * one completes quickly.
     */
    public function testLatestByStatusOrdersByFinishTimeNotInsertOrder(): void
    {
        $t = DiscoveryRunRepository::table();

        $older = $this->insert();
        $tokenA = DiscoveryRunRepository::claim($older['id']);
        DiscoveryRunRepository::markSucceeded($older['id'], (string) $tokenA, 'pass_completed', false);

        $newer = $this->insert();
        $tokenB = DiscoveryRunRepository::claim($newer['id']);
        DiscoveryRunRepository::markSucceeded($newer['id'], (string) $tokenB, 'pass_completed', false);

        // The LOWER id finished LATER.
        $GLOBALS['wpdb']->query(
            "UPDATE `{$t}` SET finished_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR) WHERE id = {$older['id']}"
        );

        $latest = DiscoveryRunRepository::findLatestByStatus(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            self::CHAIN,
            DiscoveryRunStatus::SUCCEEDED
        );

        self::assertNotNull($latest);
        self::assertSame($older['id'], (int) $latest->id, 'finish time decides, not insert order');
    }
}
