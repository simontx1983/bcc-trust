<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\DeadMarkingOptionStub;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Partial-fetch detection is corrected, and it still cannot mark anything dead.
 *
 * ── WHAT WAS WRONG ──────────────────────────────────────────────────────
 * The relative test compared this fetch against `COUNT(*)` over every row
 * ever stored for the chain. A validator that LEAVES the bonded set keeps
 * its row and keeps `status = 'active'`, so that count only grows while the
 * fetch stays flat — and the chain reports `partial` for ever.
 *
 * Measured on production 2026-11-02:
 *   cosmos  stored 318 (317 active)  fetch returned 180  -> partial
 *   osmosis stored  96 ( 93 active)  fetch returned  30  -> partial
 *   jackal, juno, solana likewise. 5 chains permanently partial, and 52
 *   `Skipped markDeadValidators` log lines recording the deadlock: the
 *   sweep that would have reconciled those rows was blocked BY those rows.
 *
 * ⚠ AND THE OBVIOUS BASELINE IS A TRAP. `indexer_stats[slug]['total']` is
 * written on EVERY non-empty fetch, partial or not, so comparing against it
 * would let one truncated run become the new normal — a ratchet in which
 * truncation normalises itself. Cosmos demonstrates it: `partial = true`
 * alongside `total = 180`, which compared against itself reads "healthy".
 *
 * So detection now compares against `last_good_total`, written only on a
 * fetch that PASSED detection.
 *
 * ── AND THE DESTRUCTIVE PATH IS NOW SEPARATE ────────────────────────────
 * Correcting a measurement must not be what triggers a destructive write.
 * Before this, a chain that stopped reporting partial immediately exposed
 * `markDeadValidators()` — a GLOBAL UPDATE with no `chain_id` predicate,
 * with 51 production rows in range. It now needs its own switch, which is
 * off, so section 1 holds regardless of what detection concludes.
 */
#[CoversNothing]
final class PartialFetchBaselineTest extends TestCase
{
    private const SERVICE = 'BCC\\Trust\\Onchain\\Services\\ChainRefreshService';

    private static function source(): string
    {
        $src = file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/ChainRefreshService.php'
        );
        self::assertNotFalse($src, 'could not read ChainRefreshService.php');
        self::assertNotSame('', $src);

