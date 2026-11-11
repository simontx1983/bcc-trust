<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Trust\Core\Services\Feed\FeedRankingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-54 — a viewer who blocks more than 500 people still never sees a blocked
 * author, proved against a real `$wpdb` with 501 real rows.
 *
 * ⚠⚠ WHY THIS CANNOT BE A UNIT TEST. The defect is `LIMIT 500` inside
 * bcc-core's SQL. A repository double returns whatever the test hands it
 * whether or not a cap exists, so a unit test of this property would pass
 * identically before and after the fix — it would assert the fixture, not
 * the behaviour. The cap has to be in play, which means a real table.
 *
 * ⭐ AND THE TEST IS DETERMINISTIC WITHOUT ASSUMING WHICH ROW IS LOST.
 * `getBlockedIds()` has `LIMIT 500` and NO `ORDER BY`, so which of the 501
 * rows falls off is the engine's business and is not something to pin. This
 * file ASKS which one was dropped, then proves the new filter catches that
 * exact author. So it demonstrates the hole and the fix in one pass, and it
 * would still do so if the engine changed its mind about row order.
 *
 * `FeedBlockExactFilterTest` covers the decision logic (both block
 * directions, fail-closed on an unreadable table, chunking, the anonymous
 * posture). This file covers only what needs a database.
 */
#[CoversClass(FeedRankingService::class)]
final class FeedBlockOver500IntegrationTest extends TestCase
{
    /** Sentinel range: the shared cleanup convention in this suite. */
    private const VIEWER    = 990501;
    private const UNBLOCKED = 990502;

    /** 501 blocked authors, one past bcc-core's LIMIT 500. */
    private const AUTHOR_BASE  = 991000;
    private const AUTHOR_COUNT = 501;

    private static bool $createdBlocks = false;

