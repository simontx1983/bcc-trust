<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\GroupsDiscoveryEndpoint;
use BCC\Trust\Core\REST\HallsEndpoint;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Structure A, route map rows 13 and 15: GET /groups takes `q` and `kind`,
 * GET /halls takes `q`, with GET /cards's rules (CardsSearchThrottleTest):
 *
 *   - a `q` longer than 100 characters is a 400, declared on the arg (so
 *     WordPress refuses it first) and checked again in the handler;
 *   - a request WITH a `q` takes a throttle bucket (10 per 5 s) before any
 *     query work, and a denied one is a 429; browsing without one never
 *     touches the throttle;
 *   - `kind` takes the group `type` values cards and search use, and any
 *     other value is a 400 naming them.
 *
 * Every case here stops before the first read. The reads themselves run on
 * a real server in GroupNameFilterIntegrationTest.
 */
#[CoversClass(GroupsDiscoveryEndpoint::class)]
#[CoversClass(HallsEndpoint::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupsHallsSearchParamsTest extends TestCase
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
     * @return array{status: int, code: mixed, message: mixed}
     */
    private static function call(string $route, array $params): array
    {
        $request = new \WP_REST_Request($params);
        $res     = $route === '/groups'
            ? (new GroupsDiscoveryEndpoint())->getList($request)
            : (new HallsEndpoint())->handle($request);
        $data = $res->get_data();
        $err  = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];

        return ['status' => $res->get_status(), 'code' => $err['code'] ?? null, 'message' => $err['message'] ?? null];
    }

    /** @return array<string, array{string, string}> route => [route, throttle bucket] */
    public static function routes(): array
    {
        return [
            'GET /groups' => ['/groups', 'groups_list'],
            'GET /halls'  => ['/halls', 'halls_list'],
        ];
    }

    // ── q ───────────────────────────────────────────────────────────────

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

    /** Exactly 100 characters (not bytes) passes the length check. */
    public function testOneHundredCharactersPassTheLengthCheck(): void
    {
        // An unknown kind is the next refusal, so reaching it proves the q passed.
        $res = self::call('/groups', ['q' => str_repeat('é', 100), 'kind' => 'bogus']);

        self::assertSame(400, $res['status']);
        self::assertStringStartsWith('kind must be one of', (string) $res['message']);
    }

    public function testBrowsingWithoutASearchNeverTouchesTheThrottle(): void
    {
        $GLOBALS['__bcc_cst']['throttle_allow'] = false;

        self::assertSame(400, self::call('/groups', ['kind' => 'bogus'])['status']);
        self::assertSame(400, self::call('/groups', ['q' => '', 'kind' => 'bogus'])['status']);
        self::assertSame([], $GLOBALS['__bcc_cst']['throttle_calls']);
    }

    #[DataProvider('routes')]
    public function testQDeclaresAMaxLengthThatWordPressEnforces(string $route): void
    {
        $route === '/groups' ? GroupsDiscoveryEndpoint::register() : HallsEndpoint::register();

        $routes = array_values(array_filter(
            $GLOBALS['__bcc_cst']['routes'],
            static fn (array $r): bool => $r[0] === 'bcc/v1' && $r[1] === $route
        ));
        self::assertCount(1, $routes);
        $q = $routes[0][2]['args']['q'] ?? null;
        self::assertIsArray($q);

        self::assertSame(100, $q['maxLength'] ?? null);
        self::assertSame('string', $q['type'] ?? null, 'maxLength is only checked for a string-typed arg');
        self::assertSame('rest_validate_request_arg', $q['validate_callback'] ?? null);
    }

    // ── kind ────────────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function unknownKinds(): array
    {
        return [
            'a tab label'      => ['holder'],
            'a plural'         => ['halls'],
            'another word'     => ['community'],
            'a kicker'         => ['delegators'],
        ];
    }

    /** An unknown kind is a 400 that names the accepted values, as /cards does. */
    #[DataProvider('unknownKinds')]
    public function testAnUnknownKindIsA400NamingTheGroupTypes(string $kind): void
    {
        $res = self::call('/groups', ['kind' => $kind]);

        self::assertSame([400, 'bcc_invalid_request'], [$res['status'], $res['code']]);
        self::assertSame(
            'kind must be one of: ' . implode(', ', array_map(static fn (GroupType $t): string => $t->value, GroupType::cases())) . '.',
            $res['message']
        );
    }

    /** `kind` keeps only the groups of that type, keyed and ordered as they came. */
    public function testAKindKeepsOnlyTheGroupsOfThatType(): void
    {
        $ctx = static fn (int $id, GroupType $type): GroupContext => new GroupContext($id, $type, PeepSoPrivacy::Open, false, null, null, null);
        $contexts = [
            31 => $ctx(31, GroupType::Hall),
            12 => $ctx(12, GroupType::Nft),
            47 => $ctx(47, GroupType::Hall),
            5  => $ctx(5, GroupType::User),
            9  => $ctx(9, GroupType::Validator),
        ];

        self::assertSame([31, 47], array_keys(GroupsDiscoveryEndpoint::filterContextsByKind($contexts, GroupType::Hall)));
        self::assertSame([12], array_keys(GroupsDiscoveryEndpoint::filterContextsByKind($contexts, GroupType::Nft)));
        self::assertSame([9], array_keys(GroupsDiscoveryEndpoint::filterContextsByKind($contexts, GroupType::Validator)));
        self::assertSame([5], array_keys(GroupsDiscoveryEndpoint::filterContextsByKind($contexts, GroupType::User)));
        self::assertSame([], GroupsDiscoveryEndpoint::filterContextsByKind($contexts, GroupType::System));
    }

    /** The accepted values are exactly the group types cards and search use. */
    public function testTheAcceptedKindsAreTheGroupTypes(): void
    {
        $values = array_map(static fn (GroupType $t): string => $t->value, GroupType::cases());
        sort($values);

        self::assertSame(['hall', 'nft', 'system', 'user', 'validator'], $values);
    }
}
