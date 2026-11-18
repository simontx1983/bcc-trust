<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * F-244 wiring: every surface that offers Join takes the verdict from
 * GroupsService (GroupJoinVerdictTest pins the logic itself).
 *
 *   - discovery and the profile Groups tab read the viewer's raw statuses
 *     in ONE batched call and pass them to GroupsService::listJoinFields();
 *   - the tab keeps no local copy of the join/leave logic;
 *   - the community card only passes the verdict through — it never
 *     recomputes it.
 */
final class GroupJoinVerdictWiringTest extends TestCase
{
    private static function source(string $rel): string
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $rel);
        return (string) preg_replace('/\s+/', ' ', $src);
    }

    /** @return array<string, array{string}> */
    public static function listSurfaces(): array
    {
        return [
            'discovery'   => ['app/Domain/Core/REST/GroupsDiscoveryEndpoint.php'],
            'profile tab' => ['app/Domain/Core/REST/UserGroupsEndpoint.php'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('listSurfaces')]
    public function testListSurfacesUseTheBatchedReadAndTheSharedVerdict(string $file): void
    {
        $src = self::source($file);

        self::assertSame(1, substr_count($src, 'PeepSoGroupRepository::findUserMembershipStatuses('), 'one batched status read');
        self::assertStringContainsString('->listJoinFields(', $src);
        self::assertStringContainsString('=== null ? false :', $src, 'a failed read reaches the verdict as FALSE (unavailable)');
    }

    public function testTheProfileTabKeepsNoLocalJoinOrLeaveLogic(): void
    {
        $src = self::source('app/Domain/Core/REST/UserGroupsEndpoint.php');

        self::assertStringNotContainsString('function canJoin(', $src);
        self::assertStringNotContainsString('function canLeave(', $src);
        self::assertStringContainsString('->leavePermission(', $src);
    }

    public function testTheCommunityCardPassesTheVerdictThrough(): void
    {
        $src = self::source('app/Domain/Core/Services/CardViewService.php');

        foreach (['can_join', 'can_request_join', 'viewer_membership_status'] as $key) {
            self::assertStringContainsString("'{$key}' => \$groupData['{$key}'] ?? null,", $src);
        }
    }

    public function testTheGroupPageGivesItsHeroCardItsOwnVerdict(): void
    {
        $src = self::source('app/Domain/Core/Services/GroupsService.php');

        self::assertStringContainsString("'can_join' => \$permissions['can_join'],", $src);
        self::assertStringContainsString("'can_request_join' => \$permissions['can_request_join'],", $src);
    }
}
