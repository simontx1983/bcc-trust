<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\ReadModelHealthEndpoint;
use BCC\Trust\Core\Support\BearerAuth;
use BCC\Trust\Disputes\Controllers\DisputeController;
use BCC\Trust\Onchain\Controllers\SignalController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Every REST admin check is BearerAuth's, so the admin capability has one
 * owner (follow-up to #876).
 *
 * `can_moderate` promises it is exactly the admin routes' check, and
 * BearerAuth::ADMIN_CAPABILITY is the one literal that check reads
 * (CanModerateFlagTest pins that). That promise only holds if no route
 * spells the capability itself: a route that did would keep the old
 * capability if the constant ever changed, and the flag would then describe
 * a check that route doesn't make.
 *
 *   - The admin-only routes registered with an inline closure now register
 *     BearerAuth::requireAdmin itself as their permission_callback.
 *   - No file under a REST/ or Controllers/ directory spells the capability
 *     anywhere outside a comment: the admin re-checks inside handlers and
 *     the owner-or-admin bypasses call BearerAuth::requireAdmin() too.
 *
 * Out of scope here: wp-admin pages, CLI guards and services, which decide
 * other things than a route's access.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(SignalController::class)]
#[CoversClass(DisputeController::class)]
#[CoversClass(ReadModelHealthEndpoint::class)]
final class AdminCapabilitySingleSourceTest extends TestCase
{
    private const PLUGIN = __DIR__ . '/../..';

    /** The routes that were gated by an inline closure. */
    private const ADMIN_ROUTES = [
        'bcc/v1/onchain/(?P<page_id>\d+)/refresh',
        'bcc/v1/disputes/(?P<id>\d+)/resolve',
        'bcc/v1/disputes/health',
        'bcc-trust/v1/health/read-model',
    ];

    public function testEachAdminRouteRegistersTheSharedSeamAsItsPermission(): void
    {
        require_once __DIR__ . '/../Stubs/admin-route-capture-stubs.php';
        $GLOBALS['__bcc_arc'] = [];

        SignalController::registerRoutes();
        (new DisputeController())->register_routes();
        ReadModelHealthEndpoint::register();

        $callbacks = [];
        foreach ($GLOBALS['__bcc_arc'] as [$namespace, $route, $args]) {
            // One endpoint array, or a list of them (several methods).
            $endpoints = isset($args['permission_callback']) ? [$args] : $args;
            foreach ($endpoints as $endpoint) {
                $callbacks[$namespace . $route][] = $endpoint['permission_callback'] ?? null;
            }
        }

        foreach (self::ADMIN_ROUTES as $route) {
            self::assertArrayHasKey($route, $callbacks, "{$route} was not registered, so this proves nothing");
            self::assertSame(
                [[BearerAuth::class, 'requireAdmin']],
                $callbacks[$route],
                "{$route} must gate through BearerAuth::requireAdmin"
            );
        }
    }

    public function testNoRestOrControllerFileSpellsTheCapability(): void
    {
        $files = self::restAndControllerFiles();

        // ANTI-VACUITY: the scan must see the tree, and the files this
        // change touched.
        self::assertGreaterThan(50, count($files), 'the scan found almost nothing');
        $relative = array_map(static fn (string $f): string => substr($f, strlen(self::PLUGIN) + 1), $files);
        foreach ([
            'app/Domain/Onchain/Controllers/SignalController.php',
            'app/Domain/Disputes/Controllers/DisputeController.php',
            'app/Domain/Core/REST/ReadModelHealthEndpoint.php',
            'app/Domain/Core/REST/AdminDigestEndpoint.php',
            'app/Domain/Core/REST/AdminReportsEndpoint.php',
            'app/Domain/Core/REST/AnnouncementCommentsEndpoint.php',
            'app/Domain/Core/REST/AnnouncementWriteEndpoint.php',
        ] as $must) {
            self::assertContains($must, $relative, "{$must} was not scanned");
        }

        $found = [];
        foreach ($files as $i => $file) {
            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token)
                    && $token[0] === T_CONSTANT_ENCAPSED_STRING
                    && trim($token[1], '\'"') === BearerAuth::ADMIN_CAPABILITY
                ) {
                    $found[] = $relative[$i] . ':' . $token[2];
                }
            }
        }

        self::assertSame([], $found, 'call BearerAuth::requireAdmin() (or userIsAdmin()) instead');
    }

    /** @return list<string> every PHP file under an app/Domain/<X>/REST or /Controllers directory */
    private static function restAndControllerFiles(): array
    {
        $out = [];
        foreach (glob(self::PLUGIN . '/app/Domain/*', GLOB_ONLYDIR) ?: [] as $domain) {
            foreach (['REST', 'Controllers'] as $dir) {
                if (!is_dir($domain . '/' . $dir)) {
                    continue;
                }
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($domain . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && $f->getExtension() === 'php') {
                        $out[] = str_replace('\\', '/', $f->getPathname());
                    }
                }
            }
        }
        sort($out);
        return $out;
    }
}
