<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\DiscoveryScanSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The session ceilings, as a pure decision.
 *
 * ── WHAT THIS FILE IS FOR ───────────────────────────────────────────────
 * `decide()` is the only thing that may say "take another chunk", so the
 * ORDER of its refusals is a safety property, not a style choice. Every
 * test below pins one refusal, and several deliberately supply a context
 * where two refusals apply at once to prove which one wins.
 *
 * ⚠ It is pure on purpose. A ceiling that could only be tested by running
 * 25 provider passes would not be tested.
 */
#[CoversClass(DiscoveryScanSession::class)]
final class DiscoveryScanSessionTest extends TestCase
{
    /** A context that would happily continue. Every test spoils one field. */
    private static function healthy(array $overrides = []): array
    {
        return array_merge([
            'chunks_used'   => 1,
            'requests_used' => 48,
            'age_seconds'   => 60,
            'error_chunks'  => 0,
            'ready'         => true,
            'cancelled'     => false,
            'eligible_now'  => 730,
            'delayed'       => 0,
        ], $overrides);
    }

    public function testAHealthySessionContinues(): void
    {
        $d = DiscoveryScanSession::decide(self::healthy());

        self::assertTrue($d['continue']);
        self::assertSame('', $d['reason']);
    }

    // ── (1) AUTHORIZATION OUTRANKS EVERY CEILING ────────────────────────

    /**
     * A cancelled run stops even with every ceiling wide open.
     *
     * ⚠ THE ORDER IS THE POINT. If cancellation were checked after the
     * "is there work" branch, a withdrawn session with 730 families left
     * would continue — which is precisely the thing an administrator
     * pressing Stop is trying to prevent.
     */
    public function testCancellationStopsASessionWithEveryCeilingOpen(): void
    {
        $d = DiscoveryScanSession::decide(self::healthy(['cancelled' => true]));

        self::assertFalse($d['continue']);
        self::assertSame(DiscoveryScanSession::STOP_NOT_READY, $d['reason']);
    }

    /** Losing readiness stops the session even mid-backlog. */
    public function testLosingReadinessStopsTheSession(): void
    {
        $d = DiscoveryScanSession::decide(self::healthy(['ready' => false]));

        self::assertFalse($d['continue']);
        self::assertSame(DiscoveryScanSession::STOP_NOT_READY, $d['reason']);
    }

    /** Cancellation wins over an exhausted ceiling: the honest reason is why it STOPPED being authorised. */
    public function testCancellationOutranksTheChunkCeiling(): void
    {
        $d = DiscoveryScanSession::decide(self::healthy([
            'cancelled'   => true,
            'chunks_used' => DiscoveryScanSession::MAX_CHUNKS,
        ]));

        self::assertSame(DiscoveryScanSession::STOP_NOT_READY, $d['reason']);
    }

    // ── (2) THE CEILINGS ────────────────────────────────────────────────

    public function testTheChunkCeilingStopsTheSession(): void
    {
        $d = DiscoveryScanSession::decide(self::healthy([
            'chunks_used' => DiscoveryScanSession::MAX_CHUNKS,
        ]));

        self::assertFalse($d['continue']);
        self::assertSame(DiscoveryScanSession::STOP_CHUNK_CEILING, $d['reason']);
    }

    /** One below the ceiling still continues — an off-by-one here costs a whole chunk. */
    public function testOneChunkBelowTheCeilingStillContinues(): void
    {
        $d = DiscoveryScanSession::decide(self::healthy([
            'chunks_used' => DiscoveryScanSession::MAX_CHUNKS - 1,
        ]));

        self::assertTrue($d['continue']);
    }

    public function testTheRequestCeilingStopsTheSession(): void
    {
        $d = DiscoveryScanSession::decide(self::healthy([
            'requests_used' => DiscoveryScanSession::MAX_REQUESTS,
        ]));

        self::assertFalse($d['continue']);
        self::assertSame(DiscoveryScanSession::STOP_REQUEST_CEILING, $d['reason']);
    }

    public function testTheWallClockCeilingStopsTheSession(): void
    {
        $d = DiscoveryScanSession::decide(self::healthy([
            'age_seconds' => DiscoveryScanSession::MAX_AGE_SECONDS,
        ]));

        self::assertFalse($d['continue']);
        self::assertSame(DiscoveryScanSession::STOP_AGE_CEILING, $d['reason']);
    }

