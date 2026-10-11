<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\RingsRenderWorld;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * What the Vote Rings tab renders once a snapshot actually exists.
 *
 * ── ⚠⚠ WHY THIS SUITE DID NOT EXIST BEFORE, AND HAD TO NOW ─────────────
 * Nothing persisted the snapshot, so the POPULATED branch of this tab had
 * never rendered — not in a test, not in a browser, not in production. It was
 * written against a shape neither detector emits: an enriched member ROW with
 * display_name / fraud_score / risk_level / mutual_vote_count / is_suspended,
 * where both detectors emit a flat list of integer user ids.
 *
 * Measured against a stored snapshot, that produced TWENTY-ONE
 * "Trying to access array offset on value of type int" diagnostics across
 * three rings, and the `?? 0` / `?? 'unknown'` defaults would have printed
 * "Fraud Score 0, Risk Unknown" for every member of a detected ring — a
 * fabricated claim about a person, on the screen whose buttons suspend them.
 *
 * ── ⚠⚠ AND THE SAFETY HALF ──────────────────────────────────────────────
 * The member list also feeds the hidden `user_ids` field of TWO DESTRUCTIVE
 * forms: invalidate-ring-votes and suspend-all. With the wrong key it was
 * EMPTY, under a confirm dialog reading "Suspend ALL 0 members". So the shape
 * of this payload is a safety property, not a display preference, and that is
 * what the cases below assert first.
 *
 * ⚠ Separate processes with PreserveGlobalState(false) are required; see the
 * stub file for why a class_exists() guard cannot substitute.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RingsTabRenderTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../Stubs/rings-tab-render-stubs.php';
        require_once __DIR__ . '/../../includes/admin/tabs/tab-rings.php';
    }

    protected function setUp(): void
    {
        RingsRenderWorld::reset();
    }

    /** try/finally: a throwing render must not leak its buffer into the next test. */
    private function render(): string
    {
        ob_start();
        try {
            bcc_trust_render_rings_tab();
        } finally {
            $html = (string) ob_get_clean();
        }
        return $html;
    }

    /**
     * Every ring fingerprint the destructive forms would post.
     *
     * ⚠ There is deliberately no destructiveTargets() any more. The forms
     * used to post the member ids and this helper read them back; they now
     * post only a fingerprint, and the handler resolves membership from the
     * snapshot. A test that still looked for submitted ids would be
     * asserting the shape this change removed.
     *
     * @return list<string>
     */
    private function destructiveFingerprints(string $html): array
    {
        preg_match_all('/name="ring_fingerprint"\s+value="([^"]*)"/', $html, $m);
        return $m[1];
    }

    /** The fingerprint the server will compute for a given member list. */
    private function fingerprintOf(array $users): string
    {
        $ids = array_map('intval', $users);
        sort($ids);
        return md5(implode(',', $ids));
    }

    // ── 0. ⚠⚠ THE DESTRUCTIVE FORMS CARRY REAL TARGETS
    // ═══════════════════════════════════════════════════════════════════

    public function testEachRingArmsItsFormsWithItsOwnFingerprint(): void
    {
        RingsRenderWorld::populated();

        $fps = $this->destructiveFingerprints($this->render());

        // Two forms per ring, two rings, each carrying ITS OWN ring's print.
        self::assertCount(4, $fps, 'two destructive forms per ring');
        self::assertSame([
            $this->fingerprintOf([11, 12, 13]),
            $this->fingerprintOf([11, 12, 13]),
            $this->fingerprintOf([21, 22, 23, 24]),
            $this->fingerprintOf([21, 22, 23, 24]),
        ], $fps);
    }

    public function testMembershipIsNeverSUBMITTEDByTheForms(): void
    {
        // ⚠⚠ THE SAFETY PROPERTY, stated as an absence and the reason the
        // fingerprint exists. The old hidden field posted "11,12,13", the
        // handler cast it with (array), and absint() reduced it to 11 — so
        // "Suspend ALL 3 members" suspended one, silently. Nothing about the
        // set of accounts to suspend may come from the browser.
        RingsRenderWorld::populated();

        $html = $this->render();

        self::assertStringNotContainsString('name="user_ids"', $html);
        self::assertStringNotContainsString('11,12,13', $html, 'no CSV member list anywhere');
    }

    public function testEveryFingerprintIsAWellFormedHash(): void
    {
        // ⚠ Anti-vacuity for the case above: "contains no ids" would also
        // pass if the field were empty or missing, which would make both
        // buttons unusable rather than safe.
        RingsRenderWorld::populated();

        $fps = $this->destructiveFingerprints($this->render());

        self::assertNotEmpty($fps);
        foreach ($fps as $i => $fp) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $fp, "form {$i} has no usable fingerprint");
        }
    }

    public function testTheConfirmDialogCountsTheRealMembers(): void
    {
        // "Suspend ALL 0 members" was the operator-facing symptom.
        RingsRenderWorld::populated();

        $html = $this->render();

        self::assertStringContainsString('Suspend ALL 3 members', $html);
        self::assertStringContainsString('Suspend ALL 4 members', $html);
        self::assertStringNotContainsString('Suspend ALL 0 members', $html);
    }

    public function testTheMemberCountInTheHeadingIsNotZero(): void
    {
        RingsRenderWorld::populated();

        $html = $this->render();

        self::assertStringContainsString('3 members', $html);
        self::assertStringContainsString('4 members', $html);
        self::assertStringNotContainsString('0 members', $html);
    }

    // ── 1. NO FABRICATED FACTS ABOUT A PERSON
    // ═══════════════════════════════════════════════════════════════════

    public function testUnloadedMemberDataSaysNotLoadedRatherThanZero(): void
    {
        // ⚠⚠ THE COLUMNS STAY AND SAY WHICH IT IS. The `?? 0` / `?? 'unknown'`
        // defaults printed "0" and a coloured "Unknown" risk pill for every
        // member of a detected ring — a fabricated measurement about a
        // person, on the screen whose buttons suspend them.
        //
        // Removing the columns would also have been wrong: an operator
        // looking for a fraud score should learn that this page does not have
        // it, not find the column missing and guess why. Three members' worth
        // of three columns = 9 "not loaded" cells across two rings of 3 and 4
        // => 21.
        RingsRenderWorld::populated();

        $html = $this->render();

        // ⚠ Count the CELLS, not the substring. A raw substring count also
        // picks up the explanatory paragraph's own two mentions of the phrase
        // (once per ring), which is how a first version of this assertion
        // expected 21 and measured 25 — the code was right and the count was
        // wrong. Matching the cell markup makes the number mean what it says.
        self::assertSame(
            21,
            preg_match_all('/<td><em [^>]*>not loaded<\/em><\/td>/', $html),
            'three unloaded columns for each of seven members across two rings'
        );
        self::assertStringContainsString('is not a score of zero and not a risk assessment', $html);
    }

    public function testNoMemberIsGivenAZeroScoreOrAnInventedRiskLevel(): void
    {
        // ⚠ The same claim stated as an absence, because "contains
        // not loaded" and "contains no fabricated zero" are different
        // assertions and only the pair rules out a table showing both.
        RingsRenderWorld::populated();

        $html = $this->render();

        // the old fabrications, verbatim
        self::assertStringNotContainsString('>Unknown<', $html, 'a risk pill reading Unknown is a classification');
        self::assertStringNotContainsString('font-weight:bold;">0', $html, 'a bold 0 in a score cell is a measurement');
        self::assertStringNotContainsString('scoreColor', $html);
        self::assertStringNotContainsString('riskColor', $html);
    }

    public function testEveryMemberStillHasAWayToBeInspected(): void
    {
        // ⚠ ANTI-OVER-REACH: removing the fabricated columns must not remove
        // the operator's route to the real numbers.
        RingsRenderWorld::populated();

        $html = $this->render();

        foreach ([11, 12, 13, 21, 22, 23, 24] as $id) {
            self::assertStringContainsString('user_id=' . $id, $html, "no Manage link for user {$id}");
        }
    }

    public function testRenderingAPopulatedSnapshotRaisesNoDiagnostic(): void
    {
        // ⚠ 21 "Trying to access array offset on value of type int" is what
        // this replaces. The suite runs under failOnWarning="true", so a
        // diagnostic here is a failure rather than noise — but asserting the
        // output too means a future `@` would not hide it.
        RingsRenderWorld::populated();

        $html = $this->render();

        self::assertStringNotContainsString('Warning', $html);
        self::assertStringNotContainsString('Deprecated', $html);
        self::assertStringNotContainsString('array offset', $html);
    }

    // ── 2. THE THREE STATES STAY DISTINCT
    // ═══════════════════════════════════════════════════════════════════

    public function testAnAbsentSnapshotSaysSoAndShowsNoCount(): void
    {
        RingsRenderWorld::absent();

        $html = $this->render();

        self::assertStringContainsString('No ring-detection snapshot yet', $html);
        self::assertStringNotContainsString('No vote rings detected', $html);
        self::assertSame([], $this->destructiveFingerprints($html), 'and nothing destructive is offered');
    }

    public function testASnapshotThatFoundNothingKeepsTheTick(): void
    {
        // ⚠ The preservation half: a run that found no rings is a
        // measurement, and the tab is entitled to say so.
        RingsRenderWorld::reset();   // available, zero rings

        $html = $this->render();

        self::assertStringContainsString('No vote rings detected', $html);
        self::assertStringNotContainsString('No ring-detection snapshot yet', $html);
    }

    public function testAnAvailableSnapshotShowsItsAge(): void
    {
        // A ring list without its age is not readable: the producer runs once
        // a day, so "0 clusters" an hour after a run and twenty-three hours
        // after one are different facts.
        RingsRenderWorld::reset();

        self::assertStringContainsString('Snapshot taken', $this->render());
    }

    public function testAnAbsentSnapshotShowsNoAge(): void
    {
        // ⚠ The pair. An age printed next to "no snapshot" would be a
        // timestamp for something that does not exist.
        RingsRenderWorld::absent();

        self::assertStringNotContainsString('Snapshot taken', $this->render());
    }

    // ── 3. ⚠⚠ THE RESULT OF A DESTRUCTIVE ACTION IS SHOWN
    // ═══════════════════════════════════════════════════════════════════
    //
    // handleRingAction() has always redirected with ?ring_msg=…, and NOTHING
    // rendered it — a grep found only the places that SET it. So an operator
    // pressed "Suspend ALL 3 members", was redirected, and saw nothing.
    //
    // That is also what hid the truncation bug: the redirect said
    // `suspended_1` for a three-member ring, every time, with no surface on
    // which to read it.

    public function testASuccessfulSuspensionReportsItsCount(): void
    {
        $_GET['ring_msg'] = 'suspended_3';
        RingsRenderWorld::reset();

        $html = $this->render();

        self::assertStringContainsString('3 ring members were suspended', $html);
        self::assertStringContainsString('notice-success', $html);
    }

    public function testSuspendingNobodyIsReportedAsAFAILURE(): void
    {
        // ⚠ `suspended_0` means every attempt threw. It used to be
        // indistinguishable from success — and invisible either way.
        $_GET['ring_msg'] = 'suspended_0';
        RingsRenderWorld::reset();

        $html = $this->render();

        self::assertStringContainsString('notice-error', $html);
        self::assertStringContainsString('No ring member was suspended', $html);
    }

    public function testARefusedActionSaysNothingWasChanged(): void
    {
        // The fingerprint no longer matches a detected ring: a stale tab, or
        // a snapshot refreshed since the page loaded.
        $_GET['ring_msg'] = 'ring_not_found';
        RingsRenderWorld::reset();

        $html = $this->render();

        self::assertStringContainsString('notice-error', $html);
        self::assertStringContainsString('nothing was changed', $html);
    }

    public function testAnUnrecognisedResultIsNotReportedAsSuccess(): void
    {
        // ⚠ Anti-vacuity, and the honest default: a code this page does not
        // know could mean anything, so it must not wear a green notice.
        $_GET['ring_msg'] = 'something_new_from_a_later_build';
        RingsRenderWorld::reset();

        $html = $this->render();

        self::assertStringNotContainsString('notice-success', $html);
        self::assertStringContainsString('does not recognise', $html);
    }

    public function testNoNoticeIsShownWithoutAResult(): void
    {
        // ⚠ The control. Without it every case above could pass against a
        // page that always prints a notice.
        RingsRenderWorld::reset();

        $html = $this->render();

        self::assertStringNotContainsString('notice-success', $html);
        self::assertStringNotContainsString('notice-error', $html);
    }
}
