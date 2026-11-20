<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\Feed\FeedRankingService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The shared hot-feed page is cached for everyone only when bcc-core did
 * not have to mask author names while building it
 * (ActivityFeedService::authorReadFailures()). A masked page is served to
 * the request that built it, and neither the request path nor the cron
 * warm stores it. Rationale: M3 (private tracker).
 *
 * Runs on the f02 wiring harness: its core ActivityFeedService double
 * bumps the counter during getFeed() when `mask_authors` is set, its cache
 * always misses (so every call builds), and it records every cache write.
 *
 * Separate processes: the harness declares core classes and namespaced
 * cache functions.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(FeedRankingService::class)]
final class HotFeedMaskedAuthorsNotCachedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/feed-f02-wiring-stubs.php';
        $GLOBALS['__bcc_f02feed'] = [
            'is_admin'         => false,
            'friend_ids'       => [],
            'calls'            => [],
            'group_rule'       => true,
            'group_rule_calls' => [],
            'cache_writes'     => [],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_f02feed']);
        parent::tearDown();
    }

    private function service(): FeedRankingService
    {
        return new FeedRankingService(
            new \BCC\Core\Feed\ActivityFeedService(),
            new \BCC\Trust\Core\Repositories\ReputationRepository(),
            new \BCC\Trust\Core\Repositories\HiddenActivityRepository(),
            new \BCC\Trust\Core\Services\GroupContextResolver(),
            new \BCC\Trust\Core\Services\Feed\FeedHydrationPipeline(),
            new \BCC\Trust\Core\Services\FeedItemReportVisibility()
        );
    }

    /** The hot-page payload writes (the group's key-generation writes aside). */
    private static function payloadWrites(int $limit): array
    {
        $key = (new \ReflectionMethod(FeedRankingService::class, 'hotFeedCacheKey'))->invoke(null, $limit);
        $id  = 'bcc_trust:feed/' . $key;

        return array_values(array_filter(
            (array) ($GLOBALS['__bcc_f02feed']['cache_writes'] ?? []),
            static fn ($w): bool => $w === $id
        ));
    }

    public function testAPageBuiltWithMaskedNamesIsServedButNotCached(): void
    {
        $GLOBALS['__bcc_f02feed']['mask_authors'] = true;

        $page = $this->service()->getHotFeed(null, 20, 0);

        self::assertArrayHasKey('items', $page, 'the request still gets its page');
        self::assertSame([], self::payloadWrites(20), 'nothing is stored for everyone');
    }

    public function testAPageBuiltCleanlyIsCached(): void
    {
        $this->service()->getHotFeed(null, 20, 0);

        self::assertCount(1, self::payloadWrites(20), 'control: the normal build is cached');
    }

    public function testTheCronWarmDoesNotStoreAMaskedPage(): void
    {
        $GLOBALS['__bcc_f02feed']['mask_authors'] = true;
        $this->service()->warmHotFeed();
        self::assertSame([], self::payloadWrites(FeedRankingService::HOT_WARM_LIMIT));

        unset($GLOBALS['__bcc_f02feed']['mask_authors']);
        $this->service()->warmHotFeed();
        self::assertCount(1, self::payloadWrites(FeedRankingService::HOT_WARM_LIMIT), 'control: a clean warm stores');
    }

    /**
     * A name bcc-trust masked during the build (a privacy flag it could not
     * read, as the reactor faces do through visibleName()) keeps the page
     * out of the shared cache too.
     */
    public function testAPageWithATrustSideMaskIsServedButNotCached(): void
    {
        \BCC\Core\Repositories\UserMetaRepository::$unreadable = [4242 => true];
        $GLOBALS['__bcc_f02feed']['mask_trust_for'] = 4242;

        $this->service()->getHotFeed(null, 20, 0);
        self::assertSame([], self::payloadWrites(20));

        $this->service()->warmHotFeed();
        self::assertSame([], self::payloadWrites(FeedRankingService::HOT_WARM_LIMIT), 'nor by the cron warm');
    }

    /** The count is compared around EACH build, not since the request began. */
    public function testAnEarlierMaskedBuildDoesNotBlockALaterCleanOne(): void
    {
        $GLOBALS['__bcc_f02feed']['mask_authors'] = true;
        $this->service()->getHotFeed(null, 20, 0);

        unset($GLOBALS['__bcc_f02feed']['mask_authors']);
        $this->service()->getHotFeed(null, 20, 0);

        self::assertCount(1, self::payloadWrites(20));
    }
}
