<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Source pins for the handle-at-creation wiring, which a unit test can't
 * drive through real WordPress:
 *
 * - Plugin.php hooks user_register to bcc_trust_assign_handle_if_missing
 *   at priority 10 (before the priority-30 welcome notification, which
 *   renders the handle).
 * - Each BCC signup path passes its chosen handle via wp_insert_user
 *   meta_input. Core saves meta_input BEFORE firing user_register, which
 *   is what stops the hook from assigning a derived handle first.
 */
final class HandleOnRegisterWiringTest extends TestCase
{
    private static function src(string $rel): string
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
        self::assertIsString($src);
        return $src;
    }

    public function testPluginHooksAssignmentOnUserRegisterAtPriority10(): void
    {
        $src = self::src('app/Domain/Core/Plugin.php');
        self::assertMatchesRegularExpression(
            "/add_action\('user_register',\s*static function \(int \\\$userId\): void \{\s*"
            . "if \(function_exists\('bcc_trust_assign_handle_if_missing'\)\) \{\s*"
            . "bcc_trust_assign_handle_if_missing\(\\\$userId\);\s*\}\s*\},\s*10,\s*1\);/",
            $src
        );
    }

    /**
     * The second value is F-253's has-password flag each path writes: only
     * a password signup knows its password; wallet and OAuth signups get a
     * random, never-shown one.
     *
     * @return array<string, array{string, string}>
     */
    public static function signupControllers(): array
    {
        return [
            'password' => ['app/Domain/Core/REST/Auth/PasswordAuthController.php', '1'],
            'wallet'   => ['app/Domain/Core/REST/Auth/WalletAuthController.php', '0'],
            'oauth'    => ['app/Domain/Core/REST/Auth/OAuthController.php', '0'],
        ];
    }

    /** The wp_insert_user([...]) call's meta_input array, as source. */
    private static function metaInput(string $src): string
    {
        self::assertSame(
            1,
            preg_match("/wp_insert_user\(\[(?:(?!\]\);).)*'meta_input'\s*=>\s*\[(.*?)\]\s*,?\s*\]\);/s", $src, $m),
            'one wp_insert_user call with a meta_input array'
        );
        return $m[1];
    }

    #[DataProvider('signupControllers')]
    public function testSignupPassesItsHandleViaMetaInput(string $rel, string $hasPassword): void
    {
        self::assertMatchesRegularExpression(
            "/^\s*HandleService::META_HANDLE\s*=>\s*\\\$handle\s*,/",
            self::metaInput(self::src($rel))
        );
    }

    #[DataProvider('signupControllers')]
    public function testSignupRecordsWhetherTheMemberKnowsTheirPassword(string $rel, string $hasPassword): void
    {
        self::assertMatchesRegularExpression(
            "/AccountRecoveryService::META_HAS_PASSWORD\s*=>\s*'" . $hasPassword . "'\s*,/",
            self::metaInput(self::src($rel))
        );
    }
}
