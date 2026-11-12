<?php
/**
 * §F-02, posts half — every feed surface opts in to bcc-core's per-post
 * visibility gate, and the three that must NOT take the admin exemption
 * do not take it.
 *
 * What this suite is for: the behaviour under test is WHICH ARGUMENTS
 * bcc-trust passes. bcc-core owns the SQL and tests the ladder itself;
 * what can go wrong HERE is a surface that was never wired, a surface
 * handed the wrong shape of friend list, or — the one that would be
 * worst — a globally cached payload built with an admin's exemption and
 * then served to everyone.
 *
 * ⚠ None of that is reachable through a return value. The gate lives in
 * SQL inside bcc-core, so a bcc-trust test can only observe the call. A
 * suite that asserted on returned items would pass with the gate wired
 * nowhere.
 *
 * ⭐ Two of these tests are CONTROLS rather than confirmations: the
 * group feed asserting the gate is ABSENT (the one deliberate carve-out
 * — see the comment at that call site), and the admin case asserting the
 * exemption does reach the surfaces that are allowed to honour it. Drop
 * either and "enforce everywhere, always" would look correct.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\Feed\FeedRankingService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FeedPostVisibilityWiringTest extends TestCase
{
    private const VIEWER = 7;
    private const AUTHOR = 42;
    private const FRIEND = 99;

    protected function setUp(): void
    {
        parent::setUp();
        // ⛔ Never alongside profile-access-stubs.php or
        // follower-wiring-stubs.php — all three declare
        // \BCC\Core\Permissions\Permissions. See the stub's header.
        require_once __DIR__ . '/../Stubs/feed-f02-wiring-stubs.php';
        $GLOBALS['__bcc_f02feed'] = [
            'is_admin'         => false,
            'friend_ids'       => [],
            'calls'            => [],
            // The group rule ALLOWS by default; the refusal test sets it
            // false explicitly. See the stub header for why that way round.
            'group_rule'       => true,
            'group_rule_calls' => [],
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
            // The GROUP's own rule. Injected rather than lazily built, so
            // the permalink's second look can be observed instead of
            // reaching Plugin::instance() in a unit process.
            new \BCC\Trust\Core\Services\FeedItemReportVisibility()
        );
    }

    /** @return list<array<string, mixed>> */
    private function calls(): array
    {
        $c = $GLOBALS['__bcc_f02feed']['calls'] ?? [];

        return is_array($c) ? $c : [];
    }

    /** The one recorded call, asserted to be the only one. */
    private function onlyCall(): array
    {
        $calls = $this->calls();
        self::assertCount(1, $calls, 'expected exactly one forwarded read');

        return $calls[0];
    }

    // ── every viewer-specific surface enforces ──────────────────────

    /**
     * ⚠ The cases are NAMES, not closures: these tests run in separate
     * processes, so a provider value has to survive `serialize()` and a
     * closure does not (PHPUnit fails the whole run, not the case).
     *
     * @return array<string, array{0: string}>
     */
    public static function viewerSurfaces(): array
    {
        return [
            'personalized feed' => ['getFeed:for_you'],
            'permalink'         => ['getActivityById'],
            'author wall'       => ['getActivityForAuthor'],
            'page stream'       => ['getActivityForPage'],
        ];
    }

    /** Invoke the surface named by its recorded method label. */
    private function invokeSurface(string $method, int $viewerId): void
    {
        $s = $this->service();
        match ($method) {
            'getFeed:for_you'      => $s->getFeed($viewerId, 'for_you'),
            'getActivityById'      => $s->getActivityById(1138, $viewerId),
            'getActivityForAuthor' => $s->getActivityForAuthor(self::AUTHOR, $viewerId),
            'getActivityForPage'   => $s->getActivityForPage(5, $viewerId),
        };
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('viewerSurfaces')]
    public function testAViewerSpecificSurfaceEnforcesTheGate(string $expectedMethod): void
    {
        $GLOBALS['__bcc_f02feed']['friend_ids'] = [self::FRIEND];

        $this->invokeSurface($expectedMethod, self::VIEWER);
        $call = $this->onlyCall();

        self::assertSame($expectedMethod, $call['method']);
        self::assertTrue($call['enforce'], 'the §F-02 gate must be switched on');
        self::assertFalse(
            $call['defer'],
            'only the permalink SECOND look may hand the group decision over'
        );
        self::assertSame(self::VIEWER, $call['viewer']);
        self::assertSame([self::FRIEND], $call['friends']);
    }

    /**
     * ⚠⚠ THE SHAPE OF THE FRIEND LIST, not just its contents.
     * `PeepSoFriendGate::friendIdsOf()` returns `[id => true]`, and
     * bcc-core iterates the VALUES — so handing over the MAP instead of
     * `array_keys()` of it would silently gate on user id 1 (`true` cast
     * to int) and on nobody else. A count assertion would not catch it;
     * this one keys on the values.
     */
    public function testTheFriendListIsAListOfIdsAndNotTheLookupMap(): void
    {
        $GLOBALS['__bcc_f02feed']['friend_ids'] = [self::FRIEND, 101];

        $this->service()->getFeed(self::VIEWER, 'for_you');

        self::assertSame([self::FRIEND, 101], $this->onlyCall()['friends']);
    }

    /**
     * An anonymous reader is a friend of nobody, and that has to arrive
     * as `null` rather than `[]` — both deny today, but the parameter is
     * documented as nullable and the repository treats the two the same
     * only by coincidence of implementation.
     */
    public function testAnAnonymousReaderPassesNoFriendList(): void
    {
        $this->service()->getFeed(0, 'for_you');

        $call = $this->onlyCall();
        self::assertNull($call['friends']);
        self::assertTrue($call['enforce']);
    }

    // ── the admin exemption, and where it must not apply ────────────

    /**
     * Control: the exemption DOES reach a per-viewer surface. Without
     * this, hard-coding `enforce = true` everywhere would satisfy every
     * other test in this file while quietly removing the admin read the
     * profile half of F-02 grants.
     */
    public function testAnAdminReadsAPerViewerSurfaceUnenforced(): void
    {
        $GLOBALS['__bcc_f02feed']['is_admin'] = true;

        $this->service()->getFeed(self::VIEWER, 'for_you');

        self::assertFalse($this->onlyCall()['enforce']);
    }

    /**
     * ⚠⚠ THE HEADLINE. `/feed/hot` builds ONE payload, caches it in a
     * store shared by every reader and warms it from cron. An admin
     * request that took the exemption here would write an UNGATED hot
     * feed and serve it to everyone — strictly worse than the hole F-02
     * closes, and invisible unless the admin happened to be the request
     * that missed the cache.
     *
     * So the hot feed's gate is hard-coded on, and this asserts it with
     * `is_admin` TRUE — the condition under which a wired-up exemption
     * would be the one thing that failed.
     */
    public function testTheSharedHotFeedIsGatedEvenForAnAdmin(): void
    {
        $GLOBALS['__bcc_f02feed']['is_admin']   = true;
        $GLOBALS['__bcc_f02feed']['friend_ids'] = [self::FRIEND];

        $this->service()->getHotFeed(null, 20, self::VIEWER);

        $call = $this->onlyCall();
        self::assertTrue($call['enforce'], 'a shared payload must never be built unenforced');
        self::assertSame(0, $call['viewer'], 'the shared payload must know no viewer');
        self::assertNull($call['friends'], 'a shared payload must carry no friend list');
    }

    /**
     * The tag feed mirrors the hot feed's posture for the same reason:
     * its docblock's parity invariant cannot hold if an exempt reader
     * sees here what `/feed/hot` withholds, and the hot feed cannot
     * grant the exemption at all.
     *
     * ⚠ The real viewer id still reaches the gate (it is the same
     * argument the query needs), so the viewer's OWN posts are visible
     * here and not in the anonymous hot feed. That widening is bounded
     * to the viewer and their friends; it is stated in the docblock.
     */
    public function testTheTagFeedIsGatedEvenForAnAdmin(): void
    {
        $GLOBALS['__bcc_f02feed']['is_admin']   = true;
        $GLOBALS['__bcc_f02feed']['friend_ids'] = [self::FRIEND];

        $this->service()->getTagFeed(self::VIEWER, 'solana');

        $call = $this->onlyCall();
        self::assertSame('getFeed:tag', $call['method']);
        self::assertTrue($call['enforce']);
        self::assertSame([self::FRIEND], $call['friends']);
    }

    // ── F-297: the tag feed pre-filters blocks in SQL, like /feed ────

    public function testTheTagFeedExcludesTheViewersBlocksInSql(): void
    {
        $GLOBALS['__bcc_f02feed']['blocked']  = [501];
        $GLOBALS['__bcc_f02feed']['blockers'] = [502];

        $this->service()->getTagFeed(self::VIEWER, 'solana');

        $excluded = $GLOBALS['__bcc_f02feed']['excluded']['getFeed:tag'] ?? null;
        self::assertIsArray($excluded, 'the tag feed must forward an author exclusion');
        self::assertContains(501, $excluded, 'someone the viewer blocked');
        self::assertContains(502, $excluded, 'someone who blocked the viewer');
    }

    /** Control: the anonymous tag feed has no viewer, so no block list. */
    public function testTheAnonymousTagFeedForwardsNoBlockList(): void
    {
        $GLOBALS['__bcc_f02feed']['blocked'] = [501];

        $this->service()->getTagFeed(0, 'solana');

        self::assertNull($GLOBALS['__bcc_f02feed']['excluded']['getFeed:tag'] ?? null);
    }

    // ── F-269: an author always sees their own posts ─────────────────

    /** @return array<string, array{0: string, 1: string}> surface => [invoke label, recorded label] */
    public static function ownPostSurfaces(): array
    {
        return [
            'personalized feed' => ['getFeed:for_you', 'getFeed:for_you'],
            'permalink'         => ['getActivityById', 'getActivityById'],
            'author wall'       => ['getActivityForAuthor', 'getActivityForAuthor'],
            'page stream'       => ['getActivityForPage', 'getActivityForPage'],
            'tag feed'          => ['tag', 'getFeed:tag'],
            'plain group room'  => ['group', 'getFeed:group'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ownPostSurfaces')]
    public function testAFlaggedViewerIsNotShadowLimitedFromTheirOwnPosts(string $surface, string $recorded): void
    {
        $GLOBALS['__bcc_f02feed']['caution'] = [self::VIEWER, self::AUTHOR];

        if ($surface === 'tag') {
            $this->service()->getTagFeed(self::VIEWER, 'solana');
        } elseif ($surface === 'group') {
            $this->service()->getGroupFeed(self::VIEWER, 1138);
        } else {
            $this->invokeSurface($surface, self::VIEWER);
        }

        $excluded = $GLOBALS['__bcc_f02feed']['excluded'][$recorded] ?? null;
        self::assertIsArray($excluded);
        self::assertNotContains(self::VIEWER, $excluded, 'the viewer sees their own posts');
        self::assertContains(self::AUTHOR, $excluded, 'other flagged authors stay shadow-limited');
    }

    /** Control: the shared anonymous hot feed keeps the whole list. */
    public function testTheAnonymousHotFeedKeepsTheWholeCautionList(): void
    {
        $GLOBALS['__bcc_f02feed']['caution'] = [self::VIEWER, self::AUTHOR];

        $this->service()->getHotFeed(null, FeedRankingService::HOT_WARM_LIMIT, self::VIEWER);

        $excluded = $GLOBALS['__bcc_f02feed']['excluded']['getFeed:for_you'] ?? null;
        self::assertSame([self::VIEWER, self::AUTHOR], $excluded);
    }

    // ── the carve-out, asserted as a decision ───────────────────────

    /**
     * ⭐ CONTROL, and the one place this suite asserts the gate is OFF.
     * Inside a group the audience is the room: membership plus the
     * per-post `public_group` / `public_all` filter is the boundary, and
     * because BCC's writer stores `act_access = 0` the effective level of
     * a group post is its author's PROFILE level alone — so enforcing
     * here would empty the room of every post by a member whose profile
     * reads "only me".
     *
     * ⚠ If that decision is reversed, this test is the thing that has to
     * change first, and it is deliberately phrased so the reversal
     * cannot happen silently. The matching reasoning lives at the call
     * site in getGroupFeed().
     */
    public function testTheGroupRoomIsDeliberatelyNotGated(): void
    {
        $GLOBALS['__bcc_f02feed']['friend_ids'] = [self::FRIEND];

        $this->service()->getGroupFeed(self::VIEWER, 1138);

        $call = $this->onlyCall();
        self::assertSame('getFeed:group', $call['method']);
        self::assertFalse(
            $call['enforce'],
            'the group room is the documented §F-02 carve-out; reversing it is a product decision'
        );
    }

    // ── the permalink follows the GROUP, not the profile ────────────
    //
    // User decision, 2026-11-12: inside a group, posts follow the
    // GROUP's own visibility rules; profile privacy hides posts on the
    // main feed, the profile and the blog only. The room was already
    // ungated (above); these four are about the PERMALINK agreeing.
    //
    // ⚠ WHAT THESE TESTS CAN AND CANNOT SHOW. Group membership and the
    // permalink's syndication rule (`isVisibleGlobally()`) are resolved
    // inside bcc-core, so at this seam "the group allows it" IS the
    // unenforced call returning a row, and "the group refuses it" IS
    // that call returning null. What is asserted here is therefore that
    // bcc-trust DEFERS to the group's answer and stops imposing the
    // author's profile level on top of it — not that bcc-core resolves
    // membership correctly, which is bcc-core's own suite.

    /**
     * ⭐ THE DECISION, as a test. A post whose room shows it must not
     * 404 by link because its AUTHOR's profile is restricted.
     *
     * Modelled exactly as production hits it: the gated read comes back
     * null (the §F-02 predicate withheld the row), the post is
     * group-tagged, and the group's own answer is "yes" — so the
     * permalink resolves.
     */
    public function testAGroupPostPermalinkOpensDespiteTheAuthorsProfileLevel(): void
    {
        $row = ['external_id' => 5150];
        $GLOBALS['__bcc_f02feed']['row_enforced']   = null;
        $GLOBALS['__bcc_f02feed']['row_unenforced'] = $row;
        $GLOBALS['__bcc_f02feed']['group_by_post']  = [5150 => 1138];

        $item = $this->service()->getActivityById(1138, self::VIEWER);

        self::assertSame($row, $item, 'the room shows it, so the link must open it');

        $calls = $this->calls();
        self::assertCount(2, $calls, 'one gated read, then the group-only second look');
        self::assertTrue($calls[0]['enforce'], 'the gate is still tried FIRST');
        self::assertFalse($calls[1]['enforce'], 'the second look is the one that drops §F-02');
        self::assertNull(
            $calls[1]['friends'],
            'the friend list is inert when the gate is off; passing it would imply otherwise'
        );
    }

    /**
     * ⭐⭐ THE FAIL-CLOSED CONTROL, and the most important test of the
     * four. The second look deliberately reads UNENFORCED, so the only
     * thing standing between it and a plain §F-02 bypass is the
     * group-tag check. Delete that check and this is the single test
     * that fails.
     *
     * Same refusal as above, same unenforced row available — but the
     * post carries no `peepso_group_id`, so §F-02's refusal stands and
     * the permalink stays a 404.
     */
    public function testANonGroupPostStaysRefusedByTheProfileGate(): void
    {
        $GLOBALS['__bcc_f02feed']['row_enforced']   = null;
        $GLOBALS['__bcc_f02feed']['row_unenforced'] = ['external_id' => 5150];
        $GLOBALS['__bcc_f02feed']['group_by_post']  = [];

        self::assertNull(
            $this->service()->getActivityById(1138, self::VIEWER),
            'off a group surface §F-02 still decides; the second look must not launder it'
        );
    }

    /**
     * The other half of the decision: a non-member still gets a 404,
     * because the GROUP's rules are what the permalink now defers to.
     *
     * Here the group's own answer is "no" (bcc-core refuses the
     * unenforced read too — a non-member of a closed group, or a
     * `members_only` / `public_group` post that the permalink's
     * syndication rule never serves by link). bcc-trust must take that
     * for an answer rather than manufacture a row.
     */
    public function testAGroupPostTheGroupsOwnRulesRefuseStaysA404(): void
    {
        $GLOBALS['__bcc_f02feed']['row_enforced']   = null;
        $GLOBALS['__bcc_f02feed']['row_unenforced'] = null;
        $GLOBALS['__bcc_f02feed']['group_by_post']  = [5150 => 1138];

        self::assertNull($this->service()->getActivityById(1138, self::VIEWER));
        self::assertCount(2, $this->calls(), 'it asks the group, and accepts the refusal');
    }

    /**
     * An exempt reader already saw everything on the first read, so a
     * null from them is a genuine 404 and the second look would be a
     * wasted query. Also a guard against the second look drifting into
     * being the primary path.
     *
     * ⚠ Note which fixture row an exempt reader actually gets: their one
     * call IS the unenforced one, so it reads `row_unenforced`. That is
     * set to null here to model the genuine 404, while `row_enforced` is
     * deliberately left at its default ROW to show it is never consulted
     * — the second look does not happen at all.
     */
    /**
     * ⚠⚠ THE TRUST HALF OF THE GROUP-PERMALINK ALIGNMENT. The second look
     * tells bcc-core to stop deciding the group-tagged case
     * (`deferGroupVisibilityToCaller: true`), which is what lets a member
     * open a `members_only` post by link at all — bcc-core's permalink
     * admits only `public_all` on its own.
     *
     * ⚠ The FIRST read must not defer. It is an ordinary gated read, and
     * deferring there would drop the group gate from the path that is
     * supposed to answer most permalinks.
     */
    public function testOnlyTheSecondLookDefersTheGroupDecision(): void
    {
        $GLOBALS['__bcc_f02feed']['row_enforced']   = null;
        $GLOBALS['__bcc_f02feed']['row_unenforced'] = ['external_id' => 5150];
        $GLOBALS['__bcc_f02feed']['group_by_post']  = [5150 => 1138];

        $this->service()->getActivityById(1138, self::VIEWER);

        $calls = $this->calls();
        self::assertCount(2, $calls);
        self::assertFalse($calls[0]['defer'], 'the gated read is an ordinary read');
        self::assertTrue($calls[1]['defer'], 'the second look owns the group decision');
    }

    /**
     * ⭐⭐ THE FAIL-CLOSED TEST, and the one that matters most in this
     * file. `deferGroupVisibilityToCaller: true` REMOVES bcc-core's group
     * gate; the only thing left standing between that and a public
     * permalink for every closed-group post is this layer applying the
     * group's rule itself. Delete the `canSeeHydratedItem()` call and this
     * is the test that fails.
     *
     * Modelled as a non-member: the post is group-tagged and bcc-core
     * hands the row over, and the group says no.
     */
    public function testTheGroupRuleRefusingKeepsThePermalinkA404(): void
    {
        $GLOBALS['__bcc_f02feed']['row_enforced']   = null;
        $GLOBALS['__bcc_f02feed']['row_unenforced'] = ['external_id' => 5150];
        $GLOBALS['__bcc_f02feed']['group_by_post']  = [5150 => 1138];
        $GLOBALS['__bcc_f02feed']['group_rule']     = false;

        self::assertNull(
            $this->service()->getActivityById(1138, self::VIEWER),
            'a non-member must not reach a group post by link'
        );
    }

    /**
     * ⚠ The group rule is asked about the VIEWER and about the BACKING
     * post. Asking about the act_id would read group meta off an
     * unrelated low-numbered post (audit H-B1) and the answer would look
     * plausible; asking about the author would answer a different
     * question entirely.
     */
    public function testTheGroupRuleIsAskedAboutTheViewerAndTheBackingPost(): void
    {
        $GLOBALS['__bcc_f02feed']['row_enforced']   = null;
        $GLOBALS['__bcc_f02feed']['row_unenforced'] = ['external_id' => 5150];
        $GLOBALS['__bcc_f02feed']['group_by_post']  = [5150 => 1138];

        $this->service()->getActivityById(1138, self::VIEWER);

        $asked = $GLOBALS['__bcc_f02feed']['group_rule_calls'];
        self::assertCount(1, $asked, 'asked exactly once');
        self::assertSame(self::VIEWER, $asked[0]['viewer']);
        self::assertSame(5150, $asked[0]['post_id'], 'the BACKING post, not the act_id');
    }

    /**
     * A NON-group post is still refused, and the group rule is never even
     * consulted for it — §F-02's answer stands. Without this, the second
     * look would launder every profile-restricted post through a rule
     * that answers "true" for anything ungrouped.
     */
    public function testANonGroupPostNeverReachesTheGroupRule(): void
    {
        $GLOBALS['__bcc_f02feed']['row_enforced']   = null;
        $GLOBALS['__bcc_f02feed']['row_unenforced'] = ['external_id' => 5150];
        $GLOBALS['__bcc_f02feed']['group_by_post']  = [];

        self::assertNull($this->service()->getActivityById(1138, self::VIEWER));
        self::assertSame(
            [],
            $GLOBALS['__bcc_f02feed']['group_rule_calls'],
            'the group rule answers TRUE for a non-group post, so it must not be asked'
        );
    }

    public function testAnExemptReaderNeverTakesTheSecondLook(): void
    {
        $GLOBALS['__bcc_f02feed']['is_admin']       = true;
        $GLOBALS['__bcc_f02feed']['row_unenforced'] = null;
        $GLOBALS['__bcc_f02feed']['group_by_post']  = [5150 => 1138];

        self::assertNull($this->service()->getActivityById(1138, self::VIEWER));

        $call = $this->onlyCall();
        self::assertFalse($call['enforce'], 'the exemption read it unenforced the first time');
    }
}