    /**
     * A provider-error chunk ends the session.
     *
     * ⚠ ONE is the threshold, deliberately. A session is a convenience, not
     * a retry engine — the operator's next click starts a fresh one.
     */
    public function testAProviderErrorChunkStopsTheSession(): void
    {
        $d = DiscoveryScanSession::decide(self::healthy(['error_chunks' => 1]));

        self::assertFalse($d['continue']);
        self::assertSame(DiscoveryScanSession::STOP_PROVIDER_ERRORS, $d['reason']);
    }

    /** The error threshold is asked BEFORE the chunk ceiling, so the reason names the fault. */
    public function testAProviderErrorOutranksTheChunkCeiling(): void
    {
        $d = DiscoveryScanSession::decide(self::healthy([
            'error_chunks' => 1,
            'chunks_used'  => DiscoveryScanSession::MAX_CHUNKS,
        ]));

        self::assertSame(DiscoveryScanSession::STOP_PROVIDER_ERRORS, $d['reason']);
    }

    // ── (3) WORK, AND THE DELAYED/COMPLETE DISTINCTION ──────────────────

    /**
     * Nothing claimable but work waiting on backoff is DELAYED, not complete.
     *
     * ⚠ THE BUSY-LOOP TEST. Scheduling a chunk here would run an empty pass,
     * find nothing claimable, and schedule another — 25 times. The
     * classifier's minimum backoff is six hours and the whole session window
     * is one, so waiting inside the session can never help.
     */
    public function testDelayedWorkEndsTheSessionAndIsNotCompletion(): void
    {
        $d = DiscoveryScanSession::decide(self::healthy([
            'eligible_now' => 0,
            'delayed'      => 5,
        ]));

        self::assertFalse($d['continue']);
        self::assertSame(DiscoveryScanSession::STOP_DELAYED_WORK, $d['reason']);
        self::assertNotSame(DiscoveryScanSession::STOP_COMPLETED, $d['reason']);
    }

    /** An empty queue with nothing delayed is the ordinary successful ending. */
    public function testAnEmptyQueueCompletes(): void
    {
        $d = DiscoveryScanSession::decide(self::healthy([
            'eligible_now' => 0,
            'delayed'      => 0,
        ]));

        self::assertFalse($d['continue']);
        self::assertSame(DiscoveryScanSession::STOP_COMPLETED, $d['reason']);
    }

    /**
     * A ceiling outranks "there is work" — a growing chain cannot outrun it.
     *
     * ⚠ The endless-session guard. If "is there work" were asked first, a
     * chain producing code ids faster than they can be classified would keep
     * `eligible_now` above zero forever and the session would never end.
     */
    public function testACeilingOutranksAvailableWork(): void
    {
        $d = DiscoveryScanSession::decide(self::healthy([
            'chunks_used'  => DiscoveryScanSession::MAX_CHUNKS,
            'eligible_now' => 100000,
        ]));

        self::assertFalse($d['continue']);
        self::assertSame(DiscoveryScanSession::STOP_CHUNK_CEILING, $d['reason']);
    }

    // ── (4) THE VOCABULARY ──────────────────────────────────────────────

    #[DataProvider('sessionStops')]
    public function testEverySessionStopIsRecognisedAndFitsTheColumn(string $reason): void
    {
        self::assertTrue(DiscoveryScanSession::isSessionStop($reason));

        // `bcc_discovery_runs.stop_reason` is VARCHAR(40) and the repository
        // truncates silently — a longer token would be stored mangled and
        // never match `isSessionStop()` again.
        self::assertLessThanOrEqual(40, strlen($reason), $reason . ' must fit stop_reason');

        // Every session stop an operator can reach has a sentence.
        self::assertNotSame('', DiscoveryScanSession::stopSentence($reason));
    }

    /** @return list<array{0: string}> */
    public static function sessionStops(): array
    {
        return [
            [DiscoveryScanSession::STOP_CHUNK_CEILING],
            [DiscoveryScanSession::STOP_REQUEST_CEILING],
            [DiscoveryScanSession::STOP_AGE_CEILING],
            [DiscoveryScanSession::STOP_PROVIDER_ERRORS],
            [DiscoveryScanSession::STOP_NOT_READY],
            [DiscoveryScanSession::STOP_DELAYED_WORK],
        ];
    }

