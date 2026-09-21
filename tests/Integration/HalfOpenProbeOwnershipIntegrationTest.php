<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\DB\AdvisoryLock;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Half-open recovery-probe OWNERSHIP, against a REAL MySQL/MariaDB advisory lock.
 *
 * ── WHY THIS CANNOT BE A UNIT TEST ──────────────────────────────────────
 * The whole defect lives in `GET_LOCK` semantics that no reasonable double
 * reproduces. `GET_LOCK` is **reentrant per session**: the same connection may
 * take the same lock twice, and it then needs TWO `RELEASE_LOCK`s to let
 * anyone else have it. The test double used by the unit suite is not
 * reentrant, so under it a second claim in one session simply fails — the
 * opposite of production, and the reason the leak was invisible.
 *
 * Measured identically on MySQL 8.0.35 and MariaDB 11.8.9 (2026-09-21):
 *
 *   A: GET_LOCK(k,0) -> 1      A: GET_LOCK(k,0) -> 1   (reentrant)
 *   B: GET_LOCK(k,0) -> 0      (another session is excluded)
 *   A: RELEASE_LOCK(k) -> 1    B: GET_LOCK(k,0) -> 0   (STILL held)
 *   A: RELEASE_LOCK(k) -> 1    B: GET_LOCK(k,0) -> 1   (now free)
 *   A: RELEASE_LOCK(k) -> 0    (releasing one you do not hold is a no-op)
 *
 * ── THE TWO FAILURES THIS FILE PINS ─────────────────────────────────────
 *   1. EARLY-RETURN LEAK. An outer preflight calls the MUTATING
 *      {@see OnchainCircuitBreaker::isOpen()} to decide whether to start, wins
 *      the probe, and then returns for a reason that has nothing to do with
 *      the provider — exhausted budget, missing driver, unsupported
 *      capability, bad configuration. Nothing releases the lock, so the chain
 *      cannot be probed by anyone until that worker's DB session closes.
 *   2. DOUBLE CLAIM. The outer check and `ApiRetry`'s own check both claim in
 *      ONE session, so the reentrant count reaches 2 while `ApiRetry` releases
 *      once. The probe is stranded even on the fully successful path.
 *
 * Both are written against the REAL breaker and the REAL lock, and observed
 * from a SECOND connection — the only vantage point from which "is this
 * probe available to another worker?" is a fact rather than an assumption.
 */
#[CoversClass(OnchainCircuitBreaker::class)]
#[Group('mariadb')]
final class HalfOpenProbeOwnershipIntegrationTest extends TestCase
{
    private const CHAIN = 7714;

    private static ?\mysqli $observer = null;

    private function lockName(): string
    {
        return 'bcc_cb_probe_' . self::CHAIN;
    }

    /**
     * A SECOND connection, standing in for another worker on the cluster.
     *
     * ⚠ IT MUST NOT BE THE SUITE'S OWN CONNECTION. Asking the connection that
     * holds a lock whether the lock is free always answers "yes" — reentrancy
     * guarantees it — which is exactly how a stranded probe reads as healthy.
     */
    private function observer(): \mysqli
    {
        if (self::$observer instanceof \mysqli) {
            return self::$observer;
        }

        $host = getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('BCC_TEST_DB_PORT') ?: 3306);
        $user = getenv('BCC_TEST_DB_USER') ?: 'root';
        $pass = getenv('BCC_TEST_DB_PASS') ?: '';
        $name = getenv('BCC_TEST_DB_NAME') ?: 'bcc_test';

        $conn = new \mysqli($host, $user, $pass, $name, $port);
        self::assertSame(0, $conn->connect_errno, 'the observer connection must succeed');
        self::$observer = $conn;

