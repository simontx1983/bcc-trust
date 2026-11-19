<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\REST\Auth\PasswordAuthController;
use BCC\Trust\Core\Security\WordPressSignInGate;
use BCC\Trust\Core\Services\HandleService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * H2 follow-up (2026-11-18): only the app's forgot-password flow mints a
 * password reset key, for every role. WordPressSignInGate refuses
 * `allow_password_reset` to every other caller (wp-login.php's lost-password
 * form, PeepSo's recover-password page), so the app's own flow has to go
 * through WordPressSignInGate::appResetKey() or no member could reset at
 * all.
 *
 * Against the REAL PasswordAuthController::forgotPassword and the REAL gate,
 * registered through its own register(); get_password_reset_key() is a
 * double shaped like core's that runs the `allow_password_reset` filters
 * add_filter() recorded.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PasswordResetAtTheAppTest extends TestCase
{
    private const MEMBER = 90;
    private const ADMIN  = 1;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/handle-rename-identity-stubs.php';
        require_once __DIR__ . '/../Stubs/account-prehijack-stubs.php';
        require_once __DIR__ . '/../Stubs/wp-signin-gate-stubs.php';
        $GLOBALS['__bcc_ph'] = [
            'users' => [], 'meta' => [], 'passwords' => [], 'transients' => [],
            'mail' => [], 'actions' => [], 'audit' => [], 'hashes' => 0, 'fast_hashes' => 0, 'generated' => [],
        ];
        $GLOBALS['__bcc_ph']['users'][self::MEMBER] = ['email' => 'alice@example.test', 'login' => AuthSupport::deriveLogin('alice'), 'display' => 'Alice'];
        $GLOBALS['__bcc_ph']['users'][self::ADMIN]  = ['email' => 'boss@example.test', 'login' => AuthSupport::deriveLogin('boss'), 'display' => 'Boss'];
        $GLOBALS['__bcc_ph']['meta'][self::MEMBER]  = [HandleService::META_HANDLE => 'alice'];
        $GLOBALS['__bcc_ph']['meta'][self::ADMIN]   = [HandleService::META_HANDLE => 'boss'];
        $GLOBALS['__bcc_sig'] = ['admins' => [self::ADMIN], 'current' => 0, 'ajax' => false, 'hooks' => []];

        WordPressSignInGate::register();
    }

    private static function forgotPassword(string $email): int
    {
        return (new PasswordAuthController())->forgotPassword(new \WP_REST_Request(['email' => $email]))->get_status();
    }

    /** @return array<string, array{int, string}> */
    public static function everyRole(): array
    {
        return [
            'a member' => [self::MEMBER, 'alice@example.test'],
            'an admin' => [self::ADMIN, 'boss@example.test'],
        ];
    }

    #[DataProvider('everyRole')]
    public function testTheAppsForgotPasswordStillMailsTheResetLink(int $id, string $email): void
    {
        self::assertSame(200, self::forgotPassword($email));

        self::assertSame([$id], $GLOBALS['__bcc_sig']['reset_keys'], 'a key was minted');
        self::assertCount(1, $GLOBALS['__bcc_ph']['mail']);
        self::assertSame($email, $GLOBALS['__bcc_ph']['mail'][0]['to']);
        self::assertStringContainsString(
            'https://app.example.test/reset-password?key=reset-key-for-' . $id,
            $GLOBALS['__bcc_ph']['mail'][0]['html'],
            'the link points at the app'
        );
    }

    #[DataProvider('everyRole')]
    public function testOutsideTheAppsFlowNoOneGetsAKey(int $id, string $email): void
    {
        self::forgotPassword($email);

        $key = get_password_reset_key(get_user_by('id', $id));

        self::assertInstanceOf(\WP_Error::class, $key);
        self::assertSame(WordPressSignInGate::RESET_ERROR_CODE, $key->get_error_code());
        self::assertSame([$id], $GLOBALS['__bcc_sig']['reset_keys'], "only the app's key");
    }

    public function testAnUnknownAddressStillGetsTheSameAnswerAndNoMail(): void
    {
        self::assertSame(200, self::forgotPassword('nobody@example.test'));

        self::assertArrayNotHasKey('reset_keys', $GLOBALS['__bcc_sig']);
        self::assertSame([], $GLOBALS['__bcc_ph']['mail']);
    }
}
