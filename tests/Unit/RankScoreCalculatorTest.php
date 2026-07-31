<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Rank\Services\RankEvidenceIngestor;
use BCC\Trust\Rank\Services\RankScoreCalculator;
use BCC\Trust\Rank\Support\RankScoringConfig;
use PHPUnit\Framework\TestCase;

/**
 * §30 invariant coverage for the pure Phase 4 pipeline pieces, run
 * against the REAL shipped config (Phase 0 doctrine): the share caps
 * (§8.2/§9.3/§10.3), category ceilings, the derived time credit
 * (invariant "time never exceeds 20%"), the §12.3 decay boundaries
 * (invariants 12/13), the §4.4 event-cap allocation (invariant 3 —
 * one viral event cannot carry a member), and the R3 representative
 * collapse (invariants 4/5 — one recognizer / a small ring cannot
 * carry another member).
 */
final class RankScoreCalculatorTest extends TestCase
{
    private function config(): RankScoringConfig
    {
        return RankScoringConfig::fromDefaultFile();
    }

    private function calculator(): RankScoreCalculator
    {
        return new RankScoreCalculator($this->config());
    }

    /** @return object Ledger-row shape the repository returns. */
    private function event(string $category, string $sourceType, float $capped, int $relationship = 0): object
    {
        return (object) [
            'category'             => $category,
            'source_type'          => $sourceType,
            'relationship_user_id' => (string) $relationship,
            'capped_value'         => (string) $capped,
        ];
    }

    public function testContributionTypeShareCap(): void
    {
        // 20 posts × 0.75 = 15 raw, but one contribution TYPE may fund
        // at most 35% of the 25-point category (§8.2) = 8.75.
        $events = [];
        for ($i = 0; $i < 20; $i++) {
            $events[] = $this->event('contribution', 'post', 0.75);
        }
        // A second type adds beyond the first type's cap.
        $events[] = $this->event('contribution', 'comment', 1.0);

        $result = $this->calculator()->calculate($events, 0, null);

        self::assertSame(9.75, $result['categories']['contribution']); // 8.75 + 1.0
    }

    public function testHelpingRecipientCap(): void
    {
        // One recipient may fund at most 10% of the 25-point helping
        // category (§9.3) = 2.5 — filling helping requires breadth.
        $events = [];
        for ($i = 0; $i < 10; $i++) {
            $events[] = $this->event('helping', 'helpful_mark', 0.5, 42);
        }
        $events[] = $this->event('helping', 'helpful_mark', 0.5, 43);

        $result = $this->calculator()->calculate($events, 0, null);

        self::assertSame(3.0, $result['categories']['helping']); // 2.5 + 0.5
    }

    public function testRecognizerCapAndClusterCollapse(): void
    {
        // §10.3: one recognizer ≤ 20% of the 15-point category = 3.0.
        // R3: clustered recognizers collapse to their representative
        // FIRST — a ring shares ONE identity bucket (invariants 4/5).
        $ring = [101, 102, 103];
        $events = [];
        foreach ($ring as $recognizer) {
            for ($i = 0; $i < 4; $i++) {
                $events[] = $this->event('recognition', 'recognition', 0.75, $recognizer);
            }
        }
        // An independent recognizer outside the ring still adds.
        $events[] = $this->event('recognition', 'recognition', 0.75, 200);

        $clusterMap = [101 => 101, 102 => 101, 103 => 101];

        $result = $this->calculator()->calculate($events, 0, null, $clusterMap);

        // Ring: 12 × 0.75 = 9.0 raw → one bucket, capped 3.0. +0.75 independent.
        self::assertSame(3.75, $result['categories']['recognition']);

        // Without the collapse the same ring would fund three separate
        // 3.0-capped buckets (9.0) + 0.75 — proving the collapse is
        // what stops the ring (invariant 5).
        $uncollapsed = $this->calculator()->calculate($events, 0, null, []);
        self::assertSame(9.75, $uncollapsed['categories']['recognition']);
    }

    public function testCategoryCeilingsAndTotalClamp(): void
    {
        $events = [];
        foreach (['post', 'comment', 'review', 'report_upheld', 'stewardship', 'onboarding'] as $type) {
            for ($i = 0; $i < 30; $i++) {
                $events[] = $this->event('contribution', $type, 2.0);
                $events[] = $this->event('helping', $type, 2.0, $i + 1);
                $events[] = $this->event('recognition', $type, 2.0, $i + 100);
                $events[] = $this->event('outcomes', $type, 2.0);
            }
        }

        $config = $this->config();
        $result = $this->calculator()->calculate($events, 50, gmdate('Y-m-d'));

        foreach ($config->categoryMax as $category => $max) {
            self::assertLessThanOrEqual($max, $result['categories'][$category], $category);
        }
        self::assertLessThanOrEqual(100.0, $result['total']);
    }

    public function testTimeIsDerivedAndCapped(): void
    {
        // §12.1: months × rate, hard-capped — 30 login months cannot
        // exceed the 20-point time ceiling (time never dominates).
        $result = $this->calculator()->calculate([], 30, gmdate('Y-m-d'));

        self::assertSame(20.0, $result['categories']['time']);
        self::assertSame(20.0, $result['total']);
    }

    public function testDecayBoundaries(): void
    {
        // §12.3 / invariants 12+13: zero through day 365; first point
        // only after a full 30-day step beyond the grace.
        $calc  = $this->calculator();
        $today = '2026-07-31';

        self::assertSame(0.0, $calc->decayFor(null, $today), 'never logged in');
        self::assertSame(0.0, $calc->decayFor('2026-07-01', $today), 'recent login');
        self::assertSame(0.0, $calc->decayFor('2025-07-31', $today), 'day 365 exactly');
        self::assertSame(0.0, $calc->decayFor('2025-07-30', $today), 'day 366 — inside first step');
        self::assertSame(1.0, $calc->decayFor('2025-07-01', $today), 'day 395 — one full step');
        self::assertSame(2.0, $calc->decayFor('2025-06-01', $today), 'day 425 — two steps');
    }

    public function testEventCapAllocation(): void
    {
        // §4.4 / invariant 3: one event's combined credit ≤ 2.0 no
        // matter how many categories its evidence layers across.
        $allocated = RankEvidenceIngestor::allocateWithinCap(
            ['contribution' => 1.5, 'outcomes' => 2.0],
            2.0
        );
        self::assertSame(['contribution' => 1.5, 'outcomes' => 0.5], $allocated);
        self::assertSame(2.0, array_sum($allocated));

        // Under-cap events pass through untouched.
        self::assertSame(
            ['contribution' => 0.75],
            RankEvidenceIngestor::allocateWithinCap(['contribution' => 0.75], 2.0)
        );

        // Degenerate cap → nothing granted, never negative.
        $zero = RankEvidenceIngestor::allocateWithinCap(['contribution' => 1.0], 0.0);
        self::assertSame(0.0, array_sum($zero));
    }

    public function testEmptyLedgerScoresZero(): void
    {
        $result = $this->calculator()->calculate([], 0, null);

        self::assertSame(0.0, $result['total']);
        self::assertSame(0.0, $result['decay']);
    }
}
