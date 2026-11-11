<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Announcements\AnnouncementModerationPolicy as Policy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The announcement auto-hide exclusion — api-contract-v1.md §4.32.16.
 *
 * ── ⚠⚠⚠ THE THING THIS PREVENTS ─────────────────────────────────────────
 * Announcements are reportable, and the auto-hide path is live: it counts
 * distinct pending reporters against a `feed_item` target and at the
 * configured threshold (default 3) hides the activity, after which the
 * next feed read filters it out for non-moderators.
 *
 * An announcement projects exactly ONE feed activity (§4.32.8), and that
 * activity is the same `feed_item` a report targets. So without this
 * policy, THREE REPORTERS WOULD SILENTLY TAKE DOWN A VALIDATOR'S OFFICIAL
 * NOTICE — no operator action, no administrator in the loop, nothing in
 * any response saying it had happened.
 *
 * ── ⚠⚠ THE THINGS THAT MUST NOT HAPPEN ──────────────────────────────────
 *  1. AN ANNOUNCEMENT IS AUTO-HIDDEN. The whole point.
 *  2. ORDINARY CONTENT STOPS BEING AUTO-HIDEABLE. A policy that blocked
 *     everything would satisfy (1) while disabling platform moderation.
 *     Every announcement case here has an ordinary-module counterpart.
 *  3. A MALFORMED MODULE VALUE SLIPS THROUGH. `act_module_id` comes back
 *     from MySQL as a STRING. An int comparison, or an untrimmed one,
 *     would never match — the exclusion would never fire and nothing
 *     would look wrong.
 *  4. A LOOKALIKE MODULE IS CAPTURED. `2050`, `20`, `1205` are other
 *     people's modules. The predicate must be exact, not a prefix match.
 *  5. AN UNREADABLE MODULE IS TREATED AS HIDEABLE. You cannot responsibly
 *     hide an activity you cannot identify, and this is the one outcome
 *     §4.32.16 forbids. ⚠ This case is a DELIBERATE trade-off against
 *     must-not-happen (2) — see the class docblock of the policy.
 *
 * ── WHAT THIS DOES NOT PROVE ────────────────────────────────────────────
 * The real `peepso_activities` read is stubbed, so this does not prove the
 * SQL. What it proves is the decision the SQL feeds, and that the caller
 * in `AutoHideService` consults it before its only write — the latter
 * pinned by a source assertion, because a policy that is computed and
 * then ignored is the failure mode a value test cannot see.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(Policy::class)]
