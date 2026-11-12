<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\REST\GroupJoinRequestsEndpoint;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\GroupsService;
use BCC\Trust\Core\Services\NotificationDispatcher;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F-272: requests to join closed groups and invitations to closed/secret
 * groups, through the REAL endpoint and service (bcc-core faked).
 *
 * Pins, per door:
 *   - the server-side gates run BEFORE the writer door (peepso-write-guard):
 *     sign-in, suspension (both sides), plain group type, privacy, actor
 *     authority (owner / manager / site admin — never a moderator, D1),
 *     the invitee check, the deny cooldown (D3), the invite cap;
 *   - a refused request writes nothing;
 *   - every door runs under the service's per-pair lock;
 *   - the writer's OUTCOME_* maps to 409 / 403 / 503, a busy lock to 409;
 *   - a secret group is 404 to anyone who is not its member or invitee,
 *     exactly like a missing id (F-47).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupJoinRequestServiceTest extends TestCase
{
    private const REQUESTER  = 140;
    private const OWNER      = 141;
    private const MANAGER    = 142;
    private const MODERATOR  = 143;
    private const MEMBER     = 144;
    private const SITE_ADMIN = 145;
    private const INVITEE    = 146;

    private const SECRET  = 4201;
    private const CLOSED  = 4202;
    private const OPEN    = 4203;
    private const NFT     = 4204;
    private const MISSING = 4999;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/group-join-request-stubs.php';

        $GLOBALS['__bcc_susp_fixture'] = ['user_id' => self::REQUESTER, 'throttled' => false, 'user_meta' => []];
        $GLOBALS['__bcc_gjr'] = [
            'contexts' => [
                self::SECRET => self::ctx(self::SECRET, PeepSoPrivacy::Secret),
                self::CLOSED => self::ctx(self::CLOSED, PeepSoPrivacy::Closed),
                self::OPEN   => self::ctx(self::OPEN, PeepSoPrivacy::Open),
                self::NFT    => self::ctx(self::NFT, PeepSoPrivacy::Closed, GroupType::Nft),
            ],
            'memberships' => [
                self::OWNER     => [self::CLOSED => 'member_owner', self::SECRET => 'member_owner', self::OPEN => 'member_owner', self::NFT => 'member_owner'],
                self::MANAGER   => [self::CLOSED => 'member_manager'],
                self::MODERATOR => [self::CLOSED => 'member_moderator'],
                self::MEMBER    => [self::CLOSED => 'member'],
            ],
            'suspended'   => [],
            'site_admins' => [self::SITE_ADMIN],
            'users'       => [self::REQUESTER, self::OWNER, self::MANAGER, self::MODERATOR, self::MEMBER, self::SITE_ADMIN, self::INVITEE],
            'writes'      => [],
            'notes'       => [],
            'audits'      => [],
        ];

        // The REAL GroupsService (existence gate), built without its
        // constructor; only its context resolver (faked) is reached.
        $class  = new ReflectionClass(GroupsService::class);
        $groups = $class->newInstanceWithoutConstructor();
        $class->getProperty('groupContextResolver')->setValue($groups, new GroupContextResolver());
        $plugin = new ReflectionClass(Plugin::class);
        $plugin->getProperty('groupsService')->setValue(Plugin::instance(), $groups);
        $plugin->getProperty('notificationDispatcher')->setValue(Plugin::instance(), new NotificationDispatcher());
    }

    private static function ctx(int $id, PeepSoPrivacy $privacy, GroupType $type = GroupType::User): GroupContext
    {
        return new GroupContext($id, $type, $privacy, false, null, null, null);
    }

    /**
     * @param array<string, int> $params
     * @return array{0: int, 1: array<string, mixed>}
     */
    private static function call(string $handler, array $params, int $asUser): array
    {
        $GLOBALS['__bcc_susp_fixture']['user_id'] = $asUser;
        $response = (new GroupJoinRequestsEndpoint())->{$handler}(new \WP_REST_Request($params));
        $data     = $response->get_data();
        return [$response->get_status(), is_array($data) ? $data : []];
    }

    private static function errorCode(array $data): ?string
    {
        return $data['error']['code'] ?? null;
    }

    /** @return list<array{0: string, 1: int, 2: int, 3: ?int, 4: bool}> */
    private static function writes(): array
    {
        return $GLOBALS['__bcc_gjr']['writes'];
    }

    // ── requester ────────────────────────────────────────────────────

    public function testAMemberCanAskToJoinAClosedGroupAndTheOwnersAndManagersAreTold(): void
    {
        [$status, $data] = self::call('postRequestJoin', ['id' => self::CLOSED], self::REQUESTER);

        self::assertSame(200, $status);
        self::assertSame('requested', $data['data']['status'] ?? $data['status'] ?? null);
        self::assertSame([['requestJoin', self::REQUESTER, self::CLOSED, null, true]], self::writes(), 'one door call, under the lock');
        $note = $GLOBALS['__bcc_gjr']['notes'][0];
        self::assertSame(['requested', self::REQUESTER, self::CLOSED], array_slice($note, 0, 3));
        sort($note[3]);
        self::assertSame([self::OWNER, self::MANAGER], $note[3], 'owners and managers only — not moderators or members');
        self::assertSame([['group_join_requested', self::CLOSED]], $GLOBALS['__bcc_gjr']['audits']);
    }

    /** @return array<string, array{int, int, string}> */
    public static function refusedRequests(): array
    {
        return [
            'open group: just join'      => [self::OPEN, 400, 'bcc_invalid_request'],
            'holder group: its own flow' => [self::NFT, 400, 'bcc_invalid_request'],
            'secret group: not visible'  => [self::SECRET, 404, 'bcc_invalid_request'],
            'missing group'              => [self::MISSING, 404, 'bcc_invalid_request'],
        ];
    }

    #[DataProvider('refusedRequests')]
    public function testARequestOutsideAClosedPlainGroupIsRefusedAndWritesNothing(int $groupId, int $status, string $code): void
    {
        [$got, $data] = self::call('postRequestJoin', ['id' => $groupId], self::REQUESTER);

        self::assertSame($status, $got);
        self::assertSame($code, self::errorCode($data));
        self::assertSame([], self::writes());
    }

    public function testASecretGroupAnswersARequestExactlyLikeAMissingId(): void
    {
        self::assertSame(
            self::call('postRequestJoin', ['id' => self::MISSING], self::REQUESTER),
            self::call('postRequestJoin', ['id' => self::SECRET], self::REQUESTER)
        );
    }

    public function testASuspendedAccountCannotAsk(): void
    {
        $GLOBALS['__bcc_gjr']['suspended'] = [self::REQUESTER];

        [$status, $data] = self::call('postRequestJoin', ['id' => self::CLOSED], self::REQUESTER);

        self::assertSame(403, $status);
        self::assertSame('bcc_forbidden', self::errorCode($data));
        self::assertSame([], self::writes());
        self::assertNotContains(true, $GLOBALS['__bcc_gjr']['bypass_args'], 'admin bypass off');
    }

    public function testSignedOutIs401OnEveryRoute(): void
    {
        foreach (['postRequestJoin', 'postCancelRequest', 'postAcceptInvite', 'postDeclineInvite', 'getRequests', 'postApprove', 'postDeny', 'getInvites', 'postInvite', 'postRevokeInvite', 'getMyInvites'] as $handler) {
            [$status] = self::call($handler, ['id' => self::CLOSED, 'user_id' => self::REQUESTER], 0);
            self::assertSame(401, $status, $handler);
        }
        self::assertSame([], self::writes());
    }

    // ── USER DECISION: system groups (BCC-created) take requests too ──

    public function testAClosedSystemGroupAcceptsRequests(): void
    {
        $GLOBALS['__bcc_gjr']['contexts'][4205] = self::ctx(4205, PeepSoPrivacy::Closed, GroupType::System);
        $GLOBALS['__bcc_gjr']['memberships'][self::OWNER][4205] = 'member_owner';

        [$status] = self::call('postRequestJoin', ['id' => 4205], self::REQUESTER);

        self::assertSame(200, $status);
        self::assertSame([['requestJoin', self::REQUESTER, 4205, null, true]], self::writes());
    }

    /** A ban and a block answer identically, so neither is inferable by elimination. */
    public function testABannedRequesterGetsTheSameGenericRefusalAsABlock(): void
    {
        $GLOBALS['__bcc_gjr']['outcome'] = 'refused';
        $banned = self::call('postRequestJoin', ['id' => self::CLOSED], self::REQUESTER);

        $GLOBALS['__bcc_gjr']['outcome'] = 'done';
        $GLOBALS['__bcc_gjr']['blocks']  = [[self::OWNER, self::REQUESTER]];
        $blocked = self::call('postRequestJoin', ['id' => self::CLOSED], self::REQUESTER);

        self::assertSame(403, $banned[0]);
        self::assertSame("You can't join this community.", $banned[1]['error']['message'] ?? null);
        self::assertSame($blocked, $banned);
        self::assertSame([], $GLOBALS['__bcc_gjr']['notes']);
    }

    // ── USER DECISION: a block either way silently refuses ─────────

    /** @return array<string, array{list<array{int, int}>, list<int>}> */
    public static function ownerBlocks(): array
    {
        return [
            'requester blocked the owner' => [[[self::REQUESTER, self::OWNER]], []],
            'owner blocked the requester' => [[[self::OWNER, self::REQUESTER]], []],
            'block state unreadable'      => [[], [self::OWNER]],
        ];
    }

    /** @param list<array{int, int}> $blocks @param list<int> $unknown */
    #[DataProvider('ownerBlocks')]
    public function testABlockWithTheOwnerSilentlyRefusesTheRequest(array $blocks, array $unknown): void
    {
        $GLOBALS['__bcc_gjr']['blocks']        = $blocks;
        $GLOBALS['__bcc_gjr']['block_unknown'] = $unknown;

        [$status, $data] = self::call('postRequestJoin', ['id' => self::CLOSED], self::REQUESTER);

        self::assertSame(403, $status);
        self::assertSame("You can't join this community.", $data['error']['message'] ?? null, 'the generic refusal: nothing about a block');
        self::assertSame([], self::writes());
        self::assertSame([], $GLOBALS['__bcc_gjr']['notes'], 'nobody is told');
    }

    /**
     * The roster read answers [] when it fails; without an owner row the
     * owner-block check would be silently skipped. Refuse, retryably.
     */
    public function testAFailedRosterReadRefusesTheRequestRetryably(): void
    {
        $GLOBALS['__bcc_gjr']['roster_fails'] = true;
        $GLOBALS['__bcc_gjr']['blocks']       = [[self::OWNER, self::REQUESTER]];

        [$status, $data] = self::call('postRequestJoin', ['id' => self::CLOSED], self::REQUESTER);

        self::assertSame(503, $status);
        self::assertSame('bcc_unavailable', $data['error']['code'] ?? null);
        self::assertSame([], self::writes());
        self::assertSame([], $GLOBALS['__bcc_gjr']['notes']);
    }

    public function testAManagerBlockedWithTheRequesterIsNotNotified(): void
    {
        $GLOBALS['__bcc_gjr']['blocks'] = [[self::MANAGER, self::REQUESTER]];

        [$status] = self::call('postRequestJoin', ['id' => self::CLOSED], self::REQUESTER);

        self::assertSame(200, $status, 'only a block with the OWNER refuses the request');
        self::assertSame([self::OWNER], $GLOBALS['__bcc_gjr']['notes'][0][3]);
    }

    public function testAManagerWhoseBlockStateIsUnreadableIsNotNotified(): void
    {
        $GLOBALS['__bcc_gjr']['block_unknown'] = [self::MANAGER];

        self::call('postRequestJoin', ['id' => self::CLOSED], self::REQUESTER);

        self::assertSame([self::OWNER], $GLOBALS['__bcc_gjr']['notes'][0][3]);
    }

    /** @return array<string, array{list<array{int, int}>, list<int>}> */
    public static function inviteBlocks(): array
    {
        return [
            'inviter blocked the invitee' => [[[self::OWNER, self::INVITEE]], []],
            'invitee blocked the inviter' => [[[self::INVITEE, self::OWNER]], []],
            'block state unreadable'      => [[], [self::INVITEE]],
        ];
    }

    /** @param list<array{int, int}> $blocks @param list<int> $unknown */
    #[DataProvider('inviteBlocks')]
    public function testABlockEitherWaySilentlyRefusesTheInvitation(array $blocks, array $unknown): void
    {
        $GLOBALS['__bcc_gjr']['blocks']        = $blocks;
        $GLOBALS['__bcc_gjr']['block_unknown'] = $unknown;

        [$status, $data] = self::call('postInvite', ['id' => self::CLOSED, 'user_id' => self::INVITEE], self::OWNER);

        self::assertSame(403, $status);
        self::assertSame("This member can't be invited.", $data['error']['message'] ?? null, 'the generic refusal copy');
        self::assertSame([], self::writes());
        self::assertSame([], $GLOBALS['__bcc_gjr']['notes']);
    }

    // ── D3: a deny is silent, and blocks a re-request for 7 days ─────

    public function testADenyIsSilentAndArmsTheReRequestCooldown(): void
    {
        $GLOBALS['__bcc_gjr']['memberships'][self::REQUESTER][self::CLOSED] = 'pending_admin';

        [$status] = self::call('postDeny', ['id' => self::CLOSED, 'user_id' => self::REQUESTER], self::OWNER);
        self::assertSame(200, $status);
        self::assertSame([], $GLOBALS['__bcc_gjr']['notes'], 'D2: a deny notifies nobody');

        unset($GLOBALS['__bcc_gjr']['memberships'][self::REQUESTER]);
        $GLOBALS['__bcc_gjr']['writes'] = [];
        [$status, $data] = self::call('postRequestJoin', ['id' => self::CLOSED], self::REQUESTER);

        self::assertSame(403, $status);
        self::assertSame('bcc_permission_denied', self::errorCode($data));
        self::assertSame([], self::writes());
    }

    public function testTheCooldownEndsAfterSevenDays(): void
    {
        $GLOBALS['__bcc_susp_fixture']['user_meta'][self::REQUESTER]['bcc_group_join_denied'] = [self::CLOSED => time() - 7 * 86400 - 1];

        [$status] = self::call('postRequestJoin', ['id' => self::CLOSED], self::REQUESTER);

        self::assertSame(200, $status);
    }

    // ── D1: owner / manager / site admin decide; nobody else ────────

    /** @return array<string, array{int, int}> */
    public static function deciders(): array
    {
        return [
            'owner'      => [self::OWNER, 200],
            'manager'    => [self::MANAGER, 200],
            'site admin' => [self::SITE_ADMIN, 200],
            'moderator'  => [self::MODERATOR, 403],
            'member'     => [self::MEMBER, 403],
            'outsider'   => [self::INVITEE, 403],
        ];
    }

    #[DataProvider('deciders')]
    public function testOnlyOwnersManagersAndSiteAdminsMayApprove(int $actor, int $expected): void
    {
        $GLOBALS['__bcc_gjr']['memberships'][self::REQUESTER][self::CLOSED] = 'pending_admin';

        [$status] = self::call('postApprove', ['id' => self::CLOSED, 'user_id' => self::REQUESTER], $actor);

        self::assertSame($expected, $status);
        if ($expected === 200) {
            self::assertSame([['approveJoinRequest', self::REQUESTER, self::CLOSED, $actor, true]], self::writes());
            self::assertSame([['approved', $actor, self::REQUESTER, self::CLOSED]], $GLOBALS['__bcc_gjr']['notes']);
        } else {
            self::assertSame([], self::writes(), 'no door call for a non-admin');
        }
    }

    #[DataProvider('deciders')]
    public function testOnlyOwnersManagersAndSiteAdminsMayInvite(int $actor, int $expected): void
    {
        [$status] = self::call('postInvite', ['id' => self::CLOSED, 'user_id' => self::INVITEE], $actor);

        self::assertSame($expected, $status);
        self::assertSame($expected === 200 ? [['invite', self::INVITEE, self::CLOSED, $actor, true]] : [], self::writes());
    }

    public function testASuspendedOwnerDecidesNothing(): void
    {
        $GLOBALS['__bcc_gjr']['suspended'] = [self::OWNER];

        [$status, $data] = self::call('postApprove', ['id' => self::CLOSED, 'user_id' => self::REQUESTER], self::OWNER);

        self::assertSame(403, $status);
        self::assertSame('bcc_forbidden', self::errorCode($data));
        self::assertSame([], self::writes());
    }

    public function testASuspendedRequesterCannotBeApproved(): void
    {
        $GLOBALS['__bcc_gjr']['suspended'] = [self::REQUESTER];

        [$status] = self::call('postApprove', ['id' => self::CLOSED, 'user_id' => self::REQUESTER], self::OWNER);

        self::assertSame(403, $status);
        self::assertSame([], self::writes());
    }

    // ── invitations ─────────────────────────────────────────────────

    /** @return array<string, array{int, int, int, string}> */
    public static function refusedInvites(): array
    {
        return [
            'open group'   => [self::OPEN, self::INVITEE, 400, 'bcc_invalid_request'],
            'self'         => [self::CLOSED, self::OWNER, 400, 'bcc_invalid_request'],
            'unknown user' => [self::CLOSED, 9999, 404, 'bcc_invalid_request'],
        ];
    }

    #[DataProvider('refusedInvites')]
    public function testAnInviteThatCannotStandWritesNothing(int $groupId, int $invitee, int $status, string $code): void
    {
        [$got, $data] = self::call('postInvite', ['id' => $groupId, 'user_id' => $invitee], self::OWNER);

        self::assertSame($status, $got);
        self::assertSame($code, self::errorCode($data));
        self::assertSame([], self::writes());
    }

    public function testASuspendedInviteeAnswersExactlyLikeAnUnknownOne(): void
    {
        $GLOBALS['__bcc_gjr']['suspended'] = [self::INVITEE];

        self::assertSame(
            self::call('postInvite', ['id' => self::CLOSED, 'user_id' => 9999], self::OWNER),
            self::call('postInvite', ['id' => self::CLOSED, 'user_id' => self::INVITEE], self::OWNER),
            'an invite form must not probe account state'
        );
        self::assertSame([], self::writes());
    }

    public function testTheOpenInvitationCapIsEnforcedAndAFailedCountFailsClosed(): void
    {
        $GLOBALS['__bcc_gjr']['pending_invites'] = 200;
        [$status] = self::call('postInvite', ['id' => self::CLOSED, 'user_id' => self::INVITEE], self::OWNER);
        self::assertSame(429, $status);

        $GLOBALS['__bcc_gjr']['lists_fail'] = true;
        [$status] = self::call('postInvite', ['id' => self::CLOSED, 'user_id' => self::INVITEE], self::OWNER);
        self::assertSame(503, $status);
        self::assertSame([], self::writes());
    }

    public function testAnInviteeCanSeeAndAcceptASecretGroup(): void
    {
        $GLOBALS['__bcc_gjr']['memberships'][self::INVITEE][self::SECRET] = 'pending_user';

        [$status, $data] = self::call('postAcceptInvite', ['id' => self::SECRET], self::INVITEE);

        self::assertSame(200, $status);
        self::assertSame([['acceptInvite', self::INVITEE, self::SECRET, null, true]], self::writes());
        self::assertSame([['group_invite_accepted', self::SECRET]], $GLOBALS['__bcc_gjr']['audits']);
        unset($data);
    }

    public function testAcceptingWithoutAnInvitationLooksLikeAMissingGroup(): void
    {
        self::assertSame(
            self::call('postAcceptInvite', ['id' => self::MISSING], self::REQUESTER),
            self::call('postAcceptInvite', ['id' => self::SECRET], self::REQUESTER)
        );
        [$status] = self::call('postDeclineInvite', ['id' => self::CLOSED], self::REQUESTER);
        self::assertSame(404, $status);
        self::assertSame([], self::writes());
    }

    public function testAFailedMembershipReadOnAcceptIs503(): void
    {
        $GLOBALS['__bcc_gjr']['read_failed'] = true;

        [$status] = self::call('postAcceptInvite', ['id' => self::SECRET], self::INVITEE);

        self::assertSame(503, $status);
        self::assertSame([], self::writes());
    }

    // ── OUTCOME_* → HTTP, for every door ─────────────────────────────

    /** @return array<string, array{string, array<string, int>, int, ?array{int, int, string}}> */
    private static function doors(): array
    {
        return [
            // handler, params, actor, membership row to plant [user, group, status]
            'request'  => ['postRequestJoin',   ['id' => self::CLOSED], self::REQUESTER, null],
            'cancel'   => ['postCancelRequest', ['id' => self::CLOSED], self::REQUESTER, null],
            'approve'  => ['postApprove',       ['id' => self::CLOSED, 'user_id' => self::REQUESTER], self::OWNER, null],
            'deny'     => ['postDeny',          ['id' => self::CLOSED, 'user_id' => self::REQUESTER], self::OWNER, null],
            'invite'   => ['postInvite',        ['id' => self::CLOSED, 'user_id' => self::INVITEE], self::OWNER, null],
            'revoke'   => ['postRevokeInvite',  ['id' => self::CLOSED, 'user_id' => self::INVITEE], self::OWNER, null],
            'accept'   => ['postAcceptInvite',  ['id' => self::CLOSED], self::INVITEE, [self::INVITEE, self::CLOSED, 'pending_user']],
            'decline'  => ['postDeclineInvite', ['id' => self::CLOSED], self::INVITEE, [self::INVITEE, self::CLOSED, 'pending_user']],
        ];
    }

    /** @return array<string, array{string, string, int, string}> */
    public static function outcomeMap(): array
    {
        $out = [];
        $map = [
            'conflict'    => [409, 'bcc_conflict'],
            'refused'     => [403, 'bcc_permission_denied'],
            'unavailable' => [503, 'bcc_unavailable'],
            'done'        => [200, ''],
        ];
        foreach (array_keys(self::doors()) as $door) {
            foreach ($map as $outcome => [$status, $code]) {
                $out["$door → $outcome"] = [$door, $outcome, $status, $code];
            }
        }
        return $out;
    }

    #[DataProvider('outcomeMap')]
    public function testTheWritersOutcomeMapsToTheSameStatusOnEveryDoor(string $door, string $outcome, int $status, string $code): void
    {
        [$handler, $params, $actor, $row] = self::doors()[$door];
        if ($row !== null) {
            $GLOBALS['__bcc_gjr']['memberships'][$row[0]][$row[1]] = $row[2];
        }
        $GLOBALS['__bcc_gjr']['outcome'] = $outcome;

        [$got, $data] = self::call($handler, $params, $actor);

        self::assertSame($status, $got);
        if ($code !== '') {
            self::assertSame($code, self::errorCode($data));
            self::assertSame([], $GLOBALS['__bcc_gjr']['notes'], 'no notification for a write that did not happen');
        }
        self::assertCount(1, self::writes(), 'exactly one door call');
        self::assertTrue(self::writes()[0][4], 'the door ran under the per-pair lock');
    }

    /** @return array<string, array{string}> */
    public static function doorNames(): array
    {
        return array_combine(array_keys(self::doors()), array_map(static fn (string $d): array => [$d], array_keys(self::doors())));
    }

    #[DataProvider('doorNames')]
    public function testABusyPairLockIsARetryable409AndNoDoorRuns(string $door): void
    {
        [$handler, $params, $actor, $row] = self::doors()[$door];
        if ($row !== null) {
            $GLOBALS['__bcc_gjr']['memberships'][$row[0]][$row[1]] = $row[2];
        }
        $GLOBALS['__bcc_gjr']['lock_busy'] = true;

        [$status, $data] = self::call($handler, $params, $actor);

        self::assertSame(409, $status);
        self::assertSame('Please retry.', $data['error']['message'] ?? null);
        self::assertSame([], self::writes());
    }

    // ── owner lists ─────────────────────────────────────────────────

    public function testOnlyAdminsSeeTheRequestListAndAFailedReadIs503(): void
    {
        [$status] = self::call('getRequests', ['id' => self::CLOSED], self::MODERATOR);
        self::assertSame(403, $status);

        [$status] = self::call('getRequests', ['id' => self::CLOSED], self::OWNER);
        self::assertSame(200, $status);

        $GLOBALS['__bcc_gjr']['lists_fail'] = true;
        [$status] = self::call('getRequests', ['id' => self::CLOSED], self::OWNER);
        self::assertSame(503, $status, 'a failed read is never "nobody is waiting"');
        [$status] = self::call('getMyInvites', [], self::INVITEE);
        self::assertSame(503, $status);
    }
}
