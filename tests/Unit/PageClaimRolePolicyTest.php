<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Services\PageClaimRolePolicy as P;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * D1 and the owner-configurable team permissions, decision by decision.
 * The expected tables are written out here independently of the class
 * (Phillip's decisions of 2026-11-23), so changing the policy means
 * changing this test too.
 */
#[CoversClass(P::class)]
final class PageClaimRolePolicyTest extends TestCase
{
    private const POWERS = [
        'publish_announcements', 'reply_reviews', 'reply_messages', 'post_feed',
        'edit_profile', 'edit_links', 'edit_community', 'edit_notifications',
        'manage_representatives', 'message_history_before_join', 'links_unrestricted',
    ];

    private const LOCKED = ['manage_administrators', 'transfer_ownership', 'remove_validator_link', 'change_permissions'];

    private const REACHING = ['publish_announcements', 'reply_messages', 'post_feed'];

    /** The defaults: what each team role holds when the owner has changed nothing. */
    private const DEFAULT_GRANTS = [
        'administrator'  => ['publish_announcements', 'reply_reviews', 'reply_messages', 'post_feed',
                             'edit_profile', 'edit_links', 'edit_community', 'edit_notifications',
                             'manage_representatives'],
        'representative' => ['publish_announcements', 'reply_reviews', 'reply_messages'],
    ];

    /** Roles that are not the owner, including junk. */
    private const NON_OWNERS = ['administrator', 'representative', 'holder', 'pending', '', null];

    public function testTheKeyListsAreExactlyTheOnesThisTestCovers(): void
    {
        self::assertSame(self::POWERS, P::DELEGABLE, 'a new power needs a row here');
        self::assertSame(self::LOCKED, P::LOCKED);
        self::assertSame(self::REACHING, P::DELEGATOR_REACHING);
        self::assertSame(['leave_team', 'set_visibility'], P::TEAM_SELF);
        self::assertSame([], array_intersect(P::DELEGABLE, P::LOCKED), 'a locked power is never delegable');
    }

    public function testTheDefaultsForEachTeamRole(): void
    {
        foreach (self::DEFAULT_GRANTS as $role => $granted) {
            foreach (self::POWERS as $power) {
                self::assertSame(in_array($power, $granted, true), P::may($role, $power, [], true), "{$role} / {$power}");
            }
        }
    }

    public function testTheOwnerHoldsEveryPowerWhateverTheSettings(): void
    {
        foreach (['operator', 'creator'] as $owner) {
            foreach ([null, [], self::allOff()] as $settings) {
                foreach (array_merge(self::POWERS, self::LOCKED) as $action) {
                    self::assertTrue(P::may($owner, $action, $settings, false), "{$owner} / {$action}");
                }
                self::assertFalse(P::may($owner, 'leave_team', $settings, true), 'the owner never leaves');
                self::assertFalse(P::may($owner, 'set_visibility', $settings, true));
            }
        }
    }

    /**
     * Security's matrix: every non-owner role x every settings combination
     * for its own row x every owner-only power is false, including settings
     * that try to name the locked powers themselves.
     */
    public function testNoSettingsEverGiveANonOwnerAnOwnerOnlyPower(): void
    {
        $checked = 0;
        foreach (self::NON_OWNERS as $role) {
            $row = is_string($role) && in_array($role, ['administrator', 'representative'], true) ? $role : 'administrator';
            for ($mask = 0; $mask < (1 << count(self::POWERS)); $mask++) {
                $grid = [];
                foreach (self::POWERS as $i => $power) {
                    $grid[$power] = (bool) ($mask & (1 << $i));
                }
                foreach (self::LOCKED as $locked) {
                    $grid[$locked] = true; // a forged stored value
                }
                foreach ([true, false] as $reconfirmed) {
                    foreach (self::LOCKED as $locked) {
                        self::assertFalse(P::may($role, $locked, [$row => $grid], $reconfirmed));
                        ++$checked;
                    }
                }
            }
            foreach ([null, []] as $settings) {
                foreach (self::LOCKED as $locked) {
                    self::assertFalse(P::may($role, $locked, $settings, true));
                }
            }
        }
        self::assertSame(count(self::NON_OWNERS) * 2048 * 2 * 4, $checked);
    }

    public function testEachTeamPowerFollowsTheOwnersSetting(): void
    {
        foreach (['administrator', 'representative'] as $role) {
            foreach (self::POWERS as $power) {
                foreach ([true, false] as $value) {
                    $settings = [$role => [$power => $value]];
                    self::assertSame($value, P::may($role, $power, $settings, true), "{$role} / {$power} = " . var_export($value, true));
                }
            }
        }
    }

    public function testUnreadableSettingsGrantATeamRoleNothingNotTheDefaults(): void
    {
        foreach (['administrator', 'representative'] as $role) {
            foreach (self::POWERS as $power) {
                self::assertFalse(P::may($role, $power, null, true), "{$role} / {$power}");
            }
            self::assertTrue(P::may($role, 'leave_team', null, true), 'a member can still leave');
        }
    }

    public function testADelegatorReachingPowerNeedsAReconfirmedMember(): void
    {
        foreach (['administrator', 'representative'] as $role) {
            $allOn = [$role => array_fill_keys(self::POWERS, true)];
            foreach (self::POWERS as $power) {
                $reaching = in_array($power, self::REACHING, true);
                self::assertSame(!$reaching, P::may($role, $power, $allOn, false), "{$role} / {$power} without re-confirmation");
                self::assertTrue(P::may($role, $power, $allOn, true), "{$role} / {$power} re-confirmed");
            }
        }
    }

    public function testJunkInStoredSettingsIsIgnored(): void
    {
        $settings = [
            'representative' => ['edit_profile' => 'yes', 'edit_links' => 1, 'bogus' => true, 'change_permissions' => true],
            'owner'          => ['transfer_ownership' => true],
            'administrator'  => 'all',
        ];
        self::assertFalse(P::may('representative', 'edit_profile', $settings, true), 'a non-boolean value keeps the default');
        self::assertFalse(P::may('representative', 'edit_links', $settings, true));
        self::assertSame(P::DEFAULTS, P::effective($settings));
    }

    public function testNoRoleAndUnknownRolesHoldNothing(): void
    {
        foreach (['holder', 'pending', '', null] as $role) {
            foreach (array_merge(self::POWERS, ['leave_team', 'set_visibility']) as $action) {
                self::assertFalse(P::may($role, $action, [], true), var_export($role, true) . " / {$action}");
            }
        }
    }

    public function testAnUnknownActionIsAProgrammingErrorNotADenial(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        P::may('operator', 'edit_everything', [], true);
    }

    public function testOnlyTheOwnerManagesAdministrators(): void
    {
        $allOn = ['administrator' => array_fill_keys(self::POWERS, true), 'representative' => array_fill_keys(self::POWERS, true)];
        foreach (['operator' => true, 'creator' => true, 'administrator' => false, 'representative' => false, 'holder' => false] as $role => $expected) {
            self::assertSame($expected, P::mayInvite($role, 'administrator', $allOn, true), "invite admin by {$role}");
            self::assertSame($expected, P::mayRemove($role, 'administrator', $allOn, true), "remove admin by {$role}");
        }
    }

    public function testRepresentativesAreManagedByTheOwnerAndPermittedAdministrators(): void
    {
        self::assertTrue(P::mayInvite('operator', 'representative', null, false));
        self::assertTrue(P::mayInvite('administrator', 'representative', [], true));
        self::assertFalse(P::mayInvite('administrator', 'representative', ['administrator' => ['manage_representatives' => false]], true));
        self::assertFalse(P::mayInvite('administrator', 'representative', null, true), 'unreadable settings: no delegated management');
        self::assertTrue(P::mayRemove('representative', 'representative', ['representative' => ['manage_representatives' => true]], true), 'only if the owner grants it');
        self::assertFalse(P::mayRemove('representative', 'representative', [], true));
    }

    /**
     * You cannot grant a power you do not hold: an administrator may add a
     * representative only when it holds every power the representative role
     * carries under the current settings. Removing is not restricted.
     */
    public function testAnAdministratorCannotGrantAPowerItDoesNotHold(): void
    {
        $adminMuted = ['administrator' => ['publish_announcements' => false]]; // reps keep it by default
        self::assertFalse(P::mayInvite('administrator', 'representative', $adminMuted, true), 'reps announce, the admin cannot');
        self::assertTrue(P::mayRemove('administrator', 'representative', $adminMuted, true), 'removing only takes power away');
        self::assertFalse(P::capabilities('administrator', $adminMuted, true)['invite_representative']);

        foreach (self::POWERS as $power) {
            $settings = [
                'administrator'  => [$power => false],
                'representative' => [$power => true],
            ];
            if ($power === 'manage_representatives') {
                continue; // without it the admin cannot invite at all
            }
            self::assertFalse(P::mayInvite('administrator', 'representative', $settings, true), $power);
        }

        self::assertTrue(P::mayInvite('operator', 'representative', $adminMuted, false), 'the owner is unaffected');
        self::assertTrue(P::mayInvite('administrator', 'representative', [], true), 'defaults: the admin holds every rep power');
        self::assertFalse(P::mayInvite('administrator', 'representative', [], false), 'an admin not re-confirmed lacks the reaching powers reps carry');
        $quietReps = ['representative' => array_fill_keys(self::POWERS, false)];
        self::assertTrue(P::mayInvite('administrator', 'representative', $quietReps, false), 'reps carrying nothing grant nothing');
    }

    public function testNobodyInvitesIntoOwnershipOrAnUnknownRole(): void
    {
        foreach (['operator', 'creator', 'holder', 'staff', ''] as $target) {
            foreach (['operator', 'administrator', 'representative', null] as $actor) {
                self::assertFalse(P::mayInvite($actor, $target, [], true), "{$target} by " . var_export($actor, true));
                self::assertFalse(P::mayRemove($actor, $target, [], true), "{$target} by " . var_export($actor, true));
            }
        }
    }

    public function testThePasswordIsNeededToAddAMemberWhoCanReachDelegators(): void
    {
        self::assertTrue(P::requiresPasswordToInvite('administrator', []));
        self::assertTrue(P::requiresPasswordToInvite('representative', []));
        $quiet = ['representative' => array_fill_keys(self::REACHING, false)];
        self::assertFalse(P::requiresPasswordToInvite('representative', $quiet));
        self::assertTrue(P::requiresPasswordToInvite('administrator', $quiet), 'the other role is unchanged');
        foreach (self::REACHING as $power) {
            $one = ['representative' => array_fill_keys(self::REACHING, false)];
            $one['representative'][$power] = true;
            self::assertTrue(P::requiresPasswordToInvite('representative', $one), $power);
        }
        self::assertTrue(P::requiresPasswordToInvite('representative', null), 'unreadable settings: ask');
        self::assertTrue(P::requiresPasswordToInvite('operator', []), 'not a team role: ask');
    }

    public function testAFullGridParsesAndAnythingElseNamesTheField(): void
    {
        $full = ['administrator' => array_fill_keys(self::POWERS, false), 'representative' => array_fill_keys(self::POWERS, true)];
        self::assertSame($full, P::parseGrid($full));

        $cases = [
            'roles'                                      => 'nope',
            'roles.owner'                                => $full + ['owner' => []],
            'roles.representative'                       => ['administrator' => $full['administrator']],
            'roles.administrator.change_permissions'     => ['administrator' => $full['administrator'] + ['change_permissions' => true], 'representative' => $full['representative']],
            'roles.administrator.bogus'                  => ['administrator' => $full['administrator'] + ['bogus' => true], 'representative' => $full['representative']],
            'roles.representative.edit_links'            => ['administrator' => $full['administrator'], 'representative' => ['edit_links' => 1] + $full['representative']],
            'roles.administrator.links_unrestricted'     => ['administrator' => array_diff_key($full['administrator'], ['links_unrestricted' => 1]), 'representative' => $full['representative']],
        ];
        foreach ($cases as $field => $grid) {
            try {
                P::parseGrid($grid);
                self::fail("{$field} must be refused");
            } catch (\InvalidArgumentException $e) {
                self::assertSame($field, $e->getMessage());
            }
        }
    }

    public function testTheCapabilitiesAreThePolicyOutput(): void
    {
        $owner = P::capabilities('operator', [], true);
        foreach (self::POWERS as $power) {
            self::assertTrue($owner[$power], $power);
        }
        self::assertTrue($owner['invite_administrator']);
        self::assertSame(['administrator' => true, 'representative' => true], $owner['remove']);
        self::assertFalse($owner['leave']);

        $rep = P::capabilities('representative', [], true);
        foreach (self::POWERS as $power) {
            self::assertSame(in_array($power, self::DEFAULT_GRANTS['representative'], true), $rep[$power], $power);
        }
        self::assertFalse($rep['invite_administrator']);
        self::assertFalse($rep['invite_representative']);
        self::assertSame(['administrator' => false, 'representative' => false], $rep['remove']);
        self::assertTrue($rep['leave']);
        self::assertTrue($rep['set_visibility']);

        $none = P::capabilities(null, [], true);
        self::assertSame([], array_filter($none, static fn ($v): bool => $v === true));
    }

    public function testOwnershipAndTeamAreDisjointAndNeverIncludeHolder(): void
    {
        self::assertSame(['operator', 'creator'], P::OWNER_ROLES);
        self::assertSame(['administrator', 'representative'], P::TEAM_ROLES);
        self::assertSame([], array_intersect(P::ENTITY_CLAIM_ROLES, P::TEAM_ROLES), 'team roles are page rows only');
        foreach (['administrator', 'representative', 'holder', null, ''] as $role) {
            self::assertFalse(P::isOwnerRole($role), var_export($role, true) . ' is never an owner');
        }
        self::assertTrue(P::isTeamRole('representative'));
        self::assertFalse(P::isTeamRole('operator'));
    }

    /** @return array{administrator: array<string, bool>, representative: array<string, bool>} */
    private static function allOff(): array
    {
        return ['administrator' => array_fill_keys(self::POWERS, false), 'representative' => array_fill_keys(self::POWERS, false)];
    }
}
