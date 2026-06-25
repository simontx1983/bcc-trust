<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\RetentionHealthSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * Locks the pure retention overgrown rules (Phase 4 ops-visibility): a table
 * is 'overgrown' strictly when rows > threshold. No WP, no DB.
 */
final class RetentionHealthSnapshotTest extends TestCase
{
    public function testNothingOvergrownAtNormalVolume(): void
    {
        $counts = ['score_events' => 4000, 'fingerprints' => 120, 'patterns' => 0];
        $thresholds = ['score_events' => 250000, 'fingerprints' => 250000, 'patterns' => 250000];

        $out = RetentionHealthSnapshot::evaluate($counts, $thresholds);

        self::assertFalse($out['summary']['has_overgrown']);
        self::assertSame(0, $out['summary']['overgrown']);
        self::assertSame(3, $out['summary']['total']);
        self::assertFalse($out['tables']['score_events']['overgrown']);
    }

    public function testOvergrownWhenStrictlyAboveThreshold(): void
    {
        $counts = ['score_events' => 300000, 'fingerprints' => 250000]; // first over, second exactly at
        $thresholds = ['score_events' => 250000, 'fingerprints' => 250000];

        $out = RetentionHealthSnapshot::evaluate($counts, $thresholds);

        self::assertTrue($out['tables']['score_events']['overgrown']);
        self::assertFalse($out['tables']['fingerprints']['overgrown'], 'exactly at threshold is not overgrown');
        self::assertSame(1, $out['summary']['overgrown']);
        self::assertTrue($out['summary']['has_overgrown']);
    }

    public function testFallsBackToDefaultThresholdWhenMissing(): void
    {
        $out = RetentionHealthSnapshot::evaluate(['x' => RetentionHealthSnapshot::DEFAULT_ALARM_THRESHOLD + 1], []);

        self::assertSame(RetentionHealthSnapshot::DEFAULT_ALARM_THRESHOLD, $out['tables']['x']['threshold']);
        self::assertTrue($out['tables']['x']['overgrown']);
    }
}
