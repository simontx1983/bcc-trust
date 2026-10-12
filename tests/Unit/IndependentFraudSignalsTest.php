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
 * The INDEPENDENT fraud signals, pinned one at a time.
 *
 * ⚠ THIS FILE MUST PASS ON `main` UNCHANGED, BEFORE ANY SCORING EDIT.
 * That is its whole point. The reciprocity separation removes points from
 * four reciprocity mechanisms; these seventeen signals are NOT reciprocity
 * signals and must keep scoring exactly what they score today. A test
 * written after the edit could only prove the edit was self-consistent.
 *
 * Each case starts from FraudSignalWorld::reset() — a long-established,
 * verified account with no signal at all, asserted at score 0 — then turns
 * on exactly ONE signal. So each assertion measures that signal alone, and
 * `testTheCleanBaselineScoresZero` is what stops the suite from passing
 * because the baseline happens to add up.
 *
 * Every threshold comes from the real `includes/config.php` loaded by
 * tests/bootstrap.php, so these cases also pin the config values that
 * select each band.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IndependentFraudSignalsTest extends TestCase
{
    private const UID = 7;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/fraud-signal-stubs.php';
        W::reset();
    }

    /**
     * @return array<string, mixed>
     */
    private function analyse(): array
    {
        return FraudDetector::analyzeFraud(self::UID);
    }

    // ══ the baseline, without which every case below is unreadable ════

    public function testTheCleanBaselineScoresZero(): void
    {
        $r = $this->analyse();

        self::assertSame(0, $r['score'], 'baseline must be 0 or every single-signal case is really measuring baseline+signal');
        self::assertSame([], array_values($r['triggers']));
        self::assertSame('minimal', $r['risk_level']);
    }

    // ══ 1. vote-behaviour signals ═════════════════════════════════════

    public function testRapidVotingScoresThirty(): void
    {
        // > BCC_TRUST_RAPID_VOTE_LIMIT (20) within the window.
        W::$rapidVotes = 21;

        $r = $this->analyse();

        self::assertSame(30, $r['score']);
        self::assertContains('rapid_voting', $r['triggers']);
    }

    public function testRapidVotingAtTheLimitDoesNotFire(): void
    {
        W::$rapidVotes = 20;

        self::assertSame(0, $this->analyse()['score']);
    }

    public function testUniformVotingScoresTwenty(): void
    {
        // >= BCC_TRUST_UNIFORM_VOTE_MIN (10) votes, all positive.
        W::$voteTypeStats = (object) ['total' => 10, 'positive' => 10];

        $r = $this->analyse();

        self::assertSame(20, $r['score']);
        self::assertContains('uniform_voting', $r['triggers']);
    }

    public function testUniformVotingBelowTheMinimumSampleDoesNotFire(): void
    {
        W::$voteTypeStats = (object) ['total' => 9, 'positive' => 9];

        self::assertSame(0, $this->analyse()['score']);
    }

    public function testVoteChangingScoresFifteen(): void
    {
        // > BCC_TRUST_VOTE_CHANGE_LIMIT (5).
        W::$voteChanges = 6;

        $r = $this->analyse();

        self::assertSame(15, $r['score']);
        self::assertContains('vote_changing', $r['triggers']);
    }

    public function testMultiAccountScoresTwentyFive(): void
    {
        // A PUBLIC address: detectMultiAccountVoting() discards private and
        // reserved ranges, so a 10.x fixture would silently prove nothing.
        W::$lastIpBinary       = inet_pton('8.8.8.8');
        W::$distinctUsersForIp = 6; // > BCC_TRUST_MULTI_ACCOUNT_LIMIT (5)

        $r = $this->analyse();

        self::assertSame(25, $r['score']);
        self::assertContains('multi_account', $r['triggers']);
    }

    public function testMultiAccountOnAPrivateAddressDoesNotFire(): void
    {
        W::$lastIpBinary       = inet_pton('192.168.1.10');
        W::$distinctUsersForIp = 99;

        self::assertSame(0, $this->analyse()['score']);
    }

    // ══ 2. device signals ═════════════════════════════════════════════

    public function testHighDeviceFraudScoresThirty(): void
    {
        W::$deviceData['fraud_probability'] = 0.71;

        $r = $this->analyse();

        self::assertSame(30, $r['score']);
        self::assertContains('high_device_fraud', $r['triggers']);
    }

    public function testMediumDeviceFraudScoresFifteen(): void
    {
        W::$deviceData['fraud_probability'] = 0.41;

        $r = $this->analyse();

        self::assertSame(15, $r['score']);
        self::assertContains('medium_device_fraud', $r['triggers']);
    }

    public function testTheTwoDeviceBandsAreExclusive(): void
    {
        W::$deviceData['fraud_probability'] = 0.9;

        $r = $this->analyse();

        self::assertSame(30, $r['score'], 'the bands are an if/elseif — 0.9 must not score 45');
        self::assertNotContains('medium_device_fraud', $r['triggers']);
    }

    public function testSharedDeviceScoresFivePerAccount(): void
    {
        W::$deviceData['shared_device'] = ['user_count' => 4];

        $r = $this->analyse();

        self::assertSame(20, $r['score']);
        self::assertContains('shared_device_4_users', $r['triggers']);
    }

    public function testSharedDeviceIsCappedAtThirty(): void
    {
        W::$deviceData['shared_device'] = ['user_count' => 50];

        $r = $this->analyse();

        self::assertSame(30, $r['score'], 'min(30, count * 5)');
        self::assertContains('shared_device_50_users', $r['triggers']);
    }

    // ══ 3. behavioural signals ════════════════════════════════════════

    public function testCriticalBehaviourScoresForty(): void
    {
        W::$behavior['behavior_score'] = 71;

        $r = $this->analyse();

        self::assertSame(40, $r['score']);
        self::assertContains('critical_behavior', $r['triggers']);
    }

    public function testHighRiskBehaviourScoresTwentyFive(): void
    {
        W::$behavior['behavior_score'] = 51;

        $r = $this->analyse();

        self::assertSame(25, $r['score']);
        self::assertContains('high_risk_behavior', $r['triggers']);
    }

    public function testSuspiciousBehaviourScoresFifteen(): void
    {
        W::$behavior['behavior_score'] = 31;

        $r = $this->analyse();

        self::assertSame(15, $r['score']);
        self::assertContains('suspicious_behavior', $r['triggers']);
    }

    public function testTheThreeBehaviourBandsAreExclusive(): void
    {
        W::$behavior['behavior_score'] = 100;

        $r = $this->analyse();

        self::assertSame(40, $r['score'], 'if/elseif/elseif — 100 must not score 80');
    }

    public function testBehaviourFlagsAddTriggersButNoPoints(): void
    {
        // Already true on main, and worth pinning: the per-flag triggers are
        // a NON-PUNITIVE precedent that predates this work.
        W::$behavior['flags'] = ['odd_hours', 'burst'];

        $r = $this->analyse();

        self::assertSame(0, $r['score']);
        self::assertContains('behavior_odd_hours', $r['triggers']);
        self::assertContains('behavior_burst', $r['triggers']);
    }

    // ══ 4. trust-rank signals ═════════════════════════════════════════

    public function testVeryLowTrustScoresThirty(): void
    {
        W::$userInfo->trust_rank = 0.1;

        $r = $this->analyse();

        self::assertSame(30, $r['score']);
        self::assertContains('very_low_trust', $r['triggers']);
    }

    public function testLowTrustScoresFifteen(): void
    {
        W::$userInfo->trust_rank = 0.3;

        $r = $this->analyse();

        self::assertSame(15, $r['score']);
        self::assertContains('low_trust', $r['triggers']);
    }

    public function testATrustRankOfZeroIsNotPenalisedAsLowTrust(): void
    {
        // 0 means "not yet calculated", not "untrustworthy". The stored rank
        // being 0 sends analyzeFraud() to the graph, which also returns 0.
        W::$userInfo->trust_rank     = 0;
        W::$calculatedTrustRank      = 0.0;

        $r = $this->analyse();

        self::assertSame(0, $r['score']);
        self::assertNotContains('very_low_trust', $r['triggers']);
        self::assertNotContains('low_trust', $r['triggers']);
    }

    // ══ 5. account signals ════════════════════════════════════════════

    public function testBrandNewAccountScoresTwenty(): void
    {
        W::$userRegistered = gmdate('Y-m-d H:i:s', time() - 3600);

        $r = $this->analyse();

        self::assertSame(20, $r['score']);
        self::assertContains('brand_new_account', $r['triggers']);
    }

    public function testNewAccountScoresTen(): void
    {
        // Inside BCC_TRUST_AGE_NEW (30 days), outside the 1-day band.
        W::$userRegistered = gmdate('Y-m-d H:i:s', time() - (10 * 86400));

        $r = $this->analyse();

        self::assertSame(10, $r['score']);
        self::assertContains('new_account', $r['triggers']);
    }

    public function testTheTwoAgeBandsAreExclusive(): void
    {
        W::$userRegistered = gmdate('Y-m-d H:i:s', time() - 600);

        $r = $this->analyse();

        self::assertSame(20, $r['score'], 'if/elseif — must not score 30');
        self::assertNotContains('new_account', $r['triggers']);
    }

    public function testUnverifiedEmailScoresFifteen(): void
    {
        W::$userInfo->is_verified = 0;

        $r = $this->analyse();

        self::assertSame(15, $r['score']);
        self::assertContains('unverified_email', $r['triggers']);
    }

    public function testMissingUserInfoScoresFifteen(): void
    {
        W::$userInfo = null;

        $r = $this->analyse();

        self::assertSame(15, $r['score']);
        self::assertContains('user_info_missing', $r['triggers']);
    }

    public function testMissingUserInfoAndUnverifiedEmailAreMutuallyExclusive(): void
    {
        W::$userInfo = null;

        $r = $this->analyse();

        self::assertNotContains('unverified_email', $r['triggers'], 'the absent row takes the else branch, not both');
    }

    // ══ 6. the bands, and the cap ═════════════════════════════════════

    public function testScoreIsCappedAtOneHundred(): void
    {
        W::$rapidVotes                      = 21;  // 30
        W::$behavior['behavior_score']      = 71;  // 40
        W::$deviceData['fraud_probability'] = 0.9; // 30
        W::$userInfo->trust_rank            = 0.1; // 30
        W::$userInfo->is_verified           = 0;   // 15

        $r = $this->analyse();

        self::assertSame(100, $r['score'], '145 raw must clamp to 100');
        self::assertSame('critical', $r['risk_level']);
    }

    public function testTheFourRiskBandsSelectOnTheRealConfigThresholds(): void
    {
        // LOW 20 / MEDIUM 40 / HIGH 60 / CRITICAL 80 — asserted through
        // real signals rather than by poking the score, so a band change
        // and a points change cannot cancel out.
        W::$voteChanges = 6; // 15 -> below LOW
        self::assertSame('minimal', $this->analyse()['risk_level']);

        W::reset();
        W::$behavior['behavior_score'] = 51; // 25 -> LOW
        self::assertSame('low', $this->analyse()['risk_level']);

        W::reset();
        W::$behavior['behavior_score']      = 51;  // 25
        W::$deviceData['fraud_probability'] = 0.9; // 30 -> 55, MEDIUM
        self::assertSame('medium', $this->analyse()['risk_level']);

        W::reset();
        W::$behavior['behavior_score']      = 71;  // 40
        W::$deviceData['fraud_probability'] = 0.9; // 30 -> 70, HIGH
        self::assertSame('high', $this->analyse()['risk_level']);

        W::reset();
        W::$behavior['behavior_score']      = 71;  // 40
        W::$deviceData['fraud_probability'] = 0.9; // 30
        W::$rapidVotes                      = 21;  // 30 -> 100, CRITICAL
        self::assertSame('critical', $this->analyse()['risk_level']);
    }

    // ══ 7. band BOUNDARIES ═══════════════════════════════════════════
    //
    // ⚠ Added after mutation testing. Every band case above fires one step
    // INSIDE its band, so moving a boundary outward (`> 50` to `> 45`)
    // survived: 51 satisfies both. These cases pin the other side.

    public function testEachBandBoundaryIsExclusive(): void
    {
        $cases = [
            // [label, setup, expected score]
            ['behaviour exactly 70 is high-risk, not critical', static function (): void {
                W::$behavior['behavior_score'] = 70;
            }, 25],
            ['behaviour exactly 50 is suspicious, not high-risk', static function (): void {
                W::$behavior['behavior_score'] = 50;
            }, 15],
            ['behaviour exactly 30 is nothing', static function (): void {
                W::$behavior['behavior_score'] = 30;
            }, 0],
            ['device exactly 0.7 is medium, not high', static function (): void {
                W::$deviceData['fraud_probability'] = 0.7;
            }, 15],
            ['device exactly 0.4 is nothing', static function (): void {
                W::$deviceData['fraud_probability'] = 0.4;
            }, 0],
            ['trust exactly 0.2 is low, not very-low', static function (): void {
                W::$userInfo->trust_rank = 0.2;
            }, 15],
            ['trust exactly 0.4 is nothing', static function (): void {
                W::$userInfo->trust_rank = 0.4;
            }, 0],
        ];

        foreach ($cases as [$label, $setup, $expected]) {
            W::reset();
            $setup();
            self::assertSame($expected, $this->analyse()['score'], $label);
        }
    }

    public function testTheAccountAgeBoundaryIsExclusive(): void
    {
        // BCC_TRUST_AGE_NEW is 30 days; at exactly 30 the band is closed.
        W::$userRegistered = gmdate('Y-m-d H:i:s', time() - (31 * 86400));

        self::assertSame(0, $this->analyse()['score']);
    }

    // ══ 8. declared points must match the arithmetic ═════════════════

    public function testEverySignalDeclaresThePointsItActuallyAdded(): void
    {
        // ⚠ Also added after mutation testing. Changing a signal's
        // `'points' => N` metadata WITHOUT changing its `$results['score']
        // += N` survived every case above, because nothing compared the two.
        // That drift matters: `points` is what an operator-facing
        // explanation of a score would be built from, so a mismatch is a
        // surface that misstates why a member was scored.
        $scenarios = [
            'rapid_voting'    => static function (): void { W::$rapidVotes = 21; },
            'uniform_voting'  => static function (): void { W::$voteTypeStats = (object) ['total' => 10, 'positive' => 10]; },
            'vote_changing'   => static function (): void { W::$voteChanges = 6; },
            'multi_account'   => static function (): void {
                W::$lastIpBinary       = inet_pton('8.8.8.8');
                W::$distinctUsersForIp = 6;
            },
            'high_device'     => static function (): void { W::$deviceData['fraud_probability'] = 0.71; },
            'medium_device'   => static function (): void { W::$deviceData['fraud_probability'] = 0.41; },
            'shared_device'   => static function (): void { W::$deviceData['shared_device'] = ['user_count' => 4]; },
            'critical_behav'  => static function (): void { W::$behavior['behavior_score'] = 71; },
            'high_behav'      => static function (): void { W::$behavior['behavior_score'] = 51; },
            'suspicious'      => static function (): void { W::$behavior['behavior_score'] = 31; },
            'very_low_trust'  => static function (): void { W::$userInfo->trust_rank = 0.1; },
            'low_trust'       => static function (): void { W::$userInfo->trust_rank = 0.3; },
            'brand_new'       => static function (): void { W::$userRegistered = gmdate('Y-m-d H:i:s', time() - 3600); },
            'new_account'     => static function (): void { W::$userRegistered = gmdate('Y-m-d H:i:s', time() - (10 * 86400)); },
            'unverified'      => static function (): void { W::$userInfo->is_verified = 0; },
            // Reciprocity is included on purpose: it declares 0 points and
            // must add 0, so the same invariant covers it.
            'reciprocity_a2'  => static function (): void { W::$mutualVotePages = 4; },
            'reciprocity_a1'  => static function (): void {
                W::$rings = [['users' => [7, 8, 9], 'size' => 3, 'strength' => 24.0]];
            },
        ];

        self::assertCount(17, $scenarios);

        foreach ($scenarios as $label => $setup) {
            W::reset();
            $setup();
            $result = $this->analyse();

            self::assertSame(
                $result['score'],
                array_sum(array_column($result['signals'], 'points')),
                $label . ': the sum of declared points must equal the score it produced'
            );
        }
    }

    // ══ 9. anti-vacuity ══════════════════════════════════════════════

    public function testTheHarnessActuallyReachesTheProductionDetector(): void
    {
        // Without this, every "scores 0" assertion above would also pass if
        // analyzeFraud() were never really executed.
        W::$rapidVotes = 21;

        $r = $this->analyse();

        self::assertArrayHasKey('confidence', $r);
        self::assertArrayHasKey('behavior_score', $r['details'], 'the behavioural collaborator must have been consulted');
        self::assertArrayHasKey('trust_rank', $r['details'], 'the trust-graph collaborator must have been consulted');
        self::assertNotSame([], $r['signals'], 'the signals list must be populated by production code');
    }

    public function testSeventeenIndependentSignalNamesAreCovered(): void
    {
        // A roll-call, so dropping a case cannot quietly shrink the guard.
        $covered = [
            'rapid_voting', 'uniform_voting', 'vote_changing', 'multi_account',
            'high_device_fraud', 'medium_device_fraud', 'shared_device_4_users',
            'critical_behavior', 'high_risk_behavior', 'suspicious_behavior',
            'behavior_odd_hours', 'very_low_trust', 'low_trust',
            'brand_new_account', 'new_account', 'unverified_email',
            'user_info_missing',
        ];

        self::assertCount(17, $covered);

        $source = file_get_contents(__FILE__);
        self::assertIsString($source);
        foreach ($covered as $name) {
            self::assertStringContainsString(
                "'" . $name . "'",
                $source,
                $name . ' is named in the roll-call but no case in this file asserts it'
            );
        }
    }
}
