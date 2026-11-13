<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit {

use BCC\Trust\Core\Repositories\HiddenActivityRepository;
use BCC\Trust\Core\Services\NotificationViewService;
use BCC\Trust\Core\Support\NotificationType;
use BCC\Trust\Core\Support\PushPayload;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * F-245 — reaction, mention and comment notifications open the POST.
 *
 * They linked `/?focus=<act_id>`, and nothing in the frontend reads `focus`
 * (no consumer in the home page, no middleware rewrite), so the three most
 * common notifications dead-ended on the Floor — bell rows and pushes alike.
 * They now link `/post/<act_id>`, the existing permalink route backed by
 * GET /feed/{id}.
 *
 * `target_available` tells the client when that post is KNOWN to be gone
 * (deleted, unpublished, moderation-hidden), so it can say so instead of
 * opening a 404. Unknown is not gone: a failed read leaves the link alone.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NotificationPostLinkTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/notification-post-link-stubs.php';
        $GLOBALS['__nv_cache']  = [];
        $GLOBALS['__nv_script'] = [];
        $GLOBALS['__nv_sql']    = [];
        $GLOBALS['wpdb']        = new \NotificationPostLinkFakeWpdb();
    }

    private static function resolve(string $type, int $actId): string
    {
        $m = new ReflectionMethod(NotificationViewService::class, 'resolveLink');
        $m->setAccessible(true);
        /** @var string $link */
        $link = $m->invoke(null, $type, 0, $actId, 'someone');

        return $link;
    }

    /**
     * @param list<int> $actIds
     * @return array<int, true>
     */
    private static function gone(?HiddenActivityRepository $hidden, array $actIds): array
    {
        $svc = new NotificationViewService(
            (new \ReflectionClass(\BCC\Trust\Core\Repositories\NotificationRepository::class))->newInstanceWithoutConstructor(),
            $hidden
        );
        $m = new ReflectionMethod(NotificationViewService::class, 'goneActIds');
        $m->setAccessible(true);
        /** @var array<int, true> $out */
        $out = $m->invoke($svc, $actIds);

        return $out;
    }

    /** Seed the hidden-id list the way a warm object cache serves it. */
    private static function hiddenRepo(array $hiddenIds): HiddenActivityRepository
    {
        $gen = \BCC\Trust\Core\Support\CacheGeneration::SEED_FLOOR + 7; // below the floor is re-seeded
        $GLOBALS['__nv_cache']['bcc_trust:hidden_activities']['gen']                   = $gen;
        $GLOBALS['__nv_cache']['bcc_trust:hidden_activities']['all_hidden_ids:' . $gen] = $hiddenIds;

        return new HiddenActivityRepository();
    }

    // ── links ───────────────────────────────────────────────────────────

    public function testReactionMentionAndCommentRowsLinkToThePostPermalink(): void
    {
        foreach ([NotificationType::REACTION, NotificationType::MENTION, NotificationType::COMMENT_RECEIVED] as $type) {
            self::assertSame('/post/4242', self::resolve($type, 4242), $type);
            self::assertSame('/', self::resolve($type, 0), "{$type} with no activity still falls back to the floor");
        }
    }

    public function testNoNotificationLinkUsesTheUnreadFocusParameter(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Services/NotificationViewService.php')
            . (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Support/PushPayload.php');
        $code = (string) preg_replace(['!/\*.*?\*/!s', '!//[^\r\n]*!'], '', $src);

        self::assertStringNotContainsString('focus=', $code, 'a `?focus=` link reaches no frontend consumer');
    }

    public function testPushPayloadsForMentionsAndCommentsOpenThePost(): void
    {
        self::assertSame('/post/77', PushPayload::forMention(1, ['actor_handle' => 'a', 'act_id' => 77])['url']);
        self::assertSame('/', PushPayload::forMention(3, ['actor_handle' => 'a', 'act_id' => 77])['url'], 'several mentions → floor');
        self::assertSame('/post/88', PushPayload::forCommentReceived(1, ['actor_handle' => 'a', 'act_id' => 88, 'post_id' => 5])['url']);
    }

    // ── target_available ────────────────────────────────────────────────

    public function testALivePostIsAvailable(): void
    {
        $GLOBALS['__nv_script'][] = [(object) ['act_id' => '10'], (object) ['act_id' => '11']];

        self::assertSame([], self::gone(self::hiddenRepo([]), [10, 11]));
        self::assertStringContainsString("p.post_status = 'publish'", $GLOBALS['__nv_sql'][0]);
        self::assertStringContainsString('a.act_id IN (10,11)', $GLOBALS['__nv_sql'][0]);
    }

    public function testADeletedOrUnpublishedPostIsGone(): void
    {
        $GLOBALS['__nv_script'][] = [(object) ['act_id' => '10']]; // 11 no longer backs a published post

        self::assertSame([11 => true], self::gone(self::hiddenRepo([]), [10, 11]));
    }

    public function testAModerationHiddenPostIsGone(): void
    {
        $GLOBALS['__nv_script'][] = [(object) ['act_id' => '10'], (object) ['act_id' => '11']];

        self::assertSame([10 => true], self::gone(self::hiddenRepo([10]), [10, 11]));
    }

    /** ⚠ Unknown is not gone: a failed read must never strip a working link. */
    public function testAFailedAvailabilityReadMarksNothingGone(): void
    {
        $GLOBALS['__nv_script'][] = false; // query() fails, with last_result left stale

        self::assertSame([], self::gone(self::hiddenRepo([]), [10, 11]));
        self::assertSame('warning', \BCC\Core\Log\Logger::$lines[0]['level'] ?? null, 'the unreadable check is logged');
    }

    public function testAQueryThatSetsLastErrorIsAlsoAFailure(): void
    {
        $GLOBALS['__nv_script'][] = ['error' => 'Deadlock found'];

        self::assertSame([], self::gone(self::hiddenRepo([]), [10, 11]));
    }

    public function testWithoutAHiddenRepositoryExistenceAloneDecides(): void
    {
        $GLOBALS['__nv_script'][] = [(object) ['act_id' => '10']];

        self::assertSame([11 => true], self::gone(null, [10, 11]));
    }

    public function testNoPostLinkedRowsMeansNoQuery(): void
    {
        self::assertSame([], self::gone(self::hiddenRepo([]), []));
        self::assertSame([], $GLOBALS['__nv_sql']);
    }
}

}
