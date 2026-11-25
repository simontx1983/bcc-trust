<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\AdminHallsEndpoint;
use BCC\Trust\Core\Support\BearerAuth;
use BCC\Trust\Onchain\Services\HallProvisioningService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The admin Halls API's gate and request handling (F-597, topic Halls 3a):
 * every route is registered with BearerAuth::requireAdmin as its permission,
 * and every handler checks again (anonymous 401, signed-in non-admin 403)
 * before any work. Bad input is a 400 before the service runs; a create
 * result maps to its HTTP status.
 *
 * Every refusal here must stop before the service: the stubs cannot serve
 * it, so a request that ran on fails the test.
 */
#[CoversClass(AdminHallsEndpoint::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminHallsEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cards-search-throttle-stubs.php';
        $GLOBALS['__bcc_cst'] = ['user_id' => 0, 'throttle_allow' => true, 'throttle_calls' => [], 'routes' => []];
        $GLOBALS['__bcc_caps'] = [];
        if (!function_exists('current_user_can')) {
            eval('function current_user_can($capability, ...$args): bool { return in_array($capability, $GLOBALS["__bcc_caps"] ?? [], true); }');
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_cst'], $GLOBALS['__bcc_caps']);
        parent::tearDown();
    }

    private static function signIn(bool $admin): void
    {
        $GLOBALS['__bcc_cst']['user_id'] = 7;
        $GLOBALS['__bcc_caps']           = $admin ? [BearerAuth::ADMIN_CAPABILITY] : [];
    }

    /**
     * @param array<string, mixed> $params
     * @return array{status: int, code: mixed, message: mixed, cache: mixed}
     */
    private static function call(string $handler, array $params = []): array
    {
        try {
            $res = (new AdminHallsEndpoint())->{$handler}(new \WP_REST_Request($params));
        } catch (\Throwable $e) {
            self::fail('the request was not refused before the service: ' . $e->getMessage());
        }
        $data = $res->get_data();
        $err  = is_array($data) && is_array($data['error'] ?? null) ? $data['error'] : [];

        return [
            'status'  => $res->get_status(),
            'code'    => $err['code'] ?? null,
            'message' => $err['message'] ?? null,
            'cache'   => $res->get_headers()['Cache-Control'] ?? null,
        ];
    }

    // ── the routes ──────────────────────────────────────────────────────

    public function testEveryRouteGatesThroughRequireAdmin(): void
    {
        AdminHallsEndpoint::register();

        $seen = [];
        foreach ($GLOBALS['__bcc_cst']['routes'] as [$namespace, $route, $args]) {
            if ($namespace !== 'bcc/v1' || !str_starts_with($route, '/admin/halls')) {
                continue;
            }
            foreach (isset($args['methods']) ? [$args] : $args as $endpoint) {
                $seen[] = $endpoint['methods'] . ' ' . $route;
                self::assertSame([BearerAuth::class, 'requireAdmin'], $endpoint['permission_callback'], "{$endpoint['methods']} {$route}");
            }
        }
        sort($seen);

        self::assertSame(['GET /admin/halls', 'PATCH /admin/halls/(?P<id>\d+)', 'POST /admin/halls'], $seen, 'anti-vacuity: all three were registered');
    }

    // ── the handlers' own gate ──────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function handlers(): array
    {
        return ['GET /admin/halls' => ['list'], 'POST /admin/halls' => ['create'], 'PATCH /admin/halls/:id' => ['update']];
    }

    #[DataProvider('handlers')]
    public function testAnAnonymousCallerIs401BeforeAnyWork(string $handler): void
    {
        $res = self::call($handler, ['id' => 501, 'name' => 'x']);

        self::assertSame([401, 'bcc_unauthorized', 'no-store'], [$res['status'], $res['code'], $res['cache']]);
    }

    #[DataProvider('handlers')]
    public function testASignedInNonAdminIs403BeforeAnyWork(string $handler): void
    {
        self::signIn(false);

        $res = self::call($handler, ['id' => 501, 'name' => 'x']);

        self::assertSame([403, 'bcc_forbidden', 'no-store'], [$res['status'], $res['code'], $res['cache']]);
    }

    // ── bad input, before the service ───────────────────────────────────

    public function testAnUnknownKindIsA400(): void
    {
        self::signIn(true);

        $res = self::call('list', ['kind' => 'hall']);

        self::assertSame([400, 'bcc_invalid_request'], [$res['status'], $res['code']]);
        self::assertSame('kind must be one of: chain, topic.', $res['message']);
    }

    public function testAnOverlongSearchIsA400(): void
    {
        self::signIn(true);

        self::assertSame(400, self::call('list', ['q' => str_repeat('a', 101)])['status']);
    }

    /** @return array<string, array{string, string}> */
    public static function nonStringFields(): array
    {
        return [
            'create name'        => ['create', 'name'],
            'create topic_slug'  => ['create', 'topic_slug'],
            'update description' => ['update', 'description'],
            'update topic_label' => ['update', 'topic_label'],
        ];
    }

    #[DataProvider('nonStringFields')]
    public function testANonStringFieldIsA400(string $handler, string $field): void
    {
        self::signIn(true);

        $res = self::call($handler, ['id' => 501, $field => ['not', 'a', 'string']]);

        self::assertSame([400, 'bcc_invalid_request', "{$field} must be a string."], [$res['status'], $res['code'], $res['message']]);
    }

    // ── a create result's HTTP status ───────────────────────────────────

    /** @return array<string, array{string, string|null, int, string|null}> */
    public static function createResults(): array
    {
        return [
            'created'             => ['created', null, 201, null],
            'ownership pending'   => ['incomplete', 'ownership_unreconciled', 201, null],
            'the topic has one'   => ['exists', null, 409, 'bcc_conflict'],
            'another run'         => ['busy', HallProvisioningService::FAIL_LOCK_CONTENDED, 409, 'bcc_conflict'],
            'the address taken'   => ['skipped', HallProvisioningService::FAIL_SLUG_TAKEN, 409, 'bcc_conflict'],
            'invalid input'       => ['skipped', HallProvisioningService::FAIL_TOPIC_INVALID, 400, 'bcc_invalid_request'],
            'an unreadable check' => ['error', HallProvisioningService::FAIL_READ_UNVERIFIED, 503, 'bcc_unavailable'],
            'a failed create'     => ['error', HallProvisioningService::FAIL_CREATE_FAILED, 503, 'bcc_unavailable'],
        ];
    }

    #[DataProvider('createResults')]
    public function testACreateResultMapsToItsStatus(string $status, ?string $failure, int $http, ?string $code): void
    {
        self::assertSame([$http, $code], AdminHallsEndpoint::createStatus($status, $failure));
    }
}
