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
 * `/feed/hot`'s per-viewer filter must never reach the SHARED cache.
 *
 * ⚠⚠ THE BUG THIS GUARDS AGAINST IS WORSE THAN THE ONE IT ENABLES. The hot
 * feed is built once with the anonymous posture, cached globally and warmed
 * by cron. Filtering §K1-blocked authors for a signed-in reader is correct —
 * but if that filtered, viewer-specific payload were written back, every
 * other reader (including logged-out ones) would be served ONE viewer's
 * feed, leaking who they block by absence. Strictly worse than the blocked
 * author it removes.
 *
 * ⭐ The code makes that structurally impossible rather than ordering-
 * dependent: the cache read and write both live in the private
 * `hotFeedPayload()`, and `getHotFeed()` filters the copy it returns. This
 * file holds the structure to its promise at runtime.
 *
 * ── HOW THE SERVICE IS EXERCISED WITHOUT ITS FIVE DEPENDENCIES ───────────
 * ⭐ On a CACHE HIT, `hotFeedPayload()` returns before touching any injected
 * collaborator. So the instance is built with
 * `newInstanceWithoutConstructor()` and the cache is pre-seeded: the real
 * `getHotFeed()`, the real `hotFeedPayload()` and the real filter all run,
 * and nothing needs `ActivityFeedService` (which is bcc-core and is not even
 * autoloaded in this suite). No partial doubles, no five stub classes.
 *
 * ⚠ The seam is narrow on purpose: the moment this test exercises a cache
 * MISS it will fail on an uninitialised readonly property rather than pass
 * vacuously, which is the right way for that assumption to break.
 */
