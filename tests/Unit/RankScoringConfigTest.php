<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Rank\Support\RankScoringConfig;
use PHPUnit\Framework\TestCase;

/**
 * The A4 configuration-invariant suite (approved plan rev. 3 + addendum).
 *
 * Every case starts from the REAL shipped includes/config/rank-scoring.php
 * array — so the "valid" path proves the real file passes, and each
 * invariant case proves the factory rejects a single targeted mutation.
 * A config edit that breaks a product invariant fails here before it
 * reaches staging.
 */
final class RankScoringConfigTest extends TestCase
{
    /** @return array<string, mixed> */
    private function realConfig(): array
    {
        /** @var array<string, mixed> $config */
        $config = require dirname(__DIR__, 2) . '/includes/config/rank-scoring.php';
        return $config;
    }

    public function testRealShippedFileIsValidAndSpecLocked(): void
    {
        $config = RankScoringConfig::fromDefaultFile();

        // Spec-locked values pinned exactly (§4.1/§4.2/§16/§18/§21.2).
        self::assertSame(100.0, array_sum($config->categoryMax));
        self::assertSame(40.0, $config->thresholds['journeyman']);
        self::assertSame(80.0, $config->thresholds['veteran']);
        self::assertSame(2.0, $config->eventCap);
        self::assertSame(0.075, $config->maturityFloor);
        self::assertSame(90, $config->maturitySpanDays);
        self::assertSame(1.0, $config->rankMultipliers['apprentice']);
        self::assertSame(1.2, $config->rankMultipliers['journeyman']);
        self::assertSame(1.4, $config->rankMultipliers['veteran']);
        self::assertSame(1.75, $config->voteCeiling);
        self::assertSame(10, $config->quorumVoters);
        self::assertSame(7.5, $config->quorumWeight);
        self::assertSame(0.60, $config->majority);
        self::assertSame(7, $config->bindingMinDays);
        self::assertSame(90, $config->bindingMaxDays);
        self::assertSame(['apprentice' => 2, 'journeyman' => 5, 'veteran' => 10], $config->communityCaps);
        self::assertSame(30, $config->communityCooldownDays);
        self::assertSame(365, $config->decayGraceDays);

        // Illustrative maximum from §16.6: 1.0 × 1.4 × 1.25 = 1.75 exactly.
        self::assertSame(
            $config->voteCeiling,
            max($config->rankMultipliers) * $config->trustMaxValue
        );
    }

    public function testCategoryMaximaMustTotalExactly100(): void
    {
        $config = $this->realConfig();
        $config['category_max']['time'] = 25.0; // 105 total

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('category_max');
        RankScoringConfig::fromArray($config);
    }

    public function testThresholdsMustBeOrderedWithinBounds(): void
    {
        $config = $this->realConfig();
        $config['thresholds']['veteran'] = 30.0; // veteran <= journeyman

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('thresholds');
        RankScoringConfig::fromArray($config);
    }

    public function testMaturityFloorMustBeBelowOne(): void
    {
        $config = $this->realConfig();
        $config['maturity']['floor'] = 1.5;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maturity.floor');
        RankScoringConfig::fromArray($config);
    }

    public function testMaturitySpanMustBePositive(): void
    {
        $config = $this->realConfig();
        $config['maturity']['span_days'] = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maturity.span_days');
        RankScoringConfig::fromArray($config);
    }

    public function testTrustAnchorsMustBeOrdered(): void
    {
        $config = $this->realConfig();
        $config['trust_multiplier']['min_value'] = 1.30; // min >= max

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trust_multiplier');
        RankScoringConfig::fromArray($config);
    }

    public function testVoteCeilingMustBoundMultiplierProduct(): void
    {
        $config = $this->realConfig();
        $config['rank_multipliers']['veteran'] = 1.5; // 1.5 × 1.25 = 1.875 > 1.75

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('vote_ceiling');
        RankScoringConfig::fromArray($config);
    }

    public function testMajorityMustExceedHalf(): void
    {
        $config = $this->realConfig();
        $config['majority'] = 0.5;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('majority');
        RankScoringConfig::fromArray($config);
    }

    public function testQuorumVotersMustBePositive(): void
    {
        $config = $this->realConfig();
        $config['quorum']['voters'] = 0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('quorum.voters');
        RankScoringConfig::fromArray($config);
    }

    public function testShareCapsMustBeWithinUnitInterval(): void
    {
        $config = $this->realConfig();
        $config['share_caps']['recognizer'] = 1.5;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('share_caps.recognizer');
        RankScoringConfig::fromArray($config);
    }

    public function testBindingWindowMustBeOrdered(): void
    {
        $config = $this->realConfig();
        $config['binding_window'] = ['min_days' => 90, 'max_days' => 7];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('binding_window');
        RankScoringConfig::fromArray($config);
    }

    public function testCommunityCapsMustBeStrictlyOrdered(): void
    {
        $config = $this->realConfig();
        $config['community']['caps']['journeyman'] = 1; // apprentice(2) >= journeyman(1)

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('community.caps');
        RankScoringConfig::fromArray($config);
    }

    public function testMasterMustBeAbsentEverywhere(): void
    {
        // §3.2 / invariant 34 — the real file must not mention it…
        $raw = file_get_contents(dirname(__DIR__, 2) . '/includes/config/rank-scoring.php');
        self::assertIsString($raw);
        self::assertStringNotContainsStringIgnoringCase("'master'", $raw);

        // …and the factory rejects a rank-keyed 'master' entry outright
        // (mapShape treats it as an unknown key — dormant support is
        // structurally impossible, not just absent).
        $config = $this->realConfig();
        $config['rank_multipliers']['master'] = 1.6;

        $this->expectException(\InvalidArgumentException::class);
        RankScoringConfig::fromArray($config);
    }

    public function testMissingKeyIsRejected(): void
    {
        $config = $this->realConfig();
        unset($config['quorum']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("missing key 'quorum'");
        RankScoringConfig::fromArray($config);
    }

    public function testUnknownTopLevelKeyIsRejected(): void
    {
        $config = $this->realConfig();
        $config['surprise_multiplier'] = 2.0;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("unknown key 'surprise_multiplier'");
        RankScoringConfig::fromArray($config);
    }

    public function testTierOrdMustEncodeQualificationOrder(): void
    {
        $config = $this->realConfig();
        $config['tier_ord'] = ['risky' => 0, 'caution' => 2, 'neutral' => 1, 'trusted' => 3, 'elite' => 4];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tier_ord');
        RankScoringConfig::fromArray($config);
    }

    public function testTrustWindowQualifyingDaysBounded(): void
    {
        $config = $this->realConfig();
        $config['trust_windows']['journeyman']['qualifying_days'] = 61; // > 60-day window

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('trust_windows.journeyman');
        RankScoringConfig::fromArray($config);
    }

    public function testTierOrdForDefaultsToNeutral(): void
    {
        $config = RankScoringConfig::fromDefaultFile();

        self::assertSame(2, $config->tierOrdFor('neutral'));
        self::assertSame(4, $config->tierOrdFor('elite'));
        // Unknown slug (legacy data, typo) resolves to neutral — the
        // same default ReputationRepository::getTier uses.
        self::assertSame(2, $config->tierOrdFor('legendary'));
    }
}
