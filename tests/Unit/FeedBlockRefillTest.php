<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Trust\Core\Services\Feed\FeedRankingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/../Stubs/blocked-sender-stubs.php';

/**
 * F-297: a page the exact block filter EMPTIES is read past.
 *
 * The feeds fetch a page, then drop authors mutually blocked with the
 * viewer (F-54). A viewer who blocked a prolific author, or more than the
 * SQL pre-filter's 500, could get `items: []` with `has_more: true`. The
 * client shows the empty state for that and never asks for more, although
 * older posts by other authors exist.
 *
 * fetchPastBlockedPages() follows the page's own `next_cursor`, up to
 * BLOCK_REFILL_PAGES more pages, only while the filter leaves nothing.
 * Driven here with a scripted fetch so every read is visible.
 */
#[CoversClass(FeedRankingService::class)]
final class FeedBlockRefillTest extends TestCase
{
    private const VIEWER  = 7;
    private const BLOCKED = 42;
    private const OTHER   = 43;

    /** @var list<?string> cursors the fetch was called with */
    private array $reads = [];

    protected function setUp(): void
    {
        parent::setUp();
        PeepSoBlockRepository::$blocking  = [[self::VIEWER, self::BLOCKED]];
        PeepSoBlockRepository::$readFails = false;
        $this->reads = [];
    }

    protected function tearDown(): void
    {
        PeepSoBlockRepository::$blocking  = [];
        PeepSoBlockRepository::$readFails = false;
        parent::tearDown();
    }

    public function testAnEmptiedPageIsReadPastToTheNextOne(): void
    {
        $out = $this->run_([
            null => self::page([self::BLOCKED, self::BLOCKED], 'c1', true),
            'c1' => self::page([self::OTHER], 'c2', true),
        ]);

        self::assertSame([null, 'c1'], $this->reads);
        self::assertSame(['feed_' . self::OTHER . '_0'], array_column($out['items'], 'id'));
        self::assertSame(['next_cursor' => 'c2', 'has_more' => true], $out['pagination'], 'the LAST page read carries the cursor on');
    }

    public function testAPageWithSomethingLeftIsNotRefilled(): void
    {
        $out = $this->run_([
            null => self::page([self::BLOCKED, self::OTHER], 'c1', true),
        ]);

        self::assertSame([null], $this->reads);
        self::assertCount(1, $out['items']);
    }

    public function testTheEndOfTheFeedStopsTheRefill(): void
    {
        $out = $this->run_([
            null => self::page([self::BLOCKED], null, false),
        ]);

        self::assertSame([null], $this->reads);
        self::assertSame([], $out['items']);
        self::assertFalse($out['pagination']['has_more']);
    }

    public function testTheRefillIsBounded(): void
    {
        $pages = [null => self::page([self::BLOCKED], 'c1', true)];
        for ($i = 1; $i <= 10; $i++) {
            $pages['c' . $i] = self::page([self::BLOCKED], 'c' . ($i + 1), true);
        }

        $out = $this->run_($pages);

        self::assertSame([null, 'c1', 'c2', 'c3'], $this->reads, 'the first read plus BLOCK_REFILL_PAGES (3) more');
        self::assertSame([], $out['items']);
        self::assertSame(['next_cursor' => 'c4', 'has_more' => true], $out['pagination'], 'the client can still page on');
    }

    public function testAnUnreadableBlockTableFailsClosedWithoutReadingOn(): void
    {
        PeepSoBlockRepository::$readFails = true;

        $out = $this->run_([
            null => self::page([self::OTHER], 'c1', true),
            'c1' => self::page([self::OTHER], 'c2', true),
        ]);

        self::assertSame([null], $this->reads);
        self::assertSame([], $out['items']);
    }

    public function testAnAnonymousViewerIsASingleUnfilteredRead(): void
    {
        $out = $this->run_([
            null => self::page([self::BLOCKED], 'c1', true),
        ], 0);

        self::assertSame([null], $this->reads);
        self::assertCount(1, $out['items']);
    }

    public function testACursorThatDoesNotAdvanceStopsTheLoop(): void
    {
        $out = $this->run_([
            null => self::page([self::BLOCKED], 'c1', true),
            'c1' => self::page([self::BLOCKED], 'c1', true),
        ]);

        self::assertSame([null, 'c1'], $this->reads);
        self::assertSame([], $out['items']);
    }

    // ── helpers ─────────────────────────────────────────────────────

    /**
     * @param array<string, array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}> $pages keyed by cursor ('' = first page)
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    private function run_(array $pages, int $viewer = self::VIEWER): array
    {
        $fetch = function (?string $cursor) use ($pages): array {
            $this->reads[] = $cursor;
            $page = $pages[$cursor ?? ''] ?? null;
            self::assertNotNull($page, 'unexpected read at cursor ' . var_export($cursor, true));

            return $page;
        };

        $m = new ReflectionMethod(FeedRankingService::class, 'fetchPastBlockedPages');
        /** @var array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}} $out */
        $out = $m->invoke(null, $fetch, null, $viewer);

        return $out;
    }

    /**
     * @param list<int> $authors
     * @return array{items: list<array<string, mixed>>, pagination: array{next_cursor: ?string, has_more: bool}}
     */
    private static function page(array $authors, ?string $next, bool $hasMore): array
    {
        $items = [];
        foreach ($authors as $i => $author) {
            $items[] = ['id' => 'feed_' . $author . '_' . $i, 'author' => ['kind' => 'user', 'id' => $author]];
        }

        return ['items' => $items, 'pagination' => ['next_cursor' => $next, 'has_more' => $hasMore]];
    }
}
