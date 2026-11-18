<?php
/**
 * §F-02 — a non-group post's own AUDIENCE gates its comment thread.
 *
 * Before this, `CommentService::gateForParent()` returned
 * `can_read => true` unconditionally for any post that was not
 * group-tagged, and `canSee()` was consulted only from `refuseParent()` —
 * i.e. only AFTER the gate had already refused. The success path never
 * asked. So anyone holding a feed id could read the thread, and therefore
 * the identity of everyone who had commented, on a followers-only or
 * only-me post.
 *
 * ── WHAT THIS SUITE CAN AND CANNOT PROVE ───────────────────────────────
 * The decision itself lives in SQL inside bcc-core (the STRICTER of the
 * post's `act_access` and its author's `usr_profile_acc`, evaluated over
 * ranges), and bcc-core tests it against rendered SQL in
 * `ActivityPerPostVisibilityTest`. Deliberately NOT restated here — a
 * second ladder in PHP is what §11 forbids and what drifts the next time
 * PeepSo adds a level.
 *
 * What is provable HERE, and is what actually went wrong, is the WIRING:
 * that the gate asks at all, that it asks with enforcement ON and for the
 * right viewer, that a refusal is indistinguishable from a missing id, and
 * that it does NOT ask where it must not.
 *
 * ⭐ The controls carry this suite. A gate that refused everybody would
 * satisfy every refusal assertion below; the group carve-out, the admin
 * exemption and the visible-post case are what make the refusals mean
 * something.
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Services\CommentService;
use BCC\Trust\Core\Services\FeedItemReportVisibility;
use BCC\Trust\Tests\Stubs\CommentDeleteFakes;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CommentAudienceGateTest extends TestCase
{
    private const VIEWER = 10;

    /** A post outside any group — the case that was ungated. */
    private const PLAIN_ACT  = 300;
    private const PLAIN_POST = 3000;

    /** A group-tagged post — deliberately EXEMPT from the audience rule. */
    private const GROUP_ACT  = 301;
    private const GROUP_POST = 3001;
    private const GROUP_ID   = 4231;

    private const NOT_FOUND_POST = ['error' => 'bcc_not_found', 'message' => 'Post not found.'];

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/comment-delete-stubs.php';
        CommentDeleteFakes::reset();

        CommentDeleteFakes::$activities[self::PLAIN_ACT] = (object) [
            'act_id'          => self::PLAIN_ACT,
            'act_external_id' => self::PLAIN_POST,
            'act_module_id'   => 1,
            'act_status'      => 'publish',
        ];
        // No `peepso_group_id` meta at all: an ordinary profile post.

        CommentDeleteFakes::$activities[self::GROUP_ACT] = (object) [
            'act_id'          => self::GROUP_ACT,
            'act_external_id' => self::GROUP_POST,
            'act_module_id'   => 1,
            'act_status'      => 'publish',
        ];
        CommentDeleteFakes::$postMeta[self::GROUP_POST]['peepso_group_id'] = (string) self::GROUP_ID;
    }

    /**
     * The real CommentService with a visibility double whose `canSee`
     * mirrors the audience answer, so the REFUSAL SHAPE is exercised
     * end-to-end.
     *
     * ⚠ `audienceAllows()` is static, so it is the REAL implementation on
     * every path below — the double only stands in for `canSee`/`isLive`,
     * which need collaborators a unit process does not have. That is why
     * these tests can assert the gate's behaviour rather than a stub's.
     */
    private static function service(bool $canSee): CommentService
    {
        $visibility = new class ($canSee) extends FeedItemReportVisibility {
            public function __construct(private bool $canSee)
            {
            }

            public function canSee(int $viewerId, object $activity): bool
            {
                return $this->canSee;
            }

            public function isLive(object $activity): bool
            {
                return true;
            }
        };

        $class   = new ReflectionClass(CommentService::class);
        $service = $class->newInstanceWithoutConstructor();
        $class->getProperty('visibility')->setValue($service, $visibility);
        $class->getProperty('commentRepo')->setValue($service, new CommentRepository());

        return $service;
    }

    /** The audience reads that actually carried enforcement. */
    private static function enforcedReads(): array
    {
        return array_values(array_filter(
            CommentDeleteFakes::$getByIdCalls,
            static fn(array $c): bool => $c['enforce'] === true
        ));
    }

    // ── the rule itself ────────────────────────────────────────────────

    /**
     * ⭐ The leak, closed: a non-group post whose audience excludes this
     * viewer refuses, and refuses as a MISSING ID rather than a 403 — a
     * refusal that said "forbidden" would itself disclose that the member
     * has posts they chose not to show.
     */
    public function testANonGroupPostTheViewerMayNotSeeRefusesLikeAMissingId(): void
    {
        CommentDeleteFakes::$f02Hidden = [self::PLAIN_ACT];

        $result = self::service(false)
            ->listByFeedId('feed_' . self::PLAIN_ACT, self::VIEWER, 'oldest', null, 20);

        self::assertSame(self::NOT_FOUND_POST, $result);

        // ⭐ The stronger claim: the thread was never QUERIED, not merely
        // filtered out of the response. A refusal that still ran the read
        // is one refactor away from leaking, and it pays for rows nobody
        // is allowed to see.
        self::assertSame([], CommentDeleteFakes::$listReads, 'the thread must never be read');
    }

    /**
     * ⭐ CONTROL. The same post, visible, still reads. Without this a gate
     * that refused every thread on the site would pass the test above.
     */
    public function testAVisibleNonGroupPostStillReadsItsThread(): void
    {
        $result = self::service(true)
            ->listByFeedId('feed_' . self::PLAIN_ACT, self::VIEWER, 'oldest', null, 20);

        self::assertArrayNotHasKey('error', $result, 'an ordinary post must still be readable');
        self::assertArrayHasKey('items', $result);
        self::assertSame(
            [self::PLAIN_POST],
            CommentDeleteFakes::$listReads,
            'and the thread IS read — the anti-vacuity half of the never-queried claim'
        );
    }

    /**
     * The gate must ASK, and ask correctly. A refusal alone does not prove
     * the audience was consulted — a gate broken in some other way refuses
     * too. This pins that the read carried `enforce = true` for THIS
     * viewer and for the PARENT act.
     */
    public function testTheAudienceReadIsEnforcedForTheRequestingViewer(): void
    {
        self::service(true)->listByFeedId('feed_' . self::PLAIN_ACT, self::VIEWER, 'oldest', null, 20);

        $enforced = self::enforcedReads();

        self::assertCount(1, $enforced, 'exactly one enforced audience read');
        self::assertSame(self::PLAIN_ACT, $enforced[0]['act_id']);
        self::assertSame(self::VIEWER, $enforced[0]['viewer']);
    }

    // ── the carve-out and the exemptions ───────────────────────────────

    /**
     * ⭐⭐ THE RATIFIED CARVE-OUT. Inside a group, posts follow the GROUP's
     * rules — members see them. Applying the audience rule here as well
     * would hide a `members_only` group post's thread from a fellow member
     * whenever its author keeps a private profile, which is the opposite
     * of what was decided.
     *
     * Asserted as "no enforced read happened", not merely "it was allowed":
     * a group post could be allowed for unrelated reasons, and what must
     * be true is that the audience question is never even asked.
     */
    public function testAGroupTaggedPostIsNeverAudienceGated(): void
    {
        CommentDeleteFakes::$f02Hidden = [self::GROUP_ACT];
        CommentDeleteFakes::$memberships[self::VIEWER][self::GROUP_ID] = 'member';

        $result = self::service(true)
            ->listByFeedId('feed_' . self::GROUP_ACT, self::VIEWER, 'oldest', null, 20);

        self::assertSame([], self::enforcedReads(), 'the group room must not consult §F-02');
        self::assertArrayNotHasKey('error', $result, 'a member still reads the room');
    }

    /**
     * ⭐ CONTROL. An admin keeps the §F-02 exemption — the same one the
     * rest of profile privacy uses — so an admin read cannot differ
     * between surfaces. Again asserted as "never asked": taking the
     * exemption means the predicate is not consulted at all.
     */
    public function testAnExemptAdminIsNotAudienceGated(): void
    {
        CommentDeleteFakes::$isAdmin  = true;
        CommentDeleteFakes::$f02Hidden = [self::PLAIN_ACT];

        $result = self::service(true)
            ->listByFeedId('feed_' . self::PLAIN_ACT, self::VIEWER, 'oldest', null, 20);

        self::assertSame([], self::enforcedReads(), 'the admin exemption skips the read entirely');
        self::assertArrayNotHasKey('error', $result);
    }

    // ── the write path ────────────────────────────────────────────────

    /**
     * Creating a comment on a post the viewer may not see is refused too,
     * and with the same missing-id answer. Reading and writing go through
     * ONE gate (`gateForParent`), which is why this needed no second
     * call site — but it is asserted rather than assumed, because "the
     * read is gated" and "the write is gated" are different claims.
     */
    public function testCommentingOnAPostTheViewerMayNotSeeIsRefused(): void
    {
        CommentDeleteFakes::$f02Hidden = [self::PLAIN_ACT];

        $result = self::service(false)->createComment(
            'feed_' . self::PLAIN_ACT,
            self::VIEWER,
            'a comment on something I cannot see'
        );

        self::assertSame(self::NOT_FOUND_POST, $result);
    }

    /**
     * An anonymous reader is gated by the same rule. `$viewerId = 0` must
     * reach the predicate as 0 (where bcc-core resolves level 30 to "the
     * author alone") rather than being skipped as "no viewer to check".
     */
    public function testAnAnonymousReaderIsGatedToo(): void
    {
        CommentDeleteFakes::$f02Hidden = [self::PLAIN_ACT];

        $result = self::service(false)
            ->listByFeedId('feed_' . self::PLAIN_ACT, 0, 'oldest', null, 20);

        self::assertSame(self::NOT_FOUND_POST, $result);

        $enforced = self::enforcedReads();
        self::assertCount(1, $enforced);
        self::assertSame(0, $enforced[0]['viewer'], 'anonymous must be passed through, not skipped');
    }
}
