<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Services\DiscoveryScanSession;
use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunStatus;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The multi-chunk session, against the real ledger.
 *
 * ── WHY REAL MySQL AND NOT A DOUBLE ─────────────────────────────────────
 * Everything that makes a session safe is SQL:
 *
 *   - `uq_active (job_kind, chain_id, active_marker)` is what stops a second
 *     session existing beside this one, and a double cannot enforce a unique
 *     index;
 *   - `chunks_used = chunks_used + 1` and the accumulating counts are
 *     expressions the database evaluates, not values PHP computes;
 *   - `claim()`'s compare-and-swap — `status='queued' AND attempt_count < 3
 *     AND (next_retry_at IS NULL OR next_retry_at <= UTC_TIMESTAMP())` — is
 *     the whole idempotency story for a duplicate Action Scheduler delivery.
 *
 * A stub asked "did you enforce that?" would answer whatever it was told.
 */
#[CoversClass(DiscoveryRunRepository::class)]
#[Group('integration')]
final class DiscoverySessionLedgerIntegrationTest extends TestCase
{
    private const CHAIN = 90803;

    private const OPERATOR = 4243;

    protected function setUp(): void
    {
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query('DELETE FROM `' . DiscoveryRunRepository::table() . '` WHERE chain_id = ' . self::CHAIN);
    }

    protected function tearDown(): void
    {
        $this->setUp();
    }

    private function queue(): int
    {
        $created = DiscoveryRunRepository::insertQueued(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            DiscoveryScanMode::INCREMENTAL,
            self::CHAIN,
            self::OPERATOR
        );

        self::assertIsArray($created, 'fixture could not queue a run');

        return (int) $created['id'];
    }

    /** @return array<string, int> */
    private static function counts(int $requests = 48): array
    {
        return [
            'requests_used'       => $requests,
            'pages_fetched'       => 1,
            'families_seen'       => 7,
            'contracts_seen'      => 25,
            'collections_emitted' => 0,
            'collections_denied'  => 0,
        ];
    }

    private function row(int $runId): object
    {
        $row = DiscoveryRunRepository::findById($runId);
        self::assertIsObject($row);

        return $row;
    }

    /**
     * Let the inter-chunk delay elapse, without sleeping.
     *
     * ⚠ THE DELAY IS A REAL GATE, NOT DECORATION.
     * `releaseForNextChunk()` writes `next_retry_at = now + delay`, and
     * `claim()` refuses while that is in the future — which is why the next
     * chunk cannot run back to back even if Action Scheduler fires early.
     * A test that ignored it would sleep 15 seconds per chunk; one that
     * removed it would stop testing the thing that protects the provider.
     * Winding the clock backwards in the row is the honest middle: time
     * passes, every other guard stays exactly as production has it.
     *
     * {@see testAnEarlyDeliveryCannotClaimBeforeTheChunkDelay} is the test
     * that asserts the gate itself, so relaxing it here hides nothing.
     */
    private function elapseChunkDelay(int $runId): void
    {
        $GLOBALS['wpdb']->query(
            'UPDATE `' . DiscoveryRunRepository::table() . '`
                SET next_retry_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND)
              WHERE id = ' . $runId . ' AND next_retry_at IS NOT NULL'
        );
    }

    // ── (1) ONE REQUEST, ONE ROW, MANY CHUNKS ───────────────────────────

    /**
     * Three chunks, one run row, one uuid, one operator.
     *
     * ⚠ THE CENTRAL CLAIM OF PR 7.3. If a chunk ever created a row, this is
     * where it shows up — the count is asserted after every single one.
     */
    public function testASessionRunsManyChunksWithoutEverCreatingASecondRow(): void
    {
        $runId = $this->queue();
        $uuid  = (string) $this->row($runId)->run_uuid;

        for ($chunk = 1; $chunk <= 3; $chunk++) {
            $token = DiscoveryRunRepository::claim($runId);
            self::assertIsString($token, "chunk {$chunk} could not claim");

            self::assertTrue(DiscoveryRunRepository::releaseForNextChunk(
                $runId,
                $token,
                self::counts(),
                1
            ), "chunk {$chunk} could not be released");
            $this->elapseChunkDelay($runId);

            $row = $this->row($runId);

            self::assertSame($chunk, (int) $row->chunks_used, 'chunks_used counts chunks');
            self::assertSame($uuid, (string) $row->run_uuid, 'the uuid is stable across chunks');
            self::assertSame(self::OPERATOR, (int) $row->requested_by, 'one operator, all session');
            self::assertSame(DiscoveryRunStatus::QUEUED, (string) $row->status);
            self::assertNotNull($row->active_marker, 'the session stays the active run');

            self::assertSame(
                1,
                (int) $GLOBALS['wpdb']->get_var(
                    'SELECT COUNT(*) FROM `' . DiscoveryRunRepository::table() . '` WHERE chain_id = ' . self::CHAIN
                ),
                "a second run row appeared during chunk {$chunk}"
            );
        }
    }

    /** Cumulative counts accumulate across chunks and the final terminal write. */
    public function testCumulativeCountsSurviveEveryChunk(): void
    {
        $runId = $this->queue();

        for ($chunk = 0; $chunk < 3; $chunk++) {
            $token = DiscoveryRunRepository::claim($runId);
            self::assertIsString($token);
            self::assertTrue(DiscoveryRunRepository::releaseForNextChunk($runId, $token, self::counts(), 1));
            $this->elapseChunkDelay($runId);
        }

        // The fourth chunk terminalises.
        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::markSucceeded(
            $runId,
            $token,
            DiscoveryScanSession::STOP_CHUNK_CEILING,
            false,
            self::counts()
        ));

        $row = $this->row($runId);

        self::assertSame(4, (int) $row->chunks_used, 'the terminal chunk counts too');
        self::assertSame(4 * 48, (int) $row->requests_used, 'requests accumulate');
        self::assertSame(4 * 7, (int) $row->families_seen, 'families accumulate');
        self::assertSame(4 * 25, (int) $row->contracts_seen, 'contracts accumulate');
        self::assertSame(0, (int) $row->collections_emitted);
        self::assertSame(DiscoveryScanSession::STOP_CHUNK_CEILING, (string) $row->stop_reason);
    }

    /**
     * A single-chunk run ends with exactly the counts it produced.
     *
     * ⚠ THE NO-REGRESSION TEST FOR ACCUMULATION. `markSucceeded()` changed
     * from `col = %d` to `col = col + %d`; for a run that never chunked the
     * two must be indistinguishable, because every counter starts at zero.
     */
    public function testASingleChunkRunIsUnchangedByAccumulation(): void
    {
        $runId = $this->queue();
        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);

        self::assertTrue(DiscoveryRunRepository::markSucceeded(
            $runId,
            $token,
            'pass_completed',
            false,
            self::counts()
        ));

        $row = $this->row($runId);

        self::assertSame(48, (int) $row->requests_used);
        self::assertSame(7, (int) $row->families_seen);
        self::assertSame(1, (int) $row->chunks_used);
        self::assertNull($row->active_marker, 'a terminal run releases the active slot');
    }

    // ── (2) ATTEMPTS ARE PER CHUNK ──────────────────────────────────────

    /**
     * A session outlives MAX_ATTEMPTS chunks.
     *
     * ⚠ THE BUG THIS DESIGN EXISTS TO AVOID. `claim()` refuses at
     * `attempt_count >= 3`, so if chunks consumed attempts every session
     * would die on its fourth chunk with `max_attempts_exhausted`. The
     * release resets the counter because a completed chunk PROVES the worker
     * is alive; the session's own bound is `chunks_used`.
     */
    public function testASessionSurvivesMoreChunksThanThereAreAttempts(): void
    {
        $runId = $this->queue();
        $limit = DiscoveryRunRepository::MAX_ATTEMPTS + 2;

        for ($chunk = 1; $chunk <= $limit; $chunk++) {
            $token = DiscoveryRunRepository::claim($runId);
            self::assertIsString(
                $token,
                "chunk {$chunk} could not claim — attempts are being consumed by chunks"
            );
            self::assertSame(1, (int) $this->row($runId)->attempt_count, 'each chunk starts at one attempt');
            self::assertTrue(DiscoveryRunRepository::releaseForNextChunk($runId, $token, self::counts(), 1));
            self::assertSame(0, (int) $this->row($runId)->attempt_count, 'a released chunk resets attempts');
            $this->elapseChunkDelay($runId);
        }

        self::assertSame($limit, (int) $this->row($runId)->chunks_used);
    }

    /** Within one chunk, three failed claims still terminalise the run. */
    public function testAttemptsStillProtectWithinASingleChunk(): void
    {
        $runId = $this->queue();

        for ($i = 0; $i < DiscoveryRunRepository::MAX_ATTEMPTS; $i++) {
            self::assertIsString(DiscoveryRunRepository::claim($runId), "claim {$i}");
            // The worker vanished: expire the lease by hand and let the
            // reaper return it, exactly as production does.
            $GLOBALS['wpdb']->query(
                'UPDATE `' . DiscoveryRunRepository::table() . '`
                    SET lease_expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
                  WHERE id = ' . $runId
            );
            DiscoveryRunRepository::requeueExpiredLease($runId);
            $this->elapseChunkDelay($runId);
        }

        self::assertSame(DiscoveryRunRepository::MAX_ATTEMPTS, (int) $this->row($runId)->attempt_count);
        self::assertNull(
            DiscoveryRunRepository::claim($runId),
            'a fourth claim within one chunk must be refused'
        );
    }

    // ── (3) IDEMPOTENCY AND RECOVERY ────────────────────────────────────

    /**
     * A duplicate Action Scheduler delivery cannot process a chunk twice.
     *
     * ⚠ Action Scheduler gives at-least-once delivery, so this is not
     * hypothetical. The compare-and-swap in `claim()` is the whole defence.
     */
    public function testADuplicateDeliveryCannotClaimTheSameChunkTwice(): void
    {
        $runId = $this->queue();

        $first = DiscoveryRunRepository::claim($runId);
        self::assertIsString($first);

        self::assertNull(
            DiscoveryRunRepository::claim($runId),
            'the second delivery must not get a lease'
        );

        // And the loser cannot release the row it never held.
        self::assertFalse(DiscoveryRunRepository::releaseForNextChunk(
            $runId,
            'a-token-it-never-held-000000000000000',
            self::counts(),
            1
        ));
        self::assertSame(0, (int) $this->row($runId)->chunks_used, 'no chunk was counted');
    }

    /** The delay gate keeps an early delivery from running the next chunk immediately. */
    public function testAnEarlyDeliveryCannotClaimBeforeTheChunkDelay(): void
    {
        $runId = $this->queue();
        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::releaseForNextChunk($runId, $token, self::counts(), 600));

        self::assertNull(
            DiscoveryRunRepository::claim($runId),
            'next_retry_at must gate the next chunk'
        );
    }

    /** An expired lease returns the run to the SAME session, not a new one. */
    public function testLeaseExpiryRecoversTheSameRun(): void
    {
        $runId = $this->queue();
        $uuid  = (string) $this->row($runId)->run_uuid;

        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::releaseForNextChunk($runId, $token, self::counts(), 1));
        $this->elapseChunkDelay($runId);

        // Chunk two claims, then its worker dies.
        self::assertIsString(DiscoveryRunRepository::claim($runId));
        $GLOBALS['wpdb']->query(
            'UPDATE `' . DiscoveryRunRepository::table() . '`
                SET lease_expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
              WHERE id = ' . $runId
        );
        self::assertTrue(DiscoveryRunRepository::requeueExpiredLease($runId));

        $row = $this->row($runId);
        self::assertSame($uuid, (string) $row->run_uuid, 'recovery resumes the same run');
        self::assertSame(1, (int) $row->chunks_used, 'a dead chunk is not counted as done');
        self::assertSame(
            1,
            (int) $GLOBALS['wpdb']->get_var(
                'SELECT COUNT(*) FROM `' . DiscoveryRunRepository::table() . '` WHERE chain_id = ' . self::CHAIN
            ),
            'recovery must not create a run'
        );
    }

    // ── (4) THE ACTIVE SLOT ─────────────────────────────────────────────

    /**
     * No second session can start for the chain while one is between chunks.
     *
     * ⚠ THE DATABASE ENFORCES THIS, NOT THE SERVICE. `releaseForNextChunk()`
     * leaves `active_marker` set precisely so `uq_active` keeps refusing.
     */
    public function testNoSecondSessionCanStartWhileOneIsBetweenChunks(): void
    {
        $runId = $this->queue();
        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::releaseForNextChunk($runId, $token, self::counts(), 60));

        self::assertNull(
            DiscoveryRunRepository::insertQueued(
                DiscoveryJobKind::COSMWASM_DISCOVERY,
                DiscoveryScanMode::INCREMENTAL,
                self::CHAIN,
                self::OPERATOR
            ),
            'uq_active must refuse a second session mid-chunk'
        );
    }

    /** Once the session terminalises, the slot is free again. */
    public function testTheSlotIsFreeAfterTheSessionEnds(): void
    {
        $runId = $this->queue();
        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::markSucceeded(
            $runId,
            $token,
            DiscoveryScanSession::STOP_CHUNK_CEILING,
            false,
            self::counts()
        ));

        $second = DiscoveryRunRepository::insertQueued(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            DiscoveryScanMode::INCREMENTAL,
            self::CHAIN,
            self::OPERATOR
        );

        self::assertIsArray($second, 'a new administrator session must be possible');
        self::assertNotSame($runId, (int) $second['id']);
        self::assertSame(0, (int) $this->row((int) $second['id'])->chunks_used, 'a new session starts at zero');
    }

    // ── (5) CANCELLATION ────────────────────────────────────────────────

    /**
     * An administrator can stop a session between chunks, and the queued
     * delivery then refuses before any provider work.
     */
    public function testCancellingBetweenChunksStopsTheNextChunkDead(): void
    {
        $runId = $this->queue();
        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::releaseForNextChunk($runId, $token, self::counts(), 1));

        // Between chunks the run is queued — which is exactly the state the
        // existing withdraw action operates on.
        self::assertSame(DiscoveryRunStatus::QUEUED, (string) $this->row($runId)->status);
        self::assertTrue(DiscoveryRunRepository::markCancelled($runId));

        // The already-queued Action Scheduler delivery arrives…
        self::assertNull(
            DiscoveryRunRepository::claim($runId),
            'a cancelled run must not be claimable — no provider call may follow'
        );

        $row = $this->row($runId);
        self::assertSame(DiscoveryRunStatus::CANCELLED, (string) $row->status);
        self::assertNull($row->active_marker);

        // ⚠ COMMITTED WORK SURVIVES. Cancelling later work must not roll back
        // what earlier chunks already recorded.
        self::assertSame(1, (int) $row->chunks_used);
        self::assertSame(48, (int) $row->requests_used);
        self::assertSame(7, (int) $row->families_seen);
    }

    /** Cancellation creates no run and leaves the chain free for a new one. */
    public function testCancellationCreatesNoRunAndFreesTheChain(): void
    {
        $runId = $this->queue();
        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::releaseForNextChunk($runId, $token, self::counts(), 1));
        self::assertTrue(DiscoveryRunRepository::markCancelled($runId));

        self::assertSame(
            1,
            (int) $GLOBALS['wpdb']->get_var(
                'SELECT COUNT(*) FROM `' . DiscoveryRunRepository::table() . '` WHERE chain_id = ' . self::CHAIN
            )
        );
        self::assertIsArray(DiscoveryRunRepository::insertQueued(
            DiscoveryJobKind::COSMWASM_DISCOVERY,
            DiscoveryScanMode::INCREMENTAL,
            self::CHAIN,
            self::OPERATOR
        ));
    }

    // ── (6) TERMINALISATION HAPPENS ONCE ────────────────────────────────

    /** A second terminal write against a spent lease is refused. */
    public function testTerminalisationHappensExactlyOnce(): void
    {
        $runId = $this->queue();
        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);

        self::assertTrue(DiscoveryRunRepository::markSucceeded($runId, $token, 'pass_completed', false, self::counts()));
        self::assertFalse(
            DiscoveryRunRepository::markSucceeded($runId, $token, 'pass_completed', false, self::counts()),
            'a second terminal write must not be confirmed'
        );
        self::assertFalse(
            DiscoveryRunRepository::markFailed($runId, $token, DiscoveryRunError::EXECUTION_FAILED),
            'nor may a failure overwrite a settled success'
        );

        $row = $this->row($runId);
        self::assertSame(1, (int) $row->chunks_used, 'the refused write must not count a chunk');
        self::assertSame(48, (int) $row->requests_used, 'nor add its counts');
    }

    /** A release cannot follow a terminal write. */
    public function testAReleaseCannotResurrectATerminalRun(): void
    {
        $runId = $this->queue();
        $token = DiscoveryRunRepository::claim($runId);
        self::assertIsString($token);
        self::assertTrue(DiscoveryRunRepository::markSucceeded($runId, $token, 'pass_completed', false, self::counts()));

        self::assertFalse(
            DiscoveryRunRepository::releaseForNextChunk($runId, $token, self::counts(), 1),
            'a settled run must never be returned to the queue'
        );
        self::assertSame(DiscoveryRunStatus::SUCCEEDED, (string) $this->row($runId)->status);
    }

    // ── (7) MAINTENANCE MAY RECOVER, NEVER CREATE ───────────────────────

    /**
     * The maintenance sweep cannot create a run — with work to do or without.
     *
     * ⚠ THE STRUCTURAL CLAIM OF THE WHOLE FEATURE. `findDispatchable()` has
     * no chain-selection logic anywhere, which is the reason the sweep can
     * never become an automatic scanner. A mutation that inserted a run
     * inside `tick()` survived the first mutation run because NOTHING
     * asserted this — the sweep was trusted, not tested.
     */
    public function testMaintenanceNeverCreatesARun(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = DiscoveryRunRepository::table();
        $all   = static fn(): int => (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");

        // (a) with nothing at all to do.
        $before = $all();
        \BCC\Trust\Onchain\Workers\DiscoveryRunMaintenance::tick();
        self::assertSame($before, $all(), 'an idle sweep must create nothing');

        // (b) with a real administrator run waiting to be dispatched.
        $runId  = $this->queue();
        $before = $all();
        \BCC\Trust\Onchain\Workers\DiscoveryRunMaintenance::tick();
        self::assertSame($before, $all(), 're-dispatching must create nothing');

        // (c) with an expired lease to recover — the sweep's real job.
        self::assertIsString(DiscoveryRunRepository::claim($runId));
        $wpdb->query(
            "UPDATE `{$table}` SET lease_expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
              WHERE id = " . $runId
        );
        $before = $all();
        \BCC\Trust\Onchain\Workers\DiscoveryRunMaintenance::tick();
        self::assertSame($before, $all(), 'recovering a lease must create nothing');

        // …and the run it recovered is the SAME one.
        self::assertSame(
            1,
            (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}` WHERE chain_id = " . self::CHAIN),
            'recovery resumes one run, it does not fork it'
        );
    }

    // ── (8) THE COLUMN ITSELF ───────────────────────────────────────────

    /** `chunks_used` exists, defaults to zero and is not `attempt_count`. */
    public function testTheChunkColumnExistsAndDefaultsToZero(): void
    {
        $wpdb  = $GLOBALS['wpdb'];
        $table = DiscoveryRunRepository::table();

        $col = $wpdb->get_row($wpdb->prepare(
            'SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE
               FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
            $table,
            'chunks_used'
        ));

        self::assertIsObject($col, 'chunks_used must exist in the real schema');
        self::assertSame('0', (string) $col->COLUMN_DEFAULT);
        self::assertSame('NO', (string) $col->IS_NULLABLE);

        $runId = $this->queue();
        self::assertSame(0, (int) $this->row($runId)->chunks_used, 'a fresh run has spent no chunks');
    }
}
