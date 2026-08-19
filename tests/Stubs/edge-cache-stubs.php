<?php
/**
 * Stubs for EdgeCacheRestExclusionTest — exercises EdgeCache::init()'s real
 * `rest_pre_dispatch` wiring rather than the pure predicate (which
 * EdgeCacheRoutePredicateTest already pins).
 *
 * Fakes `add_filter` and `do_action` at their fully-qualified names inside
 * `BCC\Trust\Infrastructure`, recording every LSCWP action the filter
 * dispatches so the test can assert the exclusion actually fires (and only
 * for BCC routes).
 *
 * Subprocess-only; guarded.
 */

declare(strict_types=1);

namespace BCC\Trust\Infrastructure\Tests\Support {
    if (!class_exists(EdgeCacheSpy::class, false)) {
        final class EdgeCacheSpy
        {
            /** @var list<array{hook: string, priority: int, args: int, cb: callable}> */
            public static array $filters = [];

            /** @var list<string> */
            public static array $actions = [];

            public static function reset(): void
            {
                self::$filters = [];
                self::$actions = [];
            }

            /** @return list<array{hook: string, priority: int, args: int, cb: callable}> */
            public static function filtersFor(string $hook): array
            {
                return array_values(array_filter(
                    self::$filters,
                    static fn (array $f): bool => $f['hook'] === $hook
                ));
            }
        }
    }
}

namespace {
    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            public function __construct(private string $route = '')
            {
            }

            public function get_route(): string
            {
                return $this->route;
            }
        }
    }
}

namespace BCC\Trust\Infrastructure {

    use BCC\Trust\Infrastructure\Tests\Support\EdgeCacheSpy;

    if (!function_exists('BCC\\Trust\\Infrastructure\\add_filter')) {
        function add_filter(string $hook, callable $cb, int $priority = 10, int $args = 1): bool
        {
            EdgeCacheSpy::$filters[] = [
                'hook'     => $hook,
                'priority' => $priority,
                'args'     => $args,
                'cb'       => $cb,
            ];
            return true;
        }
    }

    if (!function_exists('BCC\\Trust\\Infrastructure\\do_action')) {
        function do_action(string $hook, mixed ...$args): void
        {
            EdgeCacheSpy::$actions[] = $hook;
        }
    }
}
