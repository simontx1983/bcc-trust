<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\NotificationViewService;
use BCC\Trust\Core\Support\NotificationType;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * F-389 — a join-request bell row opens the Members tab's requests block.
 *
 * `bcc_group_join_requested` linked the group's page, which opens on the
 * Stream; the request is decided on the Members tab's "Requests &
 * invitations" block. It now links `/communities/<slug>/members#requests`
 * (the frontend's `id="requests"` anchor). The fallback for a group that no
 * longer resolves stays the bare directory, and the approved / invited rows
 * keep linking the group page.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NotificationJoinRequestLinkTest extends TestCase
{
    private const GROUP_ID = 31;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/notification-join-request-link-stubs.php';
        $GLOBALS['__njr_groups'] = [
            self::GROUP_ID => (object) ['post_name' => 'anvil-guild'],
        ];
        \BCC\Core\Repositories\PeepSoGroupRepository::$lookups = [];
    }

    private static function resolve(string $type, int $groupId): string
    {
        $m = new ReflectionMethod(NotificationViewService::class, 'resolveLink');
        $m->setAccessible(true);
        /** @var string $link */
        $link = $m->invoke(null, $type, $groupId, 0, 'requester');

        return $link;
    }

    public function testAJoinRequestOpensTheMembersTabRequestsBlock(): void
    {
        self::assertSame(
            '/communities/anvil-guild/members#requests',
            self::resolve(NotificationType::GROUP_JOIN_REQUESTED, self::GROUP_ID)
        );
        self::assertSame([[self::GROUP_ID]], \BCC\Core\Repositories\PeepSoGroupRepository::$lookups);
    }

    public function testAGoneGroupFallsBackToTheBareDirectory(): void
    {
        // Not '/communities/members#requests': that is no route.
        self::assertSame('/communities', self::resolve(NotificationType::GROUP_JOIN_REQUESTED, 999));
        self::assertSame('/communities', self::resolve(NotificationType::GROUP_JOIN_REQUESTED, 0));
    }

    public function testApprovedAndInvitedRowsStillOpenTheGroupPage(): void
    {
        foreach ([NotificationType::GROUP_JOIN_APPROVED, NotificationType::GROUP_INVITED] as $type) {
            self::assertSame('/communities/anvil-guild', self::resolve($type, self::GROUP_ID), $type);
            self::assertSame('/communities', self::resolve($type, 999), "{$type} for a gone group");
        }
    }
}
