<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Infrastructure\EdgeCache;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the edge-cache exclusion's route predicate (owner-directed
 * 2026-08-06): every BCC REST route — both namespaces — must be
 * flagged never-edge-cacheable, and nothing else may be. A miss here
 * either resurrects the Origin-variant CORS poisoning (BCC route not
 * matched) or strips the edge cache from unrelated surfaces (non-BCC
 * route matched).
 */
#[CoversClass(EdgeCache::class)]
final class EdgeCacheRoutePredicateTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function bccRoutes(): iterable
    {
        yield 'bcc route'               => ['/bcc/v1/search/groups'];
        yield 'bcc route with params'   => ['/bcc/v1/cards/search'];
        yield 'bcc namespace root'      => ['/bcc/v1'];
        yield 'trust route'             => ['/bcc-trust/v1/read-model/health'];
        yield 'trust namespace root'    => ['/bcc-trust/v1'];
    }

    /** @return iterable<string, array{string}> */
    public static function foreignRoutes(): iterable
    {
        yield 'wp core'                  => ['/wp/v2/posts'];
        yield 'peepso'                   => ['/peepso/v1/profile'];
        yield 'lookalike prefix'         => ['/bcc/v10/thing'];
        yield 'lookalike trust prefix'   => ['/bcc-trustx/v1/thing'];
        yield 'rest index'               => ['/'];
        yield 'empty'                    => [''];
    }

    #[DataProvider('bccRoutes')]
    public function testBccRoutesAreExcluded(string $route): void
    {
        self::assertTrue(EdgeCache::appliesTo($route), "expected excluded: {$route}");
    }

    #[DataProvider('foreignRoutes')]
    public function testForeignRoutesAreNotTouched(string $route): void
    {
        self::assertFalse(EdgeCache::appliesTo($route), "expected untouched: {$route}");
    }
}
