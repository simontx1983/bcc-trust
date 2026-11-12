<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ONE OWNER FOR THE HALF-OPEN PROBE: {@see \BCC\Trust\Onchain\Support\ApiRetry}.
 *
 * ── THE RULE ────────────────────────────────────────────────────────────
 * {@see OnchainCircuitBreaker::isOpen()} is not a read. In the HALF-OPEN
 * window it CLAIMS the per-chain recovery probe with a cluster-wide advisory
 * lock, and whoever wins it is expected to go and contact the provider.
 *
 * Four callers used it as an outer preflight — before they knew whether they
 * would make a request at all. Each of them can return afterwards for a
 * reason that never reaches the wire (exhausted budget, missing chain row,
 * missing driver, unsupported capability, misconfigured endpoint), and
 * nothing releases the lock: the chain then cannot be probed by any worker
 * until that PHP process's database session closes.
 *
 * So the rule is: **only the transport layer may claim, and it claims
 * immediately before the request it is about to make.** Outer checks use the
 * non-mutating {@see OnchainCircuitBreaker::isResting()}.
 *
 * ── WHY THIS IS A SOURCE-LEVEL TEST ─────────────────────────────────────
 * The property is "no OTHER call site does this", which is a statement about
 * the whole tree rather than about one execution. The behaviour of each
 * primitive is measured separately, against a real MySQL/MariaDB lock, by
 * {@see \BCC\Trust\Tests\Integration\HalfOpenProbeOwnershipIntegrationTest}.
 * This file is what stops a fifth caller appearing.
 */
