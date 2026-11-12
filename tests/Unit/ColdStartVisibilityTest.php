<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Repositories {
    // Separate process: declared before the real class can autoload.
    final class UserSyncRepository
    {
        /** @var list<int> */
        public static array $recent = [];

        /** @return list<int> */
        public static function getRecentlyActiveUserIds(int $intervalDays, int $limit, int $excludeUserId = 0): array
        {
            return array_values(array_filter(self::$recent, static fn (int $id): bool => $id !== $excludeUserId));
        }
    }
}

namespace {
    // PeepSo's privacy-aware user search, as MentionPolicy uses it: it
    // returns the subset of `include` the viewer may see.
    final class PeepSoUserSearch
    {
        /** @var array<int, true> ids hidden from everyone (banned, private, ...) */
        public static array $hidden = [];
        /** @var array<int, list<int>> viewer => ids blocked either way */
        public static array $blocked = [];
        /** @var list<array{include: list<int>, viewer: int|null}> */
        public static array $calls = [];

        /** @var list<int> */
        public array $results = [];

        /** @param array{include: list<int>, number: int} $args */
        public function __construct(array $args, ?int $viewerId, string $search)
        {
            self::$calls[] = ['include' => $args['include'], 'viewer' => $viewerId];
            $blocked = $viewerId !== null ? (self::$blocked[$viewerId] ?? []) : [];
            foreach ($args['include'] as $id) {
                if (!isset(self::$hidden[$id]) && !in_array($id, $blocked, true)) {
                    $this->results[] = $id;
                }
            }
        }
    }
}

namespace BCC\Trust\Tests\Unit {

    use BCC\Trust\Core\Repositories\UserSyncRepository;
    use BCC\Trust\Core\Services\FeedColdStartService;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;

    /**
     * F-78: the cold-start panel never surfaces a banned, private or blocked
     * member. Candidate selection is recency only and used to reach the
     * panel unfiltered; it now goes through the members directory's
     * privacy gate (PeepSoUserSearch via MentionPolicy) before the shuffle.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class ColdStartVisibilityTest extends TestCase
    {
        private const VIEWER = 5101;

        protected function setUp(): void
        {
            UserSyncRepository::$recent = [11, 12, 13, 14];
            \PeepSoUserSearch::$hidden  = [12 => true];          // e.g. banned
            \PeepSoUserSearch::$blocked = [self::VIEWER => [13]]; // blocked either way
            \PeepSoUserSearch::$calls   = [];
        }

        /** @return list<int> */
        private function candidates(int $viewerId): array
        {
            $svc = (new \ReflectionClass(FeedColdStartService::class))->newInstanceWithoutConstructor();
            /** @var list<int> */
            return (new \ReflectionMethod(FeedColdStartService::class, 'visibleOperatorCandidates'))->invoke($svc, $viewerId);
        }

        public function testBannedPrivateAndBlockedMembersAreNeverCandidates(): void
        {
            self::assertSame([11, 14], $this->candidates(self::VIEWER));
            self::assertSame([['include' => [11, 12, 13, 14], 'viewer' => self::VIEWER]], \PeepSoUserSearch::$calls, 'the gate sees every candidate, for this viewer');
        }

        public function testAnAnonymousViewerStillGetsTheHiddenFilter(): void
        {
            self::assertSame([11, 13, 14], $this->candidates(0), 'blocks need a viewer; banned/private never show');
            self::assertNull(\PeepSoUserSearch::$calls[0]['viewer']);
        }

        public function testHotPostsAreFetchedForTheViewerSoBlockedAuthorsDrop(): void
        {
            // FeedRankingService is final; getHotFeed() drops authors blocked
            // either way with the viewer it is given, after the shared cache.
            // Cold-start used to pass none. Pinned at the call site.
            $source = file_get_contents(__DIR__ . '/../../app/Domain/Core/Services/FeedColdStartService.php');
            self::assertIsString($source);
            self::assertStringContainsString("'hot_posts'        => \$this->composeHotPosts(\$viewerIdInt),", $source);
            self::assertStringContainsString('->getHotFeed(null, FeedRankingService::HOT_WARM_LIMIT, $viewerId);', $source);
        }
    }
}
