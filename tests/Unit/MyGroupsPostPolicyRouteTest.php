<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\MyGroupsEndpoint;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The real POST /me/groups/:id/post-policy handler: auth → throttle →
 * canonical management authz → envelope. Exercises MyGroupsEndpoint::
 * postPostPolicy with WordPress stubbed, so the HTTP contract (status
 * codes, ok/error envelope, capability reflected immediately) is proven
 * without a running WP. Role/secret-leak/lifecycle authz also lives in
 * GroupsPublicAllGateTest at the service layer; this asserts the route
 * maps them to the right HTTP surface.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MyGroupsPostPolicyRouteTest extends TestCase
{
    private const OPEN   = 0;
    private const CLOSED = 1;
    private const SECRET = 2;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/route-handler-stubs.php';
        $GLOBALS['__bcc_puball_fixture'] = ['groups' => [], 'admins' => [], 'current_user' => 0];
    }

    /** @param array<int, string> $membership */
    private function registerGroup(int $id, int $privacy, bool $optIn, array $membership): void
    {
        $GLOBALS['__bcc_puball_fixture']['groups'][$id] = [
            'post_type'  => 'peepso-group',
            'meta'       => [
                'peepso_group_privacy'          => (string) $privacy,
                '_bcc_group_public_all_members' => $optIn ? '1' : '',
            ],
            'membership' => $membership,
        ];
    }

    private function call(int $viewerId, int $groupId, bool $enabled): WP_REST_Response
    {
        $GLOBALS['__bcc_puball_fixture']['current_user'] = $viewerId;
        $request = new WP_REST_Request(['id' => $groupId, 'public_all_members' => $enabled]);
        return (new MyGroupsEndpoint())->postPostPolicy($request);
    }

    /** @return array<string, mixed> */
    private function body(WP_REST_Response $r): array
    {
        $data = $r->get_data();
        return is_array($data) ? $data : [];
    }

    public function testAnonymousIs401(): void
    {
        $this->registerGroup(400, self::CLOSED, false, [1 => 'member_owner']);
        $r = $this->call(0, 400, true);
        self::assertSame(401, $r->get_status());
        self::assertSame('bcc_unauthorized', $this->body($r)['error']['code'] ?? null);
    }

    public function testThrottledIs429(): void
    {
        $this->registerGroup(400, self::CLOSED, false, [1 => 'member_owner']);
        $GLOBALS['__bcc_puball_fixture']['throttled'] = true;
        $r = $this->call(1, 400, true);
        self::assertSame(429, $r->get_status());
        self::assertSame('bcc_rate_limited', $this->body($r)['error']['code'] ?? null);
    }

    public function testOwnerGets200AndCapabilityReflectsImmediately(): void
    {
        $this->registerGroup(400, self::CLOSED, false, [1 => 'member_owner']);
        $r = $this->call(1, 400, true);

        self::assertSame(200, $r->get_status());
        $data = $this->body($r)['data'] ?? [];
        self::assertTrue($data['ok'] ?? false);
        self::assertTrue($data['public_all_members_enabled'] ?? false, 'returned capability reflects the new state');
        // And it actually persisted (an ordinary member could now syndicate).
        self::assertSame('1', $GLOBALS['__bcc_puball_fixture']['groups'][400]['meta']['_bcc_group_public_all_members'] ?? null);
    }

    public function testManagerAndSiteAdminGet200(): void
    {
        $this->registerGroup(400, self::CLOSED, false, [2 => 'member_manager', 3 => 'member']);
        self::assertSame(200, $this->call(2, 400, true)->get_status(), 'manager');

        $GLOBALS['__bcc_puball_fixture']['admins'] = [3];
        self::assertSame(200, $this->call(3, 400, true)->get_status(), 'site admin');
    }

    public function testModeratorMemberAndNonMemberGet403(): void
    {
        $this->registerGroup(400, self::CLOSED, false, [3 => 'member_moderator', 4 => 'member']);

        foreach ([3 => 'moderator', 4 => 'member', 9 => 'non-member'] as $uid => $label) {
            $r = $this->call($uid, 400, true);
            self::assertSame(403, $r->get_status(), $label);
            self::assertSame('bcc_permission_denied', $this->body($r)['error']['code'] ?? null, $label);
        }
    }

    public function testSecretGroupNonMemberGets404NotLeaked(): void
    {
        $this->registerGroup(500, self::SECRET, false, [1 => 'member_owner']);
        $r = $this->call(9, 500, true); // viewer 9 not a member of the secret group
        self::assertSame(404, $r->get_status());
        self::assertSame('bcc_not_found', $this->body($r)['error']['code'] ?? null);
    }

    public function testUnknownGroupGets404(): void
    {
        $r = $this->call(1, 999999, true);
        self::assertSame(404, $r->get_status());
        self::assertSame('bcc_not_found', $this->body($r)['error']['code'] ?? null);
    }

    public function testEnableThenDisableLifecycleThroughRoute(): void
    {
        $this->registerGroup(400, self::CLOSED, false, [1 => 'member_owner']);

        $on = $this->call(1, 400, true);
        self::assertSame(200, $on->get_status());
        self::assertTrue(($this->body($on)['data']['public_all_members_enabled'] ?? false));

        $off = $this->call(1, 400, false);
        self::assertSame(200, $off->get_status());
        self::assertFalse(($this->body($off)['data']['public_all_members_enabled'] ?? true));
        // Disable removes the meta (default-off stays a clean absence).
        self::assertArrayNotHasKey(
            '_bcc_group_public_all_members',
            $GLOBALS['__bcc_puball_fixture']['groups'][400]['meta']
        );
    }

    public function testOpenGroupOwnerToggleIsAccepted(): void
    {
        // Open groups don't need the opt-in, but the setting still round-trips.
        $this->registerGroup(400, self::OPEN, false, [1 => 'member_owner']);
        $r = $this->call(1, 400, true);
        self::assertSame(200, $r->get_status());
        self::assertTrue(($this->body($r)['data']['public_all_members_enabled'] ?? false));
    }
}
