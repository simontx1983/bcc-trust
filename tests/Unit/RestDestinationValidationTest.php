<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\MyGroupsEndpoint;
use BCC\Trust\Core\REST\PostsEndpoint;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Destination validation — proves the REST arg SCHEMA that enforces the
 * one-post/one-origin invariant is actually declared on every create
 * route + the owner-toggle route.
 *
 * ## Why a schema test + the EMPIRICALLY-verified framework behavior
 * The bcc-trust unit harness has no WordPress runtime, so WP's own request
 * pipeline cannot be executed here. This test asserts the arg SCHEMA; the
 * runtime behavior below was verified against the live Local site
 * (2026-07-23) with anonymous probes of `POST /bcc/v1/posts`:
 *   - WP does **NOT** 400 a non-scalar `group_id`. Every shape tested —
 *     array `[1,2]`, object `{a:1}`, `"abc"`, `"5"`, `5`, `-5`, absent —
 *     reached the handler (all returned 401 bcc_unauthorized from the
 *     handler's own auth check, not 400 rest_invalid_param). So the
 *     one-origin guarantee is NOT validation-rejection.
 *   - The guarantee is SCALAR COERCION. `sanitize_callback: absint` +
 *     `(int)` in the handler collapse any shape to a single non-negative
 *     scalar: `absint([1,2]) = 1`, `absint('abc') = 0`, `absint(-5) = 5`,
 *     `(int)'5' = 5`. A single int cannot name two groups.
 *   - The service treats `group_id <= 0` as the Floor path (no group), and
 *     PeepSoStatusWriter stamps exactly one `peepso_group_id`. So repeated
 *     or array params can never create multiple destinations — they become
 *     one scalar or Floor. (WriterVisibilityParityTest proves the
 *     service-side single-origin + no-writer-on-reject.)
 * This test therefore locks the `type: integer` + `absint` declaration —
 * the mechanism that performs the collapse — and asserts the enum. It does
 * NOT claim WP rejects arrays (it doesn't). Adding an is_array handler
 * guard would be dead code (absint runs first). Authenticated end-to-end
 * behavior past the auth wall stays a staging/PR probe.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RestDestinationValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/route-registration-stubs.php';
        $GLOBALS['__bcc_routes'] = [];
        PostsEndpoint::register();
        MyGroupsEndpoint::register();
    }

    /** @return array<string, mixed> the param-schema map for a route path */
    private function paramSchema(string $routePath): array
    {
        foreach ($GLOBALS['__bcc_routes'] as $r) {
            if ($r['route'] === $routePath) {
                return $r['args']['args'] ?? [];
            }
        }
        self::fail("route not registered: {$routePath}");
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function createRoutes(): iterable
    {
        yield 'status' => ['/posts'];
        yield 'photo'  => ['/posts/photo'];
        yield 'gif'    => ['/posts/gif'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('createRoutes')]
    public function testGroupIdDeclaredAsScalarIntegerWithAbsint(string $routePath): void
    {
        $args = $this->paramSchema($routePath);

        self::assertArrayHasKey('group_id', $args, "{$routePath}: group_id registered");
        // `type: integer` + `absint` are the SCALAR-COLLAPSE mechanism (see
        // the class docstring: WP forwards non-scalar group_id to the
        // handler; absint/(int) collapse any shape to one non-negative int).
        self::assertSame('integer', $args['group_id']['type'] ?? null, "{$routePath}: group_id declared integer");
        // absint collapses array→1, 'abc'→0, -5→5: always a single scalar.
        self::assertSame('absint', $args['group_id']['sanitize_callback'] ?? null, "{$routePath}: absint sanitizer (one-origin collapse)");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('createRoutes')]
    public function testVisibilityIsAClosedEnum(string $routePath): void
    {
        $args = $this->paramSchema($routePath);
        self::assertSame(
            ['members_only', 'public_group', 'public_all'],
            $args['visibility']['enum'] ?? null,
            "{$routePath}: visibility is a closed enum"
        );
    }

    public function testPostPolicyRouteDeclaresBooleanEnable(): void
    {
        $args = $this->paramSchema('/me/groups/(?P<id>\d+)/post-policy');

        self::assertArrayHasKey('public_all_members', $args);
        self::assertSame('boolean', $args['public_all_members']['type'] ?? null, 'strict boolean, not free string');
        self::assertTrue($args['public_all_members']['required'] ?? false, 'enable flag is required');
        self::assertSame('absint', $args['id']['sanitize_callback'] ?? null, 'group id sanitized to a scalar int');
    }

    public function testAllCreateRoutesRegistered(): void
    {
        $paths = array_column($GLOBALS['__bcc_routes'], 'route');
        foreach (['/posts', '/posts/photo', '/posts/gif', '/me/groups/(?P<id>\d+)/post-policy'] as $expected) {
            self::assertContains($expected, $paths, "route registered: {$expected}");
        }
    }
}
