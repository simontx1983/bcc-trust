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
 * Reciprocity inside analyzeFraud() records, and does not punish.
 *
 * The two contributions covered here are the ones that reach fraud_score
 * through the 70/30 blend in getEnhancedFraudScore():
 *
 *   A1  `in_vote_ring`  was +40 — GROUP membership, gated on ring size and
 *                       strength, and dependent on the ring cache.
 *   A2  `vote_ring`     was +25 — PAIRWISE, two members with
 *                       BCC_TRUST_RING_MIN_MUTUAL+ mutual pages. No group,
 *                       no strength gate, no cache dependency, which is why
 *                       it applied on every host and was the easier of the
 *                       two to miss.
 *
 * Measured on `main` before the change, with this same harness:
 *
 *   A2 alone      25   confidence 0.4000
 *   A1 alone      40   confidence 0.4667
 *   A1 + A2       65   confidence 0.5333     <- a genuine trio
 *   baseline       0   confidence 0.3333
 *
 * ⚠ A1 and A2 BOTH FIRE for a member of a real trio, so the figure that
 * mattered was 65, not 40.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReciprocityIsNotPunitiveTest extends TestCase
{
    private const UID = 7;

    /** Four mutual pages: one more than BCC_TRUST_RING_MIN_MUTUAL (3). */
    private const MUTUAL_PAGES = 4;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/fraud-signal-stubs.php';
        W::reset();
    }

    /** @return array<string, mixed> */
    private function analyse(): array
    {
        return FraudDetector::analyzeFraud(self::UID);
    }

    private function asRing(): void
    {
        W::$rings = [['users' => [7, 8, 9], 'size' => 3, 'strength' => 24.0]];
    }

    // ══ A2 — the pairwise one ═════════════════════════════════════════

    public function testPairwiseReciprocityAddsNoPoints(): void
    {
        W::$mutualVotePages = self::MUTUAL_PAGES;

        $r = $this->analyse();

        self::assertSame(0, $r['score'], 'was 25');
    }

    public function testPairwiseReciprocityStillRecordsItsTrigger(): void
    {
        W::$mutualVotePages = self::MUTUAL_PAGES;

        $r = $this->analyse();

        self::assertContains('vote_ring', $r['triggers']);
        self::assertTrue($r['details']['vote_ring']);
    }

    public function testPairwiseReciprocityLeavesTheMemberAtMinimalRisk(): void
    {
        W::$mutualVotePages = self::MUTUAL_PAGES;

        self::assertSame('minimal', $this->analyse()['risk_level']);
    }

    // ══ A1 — group membership ═════════════════════════════════════════

    public function testRingMembershipAddsNoPoints(): void
    {
        $this->asRing();

        self::assertSame(0, $this->analyse()['score'], 'was 40');
    }

    public function testRingMembershipStillRecordsItsTriggerAndDetails(): void
    {
        $this->asRing();

        $r = $this->analyse();

        self::assertContains('in_vote_ring', $r['triggers']);
        self::assertSame(3, $r['details']['ring_size']);
        self::assertSame(24.0, $r['details']['ring_strength']);
    }

    // ══ both together — the real trio ═════════════════════════════════

    public function testAGenuineTrioScoresNothingWhereItPreviouslyScoredSixtyFive(): void
    {
        W::$mutualVotePages = self::MUTUAL_PAGES;
        $this->asRing();

        $r = $this->analyse();

        self::assertSame(0, $r['score'], 'A1 40 + A2 25 = 65 on main');
        self::assertContains('vote_ring', $r['triggers']);
        self::assertContains('in_vote_ring', $r['triggers']);
        self::assertSame('minimal', $r['risk_level']);
    }

    // ══ confidence must not move ══════════════════════════════════════

    public function testConfidenceIsUnchangedBecauseTheDetailsAreRetained(): void
    {
        // confidence = min(1, count(details) / 15). The details entries are
        // what reciprocity contributes to it, so dropping them to "clean up"
        // after removing the points would have silently moved confidence for
        // every observed member. These are the values measured on `main`.
        W::reset();
        self::assertEqualsWithDelta(0.3333, $this->analyse()['confidence'], 0.0001, 'baseline: 5 details');

        W::reset();
        W::$mutualVotePages = self::MUTUAL_PAGES;
        self::assertEqualsWithDelta(0.4000, $this->analyse()['confidence'], 0.0001, 'A2 adds 1 detail');

        W::reset();
        $this->asRing();
        self::assertEqualsWithDelta(0.4667, $this->analyse()['confidence'], 0.0001, 'A1 adds 2 details');

        W::reset();
        W::$mutualVotePages = self::MUTUAL_PAGES;
        $this->asRing();
        self::assertEqualsWithDelta(0.5333, $this->analyse()['confidence'], 0.0001, 'both add 3 details');
    }

    // ══ the signal payload is labelled ════════════════════════════════

    public function testBothSignalsAreLabelledZeroPointAndNonPunitive(): void
    {
        W::$mutualVotePages = self::MUTUAL_PAGES;
        $this->asRing();

        $byType = [];
        foreach ($this->analyse()['signals'] as $signal) {
            $byType[$signal['type']] = $signal;
        }

        self::assertArrayHasKey('vote_ring', $byType);
        self::assertArrayHasKey('in_vote_ring', $byType);

        foreach (['vote_ring', 'in_vote_ring'] as $type) {
            self::assertSame(0, $byType[$type]['points'], $type . ' must declare zero points');
            self::assertFalse($byType[$type]['punitive'], $type . ' must declare itself non-punitive');
        }
    }

    public function testNeitherDescriptionAssertsManipulation(): void
    {
        W::$mutualVotePages = self::MUTUAL_PAGES;
        $this->asRing();

        foreach ($this->analyse()['signals'] as $signal) {
            if (!ReciprocityObservation::isNonPunitive($signal['type'])) {
                continue;
            }
            $text = strtolower($signal['description']);
            foreach (['fraud ring', 'manipulat', 'collusion', 'fraudulent'] as $accusation) {
                self::assertStringNotContainsString(
                    $accusation,
                    $text,
                    $signal['type'] . ' describes an observation, not a finding'
                );
            }
            self::assertStringContainsString('observation', $text);
        }
    }

    // ══ the independent signals are untouched alongside ═══════════════

    public function testAnIndependentSignalStillScoresWhenReciprocityIsPresent(): void
    {
        // The removal must be scoped to reciprocity. A member who is BOTH in
        // a mutual group AND tripping a real signal keeps the real signal.
        W::$mutualVotePages            = self::MUTUAL_PAGES;
        $this->asRing();
        W::$behavior['behavior_score'] = 71; // critical_behavior, +40

        $r = $this->analyse();

        self::assertSame(40, $r['score'], 'reciprocity contributes 0; the behavioural signal is unchanged');
        self::assertContains('critical_behavior', $r['triggers']);
        self::assertContains('in_vote_ring', $r['triggers']);
    }

    public function testMultiAccountAndSharedDeviceStillScoreOnTheirOwnTerms(): void
    {
        // Explicitly pinned: these remain fraud signals in their own right.
        // What changed is only that they are not the corroboration that makes
        // a reciprocity observation punishable — a different question.
        W::$mutualVotePages             = self::MUTUAL_PAGES;
        W::$lastIpBinary                = inet_pton('8.8.8.8');
        W::$distinctUsersForIp          = 6;
        W::$deviceData['shared_device'] = ['user_count' => 4];

        $r = $this->analyse();

        self::assertSame(45, $r['score'], 'multi_account 25 + shared_device 20, reciprocity 0');
        self::assertContains('multi_account', $r['triggers']);
        self::assertContains('shared_device_4_users', $r['triggers']);
    }

    // ══ anti-vacuity ══════════════════════════════════════════════════

    public function testTheDetectorsActuallyFiredInTheseCases(): void
    {
        // Every "scores 0" case above would also pass if neither detector had
        // run at all. The triggers are the proof that they did.
        W::$mutualVotePages = self::MUTUAL_PAGES;
        $this->asRing();

        $r = $this->analyse();

        self::assertNotEmpty($r['triggers']);
        self::assertCount(2, array_intersect(['vote_ring', 'in_vote_ring'], $r['triggers']));
    }

    public function testBelowTheMutualThresholdNothingIsRecordedAtAll(): void
    {
        // The threshold is still a real threshold — this is not "the detector
        // was switched off".
        W::$mutualVotePages = 3; // == BCC_TRUST_RING_MIN_MUTUAL, needs >

        $r = $this->analyse();

        self::assertNotContains('vote_ring', $r['triggers']);
        self::assertArrayNotHasKey('vote_ring', $r['details']);
    }

    // ══ the canonical list ════════════════════════════════════════════

    public function testTheObservationListNamesExactlyTheTwoBlendedTriggers(): void
    {
        self::assertSame(['vote_ring', 'in_vote_ring'], ReciprocityObservation::TRIGGERS);
    }

    public function testIndependentSignalsAreNotOnTheObservationList(): void
    {
        foreach ([
            'uniform_voting', 'multi_account', 'shared_device_4_users',
            'rapid_voting', 'vote_changing', 'critical_behavior',
            'very_low_trust', 'brand_new_account', 'unverified_email',
        ] as $trigger) {
            self::assertFalse(
                ReciprocityObservation::isNonPunitive($trigger),
                $trigger . ' is an independent fraud signal and must keep its points'
            );
        }
    }

    public function testAnEmptyTriggerSetIsNotReportedAsAllObservations(): void
    {
        // "nothing recorded" must not read as "cleared".
        self::assertFalse(ReciprocityObservation::allNonPunitive([]));
        self::assertTrue(ReciprocityObservation::allNonPunitive(['vote_ring', 'in_vote_ring']));
        self::assertFalse(ReciprocityObservation::allNonPunitive(['vote_ring', 'multi_account']));
    }

    // ══ the one operator surface that reads a trigger back ════════════

    public function testTheOnlyTriggerReadBackSurfaceConsultsTheObservationList(): void
    {
        // `fraud_analysis.triggers` is rendered in exactly one place, and it
        // is rendered under the heading "Fraud Analysis History", which is
        // why an unqualified `in_vote_ring` there reads as a finding.
        $surface = file_get_contents(self::surfacePath());

        self::assertIsString($surface);
        self::assertStringContainsString('ReciprocityObservation::isNonPunitive', $surface);
        self::assertStringContainsString('ReciprocityObservation::QUALIFIER', $surface);
    }

    /**
     * ⚠ This file is in phpstan.neon's `scanFiles`, NOT its `paths` — it is
     * scanned for symbols and never analysed, because analysing it surfaces
     * 34 pre-existing errors that are not this change's to carry (measured).
     *
     * That is precisely the blind spot in which a reference to
     * \BCC\Trust\Core\Log\Logger — a class that does not exist — once reached
     * the refusal path of a destructive admin action. So the class references
     * this change adds to it are checked here instead.
     */
    public function testEveryQualifiedClassReferenceInThatSurfaceResolves(): void
    {
        $source = file_get_contents(self::surfacePath());
        self::assertIsString($source);

        $names = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && $token[0] === T_NAME_FULLY_QUALIFIED) {
                $names[ltrim($token[1], '\\')] = ltrim($token[1], '\\');
            }
        }
        ksort($names);

        // An EXACT list, not a floor. A loose count is how a walk that finds
        // nothing reads as a clean file, and it also means a fourth reference
        // could be added to an un-analysed file without anyone deciding to.
        self::assertSame(
            [
                'BCC\Trust\Core\Plugin',
                'BCC\Trust\Core\Support\Formatting',
                'BCC\Trust\Core\Support\ReciprocityObservation',
            ],
            array_values($names),
            'a qualified class reference was added to a file PHPStan does not analyse'
        );

        // ⚠ Resolved through the COMMITTED classmap, not class_exists().
        //
        // class_exists() would be satisfied by the Plugin DOUBLE this suite
        // registers, so it cannot tell a real production class from a stub.
        // The classmap is also the thing that actually breaks CI: a new class
        // without a regenerated `composer dump-autoload --no-dev` fails in
        // the pre-install step, long before any test runs.
        $classmap = require __DIR__ . '/../../vendor/composer/autoload_classmap.php';
        self::assertIsArray($classmap);
        self::assertGreaterThan(500, count($classmap), 'the classmap itself did not load');

        foreach ($names as $name) {
            self::assertArrayHasKey(
                $name,
                $classmap,
                $name . ' is not in the committed classmap — run composer dump-autoload --no-dev'
            );
            self::assertFileExists((string) $classmap[$name]);
        }
    }

    private static function surfacePath(): string
    {
        return __DIR__ . '/../../includes/admin/moderation-user.php';
    }
}
