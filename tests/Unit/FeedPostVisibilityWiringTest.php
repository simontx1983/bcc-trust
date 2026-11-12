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
            'is_admin'   => false,
            'friend_ids' => [],
            'calls'      => [],
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
            new \BCC\Trust\Core\Services\Feed\FeedHydrationPipeline()
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
}
