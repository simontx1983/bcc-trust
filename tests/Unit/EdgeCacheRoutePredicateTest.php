<?php

declare(strict_types=1);

namespace {
    if (!class_exists('WP_REST_Response')) {
        class WP_REST_Response
        {
            /** @var array<string, string> */
            private array $headers = [];

            /** @return array<string, string> */
            public function get_headers(): array
            {
                return $this->headers;
            }

            public function header(string $key, string $value, bool $replace = true): void
            {
                if ($replace || !isset($this->headers[$key])) {
                    $this->headers[$key] = $value;
                    return;
                }
                $this->headers[$key] .= ', ' . $value;
            }
        }
    }

    if (!class_exists('WP_REST_Request')) {
        class WP_REST_Request
        {
            /** @param array<string, string> $headers */
            public function __construct(private array $headers = [])
            {
            }

            public function get_header(string $key): ?string
            {
                return $this->headers[strtolower($key)] ?? null;
            }
        }
    }
}

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Trust\Infrastructure\EdgeCache;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;
    use WP_REST_Request;
    use WP_REST_Response;

    /**
     * Pins the edge-cache exclusion contract.
     *
     * HISTORY — this file previously asserted the OPPOSITE for core
     * routes: `/wp/v2/posts` and the REST index `/` were required to
     * return false, i.e. to stay edge-cacheable. That assertion encoded
     * the bug. WordPress core attaches `Access-Control-Allow-Origin` to
     * EVERY REST response, so a cached core route replays one caller's
     * CORS grant to the next. Reproduced on production 2026-08-19: a
     * request to `/wp-json/` carrying one Origin was answered with a
     * stored `Access-Control-Allow-Origin` naming a different origin.
     *
     * The contract is now: every REST route is excluded. Non-REST pages
     * never reach the predicate — it is only consulted from
     * `rest_pre_dispatch` — so ordinary page caching is unaffected.
     */
    #[CoversClass(EdgeCache::class)]
    final class EdgeCacheRoutePredicateTest extends TestCase
    {
        /** @return iterable<string, array{string}> */
        public static function bccRoutes(): iterable
        {
            yield 'bcc route'             => ['/bcc/v1/search/groups'];
            yield 'bcc route with params' => ['/bcc/v1/cards/search'];
            yield 'bcc namespace root'    => ['/bcc/v1'];
            yield 'trust route'           => ['/bcc-trust/v1/read-model/health'];
            yield 'trust namespace root'  => ['/bcc-trust/v1'];
        }

        /**
         * Routes that used to be asserted FALSE and are now excluded too.
         *
         * @return iterable<string, array{string}>
         */
        public static function previouslyCacheableRoutes(): iterable
        {
            yield 'wp core'                => ['/wp/v2/posts'];
            yield 'wp core types'          => ['/wp/v2/types'];
            yield 'peepso'                 => ['/peepso/v1/profile'];
            yield 'lookalike prefix'       => ['/bcc/v10/thing'];
            yield 'lookalike trust prefix' => ['/bcc-trustx/v1/thing'];
            yield 'rest index'             => ['/'];
            yield 'empty'                  => [''];
        }

        #[DataProvider('bccRoutes')]
        public function testBccRoutesAreExcluded(string $route): void
        {
            self::assertTrue(EdgeCache::appliesTo($route), "expected excluded: {$route}");
        }

        #[DataProvider('previouslyCacheableRoutes')]
        public function testEveryOtherRestRouteIsAlsoExcluded(string $route): void
        {
            self::assertTrue(
                EdgeCache::appliesTo($route),
                "expected excluded (core routes carry CORS headers too): {$route}"
            );
        }

        public function testVaryOriginIsAddedWhenAbsent(): void
        {
            $res = EdgeCache::hardenSharedCaching(new WP_REST_Response(), null, new WP_REST_Request());

            self::assertSame('Origin', $res->get_headers()['Vary']);
        }

        public function testVaryOriginIsMergedNotClobbered(): void
        {
            $r = new WP_REST_Response();
            $r->header('Vary', 'Accept-Encoding');

            $res = EdgeCache::hardenSharedCaching($r, null, new WP_REST_Request());

            self::assertSame('Accept-Encoding, Origin', $res->get_headers()['Vary']);
        }

        public function testVaryOriginIsNotDuplicated(): void
        {
            $r = new WP_REST_Response();
            $r->header('Vary', 'Accept-Encoding, Origin');

            $res = EdgeCache::hardenSharedCaching($r, null, new WP_REST_Request());

            self::assertSame('Accept-Encoding, Origin', $res->get_headers()['Vary']);
        }

        public function testOriginlessRequestKeepsItsPublicCachePolicy(): void
        {
            $r = new WP_REST_Response();
            $r->header('Cache-Control', 'public, max-age=15, stale-while-revalidate=30');

            $res = EdgeCache::hardenSharedCaching($r, null, new WP_REST_Request());

            // SSR / curl / monitors have no Origin, so the anonymous edge
            // tier keeps the TTL the endpoint deliberately declared.
            self::assertSame(
                'public, max-age=15, stale-while-revalidate=30',
                $res->get_headers()['Cache-Control']
            );
        }

        public function testOriginRequestDowngradesPublicToPrivateKeepingTtl(): void
        {
            $r = new WP_REST_Response();
            $r->header('Cache-Control', 'public, max-age=15, stale-while-revalidate=30');

            $res = EdgeCache::hardenSharedCaching(
                $r,
                null,
                new WP_REST_Request(['origin' => 'https://bluecollarcrypto.io'])
            );

            // Shared caches must not store an Origin-variant response, but
            // the browser keeps the same TTL — this is why we downgrade
            // rather than forcing no-store.
            self::assertSame(
                'private, max-age=15, stale-while-revalidate=30',
                $res->get_headers()['Cache-Control']
            );
        }

        public function testOriginRequestWithoutCacheControlGetsPrivateNoStore(): void
        {
            $res = EdgeCache::hardenSharedCaching(
                new WP_REST_Response(),
                null,
                new WP_REST_Request(['origin' => 'https://evil.example.com'])
            );

            self::assertSame('private, no-store', $res->get_headers()['Cache-Control']);
        }

        public function testExistingPrivateOrNoStorePolicyIsLeftAlone(): void
        {
            $r = new WP_REST_Response();
            $r->header('Cache-Control', 'no-store');

            $res = EdgeCache::hardenSharedCaching(
                $r,
                null,
                new WP_REST_Request(['origin' => 'https://bluecollarcrypto.io'])
            );

            self::assertSame('no-store', $res->get_headers()['Cache-Control']);
        }

        public function testNonRestResponseIsPassedThroughUntouched(): void
        {
            $sentinel = new \stdClass();

            self::assertSame(
                $sentinel,
                EdgeCache::hardenSharedCaching($sentinel, null, new WP_REST_Request())
            );
        }
    }
}
