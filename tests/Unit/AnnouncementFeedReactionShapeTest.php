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
 * The serialized shape of an announcement's `reactions` block.
 *
 * `hydrateReactions()` is the ONLY reachable production path that emits an
 * announcement's reactions — the two REST composers are refused by
 * NoReactionsGate before they run — so this is where the wire shape has to
 * be proved rather than inferred from the boundary helper.
 *
 * ── ⚠⚠⚠ THE THINGS THAT MUST NOT HAPPEN ─────────────────────────────────
 *  1. `counts` SERIALIZES AS `[]`. PHP encodes an empty array as a JSON
 *     ARRAY, so the one grammar with an empty dict would ship a different
 *     TYPE for `counts` than every other kind. api-contract-v1.md §2.11
 *     and §4.32.15 both specify an empty OBJECT. Only an assertion on the
 *     ENCODED JSON can see this — every PHP-level check passes either way.
 *  2. THE STOKE FIELDS COME BACK. `heat_stage => 1` is a real (if cold)
 *     heat signal and a client renders a lit affordance from it;
 *     `stoke_count => 0` still says a stoke surface exists. Omitted, not
 *     zeroed.
 *  3. STORED DATA DEFEATS THE RULE. The fixtures below hand the
 *     announcement REAL reaction counts, a REAL heat stage and a viewer
 *     stoke. Nothing can write those today — which is precisely why they
 *     are supplied. "We never create those" is not a guarantee; the
 *     guarantee is that the serializer refuses them if they exist.
 *  4. ORDINARY KINDS LOSE THEIR ENGAGEMENT. A status post in the SAME call
 *     must keep its five zero-filled counts and all three stoke fields.
 *     Without that control, a change suppressing everything would pass
 *     every assertion above.
 *
 * bcc-core is not autoloadable here, so its collaborators are doubled at
 * their real FQCNs; every case runs in its own process.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AnnouncementFeedReactionShapeTest extends TestCase
{
    private const ANN_ACT    = 7001;
    private const STATUS_ACT = 7002;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/announcement-feed-shape-stubs.php';

        // ⚠ HOSTILE BY DESIGN — see MUST-NOT-HAPPEN #3. Both acts carry
        // engagement the write path would refuse for the announcement.
        $GLOBALS['__bcc_afs_counts'] = [
            self::ANN_ACT    => [92 => 11, 96 => 4],   // like, fire
            self::STATUS_ACT => [92 => 2],
        ];
        $GLOBALS['__bcc_afs_viewer'] = [self::ANN_ACT => 92, self::STATUS_ACT => 96];
        $GLOBALS['__bcc_afs_heat']   = [self::ANN_ACT => 5, self::STATUS_ACT => 3];
        $GLOBALS['__bcc_afs_stoken'] = [self::ANN_ACT => 9, self::STATUS_ACT => 2];
        $GLOBALS['__bcc_afs_stoked'] = [self::ANN_ACT => true, self::STATUS_ACT => true];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private static function hydrate(array $items, int $viewerId = 42): array
    {
        $rc       = new ReflectionClass(FeedHydrationPipeline::class);
        $pipeline = $rc->newInstanceWithoutConstructor();

        foreach ([
            'reactionRepo' => \BCC\Trust\Core\Repositories\PeepSoReactionRepository::class,
            'stokeRepo'    => \BCC\Trust\Core\Repositories\StokeRepository::class,
        ] as $prop => $class) {
            $p = $rc->getProperty($prop);
            $p->setValue($pipeline, new $class());
        }

        $m = new ReflectionMethod(FeedHydrationPipeline::class, 'hydrateReactions');
        $m->setAccessible(true);
        /** @var list<array<string, mixed>> $out */
        $out = $m->invoke($pipeline, $items, $viewerId);
        return $out;
    }

    /** @return array<string, mixed> */
    private static function item(int $actId, string $postKind): array
    {
        return ['id' => 'feed_' . $actId, 'post_kind' => $postKind];
    }

    // ── 1. The wire shape — MUST-NOT-HAPPEN #1, #2, #3 ────────────────────

    public function testTheAnnouncementReactionsBlockSerializesExactlyAsTheContractSays(): void
    {
        $out = self::hydrate([self::item(self::ANN_ACT, 'announcement')]);

        self::assertSame(
            '{"kind_grammar":"none","counts":{},"viewer_reaction":null}',
            json_encode($out[0]['reactions'])
        );
    }

    public function testCountsEncodeAsAnEmptyObjectAndNeverAnArray(): void
    {
        $out  = self::hydrate([self::item(self::ANN_ACT, 'announcement')]);
        $json = (string) json_encode($out[0]['reactions']);

        self::assertStringContainsString('"counts":{}', $json);
        self::assertStringNotContainsString('"counts":[]', $json);
    }

    public function testTheThreeStokeKeysAreAbsentRatherThanZeroed(): void
    {
        $out = self::hydrate([self::item(self::ANN_ACT, 'announcement')]);

        foreach (['heat_stage', 'viewer_has_stoked', 'stoke_count'] as $key) {
            self::assertArrayNotHasKey($key, $out[0]['reactions'], $key);
        }
    }

    /**
     * MUST-NOT-HAPPEN #3 made explicit: the fixture supplied 11 likes, 4
     * fires, heat 5, 9 stokers and a viewer stoke for this act. None of it
     * reaches the wire.
     */
    public function testStoredEngagementOnAnAnnouncementIsRefusedNotRendered(): void
    {
        $out  = self::hydrate([self::item(self::ANN_ACT, 'announcement')]);
        $json = (string) json_encode($out[0]['reactions']);

        foreach (['11', '"like"', '"fire"', '"heat_stage"', '"stoke_count"'] as $leak) {
            self::assertStringNotContainsString($leak, $json, "stored engagement leaked: {$leak}");
        }
        self::assertNull($out[0]['reactions']['viewer_reaction']);
    }

    // ── 2. Ordinary kinds keep everything — MUST-NOT-HAPPEN #4 ────────────

    public function testAStatusPostInTheSameCallKeepsItsCountsAndAllThreeStokeFields(): void
    {
        $out = self::hydrate([
            self::item(self::ANN_ACT, 'announcement'),
            self::item(self::STATUS_ACT, 'status'),
        ]);

        // The announcement is still stripped…
        self::assertSame('none', $out[0]['reactions']['kind_grammar']);
        self::assertArrayNotHasKey('heat_stage', $out[0]['reactions']);

        // …and the status post is untouched, in the same invocation.
        $status = $out[1]['reactions'];
        self::assertSame('social', $status['kind_grammar']);
        self::assertSame(
            ['like' => 2, 'love' => 0, 'haha' => 0, 'wow' => 0, 'fire' => 0],
            $status['counts']
        );
        self::assertSame('fire', $status['viewer_reaction']);
        self::assertSame(3, $status['heat_stage']);
        self::assertSame(2, $status['stoke_count']);
        self::assertTrue($status['viewer_has_stoked']);
    }

    /**
     * And a social kind's populated counts still encode as an OBJECT — so
     * the `{}` assertions above are about the empty case, not about a
     * blanket cast that reshaped everything.
     */
    public function testASocialKindStillEncodesItsCountsAsAnObject(): void
    {
        $out  = self::hydrate([self::item(self::STATUS_ACT, 'status')]);
        $json = (string) json_encode($out[0]['reactions']['counts']);

        self::assertStringStartsWith('{', $json);
        self::assertStringContainsString('"like":2', $json);
    }

    // ── 3. Degenerate inputs ──────────────────────────────────────────────

    /** An announcement with NO stored engagement looks identical. */
    public function testAnAnnouncementWithNoStoredEngagementLooksTheSame(): void
    {
        foreach (['__bcc_afs_counts', '__bcc_afs_viewer', '__bcc_afs_heat',
                  '__bcc_afs_stoken', '__bcc_afs_stoked'] as $g) {
            $GLOBALS[$g] = [];
        }

        $out = self::hydrate([self::item(self::ANN_ACT, 'announcement')]);

        self::assertSame(
            '{"kind_grammar":"none","counts":{},"viewer_reaction":null}',
            json_encode($out[0]['reactions'])
        );
    }

    /**
     * A missing or unrecognised `post_kind` must NOT be treated as
     * no-reactions — the grammar map falls back to `social`, so a typo
     * keeps its engagement rather than silently losing it.
     */
    public function testAnAbsentOrUnknownPostKindKeepsItsEngagement(): void
    {
        $out = self::hydrate([
            ['id' => 'feed_' . self::STATUS_ACT],                        // no post_kind
            ['id' => 'feed_' . self::STATUS_ACT, 'post_kind' => 'announcment'], // sic
        ]);

        foreach ($out as $i => $row) {
            self::assertSame('social', $row['reactions']['kind_grammar'], "row {$i}");
            self::assertArrayHasKey('heat_stage', $row['reactions'], "row {$i}");
        }
    }
}
