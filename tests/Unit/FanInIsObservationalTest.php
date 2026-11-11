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
 * Uncorroborated fan-in is OBSERVED, not punished.
 *
 * `detectFanInCoordination()` had no test coverage at all before this.
 *
 * The rule: 5+ voters on one page within a day, all registered inside a
 * 7-day window, who between them have voted on fewer than 3 other pages.
 *
 * It used to do two punitive things, both removed here:
 *   1. +15 fraud_score PER VOTER, with peak bumped atomically and the
 *      HIGH-crossing fan-out.
 *   2. ⚠ A fraud_analysis row at min(100, 40 + 5n) — 65 at the rule's own
 *      threshold, i.e. HIGH — written against THE PAGE OWNER, the person
 *      who was voted on.
 *
 * ⚠ The detection, its thresholds, the audit row and the dedup transient
 * are all UNCHANGED. Only the consequence is removed.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FanInIsObservationalTest extends TestCase
{
    private const PAGE  = 500;
    private const OWNER = 99;
    private const VOTER = 1;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/fraud-signal-stubs.php';
        W::reset();
    }

    /**
     * @param list<int> $ids
     * @param list<string> $registered
     */
    private function voters(array $ids, array $registered): void
    {
        $rows = [];
        foreach ($ids as $i => $id) {
            $rows[] = (object) [
                'voter_user_id' => $id,
                'registered'    => $registered[$i],
            ];
        }
        W::$recentVoters = $rows;
    }

    /**
     * ⭐ LEGITIMATE CREW ONBOARDING.
     *
     * A sparky tells four colleagues about BCC. They sign up across the
     * same week, all back the person who introduced them, and have not got
     * round to anything else yet — so 2 other pages between the five of
     * them, under the rule's "< 3" diversity floor.
     *
     * Registration spread 6 days, inside the 7-day window. FIRES.
     */
    private function legitimateCrew(): void
    {
        $this->voters(
            [1, 2, 3, 4, 5],
            [
                '2026-10-01 09:00:00',
                '2026-10-02 10:00:00',
                '2026-10-03 11:00:00',
                '2026-10-05 12:00:00',
                '2026-10-07 13:00:00',
            ]
        );
        W::$otherPageVotes = 2;
    }

    /**
     * MANIPULATION fixture — same structural shape, more accounts, all
     * registered within hours and with no other activity at all.
     *
     * ⚠ Structurally indistinguishable from the crew above except in
     * degree. That is the point: the rule has no input that separates them,
     * which is why neither may be punished on this evidence alone.
     */
    private function manipulationRing(): void
    {
        $this->voters(
            [11, 12, 13, 14, 15, 16, 17, 18],
            [
                '2026-10-01 09:00:00', '2026-10-01 09:05:00',
                '2026-10-01 09:10:00', '2026-10-01 09:15:00',
                '2026-10-01 09:20:00', '2026-10-01 09:25:00',
                '2026-10-01 09:30:00', '2026-10-01 09:35:00',
            ]
        );
        W::$otherPageVotes = 0;
    }

    // ══ 1. NEITHER VOTERS NOR PAGE OWNER GAIN ANYTHING ADVERSE ════════

    public function testLegitimateCrewOnboardingGivesTheVotersNoFraudPoints(): void
    {
        $this->legitimateCrew();

        self::assertTrue(FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER));

        self::assertSame([], W::$increments, 'was +15 per voter, 75 across five newcomers');
        self::assertSame(0, W::incrementedTotal());
    }

    public function testManipulationFixtureAlsoGivesNoFraudPoints(): void
    {
        // Stated plainly: the observation alone carries no penalty for
        // EITHER fixture. Acting on the manipulation case requires
        // corroboration this rule does not have.
        $this->manipulationRing();

        self::assertTrue(FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER));
        self::assertSame([], W::$increments);
    }

    public function testThePageOwnerGetsNoFraudAnalysisRowAtAll(): void
    {
        // ⚠ The old behaviour wrote min(100, 40 + 5n) against the PAGE
        // OWNER — 65, HIGH, at five voters. The person voted on received the
        // allegation. Nothing is written about them now.
        $this->legitimateCrew();

        FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER);

        foreach (W::$patterns as $p) {
            self::assertNotSame(
                self::OWNER,
                $p['data']['subject_user_id'] ?? null,
                'no pattern may name the page owner as its subject'
            );
        }
        self::assertSame([], W::$increments, 'and no score for the owner either');
    }

    public function testNoRiskLevelIsAssertedAboutAnyone(): void
    {
        $this->manipulationRing();

        FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER);

        // 8 voters would have been min(100, 40+40) = 80 => CRITICAL.
        foreach (W::$patterns as $p) {
            self::assertArrayNotHasKey('risk_level', $p['data']);
            self::assertArrayNotHasKey('fraud_score', $p['data']);
        }
    }

    // ══ 2. THE OBSERVATION IS PRESERVED ═══════════════════════════════

    public function testTheObservationIsStoredAsASystemLevelPattern(): void
    {
        $this->legitimateCrew();

        FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER);

        $rows = array_values(array_filter(
            W::$patterns,
            static fn (array $p): bool => $p['type'] === 'fan_in_coordination'
        ));

        self::assertCount(1, $rows);
        self::assertSame(self::PAGE, $rows[0]['data']['page_id']);
        self::assertSame(5, $rows[0]['data']['voter_count']);
        self::assertSame(2, $rows[0]['data']['other_pages']);
        self::assertSame([1, 2, 3, 4, 5], $rows[0]['data']['voter_ids']);
    }

    public function testTheStoredObservationDeclaresItselfNonPunitiveAndUncorroborated(): void
    {
        $this->legitimateCrew();

        FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER);

        $row = W::$patterns[0]['data'];
        self::assertFalse($row['punitive'], 'the row must not read as a finding');
        self::assertFalse($row['corroborated'], 'account proximity alone is not corroboration');
        self::assertArrayHasKey('evidence_note', $row);
    }

    public function testTheAuditRowIsUnchanged(): void
    {
        // The audit trail is the good part of this mechanism and is kept
        // verbatim: it is what tells an operator which page and which
        // cohort, and it is better evidence than the ring increments left.
        $this->legitimateCrew();

        FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER);

        self::assertCount(1, W::$auditLog);
        self::assertSame('fan_in_coordination_detected', W::$auditLog[0]['action']);
        self::assertSame(self::PAGE, W::$auditLog[0]['target_id']);
        self::assertSame(5, W::$auditLog[0]['meta']['voter_count']);
        self::assertSame([1, 2, 3, 4, 5], W::$auditLog[0]['meta']['voter_ids']);
    }

    public function testASoftFlagIsLogged(): void
    {
        $this->legitimateCrew();

        FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER);

        $messages = array_column(W::$logs, 'message');
        self::assertNotEmpty(array_filter(
            $messages,
            static fn (string $m): bool => str_contains($m, 'fan_in_coordination detected (soft-flag)')
        ));
    }

    // ══ 3. DETECTION AND ITS THRESHOLDS ARE UNCHANGED ═════════════════

    public function testFourVotersIsBelowTheThreshold(): void
    {
        $this->voters([1, 2, 3, 4], array_fill(0, 4, '2026-10-01 09:00:00'));
        W::$otherPageVotes = 0;

        self::assertFalse(FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER));
        self::assertSame([], W::$patterns, 'nothing observed below the threshold');
    }

    public function testARegistrationSpreadOverSevenDaysDoesNotFire(): void
    {
        $this->voters(
            [1, 2, 3, 4, 5],
            [
                '2026-09-01 09:00:00', '2026-09-10 09:00:00',
                '2026-09-20 09:00:00', '2026-10-01 09:00:00',
                '2026-10-07 09:00:00',
            ]
        );
        W::$otherPageVotes = 0;

        self::assertFalse(FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER));
    }

    public function testThreeOrMoreOtherPagesDefeatsTheRule(): void
    {
        // ⚠ Worth pinning because it shows how weak the rule is: a cohort
        // that has voted on three other pages is exempt regardless of how
        // coordinated it is. A motivated ring clears this trivially; an
        // honest first-week cohort does not.
        $this->manipulationRing();
        W::$otherPageVotes = 3;

        self::assertFalse(FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER));
        self::assertSame([], W::$patterns);
    }

    public function testThePerPageDedupTransientStillSuppressesARepeat(): void
    {
        $this->legitimateCrew();

        self::assertTrue(FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER));
        self::assertCount(1, W::$patterns);

        // Second call within the hour: the transient short-circuits it.
        self::assertFalse(FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER));
        self::assertCount(1, W::$patterns, 'no second observation for the same page');
    }

    public function testVotersWithMissingRegistrationDatesAreNotCounted(): void
    {
        // Five voters but only four usable registration dates → below the
        // threshold on the date count, as before.
        $this->voters([1, 2, 3, 4, 5], ['2026-10-01 09:00:00', '2026-10-01 09:00:00', '2026-10-01 09:00:00', '2026-10-01 09:00:00', '']);
        W::$otherPageVotes = 0;

        self::assertFalse(FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER));
    }

    // ══ 4. UNRELATED SIGNALS PRESERVED ════════════════════════════════

    public function testAnalyzeFraudIsCompletelyUnaffected(): void
    {
        // detectFanInCoordination is a separate entry point. The main
        // analysis pass and all 17 independent signals are untouched by
        // this change — pinned here so the two cannot be conflated.
        W::reset();
        self::assertSame(0, FraudDetector::analyzeFraud(7)['score'], 'clean baseline');

        W::reset();
        W::$rapidVotes = 21;
        $r = FraudDetector::analyzeFraud(7);
        self::assertSame(30, $r['score'], 'rapid_voting still scores 30');
        self::assertContains('rapid_voting', $r['triggers']);

        W::reset();
        W::$lastIpBinary       = inet_pton('8.8.8.8');
        W::$distinctUsersForIp = 6;
        self::assertSame(25, FraudDetector::analyzeFraud(7)['score'], 'multi_account still scores 25');
    }

    public function testAFanInObservationDoesNotLeakIntoTheAnalysisPass(): void
    {
        $this->legitimateCrew();
        FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER);

        // The crew members are not scored, so an analysis of one of them
        // still comes back clean.
        $r = FraudDetector::analyzeFraud(1);

        self::assertSame(0, $r['score']);
        self::assertNotContains('fan_in_coordination', $r['triggers']);
        self::assertSame('minimal', $r['risk_level']);
    }

    // ══ 5. anti-vacuity ══════════════════════════════════════════════

    public function testTheHarnessCanObserveAnIncrementWhenOneHappens(): void
    {
        // Without this, every "no increment" assertion would also pass if
        // the recording double were not wired up.
        (new \BCC\Trust\Core\Repositories\UserInfoRepository())
            ->incrementFraudScore(1, 15, 'control');

        self::assertSame(15, W::incrementedTotal());
    }

    public function testBothFixturesActuallyFireTheDetector(): void
    {
        // Every "no penalty" case above would pass trivially if the detector
        // never fired at all.
        W::reset();
        $this->legitimateCrew();
        self::assertTrue(FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER));
        self::assertNotEmpty(W::$auditLog, 'the crew fixture must reach the write block');

        W::reset();
        $this->manipulationRing();
        self::assertTrue(FraudDetector::detectFanInCoordination(self::PAGE, self::VOTER));
        self::assertNotEmpty(W::$auditLog, 'the manipulation fixture must too');
    }
}
