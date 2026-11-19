<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\PageOwnerResolver;
use BCC\Trust\Core\Services\PageReadModelSync;
use BCC\Trust\Tests\Support\CoreSibling;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The owner's pages, after the bonus-page fix (user decision 2026-11-18):
 *
 *   - onOwnerDataChanged() still flags and syncs the owner's BONUS page at
 *     once, and now also marks every OTHER page they own dirty, bounded: the
 *     owner fields (verified, GitHub/X, wallet, followers) render on all of
 *     them, and only the bonus page used to be refreshed;
 *   - PageOwnerResolver::getPageForOwner()'s WordPress fallback picks the
 *     OLDEST page, like the scores read (get_posts defaults to the newest).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OwnerPagesSyncTest extends TestCase
{
    private const OWNER = 5;

    protected function setUp(): void
    {
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        require_once dirname(__DIR__) . '/Stubs/owner-pages-sync-stubs.php';
    }

    public function testEveryOwnedPageIsRefreshedAndOnlyTheBonusPageAtOnce(): void
    {
        \OwnerPagesWorld::$bonusPage  = 10;
        \OwnerPagesWorld::$ownedPages = [10, 11, 1_000_000_005];

        PageReadModelSync::onOwnerDataChanged(self::OWNER);

        self::assertSame([10], \OwnerPagesWorld::$flagged, 'the bonus page is recalculated');
        self::assertSame([10], \OwnerPagesWorld::$synced, 'and synced at once');
        self::assertSame([11, 1_000_000_005], \OwnerPagesWorld::$enqueued, 'every other owned page is marked dirty');
        self::assertSame([50], \OwnerPagesWorld::$ownedLimits, 'bounded');
    }

    public function testAnOwnerWithNoPageRefreshesNothing(): void
    {
        PageReadModelSync::onOwnerDataChanged(self::OWNER);

        self::assertSame([], \OwnerPagesWorld::$flagged);
        self::assertSame([], \OwnerPagesWorld::$enqueued);
    }

    public function testTheWordPressFallbackPicksTheOldestPage(): void
    {
        require_once CoreSibling::require('src/Contracts/PageOwnerResolverInterface.php');

        self::assertSame(0, (new PageOwnerResolver())->getPageForOwner(self::OWNER));

        $args = \OwnerPagesWorld::$getPostsArgs[0] ?? [];
        self::assertSame(self::OWNER, $args['author'] ?? null);
        self::assertSame('ID', $args['orderby'] ?? null);
        self::assertSame('ASC', $args['order'] ?? null);
        self::assertSame(1, $args['posts_per_page'] ?? null);
    }
}
