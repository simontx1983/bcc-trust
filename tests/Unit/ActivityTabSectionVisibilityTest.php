<?php
/**
 * F-341 — the profile SECTION switches reach the Activity tab.
 *
 * With Blogs switched off, a member's blog posts leave their Activity tab
 * as well as their Blogs tab. Same for Reviews, for the reviews they
 * WROTE. The Activity tab is the DEFAULT tab a visitor lands on, so
 * leaving it unfiltered made the switch cosmetic for the surface most
 * likely to be read.
 *
 * What this suite is for: the behaviour under test is WHICH MODULE IDS
 * bcc-trust asks bcc-core for. bcc-core owns the SQL and proves (in
 * ActivityWallModuleFilterTest) that the allowlist reaches the WHERE and
 * that `null` leaves the query unchanged. What can go wrong HERE is the
 * DECISION: a switch read for the wrong member, the owner or an admin
 * filtered out of their own wall, a hard-coded module id that drifted
 * from core, or — worst — a wall narrowed for everybody.
 *
 * ⚠ The decision is not reachable through a return value: the filter is
 * applied inside bcc-core's query, so a bcc-trust test can only observe
 * the call. A suite asserting on returned items would pass with the
 * filter wired nowhere.
 *
 * ⭐ The CONTROLS are the tests that matter most here. An untouched
 * profile must send `null` (every public module), the owner must be
 * unaffected, and an admin must be unaffected. Drop those three and
 * "filter the blog module out, always" would look entirely correct while
 * blanking blog posts from every wall on the site.
 *
 * ⚠ `ProfileSectionVisibility` and `PrivacySettings` are the REAL classes
 * here — only the meta STORAGE is faked. The three storage conventions
 * (new `bcc_show_*`, F-270's groups flag, the inverted §K2 `*_hidden`
 * flags) are exactly what could be read wrongly, so stubbing the accessor
 * would proof-read a stub.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\Feed\FeedRankingService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ActivityTabSectionVisibilityTest extends TestCase
{
    private const VIEWER = 7;
    private const AUTHOR = 42;

    /** PeepSo module id for a BCC blog excerpt. */
    private const MODULE_BLOG = 204;

    /** PeepSo module id for a BCC review. */
    private const MODULE_REVIEW = 202;

    /** PeepSo module id for an ordinary status — never filtered by F-341. */
    private const MODULE_STATUS = 1;

    /** The new `bcc_show_*` key for the Blogs section (absent = SHOWN). */
    private const BLOGS_KEY = 'bcc_show_blogs_on_profile';

    /** The §K2 HIDE flag for Reviews, read INVERTED (absent = SHOWN). */
    private const REVIEWS_KEY = 'bcc_privacy_reviews_hidden';

    protected function setUp(): void
    {
        parent::setUp();
        // ⛔ Never alongside profile-access-stubs.php,
        // profile-section-visibility-stubs.php or
        // follower-wiring-stubs.php — they all declare
        // \BCC\Core\Permissions\Permissions, and a class declaration
        // cannot be undone once the process has it.
        require_once __DIR__ . '/../Stubs/feed-f02-wiring-stubs.php';

        // ⭐ The REAL FeedItemNormalizer, not a stub of it. The production
        // code derives the blog/review module ids from that class's
        // MODULE_TO_KIND map precisely so it cannot drift from core; a
        // local copy of the map here would assert my own copy and the
        // drift this guards against would sail through.
        //
        // F-94: fails in CI when the sibling is missing (a skip would be
        // invisible in a green run), skips in a bare local worktree.
        require_once \BCC\Trust\Tests\Support\CoreSibling::require('src/Feed/FeedItemNormalizer.php');

        $GLOBALS['__bcc_f02feed'] = [
            'is_admin'         => false,
            'friend_ids'       => [],
            'calls'            => [],
            'group_rule'       => true,
            'group_rule_calls' => [],
            // F-341: faked user_meta storage. Empty = nobody has touched a
            // switch, which must read as SHOWN for every section.
            'section_meta'     => [],
            'module_ids'       => [],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_f02feed']);
        parent::tearDown();
    }

    private function service(): FeedRankingService
    {
        return new FeedRankingService(
            new \BCC\Core\Feed\ActivityFeedService(),
            new \BCC\Trust\Core\Repositories\ReputationRepository(),
            new \BCC\Trust\Core\Repositories\HiddenActivityRepository(),
            new \BCC\Trust\Core\Services\GroupContextResolver(),
            new \BCC\Trust\Core\Services\Feed\FeedHydrationPipeline(),
            new \BCC\Trust\Core\Services\FeedItemReportVisibility()
        );
    }

    /**
     * Read the wall as `$viewerId` and return the module allowlist it
     * asked bcc-core for.
     *
     * @return list<int>|null
     */
    private function allowlistSeenBy(int $viewerId): ?array
    {
        $this->service()->getActivityForAuthor(self::AUTHOR, $viewerId);

        // ⚠ array_key_exists, NOT `?? 'MISSING'`: null is the EXPECTED
        // value for an unfiltered wall, and `??` cannot tell a recorded
        // null from a call that never happened — it would report every
        // control as "never reached bcc-core".
        $recorded = $GLOBALS['__bcc_f02feed']['module_ids'] ?? [];
        self::assertIsArray($recorded);
        self::assertArrayHasKey(
            'getActivityForAuthor',
            $recorded,
            'the wall never reached bcc-core at all'
        );
        /** @var list<int>|null $allowlist */
        $allowlist = $recorded['getActivityForAuthor'];

        return $allowlist;
    }

    /** Switch a section off for the wall owner. */
    private function hideForAuthor(string $metaKey, string $value): void
    {
        $GLOBALS['__bcc_f02feed']['section_meta'][self::AUTHOR][$metaKey] = $value;
    }

    // ───────────────────────────────────────────────────────────────────
    // CONTROLS — these are what stop a blanket filter looking correct
    // ───────────────────────────────────────────────────────────────────

    /**
     * ⭐ THE control. A member who has never touched a switch must get the
     * unchanged query: `null`, meaning "every public module".
     *
     * Passing an explicit full list instead would be a behaviour change
     * dressed as a no-op — the repository binds an explicit list as
     * strings and its own default as ints, so "equivalent" lists are not
     * the same statement.
     */
    public function testAnUntouchedProfileAsksForEveryPublicModule(): void
    {
        self::assertNull(
            $this->allowlistSeenBy(self::VIEWER),
            'absent meta means SHOWN, so nothing may be filtered'
        );
    }

    /** ⭐ The owner sees their own wall whole, switches or not. */
    public function testTheOwnerStillSeesTheirOwnHiddenSections(): void
    {
        $this->hideForAuthor(self::BLOGS_KEY, '0');
        $this->hideForAuthor(self::REVIEWS_KEY, '1');

        self::assertNull(
            $this->allowlistSeenBy(self::AUTHOR),
            'the owner is never filtered out of their own Activity tab'
        );
    }

    /**
     * ⭐ An admin keeps the §F-02 exemption, as on every other surface.
     *
     * ⚠ The exemption is THIS VIEWER's, so the fixture names the viewer
     * in `admin_ids` as well as marking the request's user an admin. The
     * two are different questions: a list composed for one member while
     * an admin is signed in must not borrow that admin's exemption.
     */
    public function testAnExemptAdminStillSeesHiddenSections(): void
    {
        $GLOBALS['__bcc_f02feed']['is_admin']  = true;
        $GLOBALS['__bcc_f02feed']['admin_ids'] = [self::VIEWER];
        $this->hideForAuthor(self::BLOGS_KEY, '0');
        $this->hideForAuthor(self::REVIEWS_KEY, '1');

        self::assertNull(
            $this->allowlistSeenBy(self::VIEWER),
            'an admin read must not differ between surfaces'
        );
    }

    // ───────────────────────────────────────────────────────────────────
    // The switches themselves
    // ───────────────────────────────────────────────────────────────────

    public function testBlogsSwitchedOffDropsTheBlogModuleOnly(): void
    {
        $this->hideForAuthor(self::BLOGS_KEY, '0');

        $allowed = $this->allowlistSeenBy(self::VIEWER);

        self::assertIsArray($allowed);
        self::assertNotContains(self::MODULE_BLOG, $allowed, 'blog excerpts must not be candidates');
        self::assertContains(self::MODULE_REVIEW, $allowed, 'only Blogs was switched off');
        self::assertContains(self::MODULE_STATUS, $allowed, 'ordinary posts are untouched');
    }

    /**
     * The §K2 flag is stored INVERTED — `reviews_hidden`, not
     * `show_reviews` — so this also pins that the accessor reads it the
     * right way round. A reader that treated it as a show-flag would hide
     * reviews for everyone who had never set it.
     */
    public function testReviewsSwitchedOffDropsTheReviewModuleOnly(): void
    {
        $this->hideForAuthor(self::REVIEWS_KEY, '1');

        $allowed = $this->allowlistSeenBy(self::VIEWER);

        self::assertIsArray($allowed);
        self::assertNotContains(self::MODULE_REVIEW, $allowed, 'reviews they wrote must not be candidates');
        self::assertContains(self::MODULE_BLOG, $allowed, 'only Reviews was switched off');
        self::assertContains(self::MODULE_STATUS, $allowed);
    }

    public function testBothSwitchedOffStillLeavesAnOrdinaryWall(): void
    {
        $this->hideForAuthor(self::BLOGS_KEY, '0');
        $this->hideForAuthor(self::REVIEWS_KEY, '1');

        $allowed = $this->allowlistSeenBy(self::VIEWER);

        self::assertIsArray($allowed);
        self::assertNotContains(self::MODULE_BLOG, $allowed);
        self::assertNotContains(self::MODULE_REVIEW, $allowed);
        self::assertContains(self::MODULE_STATUS, $allowed);
        self::assertNotSame([], $allowed, 'two switches must not empty the whole wall');
    }

    /**
     * Hiding a section must never ADD a module. The allowlist has to stay
     * a subset of core's own public list, or the wall would start
     * admitting kinds (DMs, polls, group-join notices) that no feed may
     * show — a filter that widens is worse than no filter.
     */
    public function testTheAllowlistIsAlwaysASubsetOfCoresPublicModules(): void
    {
        $this->hideForAuthor(self::BLOGS_KEY, '0');

        $allowed = $this->allowlistSeenBy(self::VIEWER);
        $public  = \BCC\Core\Feed\FeedItemNormalizer::publicFeedModuleIds();

        self::assertIsArray($allowed);
        self::assertSame([], array_values(array_diff($allowed, $public)));
        self::assertNotSame([], $public, 'guards against both lists being empty');
    }

    /**
     * The switch belongs to the WALL OWNER, not to the viewer. Reading it
     * off the viewer would hide one member's posts because a different
     * member switched their own section off — and would leak nothing, so
     * it would go unnoticed.
     */
    public function testTheSwitchIsReadFromTheWallOwnerNotTheViewer(): void
    {
        // The VIEWER hides their blogs; the wall owner has not.
        $GLOBALS['__bcc_f02feed']['section_meta'][self::VIEWER][self::BLOGS_KEY] = '0';

        self::assertNull(
            $this->allowlistSeenBy(self::VIEWER),
            "the viewer's own switch says nothing about whose wall this is"
        );
    }

    /**
     * Disputes posts (`dispute_signed`, module 203) are deliberately left
     * alone: the ratified decision is that the Reviews switch covers only
     * reviews the member WROTE, and Disputes has its own §K2 flag that
     * governs the disputes SECTION rather than the activity stream.
     */
    public function testTheDisputesFlagDoesNotFilterTheWall(): void
    {
        $GLOBALS['__bcc_f02feed']['section_meta'][self::AUTHOR]['bcc_privacy_disputes_hidden'] = '1';

        self::assertNull(
            $this->allowlistSeenBy(self::VIEWER),
            'the Disputes switch is not an Activity-tab filter'
        );
    }
}
