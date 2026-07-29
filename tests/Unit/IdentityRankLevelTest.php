<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\FeatureAccessService;
use BCC\Trust\Core\Services\LivingService;
use BCC\Trust\Core\Services\RankService;
use BCC\Trust\Core\Support\RankCatalog;
use BCC\Trust\Core\Support\ReputationTierMap;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Locks the Identity Slice — the two orthogonal axes (Rank · Trust
 * Tier) and the level→rank derivation that replaced the old
 * tier-driven rank. Pure functions only; no WP, no DB.
 *
 * The conferred Role axis (Foreman) was retired in contract v1.36, and
 * the top rung was renamed Master → Veteran in v1.58 to match what
 * level 3 actually tests (tenure, not mastery). "Master" is reserved
 * for a future merit rung and must not reappear as a label until it is
 * earned from outcome-confirmed judgment.
 *
 *   - RankService::rankForLevel  — level (1/2/3) → apprentice/journeyman/veteran
 *   - RankCatalog                — Veteran tops the earned ladder; no rung above it
 *   - ReputationTierMap          — member trust labels (elite → "Elite")
 *   - LivingService::rankProgress — honest progress from level thresholds
 */
final class IdentityRankLevelTest extends TestCase
{
    // ── RankService::rankForLevel ────────────────────────────────────

    public function testRankForLevelMapsEachLevelToItsEarnedRank(): void
    {
        self::assertSame(RankCatalog::RANK_APPRENTICE, RankService::rankForLevel(FeatureAccessService::LEVEL_NEW));
        self::assertSame(RankCatalog::RANK_JOURNEYMAN, RankService::rankForLevel(FeatureAccessService::LEVEL_ACTIVE));
        self::assertSame(RankCatalog::RANK_VETERAN, RankService::rankForLevel(FeatureAccessService::LEVEL_VETERAN));
    }

    public function testRankForLevelFallsBackToApprenticeForUnknownLevels(): void
    {
        self::assertSame(RankCatalog::RANK_APPRENTICE, RankService::rankForLevel(0));
        self::assertSame(RankCatalog::RANK_APPRENTICE, RankService::rankForLevel(99));
        self::assertSame(RankCatalog::RANK_APPRENTICE, RankService::rankForLevel(-1));
    }

    public function testLevelToRankMapCoversExactlyTheThreeLevels(): void
    {
        self::assertSame(
            [FeatureAccessService::LEVEL_NEW, FeatureAccessService::LEVEL_ACTIVE, FeatureAccessService::LEVEL_VETERAN],
            array_keys(RankService::LEVEL_TO_RANK)
        );
    }

    // ── RankCatalog — earned ladder ──────────────────────────────────

    public function testEarnedLadderIsApprenticeJourneymanVeteran(): void
    {
        $keys = array_column(RankCatalog::all(), 'key');
        self::assertSame(
            [RankCatalog::RANK_APPRENTICE, RankCatalog::RANK_JOURNEYMAN, RankCatalog::RANK_VETERAN],
            $keys
        );
    }

    public function testEarnedLadderRanksAreAllAutoAssigned(): void
    {
        foreach (RankCatalog::all() as $rank) {
            self::assertTrue($rank['auto_assigned'], "{$rank['key']} should be auto-assigned");
        }
    }

    public function testNextRankClimbsToVeteranThenStops(): void
    {
        self::assertSame(RankCatalog::RANK_JOURNEYMAN, RankCatalog::getNextRank(RankCatalog::RANK_APPRENTICE));
        self::assertSame(RankCatalog::RANK_VETERAN, RankCatalog::getNextRank(RankCatalog::RANK_JOURNEYMAN));
        self::assertNull(RankCatalog::getNextRank(RankCatalog::RANK_VETERAN), 'Veteran is the top of the earned ladder');
    }

    public function testVeteranResolvesLabelAndIsAnAutoEarnedRung(): void
    {
        self::assertTrue(RankCatalog::isValid(RankCatalog::RANK_VETERAN));
        self::assertSame('Veteran', RankCatalog::getLabel(RankCatalog::RANK_VETERAN));
        $veteran = array_values(array_filter(RankCatalog::all(), static fn ($r) => $r['key'] === RankCatalog::RANK_VETERAN));
        self::assertCount(1, $veteran);
        self::assertTrue($veteran[0]['auto_assigned'], 'Veteran is an auto-earned rung');
    }

    /**
     * The retired top-rung slug must not resolve. This is the clean-break
     * guard that makes the v1.58 rename real rather than cosmetic:
     * `master` is neither emitted nor accepted as a current rank.
     *
     * It stays asserted-absent for the same reason Foreman does — "Master"
     * is reserved for a possible future outcome-derived merit rung, and an
     * unearned label must not drift back into the catalog.
     */
    public function testRetiredMasterSlugIsNotOnTheLadder(): void
    {
        self::assertFalse(RankCatalog::isValid('master'));
        self::assertNull(RankCatalog::getLabel('master'));
        self::assertNull(RankCatalog::orderOf('master'));
        self::assertNotContains('master', array_column(RankCatalog::all(), 'key'));
        self::assertNotContains('Master', array_column(RankCatalog::all(), 'label'));
    }

    // ── ReputationTierMap — honest member trust labels ───────────────

