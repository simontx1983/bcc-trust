<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\ProfileAccess;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * ProfileAccess::hiddenOnlyByProfileLevel(): the authors the CURRENT viewer
 * may not see only because of the level they chose for their profile. On a
 * card page's Reviews tab, such an author's reply is shown as the page's own
 * response. Every other reason a list drops a member must be ruled out by a
 * read that ran, or the member is left out: a block either way, suspension,
 * a banned or unvalidated PeepSo role, an unreadable level.
 *
 * PeepSo's ladder and bcc-core's suspension answer are doubled
 * (profile-access-stubs.php); the block read is bcc-core's REAL
 * PeepSoBlockRepository over a recording $wpdb double.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(ProfileAccess::class)]
final class ProfileLevelOnlyHiddenTest extends TestCase
{
    private const VIEWER  = 5;
    private const PRIVATE = 61;   // "only me": hidden by the level
    private const PUBLIC  = 62;   // public: dropped for some other reason

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/profile-access-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/profile-level-block-wpdb.php';
        // bcc-core's REAL block read, over the double above.
        require_once \BCC\Trust\Tests\Support\CoreSibling::require('src/Repositories/ProbesReads.php');
        require_once \BCC\Trust\Tests\Support\CoreSibling::require('src/Repositories/PeepSoBlockRepository.php');

        $GLOBALS['__bcc_profile_access'] = [
            'viewer_id' => self::VIEWER,
            'is_admin'  => false,
            'levels'    => [self::PRIVATE => 40, self::PUBLIC => 10],
            'verdicts'  => [10 => true, 20 => true, 30 => false, 40 => false],
            'suspended' => [],
            'roles'     => [],
        ];
        $GLOBALS['wpdb'] = new \BccProfileLevelBlockWpdb();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb'], $GLOBALS['__bcc_profile_access']);
    }

    public function testAPrivateProfileIsHiddenByItsLevelAlone(): void
    {
        self::assertSame([self::PRIVATE], ProfileAccess::hiddenOnlyByProfileLevel([self::PRIVATE, self::PUBLIC, self::PRIVATE]));
        self::assertCount(1, $GLOBALS['wpdb']->queries, 'one batched block read');
        self::assertStringContainsString('blk_user_id', $GLOBALS['wpdb']->queries[0]);
    }

    /** @return array<string, array{array{int, int}}> */
    public static function blocks(): array
    {
        return [
            'the viewer blocked the author' => [[self::VIEWER, self::PRIVATE]],
            'the author blocked the viewer' => [[self::PRIVATE, self::VIEWER]],
        ];
    }

    /** @param array{int, int} $pair */
    #[DataProvider('blocks')]
    public function testABlockEitherWayIsNotTheProfileLevel(array $pair): void
    {
        $GLOBALS['wpdb']->blocks = [$pair];

        self::assertSame([], ProfileAccess::hiddenOnlyByProfileLevel([self::PRIVATE]));
    }

    public function testAFailedBlockReadLeavesEveryoneOut(): void
    {
        $GLOBALS['wpdb']->fail = true;

        self::assertSame([], ProfileAccess::hiddenOnlyByProfileLevel([self::PRIVATE]));
    }

    public function testASilentFailedBlockReadLeavesEveryoneOutToo(): void
    {
        $GLOBALS['wpdb']->failSilently = true;

        self::assertSame([], ProfileAccess::hiddenOnlyByProfileLevel([self::PRIVATE]));
    }

    /** @return array<string, array{bool|string}> */
    public static function suspension(): array
    {
        return [
            'suspended'          => [true],
            'suspension unknown' => ['unknown'],
        ];
    }

    #[DataProvider('suspension')]
    public function testASuspendedOrUnreadableAuthorIsLeftOut(bool|string $state): void
    {
        $GLOBALS['__bcc_profile_access']['suspended'][self::PRIVATE] = $state;

        self::assertSame([], ProfileAccess::hiddenOnlyByProfileLevel([self::PRIVATE]));
    }

    /** @return array<string, array{mixed}> */
    public static function roles(): array
    {
        return [
            'banned'           => ['ban'],
            'not yet verified' => ['register'],
            'verified email, not approved' => ['verified'],
            'empty'            => [''],
            'unreadable'       => [false],
        ];
    }

    #[DataProvider('roles')]
    public function testABannedUnvalidatedOrUnreadableRoleIsLeftOut(mixed $role): void
    {
        $GLOBALS['__bcc_profile_access']['roles'][self::PRIVATE] = $role;

        self::assertSame([], ProfileAccess::hiddenOnlyByProfileLevel([self::PRIVATE]));
    }

    public function testAModeratorWithAPrivateProfileIsHiddenByItsLevel(): void
    {
        $GLOBALS['__bcc_profile_access']['roles'][self::PRIVATE] = 'moderator';

        self::assertSame([self::PRIVATE], ProfileAccess::hiddenOnlyByProfileLevel([self::PRIVATE]));
    }

    public function testAnUnreadableLevelIsLeftOut(): void
    {
        $GLOBALS['__bcc_profile_access']['levels'][self::PRIVATE] = null;

        self::assertSame([], ProfileAccess::hiddenOnlyByProfileLevel([self::PRIVATE]));
    }

    public function testAnAnonymousViewerHasNoBlocksToRead(): void
    {
        $GLOBALS['__bcc_profile_access']['viewer_id'] = 0;

        self::assertSame([self::PRIVATE], ProfileAccess::hiddenOnlyByProfileLevel([self::PRIVATE]));
        self::assertSame([], $GLOBALS['wpdb']->queries);
    }

    public function testAdminsAndTheViewerThemselvesAreNeverHidden(): void
    {
        self::assertSame([], ProfileAccess::hiddenOnlyByProfileLevel([self::VIEWER]));

        $GLOBALS['__bcc_profile_access']['is_admin'] = true;
        self::assertSame([], ProfileAccess::hiddenOnlyByProfileLevel([self::PRIVATE]));
        self::assertSame([], $GLOBALS['wpdb']->queries, 'no read for either');
    }

    /** canViewProfile() keeps its verdicts after its level rung moved into a helper. */
    public function testCanViewProfileStillFollowsTheLevel(): void
    {
        self::assertFalse(ProfileAccess::canViewProfile(self::PRIVATE));
        self::assertTrue(ProfileAccess::canViewProfile(self::PUBLIC));

        $GLOBALS['__bcc_profile_access']['levels'][self::PUBLIC] = 'not-a-number';
        self::assertFalse(ProfileAccess::canViewProfile(self::PUBLIC), 'an unreadable level still denies');
    }
}
