<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\HandleService;
use BCC\Trust\Core\Support\PublicIdentity;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Review fixes on the PublicIdentity change:
 *
 * M1: 'member-{id}' is the system fallback handle for member {id}. A user
 *     must not be able to CHOOSE one (signup or rename) and squat another
 *     member's fallback handle and links. Only the internal assignment
 *     path may write it, and only for its own id.
 * H1: PublicIdentity::forUser() accepts the plain row objects some callers
 *     pass, not only WP_User.
 */
#[CoversMethod(HandleService::class, 'validate')]
#[CoversMethod(PublicIdentity::class, 'forUser')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MemberFallbackHandleReservedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (!\function_exists('apply_filters')) {
            eval('function apply_filters(string $hook, $value, ...$args) { return $value; }');
        }
        if (!\function_exists('get_user_meta')) {
            eval('function get_user_meta(int $id, string $key = "", bool $single = false) { return $GLOBALS["__bcc_um"][$id][$key] ?? ""; }');
        }
        $GLOBALS['__bcc_um'] = [];
    }

    public function testUserChosenMemberHandleIsReserved(): void
    {
        $svc = new HandleService();
        // What signup and rename call: validate($handle), no assignment id.
        self::assertSame(HandleService::ERR_RESERVED, $svc->validate('member-7'));
        self::assertSame(HandleService::ERR_RESERVED, $svc->validate('member-123456'));
    }

    public function testInternalAssignmentMayWriteItsOwnMemberHandle(): void
    {
        $svc = new HandleService();
        self::assertNull($svc->validate('member-7', 7));
    }

    public function testInternalAssignmentMayNotWriteAnotherMembersHandle(): void
    {
        $svc = new HandleService();
        self::assertSame(HandleService::ERR_RESERVED, $svc->validate('member-7', 8));
    }

    public function testOnlyTheExactFallbackShapeIsReserved(): void
    {
        $svc = new HandleService();
        self::assertNull($svc->validate('member-7-2'));
        self::assertNull($svc->validate('members-7'));
        self::assertNull($svc->validate('member-x'));
        self::assertNull($svc->validate('old-member-7'));
    }

    /** @return array<string, array{string}> */
    public static function userFacingCallers(): array
    {
        return [
            'password signup' => ['app/Domain/Core/REST/Auth/PasswordAuthController.php'],
            'wallet signup'   => ['app/Domain/Core/REST/Auth/WalletAuthController.php'],
            'oauth signup'    => ['app/Domain/Core/REST/Auth/OAuthController.php'],
            'rename'          => ['app/Domain/Core/REST/OnboardingEndpoint.php'],
        ];
    }

    /**
     * Signup and rename must reach the reservation: they call validate()
     * with the handle alone, never an assignment id.
     */
    #[DataProvider('userFacingCallers')]
    public function testSignupAndRenameValidateWithoutAnAssignmentId(string $rel): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
        self::assertMatchesRegularExpression('/\$handleService->validate\(\$handle\);/', $src);
        self::assertDoesNotMatchRegularExpression('/->validate\(\$handle\s*,/', $src);
    }

    public function testForUserAcceptsAPlainRowObject(): void
    {
        $GLOBALS['__bcc_um'][9]['bcc_handle'] = 'nina';
        $row = (object) ['ID' => '9', 'display_name' => 'u_nina', 'user_login' => 'u_nina'];

        self::assertSame(['handle' => 'nina', 'display_name' => 'nina'], PublicIdentity::forUser($row));
    }

    public function testForUserToleratesMissingProperties(): void
    {
        self::assertSame(
            ['handle' => 'member-0', 'display_name' => 'member-0'],
            PublicIdentity::forUser(new \stdClass())
        );
    }
}
