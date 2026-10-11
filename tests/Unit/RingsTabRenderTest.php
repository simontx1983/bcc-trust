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

    /** @return list<string> every value the destructive forms would post */
    private function destructiveTargets(string $html): array
    {
        preg_match_all('/name="user_ids"\s+value="([^"]*)"/', $html, $m);
        return $m[1];
    }

    // ── 0. ⚠⚠ THE DESTRUCTIVE FORMS CARRY REAL TARGETS
    // ═══════════════════════════════════════════════════════════════════

    public function testEachRingArmsItsFormsWithItsOwnMembers(): void
    {
        RingsRenderWorld::populated();

        $targets = $this->destructiveTargets($this->render());

        // Two forms per ring, two rings.
        self::assertCount(4, $targets, 'two destructive forms per ring');
        self::assertSame(['11,12,13', '11,12,13', '21,22,23,24', '21,22,23,24'], $targets);
    }

    public function testNoDestructiveFormIsEverArmedWithAnEmptyTargetList(): void
    {
        // ⚠ THE CASE THE WHOLE SHAPE FIX EXISTS FOR, stated as an absence.
        // An empty user_ids under a "Suspend ALL" confirm is worse than a
        // broken page: it is a button that claims to act and cannot.
        RingsRenderWorld::populated();

        foreach ($this->destructiveTargets($this->render()) as $i => $ids) {
            self::assertNotSame('', $ids, "form {$i} has no targets");
            self::assertMatchesRegularExpression('/^\d+(,\d+)*$/', $ids, "form {$i} is not a list of ids");
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

    public function testNoFraudScoreOrRiskLevelIsInvented(): void
    {
        // ⚠ The snapshot holds membership only. Printing "Fraud Score 0" and
        // "Risk Unknown" for every member — which the `?? 0` / `?? 'unknown'`
        // defaults did — asserts a measurement nobody took, about a user, on
        // the screen that can suspend them.
        RingsRenderWorld::populated();

        $html = $this->render();

        self::assertStringNotContainsString('Fraud Score', $html);
        self::assertStringNotContainsString('Risk', $html);
        self::assertStringContainsString('records ring membership only', $html);
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
        self::assertSame([], $this->destructiveTargets($html), 'and nothing destructive is offered');
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
}
