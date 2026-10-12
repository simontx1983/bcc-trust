<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\FraudSignalWorld as W;
use BCC\Trust\Core\Repositories\EdgeRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Security\TrustGraph;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The two DIRECT detector increments, against the REAL TrustGraph.
 *
 *   B  detectVoteRings()        wrote 15 per member via
 *                               UserInfoRepository::incrementFraudScore()
 *   C  detectEndorsementRings() wrote 20 per member the same way
 *
 * These were the structurally worse half of the problem. They were not part
 * of an analysis pass an operator had asked for — they ran from whatever
 * request happened to warm a cold ring cache, so a page view could increment
 * a stranger's fraud score as a side effect, with no audit row and no trace.
 * `incrementFraudScore()` takes a `$reason` and never uses it.
 *
 * ⚠ The REAL TrustGraph is under test here, not a double. The shared stub
 * file deliberately registers its collaborator doubles under `*Double`
 * names so that this class is the production one.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RingDetectorsRecordWithoutScoringTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/fraud-signal-stubs.php';
        W::reset();
    }

    private function graph(): TrustGraph
    {
        return new TrustGraph(new UserInfoRepository(), new EdgeRepository());
    }

    /**
     * A fully-connected trio. total_weight 8.0 per pair is what the SQL
     * produces for two pages each way at vote weight 1.0 (p² rows × 2w), so
     * strength = 8.0 × density 1.0 × 3 members = 24.0, comfortably over the
     * 5.0 threshold. This is the shape a real three-tradesperson group makes.
     */
    private function seedTrioVotePairs(): void
    {
        W::$mutualVotePairs = [
            (object) ['user_a' => 7, 'user_b' => 8, 'total_weight' => 8.0],
            (object) ['user_a' => 8, 'user_b' => 9, 'total_weight' => 8.0],
            (object) ['user_a' => 7, 'user_b' => 9, 'total_weight' => 8.0],
        ];
    }

    /** A → B → C → A, one vouch per edge: the whole of the endorsement rule. */
    private function seedCycleVouchEdges(): void
    {
        W::$vouchEdges = [
            (object) ['endorser_user_id' => 7, 'page_owner_id' => 8],
            (object) ['endorser_user_id' => 8, 'page_owner_id' => 9],
            (object) ['endorser_user_id' => 9, 'page_owner_id' => 7],
        ];
    }

    // ══ B — detectVoteRings ═══════════════════════════════════════════

    public function testDetectingAVoteRingWritesNoFraudScore(): void
    {
        $this->seedTrioVotePairs();

        $rings = $this->graph()->detectVoteRings(3);

        self::assertCount(1, $rings, 'the ring must still be DETECTED');
        self::assertSame([], W::$increments, 'was 15 per member, 45 in total');
        self::assertSame(0, W::incrementedTotal());
    }

    public function testDetectingAVoteRingStillStoresTheObservation(): void
    {
        $this->seedTrioVotePairs();

        $this->graph()->detectVoteRings(3);

        $types = array_column(W::$patterns, 'type');
        self::assertContains('vote_ring', $types, 'the pattern row is the durable observation');
    }

    public function testDetectingAVoteRingStillLogsASoftFlag(): void
    {
        $this->seedTrioVotePairs();

        $this->graph()->detectVoteRings(3);

        $messages = array_column(W::$logs, 'message');
        self::assertNotEmpty(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'vote_ring detected (soft-flag)')
        ));
    }

    public function testTheVoteRingShapeAndStrengthAreUnchanged(): void
    {
        // The detection RULE is untouched by this change — only the
        // consequence is. If this moves, the change was not scoped.
        $this->seedTrioVotePairs();

        $rings = $this->graph()->detectVoteRings(3);

        self::assertSame(3, $rings[0]['size']);
        self::assertEqualsWithDelta(24.0, $rings[0]['strength'], 0.0001);
        self::assertSame([7, 8, 9], $rings[0]['users']);
    }

    public function testAWeakRingIsStillExcludedByTheStrengthGate(): void
    {
        // Three brand-new members at vote weight 0.1 give strength 2.40,
        // under the 5.0 threshold. The gate still gates.
        W::$mutualVotePairs = [
            (object) ['user_a' => 7, 'user_b' => 8, 'total_weight' => 0.8],
            (object) ['user_a' => 8, 'user_b' => 9, 'total_weight' => 0.8],
            (object) ['user_a' => 7, 'user_b' => 9, 'total_weight' => 0.8],
        ];

        self::assertSame([], $this->graph()->detectVoteRings(3));
        self::assertSame([], W::$patterns);
    }

    public function testAPairIsStillBelowTheMinimumComponentSize(): void
    {
        W::$mutualVotePairs = [
            (object) ['user_a' => 7, 'user_b' => 8, 'total_weight' => 8.0],
        ];

        self::assertSame([], $this->graph()->detectVoteRings(3));
    }

    // ══ C — detectEndorsementRings ════════════════════════════════════

    public function testDetectingAnEndorsementRingWritesNoFraudScore(): void
    {
        $this->seedCycleVouchEdges();

        $rings = $this->graph()->detectEndorsementRings(3);

        self::assertNotEmpty($rings, 'the ring must still be DETECTED');
        self::assertSame([], W::$increments, 'was 20 per member, 60 in total');
        self::assertSame(0, W::incrementedTotal());
    }

    public function testDetectingAnEndorsementRingNowStoresAnObservation(): void
    {
        // ⚠ This store is NEW. Before this change the 20-point increment was
        // this detector's ONLY durable artefact: nothing wrote an
        // `endorsement_ring` pattern row and the ring list is cache-only.
        // Removing the increment without adding this would have deleted the
        // observation rather than making it non-punitive.
        $this->seedCycleVouchEdges();

        $this->graph()->detectEndorsementRings(3);

        $rows = array_values(array_filter(
            W::$patterns,
            static fn (array $p): bool => $p['type'] === 'endorsement_ring'
        ));

        self::assertCount(1, $rows);
        self::assertSame(3, $rows[0]['data']['size']);
        self::assertSame([7, 8, 9], $rows[0]['data']['users']);
    }

    public function testTheStoredEndorsementRingDoesNotAssertAStrengthItNeverMeasured(): void
    {
        // This detector applies NO strength gate. null says "none computed";
        // 0.0 would assert a measured weakness.
        $this->seedCycleVouchEdges();

        $this->graph()->detectEndorsementRings(3);

        $rows = array_values(array_filter(
            W::$patterns,
            static fn (array $p): bool => $p['type'] === 'endorsement_ring'
        ));

        self::assertNull($rows[0]['data']['strength']);
        self::assertArrayHasKey('strength_note', $rows[0]['data']);
    }

    public function testDetectingAnEndorsementRingLogsASoftFlag(): void
    {
        $this->seedCycleVouchEdges();

        $this->graph()->detectEndorsementRings(3);

        $messages = array_column(W::$logs, 'message');
        self::assertNotEmpty(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'endorsement_ring detected (soft-flag)')
        ));
    }

    public function testATwoPersonMutualVouchIsStillNotARing(): void
    {
        W::$vouchEdges = [
            (object) ['endorser_user_id' => 7, 'page_owner_id' => 8],
            (object) ['endorser_user_id' => 8, 'page_owner_id' => 7],
        ];

        self::assertSame([], $this->graph()->detectEndorsementRings(3));
    }

    // ══ the retained transients, and the rollback hazard ══════════════

    public function testTheThirtyDayTransientIsStillWritten(): void
    {
        $this->seedTrioVotePairs();

        $this->graph()->detectVoteRings(3);

        self::assertNotEmpty(W::$transients, 'retained: clearing these would re-penalise on a revert');
    }

    public function testASecondPassOverTheSameRingDoesNotRepeatTheSoftFlag(): void
    {
        $this->seedTrioVotePairs();
        $graph = $this->graph();

        $graph->detectVoteRings(3);
        $firstLogs = count(W::$logs);

        // The in-process ring cache would short-circuit a second call, so the
        // transient is what this asserts; clear the cache the way a later
        // request would arrive with it cold.
        W::$logs = [];
        $graph->detectVoteRings(3);

        self::assertSame([], W::$increments);
        self::assertGreaterThan(0, $firstLogs, 'the first pass must actually have logged');
    }

    // ══ anti-vacuity ══════════════════════════════════════════════════

    public function testTheHarnessCanObserveAnIncrementWhenOneHappens(): void
    {
        // Without this, every "no increment" assertion above would also pass
        // if the recording double were broken or never wired up.
        (new UserInfoRepository())->incrementFraudScore(7, 15, 'control');

        self::assertSame(15, W::incrementedTotal());
        self::assertSame('control', W::$increments[0]['reason']);
    }

    public function testTheRealTrustGraphIsUnderTestRatherThanADouble(): void
    {
        // The stub file registers collaborator doubles under `*Double` names
        // precisely so this holds. If a double were ever registered as
        // `BCC\Trust\Core\Security\TrustGraph`, every case here would be
        // testing the stub.
        $reflected = new \ReflectionClass(TrustGraph::class);
        $path      = (string) $reflected->getFileName();

        self::assertStringContainsString(
            'app' . DIRECTORY_SEPARATOR . 'Domain',
            str_replace('/', DIRECTORY_SEPARATOR, $path),
            'TrustGraph must resolve to production code, not tests/Stubs'
        );
        self::assertStringNotContainsString('Stubs', $path);
    }
}
