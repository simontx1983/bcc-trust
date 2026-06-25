<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\CronHealthSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Locks the pure cron-freshness rules (Phase 4 ops-visibility): a job with no
 * recorded success is 'pending' (install grace, not an alarm); a fresh success
 * is 'ok'; a success older than max(2× interval, 600s) is 'stale'. No WP, no DB.
 */
final class CronHealthSnapshotTest extends TestCase
{
    private const NOW = 1_700_000_000;

    /** @var array<string, array{interval: string, description: string}> */
    private const HOOKS = [
        'bcc_trust_daily_cleanup'          => ['interval' => 'daily', 'description' => 'cleanup'],
        'bcc_trust_process_recalculations' => ['interval' => 'bcc_five_minutes', 'description' => 'recalc'],
    ];

    /** @var array<string, int> */
    private const INTERVALS = ['daily' => 86400, 'bcc_five_minutes' => 300];

    public function testNeverRunIsPendingNotStale(): void
    {
        $out = CronHealthSnapshot::evaluate(self::HOOKS, [], self::INTERVALS, self::NOW);

        self::assertSame('pending', $out['jobs']['bcc_trust_daily_cleanup']['state']);
        self::assertNull($out['jobs']['bcc_trust_daily_cleanup']['age_seconds']);
        self::assertSame(2, $out['summary']['pending']);
        self::assertSame(0, $out['summary']['stale']);
        self::assertFalse($out['summary']['has_stale']);
    }

    public function testFreshSuccessIsOk(): void
    {
        $lastSuccess = [
            'bcc_trust_daily_cleanup'          => self::NOW - 3600,   // 1h ago, daily job → ok
            'bcc_trust_process_recalculations' => self::NOW - 60,     // 1m ago, 5-min job → ok
        ];
        $out = CronHealthSnapshot::evaluate(self::HOOKS, $lastSuccess, self::INTERVALS, self::NOW);

        self::assertSame('ok', $out['jobs']['bcc_trust_daily_cleanup']['state']);
        self::assertSame('ok', $out['jobs']['bcc_trust_process_recalculations']['state']);
        self::assertSame(2, $out['summary']['ok']);
        self::assertFalse($out['summary']['has_stale']);
    }

    public function testStaleWhenOlderThanTwiceInterval(): void
    {
        $lastSuccess = [
            'bcc_trust_daily_cleanup'          => self::NOW - (3 * 86400), // 3 days, threshold 2 days → stale
            'bcc_trust_process_recalculations' => self::NOW - 120,         // 2m ago → ok
        ];
        $out = CronHealthSnapshot::evaluate(self::HOOKS, $lastSuccess, self::INTERVALS, self::NOW);

        self::assertSame('stale', $out['jobs']['bcc_trust_daily_cleanup']['state']);
        self::assertSame(172800, $out['jobs']['bcc_trust_daily_cleanup']['threshold_seconds']); // 2× daily
        self::assertSame('ok', $out['jobs']['bcc_trust_process_recalculations']['state']);
        self::assertSame(1, $out['summary']['stale']);
        self::assertTrue($out['summary']['has_stale']);
    }

    public function testSubTenMinuteCronUsesTheFloorNotTwiceInterval(): void
    {
        // 5-min interval → 2× = 600s, which equals the floor; a success 9 min
        // old is within the floor (not stale) so shared-host jitter doesn't flap.
        $lastSuccess = ['bcc_trust_process_recalculations' => self::NOW - 540]; // 9 min
        $out = CronHealthSnapshot::evaluate(
            ['bcc_trust_process_recalculations' => self::HOOKS['bcc_trust_process_recalculations']],
            $lastSuccess,
            self::INTERVALS,
            self::NOW
        );

        self::assertSame(600, $out['jobs']['bcc_trust_process_recalculations']['threshold_seconds']);
        self::assertSame('ok', $out['jobs']['bcc_trust_process_recalculations']['state']);
    }

    public function testUnknownIntervalYieldsNoThresholdAndStaysOk(): void
    {
        // If the interval slug isn't in the schedule registry, we can't compute
        // a threshold — a recorded success should still read 'ok', never crash.
        $out = CronHealthSnapshot::evaluate(
            ['bcc_mystery' => ['interval' => 'not_registered', 'description' => 'x']],
            ['bcc_mystery' => self::NOW - 999999],
            [],
            self::NOW
        );

        self::assertNull($out['jobs']['bcc_mystery']['threshold_seconds']);
        self::assertSame('ok', $out['jobs']['bcc_mystery']['state']);
    }
}
