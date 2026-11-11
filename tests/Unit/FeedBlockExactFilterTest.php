<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Trust\Core\Services\Feed\FeedRankingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/../Stubs/blocked-sender-stubs.php';

/**
 * F-54 — the EXACT per-page mutual-block filter on the feed surfaces.
 *
 * ⚠⚠ WHAT WAS WRONG. Every feed surface merged `getBlockedIds()` and
 * `getBlockerIds()` into an `$excluded` list the SQL narrows on. Both are
 * `LIMIT 500` with no ordering and no completeness signal, so a viewer who
 * blocks more than 500 people did not get the 501st excluded and saw their
 * posts. There is not even a way to tell "exactly 500" from "500 and more".
 *
 * ⚠ And they fail OPEN on a DB error: `$wpdb->get_col()` never returns null,
 * a failed query returns `[]`, and `[]` reads as "blocks nobody" — so an
 * unreadable block table showed every blocked author.
 *
 * ⭐ THE CAP IS WHAT MOVES. Asking "which of THESE authors is blocked" bounds
 * the question by page size (a few tens) instead of by how many people the
 * viewer blocks (unbounded; 500+ is ordinary for a heavy blocker). The old
 * list stays as a cheap pre-filter; correctness comes from this check.
 *
 * ⚠ Both directions matter — §K1 mutual invisibility. Someone the viewer
 * blocked disappears, and so does someone who blocked the viewer. Asserted
 * separately below, because a one-directional probe would pass the first.
 *
 * ── WHAT THIS FILE DOES NOT COVER ───────────────────────────────────────
 * The 500 cap itself lives in bcc-core's SQL, so no double can exercise it:
 * a stub returns whatever the test hands it whether or not a cap exists.
 * That end-to-end property — a viewer with 501 blocks never seeing the
 * 501st — is pinned by `FeedBlockOver500IntegrationTest` against a real
 * `$wpdb` with 501 seeded rows. This file covers the decision logic.
 */
#[CoversClass(FeedRankingService::class)]
final class FeedBlockExactFilterTest extends TestCase
{
    private const VIEWER = 7;

    protected function setUp(): void
    {
        parent::setUp();
        PeepSoBlockRepository::$blocking  = [];
        PeepSoBlockRepository::$readFails = false;
    }

    protected function tearDown(): void
    {
        PeepSoBlockRepository::$blocking  = [];
        PeepSoBlockRepository::$readFails = false;
        parent::tearDown();
    }

    // ── both directions of a block ──────────────────────────────────

    /** The viewer blocked the author: the author's post disappears. */
    public function testAnAuthorTheViewerBlockedIsDropped(): void
    {
        PeepSoBlockRepository::$blocking = [[self::VIEWER, 42]];

        $kept = self::filter([self::item(42), self::item(43)]);

        self::assertSame([43], self::authorIdsOf($kept));
    }

    /**
     * ⚠ THE OTHER DIRECTION, which a viewer-only probe would miss. §K1 hides
     * both ways: someone who blocked the viewer must not be able to haunt
     * the viewer's feed either.
     */
    public function testAnAuthorWhoBlockedTheViewerIsAlsoDropped(): void
    {
        PeepSoBlockRepository::$blocking = [[42, self::VIEWER]];

        $kept = self::filter([self::item(42), self::item(43)]);

        self::assertSame([43], self::authorIdsOf($kept));
    }

    /**
     * ⚠ SURGICAL, not all-or-nothing. One blocked author on a page must not
     * cost the reader the rest of the page — that would be a different and
     * much more visible bug than the one being fixed.
     */
    public function testOnlyTheBlockedAuthorsItemsAreDropped(): void
    {
        PeepSoBlockRepository::$blocking = [[self::VIEWER, 11], [33, self::VIEWER]];

        $kept = self::filter([
            self::item(10), self::item(11), self::item(22), self::item(33), self::item(44),
        ]);

        self::assertSame([10, 22, 44], self::authorIdsOf($kept));
    }

