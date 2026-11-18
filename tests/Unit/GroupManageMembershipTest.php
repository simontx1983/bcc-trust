<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\GroupsService;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * F-272 (b): group detail's `can_manage_membership` gates the owner/manager
 * panels (pending requests, invitations). True only for an owner, manager
 * or site admin of a closed OR SECRET plain group — a secret group's only
 * way in is an invitation. Runs the real GroupJoinRequestService::isGroupAdmin
 * over the shared join-request stubs (membership rows, site admins).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupManageMembershipTest extends TestCase
{
    private const GROUP = 4300;
    private const OWNER = 10;
    private const MANAGER = 11;
    private const MODERATOR = 12;
    private const MEMBER = 13;
    private const SITE_ADMIN = 14;
    private const STRANGER = 15;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/group-join-request-stubs.php';
        $GLOBALS['__bcc_gjr'] = [
            'contexts'    => [],
            'memberships' => [
                self::OWNER     => [self::GROUP => 'member_owner'],
                self::MANAGER   => [self::GROUP => 'member_manager'],
                self::MODERATOR => [self::GROUP => 'member_moderator'],
                self::MEMBER    => [self::GROUP => 'member'],
            ],
            'site_admins' => [self::SITE_ADMIN],
            'suspended'   => [],
        ];
    }

    private static function manages(PeepSoPrivacy $privacy, GroupType $type, int $viewer): bool
    {
        $m = new ReflectionMethod(GroupsService::class, 'canManageMembership');
        return (bool) $m->invoke(null, new GroupContext(self::GROUP, $type, $privacy, false, null, null, null), $viewer, self::GROUP);
    }

    /** @return array<string, array{int, bool}> */
    public static function viewers(): array
    {
        return [
            'owner'               => [self::OWNER, true],
            'manager'             => [self::MANAGER, true],
            'site admin'          => [self::SITE_ADMIN, true],
            'moderator (D1: no)'  => [self::MODERATOR, false],
            'member'              => [self::MEMBER, false],
            'stranger'            => [self::STRANGER, false],
            'signed out'          => [0, false],
        ];
    }

    #[DataProvider('viewers')]
    public function testAClosedPlainGroupsAdminsManageMembership(int $viewer, bool $expected): void
    {
        self::assertSame($expected, self::manages(PeepSoPrivacy::Closed, GroupType::User, $viewer));
    }

    #[DataProvider('viewers')]
    public function testASecretPlainGroupsAdminsManageMembershipToo(int $viewer, bool $expected): void
    {
        self::assertSame($expected, self::manages(PeepSoPrivacy::Secret, GroupType::User, $viewer), 'an invite is a secret group\'s only way in');
    }

    public function testASystemGroupIsPlain(): void
    {
        self::assertTrue(self::manages(PeepSoPrivacy::Closed, GroupType::System, self::OWNER));
    }

    public function testAnOpenGroupHasNoMembershipPanel(): void
    {
        self::assertFalse(self::manages(PeepSoPrivacy::Open, GroupType::User, self::OWNER));
        self::assertFalse(self::manages(PeepSoPrivacy::Open, GroupType::User, self::SITE_ADMIN));
    }

    public function testOnChainAndHallGroupsHaveNoMembershipPanel(): void
    {
        foreach ([GroupType::Nft, GroupType::Hall, GroupType::Validator] as $type) {
            foreach ([PeepSoPrivacy::Closed, PeepSoPrivacy::Secret] as $privacy) {
                self::assertFalse(self::manages($privacy, $type, self::OWNER), $type->value . '/' . $privacy->value);
                self::assertFalse(self::manages($privacy, $type, self::SITE_ADMIN), $type->value . '/' . $privacy->value);
            }
        }
    }

    /**
     * getGroup() emits the field and keeps pending_request_count to closed
     * groups (a secret group takes no requests). Structural pin on the
     * composer; the gate's behaviour is proven above.
     */
    public function testTheDetailCarriesTheFlagAndCountsRequestsOnClosedGroupsOnly(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Services/GroupsService.php');

        self::assertSame(1, substr_count($src, '$canManageMembership = self::canManageMembership($ctx, $viewerId, $groupId);'));
        self::assertStringContainsString("'can_manage_membership'        => \$canManageMembership,", $src);
        self::assertStringContainsString('if ($canManageMembership && $ctx->privacy === PeepSoPrivacy::Closed) {', $src);
    }
}
