<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Services\AuthorBadgeResolver;
use BCC\Trust\Core\Services\Feed\FeedHydrationPipeline;
use BCC\Trust\Rank\Services\RankStateService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Author trust score on FeedAuthor / CommentAuthor (Figma profile redesign:
 * the post card shows "TRUSTED 72").
 *
 *   - The number is the PROFILE's: one display rule
 *     (ReputationRepository::getDisplayScore — clamp 0–100, round), shared
 *     by UserViewService's trust_score and AuthorBadgeResolver.
 *   - Batched: one primeByUserIds() for the page's authors, then every
 *     score is read from the request memo — no query per author.
 *   - Copied into the author block next to the tier.
 */
final class AuthorTrustScoreTest extends TestCase
{
    /** @return array<string, array{float, int}> */
    public static function scores(): array
    {
        return [
            'in range, rounded down' => [72.4, 72],
            'in range, rounded up'   => [72.5, 73],
            'below 0 clamps'         => [-5.0, 0],
            'above 100 clamps'       => [150.0, 100],
            'the neutral default'    => [50.0, 50],
        ];
    }

    #[DataProvider('scores')]
    public function testTheDisplayRuleClampsAndRounds(float $raw, int $shown): void
    {
        $repo = new class ($raw) extends ReputationRepository {
            public function __construct(private float $raw)
            {
            }

            public function getScore(int $userId): float
            {
                return $this->raw;
            }
        };

        self::assertSame($shown, $repo->getDisplayScore(7));
    }

    public function testTheResolverPrimesOnceThenReadsEveryScoreFromTheMemo(): void
    {
        $repo = new class () extends ReputationRepository {
            /** @var list<string> */
            public array $calls = [];

            public function __construct()
            {
            }

            public function getTiersForUsers(array $userIds): array
            {
                $this->calls[] = 'tiers';

                return [1 => 'trusted', 2 => 'caution'];
            }

            public function primeByUserIds(array $userIds): void
            {
                $this->calls[] = 'prime:' . implode(',', $userIds);
            }

            public function getScore(int $userId): float
            {
                $this->calls[] = 'score:' . $userId;

                return [1 => 72.4, 2 => 31.0, 3 => 50.0][$userId];
            }
        };

        $out = $this->resolver($repo)->resolveForUsers([1, 2, 3, 2, 0]);

        self::assertSame(72, $out[1]['trust_score']);
        self::assertSame(31, $out[2]['trust_score']);
        self::assertSame(50, $out[3]['trust_score']);

        $primes = array_values(array_filter($repo->calls, static fn (string $c): bool => str_starts_with($c, 'prime:')));
        self::assertSame(['prime:1,2,3'], $primes, 'exactly one prime, over the deduped page');
        self::assertLessThan(
            array_search('score:1', $repo->calls, true),
            array_search('prime:1,2,3', $repo->calls, true),
            'the prime happens before the first per-author read'
        );
    }

    public function testTheFeedAuthorBlockCarriesTheScore(): void
    {
        $m = new ReflectionMethod(FeedHydrationPipeline::class, 'applyAuthorRankBadge');
        $author = $m->invoke(null, ['id' => 5], [
            'reputation_tier'       => 'trusted',
            'reputation_tier_label' => 'Trusted',
            'trust_score'           => 72,
            'rank_label'            => 'Apprentice',
            'member_state'          => 'ranked',
        ]);

        self::assertSame(72, $author['trust_score']);
        self::assertSame('trusted', $author['reputation_tier']);
    }

    public function testTheCommentAuthorBlockCopiesTheScore(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Services/CommentService.php');

        self::assertStringContainsString("\$author['trust_score'] = (int) \$badge['trust_score'];", $src);
    }

    public function testTheProfileUsesTheSameDisplayRule(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Services/UserViewService.php');

        self::assertStringContainsString('$this->reputationRepo->getDisplayScore($userId)', $src, 'one rule for profile and author block');
    }

    private function resolver(ReputationRepository $repo): AuthorBadgeResolver
    {
        $rankDouble = new class () extends RankStateService {
            public function __construct()
            {
            }

            public function memberStatesFor(array $userIds): array
            {
                $out = [];
                foreach ($userIds as $id) {
                    $out[$id] = ['rank_label' => null, 'member_state' => 'new_member'];
                }

                return $out;
            }
        };

        return new AuthorBadgeResolver($repo, $rankDouble);
    }
}
