<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\StokeRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for StokeRepository::stageForScore() — the pure threshold
 * lookup that turns a velocity score (SUM of capped per-stoker
 * stoke_count within the decay window) into a 1-5 heat_stage. Exercised
 * via reflection (private static, no DB dependency) — same pattern as
 * CosmosVerifierTest in bcc-core.
 */
#[CoversMethod(StokeRepository::class, 'stageForScore')]
final class StokeRepositoryTest extends TestCase
{
    private static function stageForScore(int $score): int
    {
        $ref = new ReflectionMethod(StokeRepository::class, 'stageForScore');
        $ref->setAccessible(true);
        $out = $ref->invoke(null, $score);
        self::assertIsInt($out);
        return $out;
    }

    public function testZeroScoreIsStageOneFloor(): void
    {
        self::assertSame(1, self::stageForScore(0));
    }

    public function testBelowStage2ThresholdStaysStageOne(): void
    {
        self::assertSame(1, self::stageForScore(BCC_STOKE_STAGE_2_MIN - 1));
    }

    public function testExactlyAtEachThresholdCrossesUp(): void
    {
        self::assertSame(2, self::stageForScore(BCC_STOKE_STAGE_2_MIN));
        self::assertSame(3, self::stageForScore(BCC_STOKE_STAGE_3_MIN));
        self::assertSame(4, self::stageForScore(BCC_STOKE_STAGE_4_MIN));
        self::assertSame(5, self::stageForScore(BCC_STOKE_STAGE_5_MIN));
    }

    public function testWellAboveTopThresholdCapsAtStageFive(): void
    {
        self::assertSame(5, self::stageForScore(BCC_STOKE_STAGE_5_MIN * 100));
    }

    public function testStagesAreMonotonicAcrossTheWholeRange(): void
    {
        $prev = 1;
        for ($score = 0; $score <= BCC_STOKE_STAGE_5_MIN + 10; $score++) {
            $stage = self::stageForScore($score);
            self::assertGreaterThanOrEqual($prev, $stage, "stage regressed at score={$score}");
            $prev = $stage;
        }
    }
}
