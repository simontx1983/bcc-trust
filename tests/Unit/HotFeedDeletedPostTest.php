<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Trust\Core\Services\Feed\FeedRankingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/../Stubs/blocked-sender-stubs.php';
require_once __DIR__ . '/../Stubs/hot-feed-cache-stubs.php';

/**
 * F-277: a post its author deletes leaves the cached anon hot-feed first
 * page at once (on `bcc_post_deleted`), not at the next warm tick or the
 * 300 s TTL. Moderation hides were already instant; a delete moves no
 * generation, so it needed its own path.
 *
 * Same seam as HotFeedCacheIsolationTest: forgetDeletedPost() only reads
 * and writes the cache, so an instance without a constructor is enough, and
 * a path that tried to REBUILD inside the delete request would fail here on
 * an uninitialised collaborator rather than pass.
 */
#[CoversClass(FeedRankingService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HotFeedDeletedPostTest extends TestCase
{
    private const DELETED = 501;
    private const KEPT_A  = 502;
    private const KEPT_B  = 503;

    private static string $payloadId = '';

    protected function setUp(): void
    {
        parent::setUp();
        \BccHotFeedCacheStore::reset();
        PeepSoBlockRepository::$blocking  = [];
        PeepSoBlockRepository::$readFails = false;
        $this->seedCache([self::KEPT_A, self::DELETED, self::KEPT_B]);
    }

    public function testTheDeletedPostLeavesTheCachedPageAtOnce(): void
    {
        self::service()->forgetDeletedPost(self::DELETED);

        self::assertSame(['feed_' . self::KEPT_A, 'feed_' . self::KEPT_B], self::cachedIds());
        self::assertSame(
            ['next_cursor' => 'c1', 'has_more' => true],
            self::cachedPayload()['pagination'],
            'pagination is untouched'
        );
    }

    public function testTheNextAnonymousReadNoLongerShowsIt(): void
    {
        self::service()->forgetDeletedPost(self::DELETED);

        $page = self::service()->getHotFeed(null, FeedRankingService::HOT_WARM_LIMIT, 0);

        self::assertNotContains('feed_' . self::DELETED, array_column($page['items'], 'id'));
    }

    public function testAPostNotOnTheCachedPageWritesNothing(): void
    {
        \BccHotFeedCacheStore::$writes = [];

        self::service()->forgetDeletedPost(999);

        self::assertNotContains(self::$payloadId, \BccHotFeedCacheStore::$writes);
        self::assertSame(
            ['feed_' . self::KEPT_A, 'feed_' . self::DELETED, 'feed_' . self::KEPT_B],
            self::cachedIds()
        );
    }

    public function testAnEmptyCacheIsANoOp(): void
    {
        \BccHotFeedCacheStore::reset();

        self::service()->forgetDeletedPost(self::DELETED);

        self::assertArrayNotHasKey(self::$payloadId, \BccHotFeedCacheStore::$store, 'no page is invented');
        self::assertNotContains(self::$payloadId, \BccHotFeedCacheStore::$writes);
    }

    // ── helpers ─────────────────────────────────────────────────────

    /** @param list<int> $actIds */
    private function seedCache(array $actIds): void
    {
        $r     = new ReflectionClass(FeedRankingService::class);
        $keyFn = $r->getMethod('hotFeedCacheKey');
        /** @var string $key */
        $key = $keyFn->invoke(null, FeedRankingService::HOT_WARM_LIMIT);
        /** @var string $group */
        $group = $r->getConstant('HOT_CACHE_GROUP');

        $items = [];
        foreach ($actIds as $id) {
            $items[] = ['id' => 'feed_' . $id, 'author' => ['kind' => 'user', 'id' => 70, 'handle' => 'u70']];
        }
        wp_cache_set($key, [
            'items'      => $items,
            'pagination' => ['next_cursor' => 'c1', 'has_more' => true],
        ], $group);

        self::$payloadId = \BccHotFeedCacheStore::id($key, $group);
    }

    /** @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>} */
    private static function cachedPayload(): array
    {
        self::assertArrayHasKey(self::$payloadId, \BccHotFeedCacheStore::$store);
        /** @var array{items: list<array<string, mixed>>, pagination: array<string, mixed>} $p */
        $p = \BccHotFeedCacheStore::$store[self::$payloadId];

        return $p;
    }

    /** @return list<mixed> */
    private static function cachedIds(): array
    {
        return array_column(self::cachedPayload()['items'], 'id');
    }

    private static function service(): FeedRankingService
    {
        return (new ReflectionClass(FeedRankingService::class))->newInstanceWithoutConstructor();
    }
}
