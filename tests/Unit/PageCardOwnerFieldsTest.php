<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\CardViewService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Page card fields for the validator approval tool (contract v1.NEXT):
 *  - viewer_page_role: owner, an ACTIVE team role, or null;
 *  - the owner-only permission keys are HIDDEN (no hint) from a signed-out
 *    viewer, since signing in would not unlock them.
 */
#[CoversClass(CardViewService::class)]
final class PageCardOwnerFieldsTest extends TestCase
{
    private static function role(bool $isOwner, ?string $activeTeamRole): ?string
    {
        $m = new \ReflectionMethod(CardViewService::class, 'viewerPageRole');

        /** @var string|null */
        return $m->invoke(null, $isOwner, $activeTeamRole);
    }

    public function testTheOwnerIsOwner(): void
    {
        self::assertSame('owner', self::role(true, null));
        self::assertSame('owner', self::role(true, 'administrator'), 'the owner claim wins');
    }

    public function testAnActiveTeamRoleIsTheRole(): void
    {
        self::assertSame('administrator', self::role(false, 'administrator'));
        self::assertSame('representative', self::role(false, 'representative'));
    }

    public function testAnythingElseIsNoRole(): void
    {
        self::assertNull(self::role(false, null), 'no active team row');
        self::assertNull(self::role(false, 'operator'), 'not a team role');
        self::assertNull(self::role(false, ''), 'unreadable');
    }

    public function testOwnerOnlyControlsAreHiddenFromASignedOutViewer(): void
    {
        $m     = new \ReflectionMethod(CardViewService::class, 'lockedPagePermissions');
        /** @var array<string, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}> $perms */
        $perms = $m->invoke(null);

        foreach (['can_edit_bio', 'can_manage_team', 'can_edit_links', 'can_edit_notifications'] as $key) {
            self::assertSame(['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'not_claimer'], $perms[$key] ?? null, $key);
        }
        self::assertSame('Sign in to interact', $perms['can_watch']['unlock_hint'] ?? null, 'viewer actions keep their sign-in hint');
    }
}
