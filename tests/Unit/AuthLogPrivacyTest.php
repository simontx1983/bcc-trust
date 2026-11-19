<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Controllers\GitHubController;
use BCC\Trust\Core\Controllers\XController;
use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\PasswordAuthController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Security review (log audit, 2026-11-18): two kinds of log line carried
 * what a log should not.
 *   - A failed sign-up logged the typed EMAIL in plain text.
 *   - The GitHub and X controllers logged provider exception TEXT, which
 *     can echo request details (codes, tokens, URLs, response bodies).
 * Now a sign-up failure logs the WordPress error code and message only,
 * and the controllers log the exception's class and code, never its
 * message. AuthSupport's three best-effort fallbacks (trusted login
 * network, in_good_standing, linked wallet count) followed in the #735
 * review: same class-and-code shape.
 *
 * Runs the REAL handlers over a recording Logger, defined here before
 * any stub file so it is the one that loads, and over provider services
 * whose every call throws with a secret in its message.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthLogPrivacyTest extends TestCase
{
    private const SECRET = 'code=SECRET-oauth-code-123&access_token=ghp_SECRETTOKEN';

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists(\BCC\Core\Log\Logger::class, false)) {
            eval('namespace BCC\Core\Log; final class Logger { public static function __callStatic(string $level, array $args): void { $GLOBALS["__bcc_log"][] = [$level, (string) ($args[0] ?? ""), (array) ($args[1] ?? [])]; } }');
        }
        $GLOBALS['__bcc_log'] = [];
        require_once __DIR__ . '/../Stubs/handle-rename-identity-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        require_once __DIR__ . '/../Stubs/breached-password-stubs.php';
        $GLOBALS['__bcc_hibp'] = ['calls' => [], 'metrics' => [], 'logs' => []];
        $GLOBALS['__bcc_ph'] = [
            'users' => [], 'meta' => [], 'passwords' => [], 'transients' => [], 'mail' => [],
            'actions' => [], 'audit' => [], 'deleted' => [], 'wallets' => [], 'reset_keys' => [],
            'current_user' => 0,
        ];
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException('no database in unit tests');
            }
        };
    }

    /** Every logged line, flattened to text, for "never appears" checks. */
    private static function logText(): string
    {
        return (string) json_encode($GLOBALS['__bcc_log']);
    }

    public function testAFailedSignupNeverLogsTheTypedEmail(): void
    {
        $GLOBALS['__bcc_ph']['insert_error'] = true;

        $resp = (new PasswordAuthController())->signup(new \WP_REST_Request([
            'handle' => 'dana', 'email' => 'dana.private@example.test', 'password' => 'correct horse battery',
        ]));

        self::assertSame(500, $resp->get_status());
        $failed = array_values(array_filter($GLOBALS['__bcc_log'], static fn (array $l): bool => $l[1] === '[bcc-trust] signup failed'));
        self::assertCount(1, $failed, 'the failure is still logged');
        self::assertSame('db_insert_error', $failed[0][2]['code'], 'with the code to triage it');
        self::assertArrayNotHasKey('email', $failed[0][2]);
        self::assertStringNotContainsString('dana.private', self::logText(), 'the address appears nowhere');
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: string}> */
    public static function providerCalls(): iterable
    {
        yield 'GitHub getAuthUrl'    => [GitHubController::class, 'getAuthUrl', 'github', 'request'];
        yield 'GitHub getStatus'     => [GitHubController::class, 'getStatus', 'github', 'none'];
        yield 'GitHub disconnect'    => [GitHubController::class, 'disconnect', 'github', 'request'];
        yield 'GitHub refreshData'   => [GitHubController::class, 'refreshData', 'github', 'request'];
        yield 'X getAuthUrl'         => [XController::class, 'getAuthUrl', 'x', 'request'];
        yield 'X getStatus'          => [XController::class, 'getStatus', 'x', 'none'];
        yield 'X disconnect'         => [XController::class, 'disconnect', 'x', 'request'];
    }

    #[DataProvider('providerCalls')]
    public function testAProviderErrorLogsItsClassAndCodeNeverItsMessage(string $controller, string $method, string $ns, string $arg): void
    {
        self::fakeProviderServices($ns);

        $result = $arg === 'request' ? $controller::$method(new \WP_REST_Request([])) : $controller::$method();

        self::assertInstanceOf(\WP_Error::class, $result, 'the error path ran');
        self::assertCount(1, $GLOBALS['__bcc_log']);
        $context = $GLOBALS['__bcc_log'][0][2];
        self::assertSame('RuntimeException', $context['exception']);
        self::assertSame(7, $context['code']);
        self::assertStringNotContainsString('SECRET', self::logText(), 'no exception text in the log');
    }

    public function testNeitherControllerLogsAnExceptionMessageAnywhere(): void
    {
        // The OAuth callbacks redirect and exit, so they can't run here.
        // This pins that no log line in either file reads a message.
        foreach (['GitHubController', 'XController'] as $file) {
            $src = (string) file_get_contents(__DIR__ . '/../../app/Domain/Core/Controllers/' . $file . '.php');
            self::assertStringNotContainsString('getMessage()', $src, $file);
        }
    }

    public function testATrustedNetworkRecordFailureLogsItsClassAndCodeNeverItsMessage(): void
    {
        $GLOBALS['__bcc_ph']['meta'] = self::throwingStore();

        AuthSupport::recordTrustedLoginNetwork(42);

        self::assertOnlyLine('[bcc-trust] trusted login network not recorded');
    }

    public function testAGoodStandingLookupFailureLogsItsClassAndCodeNeverItsMessage(): void
    {
        $GLOBALS['wpdb'] = new class (self::SECRET) {
            public string $prefix = 'wp_';

            public function __construct(private string $secret)
            {
            }

            /** @param array<int, mixed> $args */
            public function __call(string $name, array $args): never
            {
                throw new \RuntimeException($this->secret, 7);
            }
        };

        self::assertTrue(AuthSupport::resolveInGoodStanding(42), 'still fails open');
        self::assertOnlyLine('[AuthEndpoint] in_good_standing lookup failed; fail-open');
    }

    public function testALinkedWalletCountFailureLogsItsClassAndCodeNeverItsMessage(): void
    {
        $GLOBALS['__bcc_ph']['wallets'] = self::throwingStore();

        $count = (new \ReflectionMethod(AuthSupport::class, 'linkedWalletCount'))->invoke(null, 42);

        self::assertSame(0, $count, 'the verification still completes');
        self::assertOnlyLine('[bcc-trust] verify-email: linked wallet count unavailable');
    }

    public function testAuthSupportLogsNoExceptionMessageAnywhere(): void
    {
        // The three fallbacks above are the only catch blocks that log.
        // This keeps a new one from going back to the message.
        $src = (string) file_get_contents(__DIR__ . '/../../app/Domain/Core/REST/Auth/AuthSupport.php');
        self::assertStringNotContainsString('getMessage()', $src);
    }

    private static function assertOnlyLine(string $message): void
    {
        self::assertCount(1, $GLOBALS['__bcc_log'], 'exactly one log line');
        [, $logged, $context] = $GLOBALS['__bcc_log'][0];
        self::assertSame($message, $logged);
        self::assertSame(['user_id' => 42, 'exception' => 'RuntimeException', 'code' => 7], $context);
        self::assertStringNotContainsString('SECRET', self::logText(), 'no exception text in the log');
    }

    /** A store whose every read throws with the secret in the message (code 7). */
    private static function throwingStore(): \ArrayAccess
    {
        return new class (self::SECRET) implements \ArrayAccess {
            public function __construct(private string $secret)
            {
            }

            public function offsetExists(mixed $offset): bool
            {
                throw new \RuntimeException($this->secret, 7);
            }

            public function offsetGet(mixed $offset): mixed
            {
                throw new \RuntimeException($this->secret, 7);
            }

            public function offsetSet(mixed $offset, mixed $value): void
            {
            }

            public function offsetUnset(mixed $offset): void
            {
            }
        };
    }

    private static function fakeProviderServices(string $ns): void
    {
        $prefix = $ns === 'github' ? 'GitHub' : 'X';
        eval(sprintf(
            'namespace BCC\Trust\Core\Services\%1$s;'
            . ' final class %2$sOAuthService { public function isConfigured(): bool { return true; } }'
            . ' final class %2$sVerificationService { public function __call(string $n, array $a): never { throw new \RuntimeException(%3$s, 7); } }',
            $ns,
            $prefix,
            var_export(self::SECRET, true)
        ));
    }
}