    public function testReputationTierLabelsAreTrustWordsNotRarity(): void
    {
        self::assertSame('Risky', ReputationTierMap::toReputationTierLabel('risky'));
        self::assertSame('Caution', ReputationTierMap::toReputationTierLabel('caution'));
        self::assertSame('Neutral', ReputationTierMap::toReputationTierLabel('neutral'));
        self::assertSame('Trusted', ReputationTierMap::toReputationTierLabel('trusted'));
        // Owner decision 2026-07-28: the top tier reads "Elite"; the machine
        // identifier stays `elite`. "Proven" was considered and rejected.
        self::assertSame('Elite', ReputationTierMap::toReputationTierLabel('elite'));
    }

    public function testUnknownTierLabelDefaultsToNeutral(): void
    {
        self::assertSame('Neutral', ReputationTierMap::toReputationTierLabel('bogus'));
        self::assertSame('Neutral', ReputationTierMap::toReputationTierLabel(''));
    }

    public function testResolveReputationReturnsSlugAndLabelWithNoRarityVocabulary(): void
    {
        $caution = ReputationTierMap::resolveReputation('caution');
        self::assertSame('caution', $caution['reputation_tier']);
        self::assertSame('Caution', $caution['reputation_tier_label']);

        // Risky round-trips like every other band. Under the retired rarity
        // mapping it resolved to card_tier:null / tier_label:null, which is
        // exactly why risky members rendered as nothing on card surfaces.
        $risky = ReputationTierMap::resolveReputation('risky');
        self::assertSame('risky', $risky['reputation_tier']);
        self::assertSame('Risky', $risky['reputation_tier_label']);

        // The rarity keys are GONE, not merely renamed.
        self::assertArrayNotHasKey('card_tier', $caution);
        self::assertArrayNotHasKey('tier_label', $caution);
    }

    public function testUnknownTierResolvesToNeutralSlugAndLabel(): void
    {
        $bogus = ReputationTierMap::resolveReputation('bogus');
        self::assertSame('neutral', $bogus['reputation_tier']);
        self::assertSame('Neutral', $bogus['reputation_tier_label']);
    }

    // ── LivingService::rankProgress — honest level-based progression ──

    /**
     * @param array{level: int, next_level_thresholds: list<array{metric: string, label: string, current: int, required: int}>} $featureAccess
     * @return array{current_rank: string, next_rank: string|null, percent: int, remaining_label: string}
     */
    private static function rankProgress(string $rankKey, array $featureAccess): array
    {
        $m = new ReflectionMethod(LivingService::class, 'rankProgress');
        $m->setAccessible(true);
        /** @var array{current_rank: string, next_rank: string|null, percent: int, remaining_label: string} $out */
        $out = $m->invoke(null, $rankKey, $featureAccess);
        return $out;
    }

    public function testProgressAtVeteranIsComplete(): void
    {
        $p = self::rankProgress(RankCatalog::RANK_VETERAN, [
            'level'                 => FeatureAccessService::LEVEL_VETERAN,
            'next_level_thresholds' => [],
        ]);
        self::assertNull($p['next_rank']);
        self::assertSame(100, $p['percent']);
        self::assertSame('Top of the trade.', $p['remaining_label']);
    }

    public function testProgressApprenticeTowardJourneymanUsesPullsGate(): void
    {
        // 2 of 5 pulls done → 40%, honest remaining hint, next = journeyman.
        $p = self::rankProgress(RankCatalog::RANK_APPRENTICE, [
            'level'                 => FeatureAccessService::LEVEL_NEW,
            'next_level_thresholds' => [
                ['metric' => 'pulls', 'label' => 'Pulls', 'current' => 2, 'required' => 5],
            ],
        ]);
        self::assertSame(RankCatalog::RANK_JOURNEYMAN, $p['next_rank']);
        self::assertSame(40, $p['percent']);
        self::assertSame('3 more pulls to reach Journeyman.', $p['remaining_label']);
    }

    public function testProgressUsesLimitingGateAcrossMultipleThresholds(): void
    {
        // Journeyman → Veteran: reviews 2/3 (66%), days 6/30 (20%).
        // The limiting gate (days) drives percent; both unmet clauses listed.
        $p = self::rankProgress(RankCatalog::RANK_JOURNEYMAN, [
            'level'                 => FeatureAccessService::LEVEL_ACTIVE,
            'next_level_thresholds' => [
                ['metric' => 'reviews_written', 'label' => 'Reviews', 'current' => 2, 'required' => 3],
                ['metric' => 'account_age_days', 'label' => 'Days', 'current' => 6, 'required' => 30],
            ],
        ]);
        self::assertSame(RankCatalog::RANK_VETERAN, $p['next_rank']);
        self::assertSame(20, $p['percent']);
        self::assertSame('1 more reviews and 24 more days to reach Veteran.', $p['remaining_label']);
    }

    public function testProgressCapsAt99WhenGateAlreadyMet(): void
    {
        // All gates met but not yet promoted (eventual consistency) →
        // capped at 99, "almost there".
        $p = self::rankProgress(RankCatalog::RANK_APPRENTICE, [
            'level'                 => FeatureAccessService::LEVEL_NEW,
            'next_level_thresholds' => [
                ['metric' => 'pulls', 'label' => 'Pulls', 'current' => 7, 'required' => 5],
            ],
        ]);
        self::assertSame(99, $p['percent']);
        self::assertSame('Almost there — promotion lands shortly.', $p['remaining_label']);
    }
}