final class AnnouncementModerationPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        // §11 — reuses the stub #320 already introduced for this exact
        // seam rather than declaring a second double for it.
        require_once __DIR__ . '/../Stubs/no-reactions-stubs.php';
        $GLOBALS['__bcc_nr_module_by_act'] = [];
    }

    private function seedModule(int $actId, ?string $module): void
    {
        $map = $GLOBALS['__bcc_nr_module_by_act'] ?? [];
        if ($module === null) {
            unset($map[$actId]);
        } else {
            $map[$actId] = $module;
        }
        $GLOBALS['__bcc_nr_module_by_act'] = $map;
    }

    // ── 1. The guarantee, and its counterpart ────────────────────────────

    /** MUST-NOT-HAPPEN #1. */
    public function testAnAnnouncementActivityIsNeverAutoHidden(): void
    {
        $this->seedModule(4471, '205');

        self::assertSame(Policy::AUTO_HIDE_BLOCKED_ANNOUNCEMENT, Policy::autoHideDecision(4471));
        self::assertFalse(Policy::mayAutoHide(4471));
    }

    /**
     * MUST-NOT-HAPPEN #2 — every module the platform actually uses stays
     * hideable. Driven off the real module ids so a policy that blocked
     * broadly could not pass.
     */
    #[DataProvider('ordinaryModules')]
    public function testOrdinaryContentStaysAutoHideable(string $module, string $what): void
    {
        $this->seedModule(900, $module);

        self::assertSame(Policy::AUTO_HIDE_ALLOWED, Policy::autoHideDecision(900), $what);
        self::assertTrue(Policy::mayAutoHide(900), $what);
    }

    /** @return iterable<string, array{string, string}> */
    public static function ordinaryModules(): iterable
    {
        yield 'status / gif' => ['1', 'PeepSo status — the commonest reported kind'];
        yield 'photo'        => ['4', 'PeepSo photo'];
        yield 'review'       => ['202', 'BCC review'];
        yield 'blog'         => ['204', 'BCC blog — the module ADJACENT to 205'];
        yield 'watch batch'  => ['203', 'a BCC module in the same range'];
        yield 'page claim'   => ['201', 'likewise'];
    }

    // ── 2. Malformed stored values — MUST-NOT-HAPPEN #3 ─────────────────

    /**
     * Nothing of ours writes a padded module. An import, a migration or
     * hand-written SQL can, and privacy — here, a validator's notice
     * staying up — must not depend on the writer being well-behaved.
     */
    #[DataProvider('paddedAnnouncementModules')]
    public function testAPaddedAnnouncementModuleIsStillExcluded(string $module): void
    {
        $this->seedModule(555, $module);

        self::assertSame(
            Policy::AUTO_HIDE_BLOCKED_ANNOUNCEMENT,
            Policy::autoHideDecision(555),
            'padded [' . $module . '] must not slip past the exclusion'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function paddedAnnouncementModules(): iterable
    {
        yield 'trailing space'  => ['205 '];
        yield 'leading space'   => [' 205'];
        yield 'both'            => ['  205  '];
        yield 'trailing tab'    => ["205\t"];
        yield 'trailing CR'     => ["205\r"];
        yield 'leading newline' => ["\n205"];
    }

    // ── 3. Lookalikes are NOT ours — MUST-NOT-HAPPEN #4 ─────────────────

    /**
     * The predicate must be exact, not greedy. A module that merely
     * resembles ours belongs to whoever owns it, and capturing it would
     * quietly exempt someone else's content from moderation.
     */
    #[DataProvider('lookalikeModules')]
    public function testLookalikeModulesAreNotExcluded(string $module): void
    {
        $this->seedModule(777, $module);

        self::assertSame(
            Policy::AUTO_HIDE_ALLOWED,
            Policy::autoHideDecision(777),
            $module . ' is not the announcement module'
        );
    }

    /** @return iterable<string, array{string}> */
    public static function lookalikeModules(): iterable
    {
        yield 'suffix'         => ['2050'];
        yield 'prefix'         => ['1205'];
        yield 'truncated'      => ['20'];
        yield 'off by one'     => ['206'];
        yield 'off by one low' => ['204'];
        yield 'zero padded'    => ['0205'];
        yield 'float-ish'      => ['205.0'];
        yield 'word'           => ['announcement'];
    }

    // ── 4. Unresolvable — MUST-NOT-HAPPEN #5, and its trade-off ─────────

    /**
     * ⚠⚠ The deliberate trade-off, asserted so it cannot be changed by
     * accident. `getModuleIdByActId()` returns null for a missing row, an
     * empty module and a failed query alike — it cannot distinguish them.
     *
     * Treating that as hideable could auto-hide an announcement, which is
     * the one outcome §4.32.16 forbids and which would be unobservable
     * afterwards. Treating it as NOT hideable defers an ordinary hide. The
     * policy takes the second, and the value is reported DISTINCTLY from
     * the announcement case so the caller can log a fault as a fault.
     */
    #[DataProvider('unresolvableLookups')]
    public function testAnUnreadableModuleBlocksTheAutomaticHide(
        int $actId,
        ?string $module,
        string $why
    ): void {
        $this->seedModule($actId, $module);

        self::assertSame(Policy::AUTO_HIDE_BLOCKED_UNRESOLVED, Policy::autoHideDecision($actId), $why);
        self::assertFalse(Policy::mayAutoHide($actId), $why);
    }

    /** @return iterable<string, array{int, string|null, string}> */
    public static function unresolvableLookups(): iterable
    {
        yield 'no such activity' => [12345, null, 'a missing row has nothing to hide'];
        yield 'empty module'     => [12346, '', 'an empty module identifies nothing'];
        yield 'whitespace only'  => [12347, '   ', 'neither does whitespace'];
        yield 'act id zero'      => [0, null, 'not an activity at all'];
        yield 'act id negative'  => [-1, null, 'nor is a negative id'];
    }

    /**
     * ⚠ The three values must be DISTINCT. Collapsing the unresolved case
     * into the announcement case would make a persistent read fault look
     * like ordinary policy and it would never be investigated.
     */
    public function testTheThreeDecisionsAreDistinctValues(): void
    {
        self::assertSame(
            3,
            count(array_unique([
                Policy::AUTO_HIDE_ALLOWED,
                Policy::AUTO_HIDE_BLOCKED_ANNOUNCEMENT,
                Policy::AUTO_HIDE_BLOCKED_UNRESOLVED,
            ])),
            'a fault and a policy decision must be tellable apart'
        );
    }

    // ── 5. The module id is a STRING — MUST-NOT-HAPPEN #3 ───────────────

    /**
     * ⚠ `act_module_id` is read back from MySQL as a string, and bcc-core's
     * canonical module→kind map is keyed by string. An int constant here
     * would make every comparison fail in the SAFE-LOOKING direction: the
     * module would never match, the exclusion would never fire, and no
     * test of ordinary content would notice.
     */
    public function testTheModuleConstantIsAStringNotAnInt(): void
    {
        self::assertIsString(Policy::ANNOUNCEMENT_MODULE_ID);
        self::assertSame('205', Policy::ANNOUNCEMENT_MODULE_ID);
    }

    // ── 6. The caller actually honours it ───────────────────────────────

    /**
     * ⚠⚠ Calling the policy is not honouring it. A value test cannot see
     * the difference between a decision that gates the write and one that
     * is computed and discarded, so this reads the caller's source and
     * requires both the consultation AND a `return` inside the branch —
     * before the single `hiddenRepo->add(` that is the only way an
     * activity becomes hidden.
     */
    public function testAutoHideServiceConsultsThePolicyBeforeItsOnlyWrite(): void
    {
        $src = file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Services/AutoHideService.php'
        );
        self::assertIsString($src);

        $consult = strpos($src, 'AnnouncementModerationPolicy::autoHideDecision(');
        $write   = strpos($src, '$this->hiddenRepo->add(');

        self::assertNotFalse($consult, 'the policy must be consulted');
        self::assertNotFalse($write, 'the hide write must still exist');
        self::assertLessThan($write, $consult, 'the policy must be consulted BEFORE the write');

        // The guard must RETURN, not merely log. Taken from the consult
        // point up to the write, so a `return` elsewhere in the file
        // cannot satisfy it.
        $between = substr($src, $consult, $write - $consult);
        self::assertStringContainsString('return;', $between, 'the guard must stop the write');

        // Exactly one write path, so the guard cannot be bypassed by a
        // second one added later.
        self::assertSame(
            1,
            substr_count($src, '$this->hiddenRepo->add('),
            'a second hide write would need its own guard'
        );
    }

    /**
     * ⚠ The report path must mint no reputation, trust or fraud signal
     * (§4.32.16) — and that prohibition is currently true BY ACCIDENT of
     * the wiring, not by a guard. So it is pinned here: the report service
     * and the auto-hide service must name no scoring seam.
     *
     * ⚠ This is a STATIC check. It would not catch a scoring call reached
     * through a variable class name, a callable, or a WordPress action
     * added elsewhere — which is exactly why it also asserts the
     * subscriber COUNT below.
     */
    public function testNeitherTheReportNorTheHidePathNamesAScoringSeam(): void
    {
        foreach ([
            '/app/Domain/Core/Services/ContentReportService.php',
            '/app/Domain/Core/Services/AutoHideService.php',
        ] as $rel) {
            $src = file_get_contents(dirname(__DIR__, 2) . $rel);
            self::assertIsString($src, $rel);

            // Strip comments: the docblocks DISCUSS scoring in order to
            // rule it out, so a whole-file grep would already fail.
            $code = '';
            foreach (token_get_all($src) as $t) {
                if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= is_array($t) ? $t[1] : $t;
            }

            foreach ([
                'ReputationRepository',
                'ScoreRepository',
                'FraudDetector',
                'ReliabilityEngine',
                'EvidenceListener',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $code,
                    "{$rel} must not reach {$forbidden} — reporting mints no score (§4.32.16)"
                );
            }
        }
    }

    /**
     * ⚠⚠ The "no scoring" guarantee rests on the report event having
     * exactly ONE subscriber. A new subscriber is how that silently stops
     * being true, so the count is pinned: adding one is a decision that
     * has to pass through this assertion.
     */
    public function testTheReportEventStillHasExactlyOneSubscriber(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Plugin.php');
        self::assertIsString($src);

        // ⚠ Comment-stripped and counted on the EVENT NAME, not on an
        // `add_action(` + newline + indentation shape. An earlier revision
        // matched the literal source layout and found 0 in this CRLF repo
        // — a needle that silently measures nothing is worse than no
        // assertion, because it reads as a passing guard.
        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        self::assertSame(
            1,
            substr_count($code, "'bcc_content_reported'"),
            'a second bcc_content_reported subscriber needs a §4.32.16 review'
        );

        // Anti-vacuity: the needle really does find the one that exists.
        self::assertStringContainsString("'bcc_content_reported'", $code);
    }
}