#[CoversClass(FeedRankingService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HotFeedCacheIsolationTest extends TestCase
{
    private const VIEWER  = 7;
    private const BLOCKED = 42;
    private const OTHER   = 43;
    private const LIMIT   = 20;

    /** The store id of the payload entry, captured when it is seeded. */
    private static string $payloadId = '';

    protected function setUp(): void
    {
        parent::setUp();
        \BccHotFeedCacheStore::reset();
        PeepSoBlockRepository::$blocking  = [];
        PeepSoBlockRepository::$readFails = false;
    }

    // ── the property ────────────────────────────────────────────────

    /**
     * ⚠⚠ THE HEADLINE, both halves in one test because they are one
     * property: the signed-in reader does NOT get the blocked author, AND
     * the shared cache still holds them afterwards.
     */
    public function testAFilteredPageDoesNotPoisonTheSharedCache(): void
    {
        PeepSoBlockRepository::$blocking = [[self::VIEWER, self::BLOCKED]];
        $this->seedCache();

        $page = self::getHotFeed(self::VIEWER);

        self::assertSame(
            [self::OTHER],
            self::authorIds($page['items']),
            'the signed-in viewer must not see the author they blocked'
        );

        self::assertSame(
            [self::BLOCKED, self::OTHER],
            self::authorIds(self::cachedPayload()['items']),
            'and the SHARED cache must still contain that author for everyone else'
        );
    }

    /**
     * ⚠ The consequence of the above, stated as its own case because it is
     * the actual leak: the next reader is unaffected. An anonymous request
     * after a signed-in one still sees the full feed.
     */
    public function testAnAnonymousReadAfterAFilteredOneIsUnaffected(): void
    {
        PeepSoBlockRepository::$blocking = [[self::VIEWER, self::BLOCKED]];
        $this->seedCache();

        self::getHotFeed(self::VIEWER);
        $anon = self::getHotFeed(0);

        self::assertSame([self::BLOCKED, self::OTHER], self::authorIds($anon['items']));
    }

    /** And a different signed-in viewer gets their own answer, not the first one's. */
    public function testASecondViewerGetsTheirOwnFilter(): void
    {
        PeepSoBlockRepository::$blocking = [[self::VIEWER, self::BLOCKED]];
        $this->seedCache();

        self::getHotFeed(self::VIEWER);
        $second = self::getHotFeed(99);

        self::assertSame(
            [self::BLOCKED, self::OTHER],
            self::authorIds($second['items']),
            'viewer 99 blocks nobody, so they see the whole page'
        );
    }

    /**
     * ⚠ ANTI-VACUITY: a `getHotFeed()` that filtered nothing would pass the
     * cache assertions above. With the read FAILING, the fail-closed rule
     * must still apply on this surface too.
     */
    public function testAnUnreadableBlockTableStillFailsClosedHere(): void
    {
        PeepSoBlockRepository::$readFails = true;
        $this->seedCache();

        self::assertSame([], self::getHotFeed(self::VIEWER)['items']);

        self::assertSame(
            [self::BLOCKED, self::OTHER],
            self::authorIds(self::cachedPayload()['items']),
            'and failing closed must not empty the shared cache either'
        );
    }

    /**
     * The PAYLOAD key is not rewritten on the cache-hit path.
     *
     * ⚠ Scoped to that key rather than "no writes at all": reading the
     * generation counter that keys the payload creates its own entry, which
     * is unrelated to the property here.
     */
    public function testAReadDoesNotRewriteThePayloadEntry(): void
    {
        PeepSoBlockRepository::$blocking = [[self::VIEWER, self::BLOCKED]];
        $this->seedCache();

        self::getHotFeed(self::VIEWER);

        self::assertNotContains(
            self::$payloadId,
            \BccHotFeedCacheStore::$writes,
            'the shared payload must not be written back on a read'
        );
    }

    // ── the isolation must be in force, not assumed ─────────────────

    /**
     * ⚠⚠ PROVE THE PROCESS ISOLATION, DO NOT TRUST THE ATTRIBUTE. This file
     * declares `wp_cache_get()` / `wp_cache_set()`, which two other
     * harnesses in this repository also declare; a
     * `#[RunTestsInSeparateProcesses]` that silently were not in force would
     * leak those declarations into every later test in the process.
     *
     * #381 found 46 tests running in 2.6s where 33 had taken 18.8s — which
     * did not look like forked processes — so this is asserted by a property
     * the code depends on rather than by a stopwatch: the store starts EMPTY
     * in every test despite earlier tests having filled it. In one shared
     * process the previous test's entries would still be here.
     */
    public function testEachTestGetsAFreshProcessAndStore(): void
    {
        self::assertSame([], \BccHotFeedCacheStore::$store);
        self::assertSame([], \BccHotFeedCacheStore::$writes);

        // Leave something behind; a sibling asserting emptiness proves the
        // fork, and setUp() alone would hide a shared process.
        \BccHotFeedCacheStore::$store['leftover'] = 'from testEachTestGetsAFreshProcess';
    }

    /** The sibling half of the case above. */
    public function testTheStoreIsNotCarriedOverFromTheSiblingTest(): void
    {
        self::assertArrayNotHasKey(
            'leftover',
            \BccHotFeedCacheStore::$store,
            'a leftover here means the tests share one process and the stubs leak'
        );
    }

    // ── helpers ─────────────────────────────────────────────────────

    /**
     * Pre-seed the shared cache with the anonymous payload, using the key
     * the service itself computes.
     */
    private function seedCache(): void
    {
        $r = new ReflectionClass(FeedRankingService::class);

        $keyFn = $r->getMethod('hotFeedCacheKey');
        $keyFn->setAccessible(true);
        /** @var string $key */
        $key = $keyFn->invoke(null, self::LIMIT);

        /** @var string $group */
        $group = $r->getConstant('HOT_CACHE_GROUP');

        wp_cache_set($key, [
            'items'      => [self::item(self::BLOCKED), self::item(self::OTHER)],
            'pagination' => ['next_cursor' => null, 'has_more' => false],
        ], $group);

        self::$payloadId = \BccHotFeedCacheStore::id($key, $group);

        \BccHotFeedCacheStore::$writes = [];
    }

    /**
     * The shared payload entry, read by the exact key the service uses.
     *
     * ⚠ NOT "the only entry in the store". `hotFeedCacheKey()` consults a
     * GENERATION COUNTER (hence the `:g0` suffix on the key) and that
     * counter's own cache entry is created as a side effect of reading it —
     * so the store legitimately holds two things, and an
     * `assertCount(1, ...)` here failed for a reason that had nothing to do
     * with the property under test.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    private static function cachedPayload(): array
    {
        self::assertNotSame('', self::$payloadId, 'seedCache() must run first');
        self::assertArrayHasKey(self::$payloadId, \BccHotFeedCacheStore::$store);

        /** @var array{items: list<array<string, mixed>>, pagination: array<string, mixed>} $payload */
        $payload = \BccHotFeedCacheStore::$store[self::$payloadId];

        return $payload;
    }

    /**
     * The real `getHotFeed()`, on an instance with no constructor — safe
     * only on the cache-hit path. See the class docblock.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, mixed>}
     */
    private static function getHotFeed(int $viewerId): array
    {
        $service = (new ReflectionClass(FeedRankingService::class))->newInstanceWithoutConstructor();

        /** @var array{items: list<array<string, mixed>>, pagination: array<string, mixed>} $out */
        $out = $service->getHotFeed(null, self::LIMIT, $viewerId);

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
    private static function authorIds(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $author = is_array($item['author'] ?? null) ? $item['author'] : [];
            if (isset($author['id'])) {
                $out[] = (int) $author['id'];
            }
        }

        return $out;
    }
}
