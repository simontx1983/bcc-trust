<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Core\DB\AdvisoryLock;
use BCC\Trust\Onchain\Services\HallProvisioningService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * HALL CREATION IS SERIALIZED PER CHAIN, AND IT FAILS CLOSED.
 *
 * ── THE DEFECT THIS PINS (#123) ─────────────────────────────────────────
 * `provisionOne()` was an unguarded check-then-create. The existence read
 * (`findHallForChain()`) and the duplicate-preventing write
 * (`writeHallMeta()`) were not atomic, and `_bcc_chain_tag` is plain
 * `wp_postmeta` with NO unique index — so the database could not reject a
 * second Hall for one chain. Two overlapping runs could both read "no Hall
 * here" and both create one.
 *
 * ⚠ SEQUENTIAL RETRIES WERE ALWAYS SAFE. That is what made this easy to
 * miss: run it twice in a row and the second run reads the marker and skips.
 * Only the OVERLAP was unguarded, and nothing anywhere proved otherwise —
 * no lock, no lease, no constraint on the whole path.
 *
 * ⚠ A duplicate Hall is not a transient glitch. It is a real `peepso-group`
 * post with its own id, members and content; `/halls` would show two Halls
 * for one chain and `findHallForChain()` would resolve ambiguously for ever.
 * So the fix has to be a lock, not a retry.
 *
 * ── WHAT IS OBSERVED HERE, AND WHAT IS NOT ──────────────────────────────
 * The lock double has REAL mutual exclusion, so contention is driven from
 * one process: the test takes the lock, then calls the service. Every
 * acquire and release is recorded in order, so "released" is observed rather
 * than inferred from a call that happened to return.
 *
 * ⚠ IT DOES NOT MODEL WAITING, and this suite does not claim it does. The
 * real `GET_LOCK(name, 5)` blocks before refusing, which is what usually
 * turns a double-submit into `exists` rather than `busy`. What is pinned
 * instead is that the wait asked for is NOT ZERO — because every outcome
 * assertion below would still pass if it were, and the operator-visible
 * behaviour would be meaningfully worse.
 *
 * ⚠⚠ The lock is the ONLY new protection. A unique index on the marker
 * would be real defence in depth and is deliberately NOT in scope here.
 *
 * ⚠ The `bcc_hall_provision` cron is retired, so there is exactly one entry
 * point — `ChainsPage::handle_hall_create()`. These cases scope to it.
 */
#[CoversClass(HallProvisioningService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HallProvisioningLockTest extends TestCase
{
    private const CHAIN_ID = 42;
    private const OTHER_CHAIN = 99;
    private const LOCK = 'bcc_hall_provision:42';

    private function boot(): void
    {
        require_once __DIR__ . '/../Stubs/hall-provision-stubs.php';

        \HallTestState::reset();
        \FakePeepSoLedger::reset();
        AdvisoryLock::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Core\PeepSo\PeepSoGroupWriter::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\HallRepository::reset();

        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN_ID, 'cosmos', 'Cosmos');
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::OTHER_CHAIN, 'osmosis', 'Osmosis');
    }

    private function provision(int $chainId = self::CHAIN_ID): array
    {
        return (new HallProvisioningService())->provisionOne($chainId);
    }

    /** Nothing was created, by every handle we have. */
    private function assertNothingCreated(string $why): void
    {
        self::assertSame([], \HallTestState::$created, $why . ': no group may be constructed');
        self::assertSame([], \BCC\Trust\Onchain\Repositories\HallRepository::$metaWrites, $why . ': no marker may be written');
        self::assertSame([], \BCC\Trust\Onchain\Repositories\HallRepository::$halls, $why . ': no chain may be linked');
        self::assertSame([], \BCC\Trust\Core\Security\AuditLogger::actions(), $why . ': nothing to audit');
    }

    // ── 1. CONTENTION: exactly one proceeds ─────────────────────────────

    /**
     * A peer holds this chain's lock. The run must refuse and create
     * NOTHING — which is the whole point: the alternative is a duplicate
     * Hall that nothing can later undo automatically.
     */
    public function testAPeerHoldingThisChainsLockCreatesNothing(): void
    {
        $this->boot();

        // The peer. A real second request would hold this on its own MySQL
        // session; here the test holds it, which is the same exclusion.
        self::assertTrue(AdvisoryLock::acquire(self::LOCK, 0), 'precondition: the peer takes the lock');

        $result = $this->provision();

        self::assertSame('busy', $result['status']);
        self::assertSame(HallProvisioningService::FAIL_LOCK_CONTENDED, $result['failure_code']);
        self::assertSame(0, $result['group_id']);
        $this->assertNothingCreated('a contended run');
    }

    /**
     * THE POSITIVE CONTROL for every assertion above. With the lock free,
     * the identical call creates — so "created nothing" is a consequence of
     * the contention and not of a service that cannot create at all.
     */
    public function testTheSameCallCreatesWhenTheLockIsFree(): void
    {
        $this->boot();

        $result = $this->provision();

        self::assertSame('created', $result['status']);
        self::assertGreaterThan(0, $result['group_id']);
        self::assertCount(1, \HallTestState::$created);
    }

    /**
     * ⭐ THE EXISTENCE READ IS NEVER REACHED UNDER CONTENTION.
     *
     * This is the assertion that proves the critical section starts in the
     * right place. If the lock sat AFTER the existence check — the obvious
     * wrong way to write it — the TOCTOU window would be exactly as wide as
     * before, and every outcome assertion in this file would still pass.
     */
    public function testTheExistenceCheckIsNeverReachedUnderContention(): void
    {
        $this->boot();
        AdvisoryLock::acquire(self::LOCK, 0);

        $this->provision();

        self::assertSame(
            [],
            \BCC\Trust\Onchain\Repositories\HallRepository::$existenceReads,
            'the existence read must happen INSIDE the lock, so a contended run never performs it'
        );
    }

    /** And it IS performed when the lock is free — the other direction. */
    public function testTheExistenceCheckIsPerformedInsideTheLock(): void
    {
        $this->boot();

        $this->provision();

        self::assertContains(
            self::CHAIN_ID,
            \BCC\Trust\Onchain\Repositories\HallRepository::$existenceReads,
            'anti-vacuity: the read must really happen on the uncontended path'
        );
    }

    /**
     * ⚠ THE LOCK IS PER CHAIN. Two administrators provisioning two different
     * chains must not block each other. A global lock name would pass every
     * contention case above and quietly serialize unrelated work.
     */
    public function testALockHeldForADifferentChainDoesNotBlock(): void
    {
        $this->boot();
        AdvisoryLock::acquire('bcc_hall_provision:' . self::OTHER_CHAIN, 0);

        $result = $this->provision(self::CHAIN_ID);

        self::assertSame('created', $result['status'], 'a different chain must be unaffected');
    }

    /** The name itself, pinned, so the per-chain property cannot drift. */
    public function testTheLockNameIsScopedToTheChain(): void
    {
        $this->boot();

        $this->provision();

        self::assertSame([self::LOCK], AdvisoryLock::$acquired);
    }

    /**
     * FAIL CLOSED when the primitive itself cannot answer. `GET_LOCK`
     * returning NULL (a driver or permission error) and a peer holding the
     * lock are indistinguishable at this call site, and both must mean
     * "create nothing". An error path that created would be worse than the
     * bug it replaced.
     */
    public function testAnUnavailableLockPrimitiveFailsClosed(): void
    {
        $this->boot();
        AdvisoryLock::$acquirable = false;

        $result = $this->provision();

        self::assertSame('busy', $result['status']);
        self::assertSame(HallProvisioningService::FAIL_LOCK_CONTENDED, $result['failure_code']);
        $this->assertNothingCreated('an unavailable lock primitive');
    }

    /**
     * The wait is not zero.
     *
     * Every outcome assertion in this file passes with `timeout = 0`, so
     * without this the five-second wait could be mutated away silently —
     * and that wait is what turns the ordinary double-submit into `exists`
     * (the useful answer) rather than `busy`.
     */
    public function testTheAcquisitionAsksForANonZeroWait(): void
    {
        $this->boot();

        $this->provision();

        self::assertCount(1, AdvisoryLock::$timeouts);
        self::assertGreaterThan(0, AdvisoryLock::$timeouts[0], 'a zero wait makes a double-submit answer "busy"');

        $declared = new \ReflectionClass(HallProvisioningService::class);
        self::assertSame(
            $declared->getConstant('LOCK_WAIT_SECONDS'),
            AdvisoryLock::$timeouts[0],
            'and it must be the declared constant, not a literal that can drift from it'
        );
    }

    // ── 2. RELEASE: on every path, including a throw ────────────────────

    public function testTheLockIsReleasedAfterASuccessfulCreate(): void
    {
        $this->boot();

        self::assertSame('created', $this->provision()['status']);

        self::assertSame([self::LOCK], AdvisoryLock::$released);
        self::assertSame([], AdvisoryLock::$held, 'nothing may still be held');
    }

    public function testTheLockIsReleasedOnTheExistsPath(): void
    {
        $this->boot();
        \BCC\Trust\Onchain\Repositories\HallRepository::$halls[self::CHAIN_ID] = 900;

        self::assertSame('exists', $this->provision()['status']);

        self::assertSame([self::LOCK], AdvisoryLock::$released);
        self::assertSame([], AdvisoryLock::$held);
    }

    /**
     * ⭐ A SUBSCRIBER THROWS OUT OF THE FAN-OUT, and the lock is still freed.
     *
     * `do_action('bcc_hall_provisioned', …)` is a documented stable hook, so
     * a third-party subscriber throwing is realistic — and it is caught by
     * NOTHING in the service, which is exactly why it is the right probe for
     * a `finally`.
     *
     * ⚠ `HallTestState::$createThrows` cannot test this: `createHallGroup()`
     * catches every Throwable and returns 0, so that exception never escapes
     * and a `finally` would never be exercised. Recorded because it is the
     * hook one would reach for first.
     */
    public function testTheLockIsReleasedWhenASubscriberThrows(): void
    {
        $this->boot();
        \HallTestState::$throwOnAction = 'bcc_hall_provisioned';

        try {
            $this->provision();
            self::fail('the subscriber exception must propagate, not be swallowed');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('bcc_hall_provisioned', $e->getMessage());
        }

        self::assertSame([self::LOCK], AdvisoryLock::$released, 'the finally must have run');
        self::assertSame([], AdvisoryLock::$held);
    }

    /**
     * And a throw from EARLIER in the critical section — the repository
     * write failing closed, before the chain is linked. Two different depths,
     * because a `finally` placed inside `createHall()` rather than around it
     * would cover one and not the other.
     */
    public function testTheLockIsReleasedWhenTheRepositoryWriteThrows(): void
    {
        $this->boot();
        \BCC\Trust\Onchain\Repositories\HallRepository::$writeMetaThrows = true;

        try {
            $this->provision();
            self::fail('the repository exception must propagate');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('writeHallMeta', $e->getMessage());
        }

        self::assertSame([self::LOCK], AdvisoryLock::$released);
        self::assertSame([], AdvisoryLock::$held);
    }

    /**
     * ⭐ THE ASSERTION THAT ACTUALLY MATTERS AFTER AN EXCEPTION: a later run
     * can still acquire.
     *
     * `release()` having been called is evidence about the code; the lock
     * being genuinely free is evidence about the system. A stuck lock would
     * leave Hall creation permanently answering `busy` with no way to clear
     * it short of a MySQL restart.
     */
    public function testASubsequentRunCanAcquireAfterAnException(): void
    {
        $this->boot();
        \HallTestState::$throwOnAction = 'bcc_hall_provisioned';

        try {
            $this->provision();
        } catch (\RuntimeException $e) {
            // expected
        }

        // A fresh request, nothing reset but the hook.
        \HallTestState::$throwOnAction = null;
        $second = $this->provision();

        self::assertNotSame('busy', $second['status'], 'the lock must no longer be held');
        self::assertSame('exists', $second['status'], 'and the first run had already linked the Hall');
        self::assertCount(2, AdvisoryLock::$acquired, 'two runs, two acquisitions');
        self::assertCount(2, AdvisoryLock::$released, 'and two releases');
    }

    /**
     * ⚠ PLAIN STRINGS, NOT CLOSURES. `#[RunTestsInSeparateProcesses]`
     * serializes the test case to hand it to the subprocess, and a provider
     * returning closures dies with "Serialization of 'Closure' is not
     * allowed" — an error inside PHPUnit itself, not a test failure. So each
     * case names its arrangement and `arrange()` performs it in-process.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function lockedPaths(): array
    {
        return [
            'created'             => ['none', 'created'],
            'exists'              => ['exists', 'exists'],
            'marker refused'      => ['marker_refused', 'error'],
            'group create failed' => ['create_zero', 'error'],
        ];
    }

    private function arrange(string $kind): void
    {
        switch ($kind) {
            case 'none':
                return;
            case 'exists':
                \BCC\Trust\Onchain\Repositories\HallRepository::$halls[self::CHAIN_ID] = 900;
                return;
            case 'marker_refused':
                \BCC\Trust\Onchain\Repositories\HallRepository::$writeMetaWorks = false;
                return;
            case 'create_zero':
                \HallTestState::$createReturnsZero = true;
                return;
            default:
                self::fail('unknown arrangement: ' . $kind);
        }
    }

    /**
     * ⚠⚠ EXACTLY ONE ACQUIRE AND ONE RELEASE, on every path.
     *
     * MySQL's `GET_LOCK` is REENTRANT PER SESSION: a second acquire of the
     * same name in the same request succeeds and needs its own release, so an
     * unmatched pair leaks the lock for the rest of the request. Counting
     * both sides is the only way that stays pinned.
     */
    #[DataProvider('lockedPaths')]
    public function testExactlyOneAcquireAndOneReleasePerRun(string $kind, string $expected): void
    {
        $this->boot();
        $this->arrange($kind);

        $result = $this->provision();

        self::assertSame($expected, $result['status'], 'the path must be the one this case intends');
        self::assertCount(1, AdvisoryLock::$acquired, 'exactly one acquire');
        self::assertCount(1, AdvisoryLock::$released, 'exactly one release');
        self::assertSame([], AdvisoryLock::$held);
    }

    // ── 3. The cheap refusals take no lock at all ───────────────────────

    /**
     * @return array<string, array{0: int}>
     */
    public static function cheapRefusals(): array
    {
        return [
            'non-positive chain id' => [0],
            'negative chain id'     => [-1],
            'unknown chain'         => [12345],
        ];
    }

    /**
     * A refusal that creates nothing must not take the lock, or a stream of
     * malformed requests could contend with a real provision — and with a
     * five-second wait each, that is a cheap way to stall the real one.
     */
    #[DataProvider('cheapRefusals')]
    public function testCheapRefusalsTakeNoLock(int $chainId): void
    {
        $this->boot();

        $result = $this->provision($chainId);

        self::assertNotSame('created', $result['status']);
        self::assertSame([], AdvisoryLock::$acquired, 'no lock may be requested');
        self::assertSame([], AdvisoryLock::$released);
    }

    /** An unnamed chain is refused before the lock too. */
    public function testAnUnnamedChainTakesNoLock(): void
    {
        $this->boot();
        \BCC\Trust\Onchain\Repositories\ChainRepository::reset();
        \BCC\Trust\Onchain\Repositories\ChainRepository::seed(self::CHAIN_ID, 'cosmos', '');

        $result = $this->provision();

        self::assertSame(HallProvisioningService::FAIL_CHAIN_UNNAMED, $result['failure_code']);
        self::assertSame([], AdvisoryLock::$acquired);
    }

    // ── 4. No lock-free way in ──────────────────────────────────────────

    /**
     * THE STRUCTURAL GUARANTEE. `createHall()` performs the marker write, so
     * if anything could call it without holding the lock the critical section
     * would have a side door. Private is what forbids that, and `private` is
     * one keyword away from not being.
     */
    public function testTheCreatePathHasNoLockFreeEntryPoint(): void
    {
        $r = new \ReflectionClass(HallProvisioningService::class);

        self::assertTrue($r->hasMethod('createHall'), 'anti-vacuity: the method must exist to be private');
        self::assertTrue(
            $r->getMethod('createHall')->isPrivate(),
            'createHall() writes the duplicate-preventing marker and must be reachable only from inside the lock'
        );

        // And the one public entry point is still the only public one.
        $public = array_values(array_map(
            static fn(\ReflectionMethod $m): string => $m->getName(),
            $r->getMethods(\ReflectionMethod::IS_PUBLIC)
        ));
        sort($public);
        self::assertSame(['provisionOne'], $public, 'provisionOne() must remain the only public entry point');
    }
}
