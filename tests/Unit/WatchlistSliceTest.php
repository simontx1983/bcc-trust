<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\WatchingService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * F-255: the watchlist pages its two sources as ONE ordered list, with no
 * cap on either (user decision 2026-11-12: a member can watch any number
 * of things; page_size is only a page size).
 *
 * Before: page follows were fetched at most 100, all on page 1, and the
 * PeepSo side of page 2+ was offset as if page 1 had held only PeepSo rows,
 * so page follows past the 100th never appeared and, at every page
 * boundary, as many PeepSo follows as page 1 had page follows were skipped.
 *
 * Property checked for many shapes: walking every page visits every
 * position [0, total) exactly once, no page exceeds page_size, and the
 * page-follow rows come first.
 */
final class WatchlistSliceTest extends TestCase
{
    /** @return array{page_follow_offset: int, page_follow_take: int, peepso_offset: int, peepso_take: int} */
    private static function slice(int $offset, int $pageSize, int $pageFollowTotal): array
    {
        $m = new ReflectionMethod(WatchingService::class, 'sliceWatchlist');
        /** @var array{page_follow_offset: int, page_follow_take: int, peepso_offset: int, peepso_take: int} $out */
        $out = $m->invoke(null, $offset, $pageSize, $pageFollowTotal);

        return $out;
    }

    /** @return array<string, array{int, int, int}> pageFollows, peepso, pageSize */
    public static function shapes(): array
    {
        return [
            'no page follows'                     => [0, 170, 50],
            'a few page follows (the old skip)'   => [3, 120, 50],
            'exactly one page of page follows'    => [50, 70, 50],
            'past the old 100 cap'                => [105, 65, 50],
            'only page follows, many'             => [250, 0, 50],
            'hydrated page size'                  => [31, 40, 24],
            'page size 1'                         => [4, 5, 1],
            'empty'                               => [0, 0, 50],
        ];
    }

    #[DataProvider('shapes')]
    public function testEveryRowLandsOnExactlyOnePage(int $pageFollows, int $peepso, int $pageSize): void
    {
        $total = $pageFollows + $peepso;
        $pages = $total > 0 ? (int) ceil($total / $pageSize) : 0;

        $pfSeen = [];
        $psSeen = [];
        for ($page = 1; $page <= max(1, $pages); $page++) {
            $s = self::slice(($page - 1) * $pageSize, $pageSize, $pageFollows);

            $pfTake = $s['page_follow_take'];
            // The PeepSo source returns at most what exists past its offset.
            $psTake = max(0, min($s['peepso_take'], $peepso - $s['peepso_offset']));

            self::assertLessThanOrEqual($pageSize, $pfTake + $psTake, "page {$page} over page_size");
            for ($i = 0; $i < $pfTake; $i++) {
                $pfSeen[] = $s['page_follow_offset'] + $i;
            }
            for ($i = 0; $i < $psTake; $i++) {
                $psSeen[] = $s['peepso_offset'] + $i;
            }
            if ($pfTake > 0 && $psTake > 0) {
                self::assertSame($pageFollows, $s['page_follow_offset'] + $pfTake, 'a mixed page ends the page follows');
                self::assertSame(0, $s['peepso_offset'], 'and starts the PeepSo follows at their first row');
            }
        }

        self::assertSame(range(0, $pageFollows - 1), $pageFollows > 0 ? $pfSeen : range(0, -1), 'every page follow exactly once, in order');
        self::assertSame($peepso > 0 ? range(0, $peepso - 1) : [], $psSeen, 'every PeepSo follow exactly once, in order');
    }

    public function testThe105thPageFollowIsOnPage3(): void
    {
        // positions 100..104 → page 3 of 50 holds page follows 100..104.
        $s = self::slice(100, 50, 105);

        self::assertSame(100, $s['page_follow_offset']);
        self::assertSame(5, $s['page_follow_take']);
        self::assertSame(0, $s['peepso_offset']);
        self::assertSame(45, $s['peepso_take']);
    }
}
