<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use BCC\Trust\Core\Services\NotificationDispatcher;
use BCC\Trust\Core\Services\PageOwnerResolver;
use BCC\Trust\Core\Services\PushDispatcher;
use BCC\Trust\Core\Support\NotificationType;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The hall-post and comment-received bell windows, through the real
 * dispatcher: one bell per (recipient, Hall) and per (recipient, post) every
 * 5 minutes, while push keeps its own count.
 *
 * Characterisation tests: written for F-415, when the three bell gates
 * (these two and F-78's attestation gate, AttestationBellCoalescingTest)
 * moved onto one helper, NotificationDispatcher::claimBellWindow(). They
 * pass on the inline gates the helper replaced and on the helper, so the
 * move changed no behaviour.
 *
 * Runs over tests/Stubs/mention-audience-stubs.php, whose transient store
 * honours each entry's TTL against a test clock (`now`).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class BellWindowGateTest extends TestCase
{
    private const AUTHOR    = 7;
    private const RECIPIENT = 11;
    private const HALL      = 300;
    private const POST      = 500;
    private const ACT       = 900;
    private const T0        = 1_800_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/mention-audience-stubs.php';
        $GLOBALS['__bcc_mna'] = [
            'meta' => [], 'unmentionable' => [], 'bells' => [], 'pushes' => [],
            'transients' => [], 'now' => self::T0, 'logs' => [],
        ];
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

    private function hallPost(NotificationDispatcher $d, int $recipient, int $hall): void
    {
        $d->onPostInPrimaryHall(self::AUTHOR, $recipient, self::POST, self::ACT, $hall, 'author', 'The Hall', 'the-hall');
    }

    private function commentReceived(NotificationDispatcher $d, int $recipient, int $parentPost): void
    {
        $m = new \ReflectionMethod(NotificationDispatcher::class, 'notifyCommentRecipient');
        $m->invoke($d, self::AUTHOR, $recipient, '@author commented on your post.', 'author', $parentPost, self::ACT, false);
    }

    /** @return list<array{to: int, type: string, external_id: int}> */
    private static function bells(string $type): array
    {
        $out = [];
        foreach ($GLOBALS['__bcc_mna']['bells'] as $bell) {
            if ($bell['type'] === $type) {
                $out[] = ['to' => $bell['to'], 'type' => $bell['type'], 'external_id' => $bell['external_id']];
            }
        }
        return $out;
    }

    private static function advance(int $seconds): void
    {
        $GLOBALS['__bcc_mna']['now'] += $seconds;
    }

    // ── hall-post: one bell per (recipient, Hall) per 5 minutes ─────────

    public function testABurstOfHallPostsRingsTheBellOnce(): void
    {
        $d = $this->dispatcher();
        $this->hallPost($d, self::RECIPIENT, self::HALL);
        $this->hallPost($d, self::RECIPIENT, self::HALL);
        $this->hallPost($d, self::RECIPIENT, self::HALL);

        self::assertCount(1, self::bells(NotificationType::HALL_POST));
        self::assertSame(300, $GLOBALS['__bcc_mna']['transients']['bcc_hall_post_notified_11_300']['ttl']);
    }

    public function testTheHallWindowReopensAfterFiveMinutes(): void
    {
        $d = $this->dispatcher();
        $this->hallPost($d, self::RECIPIENT, self::HALL);
        self::advance(299);
        $this->hallPost($d, self::RECIPIENT, self::HALL);
        self::advance(1);
        $this->hallPost($d, self::RECIPIENT, self::HALL);

        self::assertCount(2, self::bells(NotificationType::HALL_POST), 'one in the window, one after it');
    }

    public function testEachHallAndEachRecipientHasItsOwnWindow(): void
    {
        $d = $this->dispatcher();
        $this->hallPost($d, self::RECIPIENT, self::HALL);
        $this->hallPost($d, self::RECIPIENT, self::HALL + 1);
        $this->hallPost($d, self::RECIPIENT + 1, self::HALL);

        self::assertSame(
            [[self::RECIPIENT, self::HALL], [self::RECIPIENT, self::HALL + 1], [self::RECIPIENT + 1, self::HALL]],
            array_map(static fn(array $b): array => [$b['to'], $b['external_id']], self::bells(NotificationType::HALL_POST))
        );
    }

    // ── comment-received: one bell per (recipient, post) per 5 minutes ──

    public function testABurstOfCommentsRingsTheBellOnce(): void
    {
        $d = $this->dispatcher();
        $this->commentReceived($d, self::RECIPIENT, self::POST);
        $this->commentReceived($d, self::RECIPIENT, self::POST);

        self::assertCount(1, self::bells(NotificationType::COMMENT_RECEIVED));
        self::assertSame(300, $GLOBALS['__bcc_mna']['transients']['bcc_comment_received_notified_11_500']['ttl']);
    }

    public function testTheCommentWindowReopensAfterFiveMinutes(): void
    {
        $d = $this->dispatcher();
        $this->commentReceived($d, self::RECIPIENT, self::POST);
        self::advance(300);
        $this->commentReceived($d, self::RECIPIENT, self::POST);

        self::assertCount(2, self::bells(NotificationType::COMMENT_RECEIVED));
    }

    public function testEachPostHasItsOwnCommentWindow(): void
    {
        $d = $this->dispatcher();
        $this->commentReceived($d, self::RECIPIENT, self::POST);
        $this->commentReceived($d, self::RECIPIENT, self::POST + 1);

        self::assertSame(
            [self::POST, self::POST + 1],
            array_column(self::bells(NotificationType::COMMENT_RECEIVED), 'external_id')
        );
    }
}
