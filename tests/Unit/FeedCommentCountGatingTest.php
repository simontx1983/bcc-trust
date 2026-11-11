<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Services\Feed\FeedHydrationPipeline;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Comment-count gating on group feeds: a viewer must NOT see comment
 * counts for a group post they can't read, and the membership check
 * must be memoized per DISTINCT group (not per item).
 *
 * Leak invariant: only READ_ALLOWED_STATUSES memberships see counts;
 * everyone else (non-member, pending, banned, anon) gets 0. Perf
 * invariant: N items across D groups → D getMembershipStatus calls.
 *
 * ## Isolation
 * Subprocess-only; setUp() pulls in tests/Stubs/feed-comment-gating-stubs.php
 * which fakes the static bcc-core PeepSoGroupRepository and the injected
 * CommentRepository at their FQNs. The pipeline is built constructor-free
 * with the fake repo reflection-injected. No DB, no WP.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FeedCommentCountGatingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/feed-comment-gating-stubs.php';
        $GLOBALS['__bcc_gate_fixture'] = [
            'status_map' => [],
            'counts'     => [],
            'calls'      => [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function hydrate(array $items, int $viewerId): array
    {
        $pipeline = (new ReflectionClass(FeedHydrationPipeline::class))->newInstanceWithoutConstructor();
        $prop = (new ReflectionClass(FeedHydrationPipeline::class))->getProperty('commentRepo');
        $prop->setValue($pipeline, new CommentRepository());

        $m = new ReflectionMethod(FeedHydrationPipeline::class, 'hydrateCommentCounts');
        $m->setAccessible(true);
        /** @var list<array<string, mixed>> $out */
        $out = $m->invoke($pipeline, $items, $viewerId);
        return $out;
    }

    private static function groupItem(int $externalId, int $groupId): array
    {
        return [
            'external_id' => $externalId,
            'group'       => ['id' => $groupId],
        ];
    }

    // ── Announcements: no count at all, never a wrong one ─────────────

    /**
     * ⚠ AN ANNOUNCEMENT GETS NO `comment_count` KEY, NOT A ZERO.
     *
     * Its discussion lives in its own comment type and
     * `peepso_disable_comments` is set on the backing post, so the ACTIVITY
     * count this method reads is structurally zero and KNOWN TO BE WRONG.
     * "0 comments" on a notice with a live discussion reads as "nobody
     * replied" rather than as a missing integration — contract §4.32.15
     * requires the real count or none, never this one.
     *
     * assertArrayNotHasKey, not assertSame(0, ...): the distinction between
     * an absent key and a zero IS the behaviour, and only the former makes
     * the client hide the figure.
     */
    public function testAnAnnouncementGetsNoCommentCountKeyAtAll(): void
    {
        $GLOBALS['__bcc_gate_fixture']['counts'][77] = 12;   // a real discussion exists

        $out = self::hydrate([
            ['external_id' => 77, 'post_kind' => 'announcement'],
        ], 9);

        self::assertArrayNotHasKey('comment_count', $out[0]);
    }

    /**
     * Anti-vacuity, and the control that matters: the SAME call, the same
     * fixture, an ordinary kind — the count is present. Without this, a
     * change that withheld the count from everything would pass the case
     * above.
     */
    public function testAnOrdinaryKindStillGetsItsCountInTheSameCall(): void
    {
        $GLOBALS['__bcc_gate_fixture']['counts'] = [77 => 12, 88 => 3];

        $out = self::hydrate([
            ['external_id' => 77, 'post_kind' => 'announcement'],
            ['external_id' => 88, 'post_kind' => 'status'],
        ], 9);

        self::assertArrayNotHasKey('comment_count', $out[0], 'announcement');
        self::assertSame(3, $out[1]['comment_count'], 'status');
    }

    /** A missing or unrecognised post_kind keeps its count — no silent loss. */
    public function testAnAbsentOrUnknownPostKindStillGetsItsCount(): void
    {
        $GLOBALS['__bcc_gate_fixture']['counts'][99] = 5;

        foreach ([[], ['post_kind' => 'announcment'], ['post_kind' => '']] as $extra) {
            $out = self::hydrate([['external_id' => 99] + $extra], 9);
            self::assertSame(5, $out[0]['comment_count'], (string) json_encode($extra));
        }
    }

    public function testMemberSeesCountsNonMemberDoesNot(): void
    {
        $GLOBALS['__bcc_gate_fixture']['status_map'] = [
            '9:100' => 'member',
            '9:200' => 'banned',
        ];
        $GLOBALS['__bcc_gate_fixture']['counts'] = [11 => 5, 22 => 8];

        $out = self::hydrate([
            self::groupItem(11, 100), // member → real count
            self::groupItem(22, 200), // banned → 0
        ], 9);

        self::assertSame(5, $out[0]['comment_count']);
        self::assertSame(0, $out[1]['comment_count']);
    }

    public function testNoMembershipRowGatesToZero(): void
    {
        // status_map has no 9:300 entry → getMembershipStatus returns null.
        $GLOBALS['__bcc_gate_fixture']['counts'] = [33 => 4];

        $out = self::hydrate([self::groupItem(33, 300)], 9);

        self::assertSame(0, $out[0]['comment_count']);
    }

    public function testAnonymousViewerGetsZeroWithNoMembershipCalls(): void
    {
        $GLOBALS['__bcc_gate_fixture']['counts'] = [44 => 9];

        $out = self::hydrate([self::groupItem(44, 400)], 0);

        self::assertSame(0, $out[0]['comment_count']);
        self::assertSame([], $GLOBALS['__bcc_gate_fixture']['calls']);
    }

    public function testMembershipCheckMemoizedPerDistinctGroup(): void
    {
        $GLOBALS['__bcc_gate_fixture']['status_map'] = [
            '9:100' => 'member',
            '9:200' => 'member_readonly',
        ];
        $GLOBALS['__bcc_gate_fixture']['counts'] = [11 => 1, 12 => 2, 21 => 3, 22 => 4];

        // 4 items across 2 groups.
        self::hydrate([
            self::groupItem(11, 100),
            self::groupItem(12, 100),
            self::groupItem(21, 200),
            self::groupItem(22, 200),
        ], 9);

        // Exactly one membership query per distinct group.
        self::assertCount(2, $GLOBALS['__bcc_gate_fixture']['calls']);
        self::assertSame([[9, 100], [9, 200]], $GLOBALS['__bcc_gate_fixture']['calls']);
    }

    public function testNonGroupItemsPassThroughUngated(): void
    {
        $GLOBALS['__bcc_gate_fixture']['counts'] = [55 => 7];

        // No 'group' key → ungated → real count, no membership call.
        $out = self::hydrate([['external_id' => 55]], 9);

        self::assertSame(7, $out[0]['comment_count']);
        self::assertSame([], $GLOBALS['__bcc_gate_fixture']['calls']);
    }
}