    // ── the read failing ────────────────────────────────────────────

    /**
     * ⚠⚠ FAIL CLOSED. `null` means the block read FAILED, not that nobody is
     * blocked. Returning the page would show every blocked author, which is
     * the pre-fix behaviour and the whole defect. Same rule as
     * MessagesService's previews, which withhold everything on an unreadable
     * block table: ugly on a DB fault, safe, self-correcting next request.
     */
    public function testAnUnreadableBlockTableDropsTheWholePage(): void
    {
        PeepSoBlockRepository::$readFails = true;

        self::assertSame([], self::filter([self::item(42), self::item(43)]));
    }

    /**
     * ⚠ ANTI-VACUITY for everything above: a filter that dropped everything
     * would pass all the drop cases. With the read SUCCEEDING and nobody
     * blocked, every item survives untouched.
     */
    public function testWithNoBlocksEveryItemSurvives(): void
    {
        $items = [self::item(10), self::item(11), self::item(12)];

        self::assertSame($items, self::filter($items));
    }

    // ── postures that must not probe ────────────────────────────────

    /**
     * ⚠ The anonymous posture. `buildHotFeed()` hydrates with viewer 0 and
     * caches the payload GLOBALLY, so a per-viewer filter there would both
     * be meaningless and poison a shared cache. Viewer 0 must pass through.
     */
    public function testAnAnonymousViewerIsNotFiltered(): void
    {
        PeepSoBlockRepository::$readFails = true; // would fail closed if consulted

        $items = [self::item(42)];

        self::assertSame($items, self::filter($items, 0));
    }

    /** An empty page needs no probe and stays empty. */
    public function testAnEmptyPageIsReturnedUnchanged(): void
    {
        PeepSoBlockRepository::$readFails = true;

        self::assertSame([], self::filter([]));
    }

    /**
     * ⚠ An item with no readable author id is KEPT. It cannot be attributed
     * to anyone, so it cannot be attributed to a blocked author either, and
     * dropping it would silently eat system events.
     */
    public function testAnItemWithNoAuthorIdIsKept(): void
    {
        PeepSoBlockRepository::$blocking = [[self::VIEWER, 42]];

        $kept = self::filter([['author' => []], self::item(42), ['id' => 'feed_9']]);

        self::assertCount(2, $kept, 'the unattributable items survive, the blocked one does not');
        self::assertSame([], self::authorIdsOf($kept));
    }

    // ── how an author id is read ────────────────────────

    /**
     * ⚠⚠ A STRINGIFIED ID IS STILL AN ID, AND READING IT STRICTLY FAILS
     * OPEN. An `is_int`-only test answers 0 for `"42"`, every author id
     * becomes 0, and the filter then silently matches nobody — a viewer
     * would see blocked authors with no error anywhere.
     *
     * ⚠ Reachable on the hot-feed path, which is why this was raised in
     * review of that change: those items come back through
     * `wp_cache_get()`, so their types survive whatever the object-cache
     * backend does to them. Serialising backends preserve ints; one that
     * round-trips through JSON or a text column need not, and this class
     * cannot know which is installed.
     */
    public function testAStringifiedAuthorIdIsStillDropped(): void
    {
        PeepSoBlockRepository::$blocking = [[self::VIEWER, 42]];

        $kept = self::filter([
            ['id' => 'feed_42', 'author' => ['id' => '42']],
            ['id' => 'feed_43', 'author' => ['id' => '43']],
        ]);

        self::assertCount(1, $kept);
        self::assertSame('43', $kept[0]['author']['id']);
    }

