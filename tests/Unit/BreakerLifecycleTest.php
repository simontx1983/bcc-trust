<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * ONE coherent lifecycle: closed → open → cooldown → half-open → resolved.
 *
 * ── WHY THIS FILE EXISTS ────────────────────────────────────────────────
 * Before PR 7.6 the string `FAILURE_THRESHOLD` appeared in ZERO test files.
 * Nothing asserted that five consecutive failures open the breaker, that a
 * success resets the count, or that the cooldown is honoured. The mechanism
 * that had already ended two staging sessions and was sitting open on a
 * production chain was constrained entirely by its own docblock.
 *
 * ── THE BUG THESE TESTS PIN ─────────────────────────────────────────────
 * The failure COUNT and the `opened_at` TIMESTAMP live in different stores
 * with different lifetimes — a `wp_options` row that never expires, and a
 * transient that expires after {@see OnchainCircuitBreaker::CACHE_TTL}. When
 * the transient goes and the option stays, the surviving number counts
 * failures inside a window that no longer exists.
 *
 * Measured on production 2026-09-08: chain 18 (THORChain) held
 * `_bcc_cb_counter_18 = 3022` beside a transient that had expired on
 * 2026-09-04. Under the old code the next single failure would read 3023,
 * find no `opened_at`, and trip instantly — an outage caused by one request
 * and explainable by nothing in the logs.
 */
