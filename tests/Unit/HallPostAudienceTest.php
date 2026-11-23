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
 * The primary-Hall bell and push go only to a member who could open the
 * post they link to: the permalink's own gate (FeedRankingService::canView,
 * injected as the dispatcher's $canViewActivity), asked per recipient.
 * Rationale: F-414 (private tracker).
 *
 * Runs over tests/Stubs/mention-audience-stubs.php (bells, the transient
 * store behind the bell window and the push queue, the logger).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class HallPostAudienceTest extends TestCase
{
    private const AUTHOR = 7;
    private const SEES   = 11;
    private const BLIND  = 12;
    private const BROKEN = 13;
    private const HALL   = 300;
    private const POST   = 500;
    private const ACT    = 900;

    /** @var list<array{0: int, 1: int}> every (act, viewer) the gate was asked */
    private array $asked = [];

    /** @var list<int> viewers the gate refuses (mutable mid-test) */
    private array $refused = [self::BLIND];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/mention-audience-stubs.php';
        $GLOBALS['__bcc_mna'] = [
            'meta' => [], 'unmentionable' => [], 'bells' => [], 'pushes' => [],
            'transients' => [], 'now' => 1_800_000_000, 'logs' => [],
        ];
        foreach ([self::AUTHOR, self::SEES, self::BLIND, self::BROKEN] as $id) {
            $GLOBALS['__bcc_mna']['meta'][$id] = [
                'bcc_handle'                          => 'member' . $id,
                'bcc_notif_pref_push_master'          => '1',
                'bcc_notif_pref_push_event_hall_post' => '1',
            ];
        }
    }

    private function dispatcher(): NotificationDispatcher
    {
        $gate = function (int $actId, int $viewerId): bool {
            $this->asked[] = [$actId, $viewerId];
            if ($viewerId === self::BROKEN) {
                throw new \RuntimeException('read did not run');
            }
            return !in_array($viewerId, $this->refused, true);
        };
        $bare = static fn(string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return new NotificationDispatcher(
            $bare(PageOwnerResolver::class),
            new PushDispatcher($bare(PushSubscriptionRepository::class)),
            $bare(CommentRepository::class),
            \Closure::fromCallable($gate)
        );
    }

    private function hallPost(NotificationDispatcher $d, int $recipient, int $actId = self::ACT): void
    {
        $d->onPostInPrimaryHall(self::AUTHOR, $recipient, self::POST, $actId, self::HALL, 'member7', 'The Hall', 'the-hall');
    }

    /** @return list<int> */
    private static function belled(): array
    {
        return array_column($GLOBALS['__bcc_mna']['bells'], 'to');
    }

    private static function pushedTo(int $id): bool
    {
        return in_array('bcc_push_q_' . $id . '_hall_post', $GLOBALS['__bcc_mna']['pushes'], true);
    }

    private static function windowClaimed(int $id): bool
    {
        return isset($GLOBALS['__bcc_mna']['transients']['bcc_hall_post_notified_' . $id . '_' . self::HALL]);
    }

    // ── the control: a member who can open the post still gets both ──────

    public function testAMemberWhoCanOpenThePostGetsTheBellAndThePush(): void
    {
        $this->hallPost($this->dispatcher(), self::SEES);

        self::assertSame([self::SEES], self::belled());
        $bell = $GLOBALS['__bcc_mna']['bells'][0];
        self::assertSame(NotificationType::HALL_POST, $bell['type']);
        self::assertSame(self::ACT, $bell['act_id'], 'it links to the post');
        self::assertSame(self::HALL, $bell['external_id']);
        self::assertTrue(self::pushedTo(self::SEES));
        self::assertSame([[self::ACT, self::SEES]], $this->asked, 'asked about this recipient, for the linked act');
    }

    // ── a member the permalink refuses gets nothing ──────────────────────

    public function testAMemberThePermalinkRefusesGetsNoBellAndNoPush(): void
    {
        $this->hallPost($this->dispatcher(), self::BLIND);

        self::assertSame([], self::belled());
        self::assertFalse(self::pushedTo(self::BLIND));
        self::assertFalse(self::windowClaimed(self::BLIND), 'the bell window is not used up');
        self::assertSame([], $GLOBALS['__bcc_mna']['logs'], 'a refusal is not an error');
    }

    public function testARefusalLeavesTheWindowOpenForTheNextPost(): void
    {
        $d = $this->dispatcher();
        $this->hallPost($d, self::BLIND);
        $this->refused = [];
        $this->hallPost($d, self::BLIND);

        self::assertSame([self::BLIND], self::belled(), 'the first post they may open still rings');
    }

    public function testAGateThatDidNotRunSendsNothingAndIsLogged(): void
    {
        $this->hallPost($this->dispatcher(), self::BROKEN);

        self::assertSame([], self::belled());
        self::assertFalse(self::pushedTo(self::BROKEN));
        self::assertCount(1, $GLOBALS['__bcc_mna']['logs']);
        self::assertStringContainsString('hall-post audience check failed', $GLOBALS['__bcc_mna']['logs'][0]);
    }

    public function testWithoutAnActIdThereIsNothingToOpenAndNothingIsSent(): void
    {
        $this->hallPost($this->dispatcher(), self::SEES, 0);

        self::assertSame([], self::belled());
        self::assertFalse(self::pushedTo(self::SEES));
        self::assertSame([], $this->asked, 'no act id: the gate is not even asked');
    }

    public function testEachRecipientIsAskedAboutSeparately(): void
    {
        $d = $this->dispatcher();
        foreach ([self::SEES, self::BLIND, self::BROKEN] as $recipient) {
            $this->hallPost($d, $recipient);
        }

        self::assertSame([[self::ACT, self::SEES], [self::ACT, self::BLIND], [self::ACT, self::BROKEN]], $this->asked);
        self::assertSame([self::SEES], self::belled());
    }
}
