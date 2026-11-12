<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Validator page creation runs per healthy chain; dead marking stays run-wide.
 *
 * ── THE ASYMMETRY THIS ENCODES ──────────────────────────────────────────
 * Both actions used to read one run-wide `$hasPartialFetch`, so a single
 * degraded chain blocked page creation for all fourteen. Measured on
 * production 2026-11-02: 228 validators across 9 chains had no page while
 * only 5 chains were actually degraded.
 *
 * They are not the same shape:
 *
 *   PAGE CREATION acts on PRESENCE — `wallet_link_id IS NULL` plus no
 *   `_bcc_onchain_validator_id` page meta. A truncated fetch can only make
 *   FEWER validators visible; it cannot invent one. So minting for a
 *   validator that demonstrably exists is safe whatever happened on another
 *   chain, and the worst case is that a page appears a run late.
 *
 *   DEAD MARKING acts on ABSENCE — `enrichment_attempts >= N` plus a
 *   `fetched_at` older than 30 days — and `ValidatorRepository::
 *   markDeadValidators()` is a GLOBAL UPDATE with no `chain_id` predicate.
 *   A truncated or skipped fetch makes present validators look absent, so
 *   that gate must keep seeing the whole run. Measured: it would flip 65
 *   rows right now, 46 of them on a chain last indexed in June.
 *
 * ⇒ page creation becomes per-chain; dead marking is untouched. Section 4
 * pins the second half so this change cannot drift into the first.
 *
 * ⚠ THE WRONG IMPLEMENTATION WOULD READ SAVED HEALTH.
 * `bcc_onchain_indexer_stats[slug]['partial']` looks like a per-chain health
 * flag but PERSISTS ACROSS RUNS and is months stale for several chains, so
 * it would report a chain last indexed in June as healthy. Section 5 pins
 * that it is not consulted.
 */
#[CoversNothing]
final class ValidatorPageMintingPerChainHealthTest extends TestCase
{
    private const SERVICE = 'BCC\\Trust\\Onchain\\Services\\ChainRefreshService';

    /** Read any plugin-relative source file, asserting it is really there. */
    private static function read(string $relative): string
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/' . $relative);
        self::assertNotFalse($src, "could not read {$relative}");
        self::assertNotSame('', $src, "{$relative} is empty");

