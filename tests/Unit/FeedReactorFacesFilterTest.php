<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Repositories\PeepSoReactionRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Services\Feed\FeedHydrationPipeline;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * F-90: the feed's reactor faces never show a SUSPENDED member, or one the
 * viewer has blocked or is blocked by. Either read failing drops every face
 * on the page: faces are decoration, so none is the safe answer.
 *
 * The real hydrateSocialProofReactors() runs; the reaction, block, suspension
 * and identity reads are doubled (tests/Stubs/feed-reactor-filter-stubs.php).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FeedReactorFacesFilterTest extends TestCase
{
    private const VIEWER = 10;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/feed-reactor-filter-stubs.php';
        $GLOBALS['__bcc_rf'] = [
            'reactors'    => [100 => [21, 22, 23]],
            'blocked'     => [],
            'block_calls' => [],
            'users'       => [21 => 'Ana', 22 => 'Ben', 23 => 'Cy'],
        ];
        $this->suspended([]);
    }

    /** @param list<int>|null $ids null = the suspension read failed */
    private function suspended(?array $ids): void
    {
        $repo = new class ($ids) extends UserInfoRepository {
            /** @param list<int>|null $ids */
            public function __construct(private ?array $ids)
            {
            }

            public function suspendedAmongOrUnknown(array $userIds): ?array
            {
                return $this->ids === null ? null : array_values(array_intersect($userIds, $this->ids));
            }
        };
        (new ReflectionClass(Plugin::class))->getProperty('userInfoRepository')->setValue(Plugin::instance(), $repo);
    }

    /** @return list<string> the face handles on the single item, or [] when it has none */
    private static function faces(int $viewerId): array
    {
        $class    = new ReflectionClass(FeedHydrationPipeline::class);
        $pipeline = $class->newInstanceWithoutConstructor();
        $class->getProperty('reactionRepo')->setValue($pipeline, new PeepSoReactionRepository());

        /** @var list<array<string, mixed>> $out */
        $out = (new ReflectionMethod(FeedHydrationPipeline::class, 'hydrateSocialProofReactors'))
            ->invoke($pipeline, [['id' => 'feed_100']], $viewerId);

        return array_map(
            static fn (array $r): string => $r['handle'],
            $out[0]['social_proof']['recent_reactors'] ?? []
        );
    }

    public function testEveryReactorShowsWhenNoneIsExcluded(): void
    {
        self::assertSame(['handle21', 'handle22', 'handle23'], self::faces(self::VIEWER));
    }

    public function testASuspendedReactorIsNotShown(): void
    {
        $this->suspended([22]);

        self::assertSame(['handle21', 'handle23'], self::faces(self::VIEWER));
    }

    public function testABlockedReactorIsNotShownToTheViewer(): void
    {
        $GLOBALS['__bcc_rf']['blocked'] = [23];

        self::assertSame(['handle21', 'handle22'], self::faces(self::VIEWER));
        self::assertSame([[self::VIEWER, [21, 22, 23]]], $GLOBALS['__bcc_rf']['block_calls']);
    }

    public function testAnonymousViewersSkipTheBlockRead(): void
    {
        $GLOBALS['__bcc_rf']['blocked'] = [23];

        self::assertSame(['handle21', 'handle22', 'handle23'], self::faces(0));
        self::assertSame([], $GLOBALS['__bcc_rf']['block_calls']);
    }

    public function testAFailedSuspensionReadDropsEveryFace(): void
    {
        $this->suspended(null);

        self::assertSame([], self::faces(self::VIEWER));
    }

    public function testAFailedBlockReadDropsEveryFace(): void
    {
        $GLOBALS['__bcc_rf']['blocked'] = null;

        self::assertSame([], self::faces(self::VIEWER));
    }

    public function testEveryReactorExcludedLeavesNoFaces(): void
    {
        $this->suspended([21, 22, 23]);

        self::assertSame([], self::faces(self::VIEWER));
    }
}
