<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\FrontendOrigin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-121: a BCC_FRONTEND_ORIGIN `regex:` entry is accepted ONLY in the one
 * shape that cannot match another person's Vercel deployment:
 *
 *   ^https://<project>-[a-z0-9]{N}-<team-slug>\.vercel\.app$
 *
 * ── ⚠ THE THINGS THAT MUST NOT HAPPEN ───────────────────────────────────
 *  1. AN ATTACKER'S PREVIEW IS GRANTED CORS. With the old documented
 *     pattern `bcc-frontend-[a-z0-9]+-<team>`, an attacker creates project
 *     `bcc-frontend` in THEIR OWN team "simon-s-projects", pushes branch
 *     `phillip`, and Vercel serves the git alias
 *     `bcc-frontend-git-phillip-simon-s-projects.vercel.app`, which the old
 *     pattern accepts (`[a-z0-9]+` = "git").
 *  2. THE PRODUCTION ORIGIN IS LOCKED OUT. Exact entries must keep working
 *     no matter how bad the regex entries are.
 *  3. THE SAFE PATTERN STOPS WORKING. A real preview of our project in our
 *     team still matches.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FrontendOriginPreviewPatternTest extends TestCase
{
    private const PROD = 'https://bluecollarcrypto.io';
    private const TEAM = 'phillip-simon-s-projects';

    /** The old documented example (CorsHandler, before F-121). */
    private const OLD_PATTERN  = '^https://bcc-frontend-[a-z0-9]+-phillip-simon-s-projects\.vercel\.app$';
    /** The recommended value. */
    private const SAFE_PATTERN = '^https://bcc-frontend-[a-z0-9]{9}-phillip-simon-s-projects\.vercel\.app$';

    /** An attacker's git-branch alias, from a project in THEIR team. */
    private const ATTACKER_ALIAS = 'https://bcc-frontend-git-phillip-simon-s-projects.vercel.app';
    /** An attacker's team slug that ENDS in ours (`x-phillip-simon-s-projects`). */
    private const ATTACKER_SUFFIX_TEAM = 'https://bcc-frontend-abc123xyz-x-phillip-simon-s-projects.vercel.app';
    /** A real preview deployment of our project in our team. */
    private const OUR_PREVIEW = 'https://bcc-frontend-k3j2h4g5f-phillip-simon-s-projects.vercel.app';

    /** @return array<string, string> per-test BCC_FRONTEND_ORIGIN */
    private static function configs(): array
    {
        return [
            'testTheOldPatternIsRefusedSoTheAttackerAliasGetsNothing' => self::PROD . ',regex:' . self::OLD_PATTERN,
            'testTheSafePatternAcceptsOurPreviewAndNotTheAttacker'    => self::PROD . ',regex:' . self::SAFE_PATTERN,
            'testExactEntriesSurviveWhenEveryRegexIsRefused'
                => 'regex:.*,' . self::PROD . ',regex:' . self::OLD_PATTERN . ',https://staging.bluecollarcrypto.io',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('BCC_FRONTEND_ORIGIN')) {
            define('BCC_FRONTEND_ORIGIN', self::configs()[$this->name()] ?? self::PROD);
        }
    }

    // ── the shape check ──────────────────────────────────────────────────

    /** @return iterable<string, array{string, bool}> */
    public static function shapes(): iterable
    {
        yield 'recommended value'                 => [self::SAFE_PATTERN, true];
        yield 'single-label project'               => ['^https://app-[a-z0-9]{9}-team\.vercel\.app$', true];
        yield 'old example ([a-z0-9]+)'            => [self::OLD_PATTERN, false];
        yield 'hyphen-capable slot'                => ['^https://bcc-frontend-[a-z0-9-]+\.vercel\.app$', false];
        yield 'hash slot too short ({3})'          => ['^https://bcc-frontend-[a-z0-9]{3}-team\.vercel\.app$', false];
        yield 'hash slot too long ({17})'          => ['^https://bcc-frontend-[a-z0-9]{17}-team\.vercel\.app$', false];
        yield 'variable range ({8,12})'            => ['^https://bcc-frontend-[a-z0-9]{8,12}-team\.vercel\.app$', false];
        yield 'project with a git label'           => ['^https://bcc-git-frontend-[a-z0-9]{9}-team\.vercel\.app$', false];
        yield 'missing ^'                          => ['https://bcc-frontend-[a-z0-9]{9}-team\.vercel\.app$', false];
        yield 'missing $'                          => ['^https://bcc-frontend-[a-z0-9]{9}-team\.vercel\.app', false];
        yield 'unescaped dots'                     => ['^https://bcc-frontend-[a-z0-9]{9}-team.vercel.app$', false];
        yield 'http scheme'                        => ['^http://bcc-frontend-[a-z0-9]{9}-team\.vercel\.app$', false];
        yield 'non-vercel suffix'                  => ['^https://bcc-frontend-[a-z0-9]{9}-team\.vercel\.app\.evil$', false];
        yield 'wildcard team'                      => ['^https://bcc-frontend-[a-z0-9]{9}-[a-z]+\.vercel\.app$', false];
        yield 'alternation'                        => ['^https://a\.test|https://b\.test$', false];
        yield 'match-everything'                   => ['.*', false];
        yield 'uppercase class'                    => ['^https://bcc-frontend-[A-Za-z0-9]{9}-team\.vercel\.app$', false];
    }

    #[DataProvider('shapes')]
    public function testOnlyTheOneSafeShapeIsAccepted(string $pattern, bool $expected): void
    {
        self::assertSame($expected, FrontendOrigin::isSafePreviewPattern($pattern), $pattern);
    }

    // ── end to end, through match() ──────────────────────────────────────

    public function testTheOldPatternIsRefusedSoTheAttackerAliasGetsNothing(): void
    {
        // Precondition: the old pattern really did accept the attacker's alias.
        self::assertSame(1, preg_match('#^(?:' . self::OLD_PATTERN . ')$#', self::ATTACKER_ALIAS));

        self::assertSame([], FrontendOrigin::patterns(), 'the unsafe entry is ignored');
        self::assertNull(FrontendOrigin::match(self::ATTACKER_ALIAS));
        self::assertSame(self::PROD, FrontendOrigin::match(self::PROD), 'production still works');
    }

    public function testTheSafePatternAcceptsOurPreviewAndNotTheAttacker(): void
    {
        self::assertSame([self::SAFE_PATTERN], FrontendOrigin::patterns());
        self::assertSame(self::OUR_PREVIEW, FrontendOrigin::match(self::OUR_PREVIEW));
        self::assertNull(FrontendOrigin::match(self::ATTACKER_ALIAS), 'git alias: "git" is not 9 chars');
        self::assertNull(FrontendOrigin::match(self::ATTACKER_SUFFIX_TEAM), 'a team slug ending in ours does not fit the shape');
        self::assertNull(FrontendOrigin::match(strtoupper(self::OUR_PREVIEW)), 'case-sensitive');
    }

    public function testExactEntriesSurviveWhenEveryRegexIsRefused(): void
    {
        self::assertSame([], FrontendOrigin::patterns());
        self::assertSame(self::PROD, FrontendOrigin::match(self::PROD));
        self::assertSame('https://staging.bluecollarcrypto.io', FrontendOrigin::match('https://staging.bluecollarcrypto.io'));
        self::assertSame(self::PROD, FrontendOrigin::canonical(), 'the canonical origin skips regex entries');
        self::assertNull(FrontendOrigin::match('https://anything.test'), '`regex:.*` is refused, not obeyed');
    }
}
