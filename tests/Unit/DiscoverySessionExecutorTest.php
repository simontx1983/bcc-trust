<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\DiscoveryRunRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\DiscoveryRunService;
use BCC\Trust\Onchain\Services\DiscoveryScanSession;
use BCC\Trust\Onchain\Support\ApiRetry;
use BCC\Trust\Onchain\Workers\DiscoveryRunExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * One administrator action, many chunks — at the executor.
 *
 * ── THE DISTINCTION UNDER TEST ──────────────────────────────────────────
 * **Automatic scan CREATION is forbidden. Bounded CONTINUATION of an
 * already authorized run is permitted.**
 *
 * Every test here counts `DiscoveryRunRepository::$rows` after the executor
 * has run. A continuation that ever inserted a row would fail all of them at
 * once, which is the point: the ledger count IS the invariant.
 */
#[CoversClass(DiscoveryRunExecutor::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DiscoverySessionExecutorTest extends TestCase
{
    private const CHAIN    = 17;
    private const OPERATOR = 2;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-cli-stubs.php';

        define('WP_CLI', true);
        define('BCC_COSMWASM_CHAIN_ALLOWLIST', (string) self::CHAIN);
        define('BCC_COSMWASM_DISCOVERY_ENABLED', true);
        define('BCC_COSMWASM_BACKFILL_ENABLED', true);
    }

    private function queueRun(): int
    {
        ChainRepository::seed(self::CHAIN, 'dungeon', 'https://lcd.example', 'cosmos', 1, 1);

        $result = (new DiscoveryRunService())->request(self::CHAIN, self::OPERATOR);
        self::assertTrue($result['ok'], 'precondition: the run must be genuinely queued');
        self::assertCount(1, DiscoveryRunRepository::$rows);

        return (int) $result['run_id'];
    }

    /**
     * Leave real pending classification work, so the session has a reason
     * to continue. These are ordinary unvisited families — the same
     * creation-time default the Cosmos Hub canary left 730 of.
     */
    private function seedBacklog(int $count = 40): void
    {
        for ($codeId = 1; $codeId <= $count; $codeId++) {
            CosmwasmCodeFamilyRepository::seed(self::CHAIN, $codeId, CosmwasmClassifier::INCONCLUSIVE);
        }

        $this->answerEveryRequestCleanly();
    }

    /**
     * A provider that always answers, and never answers badly.
     *
     * ⚠ WITHOUT THIS EVERY CEILING TEST MEASURES THE WRONG RULE. An
     * unanswered request makes the pass record an error, and a chunk with
     * pass-level errors ends the session with `session_provider_errors` —
     * correctly, but before the ceiling under test is ever reached. The
     * first draft of this file had exactly that bug: three ceiling tests
     * "failed" reporting a provider fault the fixture had caused.
     *
     * A URL-routed responder rather than the FIFO queue, because the pass is
     * multi-stage: a stage that stops on a budget floor leaves a queue
     * misaligned with what the next stage asks for, and the test then
     * measures the fixture.
     */
    private function answerEveryRequestCleanly(): void
    {
        ApiRetry::$responder = static function (string $url): array {
            // An empty, well-formed page for every listing shape, and a
            // non-CW721 contract_info for every probe. Nothing here confirms
            // a collection — these tests are about the SESSION, not about
            // classification verdicts.
            if (str_contains($url, '/contracts')) {
                return ['code' => 200, 'body' => '{"contracts":[],"pagination":{"next_key":null}}'];
            }
            if (str_contains($url, '/cosmwasm/wasm/v1/code')) {
                return ['code' => 200, 'body' => '{"code_infos":[],"pagination":{"next_key":null}}'];
            }

            return ['code' => 200, 'body' => '{"data":{}}'];
        };
    }

    /** @return array<string, mixed> */
    private function theRun(): array
    {
        $rows = DiscoveryRunRepository::$rows;
        self::assertCount(1, $rows, 'exactly one run row, always');

        return (array) reset($rows);
    }

    /** Continuations scheduled for the executor hook. */
    private function scheduled(): array
    {
        return array_values(array_filter(
            \BCC\Core\Cron\AsyncDispatcher::$scheduled,
            static fn(array $s): bool => $s['hook'] === DiscoveryRunExecutor::HOOK
        ));
    }

    /** Let the inter-chunk delay elapse in the stub ledger. */
    private function elapse(int $runId): void
    {
        if (isset(DiscoveryRunRepository::$rows[$runId])) {
            DiscoveryRunRepository::$rows[$runId]['next_retry_at'] = null;
        }
    }

    // ── (1) AN INCOMPLETE CHUNK CONTINUES THE SAME RUN ──────────────────

    /**
     * THE CENTRAL TEST: work remains, so a second chunk is scheduled for the
     * SAME run id — and no second run row exists.
     */
    public function testAnIncompleteChunkSchedulesAnotherChunkForTheSameRun(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();

        $uuidBefore = (string) ($this->theRun()['run_uuid'] ?? '');

        $result = DiscoveryRunExecutor::execute($runId);

        self::assertSame('chunk_complete', $result['status'], 'the session is not finished');

        // ⚠ ONE RUN ROW. Not two.
        self::assertCount(1, DiscoveryRunRepository::$rows, 'a chunk must never create a run');

        $run = $this->theRun();
        self::assertSame($uuidBefore, (string) $run['run_uuid'], 'the uuid is the session identity');
        self::assertSame('queued', (string) $run['status'], 'returned to the queue, not terminal');
        self::assertNotNull($run['active_marker'] ?? null, 'the session keeps the active slot');
        self::assertSame(1, (int) ($run['chunks_used'] ?? 0));
        self::assertSame(self::OPERATOR, (int) $run['requested_by'], 'one operator for the session');

        // Exactly one continuation, for this run, in the future.
        $scheduled = $this->scheduled();
        self::assertCount(1, $scheduled, 'exactly one continuation');
        self::assertSame([$runId], $scheduled[0]['args'], 'scheduled for THIS run id');
        self::assertGreaterThan(time(), $scheduled[0]['ts'], 'the next chunk is delayed');
    }

    /** The next chunk claims the same run and the counts accumulate. */
    public function testTheNextChunkClaimsTheSameRunAndCountsAccumulate(): void
    {
        $runId = $this->queueRun();
        // Enough backlog that TWO chunks both leave work behind — a queue
        // one chunk can drain would make chunk two complete the session and
        // this test would measure the fixture's size, not the mechanism.
        $this->seedBacklog(300);

        DiscoveryRunExecutor::execute($runId);
        $afterOne = $this->theRun();
        $this->elapse($runId);

        DiscoveryRunExecutor::execute($runId);
        $afterTwo = $this->theRun();

        self::assertCount(1, DiscoveryRunRepository::$rows, 'still one run');
        self::assertSame(2, (int) ($afterTwo['chunks_used'] ?? 0));
        self::assertGreaterThanOrEqual(
            (int) ($afterOne['requests_used'] ?? 0),
            (int) ($afterTwo['requests_used'] ?? 0),
            'cumulative requests never go backwards'
        );
        self::assertCount(2, $this->scheduled(), 'one continuation per incomplete chunk');
    }

    // ── (2) THE CEILING ENDS THE SESSION HONESTLY ───────────────────────

    /**
     * At the chunk ceiling the session terminalises with an honest reason
     * and schedules nothing.
     */
    public function testTheChunkCeilingEndsTheSessionAndSchedulesNothingFurther(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();

        // The session has already spent its authorised batches.
        DiscoveryRunRepository::$rows[$runId]['chunks_used'] = DiscoveryScanSession::MAX_CHUNKS - 1;

        $result = DiscoveryRunExecutor::execute($runId);

        self::assertSame('succeeded', $result['status'], 'a capped session still succeeded at what it did');
        self::assertSame(DiscoveryScanSession::STOP_CHUNK_CEILING, $result['reason']);

        $run = $this->theRun();
        self::assertSame('succeeded', (string) $run['status']);
        self::assertSame(DiscoveryScanSession::STOP_CHUNK_CEILING, (string) $run['stop_reason']);
        self::assertNull($run['active_marker'] ?? null, 'the active slot is released');
        self::assertSame([], $this->scheduled(), 'a capped session leaves no pending action');
        self::assertCount(1, DiscoveryRunRepository::$rows, 'and creates no successor');
    }

    /** The request ceiling ends it too, with its own reason. */
    public function testTheRequestCeilingEndsTheSession(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();

        DiscoveryRunRepository::$rows[$runId]['requests_used'] = DiscoveryScanSession::MAX_REQUESTS;

        $result = DiscoveryRunExecutor::execute($runId);

        self::assertSame(DiscoveryScanSession::STOP_REQUEST_CEILING, $result['reason']);
        self::assertSame([], $this->scheduled());
    }

    /** So does the wall-clock ceiling. */
    public function testTheWallClockCeilingEndsTheSession(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();

        DiscoveryRunRepository::$rows[$runId]['requested_at'] =
            gmdate('Y-m-d H:i:s', time() - DiscoveryScanSession::MAX_AGE_SECONDS - 60);

        $result = DiscoveryRunExecutor::execute($runId);

        self::assertSame(DiscoveryScanSession::STOP_AGE_CEILING, $result['reason']);
        self::assertSame([], $this->scheduled());
    }

    // ── (3) AN EMPTY QUEUE IS A GENUINE COMPLETION ──────────────────────

    /**
     * With nothing left to classify the session completes — and this is the
     * ONLY branch allowed to say so.
     */
    public function testAnEmptyQueueCompletesTheSessionWithoutScheduling(): void
    {
        $runId = $this->queueRun();
        // No backlog seeded: nothing is claimable.

        $result = DiscoveryRunExecutor::execute($runId);

        self::assertSame('succeeded', $result['status']);
        self::assertSame([], $this->scheduled(), 'nothing left to do, nothing scheduled');

        $run = $this->theRun();
        self::assertNull($run['active_marker'] ?? null);
        self::assertCount(1, DiscoveryRunRepository::$rows);
    }

    // ── (4) AUTHORIZATION CHANGES BETWEEN CHUNKS ────────────────────────

    /**
     * Withdrawing product support between chunks stops the session, and the
     * next chunk is never queued.
     */
    public function testWithdrawingSupportBetweenChunksStopsTheSession(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();

        DiscoveryRunExecutor::execute($runId);
        self::assertCount(1, $this->scheduled(), 'precondition: it was continuing');
        $this->elapse($runId);

        // The administrator turns product support off mid-session.
        ChainRepository::seed(self::CHAIN, 'dungeon', 'https://lcd.example', 'cosmos', 1, 0);

        $before = count($this->scheduled());
        DiscoveryRunExecutor::execute($runId);

        self::assertSame($before, count($this->scheduled()), 'no further chunk may be queued');
        self::assertCount(1, DiscoveryRunRepository::$rows);
    }

    /** Removing the chain from the allowlist stops it too. */
    public function testRemovingTheChainFromTheAllowlistStopsTheSession(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();

        DiscoveryRunExecutor::execute($runId);
        $before = count($this->scheduled());
        $this->elapse($runId);

        // A narrowed canary allowlist that no longer names this chain.
        ChainRepository::seed(self::CHAIN, 'dungeon', 'https://lcd.example', 'cosmos', 0, 1);

        DiscoveryRunExecutor::execute($runId);

        self::assertSame($before, count($this->scheduled()), 'an un-opted chain gets no further chunk');
    }

    // ── (5) NOTHING HERE CAN INVENT WORK ────────────────────────────────

    /**
     * A continuation never changes the chain, the operator or the mode.
     *
     * ⚠ These three fields are the whole authorization. If a session could
     * move to another chain it would be an automatic scanner wearing an
     * administrator's name.
     */
    public function testAContinuationCannotChangeChainOperatorOrMode(): void
    {
        $runId  = $this->queueRun();
        $this->seedBacklog();
        $before = $this->theRun();

        DiscoveryRunExecutor::execute($runId);
        $this->elapse($runId);
        DiscoveryRunExecutor::execute($runId);

        $after = $this->theRun();

        self::assertSame((int) $before['chain_id'], (int) $after['chain_id']);
        self::assertSame((int) $before['requested_by'], (int) $after['requested_by']);
        self::assertSame((string) $before['scan_mode'], (string) $after['scan_mode']);
        self::assertSame((string) $before['run_uuid'], (string) $after['run_uuid']);
        self::assertSame((string) $before['job_kind'], (string) $after['job_kind']);
    }

    /** Every scheduled continuation names this run id and no other. */
    public function testEveryContinuationNamesThisRunAndNoOther(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();

        DiscoveryRunExecutor::execute($runId);
        $this->elapse($runId);
        DiscoveryRunExecutor::execute($runId);

        foreach ($this->scheduled() as $s) {
            self::assertSame([$runId], $s['args'], 'a continuation may only re-run its own run');
        }
    }

    // ── (6) THE CLI IS NOT A SESSION HOST ───────────────────────────────

    /**
     * With continuation disallowed the executor terminalises, exactly as it
     * did before PR 7.3.
     *
     * ⚠ THE SUPERVISED CLI'S CONTRACT. That command promises ONE PASS PER
     * INVOCATION and runs inline while a human watches; a session there
     * would queue chunks the process will never see. It passes `false`, and
     * this is the test that the flag actually does something.
     */
    public function testContinuationCanBeRefusedByTheCaller(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog();

        $result = DiscoveryRunExecutor::execute($runId, false);

        self::assertNotSame('chunk_complete', $result['status'], 'the CLI must not host a session');
        self::assertSame([], $this->scheduled(), 'and must schedule nothing');

        $run = $this->theRun();
        self::assertNull($run['active_marker'] ?? null, 'the run is terminal');
        self::assertCount(1, DiscoveryRunRepository::$rows);
    }

    // ── (7) STATE THAT CHANGES *DURING* A CHUNK ─────────────────────────

    /**
     * Support withdrawn WHILE the chunk is running ends the session cleanly,
     * instead of queueing a chunk that is already doomed.
     *
     * ⚠ THIS IS THE ONLY WINDOW THE BETWEEN-CHUNK RECHECK OWNS, and finding
     * it took a mutation control. The executor re-asks readiness at the START
     * of every chunk too, so for any change made BETWEEN chunks the two
     * checks are indistinguishable — a mutation removing the second one
     * survived, because the first one refused the next chunk anyway.
     *
     * The difference only appears when readiness changes mid-pass: with the
     * recheck the session ends `succeeded` / `session_not_ready` and queues
     * nothing; without it, a doomed chunk is scheduled, runs, contacts
     * nobody, and terminal-FAILS the run — which an operator reads as a
     * broken scan rather than a stopped one.
     *
     * Flipping the chain inside the provider responder is what puts the
     * change in that window.
     */
    public function testSupportWithdrawnDuringAChunkEndsTheSessionWithoutQueueingADoomedChunk(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog(300);

        // The administrator turns support off partway through the pass.
        ApiRetry::$responder = static function (string $url): array {
            ChainRepository::seed(self::CHAIN, 'dungeon', 'https://lcd.example', 'cosmos', 1, 0);

            if (str_contains($url, '/contracts')) {
                return ['code' => 200, 'body' => '{"contracts":[],"pagination":{"next_key":null}}'];
            }

            return ['code' => 200, 'body' => '{"data":{}}'];
        };

        $result = DiscoveryRunExecutor::execute($runId);

        self::assertNotSame('chunk_complete', $result['status'], 'the session must not continue');
        self::assertSame([], $this->scheduled(), 'no doomed chunk may be queued');
        self::assertSame(
            DiscoveryScanSession::STOP_NOT_READY,
            $result['reason'],
            'and the reason names the authorisation, not a budget'
        );

        $run = $this->theRun();
        self::assertSame('succeeded', (string) $run['status'], 'a stopped session is not a failed one');
        self::assertNull($run['active_marker'] ?? null);
        self::assertCount(1, DiscoveryRunRepository::$rows);
    }

    /**
     * If the chunk release is not confirmed, NOTHING is scheduled.
     *
     * ⚠ Otherwise an Action Scheduler action would be queued against a run
     * whose state we failed to write — the chunk would claim a row that
     * still says `running`, get nothing, and the session would stall with a
     * pending action nobody can service.
     *
     * The lease is stolen mid-pass so the release's `lease_token` guard
     * genuinely fails, rather than the test stubbing the return value.
     */
    public function testAnUnconfirmedReleaseSchedulesNothing(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog(300);

        ApiRetry::$responder = static function (string $url) use ($runId): array {
            // Someone else re-leased the row while this chunk was working.
            if (isset(DiscoveryRunRepository::$rows[$runId])) {
                DiscoveryRunRepository::$rows[$runId]['lease_token'] = 'a-different-lease-token-000000000000';
            }

            if (str_contains($url, '/contracts')) {
                return ['code' => 200, 'body' => '{"contracts":[],"pagination":{"next_key":null}}'];
            }

            return ['code' => 200, 'body' => '{"data":{}}'];
        };

        $result = DiscoveryRunExecutor::execute($runId);

        self::assertSame([], $this->scheduled(), 'an unconfirmed release must schedule nothing');
        self::assertNotSame('chunk_complete', $result['status']);
        self::assertCount(1, DiscoveryRunRepository::$rows, 'and must not create a run');
    }

    // ── (8) NO PROVIDER IS CONTACTED BY THE DECISION ITSELF ─────────────

    /**
     * A withdrawn session makes NO provider call when its queued
     * continuation arrives.
     *
     * ⚠ THE POINT OF CANCELLATION. Action Scheduler has already been handed
     * the next chunk when the administrator presses Stop, so "cancelled"
     * only means anything if that delivery refuses BEFORE contacting the
     * chain. The claim's compare-and-swap is what enforces it — a cancelled
     * row is no longer `queued`.
     */
    public function testACancelledSessionContactsNoProviderOnItsQueuedContinuation(): void
    {
        $runId = $this->queueRun();
        $this->seedBacklog(300);

        DiscoveryRunExecutor::execute($runId);
        self::assertCount(1, $this->scheduled(), 'precondition: a continuation is queued');
        $this->elapse($runId);

        // The administrator withdraws the session between chunks.
        self::assertTrue(DiscoveryRunRepository::markCancelled($runId));

        ApiRetry::$calls = [];
        $before = count($this->scheduled());

        // …and the continuation Action Scheduler already holds now fires.
        $result = DiscoveryRunExecutor::execute($runId);

        self::assertSame('not_claimed', $result['status'], 'a cancelled run must not be claimable');
        self::assertSame([], ApiRetry::$calls, 'and must contact no provider');
        self::assertSame($before, count($this->scheduled()), 'nor schedule anything further');
        self::assertCount(1, DiscoveryRunRepository::$rows, 'nor create a run');

        // Work already committed is preserved — cancelling later work is not
        // a rollback of earlier work.
        $run = $this->theRun();
        self::assertSame(1, (int) ($run['chunks_used'] ?? 0));
        self::assertGreaterThan(0, (int) ($run['requests_used'] ?? 0));
    }
}