#[CoversClass(OnchainCircuitBreaker::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BreakerLifecycleTest extends TestCase
{
    private const CHAIN = 8;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/breaker-real-stubs.php';
        \BccBreakerStore::reset();
        \BCC\Trust\Onchain\Repositories\OnchainCircuitBreakerRepository::$failIncrement = false;
    }

    private function failTimes(int $n, int $chain = self::CHAIN): void
    {
        for ($i = 0; $i < $n; $i++) {
            OnchainCircuitBreaker::recordFailure($chain);
        }
    }

    // ── threshold ───────────────────────────────────────────────────────

    /** The documented threshold is five, and it is exactly five. */
    public function testThresholdIsExactlyFive(): void
    {
        self::assertSame(5, OnchainCircuitBreaker::FAILURE_THRESHOLD);

        $this->failTimes(4);
        self::assertSame(
            OnchainCircuitBreaker::PHASE_CLOSED,
            OnchainCircuitBreaker::phase(self::CHAIN),
            'four consecutive failures must NOT open the breaker'
        );
        self::assertFalse(OnchainCircuitBreaker::isOpen(self::CHAIN));

        OnchainCircuitBreaker::recordFailure(self::CHAIN);
        self::assertSame(
            OnchainCircuitBreaker::PHASE_OPEN,
            OnchainCircuitBreaker::phase(self::CHAIN),
            'the fifth consecutive failure opens it'
        );
        self::assertTrue(OnchainCircuitBreaker::isOpen(self::CHAIN));
    }

    /** The cooldown is the documented 300 seconds. */
    public function testCooldownIsFiveMinutes(): void
    {
        self::assertSame(300, OnchainCircuitBreaker::COOLDOWN_SECONDS);
    }

    // ── success resets ──────────────────────────────────────────────────

    /** A success clears BOTH stores, not just one. */
    public function testSuccessResetsTheFailureCount(): void
    {
        $this->failTimes(4);
        self::assertSame(4, \BccBreakerStore::counter(self::CHAIN));

        OnchainCircuitBreaker::recordSuccess(self::CHAIN);

        self::assertNull(
            \BccBreakerStore::counter(self::CHAIN),
            'the durable counter must be deleted, not merely zeroed in the transient'
        );
        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(self::CHAIN));

        // …and the next failure starts from one, not from five.
        OnchainCircuitBreaker::recordFailure(self::CHAIN);
        self::assertSame(1, \BccBreakerStore::counter(self::CHAIN));
        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(self::CHAIN));
    }

    /** Interleaved successes mean the threshold is never reached. */
    public function testSuccessesBetweenFailuresKeepItClosed(): void
    {
        for ($i = 0; $i < 12; $i++) {
            OnchainCircuitBreaker::recordFailure(self::CHAIN);
            OnchainCircuitBreaker::recordSuccess(self::CHAIN);
        }

        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(self::CHAIN));
    }

    // ── cooldown / half-open ────────────────────────────────────────────

    /** Inside the cooldown the breaker stays shut. */
    public function testInsideCooldownItStaysOpen(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 5, time() - 10);

        self::assertSame(OnchainCircuitBreaker::PHASE_OPEN, OnchainCircuitBreaker::phase(self::CHAIN));
        self::assertTrue(OnchainCircuitBreaker::isOpen(self::CHAIN));
        self::assertSame([], \BccBreakerStore::$lockAttempts, 'no probe slot may be claimed inside the cooldown');
    }

    /** Once the cooldown elapses exactly one caller is admitted. */
    public function testHalfOpenAdmitsExactlyOneProbe(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 5, time() - 301);

        self::assertSame(OnchainCircuitBreaker::PHASE_HALF_OPEN, OnchainCircuitBreaker::phase(self::CHAIN));

        // First caller wins the probe slot: isOpen() returns false = go.
        self::assertFalse(OnchainCircuitBreaker::isOpen(self::CHAIN), 'the first caller owns the probe');
        // Every other concurrent caller is still blocked.
        self::assertTrue(OnchainCircuitBreaker::isOpen(self::CHAIN), 'a second caller must not also probe');
        self::assertTrue(OnchainCircuitBreaker::isOpen(self::CHAIN));
    }

    /** ⚠ phase() must NOT consume the probe slot. */
    public function testPhaseDoesNotConsumeTheProbeSlot(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 5, time() - 301);

        OnchainCircuitBreaker::phase(self::CHAIN);
        OnchainCircuitBreaker::phase(self::CHAIN);

        self::assertSame([], \BccBreakerStore::$lockAttempts, 'looking must never be probing');
        self::assertFalse(
            OnchainCircuitBreaker::isOpen(self::CHAIN),
            'a real worker must still be able to claim the probe after any number of observations'
        );
    }

    /** A successful probe closes the breaker and frees the slot. */
    public function testSuccessfulProbeClosesTheBreaker(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 5, time() - 301);
        self::assertFalse(OnchainCircuitBreaker::isOpen(self::CHAIN)); // claim probe

        OnchainCircuitBreaker::recordSuccess(self::CHAIN);

        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(self::CHAIN));
        self::assertNull(\BccBreakerStore::counter(self::CHAIN));
        self::assertSame([], \BccBreakerStore::$locks, 'the probe lock must be released on success');
    }

    /** A failed probe restarts the cooldown rather than looping half-open. */
    public function testFailedProbeRestartsTheCooldown(): void
    {
        $openedAt = time() - 301;
        \BccBreakerStore::seedOpen(self::CHAIN, 5, $openedAt);
        self::assertFalse(OnchainCircuitBreaker::isOpen(self::CHAIN)); // claim probe

        OnchainCircuitBreaker::recordFailure(self::CHAIN);

        $state = \BccBreakerStore::state(self::CHAIN);
        self::assertNotNull($state);
        self::assertGreaterThan($openedAt, (int) $state['opened_at'], 'the cooldown must restart');
        self::assertSame(OnchainCircuitBreaker::PHASE_OPEN, OnchainCircuitBreaker::phase(self::CHAIN));
    }

    // ── the stale-counter repair ────────────────────────────────────────

    /**
     * ⚠ THE REGRESSION FIXTURE: a counter of 768 with no matching state.
     *
     * This is the staging value measured on chain 18 on 2026-09-08. Before
     * PR 7.6 the next failure incremented it to 769, saw the threshold met
     * and `opened_at === 0`, and opened the breaker on ONE request.
     */
    public function testStaleCounterOf768WithoutOpenStateDoesNotReopenInstantly(): void
    {
        \BccBreakerStore::seedOrphanCounter(self::CHAIN, 768);

        self::assertSame(
            OnchainCircuitBreaker::PHASE_CLOSED,
            OnchainCircuitBreaker::phase(self::CHAIN),
            'a counter with no state is not an open breaker'
        );

        OnchainCircuitBreaker::recordFailure(self::CHAIN);

        self::assertSame(
            1,
            \BccBreakerStore::counter(self::CHAIN),
            'the window is gone, so counting restarts at the failure in hand'
        );
        self::assertSame(
            OnchainCircuitBreaker::PHASE_CLOSED,
            OnchainCircuitBreaker::phase(self::CHAIN),
            'one failure must never open a threshold-5 breaker'
        );
        self::assertFalse(OnchainCircuitBreaker::isOpen(self::CHAIN));
    }

    /** The production value behaves identically — the fix is not 768-specific. */
    public function testStaleCounterOf3022BehavesTheSameWay(): void
    {
        \BccBreakerStore::seedOrphanCounter(self::CHAIN, 3022);

        OnchainCircuitBreaker::recordFailure(self::CHAIN);

        self::assertSame(1, \BccBreakerStore::counter(self::CHAIN));
        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(self::CHAIN));
    }

    /** …and it still opens normally once five real failures accumulate. */
    public function testAfterReconciliationTheBreakerStillOpensOnFive(): void
    {
        \BccBreakerStore::seedOrphanCounter(self::CHAIN, 768);

        $this->failTimes(5);

        self::assertSame(5, \BccBreakerStore::counter(self::CHAIN));
        self::assertSame(OnchainCircuitBreaker::PHASE_OPEN, OnchainCircuitBreaker::phase(self::CHAIN));
    }

    /**
     * ⚠ A COHERENT OPEN BREAKER IS NEVER TOUCHED BY THE RECONCILIATION.
     *
     * The repair must not become a reset. Where the state is intact the
     * counter keeps climbing exactly as before.
     */
    public function testAnIntactOpenBreakerIsNotReconciledAway(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 9, time() - 10);

        OnchainCircuitBreaker::recordFailure(self::CHAIN);

        self::assertSame(10, \BccBreakerStore::counter(self::CHAIN), 'an intact window keeps counting');
        self::assertSame(OnchainCircuitBreaker::PHASE_OPEN, OnchainCircuitBreaker::phase(self::CHAIN));
    }

    /** Reconciliation is idempotent and leaves no option growth. */
    public function testReconciliationIsIdempotentAndBounded(): void
    {
        \BccBreakerStore::seedOrphanCounter(self::CHAIN, 768);

        OnchainCircuitBreaker::recordFailure(self::CHAIN);
        OnchainCircuitBreaker::recordSuccess(self::CHAIN);
        OnchainCircuitBreaker::recordSuccess(self::CHAIN);

        $counterKeys = array_filter(
            array_keys(\BccBreakerStore::$options),
            static fn(string $k): bool => str_starts_with($k, '_bcc_cb_counter_')
        );
        self::assertSame([], $counterKeys, 'repeated success must not leave counter rows behind');
    }

    // ── isolation ───────────────────────────────────────────────────────

    /** One chain's failures never open another chain's breaker. */
    public function testBreakersAreIsolatedPerChain(): void
    {
        $this->failTimes(6, 8);

        self::assertSame(OnchainCircuitBreaker::PHASE_OPEN, OnchainCircuitBreaker::phase(8));
        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(13));
        self::assertFalse(OnchainCircuitBreaker::isOpen(13));
    }

    /** A DB failure on the counter still records the failure signal. */
    public function testACounterWriteFailureStillCountsTheFailure(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 3, 0);
        \BCC\Trust\Onchain\Repositories\OnchainCircuitBreakerRepository::$failIncrement = true;

        OnchainCircuitBreaker::recordFailure(self::CHAIN);

        $state = \BccBreakerStore::state(self::CHAIN);
        self::assertNotNull($state);
        self::assertSame(4, (int) $state['failures'], 'the fallback path must not drop the signal');
    }
}
