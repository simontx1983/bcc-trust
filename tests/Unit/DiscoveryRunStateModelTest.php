<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\ValueObjects\DiscoveryJobKind;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunStatus;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The closed vocabularies, and the shape of the lifecycle.
 *
 * These are pure, so the tests are cheap — but they are not trivial: a
 * transition table nobody checks is a comment, and the column widths are
 * load-bearing (a code longer than its VARCHAR is silently truncated by
 * MySQL, turning a bounded vocabulary into a corrupted one).
 */
#[CoversClass(DiscoveryRunStatus::class)]
#[CoversClass(DiscoveryJobKind::class)]
#[CoversClass(DiscoveryScanMode::class)]
#[CoversClass(DiscoveryRunError::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DiscoveryRunStateModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/discovery-run-stubs.php';
    }

    // ── Status ──────────────────────────────────────────────────────────

    public function testTheStatusVocabularyIsExactlyFive(): void
    {
        self::assertSame(
            ['queued', 'running', 'succeeded', 'failed', 'cancelled'],
            DiscoveryRunStatus::all()
        );
    }

    /**
     * `stopped` and `expired` were proposed and rejected: a budget stop is
     * a SUCCESS carrying `partial`, and a vanished executor is a FAILURE
     * carrying an error code. Both would have duplicated another column.
     */
    public function testTheRejectedStatesAreAbsent(): void
    {
        self::assertNotContains('stopped', DiscoveryRunStatus::all());
        self::assertNotContains('expired', DiscoveryRunStatus::all());
    }

    public function testTerminalStatesAreTheThreeRestingStates(): void
    {
        self::assertSame(['succeeded', 'failed', 'cancelled'], DiscoveryRunStatus::terminal());
    }

    /**
     * Membership in the terminal list, not "everything except queued and
     * running" — so a token from a newer build reads as NON-terminal. An
     * unknown state treated as terminal would clear `active_marker` and let
     * a second run start beside a live one.
     */
    public function testAnUnknownStatusIsNotTreatedAsTerminal(): void
    {
        self::assertFalse(DiscoveryRunStatus::isTerminal('some_future_state'));
        self::assertFalse(DiscoveryRunStatus::isValid('some_future_state'));
    }

    /** ⚠ There is deliberately NO queued -> failed edge. Age is not a fault. */
    public function testAQueuedRunCanNeverBeFailedForBeingOld(): void
    {
        self::assertFalse(
            DiscoveryRunStatus::canTransition(DiscoveryRunStatus::QUEUED, DiscoveryRunStatus::FAILED),
            'a waiting run is not a broken one; only the reaper failing an EXHAUSTED running run is terminal'
        );
    }

    public function testTheRetryableEdgeExists(): void
    {
        self::assertTrue(
            DiscoveryRunStatus::canTransition(DiscoveryRunStatus::RUNNING, DiscoveryRunStatus::QUEUED),
            'an expired lease must be able to return to the queue'
        );
    }

    public function testTerminalStatesAreAbsorbing(): void
    {
        foreach (DiscoveryRunStatus::terminal() as $terminal) {
            foreach (DiscoveryRunStatus::all() as $to) {
                self::assertFalse(
                    DiscoveryRunStatus::canTransition($terminal, $to),
                    "{$terminal} must not transition to {$to}"
                );
            }
        }
    }

    public function testTheOnlyClaimEdgeIsFromQueued(): void
    {
        foreach (DiscoveryRunStatus::all() as $from) {
            if ($from === DiscoveryRunStatus::QUEUED) {
                continue;
            }
            self::assertFalse(
                DiscoveryRunStatus::canTransition($from, DiscoveryRunStatus::RUNNING),
                "{$from} must not become running"
            );
        }
    }

    // ── Job kind ────────────────────────────────────────────────────────

    public function testOnlyCosmwasmDiscoveryIsRequestableToday(): void
    {
        self::assertSame([DiscoveryJobKind::COSMWASM_DISCOVERY], DiscoveryJobKind::requestable());
        self::assertTrue(DiscoveryJobKind::isValid(DiscoveryJobKind::EVM_INDEXER));
        self::assertFalse(
            DiscoveryJobKind::isRequestable(DiscoveryJobKind::EVM_INDEXER),
            'the vocabulary is closed for storage, but only a kind with an executor may be requested'
        );
    }

    public function testJobKindFitsItsColumn(): void
    {
        self::assertLessThanOrEqual(32, DiscoveryJobKind::maxLength());
    }

    // ── Scan mode ───────────────────────────────────────────────────────

    public function testAMissingCheckpointIsHistorical(): void
    {
        self::assertSame(DiscoveryScanMode::HISTORICAL, DiscoveryScanMode::forCheckpoint(null));
    }

    public function testANullCompletionStampIsHistorical(): void
    {
        $row = (object) ['cw_backfill_completed_at' => null];
        self::assertSame(DiscoveryScanMode::HISTORICAL, DiscoveryScanMode::forCheckpoint($row));
    }

    /**
     * MySQL hands back the zero date as a string on some configurations. It
     * means "never", not "completed at year zero" — and reading it as
     * completion would skip the entire backfill.
     */
    public function testTheZeroDateIsHistoricalNotCompleted(): void
    {
        $row = (object) ['cw_backfill_completed_at' => '0000-00-00 00:00:00'];
        self::assertSame(DiscoveryScanMode::HISTORICAL, DiscoveryScanMode::forCheckpoint($row));
    }

    public function testAnEmptyStringIsHistorical(): void
    {
        $row = (object) ['cw_backfill_completed_at' => '   '];
        self::assertSame(DiscoveryScanMode::HISTORICAL, DiscoveryScanMode::forCheckpoint($row));
    }

    public function testARealCompletionStampIsIncremental(): void
    {
        $row = (object) ['cw_backfill_completed_at' => '2026-08-19 17:29:32'];
        self::assertSame(DiscoveryScanMode::INCREMENTAL, DiscoveryScanMode::forCheckpoint($row));
    }

    // ── Error vocabulary ────────────────────────────────────────────────

    public function testErrorCodesFitTheirColumn(): void
    {
        self::assertLessThanOrEqual(
            40,
            DiscoveryRunError::maxLength(),
            'a code longer than VARCHAR(40) is silently truncated, corrupting a bounded vocabulary'
        );
    }

    public function testTheUnconfirmedTerminalWriteCodeExists(): void
    {
        self::assertTrue(DiscoveryRunError::isValid(DiscoveryRunError::TERMINAL_WRITE_UNCONFIRMED));
    }

    public function testEveryErrorCodeIsLowerSnakeCase(): void
    {
        foreach (DiscoveryRunError::all() as $code) {
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $code);
        }
    }

    /**
     * No code may read as a monetary concept. The vocabulary is where a
     * price would sneak back in as a "reason".
     */
    public function testNoErrorCodeIsAboutMoney(): void
    {
        foreach (DiscoveryRunError::all() as $code) {
            foreach (['price', 'floor', 'volume', 'sale', 'listed', 'royalty'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $code);
            }
        }
    }
}
