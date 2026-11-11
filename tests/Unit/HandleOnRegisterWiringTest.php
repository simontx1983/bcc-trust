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

    /** @return array<string, array{string}> */
    public static function signupControllers(): array
    {
        return [
            'password' => ['app/Domain/Core/REST/Auth/PasswordAuthController.php'],
            'wallet'   => ['app/Domain/Core/REST/Auth/WalletAuthController.php'],
            'oauth'    => ['app/Domain/Core/REST/Auth/OAuthController.php'],
        ];
    }

    #[DataProvider('signupControllers')]
    public function testSignupPassesItsHandleViaMetaInput(string $rel): void
    {
        $src = self::src($rel);
        self::assertMatchesRegularExpression(
            "/wp_insert_user\(\[(?:(?!\]\);).)*'meta_input'\s*=>\s*\[HandleService::META_HANDLE\s*=>\s*\\\$handle\]/s",
            $src
        );
    }
}
