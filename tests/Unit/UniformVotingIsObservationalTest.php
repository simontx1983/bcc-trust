<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\FraudSignalWorld as W;
use BCC\Trust\Core\Security\FraudDetector;
use BCC\Trust\Core\Support\ReciprocityObservation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * `uniform_voting` is observed, not punished.
 *
 * The rule: >= BCC_TRUST_UNIFORM_VOTE_MIN (10) votes in 7 days, and more
 * than 90% positive OR less than 10% positive. It scored +20.
 *
 * ⚠ WHAT IT ESTABLISHES is that a member's ratings are CONSISTENT — which
 * is what an honest rater with consistent experience produces. The fixtures
 * below are the whole argument: a satisfied customer and a sock puppet are
 * BOTH 100% positive and the rule cannot tell them apart, because it has no
 * input that could.
 *
 * ⚠ It also penalised the consumer-protection case: a member who only flags
 * poor work lands under 10% positive.
 *
 * ⚠ The THRESHOLDS ARE DELIBERATELY UNCHANGED. Raising the sample floor or
 * widening the band would put a new arbitrary number where an old one was,
 * and would still not separate the two cases above.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class UniformVotingIsObservationalTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/fraud-signal-stubs.php';
        W::reset();
    }

    /** @return array<string, mixed> */
    private function rate(int $total, int $positive): array
    {
        W::reset();
        W::$voteTypeStats = (object) ['total' => $total, 'positive' => $positive];

        return FraudDetector::analyzeFraud(7);
    }

    // ══ the two populations the rule cannot separate ══════════════════

    public function testASatisfiedCustomerScoresNothing(): void
    {
        // 30 trades, every one good. The ordinary case for a working
        // tradesperson's customer.
        $r = $this->rate(30, 30);

        self::assertSame(0, $r['score'], 'was 20');
        self::assertContains('uniform_voting', $r['triggers'], 'the observation is retained');
        self::assertSame('minimal', $r['risk_level']);
    }

    public function testADeliberateSockPuppetAlsoScoresNothingAndThatIsThePoint(): void
    {
        // ⚠ Identical input shape to the satisfied customer. Stated
        // explicitly so nobody reads this suite as claiming the rule got
        // better at detection — it did not. It stopped punishing a
        // distinction it cannot make.
        $satisfied = $this->rate(30, 30);
        $puppet    = $this->rate(30, 30);

        self::assertSame($satisfied['score'], $puppet['score']);
        self::assertSame(0, $puppet['score']);
    }

    public function testADissatisfiedCustomerScoresNothing(): void
    {
        // 10 jobs, all poor. Someone who had a bad run.
        $r = $this->rate(10, 0);

        self::assertSame(0, $r['score'], 'was 20');
        self::assertContains('uniform_voting', $r['triggers']);
    }

    public function testTheConsumerProtectionCaseScoresNothing(): void
    {
        // ⚠ 12 of 13 flagged as poor = 7.7% positive. A member who mostly
        // reports bad work is the behaviour this product most wants, and it
        // used to cost them 20 points.
        $r = $this->rate(13, 1);

        self::assertSame(0, $r['score'], 'was 20');
        self::assertContains('uniform_voting', $r['triggers']);
    }

    public function testAFamilyBusinessRatingItsRegularSuppliersScoresNothing(): void
    {
        $r = $this->rate(15, 15);

        self::assertSame(0, $r['score']);
    }

    public function testATradeCrewRatingEachOthersWorkScoresNothing(): void
    {
        $r = $this->rate(12, 12);

        self::assertSame(0, $r['score']);
    }

    // ══ detection and its thresholds are UNCHANGED ════════════════════

    public function testTheObservationIsStillRecordedWithItsDetails(): void
    {
        $r = $this->rate(10, 10);

        self::assertContains('uniform_voting', $r['triggers']);
        self::assertTrue($r['details']['uniform_voting']);
    }

    public function testTheSampleFloorIsUnchanged(): void
    {
        // 9 votes is under BCC_TRUST_UNIFORM_VOTE_MIN, so nothing is even
        // observed. The floor was NOT raised as part of this change.
        $r = $this->rate(9, 9);

        self::assertNotContains('uniform_voting', $r['triggers']);
        self::assertArrayNotHasKey('uniform_voting', $r['details']);
    }

    public function testTheBandBoundariesAreUnchanged(): void
    {
        // Exactly 90% does NOT fire (the test is strictly greater than).
        self::assertNotContains('uniform_voting', $this->rate(10, 9)['triggers'], '90% is outside the band');
        self::assertNotContains('uniform_voting', $this->rate(20, 18)['triggers'], '90% at n=20');
        // Exactly 10% does NOT fire either (strictly less than).
        self::assertNotContains('uniform_voting', $this->rate(10, 1)['triggers'], '10% is outside the band');
        // Just inside, both ends.
        self::assertContains('uniform_voting', $this->rate(100, 91)['triggers'], '91% is inside');
        self::assertContains('uniform_voting', $this->rate(100, 9)['triggers'], '9% is inside');
    }

    public function testAMixedRaterIsNotObservedAtAll(): void
    {
        self::assertNotContains('uniform_voting', $this->rate(10, 5)['triggers'], '50/50');
        self::assertNotContains('uniform_voting', $this->rate(10, 8)['triggers'], '80%');
    }

    public function testAbsentVoteStatsAreHandled(): void
    {
        W::reset();
        W::$voteTypeStats = null;

        $r = FraudDetector::analyzeFraud(7);

        self::assertSame(0, $r['score']);
        self::assertNotContains('uniform_voting', $r['triggers']);
    }

    // ══ the signal payload is labelled ════════════════════════════════

    public function testTheSignalDeclaresZeroPointsAndNonPunitive(): void
    {
        $byType = [];
        foreach ($this->rate(10, 10)['signals'] as $s) {
            $byType[$s['type']] = $s;
        }

        self::assertArrayHasKey('uniform_voting', $byType);
        self::assertSame(0, $byType['uniform_voting']['points']);
        self::assertFalse($byType['uniform_voting']['punitive']);
    }

    public function testTheDescriptionNoLongerCallsItSuspicious(): void
    {
        $byType = [];
        foreach ($this->rate(10, 10)['signals'] as $s) {
            $byType[$s['type']] = $s;
        }
        $text = strtolower($byType['uniform_voting']['description']);

        // ⚠ The forbidden list is ACCUSATION words, matching the sibling
        // reciprocity test. A bare "fraud" would be wrong to forbid: the
        // description deliberately carries the DISCLAIMER "adds no fraud
        // points", which is the opposite of an accusation. My first version
        // of this case forbade bare "fraud" and failed on that disclaimer.
        foreach (['suspicious', 'manipulat', 'fraudulent', 'collusion', 'fraud ring'] as $accusation) {
            self::assertStringNotContainsString($accusation, $text, 'was "Suspiciously consistent vote pattern"');
        }
        self::assertStringContainsString('observation', $text);
        self::assertStringContainsString('adds no fraud points', $text, 'the disclaimer must stay');
    }

    public function testItIsOnTheCanonicalNonPunitiveList(): void
    {
        self::assertTrue(ReciprocityObservation::isNonPunitive('uniform_voting'));
        self::assertContains('uniform_voting', ReciprocityObservation::CONSISTENCY_TRIGGERS);
        self::assertContains('uniform_voting', ReciprocityObservation::all());
        // ⚠ and NOT misfiled as reciprocity, which it is not.
        self::assertNotContains('uniform_voting', ReciprocityObservation::TRIGGERS);
    }

    // ══ unrelated protections preserved ══════════════════════════════

    public function testEveryOtherSIGNALSTILLSCORES(): void
    {
        // The removal must be scoped to uniform_voting alone.
        W::reset();
        W::$rapidVotes = 21;
        self::assertSame(30, FraudDetector::analyzeFraud(7)['score'], 'rapid_voting');

        W::reset();
        W::$voteChanges = 6;
        self::assertSame(15, FraudDetector::analyzeFraud(7)['score'], 'vote_changing');

        W::reset();
        W::$lastIpBinary = inet_pton('8.8.8.8');
        W::$distinctUsersForIp = 6;
        self::assertSame(25, FraudDetector::analyzeFraud(7)['score'], 'multi_account');

        W::reset();
        W::$behavior['behavior_score'] = 71;
        self::assertSame(40, FraudDetector::analyzeFraud(7)['score'], 'critical_behavior');
    }

    public function testAConsistentRaterWhoAlsoTripsARealSignalStillScoresIt(): void
    {
        W::reset();
        W::$voteTypeStats = (object) ['total' => 30, 'positive' => 30];
        W::$rapidVotes    = 21;

        $r = FraudDetector::analyzeFraud(7);

        self::assertSame(30, $r['score'], 'uniform_voting contributes 0; rapid_voting is unchanged');
        self::assertContains('uniform_voting', $r['triggers']);
        self::assertContains('rapid_voting', $r['triggers']);
    }

    // ══ confidence must not move ══════════════════════════════════════

    public function testConfidenceIsUnchangedBecauseTheDetailsEntryIsRetained(): void
    {
        W::reset();
        $baseline = FraudDetector::analyzeFraud(7)['confidence'];

        $observed = $this->rate(10, 10)['confidence'];

        // details gains exactly one key, so confidence moves by exactly 1/15
        // — the same as it did when the signal still scored.
        self::assertEqualsWithDelta($baseline + (1 / 15), $observed, 0.0001);
    }

    // ══ anti-vacuity ═════════════════════════════════════════════════

    public function testTheDetectorActuallyFiredInTheseCases(): void
    {
        // Every "scores 0" case would pass if the detector never ran.
        $r = $this->rate(10, 10);

        self::assertContains('uniform_voting', $r['triggers']);
        self::assertNotSame([], $r['signals']);
    }
}