        return (string) $src;
    }

    /** Strip comments so prose cannot satisfy a "does not" assertion. */
    private static function code(string $src): string
    {
        $out = preg_replace('!/\*.*?\*/!s', '', $src) ?? '';

        return (string) preg_replace('!//[^\r\n]*!', '', $out);
    }

    /** @return array{0:int,1:int} byte span of the block opened by $opener */
    /**
     * ⚠⚠ $occurrence IS LOAD-BEARING. Two different blocks in
     * ChainRefreshService now open with `if (!$isPartialFetch)` - the
     * baseline write and the healthy-chain collection - and the baseline
     * write comes FIRST in the file. Matching only the first occurrence
     * silently repointed these assertions at the wrong block; they failed
     * here only because the needle happened to be absent. Had the new block
     * been added lower down, they would have PASSED while checking nothing
     * they claimed to. So the occurrence is always stated explicitly.
     *
     * @return array{0:int,1:int}
     */
    private static function blockSpan(string $code, string $opener, int $occurrence = 1): array
    {
        $start = -1;
        $from  = 0;
        for ($n = 0; $n < $occurrence; $n++) {
            $start = strpos($code, $opener, $from);
            self::assertNotFalse($start, "opener #" . ($n + 1) . " not found: {$opener}");
            $from = (int) $start + 1;
        }
        $i = strpos($code, '{', $start);
        self::assertNotFalse($i, "no brace after: {$opener}");

        $depth = 0;
        for ($pos = $i, $len = strlen($code); $pos < $len; $pos++) {
            if ($code[$pos] === '{') {
                $depth++;
            } elseif ($code[$pos] === '}') {
                $depth--;
                if ($depth === 0) {
                    return [$i, $pos];
                }
            }
        }
        self::fail("unbalanced braces after: {$opener}");
    }

    /**
     * The text of one statement, from a needle to its terminating semicolon.
     *
     * ⚠ Use this, not blockSpan(), for an EXPRESSION. blockSpan matches
     * braces, so on a statement with none it runs on to an unrelated block and
     * the assertion silently stops meaning anything.
     */
    private static function statementSpan(string $code, string $needle): string
    {
        $start = strpos($code, $needle);
        self::assertNotFalse($start, "statement not found: {$needle}");
        $end = strpos($code, ';', (int) $start);
        self::assertNotFalse($end, "statement has no terminator: {$needle}");

        return substr($code, (int) $start, (int) $end - (int) $start + 1);
    }

    private static function reflect(): ReflectionClass
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        if (!class_exists(self::SERVICE, false)) {
            require dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/ChainRefreshService.php';
        }
        self::assertTrue(class_exists(self::SERVICE), 'ChainRefreshService must load');

        return new ReflectionClass(self::SERVICE);
    }

    /**
     * The detection predicate, reproduced exactly as the source composes it.
     * Pinned against the source by testTheDetectionPredicateMatchesTheSource
     * so this cannot drift into testing a fiction.
     *
     * ⛔⛔⛔ THIS IS NO LONGER A MIRROR. It invokes the REAL
     * predicate through reflection, and that change is itself a fix.
     *
     * While a local copy, this reproduced the source expression by hand, so
     * every drop case below tested the COPY. A mutation control that put the
     * age gate back on the real detection line passed the entire suite -
     * measured, not supposed - because no behavioural test could reach the
     * code it changed, and the one structural assertion was satisfied by the
     * mutant's own text. The predicate was extracted into
     * ChainRefreshService::isPartialFetch() so these cases execute it.
     *
     * ⚠ There is no age parameter, and its absence is the point: age
     * governs MINTING only. Section (3a) drives that half.
     */
    private static function isPartial(
        int $returned,
        int $known,
        int $baseline
    ): bool {
        $m = self::reflect()->getMethod('isPartialFetch');
        $m->setAccessible(true);

        return (bool) $m->invoke(null, $returned, $known, $baseline);
    }

    // ── (0) anti-vacuity ────────────────────────────────────────────────

    public function testTheSourceAndClassAreReallyLoaded(): void
    {
        self::assertStringContainsString('class ChainRefreshService', self::source());
        self::assertTrue(self::reflect()->hasMethod('deadMarkingEnabled'));
    }

    public function testTheCommentStripperStrips(): void
    {
        $code = self::code("\$a = 1; // markDeadValidators()\n/* last_good_total */\n\$b = live();");

        self::assertStringNotContainsString('markDeadValidators', $code);
        self::assertStringNotContainsString('last_good_total', $code);
        self::assertStringContainsString('live()', $code);
    }

    /** The local predicate must be the one the source actually composes. */
    public function testTheDetectionPredicateMatchesTheSource(): void
    {
        $code = self::code(self::source());

        // ⚠ UPDATED WITH THE SMALL-CHAIN FIX. This used to pin
        // `$baseline > 0` and say the gate was 'the baseline EXISTING,
        // nothing more' - which is what let a ratio be applied to a handful
        // of validators and pin a small chain partial forever. The gate is
        // now a MINIMUM, and the constant is named so the threshold cannot
        // be changed silently without this line moving too.
        self::assertStringContainsString(
            '$relativeDrop = $baseline > self::RELATIVE_TEST_MIN_BASELINE',
            $code,
            'the relative test is gated on a MINIMUM baseline, not mere existence'
        );
        self::assertStringContainsString(
            'private const RELATIVE_TEST_MIN_BASELINE = 10;',
            $code,
            'and the minimum is 10, mirroring the guard main carried'
        );

        // ⛔⛔⛔ AND IT MUST NOT BE GATED ON AGE. The regression
        // guard for the defect, as a SECOND line of defence behind the
        // behavioural one: the drop cases now execute the real predicate, so a
        // reinstated age gate fails them outright.
        //
        // ⚠ The first version of this check used blockSpan(), which matches
        // BRACES - and an assignment expression has none, so it spanned to some
        // unrelated block and asserted nothing about the statement. Span to the
        // statement TERMINATOR instead.
        $dropExpr = self::statementSpan($code, '$relativeDrop =');
        self::assertStringContainsString('RELATIVE_TEST_MIN_BASELINE', $dropExpr);
        foreach (['baselineUsable', 'baselineFresh', 'baselineAge', 'BASELINE_MAX_AGE'] as $forbidden) {
            self::assertStringNotContainsString(
                $forbidden,
                $dropExpr,
                "detection must not consult {$forbidden}"
            );
        }
        self::assertStringContainsString('($returnedCount * 10) < ($baseline * 7)', $code);
        self::assertStringContainsString('($knownCount > 200 && $returnedCount < 50)', $code);
        self::assertStringContainsString(
            '($knownCount === 0 && $returnedCount > 0 && $returnedCount < 30)',
            $code
        );

        // the predicate composes the three rules and the call site consumes it
        self::assertStringContainsString('return $relativeDrop || $absoluteFloor;', $code);
        self::assertStringContainsString(
            '$isPartialFetch = self::isPartialFetch(',
            $code,
            'the loop must take its verdict from the extracted predicate'
        );
    }

    /**
     * ⛔⛔⛔ THE CALL SITE IS WHERE THE DEFECT COMES BACK.
     *
     * The predicate is covered behaviourally and structurally, and
     * $baselineFresh is not in ITS scope - so the age gate cannot be
     * reinstated inside isPartialFetch(). It can be reinstated on the
     * ARGUMENT, one line up:
     *
     *     $isPartialFetch = self::isPartialFetch(
     *         $returnedCount,
     *         $knownCount,
     *         $baselineFresh ? $baseline : 0   // <- the original defect, exactly
     *     );
     *
     * A zero baseline switches the relative test off, so a chain truncated
     * for more than a week reads CLEAN and its truncated count is written
     * back as the new baseline. That is the ratchet, restored in one
     * expression - and MEASURED to pass the entire suite before this test
     * existed, because no behavioural case traverses the call site: they
     * invoke the predicate directly.
     *
     * ⚠ So this guard is necessarily STRUCTURAL, and a blacklist of
     * age-bearing tokens cannot enumerate every way to neutralise an
     * argument. Hence the second assertion: the argument list must be
     * exactly the three locals, each assigned unconditionally above.
     *
     * ⛔⛔ AND NEITHER OF THOSE IS ENOUGH, which was MEASURED and is
     * why the third assertion exists. Both pin the call's TEXT, and the
     * defect does not have to touch it: inserting
     *
     *     $baseline = $baselineFresh ? $baseline : 0;
     *
     * on the line ABOVE leaves the call statement byte-identical, passes
     * php -l, restores the ratchet in full - and left the ENTIRE suite
     * green, 4922 tests, with the two assertions above satisfied.
     *
     * So the guard has to be about the VALUE that reaches the predicate,
     * not the spelling of the call: within index_validators(), $baseline
     * is assigned EXACTLY ONCE and that assignment is the raw read. Any
     * re-gating is then either a second assignment or a changed one, and
     * it does not matter which line it sits on.
     */
    public function testTheCallSitePassesTheRawBaselineAndCannotReGateItOnAge(): void
    {
        $code = self::code(self::source());
        $call = self::statementSpan(
            $code,
            '$isPartialFetch = self::isPartialFetch('
        );

        $forbidden = [
            'baselineUsable',
            'baselineFresh',
            'baselineAge',
            'BASELINE_MAX_AGE',
            'mintBaselineVerified',
            '?',
        ];
        foreach ($forbidden as $token) {
            self::assertStringNotContainsString(
                $token,
                $call,
                "the baseline argument must not be conditioned on {$token}"
            );
        }

        self::assertSame(
            '$isPartialFetch = self::isPartialFetch( $returnedCount, $knownCount, $baseline );',
            preg_replace('/\s+/', ' ', $call),
            'the call site must pass the raw counts and the RAW baseline'
        );

        // ⛔⛔ THE VALUE, NOT THE SPELLING - see the docblock. The two
        // assertions above both pin the call's text, and the defect can be
        // restored one line higher without touching it.
        [$from, $to] = self::blockSpan(
            $code,
            'public static function index_validators(): void'
        );
        $body = substr($code, $from, $to - $from);

        preg_match_all(
            '/\$baseline\s*(?:[.+\-*\/%]|\?\?)?=(?!=)([^;]*);/',
            $body,
            $assignments
        );

        self::assertCount(
            1,
            $assignments[0],
            '$baseline must be assigned EXACTLY ONCE in index_validators(): a '
                . 'second assignment re-gates it on age without touching the call'
        );
        self::assertSame(
            'self::baselineTotal($prevEntry)',
            trim($assignments[1][0]),
            'that one assignment must be the RAW read, unconditioned'
        );
    }

    /**
     * ⚠⚠ THE WARNING MUST NAME THE NUMBER THE RULE COMPARED.
     *
     * It logged $knownCount as "expected ~N" after the baseline became the
     * comparison basis, so a chain returning 100 with 300 stored and a
     * baseline of 500 was reported as "expected ~300" while the rule had
     * compared against 500. That is not cosmetic: clearing last_good_total
     * is the operator's only remedy for a sustained flag - the accepted cost
     * of applying detection at any baseline age - and the log was the one
     * place that cost surfaces.
     *
     * ⚠ It also claimed the skip was "for this chain". $hasPartialFetch is
     * run-wide and markDeadValidators() has no chain_id, so one partial chain
     * withholds the sweep for every chain.
     *
     * ⚠ This test exists because a mutation control found the fix had NO
     * assertion at all - the same shape of gap this PR is about.
     */
    public function testThePartialWarningNamesTheBaselineAndTheRunWideSkip(): void
    {
        $call = self::statementSpan(
            self::code(self::source()),
            "'[Onchain] Partial fetch detected"
        );

        self::assertStringContainsString('against baseline %s', $call);
        self::assertStringContainsString('$baseline', $call, 'the baseline must be an argument');
        self::assertStringContainsString(
            '$knownCount',
            $call,
            'the stored count stays too - it is what floor A compares'
        );
        self::assertStringContainsString('WHOLE RUN', $call);

        self::assertStringNotContainsString(
            'expected ~',
            $call,
            'the stored count is no longer what the rule expects'
        );
        self::assertStringNotContainsString(
            'for this chain',
            $call,
            'the dead-marking skip is run-wide, not per-chain'
        );

        // ⚠⚠ AND IN THE RIGHT SLOT. Every assertion above tests token
        // PRESENCE, which says nothing about order - so swapping the baseline
        // and the stored count past each other satisfies all of them while the
        // log reports the stored count AS the baseline. That is the exact
        // confusion this fix exists to remove, so the slots are pinned.
        $flat   = (string) preg_replace('/\s+/', ' ', $call);
        $needle = "WHOLE RUN.',";
        $cut    = strpos($flat, $needle);
        self::assertNotFalse($cut, 'the format must end with the run-wide sentence');

        preg_match_all('/%[sd]/', substr($flat, 0, (int) $cut), $placeholders);
        self::assertSame(
            ['%s', '%d', '%s', '%d'],
            $placeholders[0],
            'chain, returned, baseline, stored - the baseline is the SECOND %s'
        );

        $tail = substr($flat, (int) $cut + strlen($needle));
        $tail = rtrim(rtrim(trim($tail), ';'), ')');
        self::assertSame(
            [
                '$chain->name',
                '$returnedCount',
                '$baseline > 0 ? (string) $baseline : \'absent\'',
                '$knownCount',
            ],
            array_map('trim', explode(',', $tail)),
            'the baseline is argument 3, against the second %s'
        );
    }

    // ── (1) corrected detection cannot mark validators inactive ─────────

    public function testDeadMarkingNeedsItsOwnSwitchOnTopOfTheRunWideFlag(): void
    {
        $code = self::code(self::source());

        self::assertStringContainsString(
            'if (!$hasPartialFetch && self::deadMarkingEnabled()) {',
            $code,
            'both gates must pass; correcting detection must not arm the sweep'
        );
        self::assertSame(
            1,
            substr_count($code, 'EnrichmentScheduler::markDeadValidators();'),
            'exactly one dead-marking call site'
        );

        [$start, $end] = self::blockSpan($code, 'if (!$hasPartialFetch && self::deadMarkingEnabled())');
        $branch = substr($code, $start, $end - $start);
        self::assertStringContainsString('EnrichmentScheduler::markDeadValidators();', $branch);
    }

    public function testTheSwitchDefaultsOff(): void
    {
        $code = self::code(self::source());

        self::assertStringContainsString(
            "get_option(self::DEAD_MARKING_OPTION, false)",
            $code,
            'the default must be false, so an absent option means off'
        );
        self::assertSame(
            'bcc_onchain_dead_marking_enabled',
            self::reflect()->getConstant('DEAD_MARKING_OPTION')
        );
    }

    /**
     * ⚠ A closed truthy allowlist, never a (bool) cast. Casting the strings
     * 'off' and 'false' both yield TRUE, which would arm a destructive sweep
     * on a value meaning the opposite.
     */
    public function testTheSwitchUsesAClosedTruthyAllowlistNotACast(): void
    {
        $code = self::code(self::source());

        self::assertSame([true, 1, '1'], self::reflect()->getConstant('DEAD_MARKING_TRUTHY'));
        self::assertStringContainsString('if ($raw === $on) {', $code, 'strict comparison');

        [$start, $end] = self::blockSpan($code, 'private static function deadMarkingEnabled(): bool');
        $body = substr($code, $start, $end - $start);
        self::assertStringNotContainsString('(bool)', $body);
        self::assertStringNotContainsString('!empty', $body);
    }

    /**
     * ⭐ THE SWITCH, EXECUTED - not read.
     *
     * Every other assertion about deadMarkingEnabled() above is a source
     * SPELLING check, which is the same gap that hid the staleness defect:
     * a mutant making this helper `return true;` unconditionally changes
     * bytes, arms a destructive global sweep, and passes a suite that only
     * reads the file. This case runs it.
     *
     * ⚠ The unit bootstrap defines NO get_option, so the helper could not
     * be invoked at all until tests/Stubs/dead-marking-switch-stubs.php
     * supplied a namespaced one. That file documents why it is namespaced
     * rather than global, and why $strict is mandatory here.
     *
     * @param mixed $stored
     */
    #[DataProvider('switchValues')]
    public function testOnlyAnExplicitTruthyValueEnablesDeadMarking(
        string $label,
        bool $present,
        $stored,
        bool $expected
    ): void {
        require_once dirname(__DIR__) . '/Stubs/dead-marking-switch-stubs.php';

        DeadMarkingOptionStub::reset();
        DeadMarkingOptionStub::$strict = true;
        if ($present) {
            DeadMarkingOptionStub::set('bcc_onchain_dead_marking_enabled', $stored);
        }

        try {
            $m = self::reflect()->getMethod('deadMarkingEnabled');
            $m->setAccessible(true);

            self::assertSame($expected, $m->invoke(null), $label);

            // ⚠ ANTI-VACUITY. A stub that was never consulted would answer
            // every row "off" - the four enabling rows would fail, but the
            // disabling rows would pass FOR THE WRONG REASON. So prove the
            // read reached the stub on every row that registered a value.
            if ($present) {
                self::assertSame(
                    1,
                    DeadMarkingOptionStub::$reads,
                    "{$label}: the switch was not actually read"
                );
            }
        } finally {
            // ⚠ Restore transparency even on failure: this stub intercepts
            // EVERY unqualified get_option() in the service namespace, and the
            // suite shares one process.
            DeadMarkingOptionStub::reset();
        }
    }

    /** @return list<array{0:string,1:bool,2:mixed,3:bool}> */
    public static function switchValues(): array
    {
        return [
            // the only three values that may arm it
            ['boolean true',          true,  true,     true],
            ['integer 1',             true,  1,        true],
            ["string '1'",            true,  '1',      true],

            // absent is the shipped state, and it must read OFF
            ['absent',                false, null,     false],

            // ⛔ the cast trap: (bool) makes BOTH of these true
            ["string 'off'",          true,  'off',    false],
            ["string 'false'",        true,  'false',  false],
            ["string 'no'",           true,  'no',     false],

            // ⚠⚠ 'on' IS WHAT AN ADMIN CHECKBOX POSTS, and it stays OFF.
            // (bool) 'on' is true, so a cast would arm the sweep on it. The
            // allowlist is CLOSED deliberately rather than widened to the
            // spellings a UI might send: whatever writes this option writes
            // true, 1 or '1', and a value that merely LOOKS affirmative is
            // not guessed at. An option meaning "mark validators dead" is
            // the wrong place to be generous about input.
            ["string 'on'",           true,  'on',     false],

            // ordinary falsy values
            ['boolean false',         true,  false,    false],
            ['integer 0',             true,  0,        false],
            ["string '0'",            true,  '0',      false],
            ["empty string",          true,  '',       false],
            ['explicit null',         true,  null,     false],

            // ⚠ near misses a loose comparison would admit
            ["string 'true'",         true,  'true',   false],
            ["string 'yes'",          true,  'yes',    false],
            ['float 1.0',             true,  1.0,      false],
            ["string '1' + space",    true,  '1 ',     false],
            ["string '01'",           true,  '01',     false],
            ['integer 2',             true,  2,        false],
            ['integer -1',            true,  -1,       false],
            ['array [1]',             true,  [1],      false],
        ];
    }

    public function testTheMintPathAndDetectionNeverMarkAnythingInactive(): void
    {
        $code = self::code(self::source());

        // the detection block itself must not touch status at all
        [$start, $end] = self::blockSpan($code, 'if (!empty($validators))');
        $branch = substr($code, $start, $end - $start);

        self::assertStringNotContainsString('markDeadValidators', $branch);
        self::assertStringNotContainsString("'inactive'", $branch);
    }

    /** The sweep's SQL is global; this change must not have scoped it. */
    public function testTheSweepSqlIsUntouchedAndStillGlobal(): void
    {
        $repo = file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Repositories/ValidatorRepository.php'
        );
        self::assertNotFalse($repo);

        [$s, $e] = self::blockSpan((string) $repo, 'function markDeadValidators(int $maxAttempts)');
        $body = substr((string) $repo, $s, $e - $s);

        self::assertStringContainsString("SET status = 'inactive'", $body);
        self::assertStringNotContainsString('chain_id', $body);
    }

    // ── (2) sudden and gradual drops are still detected ─────────────────

    /** @return list<array{0:string,1:int,2:int,3:int,4:bool}> */
    public static function dropCases(): array
    {
        return [
            // label, returned, known, baseline, expectPartial
            ['steady: 180 against a 180 baseline',        180, 318, 180, false],
            ['sudden: 100 against a 180 baseline',        100, 318, 180, true],
            ['at the exact 70% edge (126 of 180)',        126, 318, 180, false],
            ['one below the exact edge (125 of 180)',     125, 318, 180, true],
            ['catastrophic: 40 returned, 318 known',       40, 318, 180, true],
            ['catastrophic floor with NO baseline',        40, 318,   0, true],
            ['first index, suspiciously small (12)',       12,   0,   0, true],
            ['first index, plausible (45)',                45,   0,   0, false],
            ['gradual: 5% down from baseline',            171, 318, 180, false],
            ['gradual: 31% down from baseline',           124, 318, 180, true],
        ];
    }

    #[DataProvider('dropCases')]
    public function testDropDetection(
        string $label,
        int $returned,
        int $known,
        int $baseline,
        bool $expected
    ): void {
        self::assertSame(
            $expected,
            self::isPartial($returned, $known, $baseline),
            $label
        );
    }

    /**
     * ⚠ THE GRADUAL CASE IS ONLY PARTLY COVERED, AND THAT IS DELIBERATE.
     * A run-to-run comparison cannot see slow erosion: each step is inside
     * 70% of the one before, so the baseline walks down with the chain. The
     * absolute floors are what eventually catch it, and they only fire at
     * `known > 200 && returned < 50`. This test pins the limitation so it is
     * a known, documented gap rather than an assumed safety property.
     */
    public function testSlowErosionWalksTheBaselineDownUntilAFloorCatchesIt(): void
    {
        $returned = 300;
        $baseline = 300;
        $known    = 400;
        $steps    = 0;

        // 10% down each run: never trips the relative test
        while ($steps < 40) {
            $returned = (int) floor($returned * 0.9);
            if (self::isPartial($returned, $known, $baseline)) {
                break;
            }
            $baseline = $returned; // a clean run advances the baseline
            $steps++;
        }

        self::assertTrue(
            self::isPartial($returned, $known, $baseline),
            'erosion must eventually be caught'
        );
        self::assertLessThan(
            50,
            $returned,
            'and it is the ABSOLUTE FLOOR that catches it, not the relative test'
        );
    }

    // ── (3) stale stats must not make a chain look healthy ──────────────

    /**
     * ⭐ AN OLD BASELINE STILL CATCHES A LARGE DROP.
     *
     * This test asserted the OPPOSITE until the review caught it, and the
     * assertion it made was the defect written down: "a stale baseline is
     * ignored, not used in the comparison". Ignoring it is not the
     * conservative direction. For 100 returned against a 500 baseline with 300
     * stored, NO floor applies - floor A needs fewer than 50 returned, floor B
     * needs a first index - so ignoring the only applicable rule returns
     * CLEAN, and the clean verdict then rewrites the baseline to 100.
     *
     * Age is no longer an input to detection at all, which is why the mirror
     * has no parameter for it.
     */
    public function testAnOldBaselineStillCatchesALargeDrop(): void
    {
        // an 80% drop, caught on the strength of the baseline EXISTING
        self::assertTrue(self::isPartial(100, 300, 500), '80% drop must be caught');

        // the floors still bite independently where they apply
        self::assertTrue(self::isPartial(40, 300, 500), 'the floor still applies');

        // and an ABSENT baseline still skips the relative test, which is what
        // keeps a first index able to establish one rather than deadlocking
        self::assertFalse(
            self::isPartial(100, 300, 0),
            'no baseline means no relative test, so no deadlock'
        );
    }

    public function testMintEligibilityNeedsBothATotalAndAFreshStamp(): void
    {
        $code = self::code(self::source());

        // the chain of custody, link by link: a null age cannot be fresh, and
        // minting requires freshness. Section (3a) drives baselineAge() itself.
        self::assertStringContainsString('$baselineFresh = $baselineAge !== null', $code);
        self::assertStringContainsString(
            '&& $baselineAge <= self::BASELINE_MAX_AGE;',
            $code
        );
        self::assertStringContainsString(
            '$mintBaselineVerified = $baseline > 0 && $baselineFresh;',
            $code,
            'minting needs a total AND a fresh stamp'
        );
        self::assertSame(604800, self::reflect()->getConstant('BASELINE_MAX_AGE'));
    }

    /**
     * ⚠ A LITERAL, not `7 * DAY_IN_SECONDS`. A class-constant initialiser is
     * evaluated on first ACCESS and an unqualified constant resolves in this
     * NAMESPACE before global, so the WP-constant form loads fine and then
     * fatals the moment detection reads it wherever WP is not fully booted.
     */
    public function testTheFreshnessWindowDoesNotDependOnAWordPressConstant(): void
    {
        // ⚠ A POSITIVE assertion. The negative form this replaced only
        // rejected one exact spelling, so DAY_IN_SECONDS * 7, or the same
        // expression without spaces, sailed through - and an innocent comment
        // containing the phrase failed it. Requiring a bare integer literal
        // admits no spelling of the hazard.
        self::assertMatchesRegularExpression(
            '/private const BASELINE_MAX_AGE = \d+;/',
            self::code(self::source()),
            'the window must be a literal integer'
        );
    }

    // ── (3a) the baseline helpers, driven BEHAVIOURALLY ─────────

    /**
     * ⛔⛔⛔ THIS SECTION EXISTS BECAUSE ITS ABSENCE HID A DEFECT.
     *
     * Every case above drives a LOCAL MIRROR of the predicate. That is fine
     * for the predicate, which is pure arithmetic - but it meant nothing ever
     * executed the code that decides whether a baseline is usable. The rule
     * was pinned by source SPELLING only, which is why mutation control 8
     * ("drop the baseline staleness check") killed exactly one assertion: a
     * string match. No behaviour died.
     *
     * What hid there: the old spelling was
     * strtotime((string) $raw . ' UTC'), and strtotime(' UTC') returns NOW,
     * measured on PHP 8.2.30. So an empty stamp produced a PERMANENTLY fresh
     * baseline and the staleness refusal never fired. These cases invoke the
     * real helpers through reflection.
     */
    private const REF_NOW = '2026-11-02 12:00:00';

    private static function refNow(): int
    {
        return (new \DateTimeImmutable(
            self::REF_NOW,
            new \DateTimeZone('UTC')
        ))->getTimestamp();
    }

    /** @param array<string, mixed> $entry */
    private static function callTotal(array $entry): int
    {
        $m = self::reflect()->getMethod('baselineTotal');
        $m->setAccessible(true);

        return (int) $m->invoke(null, $entry);
    }

    /** @param array<string, mixed> $entry */
    private static function callAge(array $entry): ?int
    {
        $m = self::reflect()->getMethod('baselineAge');
        $m->setAccessible(true);
        $r = $m->invoke(null, $entry, self::refNow());

        return $r === null ? null : (int) $r;
    }

    /**
     * Composes the two links this file measures separately: a null or
     * over-age result cannot be fresh, and the source requires freshness to
     * mint (testMintEligibilityNeedsBothATotalAndAFreshStamp pins that).
     *
     * @param array<string, mixed> $entry
     */
    private static function callMintEligible(array $entry): bool
    {
        $age = self::callAge($entry);

        return self::callTotal($entry) > 0
            && $age !== null
            && $age <= 604800;
    }

    /** @return list<array{0:string,1:array<string,mixed>,2:int}> */
    public static function totalCases(): array
    {
        return [
            ['key absent',          [],                              0],
            ['null',                ['last_good_total' => null],     0],
            ['zero',                ['last_good_total' => 0],        0],
            ['negative',            ['last_good_total' => -5],       0],
            ['an array',            ['last_good_total' => []],       0],
            ['a float',             ['last_good_total' => 1.9],      0],
            ['a real int',          ['last_good_total' => 318],    318],
            ['a numeric string',    ['last_good_total' => '318'],  318],
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[DataProvider('totalCases')]
    public function testBaselineTotalFailsClosed(string $label, array $entry, int $expected): void
    {
        self::assertSame($expected, self::callTotal($entry), $label);
    }

    /**
     * ⭐ The timestamp table. Anything that is not an exact
     * `Y-m-d H:i:s` in the past is NULL - unusable - and null can never mint.
     *
     * @return list<array{0:string,1:array<string,mixed>,2:int|null}>
     */
    public static function ageCases(): array
    {
        return [
            // missing
            ['key absent',               [],                                           null],
            ['null',                     ['last_good_at' => null],                     null],

            // ⚠ the fail-open that hid here: '' . ' UTC' parsed as NOW
            ['empty string',             ['last_good_at' => ''],                       null],
            ['false (non-string)',       ['last_good_at' => false],                    null],
            ['int (non-string)',         ['last_good_at' => 0],                        null],

            // malformed
            ['the string zero',          ['last_good_at' => '0'],                      null],
            ['word salad',               ['last_good_at' => 'Array'],                  null],
            ['a bare timezone',          ['last_good_at' => ' UTC'],                   null],
            ['trailing junk',            ['last_good_at' => '2026-11-02 11:00:00 UTC'], null],
            ['ISO T separator',          ['last_good_at' => '2026-11-02T11:00:00'],    null],
            ['date only',                ['last_good_at' => '2026-11-02'],             null],
            ['impossible, rolls over',   ['last_good_at' => '2026-13-45 99:99:99'],    null],

            // ⭐ THE CASE THAT MAKES THE ROUND-TRIP CHECK BITE. Every other
            // malformed string above makes createFromFormat return false outright,
            // and 2026-13-45 rolls into the FUTURE, where the future-refusal
            // catches it anyway - so dropping the round-trip check passed the
            // whole suite. October 32nd rolls to 2026-11-01, age 129600s, which
            // reads FRESH and would authorize minting.
            ['rolls into the recent past', ['last_good_at' => '2026-10-32 00:00:00'],  null],

            // future - a negative age satisfies '<= MAX_AGE' and read as fresh
            ['one second ahead',         ['last_good_at' => '2026-11-02 12:00:01'],    null],
            ['far future',               ['last_good_at' => '2099-01-01 00:00:00'],    null],

            // valid
            ['the same instant',         ['last_good_at' => '2026-11-02 12:00:00'],       0],
            ['one minute old',           ['last_good_at' => '2026-11-02 11:59:00'],      60],
            ['exactly the window',       ['last_good_at' => '2026-10-26 12:00:00'],  604800],
            ['one second past it',       ['last_good_at' => '2026-10-26 11:59:59'],  604801],
        ];
    }

    /**
     * @param array<string, mixed> $entry
     */
    #[DataProvider('ageCases')]
    public function testBaselineAgeParsesStrictlyAndRefusesTheFuture(
        string $label,
        array $entry,
        ?int $expected
    ): void {
        self::assertSame($expected, self::callAge($entry), $label);
    }

    /**
     * ⛔⛔ THE REQUIREMENT, STATED AS ONE ASSERTION PER BAD STAMP:
     * a missing, malformed or future-dated timestamp cannot make a chain
     * eligible to mint. A real total is supplied in every row, so the only
     * thing under test is the stamp.
     */
    #[DataProvider('ageCases')]
    public function testOnlyAFreshPastStampCanAuthorizeMinting(
        string $label,
        array $entry,
        ?int $expectedAge
    ): void {
        $entry['last_good_total'] = 318;
        $eligible = self::callMintEligible($entry);

        if ($expectedAge === null || $expectedAge > 604800) {
            self::assertFalse($eligible, "{$label} must NOT authorize minting");

            return;
        }

        self::assertTrue($eligible, "{$label} is fresh and in the past, so it may mint");
    }

    /**
     * ⚠ And a fresh stamp is not enough on its own - the total must be
     * real too, or a chain with a parseable stamp and no comparable count
     * would mint on nothing.
     */
    public function testAFreshStampWithoutATotalCannotMint(): void
    {
        self::assertFalse(self::callMintEligible(['last_good_at' => self::REF_NOW]));
        self::assertFalse(self::callMintEligible([
            'last_good_at'    => self::REF_NOW,
            'last_good_total' => 0,
        ]));
        self::assertTrue(self::callMintEligible([
            'last_good_at'    => self::REF_NOW,
            'last_good_total' => 318,
        ]), 'both present and fresh is the only eligible shape');
    }

    /**
     * ⭐⭐ THE REGRESSION GUARD FOR THE WHOLE FIX, END TO END.
     *
     * An aged baseline must still DETECT while being refused for MINTING.
     * Before the fix these were one flag, so the aged case lost both at once:
     * a chain truncated for a week was declared clean and then minted off the
     * truncated count it had just written back.
     */
    public function testAnAgedBaselineDetectsButDoesNotAuthorizeMinting(): void
    {
        $aged = ['last_good_total' => 318, 'last_good_at' => '2026-10-20 12:00:00'];

        $age = self::callAge($aged);
        self::assertNotNull($age);
        self::assertGreaterThan(604800, $age, 'the fixture must really be over the window');

        // refused for minting
        self::assertFalse(self::callMintEligible($aged), 'an aged baseline may not mint');

        // and STILL detects - this is the assertion the defect inverted
        self::assertTrue(
            self::isPartial(180, 318, self::callTotal($aged)),
            'an aged baseline must still catch a 43% drop'
        );
    }

    // ── (4) the baseline is written only on a clean fetch ───────────────

    public function testTheBaselineAdvancesOnlyOnACleanFetch(): void
    {
        $code = self::code(self::source());

        // occurrence 1: the baseline write, which precedes the healthy-chain
        // collection in the file
        [$start, $end] = self::blockSpan($code, 'if (!$isPartialFetch) {', 1);
        $branch = substr($code, $start, $end - $start);

        self::assertStringContainsString("\$entry['last_good_total'] = \$returnedCount;", $branch);
        self::assertStringContainsString("\$entry['last_good_at']    = current_time('mysql', true);", $branch);
    }

    /**
     * ⛔⛔ THE WRITER MUST NOT PERSIST WHAT THE READER WOULD REFUSE.
     *
     * This replaces two assertStringContainsString checks on the two `isset`
     * lines. They were satisfied by the exact code that carried the defect, so
     * they could not have caught it; the property is now asserted by VALUE.
     *
     * The defect: baselineTotal() refuses anything that is not int|string and
     * returns 0, while the writer cast unconditionally. Measured on PHP 8.2,
     * (int) [1,2], (int) 1.9 and (int) true are ALL 1 - so a malformed value
     * became a baseline of 1, which is > 0 and therefore authorizes minting,
     * and which no relative test can trip because returned * 10 < 1 * 7 needs
     * returned < 1.
     *
     * ⚠ The assertion that generalises is the LAST one: whatever goes in, the
     * reader must see the same baseline before and after the write. A future
     * cast reintroduced anywhere in the carry-forward breaks that row without
     * needing a new case for the specific type.
     *
     * @param mixed $stored
     */
    #[DataProvider('malformedTotalCases')]
    public function testAMalformedBaselineIsNotLaunderedIntoAValidOne(
        string $label,
        $stored,
        bool $carried
    ): void {
        $prev = [
            'last_good_total' => $stored,
            'last_good_at'    => '2026-11-03 00:00:00',
        ];

        $entry = self::compose($prev, 180, true, '2026-11-03 04:00:00');

        if ($carried) {
            self::assertSame(318, $entry['last_good_total'], $label . ': carried');
        } else {
            self::assertArrayNotHasKey(
                'last_good_total',
                $entry,
                $label . ': a value detection refuses must not be persisted either'
            );
        }

        self::assertSame(
            self::callTotal($prev),
            self::callTotal($entry),
            $label . ': the reader must see the SAME baseline before and after the write'
        );
    }

    /** @return list<array{0:string,1:mixed,2:bool}> */
    public static function malformedTotalCases(): array
    {
        return [
            // ⛔ The three that used to launder into a baseline of 1.
            ['a non-empty array',  [1, 2], false],
            ['a float',            1.9,    false],
            ['boolean true',       true,   false],
            // Refused by the reader for other reasons, and now dropped too.
            ['an empty array',     [],     false],
            ['a non-numeric string', 'Array', false],
            ['zero',               0,      false],
            ['negative',           -5,     false],
            ['null',               null,   false],
            // The well-formed pair, unchanged: these must still carry.
            ['a real int',         318,    true],
            ['a numeric string',   '318',  true],
        ];
    }

    /**
     * ⚠ THE STAMP MUST NOT BE MANUFACTURED EITHER. (string) false gave '' and
     * (string) [] gave 'Array' behind a conversion notice - values the writer
     * invented and the reader then had to refuse. Only a non-empty string is
     * carried, verbatim.
     *
     * @param mixed $stored
     */
    #[DataProvider('malformedStampCases')]
    public function testAMalformedBaselineStampIsDroppedRatherThanManufactured(
        string $label,
        $stored,
        ?string $expected
    ): void {
        $prev = [
            'last_good_total' => 318,
            'last_good_at'    => $stored,
        ];

        $entry = self::compose($prev, 180, true, '2026-11-03 04:00:00');

        if ($expected === null) {
            self::assertArrayNotHasKey('last_good_at', $entry, $label);
        } else {
            self::assertSame($expected, $entry['last_good_at'], $label);
        }

        // ⚠ The total and the stamp are carried INDEPENDENTLY, so a bad stamp
        // must not cost the chain its total - it costs it only minting
        // authority, which is the documented split.
        self::assertSame(318, $entry['last_good_total'], $label . ': the total still carries');
    }

    /** @return list<array{0:string,1:mixed,2:?string}> */
    public static function malformedStampCases(): array
    {
        return [
            ['boolean false',     false,                 null],
            ['an array',          ['2026-11-03'],        null],
            ['an int',            0,                     null],
            ['the empty string',  '',                    null],
            ['null',              null,                  null],
            // Carried verbatim - including a string the PARSER will refuse,
            // because that refusal is baselineAge()'s call and already fails
            // closed as not-fresh.
            ['word salad',        'Array',               'Array'],
            ['a real stamp',      '2026-11-03 00:00:00', '2026-11-03 00:00:00'],
        ];
    }

    /** The stored `total` must never be read as the comparison baseline. */
    public function testTheWrittenTotalIsNotUsedAsTheBaseline(): void
    {
        $code = self::code(self::source());

        self::assertStringNotContainsString("\$prevEntry['total']", $code);
        self::assertStringContainsString("\$prevEntry['last_good_total']", $code);
    }

    /** The baseline snapshot must be taken before any chain is rewritten. */
    public function testThePriorStatsSnapshotIsTakenOnceBeforeTheLoop(): void
    {
        $code = self::code(self::source());

        $snapshot = strpos($code, "\$priorStats = get_option('bcc_onchain_indexer_stats', []);");
        $loop     = strpos($code, 'foreach ($chains as $chain) {');

        self::assertNotFalse($snapshot);
        self::assertNotFalse($loop);
        self::assertLessThan(
            $loop,
            $snapshot,
            'the snapshot must precede the loop, or a chain could read an entry this run wrote'
        );
    }
    // ── (5) no minting without an independently verified baseline ──────

    /**
     * ⚠⚠ A CLEAN VERDICT IS NOT EVIDENCE THE FETCH WAS COMPLETE.
     *
     * Where the baseline is missing or stale the relative test does not run,
     * and the floors are narrow and CONDITIONAL — floor A only applies above
     * 200 stored rows, floor B only on a first index. Measured 2026-11-02:
     * jackal returned 24 of 53 stored, juno 25 of 44, osmosis 30 of 96, and
     * NEITHER FLOOR APPLIES TO ANY OF THEM. Those chains are not verified
     * healthy; no rule covers their shape.
     *
     * So page creation needs two agreeing observations: a baseline from an
     * earlier fetch that itself passed detection, plus a current fetch that
     * passes against it.
     */
    public function testMintingRequiresAVerifiedBaselineNotJustACleanRun(): void
    {
        $code = self::code(self::source());

        [$start, $end] = self::blockSpan($code, 'if ($mintBaselineVerified) {');
        $branch = substr($code, $start, $end - $start);

        self::assertStringContainsString(
            '$healthyChainFetches[$chainId] = ValidatorRepository::idsForOperatorAddresses(',
            $branch,
            'the allowlist may only be built for a chain with a verified baseline'
        );
        self::assertSame(
            1,
            substr_count($code, '$healthyChainFetches[$chainId] ='),
            'exactly one site may make a chain mint-eligible'
        );
    }

    /**
     * ⛔ Indexing must NOT be withheld. The baseline is only written on a
     * clean run, so blocking the index would stop any baseline ever forming —
     * the deadlock this change exists to remove.
     */
    public function testIndexingAndBaselineWritingAreNotWithheld(): void
    {
        $code = self::code(self::source());

        // recordSuccess and the baseline write sit OUTSIDE the mint gate
        [$mintStart, $mintEnd] = self::blockSpan($code, 'if ($mintBaselineVerified) {');
        $mintBranch = substr($code, $mintStart, $mintEnd - $mintStart);

        self::assertStringNotContainsString('recordSuccess', $mintBranch);
        self::assertStringNotContainsString('last_good_total', $mintBranch);
        self::assertStringNotContainsString('bulkUpsert', $mintBranch);

        // and they are still present in the file
        self::assertStringContainsString('OnchainCircuitBreaker::recordSuccess($chainId);', $code);
        self::assertStringContainsString("\$entry['last_good_total'] = \$returnedCount;", $code);
    }

    /** A withheld chain must say so, not fail silently. */
    public function testWithholdingIsLoggedWithTheReason(): void
    {
        $src = self::source();

        self::assertStringContainsString('page creation is withheld', $src);

        // three reasons, not two: a stamp that will not PARSE is a distinct
        // fact from one that is merely old, and conflating them hid the
        // strtotime(' UTC') fail-open.
        self::assertStringContainsString("? 'absent'", $src);
        self::assertStringContainsString("'unreadable'", $src);
        self::assertStringContainsString("'stale'", $src);
    }

    /**
     * The three uncovered chains, as a table. This is the regression guard
     * for the claim that was wrong before: they are NOT caught by any floor,
     * so the safety has to come from withholding minting, not from detection.
     *
     * @return list<array{0:string,1:int,2:int}>
     */
    public static function uncoveredByAnyFloor(): array
    {
        return [
            ['jackal',  24, 53],
            ['juno',    25, 44],
            ['osmosis', 30, 96],
        ];
    }

    #[DataProvider('uncoveredByAnyFloor')]
    public function testTheseChainsAreNotCaughtByAnyFloorSoMintingMustBeWithheld(
        string $chain,
        int $returned,
        int $known
    ): void {
        // no baseline => the relative test does not apply
        self::assertFalse(
            self::isPartial($returned, $known, 0),
            "{$chain}: no floor covers it, so detection alone calls it clean"
        );

        // ⚠ The companion assertion that used to sit here was identical
        // for all three rows and had nothing to do with the row, inflating the
        // count 3x without adding coverage. The structural fact it claimed is
        // covered once, by testMintingRequiresAVerifiedBaselineNotJustACleanRun.
    }

    /**
     * ⚠ And the floors cannot simply be tightened: kujira legitimately
     * returns 8 validators and dungeon 19, so an unconditional floor at 30 or
     * 50 would permanently flag real chains. Pinned so nobody "fixes" the
     * floors by breaking the small chains.
     */
    public function testSmallChainsAreLegitimateAndMustNotTripAFloor(): void
    {
        self::assertFalse(self::isPartial(8, 10, 8), 'kujira: 8 of 10 is real');
        self::assertFalse(self::isPartial(19, 27, 19), 'dungeon: 19 of 27 is real');
    }
    /**
     * ⛔⛔ THE WRITE PATH, EXECUTED ACROSS RUNS RATHER THAN MATCHED AS TEXT.
     *
     * An independent review found the gap these cases close: carry-forward and
     * clean-only advance are the only two statements in this change that mutate
     * persistent state, and every assertion that could see them was
     * assertStringContainsString. It named two mutants that passed the whole
     * suite, and both are now killed by VALUE here:
     *
     *   A. carry-forward bodies emptied  -> a partial run loses the baseline,
     *      so the truncated count becomes the next reference. That is the
     *      ratchet this change exists to remove, restored.
     *   B. carry-forward moved BELOW the advance -> the carried value
     *      overwrites the fresh one, the baseline freezes at its first-ever
     *      value, and every chain's minting is withheld once that stamp ages
     *      past BASELINE_MAX_AGE.
     *
     * ⚠ Mutant B is invisible to any single-run test. It only shows up on the
     * SECOND clean run, when there is a carried value available to clobber the
     * fresh one with - which is why these cases chain runs instead of asserting
     * on one.
     */
    public function testACleanRunWritesTheBaselineFromTheFetchCount(): void
    {
        $run = self::compose([], 318, false, '2026-11-03 00:00:00');

        self::assertSame(318, $run['last_good_total'], 'from the FETCH count');
        self::assertSame('2026-11-03 00:00:00', $run['last_good_at']);
        self::assertFalse($run['partial']);
    }

    /** Kills mutant A: an emptied carry-forward drops the key entirely. */
    public function testAPartialRunCarriesTheBaselineForwardInsteadOfDroppingIt(): void
    {
        $clean   = self::compose([], 318, false, '2026-11-03 00:00:00');
        $partial = self::compose($clean, 180, true, '2026-11-03 04:00:00');

        self::assertArrayHasKey(
            'last_good_total',
            $partial,
            'without the carry-forward the key is GONE, and a gone baseline '
                . 'disables the relative test on the next run'
        );
        self::assertSame(
            318,
            $partial['last_good_total'],
            'a partial run keeps the last CLEAN total and must not adopt its own 180'
        );
        self::assertSame(
            '2026-11-03 00:00:00',
            $partial['last_good_at'],
            'and the clean run\'s stamp, not this run\'s time'
        );
        self::assertTrue($partial['partial']);
        self::assertSame(180, $partial['total'], 'the run of record still reports THIS fetch');
    }

    /** Kills mutant B: the two blocks in the wrong order freeze the baseline. */
    public function testASecondCleanRunAdvancesTheBaselineRatherThanRestoringTheCarriedOne(): void
    {
        $first  = self::compose([], 318, false, '2026-11-03 00:00:00');
        $middle = self::compose($first, 180, true, '2026-11-03 04:00:00');
        $second = self::compose($middle, 330, false, '2026-11-03 08:00:00');

        self::assertSame(
            330,
            $second['last_good_total'],
            'ORDER IS LOAD-BEARING: carry-forward must precede the advance. Moved '
                . 'below it, the carried 318 overwrites the fresh 330 and the '
                . 'baseline never moves again'
        );
        self::assertSame(
            '2026-11-03 08:00:00',
            $second['last_good_at'],
            'the stamp must advance too, or every chain is withheld once it ages out'
        );
    }

    /**
     * The whole property in one sequence: the baseline tracks the most recent
     * CLEAN fetch and is untouched by everything else.
     */
    public function testTheBaselineSurvivesASequenceOfRunsAndOnlyACleanRunMovesIt(): void
    {
        $runs = [
            // [returned, isPartial, now,                   expected total, expected stamp]
            [318, false, '2026-11-03 00:00:00', 318, '2026-11-03 00:00:00'],
            [180, true,  '2026-11-03 04:00:00', 318, '2026-11-03 00:00:00'],
            [120, true,  '2026-11-03 08:00:00', 318, '2026-11-03 00:00:00'],
            [330, false, '2026-11-03 12:00:00', 330, '2026-11-03 12:00:00'],
            [50,  true,  '2026-11-03 16:00:00', 330, '2026-11-03 12:00:00'],
            [340, false, '2026-11-03 20:00:00', 340, '2026-11-03 20:00:00'],
        ];

        $entry = [];
        foreach ($runs as $i => [$returned, $partial, $now, $total, $stamp]) {
            $entry = self::compose($entry, $returned, $partial, $now);

            self::assertSame($total, $entry['last_good_total'], "run {$i}: baseline total");
            self::assertSame($stamp, $entry['last_good_at'], "run {$i}: baseline stamp");
        }

        // ⚠ And the baseline the LAST run leaves behind is the one detection
        // would use, read back through the production reader rather than asserted
        // on the raw array.
        self::assertSame(340, self::callTotal($entry));
    }

    /**
     * ⚠ The run-of-record fields must NOT be carried. If `timestamp` and
     * `last_good_at` agree on a partial run, the carry-forward did not happen -
     * which is mutant A seen from the other side.
     */
    public function testTheRunOfRecordFieldsAreWrittenEveryRunAndNeverCarried(): void
    {
        $clean   = self::compose([], 318, false, '2026-11-03 00:00:00');
        $partial = self::compose($clean, 180, true, '2026-11-03 04:00:00');

        self::assertSame('2026-11-03 04:00:00', $partial['timestamp'], 'timestamp is THIS run');
        self::assertNotSame(
            $partial['timestamp'],
            $partial['last_good_at'],
            'timestamp moves every run while last_good_at moves only on a clean '
                . 'one, so on a partial run they MUST differ'
        );
        self::assertSame('Cosmos', $partial['chain']);
    }

    /**
     * Drive the production write path for one run.
     *
     * ⚠ $stats carries bulkUpsert's real shape, which deliberately has NO
     * last_good_* keys - that absence is why the carry-forward has to exist.
     *
     * @param  array<string, mixed> $prevEntry
     * @return array<string, mixed>
     */
    private static function compose(
        array $prevEntry,
        int $returnedCount,
        bool $isPartial,
        string $now
    ): array {
        require_once dirname(__DIR__) . '/Stubs/dead-marking-switch-stubs.php';

        DeadMarkingOptionStub::$now = $now;

        $m = self::reflect()->getMethod('composeStatsEntry');
        $m->setAccessible(true);

        /** @var array<string, mixed> $out */
        $out = $m->invoke(
            null,
            [
                'total'     => $returnedCount,
                'new'       => 0,
                'updated'   => 0,
                'unchanged' => $returnedCount,
                'refreshed' => 0,
            ],
            $prevEntry,
            'Cosmos',
            $returnedCount,
            $isPartial
        );

        return $out;
    }

    /**
     * ⛔⛔ AN ORDINARY FLUCTUATION ON A SMALL CHAIN IS NOT A TRUNCATION.
     *
     * An independent review found this, and it was a REGRESSION against
     * `main`: the old rule guarded the relative test with `$knownCount > 10`
     * and this change had dropped the guard, so a ratio was applied to a
     * handful of validators. Measured against the real predicate before the
     * fix, kujira - which this file's own comments name as legitimately
     * returning 8 - went PARTIAL the moment it returned 5.
     *
     * ⚠ AND THE FLAG WAS PERMANENT, not transient. A partial run never
     * advances the baseline, so the chain reported partial on every later run,
     * its placeholder minting stopped at once, and the run-wide
     * $hasPartialFetch was pinned true - which would block
     * markDeadValidators() for EVERY chain once the switch is armed. The only
     * exit was the manual procedure in docs/validator-baseline-recovery.md.
     */
    #[DataProvider('smallChainCases')]
    public function testASmallChainFluctuationIsNotPartial(
        string $label,
        int $returned,
        int $known,
        int $baseline,
        bool $expected
    ): void {
        self::assertSame($expected, self::isPartial($returned, $known, $baseline), $label);
    }

    /** @return list<array{0:string,1:int,2:int,3:int,4:bool}> */
    public static function smallChainCases(): array
    {
        return [
            // ⛔ THE REGRESSION CASE. Before the guard this returned TRUE.
            ['kujira returns 5 against a baseline of 8',      5,  10,   8, false],

            // The rest of that chain's ordinary range, all legitimate.
            ['kujira steady at 8',                            8,  10,   8, false],
            ['kujira returns 6 of 8',                          6,  10,   8, false],
            ['kujira returns 4 of 8',                          4,  10,   8, false],
            ['a four-validator chain halves',                  2,   4,   3, false],

            // ⚠ THE BOUNDARY, BOTH SIDES. The guard is a THRESHOLD, not a
            // blanket exemption for whatever feels small, so a baseline one
            // above the minimum must still be judged by ratio. Without the
            // second row here the fix could be widened to exempt everything
            // and the suite would not notice.
            ['at the minimum: 1 against a baseline of 10',     1,  20,  10, false],
            ['one above it: 7 against a baseline of 11',       7,  20,  11, true],

            // ⭐ LARGER CHAINS UNCHANGED - this is what the guard must not cost.
            ['dungeon returns 13 against a baseline of 19',   13,  27,  19, true],
            ['dungeon steady at 19',                          19,  27,  19, false],
            ['cosmos returns 120 against a baseline of 180', 120, 318, 180, true],
            ['cosmos steady at 180',                         180, 318, 180, false],

            // An absent baseline is still simply not compared.
            ['no baseline at all',                             5,  10,   0, false],
        ];
    }

    /**
     * ⚠ THE EXEMPTION KEYS ON THE BASELINE, NOT ON knownCount. knownCount
     * only grows - a validator that leaves the bonded set keeps its row - and
     * it is the number this whole change exists to stop depending on. So a
     * chain with many stale rows and a genuinely small current set must still
     * be exempt from the ratio.
     */
    public function testTheExemptionKeysOnTheBaselineNotOnTheStoredCount(): void
    {
        self::assertFalse(
            self::isPartial(5, 199, 8),
            'a small baseline is exempt even with many stale rows stored'
        );

        // ⚠ ...but the absolute floor is a SEPARATE rule and still fires,
        // which is what stops the exemption from becoming a hole.
        self::assertTrue(
            self::isPartial(5, 201, 8),
            'floor A still catches it once the stored count passes 200'
        );
    }
    /**
     * ⚠⚠ THE FROZEN CLOCK MUST NOT LEAK. The stub's current_time() wins for
     * every unqualified call reached from BCC\Trust\Onchain\Services, so a test
     * that left $now set would hand a fixed stamp to unrelated suites in that
     * namespace. Resetting here keeps it transparent outside these cases.
     */
    protected function tearDown(): void
    {
        if (class_exists(DeadMarkingOptionStub::class, false)) {
            DeadMarkingOptionStub::reset();
        }

        parent::tearDown();
    }
}
