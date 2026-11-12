<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\FraudSignalWorld as W;
use BCC\Trust\Core\Security\FraudDetector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-234: on a COLD `ring_results` cache, analyzeFraud() falls back to
 * TrustGraph::detectVoteRings(), whose edge read is fail-closed (F-209)
 * and can throw. That used to abort the WHOLE analysis, so no scoring
 * signal was stored, for a 0-point observation.
 *
 * Decision (b), approved: rings are UNKNOWN for that run. The ring block
 * is skipped, every other signal still scores, and nothing is added to
 * `details` (so `confidence` reads as "not in a ring").
 *
 * The stub cache always misses, so every analysis here runs cold.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ColdCacheRingReadFailureSkipsTest extends TestCase
{
    private const UID = 7;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/fraud-signal-stubs.php';
        W::reset();
    }

    private function ringWithTheUser(): void
    {
        W::$rings = [['users' => [self::UID, 8, 9], 'size' => 3, 'strength' => 12.5]];
    }

    public function testAFailedColdRingReadDoesNotAbortTheAnalysis(): void
    {
        W::$rapidVotes         = 21;  // a scoring signal that must still land
        W::$ringDetectionFails = true;
        $this->ringWithTheUser();     // would be found if the read worked

        $r = FraudDetector::analyzeFraud(self::UID);

        self::assertSame(30, $r['score'], 'rapid_voting still scores');
        self::assertContains('rapid_voting', $r['triggers']);
        self::assertNotContains('in_vote_ring', $r['triggers'], 'rings are unknown, not asserted');
        self::assertArrayNotHasKey('ring_size', $r['details']);
        self::assertArrayNotHasKey('ring_strength', $r['details']);
    }

    public function testTheSkipIsLogged(): void
    {
        W::$ringDetectionFails = true;

        FraudDetector::analyzeFraud(self::UID);

        $warnings = array_filter(
            W::$logs,
            static fn (array $l): bool => $l['level'] === 'warning'
                && str_contains($l['message'], 'ring observation skipped')
        );
        self::assertCount(1, $warnings);
    }

    public function testConfidenceMatchesARunWithNoRing(): void
    {
        W::$ringDetectionFails = true;
        $failed = FraudDetector::analyzeFraud(self::UID);

        W::reset(); // healthy read, user in no ring
        $clean = FraudDetector::analyzeFraud(self::UID);

        self::assertSame($clean['confidence'], $failed['confidence']);
        self::assertSame($clean['score'], $failed['score']);
    }

    /** Control: with a healthy read the cold fallback still finds the ring (0 points). */
    public function testAHealthyColdReadStillRecordsTheRing(): void
    {
        $this->ringWithTheUser();

        $r = FraudDetector::analyzeFraud(self::UID);

        self::assertContains('in_vote_ring', $r['triggers']);
        self::assertSame(3, $r['details']['ring_size']);
        self::assertSame(0, $r['score'], 'ring membership adds no points');
    }
}
