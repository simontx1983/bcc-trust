<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Four readers, one lifecycle. They must never disagree.
 *
 * ── THE DEFECT THIS FILE ENCODES ────────────────────────────────────────
 * PR 7.6 gave the breaker a single truthful lifecycle — and then left two
 * older readers computing their own. `getAllStatus()` and `getStaleChains()`
 * both did:
 *
 *     $elapsed = time() - $openedAt;
 *     $status  = $elapsed >= COOLDOWN_SECONDS ? 'half-open' : 'open';
 *
 * With `opened_at = 0` — the threshold reached but no cooldown started, which
 * is exactly the state a stale counter produces — `$elapsed` is the whole Unix
 * epoch. Both dashboards therefore reported **HALF-OPEN**, meaning "one probe
 * is allowed through", while `isOpen()` and `phase()` were treating that same
 * state as **OPEN** and refusing every request.
 *
 * An administrator reading a status page would have concluded traffic was
 * flowing again. That is the same failure shape this project has already paid
 * for once: a dashboard reporting a healthy scanner that was scanning nothing.
 *
 * ⚠ SO EVERY CASE BELOW IS ASSERTED ACROSS ALL FOUR CONSUMERS AT ONCE. A test
 * that checked `phase()` alone would have passed throughout the defect.
 */
