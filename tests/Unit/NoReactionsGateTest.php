<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * An announcement takes no engagement — the bcc-trust write-path half.
 *
 * ── ⚠⚠⚠ THE THINGS THAT MUST NOT HAPPEN ─────────────────────────────────
 *  1. A HIDDEN CONTROL IS MISTAKEN FOR A CLOSED PATH. The client suppresses
 *     the affordances, but a suppressed button is a rendering choice, not a
 *     policy. Anyone can POST. If this gate is absent, an announcement
 *     accumulates real stokes and real helpful marks from a hand-made
 *     request, and the second is REPUTATION-BEARING.
 *  2. ONLY THE REACTIONS PATH IS CLOSED. The empty kind set closes
 *     set-reaction and nothing else: Stoke has its own table and endpoint,
 *     helpful marks likewise, and reaction REMOVAL is not grammar-gated.
 *     Three of the four doors stay open unless this gate shuts them.
 *  3. THE GATE EXISTS AND NOTHING CALLS IT. A safety method without a
 *     caller is a defect, and it is invisible to every behavioural test.
 *     The caller inventory below is the only thing that catches it.
 *  4. THE GATE REFUSES TOO MUCH. It must not touch an ordinary post. A
 *     guard that fails closed on everything would read as working while
 *     silently breaking Stoke for the whole feed.
 *  5. THE LOCAL WIRE LITERAL DRIFTS FROM bcc-core'S CONSTANT. The gate
 *     compares against its own `'none'` rather than
 *     `ReactionGrammarMap::GRAMMAR_NONE`, deliberately — see the class
 *     docblock — so the two must be reconciled the moment the constant is
 *     reachable.
 *
 * bcc-core is not autoloadable in this suite, so its collaborators are
 * doubled at their real FQCNs and every case runs in its own process.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NoReactionsGateTest extends TestCase
{
    /** @var class-string */
    private const GATE = \BCC\Trust\Core\Support\NoReactionsGate::class;

    private const ANNOUNCEMENT_ACT = 9001;
    private const STATUS_ACT       = 9002;
    private const REVIEW_ACT       = 9003;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/no-reactions-stubs.php';

        $GLOBALS['__bcc_nr_module_lookups'] = 0;
        $GLOBALS['__bcc_nr_module_by_act']  = [
            self::ANNOUNCEMENT_ACT => '205',
            self::STATUS_ACT       => '1',
            self::REVIEW_ACT       => '202',
        ];
    }

    // ── 1. It refuses an announcement ─────────────────────────────────────

    public function testAnAnnouncementActivityIsRefused(): void
    {
        $r = (self::GATE)::check(self::ANNOUNCEMENT_ACT);

        self::assertNotNull($r, 'module 205 must be refused');
        self::assertSame(403, $r->get_status());

        $body = $r->get_data();
        self::assertIsArray($body);
        self::assertSame('bcc_forbidden', $body['error']['code']);
        self::assertSame('reactions_not_applicable', $body['error']['data']['reason_code']);
    }

    /**
     * The refusal must be a flat statement about the CONTENT, carrying no
     * counts, no viewer state and no engagement figures — there is nothing
     * to report, and a payload implying otherwise invites a client to
     * render something.
     */
    public function testTheRefusalCarriesNoEngagementDetail(): void
    {
        $body = (self::GATE)::check(self::ANNOUNCEMENT_ACT)->get_data();
        $json = (string) json_encode($body);

        foreach (['heat_stage', 'stoke_count', 'viewer_has_stoked', 'counts', 'viewer_reaction'] as $leak) {
            self::assertStringNotContainsString($leak, $json, "refusal must not mention {$leak}");
        }
    }

    // ── 2. It refuses nothing else — MUST-NOT-HAPPEN #4 ───────────────────

    public function testAnOrdinaryPostIsUntouched(): void
    {
        self::assertNull((self::GATE)::check(self::STATUS_ACT), 'a status post still takes reactions');
        self::assertNull((self::GATE)::check(self::REVIEW_ACT), 'a review still takes the trust reaction');
    }

    /**
     * Anti-vacuity on the fixture: the same gate, in the same process,
     * refuses one act and allows another. So "allowed" is a real answer and
     * not the gate silently doing nothing.
     */
    public function testTheSameGateDiscriminatesBetweenKindsInOneProcess(): void
    {
        self::assertNotNull((self::GATE)::check(self::ANNOUNCEMENT_ACT));
        self::assertNull((self::GATE)::check(self::STATUS_ACT));
    }

    // ── 3. Fail-open cases, and why they are correct ──────────────────────

    /**
     * An act id that resolves to no module is not a no-reactions concern.
     * Swallowing it here would mask the caller's own not-found path, and an
     * unknown act cannot be an announcement. Every caller already surfaces
     * that case.
     */
    public function testAnUnknownActIsNotThisGatesConcern(): void
    {
        self::assertNull((self::GATE)::check(424242));
    }

    public function testANonPositiveActIdIsRejectedBeforeAnyLookup(): void
    {
        foreach ([0, -7] as $bad) {
            self::assertNull((self::GATE)::check($bad), "actId {$bad}");
        }
        self::assertSame(0, $GLOBALS['__bcc_nr_module_lookups'], 'no query for an impossible id');
    }

    public function testAnEmptyModuleStringIsTreatedAsUnknown(): void
    {
        $GLOBALS['__bcc_nr_module_by_act'] = [777 => ''];
        self::assertNull((self::GATE)::check(777));
    }

    public function testEachCheckCostsExactlyOneModuleLookup(): void
    {
        (self::GATE)::check(self::ANNOUNCEMENT_ACT);
        self::assertSame(1, $GLOBALS['__bcc_nr_module_lookups']);

        (self::GATE)::check(self::STATUS_ACT);
        self::assertSame(2, $GLOBALS['__bcc_nr_module_lookups'], 'no memoization — a stale answer is worse');
    }

    // ── 4. The two read-side predicates ───────────────────────────────────

    public function testTheKindPredicateAgreesWithTheModulePredicate(): void
    {
        self::assertTrue((self::GATE)::kindTakesNoReactions('announcement'));
        self::assertTrue((self::GATE)::moduleTakesNoReactions('205'));

        foreach (['status', 'review', 'blog_excerpt', 'watch_batch', 'page_claim'] as $kind) {
            self::assertFalse((self::GATE)::kindTakesNoReactions($kind), $kind);
        }
        foreach (['1', '202', '204', '200', '201'] as $module) {
            self::assertFalse((self::GATE)::moduleTakesNoReactions($module), $module);
        }
    }

    /**
     * An unregistered kind must NOT be treated as no-reactions. The grammar
     * map falls back to `social`, so a typo in a post_kind has to keep its
     * reactions rather than silently losing them — failing closed here
     * would be the wrong direction, because it removes a working feature on
     * the strength of a spelling mistake.
     */
    public function testAnUnregisteredKindKeepsItsReactions(): void
    {
        self::assertFalse((self::GATE)::kindTakesNoReactions('announcment'));   // sic
        self::assertFalse((self::GATE)::kindTakesNoReactions(''));
        self::assertFalse((self::GATE)::moduleTakesNoReactions('999'));
    }

    // ── 5. The cross-repo literal — MUST-NOT-HAPPEN #5 ────────────────────

    /**
     * The gate compares against its own `'none'` literal rather than
     * bcc-core's constant, because bcc-trust's static analysis runs against
     * bcc-core's DEFAULT BRANCH and a direct reference would make this file
     * unanalysable until the bcc-core half merged.
     *
     * This reconciles the two the moment the real class is reachable, and
     * SKIPS with a named reason until then — so the skip is the ordering
     * signal, and the drift is caught as soon as it is possible to catch.
     *
     * Reflection rather than a direct constant access, so static analysis
     * sees no reference to a constant that may not exist yet.
     */
    public function testTheWireLiteralMatchesTheProductionConstant(): void
    {
        self::assertSame('none', (self::GATE)::GRAMMAR_NONE_WIRE);

        $fqcn = 'BCC\\Core\\Feed\\ReactionGrammarMap';
        $path = dirname(__DIR__, 3) . '/bcc-core/src/Feed/ReactionGrammarMap.php';
        if (!is_file($path)) {
            self::markTestSkipped(
                'bcc-core is not checked out beside this plugin — cannot reconcile the wire literal'
            );
        }

        $src = (string) file_get_contents($path);
        if (!str_contains($src, 'GRAMMAR_NONE')) {
            self::markTestSkipped(
                'bcc-core does not declare GRAMMAR_NONE yet (bcc-core #57 unmerged) — '
                . 'this is the documented cross-repo ordering dependency, not a failure'
            );
        }

        self::assertMatchesRegularExpression(
            '/GRAMMAR_NONE\s*=\s*\x27none\x27/',
            $src,
            'bcc-core declares GRAMMAR_NONE with a different value than this gate compares against'
        );

        // The double must stay faithful too, or every case above drifts
        // from production while staying green.
        $rc = new ReflectionClass($fqcn);
        self::assertTrue($rc->hasConstant('GRAMMAR_NONE'));
        self::assertSame('none', $rc->getConstant('GRAMMAR_NONE'));
    }

    // ── 6. The caller inventory — MUST-NOT-HAPPEN #1, #2, #3 ──────────────

    /**
     * Every endpoint that writes engagement must consult the gate. This is
     * the only assertion that catches a gate nobody calls, and it is why
     * the reaction-removal path is listed: it is NOT grammar-gated, so the
     * empty kind set does not cover it.
     *
     * Counts, not just presence — `StokeEndpoint` and `ReactionsEndpoint`
     * each have two handlers, and covering one of the two would leave the
     * toggle open in one direction.
     */
    public function testEveryEngagementWritePathConsultsTheGate(): void
    {
        $root = dirname(__DIR__, 2) . '/app/Domain/Core/REST/';

        $expected = [
            // file => required number of NoReactionsGate::check() call sites
            'StokeEndpoint.php'       => 2,   // addStoke + removeStoke
            'ReactionsEndpoint.php'   => 2,   // setReaction + removeReaction
            'HelpfulMarkEndpoint.php' => 1,   // one shared handle() for all four routes
        ];

        foreach ($expected as $file => $want) {
            $src = (string) file_get_contents($root . $file);
            self::assertNotSame('', $src, "{$file} must be readable for this guard to mean anything");

            // Positive control: the file really is the one we think it is.
            self::assertStringContainsString('class ', $src, $file);

            self::assertSame(
                $want,
                substr_count($src, 'NoReactionsGate::check('),
                "{$file} must consult the gate on every engagement write path"
            );
            self::assertStringContainsString(
                'use BCC\\Trust\\Core\\Support\\NoReactionsGate;',
                $src,
                "{$file} must import the gate"
            );

            // ⚠ CALLING THE GATE IS NOT HONOURING IT, and this test was
            // weaker until a mutation control proved it. Neutering the guard
            // to `if (false)` leaves every `NoReactionsGate::check(` in
            // place, so a count of call sites alone stayed green while all
            // three endpoints silently reopened. That is precisely the shape
            // a "simplify the gates" refactor produces.
            //
            // So each call site must also carry its guard AND its return.
            self::assertSame(
                $want,
                substr_count($src, 'if ($noReactions !== null) {'),
                "{$file}: every gate call must be guarded on its result"
            );
            self::assertSame(
                $want,
                substr_count($src, 'return $noReactions;'),
                "{$file}: every guard must return the refusal rather than fall through"
            );
        }
    }

    /**
     * The feed hydrator consults the READ-side predicate in BOTH places it
     * decorates an item, so the response cannot claim a surface the write
     * path refuses. A server that advertises `heat_stage` and then 403s the
     * stoke is incoherent, and the incoherence shows up as a broken button
     * rather than as a policy.
     *
     * ⚠ COUNTED, NOT MERELY PRESENT, and this test was weaker until a
     * mutation control proved it. There are TWO call sites — one in the
     * reactions hydrator (omit the Stoke fields) and one in the
     * comment-count hydrator (omit the known-wrong count). A
     * `assertStringContainsString` passes with either one deleted, because
     * the other still matches the needle: breaking the Stoke half left the
     * suite green. The count is what distinguishes them.
     */
    public function testTheFeedHydratorConsultsTheReadSidePredicateInBothPlaces(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Services/Feed/FeedHydrationPipeline.php'
        );
        self::assertNotSame('', $src);

        self::assertSame(
            2,
            substr_count($src, 'NoReactionsGate::kindTakesNoReactions('),
            'both the reactions hydrator and the comment-count hydrator must consult the predicate'
        );
        self::assertStringContainsString(
            'use BCC\\Trust\\Core\\Support\\NoReactionsGate;',
            $src
        );

        // Negative half: the Stoke fields must be reachable ONLY under the
        // predicate. A straight-line assignment would mean the omission is
        // conditional on nothing.
        foreach (['heat_stage', 'viewer_has_stoked', 'stoke_count'] as $field) {
            self::assertStringNotContainsString(
                "'{$field}'         => \$heat",
                $src,
                "{$field} must not be assigned unconditionally"
            );
        }
    }
}
