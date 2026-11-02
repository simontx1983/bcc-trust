<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

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
     */
    private static function isPartial(
        int $returned,
        int $known,
        int $baseline,
        bool $baselineUsable
    ): bool {
        $relativeDrop  = $baselineUsable && ($returned * 10) < ($baseline * 7);
        $absoluteFloor = ($known > 200 && $returned < 50)
            || ($known === 0 && $returned > 0 && $returned < 30);

        return $relativeDrop || $absoluteFloor;
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

        self::assertStringContainsString(
            '$relativeDrop = $baselineUsable',
            $code,
            'the relative test must be gated on a usable baseline'
        );
        self::assertStringContainsString('($returnedCount * 10) < ($baseline * 7)', $code);
        self::assertStringContainsString('($knownCount > 200 && $returnedCount < 50)', $code);
        self::assertStringContainsString(
            '($knownCount === 0 && $returnedCount > 0 && $returnedCount < 30)',
            $code
        );
        self::assertStringContainsString('$isPartialFetch = $relativeDrop || $absoluteFloor;', $code);
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

    /** @return list<array{0:string,1:int,2:int,3:int,4:bool,5:bool}> */
    public static function dropCases(): array
    {
        return [
            // label, returned, known, baseline, baselineUsable, expectPartial
            ['steady: 180 against a 180 baseline',        180, 318, 180, true,  false],
            ['sudden: 100 against a 180 baseline',        100, 318, 180, true,  true],
            ['at the exact 70% edge (126 of 180)',        126, 318, 180, true,  false],
            ['one below the exact edge (125 of 180)',     125, 318, 180, true,  true],
            ['catastrophic: 40 returned, 318 known',       40, 318, 180, true,  true],
            ['catastrophic floor with NO baseline',        40, 318,   0, false, true],
            ['first index, suspiciously small (12)',       12,   0,   0, false, true],
            ['first index, plausible (45)',                45,   0,   0, false, false],
            ['gradual: 5% down from baseline',            171, 318, 180, true,  false],
            ['gradual: 31% down from baseline',           124, 318, 180, true,  true],
        ];
    }

    #[DataProvider('dropCases')]
    public function testDropDetection(
        string $label,
        int $returned,
        int $known,
        int $baseline,
        bool $usable,
        bool $expected
    ): void {
        self::assertSame(
            $expected,
            self::isPartial($returned, $known, $baseline, $usable),
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
            if (self::isPartial($returned, $known, $baseline, true)) {
                break;
            }
            $baseline = $returned; // a clean run advances the baseline
            $steps++;
        }

        self::assertTrue(
            self::isPartial($returned, $known, $baseline, true),
            'erosion must eventually be caught'
        );
        self::assertLessThan(
            50,
            $returned,
            'and it is the ABSOLUTE FLOOR that catches it, not the relative test'
        );
    }

    // ── (3) stale stats must not make a chain look healthy ──────────────

    public function testAStaleBaselineIsIgnoredRatherThanTrusted(): void
    {
        // 100 returned against a 500 baseline is a 80% drop. With a usable
        // baseline that is partial; with a STALE one the relative test is not
        // applied, so the verdict must come from the floors alone — it must
        // never be granted health BY the stale number.
        self::assertTrue(self::isPartial(100, 300, 500, true), 'fresh baseline catches it');
        self::assertFalse(
            self::isPartial(100, 300, 500, false),
            'a stale baseline is ignored, not used in the comparison'
        );
        // and the floors still bite where they apply
        self::assertTrue(self::isPartial(40, 300, 500, false), 'the floor still applies');
    }

    public function testTheBaselineIsOnlyUsableWhenPresentAndFresh(): void
    {
        $code = self::code(self::source());

        self::assertStringContainsString('$baselineUsable = $baseline > 0', $code);
        self::assertStringContainsString('&& $baselineAt > 0', $code);
        self::assertStringContainsString(
            '&& (time() - $baselineAt) <= self::BASELINE_MAX_AGE;',
            $code,
            'freshness must be part of usability'
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
        self::assertStringNotContainsString(
            'BASELINE_MAX_AGE = 7 * DAY_IN_SECONDS',
            self::source()
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
     * array_merge is built from $stats, which carries no last_good_* keys, so
     * without an explicit carry-forward the baseline would be DROPPED every
     * run and the relative test would never have anything to compare against.
     */
    public function testThePreviousBaselineIsCarriedForwardExplicitly(): void
    {
        $code = self::code(self::source());

        self::assertStringContainsString("if (isset(\$prevEntry['last_good_total'])) {", $code);
        self::assertStringContainsString("if (isset(\$prevEntry['last_good_at'])) {", $code);
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

        [$start, $end] = self::blockSpan($code, 'if ($baselineUsable) {');
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
        [$mintStart, $mintEnd] = self::blockSpan($code, 'if ($baselineUsable) {');
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
        self::assertStringContainsString("\$baseline > 0 ? 'stale' : 'absent'", $src);
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
            self::isPartial($returned, $known, 0, false),
            "{$chain}: no floor covers it, so detection alone calls it clean"
        );

        // which is precisely why mint-eligibility must require a usable
        // baseline rather than a clean verdict
        self::assertStringContainsString(
            'if ($baselineUsable) {',
            self::code(self::source()),
            "{$chain} must not be able to mint on an unverified first fetch"
        );
    }

    /**
     * ⚠ And the floors cannot simply be tightened: kujira legitimately
     * returns 8 validators and dungeon 19, so an unconditional floor at 30 or
     * 50 would permanently flag real chains. Pinned so nobody "fixes" the
     * floors by breaking the small chains.
     */
    public function testSmallChainsAreLegitimateAndMustNotTripAFloor(): void
    {
        self::assertFalse(self::isPartial(8, 10, 8, true), 'kujira: 8 of 10 is real');
        self::assertFalse(self::isPartial(19, 27, 19, true), 'dungeon: 19 of 27 is real');
    }
}