    /**
     * ⚠ But only a plain digit string. `is_numeric` would also accept
     * `"1e3"`, `" 7"` and `"-1"`; an id is an unsigned integer, and a cast
     * must mean what the string says rather than what PHP can coerce it to.
     * Each of these is unattributable, so the item is KEPT — the same
     * answer as an item with no author at all.
     */
    public function testOnlyAPlainDigitStringCountsAsAnId(): void
    {
        PeepSoBlockRepository::$blocking = [[self::VIEWER, 42]];

        foreach (['4e1', ' 42', '42 ', '-42', '0x2a', '', 'forty-two'] as $weird) {
            $kept = self::filter([['id' => 'x', 'author' => ['id' => $weird]]]);

            self::assertCount(
                1,
                $kept,
                'unattributable, so kept rather than guessed at: ' . var_export($weird, true)
            );
        }
    }

    /**
     * ⚠ ANTI-VACUITY for the pair above: the loose reading must not make
     * EVERYTHING match. `"0"` is a digit string but not a valid user id, and
     * must not become a wildcard that drops items.
     */
    public function testAZeroIdIsNotTreatedAsAMatch(): void
    {
        PeepSoBlockRepository::$blocking = [[self::VIEWER, 42]];

        self::assertCount(1, self::filter([['id' => 'x', 'author' => ['id' => '0']]]));
        self::assertCount(1, self::filter([['id' => 'x', 'author' => ['id' => 0]]]));
    }

    // ── the chunking ────────────────────────────────────────────────

    /**
     * ⚠⚠ EVERY AUTHOR IS CHECKED, however many there are. The probe caps its
     * own candidate list, and the defect being fixed here IS a silent cap —
     * so a page larger than one chunk must still be fully checked, not
     * checked up to the first chunk and passed thereafter.
     *
     * The blocked author is placed in the LAST chunk deliberately: a loop
     * that stopped after the first would keep this item and pass every other
     * case in this file.
     */
    public function testAnAuthorBeyondTheFirstChunkIsStillDropped(): void
    {
        $chunk = self::chunkSize();
        $last  = ($chunk * 2) + 5;

        PeepSoBlockRepository::$blocking = [[self::VIEWER, $last]];

        $items = [];
        for ($id = 1; $id <= $last; $id++) {
            $items[] = self::item($id);
        }

        $kept = self::authorIdsOf(self::filter($items));

        self::assertCount($last - 1, $kept);
        self::assertNotContains($last, $kept, 'an author in the third chunk must still be dropped');
        self::assertContains(1, $kept, 'and the first chunk is unaffected');
    }

    /**
     * ⚠ And a read failure in ANY chunk fails the whole page closed, not
     * just that chunk — a partially checked page is exactly the "some
     * blocked authors got through" outcome this fixes.
     */
    public function testAReadFailureAnywhereDropsTheWholePage(): void
    {
        PeepSoBlockRepository::$readFails = true;

        $items = [];
        for ($id = 1; $id <= (self::chunkSize() * 2) + 1; $id++) {
            $items[] = self::item($id);
        }

        self::assertSame([], self::filter($items));
    }

    // ── helpers ─────────────────────────────────────────────────────

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function filter(array $items, int $viewerId = self::VIEWER): array
    {
        $m = (new ReflectionClass(FeedRankingService::class))->getMethod('dropMutuallyBlockedAuthors');
        $m->setAccessible(true);

        /** @var list<array<string, mixed>> $out */
        $out = $m->invoke(null, $items, $viewerId);

        return $out;
    }

    private static function chunkSize(): int
    {
        $c = (new ReflectionClass(FeedRankingService::class))->getConstant('BLOCK_PROBE_CHUNK');
        self::assertIsInt($c);
        self::assertGreaterThan(0, $c);

        return $c;
    }

    /** @return array<string, mixed> */
    private static function item(int $authorId): array
    {
        return [
            'id'     => 'feed_' . $authorId,
            'author' => ['kind' => 'user', 'id' => $authorId, 'handle' => 'u' . $authorId],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<int>
     */
    private static function authorIdsOf(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $author = is_array($item['author'] ?? null) ? $item['author'] : [];
            if (is_int($author['id'] ?? null)) {
                $out[] = $author['id'];
            }
        }

        return $out;
    }
}
