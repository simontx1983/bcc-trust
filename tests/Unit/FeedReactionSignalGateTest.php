<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\Feed\FeedHydrationPipeline;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * F-90: reaction signals on a group item the viewer cannot read are
 * neutralised, by the SAME rule that already zeroes its comment_count
 * (FeedHydrationPipeline::unreadableGroupExternalIds: anonymous, no
 * membership row, or a status outside READ_ALLOWED_STATUSES).
 *
 * Neutral is what an unreacted post shows: grammar counts 0, heat_stage 1,
 * stoke_count 0, no headline, no reactor faces. The viewer's OWN reaction and
 * stoke flag are kept. Members and non-group items are untouched.
 *
 * Reuses the comment-count gating harness (tests/Stubs/feed-comment-gating-stubs.php).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class FeedReactionSignalGateTest extends TestCase
{
    private const VIEWER = 10;
    private const GROUP  = 4231;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/feed-comment-gating-stubs.php';
        $GLOBALS['__bcc_gate_fixture'] = ['status_map' => [], 'counts' => [], 'calls' => []];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function gate(array $items, int $viewerId): array
    {
        $pipeline = (new ReflectionClass(FeedHydrationPipeline::class))->newInstanceWithoutConstructor();
        $m = new ReflectionMethod(FeedHydrationPipeline::class, 'gateReactionSignals');

        /** @var list<array<string, mixed>> $out */
        $out = $m->invoke($pipeline, $items, $viewerId);
        return $out;
    }

    /** @return array<string, mixed> */
    private static function reactedGroupItem(int $externalId, ?int $groupId = self::GROUP): array
    {
        $item = [
            'external_id'  => $externalId,
            'reactions'    => [
                'kind_grammar'      => 'social',
                'counts'            => ['fire' => 12, 'solid' => 3],
                'viewer_reaction'   => 'fire',
                'heat_stage'        => 4,
                'viewer_has_stoked' => true,
                'stoke_count'       => 9,
            ],
            'social_proof' => [
                'headline'        => 'Ana and 3 others reacted',
                'recent_reactors' => [['handle' => 'ana', 'display_name' => 'Ana', 'avatar_url' => '']],
            ],
        ];
        if ($groupId !== null) {
            $item['group'] = ['id' => $groupId];
        }
        return $item;
    }

    public function testANonMembersGroupItemShowsNoEngagementSignal(): void
    {
        [$out] = self::gate([self::reactedGroupItem(501)], self::VIEWER);

        self::assertSame(['fire' => 0, 'solid' => 0], $out['reactions']['counts']);
        self::assertSame(1, $out['reactions']['heat_stage']);
        self::assertSame(0, $out['reactions']['stoke_count']);
        self::assertNull($out['social_proof']['headline']);
        self::assertArrayNotHasKey('recent_reactors', $out['social_proof']);
        // The viewer's OWN acts are theirs to see.
        self::assertSame('fire', $out['reactions']['viewer_reaction']);
        self::assertTrue($out['reactions']['viewer_has_stoked']);
    }

    public function testAnAnonymousViewerIsGatedWithoutAMembershipQuery(): void
    {
        [$out] = self::gate([self::reactedGroupItem(501)], 0);

        self::assertSame(['fire' => 0, 'solid' => 0], $out['reactions']['counts']);
        self::assertSame([], $GLOBALS['__bcc_gate_fixture']['calls']);
    }

    public function testAMemberSeesTheRealSignals(): void
    {
        $GLOBALS['__bcc_gate_fixture']['status_map'][self::VIEWER . ':' . self::GROUP] = 'member';
        $item = self::reactedGroupItem(501);

        self::assertSame([$item], self::gate([$item], self::VIEWER));
    }

    public function testANonGroupItemIsUntouched(): void
    {
        $item = self::reactedGroupItem(502, null);

        self::assertSame([$item], self::gate([$item], self::VIEWER));
    }

    public function testAKindWithNoStokeFieldsGainsNone(): void
    {
        $item = self::reactedGroupItem(503);
        unset($item['reactions']['heat_stage'], $item['reactions']['stoke_count'], $item['reactions']['viewer_has_stoked']);

        [$out] = self::gate([$item], self::VIEWER);

        self::assertArrayNotHasKey('heat_stage', $out['reactions']);
        self::assertArrayNotHasKey('stoke_count', $out['reactions']);
    }

    /** The reaction gate and the comment-count gate share one membership read per (viewer, group). */
    public function testTheMembershipReadIsSharedWithTheCommentCountGate(): void
    {
        $pipeline = (new ReflectionClass(FeedHydrationPipeline::class))->newInstanceWithoutConstructor();
        (new ReflectionClass(FeedHydrationPipeline::class))->getProperty('commentRepo')
            ->setValue($pipeline, new \BCC\Trust\Core\Repositories\CommentRepository());
        $items = [self::reactedGroupItem(501), self::reactedGroupItem(504)];

        $items = (new ReflectionMethod(FeedHydrationPipeline::class, 'gateReactionSignals'))->invoke($pipeline, $items, self::VIEWER);
        (new ReflectionMethod(FeedHydrationPipeline::class, 'hydrateCommentCounts'))->invoke($pipeline, $items, self::VIEWER);

        self::assertSame([[self::VIEWER, self::GROUP]], $GLOBALS['__bcc_gate_fixture']['calls']);
    }
}