        return $conn;
    }

    /** Can another worker take this probe right now? Answered from the observer. */
    private function probeIsAvailableToAnotherWorker(): bool
    {
        $conn = $this->observer();
        $r = $conn->query("SELECT GET_LOCK('" . $this->lockName() . "', 0)");
        $got = (int) ($r->fetch_row()[0] ?? 0);
        if ($got === 1) {
            // Put it straight back; this method is an observation, not a claim.
            $conn->query("SELECT RELEASE_LOCK('" . $this->lockName() . "')");
        }

        return $got === 1;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Release anything this session still holds from a previous test, so
        // one leaking case cannot make the next one look broken.
        for ($i = 0; $i < 8; $i++) {
            AdvisoryLock::release($this->lockName());
        }
        OnchainCircuitBreaker::forgetForEndpointChange(self::CHAIN);
    }

    protected function tearDown(): void
    {
        for ($i = 0; $i < 8; $i++) {
            AdvisoryLock::release($this->lockName());
        }
        OnchainCircuitBreaker::forgetForEndpointChange(self::CHAIN);
        parent::tearDown();
    }

    /** Put the breaker in HALF-OPEN: tripped, cooldown elapsed. */
    private function seedHalfOpen(): void
    {
        for ($i = 0; $i < OnchainCircuitBreaker::FAILURE_THRESHOLD; $i++) {
            OnchainCircuitBreaker::recordFailure(self::CHAIN);
        }
        $state = [
            'failures'  => OnchainCircuitBreaker::FAILURE_THRESHOLD,
            'opened_at' => time() - (OnchainCircuitBreaker::COOLDOWN_SECONDS + 60),
        ];
        set_transient('bcc_cb_' . self::CHAIN, $state, OnchainCircuitBreaker::CACHE_TTL);
        wp_cache_set('cb_' . self::CHAIN, $state, 'bcc_circuit', OnchainCircuitBreaker::CACHE_TTL);

        self::assertSame(
            OnchainCircuitBreaker::PHASE_HALF_OPEN,
            OnchainCircuitBreaker::phase(self::CHAIN),
            'precondition: the breaker must be HALF-OPEN'
        );
    }

    // ── 1. the real lock's semantics, pinned ────────────────────────────

    /**
     * ⚠ THE GROUND TRUTH THE WHOLE FILE RESTS ON. If any of this changes,
     * every conclusion below changes with it.
     */
    public function testGetLockIsReentrantPerSessionAndNeedsMatchingReleases(): void
    {
        $k = $this->lockName();

        self::assertTrue(AdvisoryLock::acquire($k, 0), 'first claim succeeds');
        self::assertFalse($this->probeIsAvailableToAnotherWorker(), 'another worker is excluded');

        self::assertTrue(AdvisoryLock::acquire($k, 0), 'the SAME session may claim again — reentrant');
        self::assertFalse($this->probeIsAvailableToAnotherWorker(), 'still excluded');

        AdvisoryLock::release($k);
        self::assertFalse(
            $this->probeIsAvailableToAnotherWorker(),
            'ONE release after TWO claims still leaves the lock held — this is the defect mechanism'
        );

        AdvisoryLock::release($k);
        self::assertTrue($this->probeIsAvailableToAnotherWorker(), 'matched releases free it');

        AdvisoryLock::release($k);
        self::assertTrue($this->probeIsAvailableToAnotherWorker(), 'releasing one you do not hold is a no-op');
    }

    /** Another worker cannot take a probe that is genuinely in use. */
    public function testAnotherWorkerCannotTakeAnActiveProbe(): void
    {
        $this->seedHalfOpen();

        self::assertFalse(
            OnchainCircuitBreaker::isOpen(self::CHAIN),
            'the first caller wins the probe and is let through'
        );
        self::assertFalse(
            $this->probeIsAvailableToAnotherWorker(),
            'while that probe is in flight no other worker may take it'
        );

        OnchainCircuitBreaker::releaseProbe(self::CHAIN);
        self::assertTrue($this->probeIsAvailableToAnotherWorker(), 'and it is available again afterwards');
    }

    // ── 2. the non-mutating reader ──────────────────────────────────────

    /**
     * An outer preflight must be able to ask "is this chain resting?" without
     * consuming the one probe the half-open window allows.
     */
    public function testTheNonMutatingCheckNeverClaimsTheProbe(): void
    {
        $this->seedHalfOpen();

        self::assertFalse(
            OnchainCircuitBreaker::isResting(self::CHAIN),
            'a half-open chain is NOT resting — it is eligible for one probe, so a preflight lets the caller continue'
        );
        self::assertTrue(
            $this->probeIsAvailableToAnotherWorker(),
            'and asking the question must not have taken the probe'
        );

        // Asking repeatedly is still free.
        for ($i = 0; $i < 5; $i++) {
            OnchainCircuitBreaker::isResting(self::CHAIN);
        }
        self::assertTrue($this->probeIsAvailableToAnotherWorker(), 'five more looks, still untaken');
    }

    /** While OPEN and inside its cooldown, the preflight says "skip" — still without claiming. */
    public function testTheNonMutatingCheckReportsRestingWhileOpen(): void
    {
        for ($i = 0; $i < OnchainCircuitBreaker::FAILURE_THRESHOLD; $i++) {
            OnchainCircuitBreaker::recordFailure(self::CHAIN);
        }
        self::assertSame(OnchainCircuitBreaker::PHASE_OPEN, OnchainCircuitBreaker::phase(self::CHAIN), 'precondition');

        self::assertTrue(OnchainCircuitBreaker::isResting(self::CHAIN), 'an open, cooling chain is resting');
        self::assertTrue($this->probeIsAvailableToAnotherWorker(), 'and the probe slot was not touched');
    }

    /** A closed chain is never resting. */
    public function testAClosedChainIsNotResting(): void
    {
        self::assertSame(OnchainCircuitBreaker::PHASE_CLOSED, OnchainCircuitBreaker::phase(self::CHAIN), 'precondition');
        self::assertFalse(OnchainCircuitBreaker::isResting(self::CHAIN));
        self::assertTrue($this->probeIsAvailableToAnotherWorker());
    }

    // ── 3. the early-return leak ────────────────────────────────────────

    /**
     * ⚠ THE DEFECT, AS A SCENARIO. A preflight decides to start, wins the
     * probe, and then returns for a reason that never touches the provider.
     *
     * With a MUTATING preflight this leaves the lock held and the chain
     * unprobeable. With the non-mutating one it costs nothing.
     */
    public function testAPreflightThatExitsEarlyLeavesTheProbeAvailable(): void
    {
        $this->seedHalfOpen();

        // The shape of all four outer callers: check the breaker, then bail
        // out on budget / configuration / driver / capability.
        $ranTransport = false;
        if (!OnchainCircuitBreaker::isResting(self::CHAIN)) {
            $budgetExhausted = true;      // stands in for the CU-budget gate
            if ($budgetExhausted) {
                // early return — no provider contacted
            } else {
                $ranTransport = true;
            }
        }

        self::assertFalse($ranTransport, 'the scenario must not have contacted a provider');
        self::assertTrue(
            $this->probeIsAvailableToAnotherWorker(),
            'a preflight that never reached the wire must leave the probe for a worker that will'
        );
    }

    // ── 4. one owner: ApiRetry ──────────────────────────────────────────

    /**
     * The transport layer claims, uses and releases — and the probe is free
     * afterwards even though the outer preflight also looked at the breaker.
     *
     * ⚠ ASSERTS AVAILABILITY, NOT A RELEASE COUNT. Settlement releases and so
     * does the `finally`; what matters to the next worker is whether the lock
     * is free, which is what the observer measures.
     */
    public function testAfterAPreflightAndATransportRequestNoLockIsHeld(): void
    {
        $this->seedHalfOpen();

        // Outer preflight — non-mutating.
        self::assertFalse(OnchainCircuitBreaker::isResting(self::CHAIN));

        // Transport claims the probe for the request it is about to make.
        self::assertFalse(OnchainCircuitBreaker::isOpen(self::CHAIN), 'transport wins the probe');
        self::assertFalse($this->probeIsAvailableToAnotherWorker(), 'held while in flight');

        // …and settles + releases the way ApiRetry does on every exit path.
        OnchainCircuitBreaker::recordFailure(self::CHAIN);
        OnchainCircuitBreaker::releaseProbe(self::CHAIN);

        self::assertTrue(
            $this->probeIsAvailableToAnotherWorker(),
            'a completed transport request must leave no lock held'
        );
    }

    /**
     * ⚠ THE DOUBLE-CLAIM STRAND. Two claims in one session need two releases;
     * ApiRetry issues one. This is why the outer check must not claim — and
     * it strands the probe even when everything succeeds.
     */
    public function testTwoClaimsInOneSessionStrandTheProbeAfterASingleRelease(): void
    {
        $k = $this->lockName();

        self::assertTrue(AdvisoryLock::acquire($k, 0), 'outer preflight claims');
        self::assertTrue(AdvisoryLock::acquire($k, 0), 'transport claims again, same session');

        // What ApiRetry does on a successful request: one release.
        AdvisoryLock::release($k);

        self::assertFalse(
            $this->probeIsAvailableToAnotherWorker(),
            'THE STRAND: after a successful request the probe is still held, because it was claimed twice'
        );

        AdvisoryLock::release($k);
        self::assertTrue($this->probeIsAvailableToAnotherWorker(), 'only a matching second release frees it');
    }

    /** A throwing transport request must still leave the probe free. */
    public function testAThrowingRequestLeavesNoLockHeld(): void
    {
        $this->seedHalfOpen();

        try {
            if (!OnchainCircuitBreaker::isOpen(self::CHAIN)) {
                try {
                    throw new \RuntimeException('provider client blew up');
                } finally {
                    OnchainCircuitBreaker::releaseProbe(self::CHAIN);
                }
            }
            self::fail('the probe should have been won');
        } catch (\RuntimeException $e) {
            self::assertSame('provider client blew up', $e->getMessage());
        }

        self::assertTrue($this->probeIsAvailableToAnotherWorker(), 'the finally released it');
    }
}
