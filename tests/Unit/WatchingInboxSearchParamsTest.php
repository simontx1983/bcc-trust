<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\MessagesEndpoint;
use BCC\Trust\Core\REST\WatchingEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Structure A, route map row 14: GET /me/watching and GET /me/conversations
 * take `q`, with GET /cards's rules (CardsSearchThrottleTest), as rows 13 and
 * 15 do (GroupsHallsSearchParamsTest):
 *
 *   - a `q` longer than 100 characters is a 400, declared on the arg (so
 *     WordPress refuses it first) and checked again in the handler;
 *   - a request WITH a `q` takes a throttle bucket (10 per 5 s) before any
 *     query work, and a denied one is a 429; a request without one never
 *     touches the throttle;
 *   - signed out is a 401 before any of that.
 *
 * Every case here stops before the first read. The searches themselves run
 * on a real server in WatchingNameSearchIntegrationTest and
 * InboxNameSearchIntegrationTest.
 */
#[CoversClass(WatchingEndpoint::class)]
#[CoversClass(MessagesEndpoint::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class WatchingInboxSearchParamsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cards-search-throttle-stubs.php';
        $GLOBALS['__bcc_cst'] = ['user_id' => 7, 'throttle_allow' => true, 'throttle_calls' => [], 'routes' => []];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_cst']);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $params
     * @return array{status: int, code: mixed, message: mixed}
     */
    private static function call(string $route, array $params): array
    {
        $request = new \WP_REST_Request($params);
        try {
            $res = $route === '/me/watching'
                ? (new WatchingEndpoint())->list($request)
                : (new MessagesEndpoint())->listInbox($request);
        } catch (\Throwable $e) {
            // Every case here must stop before the first read; one that ran
            // on into a read the stubs cannot serve failed to refuse.
            self::fail('the request was not refused before the reads: ' . $e->getMessage());
        }
        $data = $res->get_data();
        $err  = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];

        return ['status' => $res->get_status(), 'code' => $err['code'] ?? null, 'message' => $err['message'] ?? null];
    }

    /** @return array<string, array{string, string}> route => [route, throttle bucket] */
    public static function routes(): array
    {
        return [
            'GET /me/watching'      => ['/me/watching', 'me_watching_q'],
            'GET /me/conversations' => ['/me/conversations', 'me_conversations_q'],
        ];
    }

    #[DataProvider('routes')]
    public function testADeniedSearchIs429BeforeAnyOtherWork(string $route, string $bucket): void
    {
        $GLOBALS['__bcc_cst']['throttle_allow'] = false;

        $res = self::call($route, ['q' => 'alpha']);

        self::assertSame([429, 'bcc_rate_limited'], [$res['status'], $res['code']]);
        self::assertSame([[$bucket, 10, 5, null]], $GLOBALS['__bcc_cst']['throttle_calls'], 'GET /cards\'s numbers, on Throttle\'s own key');
    }

    #[DataProvider('routes')]
    public function testTheHandlerRefusesAnOverlongSearchItself(string $route): void
    {
        $res = self::call($route, ['q' => str_repeat('a', 101)]);

        self::assertSame([400, 'bcc_invalid_request'], [$res['status'], $res['code']]);
        self::assertSame('q must be at most 100 characters.', $res['message']);
    }

    #[DataProvider('routes')]
    public function testSignedOutIs401AndNeverTouchesTheThrottle(string $route): void
    {
        $GLOBALS['__bcc_cst']['user_id'] = 0;

        $res = self::call($route, ['q' => 'alpha']);

        self::assertSame(401, $res['status']);
        self::assertSame([], $GLOBALS['__bcc_cst']['throttle_calls']);
    }

    #[DataProvider('routes')]
    public function testQDeclaresAMaxLengthThatWordPressEnforces(string $route): void
    {
        $route === '/me/watching' ? WatchingEndpoint::register() : MessagesEndpoint::register();

        $routes = array_values(array_filter(
            $GLOBALS['__bcc_cst']['routes'],
            static fn (array $r): bool => $r[0] === 'bcc/v1' && $r[1] === $route
        ));
        self::assertCount(1, $routes);
        // /me/conversations registers GET and POST together; GET is the first.
        $spec = isset($routes[0][2]['args']) ? $routes[0][2] : $routes[0][2][0];
        $q    = $spec['args']['q'] ?? null;
        self::assertIsArray($q);

        self::assertSame(100, $q['maxLength'] ?? null);
        self::assertSame('string', $q['type'] ?? null, 'maxLength is only checked for a string-typed arg');
        self::assertSame('rest_validate_request_arg', $q['validate_callback'] ?? null);
        self::assertSame('sanitize_text_field', $q['sanitize_callback'] ?? null);
    }
}
