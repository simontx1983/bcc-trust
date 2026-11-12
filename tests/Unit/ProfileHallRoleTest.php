<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\UserViewService;
use BCC\Trust\Core\Support\GroupRole;
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
 * The member's role in each group on their profile (MemberProfile.halls[]
 * role / role_label) — the roster's vocabulary (GroupRole), shown ONLY where
 * the viewer could already see that group's roster: the member themself, an
 * open group or a Hall, or a group the viewer is an active member of.
 * Elsewhere null, so a profile never reveals who moderates a closed group.
 * A failed status read is null, never a guessed role.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProfileHallRoleTest extends TestCase
{
    private const MEMBER = 50;
    private const VIEWER = 60;

    private const OPEN   = 101;
    private const HALL   = 102;
    private const CLOSED = 103;
    private const SECRET = 104;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-hall-role-stubs.php';
        $GLOBALS['__bcc_phr'] = [
            'groups'   => [self::OPEN, self::HALL, self::CLOSED, self::SECRET],
            'contexts' => [
                self::OPEN   => self::ctx(self::OPEN, PeepSoPrivacy::Open, GroupType::User),
                self::HALL   => self::ctx(self::HALL, PeepSoPrivacy::Closed, GroupType::Hall),
                self::CLOSED => self::ctx(self::CLOSED, PeepSoPrivacy::Closed, GroupType::User),
                self::SECRET => self::ctx(self::SECRET, PeepSoPrivacy::Secret, GroupType::User),
            ],
            'statuses' => [
                self::MEMBER => [
                    self::OPEN   => 'member_moderator',
                    self::HALL   => 'member',
                    self::CLOSED => 'member_manager',
                    self::SECRET => 'member_owner',
                ],
            ],
            'read_fails'   => [],
            'status_reads' => [],
        ];
    }

    private static function ctx(int $id, PeepSoPrivacy $privacy, GroupType $type): GroupContext
    {
        return new GroupContext($id, $type, $privacy, false, null, null, null);
    }

    /** @return array<int, array<string, mixed>> group id => row */
    private static function halls(int $viewerId, bool $withRoles = true): array
    {
        $service = (new ReflectionClass(UserViewService::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(UserViewService::class, 'resolveHalls');
        $m->setAccessible(true);
        /** @var list<array<string, mixed>> $rows */
        $rows = $m->invoke($service, self::MEMBER, $viewerId, $withRoles);
        $out  = [];
        foreach ($rows as $row) {
            $out[(int) $row['id']] = $row;
        }
        return $out;
    }

    /** @param array<int, array<string, mixed>> $halls @return array<int, ?string> */
    private static function roles(array $halls): array
    {
        return array_map(static fn (array $r): ?string => $r['role'] ?? null, $halls);
    }

    public function testTheMemberSeesEveryOwnRole(): void
    {
        self::assertSame(
            [self::OPEN => 'moderator', self::HALL => 'member', self::CLOSED => 'moderator', self::SECRET => 'owner'],
            self::roles(self::halls(self::MEMBER))
        );
        self::assertNotContains(self::VIEWER, $GLOBALS['__bcc_phr']['status_reads'], 'no viewer read for the member themself');
    }

    public function testAnOutsiderSeesRolesOnlyInOpenGroupsAndHalls(): void
    {
        self::assertSame(
            [self::OPEN => 'moderator', self::HALL => 'member', self::CLOSED => null, self::SECRET => null],
            self::roles(self::halls(self::VIEWER))
        );
    }

    public function testAnonymousViewersSeeRolesOnlyInOpenGroupsAndHalls(): void
    {
        self::assertSame(
            [self::OPEN => 'moderator', self::HALL => 'member', self::CLOSED => null, self::SECRET => null],
            self::roles(self::halls(0))
        );
    }

    /** @return array<string, array{string, ?string}> */
    public static function viewerMemberships(): array
    {
        return [
            'member'      => ['member', 'moderator'],
            'read-only'   => ['member_readonly', 'moderator'],
            'owner'       => ['member_owner', 'moderator'],
            'requested'   => ['pending_admin', null],
            'invited'     => ['pending_user', null],
            'banned'      => ['banned', null],
        ];
    }

    #[DataProvider('viewerMemberships')]
    public function testAClosedGroupsRoleIsShownToItsActiveMembersOnly(string $viewerStatus, ?string $expected): void
    {
        $GLOBALS['__bcc_phr']['statuses'][self::VIEWER] = [self::CLOSED => $viewerStatus];

        self::assertSame($expected, self::halls(self::VIEWER)[self::CLOSED]['role']);
    }

    public function testTheLabelIsTheRostersLabel(): void
    {
        $halls = self::halls(self::MEMBER);

        self::assertSame(GroupRole::label('owner'), $halls[self::SECRET]['role_label']);
        self::assertSame('MODERATOR', $halls[self::OPEN]['role_label']);
        self::assertNull(self::halls(self::VIEWER)[self::CLOSED]['role_label']);
    }

    public function testAFailedMemberStatusReadShowsNoRoleAnywhere(): void
    {
        $GLOBALS['__bcc_phr']['read_fails'] = [self::MEMBER];

        foreach (self::halls(self::VIEWER) as $row) {
            self::assertNull($row['role']);
            self::assertNull($row['role_label']);
        }
    }

    public function testAFailedViewerStatusReadHidesClosedGroupRoles(): void
    {
        $GLOBALS['__bcc_phr']['statuses'][self::VIEWER] = [self::CLOSED => 'member'];
        $GLOBALS['__bcc_phr']['read_fails']             = [self::VIEWER];

        $roles = self::roles(self::halls(self::VIEWER));

        self::assertNull($roles[self::CLOSED], 'fail closed: not shown');
        self::assertSame('moderator', $roles[self::OPEN], 'open groups need no viewer read');
    }

    public function testTheSummaryPathMakesNoRoleReadsAndAddsNoKeys(): void
    {
        $halls = self::halls(self::VIEWER, false);

        self::assertSame([], $GLOBALS['__bcc_phr']['status_reads']);
        self::assertArrayNotHasKey('role', $halls[self::OPEN]);
        self::assertArrayNotHasKey('type', $halls[self::OPEN]);
    }

    /**
     * `halls` carries every group the viewer may see, not only Halls; the
     * row's `type` lets a client tell them apart without guessing from a
     * name or slug — on the owner's view and anyone else's.
     */
    public function testEveryRowSaysWhatKindOfGroupItIs(): void
    {
        $expected = [self::OPEN => 'user', self::HALL => 'hall', self::CLOSED => 'user', self::SECRET => 'user'];

        foreach ([self::MEMBER, self::VIEWER, 0] as $viewer) {
            self::assertSame(
                $expected,
                array_map(static fn (array $r): ?string => $r['type'] ?? null, self::halls($viewer)),
                "viewer {$viewer}"
            );
        }
    }

    /**
     * Only the full profile (getUser) pays for the role reads; the summary
     * path (member lists, cards) asks for halls without roles.
     */
    public function testOnlyTheFullProfileAsksForRoles(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Services/UserViewService.php');

        self::assertSame(1, substr_count($src, '$this->resolveHalls($userId, $viewerId, true)'), 'getUser asks for roles');
        self::assertSame(1, substr_count($src, '$this->resolveHalls($userId, $viewerId);'), 'getSummary does not');
        $summary = substr($src, (int) strpos($src, 'public function getSummary('));
        self::assertStringNotContainsString('resolveHalls($userId, $viewerId, true)', substr($summary, 0, (int) strpos($summary, 'private function resolveHalls')));
    }

    public function testEachSideIsReadOnceInABatch(): void
    {
        self::halls(self::VIEWER);

        self::assertSame([self::MEMBER, self::VIEWER], $GLOBALS['__bcc_phr']['status_reads']);
    }
}
