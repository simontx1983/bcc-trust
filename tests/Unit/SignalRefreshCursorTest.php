<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\SignalRefreshService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-11: the daily on-chain refresh walks every owner exactly once, in order,
 * however its ticks are cut short.
 *
 * It used to page owners by OFFSET over an unordered DISTINCT (skips and
 * repeats), save the offset of the CURRENT page on a time-out (so a tick that
 * could not finish one page never advanced), restart from zero every day, and
 * read a failed query as "no more owners". The cycle is now a keyset on
 * user_id with a per-owner cursor; see SignalRefreshService::processBatch().
 */
#[CoversClass(SignalRefreshService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SignalRefreshCursorTest extends TestCase
{
    private const CURSOR = 'bcc_onchain_refresh_cursor';

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        require_once __DIR__ . '/../Stubs/signal-refresh-cursor-stubs.php';
        \RefreshWorld::reset();

        SignalRefreshService::setTestSeams(
            static function (int $ownerId): void {
                \RefreshWorld::$refreshed[] = $ownerId;
                \RefreshWorld::$now += \RefreshWorld::$secondsPerOwner;
            },
            static fn (): int => \RefreshWorld::$now
        );
    }

    protected function tearDown(): void
    {
        SignalRefreshService::setTestSeams(null, null);
        parent::tearDown();
    }

    /** @return list<int> */
    private static function owners(int $n): array
    {
        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $ids[] = $i * 7; // gaps, like real user ids
        }
        return $ids;
    }

    /** Run continuation ticks until the cycle completes, bounded. */
    private function tickUntilDone(int $max = 200): int
    {
        for ($ticks = 1; $ticks <= $max; $ticks++) {
            SignalRefreshService::processBatch();
            if (!array_key_exists(self::CURSOR, \RefreshWorld::$options)) {
                return $ticks;
            }
        }
        self::fail('the cycle did not complete within ' . $max . ' ticks');
    }

    public function testAQuickCycleRefreshesEveryOwnerOnceInOrderAndClearsTheCursor(): void
    {
        \RefreshWorld::$owners = self::owners(45);
        \RefreshWorld::$owners = array_reverse(\RefreshWorld::$owners); // storage order is irrelevant

        SignalRefreshService::processBatch();

        self::assertSame(self::owners(45), \RefreshWorld::$refreshed);
        self::assertArrayNotHasKey(self::CURSOR, \RefreshWorld::$options, 'a complete cycle clears its cursor');
        self::assertSame([], \RefreshWorld::$scheduled);
    }

    public function testATickThatTimesOutMidPageResumesOnTheNextOwnerNotThePagesFirst(): void
    {
        // 45 s budget at 15 s per owner: exactly 3 owners per tick, well short
        // of a 20-owner page. The old code saved the PAGE offset and so redid
        // the same 3 owners for ever.
        \RefreshWorld::$owners = self::owners(10);
        \RefreshWorld::$secondsPerOwner = 15;

        SignalRefreshService::processBatch();

        self::assertSame([7, 14, 21], \RefreshWorld::$refreshed);
        self::assertSame(21, \RefreshWorld::$options[self::CURSOR] ?? null, 'the cursor is the last owner refreshed');
        self::assertSame(['bcc_onchain_refresh_batch'], \RefreshWorld::$scheduled);

        SignalRefreshService::processBatch();
        self::assertSame([7, 14, 21, 28, 35, 42], \RefreshWorld::$refreshed, 'tick 2 starts on the 4th owner');
    }

    public function testEveryOwnerExactlyOnceAcrossManyTimedOutTicks(): void
    {
        \RefreshWorld::$owners = self::owners(65);
        \RefreshWorld::$secondsPerOwner = 15;

        $ticks = $this->tickUntilDone();

        self::assertSame(self::owners(65), \RefreshWorld::$refreshed, 'every owner once, ascending, none skipped or repeated');
        self::assertSame(22, $ticks, '65 owners at 3 a tick');
    }

    public function testTheDailyRunResumesAnUnfinishedCycleInsteadOfRestarting(): void
    {
        \RefreshWorld::$owners = self::owners(10);
        \RefreshWorld::$options[self::CURSOR] = 35; // a cycle stopped after owner 35

        SignalRefreshService::dailyRefresh();

        self::assertSame([42, 49, 56, 63, 70], \RefreshWorld::$refreshed, 'carried on from the cursor — the first five are not redone');
        self::assertContains('info:[SignalRefresh] resuming unfinished refresh cycle', \RefreshWorld::$logs);
        self::assertArrayNotHasKey(self::CURSOR, \RefreshWorld::$options);
    }

    public function testTheDailyRunStartsAFreshCycleWhenNoneIsInProgress(): void
    {
        \RefreshWorld::$owners = self::owners(3);

        SignalRefreshService::dailyRefresh();

        self::assertSame([7, 14, 21], \RefreshWorld::$refreshed);
    }

    public function testTheDailyRunDropsTheLegacyOffsetCursor(): void
    {
        \RefreshWorld::$options['bcc_onchain_refresh_offset'] = 140;

        SignalRefreshService::dailyRefresh();

        self::assertArrayNotHasKey('bcc_onchain_refresh_offset', \RefreshWorld::$options);
    }

    public function testAFailedReadKeepsTheCycleAndRetriesNextTick(): void
    {
        // A failed read used to look like "no more owners": the cursor was
        // deleted and the rest of the list skipped until the next day.
        \RefreshWorld::$owners = self::owners(10);
        \RefreshWorld::$options[self::CURSOR] = 21;
        \RefreshWorld::$readFailures = 1;

        SignalRefreshService::processBatch();

        self::assertSame([], \RefreshWorld::$refreshed);
        self::assertSame(21, \RefreshWorld::$options[self::CURSOR] ?? null, 'the cursor is kept');
        self::assertSame(['bcc_onchain_refresh_batch'], \RefreshWorld::$scheduled, 'and the next tick is scheduled');

        SignalRefreshService::processBatch();
        self::assertSame([28, 35, 42, 49, 56, 63, 70], \RefreshWorld::$refreshed, 'the retry picks up exactly where it stopped');
    }

    public function testAFailedReadOnTheFirstPageOfACycleStillDoesNotEndIt(): void
    {
        \RefreshWorld::$owners = self::owners(5);
        \RefreshWorld::$readFailures = 1;

        SignalRefreshService::processBatch();

        self::assertSame(0, \RefreshWorld::$options[self::CURSOR] ?? null, 'a cursor of 0 is saved, so the daily run resumes rather than treating it as done');
        self::assertSame(['bcc_onchain_refresh_batch'], \RefreshWorld::$scheduled);
    }
}
