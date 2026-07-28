<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\Feed\FeedHydrationPipeline;
use BCC\Trust\Core\Services\UserViewService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression: a feed author's `is_in_good_standing` must reflect the
 * author's REAL reputation tier, not bcc-core's shape-stable `true`
 * sentinel.
 *
 * bcc-core's ActivityFeedService::hydrateAuthors emits
 * `is_in_good_standing => true` for every author because it can't see the
 * trust read model. FeedHydrationPipeline::applyAuthorRankBadge (the
 * bcc-trust layer that owns tier data) must overwrite that sentinel with
 * the honest §E1 value. Left unoverwritten it falsely stamps "good
 * standing" on sub-neutral authors that slip past the reputation
 * shadow-limit — e.g. a low-trust holder inside an NFT group feed, where
 * getGroupFeed drops the caution/risky exclusion.
 *
 * Pinned on the pure, no-DB `applyAuthorRankBadge` seam so the derivation
 * (and its reuse of the canonical UserViewService predicate) can't drift.
 */
final class FeedAuthorGoodStandingTest extends TestCase
{
    /**
     * @param array<string, mixed> $author
     * @param array{reputation_tier: string, reputation_tier_label: string, card_tier: string|null, tier_label: string|null, rank_label: string} $badge
     * @return array<string, mixed>
     */
    private function apply(array $author, array $badge): array
    {
        $m = new ReflectionMethod(FeedHydrationPipeline::class, 'applyAuthorRankBadge');
        $m->setAccessible(true);
        /** @var array<string, mixed> $out */
        $out = $m->invoke(null, $author, $badge);
        return $out;
    }

    /**
     * @param string $tier
     * @return array{reputation_tier: string, reputation_tier_label: string, card_tier: string|null, tier_label: string|null, rank_label: string}
     */
    private function badgeForTier(string $tier): array
    {
        // Mirrors AuthorBadgeResolver's real output shape (contract v1.56 —
        // the retired card_tier/tier_label keys are deliberately absent).
        return [
            'reputation_tier'       => $tier,
            'reputation_tier_label' => \BCC\Trust\Core\Support\ReputationTierMap::toReputationTierLabel($tier),
            'rank_label'            => 'Apprentice',
        ];
    }

    /**
     * The core regression: an author arriving with the bcc-core sentinel
     * `is_in_good_standing => true` but a caution tier must come back
     * false. A regression that drops the overwrite leaves the fabricated
     * true and fails here.
     */
    public function testCautionTierOverwritesFabricatedGoodStanding(): void
    {
        $author = ['id' => 5, 'is_in_good_standing' => true];
        $out = $this->apply($author, $this->badgeForTier('caution'));
        self::assertFalse(
            $out['is_in_good_standing'],
            'a caution-tier author must NOT be stamped in good standing'
        );
    }

    public function testRiskyTierIsNotInGoodStanding(): void
    {
        $out = $this->apply(['id' => 7, 'is_in_good_standing' => true], $this->badgeForTier('risky'));
        self::assertFalse($out['is_in_good_standing']);
    }

    #[DataProvider('goodStandingTiers')]
    public function testGoodStandingTiersRemainTrue(string $tier): void
    {
        $out = $this->apply(['id' => 3], $this->badgeForTier($tier));
        self::assertTrue(
            $out['is_in_good_standing'],
            "{$tier} is in good standing per §E1"
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function goodStandingTiers(): array
    {
        // Mirror the canonical source of truth so a change to the tier
        // list surfaces here rather than silently passing.
        $cases = [];
        foreach (UserViewService::GOOD_STANDING_TIERS as $tier) {
            $cases[$tier] = [$tier];
        }
        return $cases;
    }

    /**
     * The derived value must equal the canonical predicate exactly — this
     * pins the reuse (no inlined tier list in the pipeline).
     */
    public function testMatchesCanonicalPredicate(): void
    {
        foreach (['neutral', 'trusted', 'elite', 'caution', 'risky', 'unknown'] as $tier) {
            $out = $this->apply(['id' => 1], $this->badgeForTier($tier));
            self::assertSame(
                UserViewService::isInGoodStanding($tier),
                $out['is_in_good_standing'],
                "derived standing for {$tier} must match UserViewService::isInGoodStanding"
            );
        }
    }

    /**
     * The other rank-chip fields must still be copied through unchanged —
     * the refactor to a shared helper must not drop any field.
     */
    public function testRankChipFieldsCopiedThrough(): void
    {
        $out = $this->apply(['id' => 9], $this->badgeForTier('trusted'));
        self::assertSame('trusted', $out['reputation_tier']);
        self::assertSame('Trusted', $out['reputation_tier_label']);
        self::assertSame('Apprentice', $out['rank_label']);

        // card_tier / tier_label are RETIRED (contract v1.56) — assert they
        // are GONE, not merely changed. A copy-through helper that silently
        // reintroduced them would otherwise pass unnoticed.
        self::assertArrayNotHasKey('card_tier', $out);
        self::assertArrayNotHasKey('tier_label', $out);
    }
}