    /** `pass_completed` is a PASS reason, not a session one — it predates this class. */
    public function testCompletionIsNotASessionStop(): void
    {
        self::assertFalse(DiscoveryScanSession::isSessionStop(DiscoveryScanSession::STOP_COMPLETED));
        self::assertSame('', DiscoveryScanSession::stopSentence(DiscoveryScanSession::STOP_COMPLETED));
    }

    /** No sentence promises a completion time. */
    #[DataProvider('sessionStops')]
    public function testNoStopSentencePromisesATime(string $reason): void
    {
        $s = strtolower(DiscoveryScanSession::stopSentence($reason));

        foreach ([' minute', ' hour', ' second', 'estimated', 'remaining time', 'will finish'] as $promise) {
            self::assertStringNotContainsString($promise, $s, $reason . ' must not promise a time');
        }
    }

    /** No sentence claims the chain has no NFTs. */
    #[DataProvider('sessionStops')]
    public function testNoStopSentenceClaimsTheChainIsEmpty(string $reason): void
    {
        $s = strtolower(DiscoveryScanSession::stopSentence($reason));

        foreach (['no nft collections', 'has no nft', 'nothing to scan', 'scan complete'] as $lie) {
            self::assertStringNotContainsString($lie, $s, $reason . ' must not claim emptiness');
        }
    }

    // ── (5) THE CEILINGS THEMSELVES ─────────────────────────────────────

    /**
     * The ceilings are conservative and mutually consistent.
     *
     * ⚠ Measured, not invented. Each canary chunk cost 48 requests and ~16 s
     * and settled ~7 families; 730 remaining is ~104 chunks. These bounds
     * make one click worth ~175 families without letting a session run
     * unattended for long.
     */
    public function testTheCeilingsAreConsistentWithThePerChunkBudget(): void
    {
        $chunk = DiscoveryScanSession::chunkCeilings();

        // The request ceiling must admit every authorised chunk at full
        // budget, or the chunk ceiling could never actually be reached.
        self::assertGreaterThanOrEqual(
            DiscoveryScanSession::MAX_CHUNKS * $chunk['requests'],
            DiscoveryScanSession::MAX_REQUESTS,
            'the request ceiling must not bite before the chunk ceiling'
        );

        // Cumulative execution time is bounded transitively, not stored.
        self::assertSame(
            DiscoveryScanSession::MAX_CHUNKS * $chunk['seconds'],
            DiscoveryScanSession::maxExecutionSeconds()
        );

        // And the wall-clock window must be able to contain a full session:
        // 25 chunks of work plus 25 gaps.
        $worstCase = DiscoveryScanSession::MAX_CHUNKS
            * ($chunk['seconds'] + DiscoveryScanSession::CHUNK_DELAY_SECONDS);
        self::assertGreaterThan(
            $worstCase,
            DiscoveryScanSession::MAX_AGE_SECONDS,
            'the age ceiling must not end a healthy session early'
        );
    }

    /** The inter-chunk delay is real, bounded, and never zero. */
    public function testTheChunkDelayIsBoundedAndNonZero(): void
    {
        $d = DiscoveryScanSession::nextChunkDelay();

        self::assertGreaterThan(0, $d);
        self::assertSame(DiscoveryScanSession::CHUNK_DELAY_SECONDS, $d);
        self::assertLessThanOrEqual(60, $d, 'a long gap would make a session drag');
    }

    /** Attempts are per CHUNK, and the session bound is separate. */
    public function testAttemptsArePerChunkNotPerSession(): void
    {
        self::assertSame(3, DiscoveryScanSession::attemptsPerChunk());
        self::assertGreaterThan(
            DiscoveryScanSession::attemptsPerChunk(),
            DiscoveryScanSession::MAX_CHUNKS,
            'if a session were capped by attempts it could never exceed three chunks'
        );
    }

    /** Missing context fields fail CLOSED, never into a continuation. */
    public function testAnEmptyContextDoesNotContinue(): void
    {
        $d = DiscoveryScanSession::decide([]);

        self::assertFalse($d['continue']);
        self::assertSame(DiscoveryScanSession::STOP_NOT_READY, $d['reason']);
    }
}
