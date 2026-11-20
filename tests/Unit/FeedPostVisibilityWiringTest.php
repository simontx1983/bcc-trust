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
        // F-451: the room-membership read (this suite only).
        require_once __DIR__ . '/../Stubs/permalink-room-membership-stubs.php';
        $GLOBALS['__bcc_f02feed'] = [
            'is_admin'         => false,
            'friend_ids'       => [],
            'calls'            => [],
            // The group rule ALLOWS by default; the refusal test sets it
            // false explicitly. See the stub header for why that way round.
            'group_rule'       => true,
            'group_rule_calls' => [],
            'membership_calls' => [],
            'excluded_by_read' => [],
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

    /**
     * F-276: the tag feed's body is shared with the signed-in hot page
     * (personalizedHotFeed), so what getTagFeed() hands it is pinned here:
     * the NORMALISED hashtag, the caller's cursor and the caller's limit,
     * on the global feed.
     */
    public function testTheTagFeedForwardsItsHashtagCursorAndLimit(): void
    {
        $this->service()->getTagFeed(self::VIEWER, '#Solana ', 'abc', 5);

        $args = $GLOBALS['__bcc_f02feed']['getfeed_args'] ?? [];
        self::assertIsArray($args);
        self::assertCount(1, $args);
        self::assertSame('Solana', $args[0]['hashtag'], 'trimmed, without the leading #');
        self::assertSame('abc', $args[0]['cursor']);
        self::assertSame(5, $args[0]['limit']);
        self::assertSame(self::VIEWER, $args[0]['viewerId']);
        self::assertNull($args[0]['onlyForGroupId'], 'the global feed, with its public_all gate');
        self::assertSame([self::VIEWER], $GLOBALS['__bcc_f02feed']['hydrated_for'] ?? null, 'hydrated for the viewer');
    }

    /** An empty hashtag answers an empty page without reading anything. */
    public function testAnEmptyHashtagReadsNothing(): void
    {
        $out = $this->service()->getTagFeed(self::VIEWER, '  ##  ');

        self::assertSame([], $out['items']);
        self::assertSame([], $this->calls(), 'no candidate read');
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

    // ── canView(): the permalink's yes/no, without the hydration ────

    /**
     * The permalink situations a mention bell must follow (N1): the cases
     * are NAMES so they survive separate-process serialisation.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function permalinkSituations(): array
    {
        return [
            'a post the viewer can open'                           => ['visible', true],
            'a non-group post its author restricted (followers-only, only me)' => ['profile_restricted', false],
            'a secret or closed group post, viewer not a member'   => ['group_refuses', false],
            'a closed group post, viewer a member'                 => ['group_member', true],
            'a group post bcc-core refuses outright'               => ['core_refuses', false],
            'a post by an author the viewer is blocked with'       => ['mutually_blocked', false],
            // F-451: a caution-tier author (the §O4.1 shadow-limit) in a room.
            "a caution holder's post in an NFT room, viewer a member"       => ['nft_room_member', true],
            "a caution holder's post in an NFT room, viewer not a member"   => ['nft_room_non_member', false],
            "a caution holder's post in an NFT room, viewer banned there"   => ['nft_room_banned', false],
            "a caution author's post in a plain room, viewer a member"      => ['plain_room_member', false],
            "a caution holder in an NFT room the viewer blocked"            => ['nft_room_blocked', false],
            "a caution holder in an NFT room who blocked the viewer"        => ['nft_room_blocker', false],
            "a caution holder in an NFT room, viewer and author blocked"    => ['nft_room_mutually_blocked', false],
            "a caution holder in an NFT room, viewer's join request pending" => ['nft_room_pending', false],
            "a caution holder in an NFT room, viewer only invited"          => ['nft_room_invited', false],
            "a caution holder in an NFT room whose group rule refuses"      => ['nft_room_group_rule_refuses', false],
            "a caution holder's post in a plain room and an NFT room, viewer in the NFT one" => ['multi_room_member', true],
            "a caution holder's post in two NFT rooms, viewer in the second only"           => ['two_nft_rooms_second', true],
        ];
    }

    /** NFT holder rooms (lift the shadow-limit) and a plain user room (does not). */
    private const NFT_ROOM   = 77;
    private const PLAIN_ROOM = 78;
    private const NFT_ROOM_2 = 79;

    private static function room(int $groupId, \BCC\Trust\Core\ValueObjects\GroupType $type): \BCC\Trust\Core\ValueObjects\GroupContext
    {
        return new \BCC\Trust\Core\ValueObjects\GroupContext(
            $groupId,
            $type,
            \BCC\Trust\Core\ValueObjects\PeepSoPrivacy::Closed,
            true,
            null,
            null,
            null
        );
    }

    private function arrange(string $situation): void
    {
        $row = ['external_id' => 5150, 'author' => ['id' => self::AUTHOR]];
        $f   = &$GLOBALS['__bcc_f02feed'];
        $f['row_enforced']   = $row;
        $f['row_unenforced'] = $row;
        $f['group_by_post']  = [];
        $f['group_contexts'] = [
            self::NFT_ROOM   => self::room(self::NFT_ROOM, \BCC\Trust\Core\ValueObjects\GroupType::Nft),
            self::PLAIN_ROOM => self::room(self::PLAIN_ROOM, \BCC\Trust\Core\ValueObjects\GroupType::User),
            self::NFT_ROOM_2 => self::room(self::NFT_ROOM_2, \BCC\Trust\Core\ValueObjects\GroupType::Nft),
        ];
        $inRoom = static function (int $room, ?string $status) use (&$f): void {
            $f['caution']       = [self::AUTHOR];
            $f['group_by_post'] = [5150 => $room];
            $f['membership']    = [self::VIEWER => [$room => $status]];
        };
        match ($situation) {
            'visible'            => null,
            'profile_restricted' => $f['row_enforced'] = null,
            'group_refuses'      => [$f['row_enforced'], $f['group_by_post'], $f['group_rule']] = [null, [5150 => 1138], false],
            'group_member'       => [$f['row_enforced'], $f['group_by_post'], $f['group_rule']] = [null, [5150 => 1138], true],
            'core_refuses'       => [$f['row_enforced'], $f['row_unenforced'], $f['group_by_post']] = [null, null, [5150 => 1138]],
            'mutually_blocked'   => $f['mutual_blocked'] = [self::AUTHOR],
            'nft_room_member'           => $inRoom(self::NFT_ROOM, 'member'),
            'nft_room_non_member'       => $inRoom(self::NFT_ROOM, null),
            'nft_room_banned'           => $inRoom(self::NFT_ROOM, 'banned'),
            'plain_room_member'         => $inRoom(self::PLAIN_ROOM, 'member'),
            'nft_room_blocked'          => [$inRoom(self::NFT_ROOM, 'member'), $f['blocked'] = [self::AUTHOR]],
            'nft_room_blocker'          => [$inRoom(self::NFT_ROOM, 'member'), $f['blockers'] = [self::AUTHOR]],
            'nft_room_mutually_blocked' => [$inRoom(self::NFT_ROOM, 'member'), $f['mutual_blocked'] = [self::AUTHOR]],
            'nft_room_pending'          => $inRoom(self::NFT_ROOM, 'pending_admin'),
            'nft_room_invited'          => $inRoom(self::NFT_ROOM, 'pending_user'),
            // The profile gate withholds it (row_enforced null), so each pass
            // reaches the group second look, whose rule says no.
            'nft_room_group_rule_refuses' => [$inRoom(self::NFT_ROOM, 'member'), $f['row_enforced'] = null, $f['group_rule'] = false],
            'multi_room_member'         => [
                $inRoom(self::NFT_ROOM, 'member'),
                $f['group_by_post'] = [5150 => [self::PLAIN_ROOM, self::NFT_ROOM]],
                $f['membership']    = [self::VIEWER => [self::PLAIN_ROOM => 'member', self::NFT_ROOM => 'member']],
            ],
            'two_nft_rooms_second'      => [
                $inRoom(self::NFT_ROOM, null),
                $f['group_by_post'] = [5150 => [self::NFT_ROOM, self::NFT_ROOM_2]],
                $f['membership']    = [self::VIEWER => [self::NFT_ROOM => null, self::NFT_ROOM_2 => 'member']],
            ],
        };
    }

    /**
     * canView() is the permalink's answer, in lockstep: it says yes exactly
     * when getActivityById() returns the item, and forwards the very same
     * reads (same viewer, friend list, gate and second look). A mention bell
     * relies on it to reach only a member the link would show the post to.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('permalinkSituations')]
    public function testCanViewAnswersExactlyWhatThePermalinkShows(string $situation, bool $expected): void
    {
        $this->arrange($situation);
        $item = $this->service()->getActivityById(1138, self::VIEWER);
        $permalinkCalls = $this->calls();
        $GLOBALS['__bcc_f02feed']['calls'] = [];
        $GLOBALS['__bcc_f02feed']['group_rule_calls'] = [];

        $canView = $this->service()->canView(1138, self::VIEWER);

        self::assertSame($expected, $item !== null, 'the permalink itself');
        self::assertSame($item !== null, $canView, 'canView() agrees with the permalink');
        self::assertSame($permalinkCalls, $this->calls(), 'the same reads, with the same arguments');
    }

    public function testCanViewAsksAboutTheGivenViewer(): void
    {
        $this->service()->canView(1138, self::FRIEND);

        self::assertSame(self::FRIEND, $this->onlyCall()['viewer']);
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

    // ── canView(): the admin exemption is the VIEWER's, not the request's ──

    /**
     * A mention is checked during the AUTHOR's request, so the current user
     * is the author, not the mentionee. An admin author must not lend the
     * mentionee an admin's read. canView() must answer every situation as the
     * member's own permalink request does, with the very same reads: the gate
     * enforced, and the group second look taken when it is owed.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('permalinkSituations')]
    public function testAnAdminRequestDoesNotLendTheViewerItsExemption(string $situation, bool $expected): void
    {
        $this->arrange($situation);
        $item = $this->service()->getActivityById(1138, self::VIEWER); // the member's own request
        $memberCalls = $this->calls();
        $GLOBALS['__bcc_f02feed']['calls'] = [];
        $GLOBALS['__bcc_f02feed']['group_rule_calls'] = [];

        $GLOBALS['__bcc_f02feed']['is_admin'] = true; // now the request's user is an admin

        self::assertSame($expected, $item !== null, 'the member\'s permalink');
        self::assertSame($expected, $this->service()->canView(1138, self::VIEWER), 'canView() during an admin\'s request');
        self::assertSame($memberCalls, $this->calls(), 'the same reads as the member\'s own request');
        self::assertTrue($this->calls()[0]['enforce'], 'the viewer is no admin, so the gate runs');
    }

    /** The reverse: an admin mentionee reads as an admin, whoever makes the request. */
    public function testAnAdminViewerIsExemptWhoeverMakesTheRequest(): void
    {
        $this->arrange('profile_restricted');
        $GLOBALS['__bcc_f02feed']['admin_ids'] = [self::VIEWER];

        self::assertTrue(
            $this->service()->canView(1138, self::VIEWER),
            'an admin opens what the profile gate withholds, as on their own permalink'
        );
        self::assertFalse($this->onlyCall()['enforce'], 'read unenforced, by the viewer\'s own exemption');
    }

    // ── F-451: a room that lifts the shadow-limit lifts it by link too ──
    //
    // The room shows a caution-tier holder's post to fellow holders
    // (getGroupFeed(), My Groups), so its permalink must open for them, and
    // the mention gate follows (canView() rows in permalinkSituations()).
    // Outside such a room, or for anyone not an active member of it, the
    // caution list's refusal stands.

    /**
     * The third look drops the caution list and nothing else: the block
     * lists still go to bcc-core. Without this, "re-read without the
     * caution list" could quietly become "re-read without exclusions".
     */
    public function testTheThirdLookDropsOnlyTheCautionList(): void
    {
        $this->arrange('nft_room_member');
        $GLOBALS['__bcc_f02feed']['blocked']  = [self::FRIEND];
        $GLOBALS['__bcc_f02feed']['blockers'] = [123];

        self::assertNotNull($this->service()->getActivityById(1138, self::VIEWER));

        $reads = $GLOBALS['__bcc_f02feed']['excluded_by_read'];
        self::assertCount(3, $reads, 'the gated read, the group second look, then the third look');
        self::assertSame([self::AUTHOR, self::FRIEND, 123], $reads[0], 'caution and blocks both ways, as before');
        self::assertSame([self::AUTHOR, self::FRIEND, 123], $reads[1]);
        self::assertSame([self::FRIEND, 123], $reads[2], 'the third look keeps the blocks both ways');
    }

    /** The moderation-hidden list and the §F-02 gate go to the third read exactly as to the first. */
    public function testTheThirdLookKeepsTheHiddenListAndTheProfileGate(): void
    {
        $this->arrange('nft_room_member');
        $GLOBALS['__bcc_f02feed']['hidden'] = [555];

        self::assertNotNull($this->service()->getActivityById(1138, self::VIEWER));

        $calls = $this->calls();
        self::assertCount(3, $calls);
        self::assertSame([555], $GLOBALS['__bcc_f02feed']['hidden_by_read'][2], 'the hidden list still applies');
        self::assertTrue($calls[2]['enforce'], 'the profile gate still runs for a non-exempt viewer');
        self::assertSame($calls[0]['friends'], $calls[2]['friends'], 'with the same friend list');
        self::assertFalse($calls[2]['defer'], 'and the group decision is not deferred on the gated read');
    }

    /** Only a refusal reaches the third look: a post the first look shows asks nothing more. */
    public function testTheThirdLookRunsOnlyAfterTheFirstLookRefused(): void
    {
        $this->arrange('visible');
        $GLOBALS['__bcc_f02feed']['caution'] = [self::FRIEND]; // a caution list exists, but not for this author

        self::assertNotNull($this->service()->getActivityById(1138, self::VIEWER));
        self::assertCount(1, $this->calls(), 'the first look answered');
        self::assertSame([], $GLOBALS['__bcc_f02feed']['membership_calls']);
    }

    /**
     * The membership asked is the VIEWER's, and only for the room that lifts
     * the limit. canView() runs during the AUTHOR's request on a mentionee's
     * behalf, so the current user (here an admin) must not stand in.
     */
    public function testTheLiftAsksAboutTheViewersOwnMembership(): void
    {
        $this->arrange('nft_room_member');
        $GLOBALS['__bcc_f02feed']['is_admin'] = true;

        self::assertTrue($this->service()->canView(1138, self::VIEWER));
        self::assertSame(
            [['user' => self::VIEWER, 'group' => self::NFT_ROOM]],
            $GLOBALS['__bcc_f02feed']['membership_calls']
        );
    }

    public function testARoomThatDoesNotLiftTheLimitNeverAsksForMembership(): void
    {
        $this->arrange('plain_room_member');

        self::assertNull($this->service()->getActivityById(1138, self::VIEWER));
        self::assertSame([], $GLOBALS['__bcc_f02feed']['membership_calls'], 'a plain room keeps the caution list');
    }

    /** No caution list, nothing to lift: no third read for an ordinary 404. */
    public function testWithNoCautionListThereIsNoThirdLook(): void
    {
        $this->arrange('core_refuses');

        self::assertNull($this->service()->getActivityById(1138, self::VIEWER));
        self::assertCount(2, $this->calls(), 'the gated read and the group second look only');
    }

    /**
     * ⚠ A membership that cannot be read answers EXACTLY like a missing post:
     * not lifted, so 404, and no bell. Never a 503. This read is reached only
     * for an existing post in a room that lifts the limit, so a 503 would
     * tell the caller both (security's rule for /feed/:id). A lift is never
     * granted on a read that did not run, and the failure is logged.
     */
    public function testAFailedMembershipReadAnswersLikeAMissingPost(): void
    {
        $this->arrange('nft_room_member');
        $GLOBALS['__bcc_f02feed']['membership'] = [self::VIEWER => [self::NFT_ROOM => false]];

        self::assertNull($this->service()->getActivityById(1138, self::VIEWER), 'not lifted: the same 404 as a missing id');
        self::assertFalse($this->service()->canView(1138, self::VIEWER), 'and no mention bell');

        $logs = $GLOBALS['__bcc_f02feed']['logs'] ?? [];
        self::assertCount(2, $logs, 'logged on each read, never silent');
        self::assertStringContainsString('room membership unreadable', $logs[0], 'the step is named in the log, and only there');
    }

    /**
     * An exempt (admin) viewer takes the third look too: it is the ROOM's
     * rule, not the §F-02 profile rule the exemption skips. It still needs
     * that viewer's own membership.
     *
     * @return array<string, array{0: ?string, 1: bool}>
     */
    public static function exemptViewerMemberships(): array
    {
        return [
            'an admin who is a member of the NFT room' => ['member', true],
            'an admin who is not a member'             => [null, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('exemptViewerMemberships')]
    public function testAnExemptViewerTakesTheThirdLookWithTheirOwnMembership(?string $status, bool $expected): void
    {
        $this->arrange('nft_room_member');
        $GLOBALS['__bcc_f02feed']['membership'] = [self::VIEWER => [self::NFT_ROOM => $status]];
        $GLOBALS['__bcc_f02feed']['admin_ids']  = [self::VIEWER];

        self::assertSame($expected, $this->service()->canView(1138, self::VIEWER));
        self::assertCount(2, $this->calls(), 'the exempt read, then the third look; no §F-02 second look');
        self::assertFalse($this->calls()[1]['enforce']);
    }
}
