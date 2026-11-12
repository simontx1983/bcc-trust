<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Security\TrustGraph;
use PHPUnit\Framework\TestCase;

/**
 * F-106 (user decision): a member nobody has linked TO yet starts NEUTRAL.
 *
 * Min-max normalisation put the lowest raw PageRank at 0 → clamped to 0.15,
 * and the lowest was always someone with no inbound edges: a member who has
 * voted or vouched but has not been voted on yet. 0.15 is under
 * FraudDetector's 0.2 cut, so they took `very_low_trust` (+30 fraud points)
 * for not having been reviewed yet.
 */
final class TrustRankNoInboundNeutralTest extends TestCase
{
    /**
     * @param array<int, float>             $ranks
     * @param array<int, array<int, float>> $graph
     * @param array<int, float>             $outgoing
     * @return array<int, float>
     */
    private function normalize(array $ranks, array $graph, array $outgoing): array
    {
        $m = new \ReflectionMethod(TrustGraph::class, 'normalizeRanks');
        /** @var array<int, float> */
        return $m->invoke(null, $ranks, $graph, $outgoing);
    }

    public function testAMemberWithNoInboundEdgeIsNeutralNotFloored(): void
    {
        // 1 ⇄ 2, 2 → 3, 3 → 1, and 4 → 1 (4 has voted, nobody has voted on 4).
        $graph    = [1 => [2 => 1.0], 2 => [1 => 1.0, 3 => 1.0], 3 => [1 => 1.0], 4 => [1 => 1.0]];
        $outgoing = [1 => 1.0, 2 => 2.0, 3 => 1.0, 4 => 1.0];
        // Raw ranks as PageRank leaves them: 4 holds only the teleport share.
        $ranks    = [1 => 0.40, 2 => 0.30, 3 => 0.1650, 4 => 0.0375];

        $out = $this->normalize($ranks, $graph, $outgoing);

        self::assertSame(0.5, $out[4], 'no inbound edge: neutral, not 0.15 (very_low_trust)');

        // Everyone else is exactly what the old normalisation gave: the
        // min/max still span ALL members, member 4 included.
        $range = 0.40 - 0.0375;
        self::assertEqualsWithDelta(0.95, $out[1], 1e-9);
        self::assertEqualsWithDelta((0.30 - 0.0375) / $range, $out[2], 1e-9);
        self::assertEqualsWithDelta((0.1650 - 0.0375) / $range, $out[3], 1e-9);
    }

    public function testOneInboundEdgeIsEnoughToBeRanked(): void
    {
        $graph    = [6 => [5 => 1.0], 5 => []];
        $outgoing = [6 => 1.0, 5 => 0.0];
        $ranks    = [5 => 0.6, 6 => 0.1];

        $out = $this->normalize($ranks, $graph, $outgoing);

        self::assertSame(0.95, $out[5], 'linked to once: ranked normally');
        self::assertSame(0.5, $out[6], 'nobody links to 6');
    }

    public function testAnEdgeFromOutsideTheScopeDoesNotCount(): void
    {
        // 9 is not in the rank set being normalised (another component or
        // outside the neighbourhood), so its edge into 7 is not inbound trust
        // here: 7 is neutral, not min-max'd to the 0.15 floor.
        $graph    = [9 => [7 => 1.0], 7 => [8 => 1.0]];
        $outgoing = [9 => 1.0, 7 => 1.0];
        $ranks    = [7 => 0.1, 8 => 0.6];

        $out = $this->normalize($ranks, $graph, $outgoing);

        self::assertSame(0.5, $out[7]);
        self::assertSame(0.95, $out[8]);
    }
}