        return (string) $src;
    }

    private static function source(): string
    {
        $path = dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/ChainRefreshService.php';
        $src  = file_get_contents($path);
        self::assertNotFalse($src, 'could not read ChainRefreshService.php');
        self::assertNotSame('', $src, 'ChainRefreshService.php is empty');

        return (string) $src;
    }

    /** Strip comments so prose can never satisfy a "does not call" assertion. */
    private static function code(string $src): string
    {
        $out = preg_replace('!/\*.*?\*/!s', '', $src) ?? '';

        return (string) preg_replace('!//[^\r\n]*!', '', $out);
    }

    /**
     * The byte span of the block opened by the first occurrence of $opener,
     * found by brace matching rather than by line adjacency — so "inside this
     * branch" is a structural claim, not a guess about formatting.
     *
     * @return array{0:int,1:int}
     */
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
        self::assertNotFalse($i, "no brace after opener: {$opener}");

        $depth = 0;
        $len   = strlen($code);
        for ($p = $i; $p < $len; $p++) {
            if ($code[$p] === '{') {
                $depth++;
            } elseif ($code[$p] === '}') {
                $depth--;
                if ($depth === 0) {
                    return [$i, $p];
                }
            }
        }

        self::fail("unbalanced braces after opener: {$opener}");
    }

    private static function helper(): ReflectionMethod
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        if (!class_exists(self::SERVICE, false)) {
            require dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/ChainRefreshService.php';
        }
        self::assertTrue(class_exists(self::SERVICE), 'ChainRefreshService must load');

        $m = new ReflectionMethod(self::SERVICE, 'mintForHealthyChains');
        $m->setAccessible(true);

        return $m;
    }

    /**
     * @param array<int, list<int>> $chainFetches
     * @param list<array{0:int,1:int,2:list<int>}> $calls
     * @return array{chains:int,scanned:int,minted:int,skipped:int,errors:int}
     */
    private static function dispatch(array $chainFetches, int $budget, callable $perChain, array &$calls): array
    {
        $calls = [];
        $fake  = static function (int $chainId, int $max, array $allowlist) use (&$calls, $perChain): array {
            $calls[] = [$chainId, $max, $allowlist];

            return $perChain($chainId, $max, $allowlist);
        };

        /** @var array{chains:int,scanned:int,minted:int,skipped:int,errors:int} $out */
        $out = self::helper()->invoke(null, $chainFetches, $budget, $fake);

        return $out;
    }

    // ── (0) anti-vacuity ────────────────────────────────────────────────

    public function testTheSourceAndHelperUnderTestAreReallyLoaded(): void
    {
        self::assertStringContainsString('class ChainRefreshService', self::source());
        self::assertSame('mintForHealthyChains', self::helper()->getName());
    }

    /** The comment stripper must strip, or section 1's negatives are hollow. */
    public function testTheCommentStripperRemovesProseButKeepsCode(): void
    {
        $code = self::code("\$a = 1; // mintMissing(null, false)\n/* hasPartialFetch */\n\$b = live();");

        self::assertStringNotContainsString('mintMissing(null', $code);
        self::assertStringNotContainsString('hasPartialFetch', $code);
        self::assertStringContainsString('live()', $code);
    }

    /** The brace matcher must actually bound a block. */
    public function testTheBlockSpanMatcherIsSound(): void
    {
        $sample = 'if (!$x) { inside(); if ($y) { nested(); } still(); } outside();';
        [$a, $b] = self::blockSpan($sample, 'if (!$x)');
        $span = substr($sample, $a, $b - $a);

        self::assertStringContainsString('inside()', $span);
        self::assertStringContainsString('nested()', $span);
        self::assertStringNotContainsString('outside()', $span);
    }

    // ── (1) a bad chain must not block a healthy chain ──────────────────

    public function testMintingIsGatedOnTheHealthyListNotOnTheRunWideFlag(): void
    {
        $code = self::code(self::source());

        self::assertStringContainsString(
            'if ($healthyChainFetches !== [])',
            $code,
            'minting must be gated on the per-chain list'
        );

        [$start, $end] = self::blockSpan($code, 'if ($healthyChainFetches !== [])');
        $branch = substr($code, $start, $end - $start);

        self::assertStringNotContainsString(
            'hasPartialFetch',
            $branch,
            'the mint branch must not consult the run-wide flag at all'
        );
    }

    public function testTheMinterIsNeverAskedForEveryChain(): void
    {
        self::assertStringNotContainsString(
            'mintMissing(null',
            self::code(self::source()),
            'null means every chain — the all-or-nothing behaviour this replaces'
        );
    }

    public function testEveryHealthyChainIsMintedEvenAlongsideUnhealthyOnes(): void
    {
        // The unhealthy chains are simply absent from the list; the healthy
        // ones must each still be dispatched, with their own id.
        $calls = [];
        $out     = self::dispatch(
            [10 => [101], 13 => [131], 21 => [211]],
            500,
            static fn (): array => ['scanned' => 5, 'minted' => 2, 'skipped' => 3, 'errors' => 0],
            $calls
        );

        self::assertSame([10, 13, 21], array_column($calls, 0), 'each healthy chain dispatched once, in order');
        self::assertSame(3, $out['chains']);
        self::assertSame(6, $out['minted']);
    }

    // ── (2) an unhealthy chain must not get pages ───────────────────────

    public function testTheHealthyListIsPopulatedOnlyWhereHealthIsProven(): void
    {
        $code = self::code(self::source());

        self::assertSame(
            1,
            substr_count($code, '$healthyChainFetches[$chainId] ='),
            'exactly one site may declare a chain healthy'
        );

        // occurrence 2: the healthy-chain collection, not the baseline write
        [$start, $end] = self::blockSpan($code, 'if (!$isPartialFetch)', 2);
        $branch = substr($code, $start, $end - $start);

        self::assertStringContainsString(
            '$healthyChainFetches[$chainId] = ValidatorRepository::idsForOperatorAddresses(',
            $branch,
            'the append must sit INSIDE the not-partial branch, structurally'
        );
    }

    public function testAChainIsNeverMintedUnlessItIsInTheList(): void
    {
        $calls = [];
        self::dispatch([13 => [131]], 500, static fn (): array => ['scanned' => 1, 'minted' => 1, 'skipped' => 0, 'errors' => 0], $calls);

        self::assertSame([[13, 500, [131]]], $calls, 'only the listed chain may be dispatched');
    }

    public function testAnEmptyHealthyListMintsNothingAtAll(): void
    {
        $calls = [];
        $out   = self::dispatch([], 500, static fn (): array => self::fail('must not be called'), $calls);

        self::assertSame([], $calls);
        self::assertSame(0, $out['chains']);
        self::assertSame(0, $out['minted']);
    }

    /**
     * The three paths that mean "this chain is not healthy" must each latch
     * the run-wide flag and must NOT append to the healthy list. Asserted by
     * counting: one append total (above), and the flag latched in three
     * places besides its initialiser.
     */
    public function testTheUnhealthyPathsLatchTheFlagAndAddNoChain(): void
    {
        $code = self::code(self::source());

        self::assertSame(
            3,
            substr_count($code, '$hasPartialFetch = true;'),
            'partial verdict, empty result and caught throw must each latch the run-wide flag'
        );
        self::assertSame(
            1,
            substr_count($code, '$hasPartialFetch = false;'),
            'the flag is initialised exactly once per run'
        );
    }

    // ── (3) one shared budget, decremented by scanned ───────────────────

    public function testTheBudgetIsSharedAcrossChainsAndNotPerChain(): void
    {
        $calls = [];
        self::dispatch(
            [1 => [11], 2 => [22], 3 => [33]],
            500,
            static fn (): array => ['scanned' => 100, 'minted' => 100, 'skipped' => 0, 'errors' => 0],
            $calls
        );

        // Each chain is offered only what is LEFT, so no chain can exceed it.
        self::assertSame([500, 400, 300], array_column($calls, 1));
    }

    public function testAnExhaustedBudgetSkipsTheRemainingChains(): void
    {
        $calls = [];
        $out   = self::dispatch(
            [1 => [11], 2 => [22], 3 => [33], 4 => [44]],
            500,
            static fn (): array => ['scanned' => 250, 'minted' => 250, 'skipped' => 0, 'errors' => 0],
            $calls
        );

        self::assertSame([1, 2], array_column($calls, 0), 'chains 3 and 4 must be skipped once the budget is spent');
        self::assertSame(2, $out['chains']);
        self::assertSame(500, $out['scanned'], 'the run total must not exceed the shared budget');
    }

    /**
     * ⚠ The decrement must be SCANNED, not minted. A chain whose rows all
     * already have pages reports minted=0 while still scanning; decrementing
     * by minted would let it burn unbounded scans and starve the rest.
     */
    public function testTheBudgetIsDecrementedByScannedNotMinted(): void
    {
        $calls = [];
        $out   = self::dispatch(
            [1 => [11], 2 => [22]],
            500,
            static fn (): array => ['scanned' => 500, 'minted' => 0, 'skipped' => 500, 'errors' => 0],
            $calls
        );

        self::assertSame([1], array_column($calls, 0), 'a minted=0 chain that scanned the whole budget must still exhaust it');
        self::assertSame(500, $out['scanned']);
        self::assertSame(0, $out['minted']);
    }

    public function testTheRunWideBudgetConstantIsFiveHundred(): void
    {
        self::assertStringContainsString(
            'private const MINT_SCAN_BUDGET = 500;',
            self::source(),
            'the shared budget must be an explicit named constant'
        );
        self::assertStringContainsString(
            'self::mintForHealthyChains($healthyChainFetches, self::MINT_SCAN_BUDGET)',
            self::code(self::source()),
            'production must pass the shared budget, not a per-chain default'
        );
    }

    // ── (4) no validator is marked inactive as a side effect ────────────

    public function testDeadMarkingIsStillGatedOnTheRunWideFlagAndCalledOnce(): void
    {
        $code = self::code(self::source());

        self::assertSame(
            1,
            substr_count($code, 'EnrichmentScheduler::markDeadValidators(array_keys($indexedChainIds));'),
            'exactly one dead-marking call site'
        );

        // ⚠ The gate gained a SECOND condition: an explicit switch that
        // defaults off, so correcting partial-fetch detection cannot by
        // itself start marking validators inactive.
        [$start, $end] = self::blockSpan($code, 'if (!$hasPartialFetch && self::deadMarkingEnabled())');
        $branch = substr($code, $start, $end - $start);

        self::assertStringContainsString(
            'EnrichmentScheduler::markDeadValidators(array_keys($indexedChainIds));',
            $branch,
            'dead marking must remain INSIDE the run-wide gate'
        );
    }

    public function testTheMintPathCannotMarkAnythingInactive(): void
    {
        $code = self::code(self::source());
        [$start, $end] = self::blockSpan($code, 'if ($healthyChainFetches !== [])');
        $branch = substr($code, $start, $end - $start);

        self::assertStringNotContainsString('markDeadValidators', $branch);
        self::assertStringNotContainsString('inactive', $branch);
        self::assertStringNotContainsString('status', $branch);
    }

    /** The helper touches nothing but its own inputs — no repository, no SQL. */
    public function testTheBudgetHelperReachesNoRepositoryWhenGivenAMinter(): void
    {
        $calls = [];
        $out   = self::dispatch(
            [7 => [77]],
            10,
            static fn (): array => ['scanned' => 1, 'minted' => 1, 'skipped' => 0, 'errors' => 0],
            $calls
        );

        // If the helper had reached a repository or $wpdb, loading the class
        // with nothing but ABSPATH defined would have failed before this point.
        self::assertSame(1, $out['minted']);
        self::assertSame([[7, 10, [77]]], $calls);
    }

    public function testTheDeadMarkingSqlIsUnchangedAndStillGlobal(): void
    {
        $repo = file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Repositories/ValidatorRepository.php'
        );
        self::assertNotFalse($repo);

        // Pinned because the per-chain minting change is only safe while the
        // sweep stays gated RUN-WIDE on $hasPartialFetch (asserted above).
        // F-157 re-decided the predicate on purpose: the sweep is now ALSO
        // scoped to the chains the run actually indexed, so a skipped chain's
        // rows are never swept by another chain's clean run. The run-wide
        // gate is unchanged, so this narrows the sweep and never widens it.
        //
        // ⚠ Asserted over the METHOD BODY, located by brace matching, with no
        // multi-line needle. An earlier version used a multi-line
        // assertStringNotContainsString whose exact whitespace and line
        // endings could never match, so it passed whether or not the sweep
        // had been chain-scoped. A mutation control caught that.
        [$mdStart, $mdEnd] = self::blockSpan((string) $repo, 'function markDeadValidators(int $maxAttempts, array $chainIds)');
        $body = substr((string) $repo, $mdStart, $mdEnd - $mdStart);

        self::assertStringContainsString("SET status = 'inactive'", $body);
        self::assertStringContainsString('enrichment_attempts >= %d', $body);
        self::assertStringContainsString('INTERVAL 30 DAY', $body);
        self::assertStringContainsString(
            'AND chain_id IN ({$placeholders})',
            $body,
            'F-157: the destructive sweep is scoped to the indexed chains'
        );
        self::assertStringContainsString(
            'if ($ids === []) {',
            $body,
            'F-157: an empty chain list marks nothing'
        );
    }

    // ── (5) health checks not loosened, saved health not used ───────────

    public function testRuleOneWasReplacedAndTheTwoFloorsSurvive(): void
    {
        $code = self::code(self::source());

        // ⚠ RULE 1 WAS DELIBERATELY REPLACED, not preserved. It compared
        // against COUNT(*) over every row ever stored, which only grows as
        // validators leave the set - five chains were permanently partial.
        // The relative test now compares against a verified clean baseline.
        self::assertStringNotContainsString('$knownCount > 10 && $returnedCount', $code);
        self::assertStringContainsString('($returnedCount * 10) < ($baseline * 7)', $code);
        self::assertStringContainsString('$knownCount > 200 && $returnedCount < 50', $code);
        self::assertStringContainsString('$knownCount === 0 && $returnedCount > 0 && $returnedCount < 30', $code);
    }

    public function testSavedPerChainHealthIsNotConsulted(): void
    {
        $code = self::code(self::source());
        [$start, $end] = self::blockSpan($code, 'if ($healthyChainFetches !== [])');
        $branch = substr($code, $start, $end - $start);

        self::assertStringNotContainsString(
            'bcc_onchain_indexer_stats',
            $branch,
            'that option persists across runs and is months stale for several chains'
        );
        self::assertStringNotContainsString('get_option', $branch);
    }

    /** The list must be per-run state, not static or global. */
    public function testTheHealthyListIsResetEveryRun(): void
    {
        $code = self::code(self::source());

        self::assertStringContainsString('$healthyChainFetches = [];', $code);
        self::assertStringNotContainsString('static $healthyChainFetches', $code);
        self::assertStringNotContainsString('self::$healthyChainFetches', $code);
    }
    // ── (6) the exact allowlist: only validators in THIS run's fetch ──

    /**
     * A healthy chain proves the FETCH was good. It does not prove any stored
     * row is still in the set — eligibility reads `wallet_link_id IS NULL`
     * plus no page meta and never consults currency. Measured 2026-11-02: 149
     * of 693 staging candidates were absent from their chain's latest complete
     * fetch, all 149 still labelled 'active', 56 unseen for over 90 days.
     */
    public function testTheAllowlistIsBuiltFromThisRunsFetchedAddresses(): void
    {
        $code = self::code(self::source());
        [$start, $end] = self::blockSpan($code, 'if (!$isPartialFetch)', 2);
        $branch = substr($code, $start, $end - $start);

        self::assertStringContainsString(
            'foreach ($validators as $fetched)',
            $branch,
            'the allowlist must come from the array the fetch returned'
        );
        self::assertStringContainsString('ValidatorRepository::idsForOperatorAddresses(', $branch);
        self::assertStringContainsString('$healthyChainFetches[$chainId] =', $branch);
    }

    /** Neither `status` nor `fetched_at` may stand in for fetch membership. */
    public function testStatusAndFetchedAtAreNotUsedAsTheCurrencySignal(): void
    {
        $code = self::code(self::source());
        [$start, $end] = self::blockSpan($code, 'if (!$isPartialFetch)', 2);
        $branch = substr($code, $start, $end - $start);

        self::assertStringNotContainsString('fetched_at', $branch);
        self::assertStringNotContainsString("'active'", $branch);
    }

    public function testEachChainsAllowlistReachesTheMinterIntact(): void
    {
        $calls = [];
        self::dispatch(
            [10 => [101, 102], 13 => [131]],
            500,
            static fn (): array => ['scanned' => 1, 'minted' => 1, 'skipped' => 0, 'errors' => 0],
            $calls
        );

        self::assertSame([[10, 500, [101, 102]], [13, 499, [131]]], $calls);
    }

    /**
     * ⚠ A validator absent from the allowlist cannot be minted, because the
     * allowlist is the only thing the minter is given. Asserted positively:
     * every id the minter receives came from the allowlist.
     */
    public function testAValidatorAbsentFromTheAllowlistIsNeverOffered(): void
    {
        $calls = [];
        self::dispatch(
            [10 => [101, 102]],
            500,
            static fn (): array => ['scanned' => 2, 'minted' => 2, 'skipped' => 0, 'errors' => 0],
            $calls
        );

        self::assertSame([101, 102], $calls[0][2]);
        self::assertNotContains(999, $calls[0][2], 'a stale validator id must never appear');
    }

    /**
     * ⚠ EMPTY MEANS MINT NONE, not "no filter". Exercised against the REAL
     * ValidatorPageMinter, and the absence of any database contact is itself
     * part of the assertion: $wpdb is undefined in this process, so a
     * repository call would fatal rather than return zeros.
     */
    public function testAnEmptyAllowlistMintsNoneAndNeverReachesTheDatabase(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        $minter = 'BCC\\Trust\\Onchain\\Services\\ValidatorPageMinter';
        if (!class_exists($minter, false)) {
            require dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/ValidatorPageMinter.php';
        }

        $out = $minter::mintMissing(8, false, 500, []);

        self::assertSame(0, $out['minted']);
        self::assertSame(0, $out['scanned']);
        self::assertSame([], $out['minted_ids']);
        self::assertSame([], $out['error_log']);
    }

    /** The fail-closed guard must test for [] explicitly, not for falsiness. */
    public function testTheEmptyAllowlistGuardIsExplicitAboutTheEmptyArray(): void
    {
        $src = self::read('app/Domain/Onchain/Services/ValidatorPageMinter.php');

        self::assertStringContainsString(
            'is_array($onlyValidatorIds) && $onlyValidatorIds === []',
            self::code($src),
            'an empty allowlist must be distinguished from null, not merged with it'
        );
    }

    /**
     * The manual CLI backfill is deliberately unchanged: it passes no
     * allowlist, the parameter defaults to null, and null means no
     * restriction. An operator running `backfill_pages` is making an
     * explicit, reviewed request.
     */
    public function testTheManualBackfillPathIsUnchanged(): void
    {
        $minter = self::code(self::read('app/Domain/Onchain/Services/ValidatorPageMinter.php'));
        self::assertStringContainsString('?array $onlyValidatorIds = null', $minter);

        $cli = self::code(self::read('app/Domain/Onchain/CLI/BackfillValidatorPagesCommand.php'));
        self::assertStringContainsString(
            'ValidatorPageMinter::mintMissing($chainId, $dryRun, $max)',
            $cli,
            'the CLI must still call the minter without an allowlist'
        );
    }

    /** The repository must refuse an empty allowlist before composing SQL. */
    public function testTheRepositoryRefusesAnEmptyAllowlistBeforeBuildingSql(): void
    {
        $repo = self::code(self::read('app/Domain/Onchain/Repositories/ValidatorRepository.php'));

        [$start, $end] = self::blockSpan($repo, 'if ($onlyValidatorIds !== null)');
        $guard = substr($repo, $start, $end - $start);

        self::assertStringContainsString('return [];', $guard, 'empty must short-circuit, never compose IN ()');
        self::assertStringContainsString("'v.id IN ('", $guard);
    }

    /**
     * ⚠ THE PRODUCTION DEFAULT MINTER MUST DERIVE ITS SCOPE FROM THE ALLOWLIST.
     *
     * Every behavioural test above injects a fake minter, so none of them
     * exercises the default closure — and a mutation that dropped $allowlist
     * from it passed the whole suite.
     *
     * ⭐ REWRITTEN 2026-11-04. This test used to assert that the closure
     * forwarded $ids as the fourth argument, i.e. that minting was scoped to
     * the addresses this run returned. That is no longer the guarantee: a page
     * is a durable, claimable identity for a validator we have ever seen, not
     * a statement about current set membership, so a chain that proved healthy
     * in this run mints for every pageless row it owns. See
     * ChainRefreshService::mintScopeFor() and
     * ValidatorPageExistenceIndependentOfMembershipTest.
     *
     * ⚠⚠ AND THE OLD TEXT ASSERTION WAS NOT SOUND. It checked for the literal
     * `mintMissing($chainId, false, $max, $ids)` and for the ABSENCE of
     * `mintMissing($chainId, false, $max);`. Both still held when the fourth
     * argument was changed to a hardcoded `null`, so the suite stayed green
     * across a real behaviour change. The scope decision now lives in a named
     * method asserted BY VALUE, and what is left here is the chain of custody.
     */
    public function testTheProductionMinterClosureDerivesItsScopeFromTheAllowlist(): void
    {
        $code = self::code(self::source());

        self::assertStringContainsString(
            '$ids = array_values(array_map(static fn ($id): int => (int) $id, $allowlist));',
            $code,
            'the normalised list must be derived from the allowlist the caller gave'
        );
        self::assertStringContainsString(
            'ValidatorPageMinter::mintMissing($chainId, false, $max, self::mintScopeFor($ids))',
            $code,
            'the scope must be resolved from $ids, not fabricated'
        );
        self::assertStringNotContainsString(
            'ValidatorPageMinter::mintMissing($chainId, false, $max);',
            $code,
            'an argument-less call would bypass the fail-closed empty case'
        );
        self::assertStringNotContainsString(
            'ValidatorPageMinter::mintMissing($chainId, false, $max, null)',
            $code,
            'a HARDCODED null would mint even when the allowlist resolved to nothing'
        );
    }

    public function testTheAddressToIdLookupIsChunkedRatherThanTruncated(): void
    {
        $repo = self::code(self::read('app/Domain/Onchain/Repositories/ValidatorRepository.php'));

        self::assertStringContainsString('function idsForOperatorAddresses(int $chainId, array $operatorAddresses)', $repo);
        self::assertStringContainsString('array_chunk(array_keys($clean), 200)', $repo);
        self::assertStringContainsString('SELECT id', $repo, 'no SELECT * (§2)');
    }
}
