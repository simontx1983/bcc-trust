<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\REST\MyGroupsEndpoint;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\GroupsService;
use BCC\Trust\Core\Support\GroupLeaveGuard;
use BCC\Trust\Onchain\REST\HolderGroupsEndpoint;
use BCC\Trust\Onchain\REST\ValidatorGroupsEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The three leave routes (plain groups, holder groups, delegator
 * communities) used to answer ANY PeepSoGroupWriter::leave() false with 403
 * "Owners cannot leave their own community". Since bcc-core #112, leave()
 * also returns false for a busy per-pair lock, a failed read or PeepSo
 * absent, so an ordinary member was told they were the owner.
 *
 * Now, on every route:
 *   - the owner gets the owner 403, and leave() is never called;
 *   - a failed membership read is 503, and leave() is never called;
 *   - any other false from leave() is 503 bcc_unavailable, never the owner copy.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupLeaveGuardTest extends TestCase
{
    private const USER  = 140;
    private const GROUP = 4300;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/group-leave-guard-stubs.php';

        $GLOBALS['__bcc_susp_fixture'] = ['user_id' => self::USER, 'suspended' => false, 'throttled' => false];
        $GLOBALS['__bcc_lg']           = ['status' => 'member', 'leaves' => [], 'audits' => []];

        $class  = new ReflectionClass(GroupsService::class);
        $groups = $class->newInstanceWithoutConstructor();
        $class->getProperty('groupContextResolver')->setValue($groups, new GroupContextResolver());
        (new ReflectionClass(Plugin::class))->getProperty('groupsService')->setValue(Plugin::instance(), $groups);
    }

    /** @return array<string, array{class-string}> */
    public static function routes(): array
    {
        return [
            'plain group (MyGroupsEndpoint)'               => [MyGroupsEndpoint::class],
            'holder group (HolderGroupsEndpoint)'          => [HolderGroupsEndpoint::class],
            'delegator community (ValidatorGroupsEndpoint)' => [ValidatorGroupsEndpoint::class],
        ];
    }

    /**
     * @param class-string $endpoint
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function leave(string $endpoint): array
    {
        $response = (new $endpoint())->postLeave(new \WP_REST_Request(['id' => self::GROUP]));
        $data     = $response->get_data();
        return [$response->get_status(), is_array($data) ? $data : []];
    }

    /** @param class-string $endpoint */
    #[DataProvider('routes')]
    public function testTheOwnerIsToldTheyAreTheOwnerAndNothingIsWritten(string $endpoint): void
    {
        $GLOBALS['__bcc_lg']['status'] = 'member_owner';

        [$status, $data] = self::leave($endpoint);

        self::assertSame(403, $status);
        self::assertSame(GroupLeaveGuard::OWNER_MESSAGE, $data['error']['message'] ?? null);
        self::assertSame([], $GLOBALS['__bcc_lg']['leaves']);
    }

    /** @param class-string $endpoint */
    #[DataProvider('routes')]
    public function testAFailedMembershipReadIs503AndNothingIsWritten(string $endpoint): void
    {
        $GLOBALS['__bcc_lg']['read_failed'] = true;

        [$status, $data] = self::leave($endpoint);

        self::assertSame(503, $status);
        self::assertSame('bcc_unavailable', $data['error']['code'] ?? null);
        self::assertSame([], $GLOBALS['__bcc_lg']['leaves']);
    }

    /**
     * The bug: a member whose leave() failed for any other reason (a busy
     * pair lock, a failed write, PeepSo absent) was told they own the group.
     *
     * @param class-string $endpoint
     */
    #[DataProvider('routes')]
    public function testAMembersFailedLeaveIs503NotTheOwnerMessage(string $endpoint): void
    {
        $GLOBALS['__bcc_lg']['leave_result'] = false;

        [$status, $data] = self::leave($endpoint);

        self::assertSame(503, $status);
        self::assertSame('bcc_unavailable', $data['error']['code'] ?? null);
        self::assertNotSame(GroupLeaveGuard::OWNER_MESSAGE, $data['error']['message'] ?? null);
        self::assertSame([[self::USER, self::GROUP]], $GLOBALS['__bcc_lg']['leaves']);
    }

    public function testAPlainGroupMemberStillLeavesAndTheLeaveIsAudited(): void
    {
        [$status] = self::leave(MyGroupsEndpoint::class);

        self::assertSame(200, $status);
        self::assertSame([[self::USER, self::GROUP]], $GLOBALS['__bcc_lg']['leaves']);
        self::assertSame('group_leave', $GLOBALS['__bcc_lg']['audits'][0][0] ?? null);
    }

    public function testLeavingAGroupYouAreNotInIsNotAuditedAsALeave(): void
    {
        $GLOBALS['__bcc_lg']['status'] = null;

        [$status] = self::leave(MyGroupsEndpoint::class);

        self::assertSame(200, $status);
        self::assertSame([], $GLOBALS['__bcc_lg']['audits'], 'the pre-read still tells a real leave from an idempotent one');
    }
}
