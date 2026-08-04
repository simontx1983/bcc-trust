<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\InternalPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the InternalPath relative-in-app-href boundary (v1.70).
 *
 * CardsSearchEndpoint passes vertical-built hrefs (group_url /
 * profile_url) through to the frontend, where they become Next.js
 * routes. This validator is the only thing standing between a
 * version-skewed or regressed upstream and the off-app-navigation
 * defect class (v1.46 users / v1.47 projects / v1.70 groups) — every
 * rejection case here is a way a row could smuggle the browser
 * off-origin.
 */
#[CoversClass(InternalPath::class)]
final class InternalPathTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validPaths(): iterable
    {
        yield 'hall detail'      => ['/halls/cosmos-hall'];
        yield 'community detail' => ['/communities/cosmos'];
        yield 'member profile'   => ['/u/simon'];
        yield 'root'             => ['/'];
        yield 'list fallback'    => ['/communities'];
        yield 'query string'     => ['/search?q=hall'];
        yield 'fragment'         => ['/halls/cosmos-hall#feed'];
        yield 'encoded slug'     => ['/communities/a%20b'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidPaths(): iterable
    {
        yield 'protocol-relative'    => ['//evil.example/path'];
        yield 'https absolute'       => ['https://evil.example/x'];
        yield 'http absolute'        => ['http://evil.example/x'];
        yield 'no leading slash'     => ['foo/bar'];
        yield 'empty'                => [''];
        yield 'backslash'            => ['/a\\b'];
        yield 'nul control char'     => ["/a\x00b"];
        yield 'esc control char'     => ["/a\x1Fb"];
        yield 'del control char'     => ["/a\x7Fb"];
        yield 'javascript scheme'    => ['javascript:alert(1)'];
        yield 'bare double slash'    => ['//'];
    }

    #[DataProvider('validPaths')]
    public function testAcceptsRelativeAppRoutes(string $path): void
    {
        self::assertTrue(InternalPath::isValid($path), "expected valid: {$path}");
    }

    #[DataProvider('invalidPaths')]
    public function testRejectsOffAppAndMalformedPaths(string $path): void
    {
        self::assertFalse(InternalPath::isValid($path), 'expected invalid: ' . addcslashes($path, "\x00..\x1f\x7f"));
    }
}