#[CoversClass(OnchainCircuitBreaker::class)]
final class HalfOpenProbeOwnershipTest extends TestCase
{
    /** Executable (comment-stripped) source of every PHP file under app/. */
    private static function executableSources(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $root = dirname(__DIR__, 2) . '/app';
        self::assertDirectoryExists($root);

        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            // ⚠ COMMENTS STRIPPED WITH token_get_all(), NEVER A REGEX. This
            // class's own docblocks reference `isOpen()` repeatedly, and a
            // grep counts every one of them as a call site.
            $code = '';
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $t) {
                if (is_array($t)) {
                    if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                        $code .= str_repeat("\n", substr_count($t[1], "\n"));
                        continue;
                    }
                    $code .= $t[1];
                } else {
                    $code .= $t;
                }
            }
            $out[str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))] = $code;
        }

        self::assertNotSame([], $out, 'anti-vacuity: some source must have been scanned');
        $cache = $out;

        return $out;
    }

    /**
     * Every `OnchainCircuitBreaker::isOpen()` call, as
     * `relative/path.php::enclosingMethod`.
     *
     * ⭐ WHY NOT `file:line`, WHICH THIS USED TO RETURN. A line number is
     * not the invariant. It moves whenever anything above a call site is
     * edited — F-156(b) moved these twice in one PR, once by adding code and
     * once by adding a DOCBLOCK to a method that merely sits above
     * `getBatchSameHost()` — so the guard failed twice with nothing wrong.
     *
     * ⚠⚠ And worse, a line number cannot see the thing that matters. Move a
     * claim into a private helper and the pin fails; renumber it, as a tired
     * reader would, and the guard goes green on a REAL change of ownership.
     * The enclosing method is both stable under edits above and sensitive to
     * the move that counts. {@see testAClaimMovedIntoAHelperIsReported()}
     * holds this to that.
     *
     * ⚠ Token-walked, not matched by regex: `$code` is already
     * comment-stripped, but function scope still has to be tracked to answer
     * "which method is this inside", and a closure must attribute to the
     * named function containing it rather than to nothing.
     *
     * @return list<string>
     */
    private static function claimSitesIn(string $code, string $rel): array
    {
        $tokens = token_get_all($code);
        $n      = count($tokens);

        $sites     = [];
        $depth     = 0;
        $stack     = [];      // [name, depth at which its body opened]
        $sawFn     = false;   // just passed T_FUNCTION
        $pendingFn = null;    // its name, awaiting the body brace

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];

            if (is_array($t)) {
                if ($t[0] === T_WHITESPACE) {
                    continue;
                }

                if ($t[0] === T_FUNCTION) {
                    $sawFn     = true;
                    $pendingFn = null;
                    continue;
                }

                // ⚠⚠ STRING-INTERPOLATION BRACES ARE ASYMMETRIC IN THE TOKEN
                // STREAM, and this cost a debugging round. In
                // `"chain {$chainId}"` the OPENING brace arrives as the array
                // token `T_CURLY_OPEN` (or `T_DOLLAR_OPEN_CURLY_BRACES` for
                // `${...}`), while the CLOSING one arrives as a plain `}`.
                // Counting only plain braces therefore decrements once per
                // interpolation with no matching increment: on ApiRetry.php
                // depth reached -2 and `request()`'s frame was popped before
                // its claim, which reported as `{file scope}`.
                //
                // Counting these keeps the two sides balanced. No frame is
                // pushed — an interpolation is not a function body.
                if ($t[0] === T_CURLY_OPEN || $t[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                    continue;
                }

                if ($sawFn && $t[0] === T_STRING) {
                    // A NAMED function; a closure has `(` here instead and so
                    // pushes no frame, leaving a claim inside it attributed to
                    // the named method that contains it.
                    $pendingFn = $t[1];
                    $sawFn     = false;
                    continue;
                }

                if ($t[0] === T_STRING && $t[1] === 'OnchainCircuitBreaker'
                    && isset($tokens[$i + 1], $tokens[$i + 2])
                    && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_DOUBLE_COLON
                    && is_array($tokens[$i + 2]) && $tokens[$i + 2][1] === 'isOpen'
                ) {
                    $sites[] = $rel . '::' . ($stack === [] ? '{file scope}' : end($stack)[0]);
                }

                continue;
            }

            // ⚠⚠ ANY non-array token ends the chance to NAME this function,
            // and that is load-bearing. `static function (int $c)` has `(`
            // here; without this the walker kept looking and took the
            // parameter's TYPE HINT as the name, pushing a bogus frame whose
            // closing brace then popped at the wrong depth and corrupted the
            // stack for the rest of the file. The visible symptom was the
            // real `request()` claim reporting `{file scope}`.
            $sawFn = false;

            if ($t === '{') {
                $depth++;
                if ($pendingFn !== null) {
                    $stack[]   = [$pendingFn, $depth];
                    $pendingFn = null;
                }
                continue;
            }

            if ($t === '}') {
                if ($stack !== [] && end($stack)[1] === $depth) {
                    array_pop($stack);
                }
                $depth--;
                continue;
            }

            // An abstract or interface method: `function f();` with no body.
            if ($t === ';' && $pendingFn !== null) {
                $pendingFn = null;
            }
        }

        sort($sites);

        return $sites;
    }

    /** @return list<string> */
    private static function claimSites(): array
    {
        $sites = [];
        foreach (self::executableSources() as $rel => $code) {
            foreach (self::claimSitesIn($code, $rel) as $site) {
                $sites[] = $site;
            }
        }
        sort($sites);

        return $sites;
    }

    // ── the ownership rule ──────────────────────────────────────────────

    /**
     * ⚠⚠ EXACTLY TWO CLAIM SITES, AND IN THESE TWO METHODS. `isOpen()` is
     * the MUTATING reader — it claims the single HALF-OPEN probe slot — so
     * ownership belongs to the transport that goes on to coordinate the
     * request, and nowhere else. An outer preflight claiming it could strand
     * the slot on an early exit, which is what #265 removed.
     */
    public function testOnlyApiRetryClaimsTheProbe(): void
    {
        $sites = self::claimSites();

        self::assertNotSame([], $sites, 'anti-vacuity: the mutating check must still exist somewhere');
        self::assertSame(
            [
                'Domain/Onchain/Support/ApiRetry.php::getBatchSameHost',
                'Domain/Onchain/Support/ApiRetry.php::request',
            ],
            $sites,
            "the half-open probe has exactly one owner — ApiRetry::request() and\n"
            . "ApiRetry::getBatchSameHost(). Found:\n  " . implode("\n  ", $sites)
        );
    }

    /**
     * ⚠⚠ THE CASE THE OLD LINE PIN COULD NOT SEE, and the reason this guard
     * was rewritten. Extracting the claim into a private helper is a REAL
     * change of probe ownership: the helper can now be called from anywhere,
     * including an outer preflight, and the two entry points no longer own
     * it. A `file:line` pin fails on that — but it fails on a stray docblock
     * too, so the honest response to a failure became "renumber it", and
     * renumbering waves this through.
     */
    public function testAClaimMovedIntoAHelperIsReported(): void
    {
        $refactored = <<<'PHP'
        <?php
        final class ApiRetry
        {
            private static function claimProbe(int $chainId): bool
            {
                return OnchainCircuitBreaker::isOpen($chainId);
            }

            public static function request(callable $fn)
            {
                return self::claimProbe(1) ? null : $fn();
            }
        }
        PHP;

        self::assertSame(
            ['Domain/Onchain/Support/ApiRetry.php::claimProbe'],
            self::claimSitesIn($refactored, 'Domain/Onchain/Support/ApiRetry.php'),
            'a claim moved into a helper must be reported against the HELPER'
        );
    }

    /**
     * ⚠ A claim added to an unrelated class is reported with its own
     * method, so the failure names where to look instead of only saying a
     * count changed.
     */
    public function testAClaimElsewhereIsReportedWithItsMethod(): void
    {
        $elsewhere = <<<'PHP'
        <?php
        final class ChainRefreshService
        {
            public function tick(): void
            {
                if (OnchainCircuitBreaker::isOpen($this->chainId)) {
                    return;
                }
            }
        }
        PHP;

        self::assertSame(
            ['Domain/Onchain/Services/ChainRefreshService.php::tick'],
            self::claimSitesIn($elsewhere, 'Domain/Onchain/Services/ChainRefreshService.php')
        );
    }

    /**
     * ⭐ A claim inside a CLOSURE attributes to the named method containing
     * it, not to nothing. `ApiRetry` passes closures around, so a detector
     * that lost scope there would report `{file scope}` and the main
     * assertion would fail for a bogus reason.
     */
    public function testAClaimInsideAClosureAttributesToItsMethod(): void
    {
        $withClosure = <<<'PHP'
        <?php
        final class ApiRetry
        {
            public static function request(callable $fn)
            {
                $guard = static function (int $c) {
                    return OnchainCircuitBreaker::isOpen($c);
                };

                return $guard(1) ? null : $fn();
            }
        }
        PHP;

        self::assertSame(
            ['Domain/Onchain/Support/ApiRetry.php::request'],
            self::claimSitesIn($withClosure, 'Domain/Onchain/Support/ApiRetry.php')
        );
    }

    /**
     * ⚠⚠ REGRESSION: STRING INTERPOLATION MUST NOT MOVE FUNCTION SCOPE.
     * This is the bug that cost a debugging round, so it is pinned rather
     * than left to a comment.
     *
     * `"chain {$id}"` puts the OPENING brace in the token stream as the
     * array token `T_CURLY_OPEN` and the CLOSING one as a plain `}`. A
     * walker counting only plain braces loses one level per interpolation;
     * on the real `ApiRetry.php` depth reached -2 and `request()`'s frame
     * was popped before its own claim, which then reported as
     * `{file scope}` — a guard failure with nothing actually wrong.
     *
     * Two interpolations before the claim, so a single off-by-one is still
     * caught.
     */
    public function testStringInterpolationDoesNotBreakScopeTracking(): void
    {
        $interpolating = <<<'PHP'
        <?php
        final class ApiRetry
        {
            public static function request(int $chainId)
            {
                self::log("BLOCKED by circuit breaker: {$chainId}");
                self::log("another {$chainId} for luck");

                if (OnchainCircuitBreaker::isOpen($chainId)) {
                    return null;
                }

                return true;
            }
        }
        PHP;

        self::assertSame(
            ['Domain/Onchain/Support/ApiRetry.php::request'],
            self::claimSitesIn($interpolating, 'Domain/Onchain/Support/ApiRetry.php'),
            'interpolation braces are asymmetric in the token stream and must be balanced'
        );
    }

    /**
     * ⚠ ANTI-VACUITY FOR THE DETECTOR: source with no claim must report
     * NOTHING. A walker that reported a site per function, or that fell over
     * and returned `[]` on everything, would pass the cases above in one
     * direction or the other.
     */
    public function testSourceWithNoClaimReportsNothing(): void
    {
        $clean = <<<'PHP'
        <?php
        final class EnrichmentScheduler
        {
            public function tick(): void
            {
                if (OnchainCircuitBreaker::isResting($this->chainId)) {
                    return;
                }
            }
        }
        PHP;

        self::assertSame([], self::claimSitesIn($clean, 'x.php'));
    }

    /**
     * ⚠ And `isResting()` is NOT mistaken for a claim. It is the
     * non-mutating preflight reader, deliberately distinct (#264/#265); a
     * detector that matched the class name alone would report all four
     * preflights and the main assertion would fail.
     */
    public function testTheNonMutatingReaderIsNotCountedAsAClaim(): void
    {
        $sites = self::claimSites();

        foreach ($sites as $site) {
            self::assertStringStartsWith(
                'Domain/Onchain/Support/ApiRetry.php::',
                $site,
                'isResting() call sites must not appear here: ' . $site
            );
        }
    }

    /**
     * The four outer preflights, named individually so that silently deleting
     * a breaker check (rather than making it non-mutating) also fails.
     *
     * @return array<string, array{0: string}>
     */
    public static function outerPreflights(): array
    {
        return [
            'EVM indexer tick'        => ['Domain/Onchain/Workers/NftEthIndexerWorker.php'],
            'validator index cron'    => ['Domain/Onchain/Services/ChainRefreshService.php'],
            'enrichment scheduler'    => ['Domain/Onchain/Services/EnrichmentScheduler.php'],
            // ⚠ `cosmwasm discovery` WAS HERE AND IS NOT COMING BACK.
            // It read `['Domain/Onchain/Workers/CosmwasmDiscoveryWorker.php']`
            // and carried the note "retained but frozen since #261 — fix in
            // place, do NOT unfreeze and do NOT delete". S8 deleted the
            // worker, so the row would now fail on its own "the caller must
            // still exist" guard. Three outer preflights remain, which is the
            // whole population of `isResting()` callers.
        ];
    }

    #[DataProvider('outerPreflights')]
    public function testEveryOuterPreflightUsesTheNonMutatingCheck(string $relPath): void
    {
        $sources = self::executableSources();
        self::assertArrayHasKey($relPath, $sources, 'the caller must still exist');
        $code = $sources[$relPath];

        self::assertStringContainsString(
            'OnchainCircuitBreaker::isResting(',
            $code,
            $relPath . ' must inspect breaker state without claiming the probe'
        );
        self::assertStringNotContainsString(
            'OnchainCircuitBreaker::isOpen(',
            $code,
            $relPath . ' must NOT claim the probe before it knows it will contact a provider'
        );
    }


    // ── the reader is genuinely side-effect free ────────────────────────

    /**
     * `isResting()` must not touch the lock at all. Proven here structurally
     * — the method body may not mention AdvisoryLock — and behaviourally,
     * against a real lock, by the integration suite.
     */
    public function testIsRestingContainsNoLockCall(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Support/OnchainCircuitBreaker.php'
        );
        self::assertMatchesRegularExpression(
            '/function isResting\(int \$chainId\): bool\s*\{[^}]*\}/s',
            $src,
            'isResting() must exist with the expected signature'
        );
        preg_match('/function isResting\(int \$chainId\): bool\s*\{(.*?)\n    \}/s', $src, $m);
        self::assertNotEmpty($m, 'the isResting() body must be readable');
        self::assertStringNotContainsString(
            'AdvisoryLock',
            $m[1],
            'isResting() must never acquire or release the probe lock'
        );
    }
}
