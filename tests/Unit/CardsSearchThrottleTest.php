<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\CardsListEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * `GET /cards` text search is length-capped and throttled.
 *
 * `q` had no length cap and no throttle, and the route is anonymous. Each
 * distinct `q` is a leading-wildcard LIKE the 30 s discovery cache cannot
 * absorb, so a script could make the database scan per request. Now:
 *   - `q` declares `maxLength` 100 AND names `rest_validate_request_arg`
 *     as its validate_callback. WordPress does not auto-validate a
 *     hand-registered arg, so without that callback the maxLength is
 *     ignored. With it, a longer `q` is a 400 before the handler runs;
 *   - the handler checks the length again (defence in depth);
 *   - a request WITH a `q` takes Throttle 'cards_list' (10 per 5 s, the
 *     bcc-search numbers) on Throttle's own key, before any query work, and
 *     a denial is 429 bcc_rate_limited;
 *   - a request WITHOUT a `q` never touches the throttle.
 *
 * The real handler runs; requests that pass the throttle stop at the
 * handler's own `page` ceiling (page 21 → 400), so no discovery query runs
 * in a unit test. Stubs: tests/Stubs/cards-search-throttle-stubs.php.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(CardsListEndpoint::class)]
final class CardsSearchThrottleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cards-search-throttle-stubs.php';
        $GLOBALS['__bcc_cst'] = ['user_id' => 0, 'throttle_allow' => true, 'throttle_calls' => [], 'routes' => []];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_cst']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $params
     * @return array{status: int, code: mixed}
     */
    private function get(array $params): array
    {
        $r = $this->call($params);

        return ['status' => $r['status'], 'code' => $r['code']];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{status: int, code: mixed, message: mixed}
     */
    private function call(array $params): array
    {
        $res  = (new CardsListEndpoint())->handle(new \WP_REST_Request($params));
        $data = $res->get_data();
        $err  = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];

        return ['status' => $res->get_status(), 'code' => $err['code'] ?? null, 'message' => $err['message'] ?? null];
    }

    // ── the throttle ────────────────────────────────────────────────

    public function testADeniedSearchIs429BeforeAnyOtherWork(): void
    {
        $GLOBALS['__bcc_cst']['throttle_allow'] = false;

        // page 21 would otherwise be a 400: the 429 comes first.
        self::assertSame(['status' => 429, 'code' => 'bcc_rate_limited'], $this->get(['q' => 'stake', 'page' => 21]));
    }

    public function testASearchTakesTheCardsListBucketOnThrottlesOwnKey(): void
    {
        $this->get(['q' => 'stake', 'page' => 21]);

        self::assertSame([['cards_list', 10, 5, null]], $GLOBALS['__bcc_cst']['throttle_calls']);
    }

    public function testAnAllowedSearchCarriesOn(): void
    {
        self::assertSame(['status' => 400, 'code' => 'bcc_invalid_request'], $this->get(['q' => 'stake', 'page' => 21]));
    }

    public function testBrowsingWithoutASearchNeverTouchesTheThrottle(): void
    {
        $GLOBALS['__bcc_cst']['throttle_allow'] = false;

        self::assertSame(['status' => 400, 'code' => 'bcc_invalid_request'], $this->get(['page' => 21]));
        self::assertSame(['status' => 400, 'code' => 'bcc_invalid_request'], $this->get(['q' => '', 'page' => 21]));
        self::assertSame([], $GLOBALS['__bcc_cst']['throttle_calls']);
    }

    // ── the length cap ──────────────────────────────────────────────

    public function testTheHandlerRefusesAnOverlongSearchItself(): void
    {
        $long = $this->call(['q' => str_repeat('a', 101), 'page' => 21]);
        self::assertSame(400, $long['status']);
        self::assertSame('q must be at most 100 characters.', $long['message']);

        // Exactly 100 is allowed through to the next check (the page ceiling).
        $max = $this->call(['q' => str_repeat('é', 100), 'page' => 21]);
        self::assertSame(400, $max['status']);
        self::assertNotSame('q must be at most 100 characters.', $max['message'], 'counted in characters, not bytes');
    }

    public function testQDeclaresAMaxLengthThatWordPressEnforces(): void
    {
        CardsListEndpoint::register();

        $routes = array_values(array_filter(
            $GLOBALS['__bcc_cst']['routes'],
            static fn (array $r): bool => $r[0] === 'bcc/v1' && $r[1] === '/cards'
        ));
        self::assertCount(1, $routes);
        $q = $routes[0][2]['args']['q'] ?? null;
        self::assertIsArray($q);

        self::assertSame(100, $q['maxLength'] ?? null);
        self::assertSame('string', $q['type'] ?? null, 'maxLength is only checked for a string-typed arg');
        self::assertSame(
            'rest_validate_request_arg',
            $q['validate_callback'] ?? null,
            'WordPress checks maxLength only through its schema validator; it does not attach it to a hand-registered arg'
        );
    }
}
