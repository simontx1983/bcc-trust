<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\PeepSoNameParts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * D1: PeepSo's "use name everywhere" aggressive mode writes its computed
 * name into wp_users.display_name. With the display style 'username' that
 * name is user_login, which undid the display-name hygiene backfill.
 * PeepSoNameParts filters `peepso_get_name_parts` so the computed name
 * never contains the login.
 */
#[CoversClass(PeepSoNameParts::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PeepSoNamePartsNeverLoginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        eval(<<<'PHP'
            function get_userdata(int $id)
            {
                return $GLOBALS['__pn_users'][$id] ?? false;
            }
            function get_user_meta(int $id, string $key = '', bool $single = false)
            {
                return $GLOBALS['__pn_meta'][$id][$key] ?? '';
            }
            function add_filter(string $hook, $cb, int $prio = 10, int $args = 1): void
            {
                $GLOBALS['__pn_filters'][] = [$hook, $cb, $prio, $args];
            }
            PHP);
        $GLOBALS['__pn_users'] = [
            7 => (object) ['ID' => 7, 'user_login' => 'jsmith77'],
            8 => (object) ['ID' => 8, 'user_login' => 'nohandle'],
            9 => (object) ['ID' => 9, 'user_login' => 'simon'],
        ];
        $GLOBALS['__pn_meta'] = [7 => ['bcc_handle' => 'jane-smith'], 9 => ['bcc_handle' => 'simon-t']];
        $GLOBALS['__pn_filters'] = [];
    }

    public function testTheUsernameStyleNameBecomesTheHandle(): void
    {
        // PeepSo style 'username' → name parts = [user_login].
        self::assertSame(['jane-smith'], PeepSoNameParts::filter(['jsmith77'], 7));
        self::assertSame(['jane-smith'], PeepSoNameParts::filter(['JSmith77'], 7), 'case-insensitive');
    }

    public function testTheAdminLoginSuffixIsDropped(): void
    {
        self::assertSame(['Jane', 'Smith'], PeepSoNameParts::filter(['Jane', 'Smith', '(jsmith77)'], 7));
        self::assertSame(['jane-smith'], PeepSoNameParts::filter(['jsmith77', '(jsmith77)'], 7));
    }

    public function testRealNamesAreUntouched(): void
    {
        self::assertSame(['Jane', 'Smith'], PeepSoNameParts::filter(['Jane', 'Smith'], 7));
    }

    public function testNoHandleFallsBackToMemberIdNeverTheLogin(): void
    {
        self::assertSame(['member-8'], PeepSoNameParts::filter(['nohandle'], 8));
    }

    public function testUnknownInputPassesThrough(): void
    {
        self::assertSame(['x'], PeepSoNameParts::filter(['x'], 999));
        self::assertSame('weird', PeepSoNameParts::filter('weird', 7));
        self::assertSame([], PeepSoNameParts::filter([], 7));
    }

    public function testItHooksPeepSosNamePartsFilterWithTheUserId(): void
    {
        PeepSoNameParts::register();
        self::assertSame(
            [['peepso_get_name_parts', [PeepSoNameParts::class, 'filter'], 10, 2]],
            $GLOBALS['__pn_filters']
        );
    }
    /**
     * Review finding on #602: a REAL first name equal to the login must not
     * be swapped, or aggressive mode would write "<handle> Tx" into
     * display_name. Only PeepSo's single-part fallback is replaced.
     */
    public function testARealFirstNameEqualToTheLoginIsLeftAlone(): void
    {
        self::assertSame(['Simon', 'Tx'], PeepSoNameParts::filter(['Simon', 'Tx'], 9));
        self::assertSame(['simon', 'Tx'], PeepSoNameParts::filter(['simon', 'Tx'], 9));
    }

    public function testAFirstNameOnlyStyleEqualToTheLoginIsTheFallbackShape(): void
    {
        // A single part equal to the login is indistinguishable from the
        // 'username' fallback, so it becomes the handle.
        self::assertSame(['simon-t'], PeepSoNameParts::filter(['Simon'], 9));
    }

    public function testTheReportedSuffixIsKeptAndIgnoredForTheShapeCheck(): void
    {
        self::assertSame(['simon-t', ' - Profile reported'], PeepSoNameParts::filter(['simon', ' - Profile reported'], 9));
        self::assertSame(['Simon', 'Tx', ' - Profile reported'], PeepSoNameParts::filter(['Simon', 'Tx', ' - Profile reported'], 9));
        self::assertSame(['simon-t', ' - Profile reported'], PeepSoNameParts::filter(['simon', '(simon)', ' - Profile reported'], 9));
    }
}
