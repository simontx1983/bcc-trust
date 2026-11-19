<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\MyBlocksService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * GET /me/blocks names who holds a blocked id only when there is a member
 * AND the blocker may view their profile.
 *
 * Since v1.179 anyone can block any positive id. Before this, listing the
 * blocks then said whether a member holds the id, and who (handle, name,
 * avatar), private profiles included: the oracle v1.179 closed, reopened
 * (F-404). An id nobody holds also came back as `member-<id>`, with a
 * profile link to a page that does not exist.
 *
 * Every other row is the unknown row: the user_id, and "" for handle,
 * display name, avatar and profile link. It is identical for an id nobody
 * holds and for a member the blocker may not view.
 *
 * The REAL MyBlocksService, PublicIdentity and ProfileAccess::canViewProfile,
 * over PeepSo and suspension doubles (tests/Stubs/profile-access-stubs.php)
 * and the block list and avatar cache (blocks-list-identity-stubs.php).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MyBlocksListIdentityTest extends TestCase
{
    private const BLOCKER   = 5;
    private const VISIBLE   = 11; // public profile (10)
    private const PRIVATE   = 12; // "only me" (40)
    private const NOBODY    = 13; // no WP user holds the id
    private const SUSPENDED = 14; // public profile, suspended account

    private const UNKNOWN = ['handle' => '', 'display_name' => '', 'avatar_url' => '', 'profile_url' => ''];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-access-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/blocks-list-identity-stubs.php';

        $GLOBALS['__bcc_profile_access'] = [
            'viewer_id' => self::BLOCKER,
            'levels'    => [self::VISIBLE => 10, self::PRIVATE => 40, self::SUSPENDED => 10],
            'verdicts'  => [10 => true, 40 => false],
            'suspended' => [self::SUSPENDED => true],
            'calls'     => [],
        ];
        $GLOBALS['__bcc_blk'] = [
            'rows' => [
                self::row(1, self::VISIBLE, 'u_ramona', 'Ramona V.'),
                self::row(2, self::PRIVATE, 'u_quiet', 'Quiet Person'),
                self::row(3, self::NOBODY, null, null),
                self::row(4, self::SUSPENDED, 'u_gone', 'Gone Person'),
            ],
            'meta' => [
                self::VISIBLE   => ['bcc_handle' => 'ramona'],
                self::PRIVATE   => ['bcc_handle' => 'quiet'],
                self::SUSPENDED => ['bcc_handle' => 'gone'],
            ],
            'avatar_calls' => [],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_profile_access'], $GLOBALS['__bcc_blk']);
    }

    public function testAMemberTheBlockerMayViewIsShownWithTheirIdentity(): void
    {
        self::assertSame(
            [
                'user_id'      => self::VISIBLE,
                'handle'       => 'ramona',
                'display_name' => 'Ramona V.',
                'avatar_url'   => 'https://avatars.test/' . self::VISIBLE . '.png',
                'profile_url'  => '/u/ramona',
            ],
            $this->items()[self::VISIBLE]
        );
    }

    public function testAnIdNobodyHoldsIsTheUnknownRow(): void
    {
        self::assertSame(['user_id' => self::NOBODY] + self::UNKNOWN, $this->items()[self::NOBODY], 'never member-<id>');
    }

    public function testAPrivateMemberIsExactlyTheUnknownRow(): void
    {
        $items = $this->items();

        self::assertSame(['user_id' => self::PRIVATE] + self::UNKNOWN, $items[self::PRIVATE]);
        self::assertSame(
            array_diff_key($items[self::NOBODY], ['user_id' => 0]),
            array_diff_key($items[self::PRIVATE], ['user_id' => 0]),
            'a private member reads exactly like an id nobody holds'
        );
    }

    public function testASuspendedMemberIsTheUnknownRow(): void
    {
        self::assertSame(['user_id' => self::SUSPENDED] + self::UNKNOWN, $this->items()[self::SUSPENDED]);
    }

    public function testOnlyTheShownMembersAvatarsAreFetched(): void
    {
        $this->items();

        self::assertSame([[self::VISIBLE]], $GLOBALS['__bcc_blk']['avatar_calls']);
    }

    public function testWhenNoRowIsShownNoAvatarIsFetched(): void
    {
        $GLOBALS['__bcc_blk']['rows'] = [self::row(2, self::PRIVATE, 'u_quiet', 'Quiet Person'), self::row(3, self::NOBODY, null, null)];

        $this->items();

        self::assertSame([], $GLOBALS['__bcc_blk']['avatar_calls']);
    }

    public function testAnIdNobodyHoldsCostsNoProfileRead(): void
    {
        $this->items();

        $asked = array_merge(
            array_column($GLOBALS['__bcc_profile_access']['calls'], 'user_id'),
            array_column($GLOBALS['__bcc_profile_access']['susp_calls'] ?? [], 'user_id')
        );
        self::assertNotContains(self::NOBODY, $asked);
        self::assertContains(self::VISIBLE, $asked, 'a member is asked about');
    }

    public function testAskedOnBehalfOfAnotherViewerNoIdentityIsShown(): void
    {
        // canViewProfile() judges the CURRENT viewer, so it cannot answer
        // for a blocker who is not the one asking.
        $GLOBALS['__bcc_profile_access']['viewer_id'] = 99;

        self::assertSame(['user_id' => self::VISIBLE] + self::UNKNOWN, $this->items()[self::VISIBLE]);
        self::assertSame([], $GLOBALS['__bcc_blk']['avatar_calls']);
    }

    public function testEveryBlockStaysListedInOrderAndCounted(): void
    {
        $page = (new MyBlocksService())->getMyBlocks(self::BLOCKER);

        self::assertSame([self::VISIBLE, self::PRIVATE, self::NOBODY, self::SUSPENDED], array_column($page['items'], 'user_id'));
        self::assertSame(4, $page['pagination']['total']);
    }

    /** @return array<int, array<string, mixed>> user_id => item */
    private function items(): array
    {
        $page = (new MyBlocksService())->getMyBlocks(self::BLOCKER);

        return array_column($page['items'], null, 'user_id');
    }

    private static function row(int $blkId, int $userId, ?string $login, ?string $displayName): object
    {
        return (object) [
            'blk_id'         => (string) $blkId,
            'blk_blocked_id' => (string) $userId,
            'user_login'     => $login,
            'display_name'   => $displayName,
        ];
    }
}
