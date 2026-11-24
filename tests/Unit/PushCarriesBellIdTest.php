<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use BCC\Trust\Core\Services\NotificationDispatcher;
use BCC\Trust\Core\Services\PageOwnerResolver;
use BCC\Trust\Core\Services\PushDispatcher;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-516: the push enqueued for an event carries the id of the bell row the
 * same event wrote, and no id when it wrote none.
 *
 * Real NotificationDispatcher and PushDispatcher::enqueue() over
 * tests/Stubs/mention-audience-stubs.php, whose PeepSo writer answers a row
 * id when `next_bell_id` is set. The mention path is the one with every
 * case: a bell, a bell its window already covered, a failed write, and a
 * bell the member turned off. The source pin at the end holds every other
 * push site to the same shape.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PushCarriesBellIdTest extends TestCase
{
    private const AUTHOR = 7;
    private const MEMBER = 11;
    private const POST   = 500;
    private const ACT    = 900;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/mention-audience-stubs.php';
        $GLOBALS['__bcc_mna'] = [
            'meta' => [], 'unmentionable' => [], 'bells' => [], 'pushes' => [],
            'transients' => [], 'now' => 1_800_000_000, 'deleted' => [], 'logs' => [],
            'next_bell_id' => 801,
        ];
        foreach ([self::AUTHOR, self::MEMBER] as $id) {
            $GLOBALS['__bcc_mna']['meta'][$id] = [
                'bcc_handle'                        => 'member' . $id,
                'bcc_notif_pref_push_master'        => '1',
                'bcc_notif_pref_push_event_mention' => '1',
            ];
        }
    }

    private function dispatcher(): NotificationDispatcher
    {
        $bare = static fn(string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return new NotificationDispatcher(
            $bare(PageOwnerResolver::class),
            new PushDispatcher($bare(PushSubscriptionRepository::class)),
            $bare(CommentRepository::class),
            static fn(int $actId, int $viewerId): bool => true
        );
    }

    private function mention(NotificationDispatcher $d, int $postId, int $actId): void
    {
        $d->dispatchMentionsFor(self::AUTHOR, $postId, 'hey @peepso_user_' . self::MEMBER . '(Member)', $actId, false);
    }

    /** @return array<string, mixed>|null the member's queued mention push */
    private static function queued(): ?array
    {
        $q = $GLOBALS['__bcc_mna']['transients']['bcc_push_q_' . self::MEMBER . '_mention']['value'] ?? null;
        return is_array($q) ? $q : null;
    }

    /** @return list<int> */
    private static function queuedIds(): array
    {
        $q = self::queued();
        self::assertNotNull($q, 'the push was queued');
        return $q['actors'][self::AUTHOR]['ids'];
    }

    public function testTheMentionPushCarriesTheBellRowItsMentionWrote(): void
    {
        $this->mention($this->dispatcher(), self::POST, self::ACT);

        self::assertSame([], $GLOBALS['__bcc_mna']['logs'], 'nothing failed on the way');
        self::assertCount(1, $GLOBALS['__bcc_mna']['bells']);
        self::assertSame([801], self::queuedIds());
        self::assertArrayNotHasKey('notification_id', self::queued()['first_payload'], 'the id is not part of the wording');
    }

    public function testAMentionTheWindowAlreadyCoveredAddsNoId(): void
    {
        $d = $this->dispatcher();
        $this->mention($d, self::POST, self::ACT);
        $this->mention($d, 502, 902); // same author, same member, inside the window: no second bell

        self::assertCount(1, $GLOBALS['__bcc_mna']['bells']);
        self::assertSame(2, self::queued()['count'], 'the push still counts both');
        self::assertSame([801], self::queuedIds());
    }

    public function testAFailedBellWriteSendsNoId(): void
    {
        $GLOBALS['__bcc_mna']['bell_write_fails'] = true;
        $this->mention($this->dispatcher(), self::POST, self::ACT);

        self::assertSame([], $GLOBALS['__bcc_mna']['bells']);
        self::assertSame(1, self::queued()['count'], 'the push still goes out');
        self::assertSame([], self::queuedIds());
    }

    public function testABellTurnedOffSendsNoId(): void
    {
        $GLOBALS['__bcc_mna']['meta'][self::MEMBER]['bcc_notif_pref_bell_bcc_mention'] = '0';
        $this->mention($this->dispatcher(), self::POST, self::ACT);

        self::assertSame([], $GLOBALS['__bcc_mna']['bells']);
        self::assertSame(1, self::queued()['count']);
        self::assertSame([], self::queuedIds());
    }

    public function testAWriteThatPeepSoSuppressedSendsNoId(): void
    {
        $GLOBALS['__bcc_mna']['next_bell_id'] = 0; // PeepSo's answer for an opt-out or block
        $this->mention($this->dispatcher(), self::POST, self::ACT);

        self::assertSame([], self::queuedIds());
    }

    /**
     * Every push NotificationDispatcher enqueues names the bell id of its own
     * event, taken from writeBell() in the same method; none still goes
     * through dispatch(), which cannot answer an id.
     */
    public function testEveryPushSiteCarriesItsOwnBellId(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(NotificationDispatcher::class))->getFileName());
        preg_match_all('/\n    (?:public|private|protected) function (\w+)\(/', $src, $m, PREG_OFFSET_CAPTURE);
        $starts = array_column($m[0], 1);
        $names  = array_column($m[1], 0);
        $starts[] = strlen($src);

        $sites = 0;
        foreach ($names as $i => $name) {
            $body = substr($src, $starts[$i], $starts[$i + 1] - $starts[$i]);
            $pushes = substr_count($body, '$this->pushDispatcher->enqueue(');
            if ($pushes === 0) {
                continue;
            }
            $sites += $pushes;
            self::assertSame($pushes, preg_match_all('/\'notification_id\'\s+=>\s+\$bellId,/', $body), "$name: each push names its bell id");
            self::assertStringContainsString('$bellId = $this->writeBell(', $body, "$name: the id comes from writeBell()");
            self::assertStringNotContainsString('$this->dispatch(', $body, "$name: no bell of a pushed event goes through dispatch()");
        }
        self::assertSame(9, $sites, 'the push sites this pin knows; a new one must carry its bell id too');
    }
}
