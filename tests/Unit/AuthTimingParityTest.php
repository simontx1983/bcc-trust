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
 * Sign-in now pays one password hash; forgot-password pays the hash
 * get_password_reset_key() would have: wp_fast_hash() on WordPress 6.8+
 * (a password hash there would make an unknown address the SLOWER one),
 * a password hash before. Counted, not timed: the stubs record how many
 * hashes a request ran. Against the REAL controller.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuthTimingParityTest extends TestCase
{
    private const ALICE = 10;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->name() === 'testBeforeWordPress68ForgotPasswordPaysAPasswordHash') {
            $GLOBALS['__bcc_no_fast_hash'] = true; // no wp_fast_hash(), like core < 6.8
        }
        require_once __DIR__ . '/../Stubs/handle-rename-identity-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users' => [], 'meta' => [], 'passwords' => [], 'transients' => [],
            'mail' => [], 'actions' => [], 'audit' => [], 'hashes' => 0, 'fast_hashes' => 0, 'generated' => [],
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
        self::assertSame(1, $GLOBALS['__bcc_ph']['fast_hashes'], "get_password_reset_key()'s own hash (WordPress 6.8+)");
        self::assertSame(0, $GLOBALS['__bcc_ph']['hashes'], 'NOT a password hash: that would make an unknown address the slower one');
        self::assertSame(['20'], $GLOBALS['__bcc_ph']['generated'], 'a key shaped like the real one: 20 chars, no specials');
        self::assertSame([], $GLOBALS['__bcc_ph']['mail']);
    }

    public function testBeforeWordPress68ForgotPasswordPaysAPasswordHash(): void
    {
        self::assertFalse(function_exists('wp_fast_hash'), 'playing core < 6.8');

        $resp = (new PasswordAuthController())->forgotPassword(new \WP_REST_Request(['email' => 'nobody@example.test']));

        self::assertSame(200, $resp->get_status());
        self::assertSame(1, $GLOBALS['__bcc_ph']['hashes'], 'core < 6.8 hashed the key with phpass: a password hash');
    }
}
