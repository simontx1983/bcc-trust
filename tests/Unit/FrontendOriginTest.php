<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\FrontendOrigin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * FrontendOrigin::match() must not accept a lookalike host.
 *
 * ## The bug this pins
 *
 * A `regex:` entry used to be interpolated bare into its delimiters and
 * matched case-insensitively:
 *
 *     @preg_match('#' . $pattern . '#i', $requestOrigin)
 *
 * An operator writing a pattern without `^`/`$` therefore got SUBSTRING
 * matching, so `regex:bcc-frontend-[a-z0-9]+-team\.vercel\.app` accepted
 * `https://bcc-frontend-abc-team.vercel.app.evil.test` — an
 * attacker-controlled host. The `i` flag additionally accepted case
 * variants that the exact path, which is strict, rejects.
 *
 * Requiring operators to supply anchors would NOT have been sufficient:
 * `^https://a\.test|https://b\.test$` contains both anchors yet still
 * matches `https://a.test.evil.io`, because top-level alternation binds
 * looser than either anchor. Only force-wrapping in a non-capturing group
 * makes the class of mistake unrepresentable.
 *
 * Third instance of this defect class in the codebase: see also the
 * `expanded_url` substring match in X share verification and the unbounded
 * prefix match in the `rest_url` origin filter (now
 * BCC\Core\Support\HeadlessOrigin, which boundary-checks).
 *
 * ## Not changed, and pinned here so it stays that way
 *
 *   - the exact-match path (`in_array(…, true)`) and its case-sensitivity
 *   - malformed patterns being silently skipped (deliberate fail-closed)
 *
 * BCC_FRONTEND_ORIGIN is a constant, so each case needs its own process.
 */