    public static function setUpBeforeClass(): void
    {
        global $wpdb;

        $blocks = $wpdb->prefix . 'peepso_blocks';
        if (!self::tableExists($blocks)) {
            self::$createdBlocks = true;
            self::assertNotFalse($wpdb->query(
                "CREATE TABLE `{$blocks}` (
                    blk_user_id BIGINT UNSIGNED NOT NULL,
                    blk_blocked_id BIGINT UNSIGNED NOT NULL,
                    PRIMARY KEY (blk_user_id, blk_blocked_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
            ), 'create peepso_blocks: ' . $wpdb->last_error);
        }
    }

    public static function tearDownAfterClass(): void
    {
        global $wpdb;

        self::purge();
        if (self::$createdBlocks) {
            $wpdb->query("DROP TABLE IF EXISTS `{$wpdb->prefix}peepso_blocks`");
            self::$createdBlocks = false;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::purge();
    }

    protected function tearDown(): void
    {
        self::purge();
        parent::tearDown();
    }

    // ── the hole, and the fix, in one pass ──────────────────────────

    /**
     * ⚠⚠ THE HEADLINE. 501 blocks: the capped list demonstrably loses one,
     * and the new per-page filter catches exactly the one it lost.
     *
     * Before this fix the lost author's posts appeared in the viewer's feed,
     * because the capped list was the only thing consulted.
     */
    public function testTheAuthorTheCapDropsIsStillFilteredOut(): void
    {
        $this->seedBlocks();

        // 1. The cap really does truncate, against a real table.
        $capped = PeepSoBlockRepository::getBlockedIds(self::VIEWER);
        self::assertCount(
            500,
            $capped,
            'the pre-filter is capped at 500 of the 501 seeded blocks'
        );

        // 2. Exactly one seeded author is missing from it. Which one is the
        //    engine's choice, so it is asked for rather than assumed.
        $missing = array_values(array_diff($this->seededAuthorIds(), $capped));
        self::assertCount(1, $missing, 'exactly one blocked author fell off the cap');
        $lost = (int) $missing[0];

        // 3. And the exact filter drops that author's post anyway, which is
        //    the property the whole change exists for.
        $kept = self::filter([self::item($lost), self::item(self::UNBLOCKED)]);

        self::assertSame(
            [self::UNBLOCKED],
            self::authorIdsOf($kept),
            'the author the cap lost must still be filtered out of the page'
        );
    }

    /**
     * ⚠ ANTI-VACUITY against a real table: the filter must not simply drop
     * everything once the viewer has many blocks. An author who is NOT
     * blocked survives a page built by the same viewer in the same state.
     */
    public function testAnUnblockedAuthorSurvivesForAHeavyBlocker(): void
    {
        $this->seedBlocks();

        $kept = self::filter([self::item(self::UNBLOCKED)]);

        self::assertSame([self::UNBLOCKED], self::authorIdsOf($kept));
    }

    /**
     * ⚠ §K1 is mutual, and the capped reader for the other direction
     * (`getBlockerIds()`) carries the same `LIMIT 500`. An author who
     * blocked the VIEWER must also disappear, at this scale too.
     */
    public function testAnAuthorWhoBlockedTheViewerIsFilteredAtScaleToo(): void
    {
        $this->seedBlocks();

        // One more row, in the opposite direction.
        $inverse = self::AUTHOR_BASE + self::AUTHOR_COUNT + 1;
        $this->insertBlock($inverse, self::VIEWER);

        $kept = self::filter([self::item($inverse), self::item(self::UNBLOCKED)]);

        self::assertSame([self::UNBLOCKED], self::authorIdsOf($kept));
    }

    /**
     * ⚠ And the probe the fix relies on is NOT itself capped by the viewer's
     * block count — it is bounded by the page, which is the whole point of
     * moving the question. Asked directly, with 501 blocks in the table.
     */
    public function testTheExactProbeAnswersForAPageSizedCandidateList(): void
    {
        $this->seedBlocks();

        $authors = array_slice($this->seededAuthorIds(), 0, 20);
        $set     = PeepSoBlockRepository::getMutuallyBlockedSetOrUnknown(self::VIEWER, $authors);

        self::assertIsArray($set, 'a readable table must not answer UNKNOWN');
        self::assertCount(20, $set, 'every candidate on the page is answered for');
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function seedBlocks(): void
    {
        global $wpdb;

        $rows = [];
        foreach ($this->seededAuthorIds() as $authorId) {
            $rows[] = '(' . self::VIEWER . ',' . $authorId . ')';
        }

        // One statement: 501 individual inserts is slow enough to matter.
        self::assertNotFalse($wpdb->query(
            "INSERT INTO `{$wpdb->prefix}peepso_blocks` (blk_user_id, blk_blocked_id) VALUES "
            . implode(',', $rows)
        ), 'seed 501 blocks: ' . $wpdb->last_error);

        self::assertSame(
            self::AUTHOR_COUNT,
            (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM `{$wpdb->prefix}peepso_blocks` WHERE blk_user_id = " . self::VIEWER
            ),
            'the fixture itself must really hold 501 rows, or nothing below means anything'
        );
    }

    /** @return list<int> */
    private function seededAuthorIds(): array
    {
        $ids = [];
        for ($i = 0; $i < self::AUTHOR_COUNT; $i++) {
            $ids[] = self::AUTHOR_BASE + $i;
        }

        return $ids;
    }

    private function insertBlock(int $blockerId, int $blockedId): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'peepso_blocks', [
            'blk_user_id'    => $blockerId,
            'blk_blocked_id' => $blockedId,
        ]);
    }

    private static function purge(): void
    {
        global $wpdb;

        $blocks = $wpdb->prefix . 'peepso_blocks';
        if (self::tableExists($blocks)) {
            $wpdb->query("DELETE FROM `{$blocks}` WHERE blk_user_id >= 990000 OR blk_blocked_id >= 990000");
        }
    }

    private static function tableExists(string $table): bool
    {
        global $wpdb;

        return (string) $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $table)
        ) === $table;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function filter(array $items): array
    {
        $m = (new ReflectionClass(FeedRankingService::class))->getMethod('dropMutuallyBlockedAuthors');
        $m->setAccessible(true);

        /** @var list<array<string, mixed>> $out */
        $out = $m->invoke(null, $items, self::VIEWER);

        return $out;
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
