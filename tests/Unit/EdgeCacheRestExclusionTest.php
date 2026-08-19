<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Infrastructure\EdgeCache;
use BCC\Trust\Infrastructure\Tests\Support\EdgeCacheSpy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Pins the WIRING of the blanket BCC edge-cache exclusion, not just its
 * route predicate.
 *
 * EdgeCacheRoutePredicateTest already covers `appliesTo()` in isolation.
 * What that cannot catch is the exclusion being registered and then never
 * firing — which is the failure mode that resurrects the original
 * incident: the LiteSpeed edge keys REST entries by URL WITHOUT varying on
 * `Origin`, so an entry primed by an Origin-less request (curl, uptime
 * monitor, SSR) is replayed to browsers with no
 * `Access-Control-Allow-Origin` for the rest of its TTL. Correct CORS
 * headers are worthless if a cache is allowed to serve a copy that
 * predates them.
 *
 * Own subprocess; setUp() pulls in tests/Stubs/edge-cache-stubs.php, which
 * fakes `add_filter` / `do_action` inside `BCC\Trust\Infrastructure`.
 */
#[CoversClass(EdgeCache::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class EdgeCacheRestExclusionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__ . '/../Stubs/edge-cache-stubs.php';

        EdgeCacheSpy::reset();
    }

    /** Run EdgeCache's registered rest_pre_dispatch filter against one route. */
    private function dispatch(string $route): void
    {
        EdgeCacheSpy::$actions = [];

        $filters = EdgeCacheSpy::filtersFor('rest_pre_dispatch');
        self::assertCount(1, $filters, 'EdgeCache::init must register exactly one rest_pre_dispatch filter');

        ($filters[0]['cb'])(null, null, new \WP_REST_Request($route));
    }

    public function testEveryBccRestResponseIsMarkedNeverEdgeCacheable(): void
    {
        EdgeCache::init();

        $routes = [
            '/bcc/v1',
            '/bcc/v1/users',
            '/bcc/v1/cards/search',
            '/bcc-trust/v1',
            '/bcc-trust/v1/read-model/health',
        ];

        foreach ($routes as $route) {
            $this->dispatch($route);
            self::assertContains(
                'litespeed_control_set_nocache',
                EdgeCacheSpy::$actions,
                "{$route}: BCC responses must be excluded from the edge cache"
            );
        }
    }

    public function testNonBccRoutesRetainNormalCaching(): void
    {
        EdgeCache::init();

        $routes = [
            '/wp/v2/posts',
            '/peepso/v1/profile',
            '/bcc/v10/thing',
            '/bcc-trustx/v1/thing',
            '/',
            '',
        ];

        foreach ($routes as $route) {
            $this->dispatch($route);
            self::assertSame(
                [],
                EdgeCacheSpy::$actions,
                "{$route}: unrelated surfaces must keep their edge cache"
            );
        }
    }

    public function testFilterPassesTheResultThroughUnchanged(): void
    {
        EdgeCache::init();

        $filters = EdgeCacheSpy::filtersFor('rest_pre_dispatch');
        self::assertCount(1, $filters);
        self::assertSame(10, $filters[0]['priority']);
        self::assertSame(3, $filters[0]['args']);

        $sentinel = new \stdClass();
        $returned = ($filters[0]['cb'])($sentinel, null, new \WP_REST_Request('/bcc/v1/users'));

        self::assertSame($sentinel, $returned, 'the exclusion must never alter the response');
    }
}
