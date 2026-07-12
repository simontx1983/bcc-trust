<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\FeatureAccessService;
use BCC\Trust\Core\Services\RankService;
use BCC\Trust\Core\Services\UserViewService;
use BCC\Trust\Core\Support\RankCatalog;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the /members rank perf seam: UserViewService::getSummary resolves
 * the rank chip from the prefetched `levels` map (FeatureAccessService::
 * getLevelsForUsers, batched via MemberSummaryPrefetcher) instead of the
 * per-row autoDerivedRank → getLevel chain — two follower COUNTs, a
 * votes-cast COUNT, and a wallet-links read PER MEMBER, the exact
 * 4-queries-per-row growth measured on GET /members (2026-07-12).
 *
 * The batched and per-user paths share resolveLevel and the
 * admin-tunable thresholds, so for any given level the derived
 * rank key/label pair MUST be identical. This suite pins that mapping
 * and the absent-user default (level 1 → apprentice, the same outcome
 * the per-user path produces for zero counts).
 *
 * Isolation: rankFromPrefetchedLevel is pure — RankService::rankForLevel
 * and RankCatalog::getLabel are static lookups — so the service is built
 * without its constructor and the private method invoked by reflection,
 * the same posture as MemberNegativeSignalsPrefetchTest. No DB, no WP.
 */
final class MemberRankLevelPrefetchTest extends TestCase
{
    private const USER_ID = 7;

    /**
     * @param array<int, int> $levels
     * @return array{key: string, label: string}
     */
    private function rankFor(array $levels): array
    {
        $service = (new ReflectionClass(UserViewService::class))->newInstanceWithoutConstructor();
        $method  = new ReflectionMethod(UserViewService::class, 'rankFromPrefetchedLevel');
        /** @var array{key: string, label: string} $result */
        $result = $method->invoke($service, self::USER_ID, $levels);
        return $result;
    }

    public function testEachLevelResolvesTheSameRankAsThePerUserChain(): void
    {
        $levels = [
            FeatureAccessService::LEVEL_NEW,
            FeatureAccessService::LEVEL_ACTIVE,
            FeatureAccessService::LEVEL_VETERAN,
        ];
        foreach ($levels as $level) {
            $rank = $this->rankFor([self::USER_ID => $level]);

            // The per-user chain is autoDerivedRank = rankForLevel(getLevel());
            // with the level fixed, both paths reduce to rankForLevel + the
            // RankCatalog label — assert the batched branch matches it.
            $expectedKey = RankService::rankForLevel($level);
            self::assertSame($expectedKey, $rank['key'], "level {$level} key");
            self::assertSame(RankCatalog::getLabel($expectedKey), $rank['label'], "level {$level} label");
        }
    }

    public function testConcreteLadderMapping(): void
    {
        self::assertSame(
            ['key' => RankCatalog::RANK_APPRENTICE, 'label' => 'Apprentice'],
            $this->rankFor([self::USER_ID => FeatureAccessService::LEVEL_NEW])
        );
        self::assertSame(
            ['key' => RankCatalog::RANK_JOURNEYMAN, 'label' => 'Journeyman'],
            $this->rankFor([self::USER_ID => FeatureAccessService::LEVEL_ACTIVE])
        );
        self::assertSame(
            ['key' => RankCatalog::RANK_MASTER, 'label' => 'Master'],
            $this->rankFor([self::USER_ID => FeatureAccessService::LEVEL_VETERAN])
        );
    }

    public function testUserAbsentFromLevelsMapDefaultsToApprentice(): void
    {
        $rank = $this->rankFor([]);
        self::assertSame(RankCatalog::RANK_APPRENTICE, $rank['key']);
        self::assertSame('Apprentice', $rank['label']);
    }

    public function testOtherUsersLevelsDoNotBleedIn(): void
    {
        // A veteran level keyed on a DIFFERENT user must not leak into
        // this user's rank — absent id resolves to the apprentice default.
        $rank = $this->rankFor([999 => FeatureAccessService::LEVEL_VETERAN]);
        self::assertSame(RankCatalog::RANK_APPRENTICE, $rank['key']);
    }
}