#[CoversClass(OnchainCircuitBreaker::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BreakerReaderAgreementTest extends TestCase
{
    private const CHAIN = 8;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/breaker-real-stubs.php';
        \BccBreakerStore::reset();
        \BCC\Trust\Onchain\Repositories\OnchainCircuitBreakerRepository::$failIncrement = false;
    }

    /**
     * The canonical phase each reader reports, normalised for comparison.
     *
     * ⚠ NORMALISED FOR CASING ONLY. The three surfaces publish three
     * spellings — `half_open`, `half-open`, `HALF-OPEN` — and those are part
     * of their output contracts. This collapses the spelling so the DECISION
     * can be compared; the spellings themselves are pinned separately by
     * {@see testEachReaderKeepsItsOwnCasing()}.
     *
     * @return array{phase: string, status: string, stale: string}
     */
    private function readAll(): array
    {
        $norm = static fn(string $v): string => str_replace('-', '_', strtolower($v));

        $status = OnchainCircuitBreaker::getAllStatus([self::CHAIN]);
        // ⚠ -1, NOT 0. getStaleChains() tests `age > maxAgeSec`, so 0 would
        // exclude a chain whose last success was stamped this very second —
        // precisely what recordSuccess() does — and the reader under test
        // would silently drop out of the comparison.
        $stale  = OnchainCircuitBreaker::getStaleChains([self::CHAIN], -1);

        self::assertArrayHasKey(self::CHAIN, $status, 'anti-vacuity: getAllStatus must report the chain');
        self::assertArrayHasKey(
            self::CHAIN,
            $stale,
            'anti-vacuity: the fixture must be stale enough for getStaleChains to include it'
        );

        return [
            'phase'  => $norm(OnchainCircuitBreaker::phase(self::CHAIN)),
            'status' => $norm((string) $status[self::CHAIN]['status']),
            'stale'  => $norm((string) $stale[self::CHAIN]['circuit_status']),
        ];
    }

    /** Assert every reader agrees on one canonical phase. */
    private function assertAllReadersSay(string $expected, string $why): void
    {
        $seen = $this->readAll();

        self::assertSame($expected, $seen['phase'], "phase(): {$why}");
        self::assertSame($expected, $seen['status'], "getAllStatus(): {$why}");
        self::assertSame($expected, $seen['stale'], "getStaleChains(): {$why}");

        // isOpen() is the fourth consumer. CLOSED must pass traffic; OPEN must
        // block it. HALF-OPEN is asserted separately because it has a side
        // effect and cannot be observed without consuming the probe slot.
        if ($expected === OnchainCircuitBreaker::PHASE_CLOSED) {
            self::assertFalse(OnchainCircuitBreaker::isOpen(self::CHAIN), "isOpen(): {$why}");
        } elseif ($expected === OnchainCircuitBreaker::PHASE_OPEN) {
            self::assertTrue(OnchainCircuitBreaker::isOpen(self::CHAIN), "isOpen(): {$why}");
        }
    }

    // ── 1-3. below the threshold ────────────────────────────────────────

    /** 1. No state at all. */
    public function testMissingStateIsClosedEverywhere(): void
    {
        $this->assertAllReadersSay(OnchainCircuitBreaker::PHASE_CLOSED, 'no state means closed');
    }

    /** 2. State present, zero failures. */
    public function testZeroFailuresIsClosedEverywhere(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 0, 0);

        $this->assertAllReadersSay(OnchainCircuitBreaker::PHASE_CLOSED, 'zero failures means closed');
    }

    /** 3. One short of the threshold. */
    public function testFourFailuresIsClosedEverywhere(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 4, 0);

        $this->assertAllReadersSay(OnchainCircuitBreaker::PHASE_CLOSED, 'four is below a threshold of five');
    }

    // ── 4. THE DEFECT ───────────────────────────────────────────────────

    /**
     * 4. ⚠ Threshold reached with NO cooldown timestamp.
     *
     * Before the fix, `phase()` and `isOpen()` said OPEN while both status
     * readers said HALF-OPEN, because `time() - 0` is the entire epoch.
     */
    public function testThresholdWithZeroOpenedAtIsOpenEverywhere(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 5, 0);

        $this->assertAllReadersSay(
            OnchainCircuitBreaker::PHASE_OPEN,
            'a missing timestamp cannot start a cooldown, so no probe is admitted'
        );
    }

    /** 12. …and neither status reader may ever say HALF-OPEN for it. */
    public function testStatusReadersNeverReportHalfOpenForZeroOpenedAt(): void
    {
        foreach ([5, 6, 50, 768, 3022] as $failures) {
            \BccBreakerStore::reset();
            \BccBreakerStore::seedOpen(self::CHAIN, $failures, 0);

            $status = OnchainCircuitBreaker::getAllStatus([self::CHAIN]);
            $stale  = OnchainCircuitBreaker::getStaleChains([self::CHAIN], -1);

            self::assertSame('open', $status[self::CHAIN]['status'], "failures={$failures}");
            self::assertSame('OPEN', $stale[self::CHAIN]['circuit_status'], "failures={$failures}");

            self::assertStringNotContainsStringIgnoringCase(
                'half',
                (string) $status[self::CHAIN]['status'],
                'a zero timestamp must never read as half-open'
            );
            self::assertStringNotContainsStringIgnoringCase(
                'half',
                (string) $stale[self::CHAIN]['circuit_status'],
                'a zero timestamp must never read as half-open'
            );
        }
    }

    // ── 5-7. the cooldown boundary ──────────────────────────────────────

    /** 5. Tripped, still resting. */
    public function testInsideCooldownIsOpenEverywhere(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 5, time() - 10);

        $this->assertAllReadersSay(OnchainCircuitBreaker::PHASE_OPEN, 'inside the cooldown');
    }

    /**
     * 6. EXACTLY at the boundary. `>=` means the probe is admitted.
     *
     * @param int $offset seconds subtracted from now
     */
    #[DataProvider('boundaryOffsets')]
    public function testTheCooldownBoundaryIsInclusiveEverywhere(int $offset, string $expected): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 5, time() - $offset);

        $seen = $this->readAll();

        self::assertSame($expected, $seen['phase'], "offset {$offset}s");
        self::assertSame($expected, $seen['status'], "offset {$offset}s");
        self::assertSame($expected, $seen['stale'], "offset {$offset}s");
    }

    /** @return list<array{int, string}> */
    public static function boundaryOffsets(): array
    {
        $cooldown = OnchainCircuitBreaker::COOLDOWN_SECONDS;

        return [
            'one second before'  => [$cooldown - 1, OnchainCircuitBreaker::PHASE_OPEN],
            'exactly on'         => [$cooldown,     OnchainCircuitBreaker::PHASE_HALF_OPEN],
            'one second after'   => [$cooldown + 1, OnchainCircuitBreaker::PHASE_HALF_OPEN],
        ];
    }

    /** 7. Well past the cooldown. */
    public function testAfterCooldownIsHalfOpenEverywhere(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 5, time() - 3600);

        $this->assertAllReadersSay(
            OnchainCircuitBreaker::PHASE_HALF_OPEN,
            'the cooldown has elapsed'
        );
    }

    // ── 8-9. counter lifecycle ──────────────────────────────────────────

    /** 8. A stale counter with no live state reads closed everywhere. */
    public function testStaleCounterWithoutStateIsClosedEverywhere(): void
    {
        \BccBreakerStore::seedOrphanCounter(self::CHAIN, 768);

        $this->assertAllReadersSay(
            OnchainCircuitBreaker::PHASE_CLOSED,
            'a counter whose window is gone is not an open breaker'
        );
    }

    /** 9. A success returns every reader to closed. */
    public function testSuccessResetsEveryReaderToClosed(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 9, time() - 10);
        $this->assertAllReadersSay(OnchainCircuitBreaker::PHASE_OPEN, 'precondition: open');

        OnchainCircuitBreaker::recordSuccess(self::CHAIN);

        $this->assertAllReadersSay(OnchainCircuitBreaker::PHASE_CLOSED, 'a success closes the circuit');
    }

    // ── 10-11. the probe slot ───────────────────────────────────────────

    /**
     * 10. ⚠ OBSERVING HALF-OPEN MUST TAKE NO LOCK.
     *
     * If a status page consumed the probe slot, rendering the dashboard
     * would turn a real worker away.
     */
    public function testObservingHalfOpenPerformsNoLockOperation(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 5, time() - 3600);

        OnchainCircuitBreaker::phase(self::CHAIN);
        OnchainCircuitBreaker::getAllStatus([self::CHAIN]);
        OnchainCircuitBreaker::getStaleChains([self::CHAIN], -1);
        // …and repeatedly, in case one of them is lazy.
        OnchainCircuitBreaker::phase(self::CHAIN);
        OnchainCircuitBreaker::getAllStatus([self::CHAIN]);

        self::assertSame([], \BccBreakerStore::$lockAttempts, 'looking must never be probing');
        self::assertSame([], \BccBreakerStore::$locks);
    }

    /** 11. Only isOpen() claims the probe, and only once. */
    public function testOnlyIsOpenClaimsTheHalfOpenProbe(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 5, time() - 3600);

        OnchainCircuitBreaker::phase(self::CHAIN);
        OnchainCircuitBreaker::getAllStatus([self::CHAIN]);
        OnchainCircuitBreaker::getStaleChains([self::CHAIN], -1);
        self::assertSame([], \BccBreakerStore::$lockAttempts);

        self::assertFalse(OnchainCircuitBreaker::isOpen(self::CHAIN), 'the first caller owns the probe');
        self::assertSame(
            ['bcc_cb_probe_' . self::CHAIN],
            \BccBreakerStore::$lockAttempts,
            'exactly one lock attempt, from isOpen() alone'
        );

        self::assertTrue(OnchainCircuitBreaker::isOpen(self::CHAIN), 'a second caller must not also probe');
    }

    /** An OPEN breaker must not touch the lock at all. */
    public function testAnOpenBreakerNeverTouchesTheLock(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 5, 0);

        self::assertTrue(OnchainCircuitBreaker::isOpen(self::CHAIN));
        self::assertSame(
            [],
            \BccBreakerStore::$lockAttempts,
            'no cooldown has started, so there is no probe window to claim'
        );
    }

    // ── output contracts ────────────────────────────────────────────────

    /**
     * ⚠ THE SPELLINGS ARE PART OF EACH METHOD'S CONTRACT.
     *
     * Unifying the decision must not silently re-case anyone's output.
     */
    public function testEachReaderKeepsItsOwnCasing(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 5, time() - 3600);

        self::assertSame('half_open', OnchainCircuitBreaker::phase(self::CHAIN));
        self::assertSame(
            'half-open',
            OnchainCircuitBreaker::getAllStatus([self::CHAIN])[self::CHAIN]['status']
        );
        self::assertSame(
            'HALF-OPEN',
            OnchainCircuitBreaker::getStaleChains([self::CHAIN], -1)[self::CHAIN]['circuit_status']
        );

        \BccBreakerStore::reset();
        \BccBreakerStore::seedOpen(self::CHAIN, 5, time() - 10);

        self::assertSame('open', OnchainCircuitBreaker::phase(self::CHAIN));
        self::assertSame('open', OnchainCircuitBreaker::getAllStatus([self::CHAIN])[self::CHAIN]['status']);
        self::assertSame(
            'OPEN',
            OnchainCircuitBreaker::getStaleChains([self::CHAIN], -1)[self::CHAIN]['circuit_status']
        );

        \BccBreakerStore::reset();

        self::assertSame('closed', OnchainCircuitBreaker::phase(self::CHAIN));
        self::assertSame('closed', OnchainCircuitBreaker::getAllStatus([self::CHAIN])[self::CHAIN]['status']);
        self::assertSame(
            'CLOSED',
            OnchainCircuitBreaker::getStaleChains([self::CHAIN], -1)[self::CHAIN]['circuit_status']
        );
    }

    /** getAllStatus still publishes the raw counters it always did. */
    public function testGetAllStatusStillPublishesItsCounters(): void
    {
        \BccBreakerStore::seedOpen(self::CHAIN, 7, 1788835035);

        $row = OnchainCircuitBreaker::getAllStatus([self::CHAIN])[self::CHAIN];

        self::assertSame(7, $row['failures']);
        self::assertSame(1788835035, $row['opened_at']);
    }
}
