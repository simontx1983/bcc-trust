<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\ModerationQueueService;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression pins for the 2026-07-21 admin-audit P2: the queue's
 * target block must expose SEMANTIC post kinds, not the raw numeric
 * `act_module_id`.
 *
 * Pre-fix, shapeTarget() copied act_module_id straight into post_kind
 * ("1"), which broke three things at once: the queue row rendered
 * "TARGET · 1", the preview branch (`=== 'status'`) never matched, and
 * the POST KIND filter chips could never find a report. The fix routes
 * through FeedItemNormalizer::MODULE_TO_KIND (the single module→kind
 * authority in bcc-core) with the feed's 'blog_excerpt' collapsed to
 * the queue enum value 'blog', plus the peepso_giphy layer-2 GIF
 * promotion and the CardUrlMap::postUrl permalink.
 *
 * Runs in subprocesses: shapeTarget's preview branch calls get_post(),
 * stubbed to null here (preview coverage belongs to an integration
 * tier; this file pins the pure mapping).
 */
#[CoversMethod(ModerationQueueService::class, 'shapeTarget')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ModerationQueueShapeTargetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Load the REAL bcc-core normalizer (not a stub) so the
        // module→kind lockstep is what's actually pinned. CI checks out
        // bcc-core adjacent to this plugin — same path convention as
        // tests/Integration/bootstrap.php's DB.php require.
        if (!\class_exists('BCC\\Core\\Feed\\FeedItemNormalizer', false)) {
            require_once dirname(__DIR__, 2) . '/../bcc-core/src/Feed/FeedItemNormalizer.php';
        }
        if (!\function_exists('get_post')) {
            // Preview source — null keeps preview '' so these tests pin
            // only the mapping. Defined here, not in a shared stub file,
            // because only this suite needs it.
            eval('function get_post($id = null) { return null; }');
        }
    }

    /**
     * @param array<int, string> $gifByPostId
     * @return array<string, mixed>
     */
    private static function shape(
        ?object $activity,
        string $authorHandle = '',
        ?string $shortCode = null,
        array $gifByPostId = []
    ): array {
        $report = (object) ['target_kind' => 'feed_item', 'target_id' => 169];
        $ref    = new ReflectionMethod(ModerationQueueService::class, 'shapeTarget');
        $ref->setAccessible(true);
        $out = $ref->invoke(null, $report, $activity, $authorHandle, $shortCode, $gifByPostId);
        self::assertIsArray($out);
        return $out;
    }

    /** @return object PeepSo activity row shape used by the queue. */
    private static function activity(int|string $moduleId, int $externalId = 5006): object
    {
        return (object) [
            'act_id'          => 169,
            'act_module_id'   => (string) $moduleId,
            'act_external_id' => $externalId,
            'act_user_id'     => 141,
            'act_time'        => '2026-07-20 01:31:19',
        ];
    }

    public function testStatusModuleMapsToSemanticKind(): void
    {
        $target = self::shape(self::activity(1));
        self::assertSame('status', $target['post_kind']);
    }

    public function testPhotoReviewBlogModulesMapToQueueEnum(): void
    {
        self::assertSame('photo', self::shape(self::activity(4))['post_kind']);
        self::assertSame('review', self::shape(self::activity(202))['post_kind']);
        // Feed kind 'blog_excerpt' collapses to the queue enum 'blog'.
        self::assertSame('blog', self::shape(self::activity(204))['post_kind']);
    }

    public function testGifPromotionViaGiphyMeta(): void
    {
        $target = self::shape(self::activity(1, 5006), '', null, [5006 => 'https://giphy.example/x.gif']);
        self::assertSame('gif', $target['post_kind']);
        // A different post id must NOT promote.
        $other = self::shape(self::activity(1, 5007), '', null, [5006 => 'https://giphy.example/x.gif']);
        self::assertSame('status', $other['post_kind']);
    }

    public function testUnknownModuleFallsBackToStatus(): void
    {
        self::assertSame('status', self::shape(self::activity(9999))['post_kind']);
    }

    public function testPostUrlComposedFromHandleAndShortcode(): void
    {
        $target = self::shape(self::activity(1), 'smokea4ic74l', 'nBnLvTPk');
        self::assertSame('/u/smokea4ic74l/post/nBnLvTPk', $target['post_url']);
    }

    public function testPostUrlEmptyWhenHandleOrCodeMissing(): void
    {
        self::assertSame('', self::shape(self::activity(1), '', 'nBnLvTPk')['post_url']);
        self::assertSame('', self::shape(self::activity(1), 'smokea4ic74l', null)['post_url']);
    }

    public function testNullActivityKeepsDegradedBase(): void
    {
        $target = self::shape(null);
        self::assertNull($target['post_kind']);
        self::assertSame('', $target['post_url']);
        self::assertSame(169, $target['target_id']);
    }
}
