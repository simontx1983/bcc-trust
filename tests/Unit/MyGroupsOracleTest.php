<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\REST\MyGroupsEndpoint;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\GroupsService;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-47: `POST /me/groups/:id/join` and `/leave` must not confirm that an id is
 * a secret group.
 *
 * Before, a secret group the caller was not in answered join with 403
 * "This community is invite-only." and leave with 200, while an unknown id
 * answered 404 on both. Now both endpoints resolve the group through
 * GroupsService::resolveGroupAccess(), the existence gate the group detail and
 * feed routes already use: a missing group and a secret group the caller is
 * not an active member of get the SAME 404, and nothing is written.
 *
 * Groups whose existence is public keep their answers: a closed group still
 * says it needs approval, and a secret-group MEMBER still gets the
 * invite-only 403.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MyGroupsOracleTest extends TestCase
{
    private const USER      = 140;
    private const SECRET    = 4201;
    private const CLOSED    = 4202;
    private const MISSING   = 4999;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/my-groups-oracle-stubs.php';

        $GLOBALS['__bcc_susp_fixture'] = ['user_id' => self::USER, 'suspended' => false, 'throttled' => false];
        $GLOBALS['__bcc_mg'] = [
            'contexts'    => [
                self::SECRET => self::context(self::SECRET, PeepSoPrivacy::Secret),
                self::CLOSED => self::context(self::CLOSED, PeepSoPrivacy::Closed),
            ],
            'memberships' => [],
            'writes'      => [],
        ];

        // The REAL GroupsService, built without its constructor: only its
        // context resolver (faked at its FQN) and the membership read
        // (PeepSoGroupRepository, faked) are reached by resolveGroupAccess().
        $class  = new ReflectionClass(GroupsService::class);
        $groups = $class->newInstanceWithoutConstructor();
        $class->getProperty('groupContextResolver')->setValue($groups, new GroupContextResolver());
        (new ReflectionClass(Plugin::class))->getProperty('groupsService')->setValue(Plugin::instance(), $groups);
    }

    private static function context(int $groupId, PeepSoPrivacy $privacy): GroupContext
    {
        return new GroupContext($groupId, GroupType::User, $privacy, false, null, null, null);
    }

    /** @return array{0: int, 1: mixed} */
    private static function call(string $method, int $groupId): array
    {
        $response = (new MyGroupsEndpoint())->{$method}(new \WP_REST_Request(['id' => $groupId]));

        return [$response->get_status(), $response->get_data()];
    }

    public function testJoiningASecretGroupYouAreNotInAnswersExactlyLikeAMissingId(): void
    {
        $missing = self::call('postJoin', self::MISSING);
        $secret  = self::call('postJoin', self::SECRET);

        self::assertSame(404, $missing[0]);
        self::assertSame($missing, $secret, 'a secret group must be indistinguishable from a missing id');
        self::assertSame([], $GLOBALS['__bcc_mg']['writes']);
    }

    public function testLeavingASecretGroupYouAreNotInAnswersExactlyLikeAMissingId(): void
    {
        $missing = self::call('postLeave', self::MISSING);
        $secret  = self::call('postLeave', self::SECRET);

        self::assertSame(404, $missing[0]);
        self::assertSame($missing, $secret);
        self::assertSame([], $GLOBALS['__bcc_mg']['writes'], 'no leave write for a group the caller cannot see');
    }

    public function testAClosedGroupStillSaysItNeedsApproval(): void
    {
        [$status, $data] = self::call('postJoin', self::CLOSED);

        self::assertSame(403, $status);
        self::assertSame('bcc_permission_denied', $data['error']['code']);
        self::assertSame(
            'This community requires admin approval. Visit the group page to request access.',
            $data['error']['message']
        );
        self::assertSame([], $GLOBALS['__bcc_mg']['writes'], 'F-96: a refused join writes nothing');
    }

    public function testASecretGroupMemberStillGetsTheInviteOnly403(): void
    {
        $GLOBALS['__bcc_mg']['memberships'][self::USER][self::SECRET] = 'member';

        [$status, $data] = self::call('postJoin', self::SECRET);

        self::assertSame(403, $status);
        self::assertSame('This community is invite-only.', $data['error']['message']);
        self::assertSame([], $GLOBALS['__bcc_mg']['writes'], 'F-96: a refused join writes nothing');
    }

    public function testASecretGroupMemberCanStillLeave(): void
    {
        $GLOBALS['__bcc_mg']['memberships'][self::USER][self::SECRET] = 'member';

        [$status] = self::call('postLeave', self::SECRET);

        self::assertSame(200, $status);
        self::assertSame([['leave', self::USER, self::SECRET]], $GLOBALS['__bcc_mg']['writes']);
    }
}
