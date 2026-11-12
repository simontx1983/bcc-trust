<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\GroupsService;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * F-272 group view-model: `permissions.can_request_join` mirrors the
 * request-join gates (so the button and the server reject cannot drift), and
 * `viewer_membership.status` says requested / invited / member / none — a
 * banned row reads as "none" (no disclosure).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupJoinRequestViewModelTest extends TestCase
{
    private const VIEWER = 140;
    private const GROUP  = 4202;

    private GroupsService $groups;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/group-join-request-stubs.php';
        $GLOBALS['__bcc_susp_fixture'] = ['user_id' => self::VIEWER, 'throttled' => false, 'user_meta' => []];
        $GLOBALS['__bcc_gjr'] = ['contexts' => [], 'memberships' => [], 'suspended' => []];

        $class        = new ReflectionClass(GroupsService::class);
        $this->groups = $class->newInstanceWithoutConstructor();
        $class->getProperty('groupContextResolver')->setValue($this->groups, new GroupContextResolver());
    }

    /** @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null} */
    private function canRequest(GroupContext $ctx, int $viewer, bool $isMember, ?string $raw): array
    {
        $m = new ReflectionMethod(GroupsService::class, 'resolveCanRequestJoin');
        $m->setAccessible(true);
        /** @var array{allowed: bool, unlock_hint: string|null, reason_code: string|null} */
        return $m->invoke($this->groups, $ctx, $viewer, $isMember, $raw, self::GROUP);
    }

    private static function ctx(PeepSoPrivacy $privacy, GroupType $type = GroupType::User): GroupContext
    {
        return new GroupContext(self::GROUP, $type, $privacy, false, null, null, null);
    }

    public function testANonMemberOfAClosedPlainGroupMayAsk(): void
    {
        $r = $this->canRequest(self::ctx(PeepSoPrivacy::Closed), self::VIEWER, false, null);

        self::assertTrue($r['allowed']);
        self::assertNull($r['reason_code']);
    }

    /** @return array<string, array{PeepSoPrivacy, GroupType, int, bool, ?string, string}> */
    public static function denials(): array
    {
        return [
            'anonymous'        => [PeepSoPrivacy::Closed, GroupType::User, 0, false, null, 'auth_required'],
            'already a member' => [PeepSoPrivacy::Closed, GroupType::User, self::VIEWER, true, 'member', 'already_member'],
            'open group'       => [PeepSoPrivacy::Open, GroupType::User, self::VIEWER, false, null, 'not_applicable'],
            'secret group'     => [PeepSoPrivacy::Secret, GroupType::User, self::VIEWER, false, 'pending_user', 'not_applicable'],
            'holder group'     => [PeepSoPrivacy::Closed, GroupType::Nft, self::VIEWER, false, null, 'not_applicable'],
            'delegator group'  => [PeepSoPrivacy::Closed, GroupType::Validator, self::VIEWER, false, null, 'not_applicable'],
            'already asked'    => [PeepSoPrivacy::Closed, GroupType::User, self::VIEWER, false, 'pending_admin', 'already_requested'],
            'invited'          => [PeepSoPrivacy::Closed, GroupType::User, self::VIEWER, false, 'pending_user', 'invited'],
            'banned'           => [PeepSoPrivacy::Closed, GroupType::User, self::VIEWER, false, 'banned', 'not_eligible'],
        ];
    }

    #[DataProvider('denials')]
    public function testEveryRequestJoinGateShowsOnTheButton(PeepSoPrivacy $privacy, GroupType $type, int $viewer, bool $isMember, ?string $raw, string $code): void
    {
        $r = $this->canRequest(self::ctx($privacy, $type), $viewer, $isMember, $raw);

        self::assertFalse($r['allowed']);
        self::assertSame($code, $r['reason_code']);
    }

    public function testASuspendedViewerIsNotEligible(): void
    {
        $GLOBALS['__bcc_gjr']['suspended'] = [self::VIEWER];

        self::assertSame('not_eligible', $this->canRequest(self::ctx(PeepSoPrivacy::Closed), self::VIEWER, false, null)['reason_code']);
    }

    public function testADeniedRequestShowsTheCooldown(): void
    {
        $GLOBALS['__bcc_susp_fixture']['user_meta'][self::VIEWER]['bcc_group_join_denied'] = [self::GROUP => time() - 60];

        self::assertSame('cooldown', $this->canRequest(self::ctx(PeepSoPrivacy::Closed), self::VIEWER, false, null)['reason_code']);
    }

    /** @return array<string, array{?string, string, bool}> */
    public static function statuses(): array
    {
        return [
            'no row'    => [null, 'none', false],
            'requested' => ['pending_admin', 'requested', false],
            'invited'   => ['pending_user', 'invited', false],
            'member'    => ['member', 'member', true],
            'banned'    => ['banned', 'none', false],
        ];
    }

    #[DataProvider('statuses')]
    public function testViewerMembershipCarriesThePendingStatus(?string $raw, string $status, bool $isMember): void
    {
        if ($raw !== null) {
            $GLOBALS['__bcc_gjr']['memberships'][self::VIEWER][self::GROUP] = $raw;
        }
        $m = new ReflectionMethod(GroupsService::class, 'renderViewerMembership');
        $m->setAccessible(true);

        $vm = $m->invoke($this->groups, self::VIEWER, self::GROUP);

        self::assertIsArray($vm);
        self::assertSame($status, $vm['status']);
        self::assertSame($isMember, $vm['is_member']);
    }
}
