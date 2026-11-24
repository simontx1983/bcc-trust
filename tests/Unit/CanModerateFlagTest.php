<?php
/**
 * Structure A: every auth response that carries `suspended` also carries
 * `can_moderate`, the frontend's cue to show its "Admin" avatar group.
 *
 *  - It is exactly the admin routes' check: BearerAuth::requireAdmin() and
 *    the flag (via BearerAuth::userIsAdmin) ask for the same single
 *    capability constant, so the two cannot drift.
 *  - It is on every response that carries `suspended` (the sign-in
 *    responses, /auth/refresh, /auth/session), for the same user.
 *  - It is DISPLAY ONLY: nothing outside those response payloads reads it.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\Support\BearerAuth;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CanModerateFlagTest extends TestCase
{
    private const AUTH_DIR = 'app/Domain/Core/REST/Auth';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/can-moderate-stubs.php';
        $GLOBALS['__bcc_cm_asked'] = [];
    }

    public function testTheFlagIsTheAdminRoutesCheckForThatUser(): void
    {
        self::assertTrue(AuthSupport::resolveCanModerate(1), 'holds the admin capability');
        self::assertFalse(AuthSupport::resolveCanModerate(2), 'does not');
        self::assertFalse(AuthSupport::resolveCanModerate(0), 'no user, no flag, and no capability check');

        // The flag and the route gate ask for the SAME capability.
        BearerAuth::requireAdmin();
        self::assertSame(
            [
                ['user_can', 1, BearerAuth::ADMIN_CAPABILITY],
                ['user_can', 2, BearerAuth::ADMIN_CAPABILITY],
                ['current_user_can', 0, BearerAuth::ADMIN_CAPABILITY],
            ],
            $GLOBALS['__bcc_cm_asked']
        );
        self::assertSame('manage_options', BearerAuth::ADMIN_CAPABILITY);
    }

    /** A failed lookup hides the group and never fails the auth response it decorates. */
    public function testAFailedLookupIsFalseAndDoesNotThrow(): void
    {
        self::assertFalse(AuthSupport::resolveCanModerate(3));
    }

    /** One capability literal in BearerAuth, used by both the gate and the flag. */
    public function testTheCapabilityIsOneConstant(): void
    {
        $code = self::code(self::path('app/Domain/Core/Support/BearerAuth.php'));

        self::assertSame(1, substr_count($code, "'manage_options'"), 'only the constant names it');
        self::assertStringContainsString('current_user_can(self::ADMIN_CAPABILITY)', $code);
        self::assertStringContainsString('user_can($userId, self::ADMIN_CAPABILITY)', $code);
        self::assertStringContainsString(
            'return \\BCC\\Trust\\Core\\Support\\BearerAuth::userIsAdmin($userId);',
            self::code(self::path(self::AUTH_DIR . '/AuthSupport.php'))
        );
    }

    /**
     * Every response that carries `suspended` carries `can_moderate`, on the
     * very next line, for the same user.
     */
    public function testEveryResponseCarryingSuspendedCarriesTheFlag(): void
    {
        $pairs = 0;
        foreach (glob(self::path(self::AUTH_DIR) . '/*.php') ?: [] as $file) {
            $lines = preg_split('/\R/', self::code($file)) ?: [];
            foreach ($lines as $i => $line) {
                if (!preg_match("~'suspended'\s*=>\s*(?:self|AuthSupport)::resolveSuspended\((\\\$\w+)\),~", $line, $m)) {
                    continue;
                }
                $next = $lines[$i + 1] ?? '';
                self::assertMatchesRegularExpression(
                    "~'can_moderate'\s*=>\s*(?:self|AuthSupport)::resolveCanModerate\(" . preg_quote($m[1], '~') . '\),~',
                    $next,
                    basename($file) . ': `suspended` without `can_moderate` for the same user right after it'
                );
                $pairs++;
            }
        }

        self::assertSame(9, $pairs, 'the sign-in responses, /auth/refresh and /auth/session');
    }

    /** DISPLAY ONLY: nothing in app/ reads the flag outside those response payloads. */
    public function testNothingDecidesByIt(): void
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::path('app')));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $code = self::code($file->getPathname());
            foreach ((preg_split('/\R/', $code) ?: []) as $line) {
                if (!str_contains($line, 'can_moderate') && !str_contains($line, 'resolveCanModerate')) {
                    continue;
                }
                $isPayloadLine = (bool) preg_match("~^\s*'can_moderate'\s*=>\s*(?:self|AuthSupport)::resolveCanModerate\(\\\$\w+\),$~", $line);
                $isDefinition  = str_contains($line, 'public static function resolveCanModerate(int $userId): bool');
                self::assertTrue(
                    $isPayloadLine || $isDefinition,
                    $file->getFilename() . ' reads the display flag: ' . trim($line)
                );
            }
        }
    }

    private static function path(string $rel): string
    {
        return dirname(__DIR__, 2) . '/' . $rel;
    }

    /** Source without comments. */
    private static function code(string $path): string
    {
        $src = file_get_contents($path);
        self::assertIsString($src, $path);

        $out = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }
}
