<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\Auth\AuthSupport;
use BCC\Trust\Core\Security\CorePasswordPolicy;
use BCC\Trust\Core\Services\BreachedPasswordCheck;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Security review (2026-11-18): WordPress's own password paths skipped
 * BCC's 8-character minimum and the breached-password check: the core
 * reset form, wp-admin profile.php / user-edit.php / user-new.php (admins
 * included) and core REST user writes. CorePasswordPolicy runs the same
 * rules on all three, through one violation() that reuses the constant,
 * BreachedPasswordCheck and its copy. It fails open as BCC's routes do.
 *
 * Over breached-password-stubs.php (a fake range API) and WordPress
 * doubles defined here first, so they are the ones that load.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CorePasswordPolicyTest extends TestCase
{
    private const LEAKED = 'password123';
    private const GOOD   = 'a long unique passphrase';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__cpp'] = ['hooks' => []];
        if (!class_exists('WP_Error', false)) {
            eval('class WP_Error { public array $errors = []; public array $data = []; public function __construct(string $code = "", string $message = "", mixed $data = null) { if ($code !== "") { $this->add($code, $message, $data); } } public function add(string $code, string $message, mixed $data = null): void { $this->errors[$code][] = $message; if ($data !== null) { $this->data[$code] = $data; } } public function get_error_codes(): array { return array_keys($this->errors); } public function get_error_code(): string { return (string) (array_key_first($this->errors) ?? ""); } public function get_error_message(): string { $c = $this->get_error_code(); return $c === "" ? "" : (string) $this->errors[$c][0]; } public function get_error_data(string $code = ""): mixed { return $this->data[$code !== "" ? $code : $this->get_error_code()] ?? null; } }');
        }
        if (!function_exists('is_wp_error')) {
            eval('function is_wp_error($t): bool { return $t instanceof \WP_Error; }');
        }
        if (!function_exists('wp_unslash')) {
            eval('function wp_unslash($v) { return is_string($v) ? stripslashes($v) : $v; }');
        }
        if (!function_exists('add_action')) {
            eval('function add_action(string $h, $cb, int $p = 10, int $a = 1): bool { $GLOBALS["__cpp"]["hooks"][] = ["action", $h, $cb, $p, $a]; return true; }');
        }
        if (!function_exists('add_filter')) {
            eval('function add_filter(string $h, $cb, int $p = 10, int $a = 1): bool { $GLOBALS["__cpp"]["hooks"][] = ["filter", $h, $cb, $p, $a]; return true; }');
        }
        require_once __DIR__ . '/../Stubs/breached-password-stubs.php';
        $GLOBALS['__bcc_hibp'] = ['calls' => [], 'metrics' => [], 'logs' => []];
        unset($_POST['pass1']);
    }

    /** Make the fake range API list $password. */
    private static function leaked(string $password): void
    {
        $GLOBALS['__bcc_hibp']['response'] = [
            'response' => ['code' => 200],
            'body'     => substr(strtoupper(sha1($password)), 5) . ":12\r\n",
        ];
    }

    private static function outage(): void
    {
        $GLOBALS['__bcc_hibp']['response'] = ['response' => ['code' => 503], 'body' => ''];
    }

    // ── the shared rule ───────────────────────────────────────────────────

    public function testTheRuleIsBccsOwn(): void
    {
        self::leaked(self::LEAKED);

        self::assertNull(CorePasswordPolicy::violation(self::GOOD));
        $short = CorePasswordPolicy::violation(str_repeat('x', AuthSupport::SIGNUP_MIN_PASSWORD_LENGTH - 1));
        self::assertSame('bcc_invalid_request', $short['code'] ?? null);
        self::assertSame('Password must be at least 8 characters.', $short['message'] ?? null);
        self::assertNull(CorePasswordPolicy::violation(str_repeat('x', AuthSupport::SIGNUP_MIN_PASSWORD_LENGTH)), 'exactly the minimum passes');
        $leaked = CorePasswordPolicy::violation(self::LEAKED);
        self::assertSame(BreachedPasswordCheck::ERROR_CODE, $leaked['code'] ?? null);
        self::assertSame(BreachedPasswordCheck::ERROR_MESSAGE, $leaked['message'] ?? null);
    }

    public function testAShortPasswordCostsNoBreachLookup(): void
    {
        CorePasswordPolicy::violation('short');

        self::assertSame([], $GLOBALS['__bcc_hibp']['calls']);
    }

    public function testItFailsOpenLikeBccsRoutes(): void
    {
        self::outage();

        self::assertNull(CorePasswordPolicy::violation(self::LEAKED), 'an outage allows the password');
        self::assertNotSame([], $GLOBALS['__bcc_hibp']['metrics'], 'and records the metric');
    }

    // ── wp-login.php reset ────────────────────────────────────────────────

    public function testTheCoreResetFormRefusesALeakedPassword(): void
    {
        self::leaked(self::LEAKED);
        $_POST['pass1'] = self::LEAKED;
        $errors = new \WP_Error();

        CorePasswordPolicy::onValidatePasswordReset($errors, new \stdClass());

        self::assertSame([BreachedPasswordCheck::ERROR_CODE], $errors->get_error_codes(), 'core resets only when this is empty');
        self::assertSame(['form-field' => 'pass1'], $errors->get_error_data(BreachedPasswordCheck::ERROR_CODE));
    }

    public function testTheCoreResetFormRefusesAShortPassword(): void
    {
        $_POST['pass1'] = 'short';
        $errors = new \WP_Error();

        CorePasswordPolicy::onValidatePasswordReset($errors, new \stdClass());

        self::assertSame(['bcc_invalid_request'], $errors->get_error_codes());
    }

    public function testTheCoreResetFormChecksTheUnslashedPassword(): void
    {
        // wp_magic_quotes slashes $_POST; the member typed the unslashed one.
        $typed = "it's a long passphrase";
        self::leaked($typed);
        $_POST['pass1'] = addslashes($typed);
        $errors = new \WP_Error();

        CorePasswordPolicy::onValidatePasswordReset($errors, new \stdClass());

        self::assertSame([BreachedPasswordCheck::ERROR_CODE], $errors->get_error_codes());
    }

    public function testTheCoreResetFormPassesAGoodPasswordAndLeavesEmptyToCore(): void
    {
        self::leaked(self::LEAKED);
        foreach ([self::GOOD, '', '   '] as $typed) {
            $_POST['pass1'] = $typed;
            $errors = new \WP_Error();
            CorePasswordPolicy::onValidatePasswordReset($errors, new \stdClass());
            self::assertSame([], $errors->get_error_codes(), var_export($typed, true));
        }
    }

    // ── wp-admin profile / user-edit / user-new ───────────────────────────

    public function testAProfilePasswordChangeIsRefused(): void
    {
        self::leaked(self::LEAKED);
        $user = new \stdClass();
        $user->user_pass = self::LEAKED;
        $errors = new \WP_Error();

        CorePasswordPolicy::onProfileUpdateErrors($errors, true, $user);

        self::assertSame([BreachedPasswordCheck::ERROR_CODE], $errors->get_error_codes());
    }

    public function testAProfileSaveWithoutANewPasswordIsNotChecked(): void
    {
        self::leaked(self::LEAKED);
        $errors = new \WP_Error();

        CorePasswordPolicy::onProfileUpdateErrors($errors, true, new \stdClass());

        self::assertSame([], $errors->get_error_codes());
        self::assertSame([], $GLOBALS['__bcc_hibp']['calls'], 'no lookup at all');
    }

    public function testANewUserWithAShortPasswordIsRefused(): void
    {
        $user = new \stdClass();
        $user->user_pass = 'short';
        $errors = new \WP_Error();

        CorePasswordPolicy::onProfileUpdateErrors($errors, false, $user);

        self::assertSame(['bcc_invalid_request'], $errors->get_error_codes());
    }

    // ── core REST user writes ─────────────────────────────────────────────

    public function testACoreRestPasswordWriteIsRefused(): void
    {
        self::leaked(self::LEAKED);
        $prepared = new \stdClass();
        $prepared->user_pass = self::LEAKED;

        $res = CorePasswordPolicy::onRestPreInsertUser($prepared, null);

        self::assertInstanceOf(\WP_Error::class, $res);
        self::assertSame(BreachedPasswordCheck::ERROR_CODE, $res->get_error_code());
        self::assertSame(['status' => 422], $res->get_error_data());
    }

    public function testACoreRestWriteWithoutAPasswordPassesUntouched(): void
    {
        $prepared = new \stdClass();
        $prepared->display_name = 'x';

        self::assertSame($prepared, CorePasswordPolicy::onRestPreInsertUser($prepared, null));
        $good = new \stdClass();
        $good->user_pass = self::GOOD;
        self::assertSame($good, CorePasswordPolicy::onRestPreInsertUser($good, null));
    }

    public function testItHooksAllThreePaths(): void
    {
        CorePasswordPolicy::register();

        self::assertSame([
            ['action', 'validate_password_reset', [CorePasswordPolicy::class, 'onValidatePasswordReset'], 10, 2],
            ['action', 'user_profile_update_errors', [CorePasswordPolicy::class, 'onProfileUpdateErrors'], 10, 3],
            ['filter', 'rest_pre_insert_user', [CorePasswordPolicy::class, 'onRestPreInsertUser'], 10, 2],
        ], $GLOBALS['__cpp']['hooks']);
        $boot = (string) file_get_contents(__DIR__ . '/../../bcc-trust.php');
        self::assertStringContainsString('\\BCC\\Trust\\Core\\Security\\CorePasswordPolicy::register();', $boot, 'registered at boot');
    }
}
