<?php

declare(strict_types=1);

namespace BCC\Trust\Disputes\Tests\Unit;

use BCC\Trust\Core\Services\NotificationDispatcher;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for NotificationDispatcher::resolveWatchRecipient().
 *
 * `bcc_card_watched` carries a USER id for 'member' but a POST id for every
 * page kind. Treating the post id as a recipient sent the bell to whichever
 * unrelated user shared the number, and never to the page's owner.
 */
#[CoversMethod(NotificationDispatcher::class, 'resolveWatchRecipient')]
final class WatchRecipientTest extends TestCase
{
    private const VIEWER = 11;
    private const OWNER  = 22;
    private const PAGE   = 3852;

    /** @return array<string, array{string}> */
    public static function pageKinds(): array
    {
        return [
            'validator' => ['validator'],
            'project'   => ['project'],
            'creator'   => ['creator'],
        ];
    }

    #[DataProvider('pageKinds')]
    public function testPageWatchNotifiesTheOwnerNotThePostIdAsAUser(string $kind): void
    {
        $asked = [];
        $owner = function (int $pageId) use (&$asked): int {
            $asked[] = $pageId;
            return self::OWNER;
        };

        self::assertSame(
            self::OWNER,
            NotificationDispatcher::resolveWatchRecipient(self::VIEWER, $kind, self::PAGE, $owner)
        );
        self::assertSame([self::PAGE], $asked);
    }

    #[DataProvider('pageKinds')]
    public function testUnclaimedPageNotifiesNobody(string $kind): void
    {
        self::assertSame(
            0,
            NotificationDispatcher::resolveWatchRecipient(self::VIEWER, $kind, self::PAGE, static fn (int $id): int => 0)
        );
    }

    #[DataProvider('pageKinds')]
    public function testOwnerWatchingTheirOwnPageNotifiesNobody(string $kind): void
    {
        self::assertSame(
            0,
            NotificationDispatcher::resolveWatchRecipient(self::OWNER, $kind, self::PAGE, static fn (int $id): int => self::OWNER)
        );
    }

    public function testPostIdEqualToViewerIdStillNotifiesTheOwner(): void
    {
        // The old guard compared the viewer with the post id, so a viewer whose
        // user id happened to match the page id silently dropped the bell.
        self::assertSame(
            self::OWNER,
            NotificationDispatcher::resolveWatchRecipient(self::PAGE, 'validator', self::PAGE, static fn (int $id): int => self::OWNER)
        );
    }

    public function testMemberWatchNotifiesTheMemberWithoutAnOwnerLookup(): void
    {
        $owner = static function (int $id): int {
            self::fail('a member target is already a user id');
        };

        self::assertSame(
            self::OWNER,
            NotificationDispatcher::resolveWatchRecipient(self::VIEWER, 'member', self::OWNER, $owner)
        );
    }

    public function testMemberWatchingThemselvesNotifiesNobody(): void
    {
        self::assertSame(
            0,
            NotificationDispatcher::resolveWatchRecipient(self::VIEWER, 'member', self::VIEWER, static fn (int $id): int => self::OWNER)
        );
    }
}
