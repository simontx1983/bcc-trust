<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\GroupPostPolicyRepository;
use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\GroupsService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Wiring proof for the public_all authoring boundary at the service layer:
 * GroupsService::canUsePublicAll resolves (privacy, role, opt-in) and
 * delegates to PublicAllPolicy, and GroupsService::setPublicAllMembersPolicy
 * enforces owner/site-admin authorization on the opt-in write.
 *
 * The exhaustive authorization matrix lives in PublicAllPolicyTest (pure);
 * this test proves the RESOLVER feeds the right inputs (privacy from meta,
 * role from getMembershipStatus, opt-in from the repository) and that the
 * owner-toggle rejects everyone but the owner/admin.
 *
 * GroupsService is constructed directly with the REAL GroupContextResolver
 * (the same injection LocalsService uses) so no Plugin singleton is needed;
 * WP functions + PeepSoGroupRepository + ReputationRepository are stubbed at
 * their FQNs.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupsPublicAllGateTest extends TestCase
{
    private const OPEN   = 0;
    private const CLOSED = 1;
    private const SECRET = 2;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/public-all-stubs.php';
        $GLOBALS['__bcc_puball_fixture'] = ['groups' => [], 'admins' => []];
    }

    /**
     * @param array<int, string> $membership uid => PeepSo status
     */
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

    private function service(): GroupsService
    {
        return new GroupsService(new ReputationRepository(), new GroupContextResolver());
    }

    // ── canUsePublicAll ────────────────────────────────────────────────

    public function testOpenGroupOrdinaryMemberMaySyndicate(): void
    {
        $this->registerGroup(100, self::OPEN, false, [7 => 'member']);
        self::assertTrue($this->service()->canUsePublicAll(7, 100));
    }

    public function testOpenGroupNonMemberMayNot(): void
    {
        $this->registerGroup(100, self::OPEN, false, []); // no row for viewer 7
        self::assertFalse($this->service()->canUsePublicAll(7, 100));
    }

    public function testClosedGroupOrdinaryMemberBlockedWhenOptInOff(): void
    {
        $this->registerGroup(200, self::CLOSED, false, [7 => 'member']);
        self::assertFalse($this->service()->canUsePublicAll(7, 200));
    }

    public function testClosedGroupOrdinaryMemberAllowedWhenOptInOn(): void
    {
        $this->registerGroup(200, self::CLOSED, true, [7 => 'member']);
        self::assertTrue($this->service()->canUsePublicAll(7, 200));
    }

    public function testClosedGroupOwnerAlwaysAllowed(): void
    {
        $this->registerGroup(200, self::CLOSED, false, [7 => 'member_owner']);
        self::assertTrue($this->service()->canUsePublicAll(7, 200));
    }

    public function testSecretGroupModeratorAllowedNonMemberDenied(): void
    {
        $this->registerGroup(300, self::SECRET, false, [7 => 'member_moderator']);
        self::assertTrue($this->service()->canUsePublicAll(7, 300));
        // Viewer 9 has no row in the secret group.
        self::assertFalse($this->service()->canUsePublicAll(9, 300));
    }

    public function testReadonlyMemberDenied(): void
    {
        $this->registerGroup(200, self::OPEN, true, [7 => 'member_readonly']);
        self::assertFalse($this->service()->canUsePublicAll(7, 200));
    }

    public function testUnknownGroupAndBadArgsDenied(): void
    {
        self::assertFalse($this->service()->canUsePublicAll(7, 999999)); // no fixture
        self::assertFalse($this->service()->canUsePublicAll(0, 100));
        self::assertFalse($this->service()->canUsePublicAll(7, 0));
    }

    // ── setPublicAllMembersPolicy (owner/admin toggle) ─────────────────

    public function testOwnerMayEnableAndItPersists(): void
    {
        $this->registerGroup(400, self::CLOSED, false, [7 => 'member_owner']);

        $result = $this->service()->setPublicAllMembersPolicy(7, 400, true);
        self::assertTrue($result['ok'] ?? false);
        self::assertTrue($result['public_all_members_enabled'] ?? false);
        // The write actually landed — an ordinary member can now syndicate.
        self::assertTrue(GroupPostPolicyRepository::publicAllMembersEnabled(400));
    }

    // ── canManagePublicAllPolicy (the canonical management authz) ──────
    //
    // Distinct from canUsePublicAll: a moderator MAY use public_all on
    // their own post but MAY NOT flip the group-wide policy. Every role
    // is asserted explicitly.

    public function testCanManageMatrixEveryRole(): void
    {
        // One closed group, every role present; viewer 50 is a site admin
        // (plain member row), viewer 60 is anonymous (uid 0).
        $this->registerGroup(500, self::CLOSED, false, [
            1 => 'member_owner',
            2 => 'member_manager',
            3 => 'member_moderator',
            4 => 'member',
            5 => 'member_readonly',
            6 => 'pending_admin',
            7 => 'banned',
            50 => 'member',            // site admin, see below
            // 8 = non-member (no row)
        ]);
        $GLOBALS['__bcc_puball_fixture']['admins'] = [50];

        $svc = $this->service();
        // MAY manage:
        self::assertTrue($svc->canManagePublicAllPolicy(1, 500), 'owner');
        self::assertTrue($svc->canManagePublicAllPolicy(2, 500), 'manager');
        self::assertTrue($svc->canManagePublicAllPolicy(50, 500), 'site admin');
        // MAY NOT manage:
        self::assertFalse($svc->canManagePublicAllPolicy(3, 500), 'moderator');
        self::assertFalse($svc->canManagePublicAllPolicy(4, 500), 'ordinary member');
        self::assertFalse($svc->canManagePublicAllPolicy(5, 500), 'readonly');
        self::assertFalse($svc->canManagePublicAllPolicy(6, 500), 'pending');
        self::assertFalse($svc->canManagePublicAllPolicy(7, 500), 'banned');
        self::assertFalse($svc->canManagePublicAllPolicy(8, 500), 'non-member');
        self::assertFalse($svc->canManagePublicAllPolicy(0, 500), 'anonymous');
    }

    /**
     * The management set (owner/manager/admin) and the use set
     * (owner/manager/moderator) genuinely differ on the moderator: a
     * moderator may USE public_all on their own post but may NOT manage
     * the group policy. Guards against collapsing the two authz methods.
     */
    public function testModeratorMayUseButNotManage(): void
    {
        $this->registerGroup(500, self::CLOSED, false, [3 => 'member_moderator']);
        $svc = $this->service();
        self::assertTrue($svc->canUsePublicAll(3, 500), 'moderator may USE public_all on own post');
        self::assertFalse($svc->canManagePublicAllPolicy(3, 500), 'moderator may NOT manage the group policy');
    }

    // ── setPublicAllMembersPolicy (write route authz) ──────────────────

    public function testOwnerManagerAdminMayToggleModeratorAndMembersMayNot(): void
    {
        $this->registerGroup(400, self::CLOSED, false, [
            1 => 'member_owner',
            2 => 'member_manager',
            3 => 'member_moderator',
            4 => 'member',
            5 => 'member_readonly',
            50 => 'member',   // site admin
        ]);
        $GLOBALS['__bcc_puball_fixture']['admins'] = [50];
        $svc = $this->service();

        // Allowed:
        self::assertTrue($svc->setPublicAllMembersPolicy(1, 400, true)['ok'] ?? false, 'owner');
        self::assertTrue($svc->setPublicAllMembersPolicy(2, 400, true)['ok'] ?? false, 'manager');
        self::assertTrue($svc->setPublicAllMembersPolicy(50, 400, true)['ok'] ?? false, 'site admin');

        // Denied (moderator is the critical case — may use, may not manage):
        self::assertSame('bcc_permission_denied', $svc->setPublicAllMembersPolicy(3, 400, true)['error'] ?? null, 'moderator');
        self::assertSame('bcc_permission_denied', $svc->setPublicAllMembersPolicy(4, 400, true)['error'] ?? null, 'member');
        self::assertSame('bcc_permission_denied', $svc->setPublicAllMembersPolicy(5, 400, true)['error'] ?? null, 'readonly');
        self::assertSame('bcc_permission_denied', $svc->setPublicAllMembersPolicy(9, 400, true)['error'] ?? null, 'non-member (closed)');
        self::assertSame('bcc_invalid_request', $svc->setPublicAllMembersPolicy(0, 400, true)['error'] ?? null, 'anonymous');
    }

    /**
     * A non-member of a SECRET group must get 404 (not 403) — the write
     * route must never leak a secret group's existence, even to reject an
     * unauthorized toggle.
     */
    public function testSecretGroupExistenceNotLeakedOnToggle(): void
    {
        $this->registerGroup(600, self::SECRET, false, [1 => 'member_owner']);
        // Viewer 9 is not a member of the secret group.
        self::assertSame('bcc_not_found', $this->service()->setPublicAllMembersPolicy(9, 600, true)['error'] ?? null);
        // The owner, being a member, is not 404'd and may manage.
        self::assertTrue($this->service()->setPublicAllMembersPolicy(1, 600, true)['ok'] ?? false);
    }

    public function testUnknownGroupAndBadArgsRejected(): void
    {
        self::assertSame('bcc_not_found', $this->service()->setPublicAllMembersPolicy(7, 999999, true)['error'] ?? null);
        self::assertSame('bcc_invalid_request', $this->service()->setPublicAllMembersPolicy(0, 400, true)['error'] ?? null);
        self::assertSame('bcc_invalid_request', $this->service()->setPublicAllMembersPolicy(7, 0, true)['error'] ?? null);
    }

    public function testEnableReadDisableLifecycleRemovesMeta(): void
    {
        $this->registerGroup(400, self::CLOSED, false, [1 => 'member_owner']);

        // Enable → persists.
        $enabled = $this->service()->setPublicAllMembersPolicy(1, 400, true);
        self::assertTrue($enabled['public_all_members_enabled'] ?? false);
        self::assertTrue(GroupPostPolicyRepository::publicAllMembersEnabled(400));

        // Disable → removed (meta deleted, not stored as '0').
        $disabled = $this->service()->setPublicAllMembersPolicy(1, 400, false);
        self::assertFalse($disabled['public_all_members_enabled'] ?? true);
        self::assertFalse(GroupPostPolicyRepository::publicAllMembersEnabled(400));
        self::assertArrayNotHasKey(
            '_bcc_group_public_all_members',
            $GLOBALS['__bcc_puball_fixture']['groups'][400]['meta']
        );
    }

    /**
     * `public_all_members_enabled` exposure via canManagePublicAllPolicy:
     * managers see the true value, everyone else sees false (minimum
     * exposure). Proven here at the authz layer that feeds the view-model.
     */
    public function testManageGateDrivesSettingExposure(): void
    {
        $this->registerGroup(400, self::CLOSED, true, [1 => 'member_owner', 4 => 'member']);
        $svc = $this->service();
        // Manager can manage → view-model would expose the real (true) value.
        self::assertTrue($svc->canManagePublicAllPolicy(1, 400));
        self::assertTrue(GroupPostPolicyRepository::publicAllMembersEnabled(400));
        // Ordinary member cannot manage → view-model exposes false regardless.
        self::assertFalse($svc->canManagePublicAllPolicy(4, 400));
    }
}
