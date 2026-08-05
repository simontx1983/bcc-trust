<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\CardsSearchEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the /cards/search All-scope allocation (contract §4.9, v1.70):
 * floors (1 page → 1 community → 1 member), quotas (≤3 communities,
 * ≤3 members), pages absorb unused capacity, display grouping
 * pages → communities → members, and count NEVER exceeds the limit.
 *
 * The endpoint always calls allocateSuggestions with MAX_ITEMS = 12;
 * the smaller limits below exercise the pure helper's documented
 * degradation order (the contract documents the same table).
 */
#[CoversClass(CardsSearchEndpoint::class)]
final class CardsSearchAllocationTest extends TestCase
{
    /**
     * @return list<array{kind: string, n: int}>
     */
    private static function rows(string $kind, int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = ['kind' => $kind, 'n' => $i];
        }
        return $out;
    }

    /**
     * @param list<array{kind: string, n: int}> $result
     * @return list<string>
     */
    private static function labels(array $result): array
    {
        return array_map(
            static fn (array $row): string => $row['kind'] . $row['n'],
            $result,
        );
    }

    /**
     * The documented example table — asserted row-for-row (composition
     * AND order). Default fixtures: 12 pages, 3 communities, 3 members.
     */
    public function testDocumentedExampleTable(): void
    {
        $p12 = self::rows('p', 12);
        $c3  = self::rows('c', 3);
        $m3  = self::rows('m', 3);

        $cases = [
            // [limit, pages, communities, members, expected labels]
            [1,  $p12, $c3, $m3, ['p1']],
            [2,  $p12, $c3, $m3, ['p1', 'c1']],
            [3,  $p12, $c3, $m3, ['p1', 'c1', 'm1']],
            [5,  $p12, $c3, $m3, ['p1', 'c1', 'c2', 'c3', 'm1']],
            [10, $p12, $c3, $m3, ['p1', 'p2', 'p3', 'p4', 'c1', 'c2', 'c3', 'm1', 'm2', 'm3']],
            [12, $p12, $c3, $m3, ['p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'c1', 'c2', 'c3', 'm1', 'm2', 'm3']],
            // Missing verticals — unused capacity flows to the others.
            [12, [], $c3, $m3, ['c1', 'c2', 'c3', 'm1', 'm2', 'm3']],
            [12, $p12, [], $m3, ['p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p8', 'p9', 'm1', 'm2', 'm3']],
            [12, $p12, $c3, [], ['p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'p7', 'p8', 'p9', 'c1', 'c2', 'c3']],
            // Oversupply in every vertical — quotas + cap still hold.
            [12, self::rows('p', 50), self::rows('c', 20), self::rows('m', 20),
                ['p1', 'p2', 'p3', 'p4', 'p5', 'p6', 'c1', 'c2', 'c3', 'm1', 'm2', 'm3']],
            // Degenerate inputs.
            [0, $p12, $c3, $m3, []],
            [12, [], [], [], []],
        ];

        foreach ($cases as [$limit, $pages, $communities, $members, $expected]) {
            $got = CardsSearchEndpoint::allocateSuggestions($pages, $communities, $members, $limit);
            self::assertSame(
                $expected,
                self::labels($got),
                "limit={$limit} P=" . count($pages) . ' C=' . count($communities) . ' M=' . count($members),
            );
        }
    }

    /**
     * The regression the allocation exists to prevent: a merged
     * response must never exceed the requested limit (e.g. limit=10
     * must never return 11–16 rows), for ANY input combination.
     * Property sweep: limits 0..12 × counts {0,1,3,20} per vertical.
     */
    public function testNeverExceedsLimitAndInvariantsHoldAcrossSweep(): void
    {
        $counts = [0, 1, 3, 20];

        foreach (range(0, 12) as $limit) {
            foreach ($counts as $np) {
                foreach ($counts as $nc) {
                    foreach ($counts as $nm) {
                        $got = CardsSearchEndpoint::allocateSuggestions(
                            self::rows('p', $np),
                            self::rows('c', $nc),
                            self::rows('m', $nm),
                            $limit,
                        );

                        $ctx = "limit={$limit} P={$np} C={$nc} M={$nm}";
                        $kinds = array_column($got, 'kind');
                        $tally = array_count_values($kinds);

                        self::assertLessThanOrEqual($limit, count($got), $ctx);
                        self::assertLessThanOrEqual(3, $tally['c'] ?? 0, "community quota: {$ctx}");
                        self::assertLessThanOrEqual(3, $tally['m'] ?? 0, "member quota: {$ctx}");

                        // Grouping: pages → communities → members,
                        // strictly — sorting a copy by group must be a
                        // no-op.
                        $order  = ['p' => 0, 'c' => 1, 'm' => 2];
                        $ranks  = array_map(static fn (string $k): int => $order[$k], $kinds);
                        $sorted = $ranks;
                        sort($sorted);
                        self::assertSame($sorted, $ranks, "grouping order: {$ctx}");

                        // Floors: with limit ≥ 3 every non-empty
                        // vertical is represented.
                        if ($limit >= 3) {
                            if ($np > 0) {
                                self::assertGreaterThanOrEqual(1, $tally['p'] ?? 0, "page floor: {$ctx}");
                            }
                            if ($nc > 0) {
                                self::assertGreaterThanOrEqual(1, $tally['c'] ?? 0, "community floor: {$ctx}");
                            }
                            if ($nm > 0) {
                                self::assertGreaterThanOrEqual(1, $tally['m'] ?? 0, "member floor: {$ctx}");
                            }
                        }
                    }
                }
            }
        }
    }
}
