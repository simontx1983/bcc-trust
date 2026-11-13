<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\PasswordAuthController;
use BCC\Trust\Core\Services\HandleService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-294 (account Lows, timing): an unknown account answered measurably
 * faster than a known one, because it skipped the one expensive step.
 *   - sign-in: an unknown account skipped the bcrypt compare;
 *   - forgot-password: an unknown address skipped key generation, which
 *     hashes the key.
 * Each now pays one password hash. Counted, not timed: the stubs record
 * how many hashes / checks a request ran. Against the REAL controller.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthTimingParityTest extends TestCase
{
    private const ALICE = 10;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/handle-rename-identity-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users' => [], 'meta' => [], 'passwords' => [], 'transients' => [],
            'mail' => [], 'actions' => [], 'audit' => [], 'hashes' => 0,
        ];
        $GLOBALS['__bcc_ph']['users'][self::ALICE] = ['email' => 'alice@example.test', 'login' => AuthSupport::deriveLogin('alice'), 'display' => 'Alice'];
        $GLOBALS['__bcc_ph']['meta'][self::ALICE]  = [HandleService::META_HANDLE => 'alice'];
        $GLOBALS['__bcc_ph']['passwords'][self::ALICE] = 'right-password';
    }

    private static function signIn(string $identifier, string $password): int
    {
        return (new PasswordAuthController())->login(
            new \WP_REST_Request(['identifier' => $identifier, 'password' => $password])
        )->get_status();
    }

    public function testAnUnknownHandlePaysAPasswordHash(): void
    {
        self::assertSame(401, self::signIn('nobody', 'whatever-password'));
        self::assertSame(1, $GLOBALS['__bcc_ph']['hashes']);
    }

    public function testAnUnknownEmailPaysAPasswordHash(): void
    {
        self::assertSame(401, self::signIn('nobody@example.test', 'whatever-password'));
        self::assertSame(1, $GLOBALS['__bcc_ph']['hashes']);
    }

    public function testAWrongPasswordPaysTheCompareNotAnExtraHash(): void
    {
        self::assertSame(401, self::signIn('alice', 'wrong-password'));
        self::assertSame(0, $GLOBALS['__bcc_ph']['hashes'], 'the compare is the cost; no second hash');
    }

    public function testForgotPasswordForAnUnknownAddressPaysTheKeyHash(): void
    {
        $resp = (new PasswordAuthController())->forgotPassword(new \WP_REST_Request(['email' => 'nobody@example.test']));

        self::assertSame(200, $resp->get_status(), 'always ok');
        self::assertSame(1, $GLOBALS['__bcc_ph']['hashes'], 'the hash a real key generation would have cost');
        self::assertSame([], $GLOBALS['__bcc_ph']['mail']);
    }
}