#[CoversClass(FrontendOrigin::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FrontendOriginTest extends TestCase
{
    private const PROD = 'https://bluecollarcrypto.io';

    /** A preview host the allowlist is meant to accept. */
    private const PREVIEW = 'https://bcc-frontend-abc-team.vercel.app';

    /** The same host with an attacker-controlled suffix. */
    private const LOOKALIKE = 'https://bcc-frontend-abc-team.vercel.app.evil.test';

    /** Pattern body as an operator might carelessly write it — no anchors. */
    private const UNANCHORED = 'bcc-frontend-[a-z0-9]+-team\.vercel\.app';

    /** The same intent, written correctly. */
    private const ANCHORED = '^https://bcc-frontend-[a-z0-9]+-team\.vercel\.app$';

    /** Anchors present, but alternation escapes them. */
    private const ALTERNATION = '^https://a\.test|https://b\.test$';

    /**
     * Per-test allowlist. The constant can only be defined once per process,
     * which is why this class runs each test in isolation.
     *
     * @return array<string, string>
     */
    private static function configs(): array
    {
        return [
            'testUnanchoredPatternCannotMatchALookalikeHost'
                => self::PROD . ',regex:' . self::UNANCHORED,
            'testUnanchoredPatternFailsClosedRatherThanOpen'
                => self::PROD . ',regex:' . self::UNANCHORED,
            'testTheGuardIsLoadBearingNotDecorative'
                => self::PROD . ',regex:' . self::UNANCHORED,
            'testAnchoredPatternStillMatchesWhatItShould'
                => self::PROD . ',regex:' . self::ANCHORED,
            'testAnchoredPatternStillRejectsTheLookalike'
                => self::PROD . ',regex:' . self::ANCHORED,
            'testAlternationAnchorsBothBranches'
                => self::PROD . ',regex:' . self::ALTERNATION,
            'testAlternationDoesNotLeakPastEitherBranch'
                => self::PROD . ',regex:' . self::ALTERNATION,
            'testRegexPathIsCaseSensitive'
                => self::PROD . ',regex:' . self::ANCHORED,
            'testMalformedPatternIsSkippedAndNeverMatches'
                => self::PROD . ',regex:^https://[unclosed',
            'testMalformedPatternDoesNotDisableTheRestOfTheAllowlist'
                => self::PROD . ',regex:^https://[unclosed',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('BCC_FRONTEND_ORIGIN')) {
            define('BCC_FRONTEND_ORIGIN', self::configs()[$this->name()] ?? self::PROD);
        }
    }

    // ── the defect ────────────────────────────────────────────────────────

    public function testUnanchoredPatternCannotMatchALookalikeHost(): void
    {
        self::assertNull(
            FrontendOrigin::match(self::LOOKALIKE),
            'an attacker-controlled suffix host must never be echoed back',
        );
    }

    /**
     * An unanchored pattern that omits the scheme now matches NOTHING —
     * including the host it was meant to allow. That is the deliberate
     * trade: both outcomes fail closed, and a preview that stops working is
     * a visible, safe failure. Pinned so the behaviour is a decision rather
     * than a surprise.
     */
    public function testUnanchoredPatternFailsClosedRatherThanOpen(): void
    {
        self::assertNull(FrontendOrigin::match(self::PREVIEW));
        self::assertNull(FrontendOrigin::match(self::LOOKALIKE));
        self::assertSame(self::PROD, FrontendOrigin::match(self::PROD));
    }

    /**
     * The delta, asserted directly: the OLD expression would have accepted
     * the lookalike. Without this, the test above could pass for reasons
     * unrelated to the anchoring.
     */
    public function testTheGuardIsLoadBearingNotDecorative(): void
    {
        $old = @preg_match('#' . self::UNANCHORED . '#i', self::LOOKALIKE);
        self::assertSame(1, $old, 'precondition: the old expression DID accept the lookalike');

        self::assertNull(
            FrontendOrigin::match(self::LOOKALIKE),
            'the anchoring is what rejects it now',
        );
    }

    // ── no regression for correctly written patterns ──────────────────────

    public function testAnchoredPatternStillMatchesWhatItShould(): void
    {
        self::assertSame(
            self::PREVIEW,
            FrontendOrigin::match(self::PREVIEW),
            'wrapping an already-anchored pattern must be a no-op: anchors are zero-width',
        );
    }

    public function testAnchoredPatternStillRejectsTheLookalike(): void
    {
        self::assertNull(FrontendOrigin::match(self::LOOKALIKE));
    }

    // ── alternation ───────────────────────────────────────────────────────

    /**
     * The non-capturing group is load-bearing. Without it, `^…$` would
     * anchor only the first branch of a top-level alternation.
     */
    public function testAlternationAnchorsBothBranches(): void
    {
        self::assertSame('https://a.test', FrontendOrigin::match('https://a.test'));
        self::assertSame('https://b.test', FrontendOrigin::match('https://b.test'));
    }

    public function testAlternationDoesNotLeakPastEitherBranch(): void
    {
        // Escapes the first branch's `^` under the old expression.
        self::assertNull(FrontendOrigin::match('https://a.test.evil.io'));
        // Escapes the second branch's `$` under the old expression.
        self::assertNull(FrontendOrigin::match('https://evil.io/https://b.test'));
    }

    // ── case sensitivity ──────────────────────────────────────────────────

    public function testRegexPathIsCaseSensitive(): void
    {
        self::assertSame(self::PREVIEW, FrontendOrigin::match(self::PREVIEW));
        self::assertNull(
            FrontendOrigin::match(strtoupper(self::PREVIEW)),
            'the regex path must not accept spellings the strict exact path rejects',
        );
    }

    // ── unchanged behaviour, pinned ───────────────────────────────────────

    public function testMalformedPatternIsSkippedAndNeverMatches(): void
    {
        self::assertNull(FrontendOrigin::match(self::PREVIEW));
        self::assertNull(FrontendOrigin::match('https://anything.test'));
    }

    public function testMalformedPatternDoesNotDisableTheRestOfTheAllowlist(): void
    {
        self::assertSame(
            self::PROD,
            FrontendOrigin::match(self::PROD),
            'a typo in one entry must not take the whole allowlist down',
        );
    }

    /**
     * The exact path is untouched by this change. Production depends on its
     * case-sensitivity: https://BLUECOLLARCRYPTO.IO is rejected today.
     */
    #[DataProvider('exactPathCases')]
    public function testExactMatchBehaviourIsUnchanged(string $origin, ?string $expected): void
    {
        self::assertSame($expected, FrontendOrigin::match($origin));
    }

    /** @return iterable<string, array{0: string, 1: ?string}> */
    public static function exactPathCases(): iterable
    {
        yield 'configured origin allowed' => [self::PROD, self::PROD];
        yield 'uppercase host rejected'   => ['https://BLUECOLLARCRYPTO.IO', null];
        yield 'trailing slash rejected'   => ['https://bluecollarcrypto.io/', null];
        yield 'path rejected'             => ['https://bluecollarcrypto.io/admin', null];
        yield 'port rejected'             => ['https://bluecollarcrypto.io:8443', null];
        yield 'suffix host rejected'      => ['https://bluecollarcrypto.io.evil.test', null];
        yield 'subdomain rejected'        => ['https://evil.bluecollarcrypto.io', null];
        yield 'scheme downgrade rejected' => ['http://bluecollarcrypto.io', null];
        yield 'literal null rejected'     => ['null', null];
        yield 'empty origin rejected'     => ['', null];
        yield 'whitespace only rejected'  => ['   ', null];
    }
}
