<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use BCC\Trust\Core\Services\NotificationDispatcher;
use BCC\Trust\Core\Services\PageOwnerResolver;
use BCC\Trust\Core\Services\PushDispatcher;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-415: at most one mention bell per (mentioner, mentionee) per 5 minutes,
 * across every kind of mention: a post, a comment, a quote.
 *
 * The only de-dup used to be per body (MentionExtractor's unique ids). Quotes
 * are unlimited, and the write throttles alone let one member bell another
 * hundreds of times an hour. Now a later mention in the window folds into
 * the bell already written, and the push still counts it.
 *
 * The order is (b), agreed with the main agent 2026-11-19:
 *   1. #767's audience gate;
 *   2. the window claim inside onMentioned();
 *   3. the bell only if claimed, and the push for every mention that passed
 *      the audience gate.
 * Failure rules: a window read that fails reads as open (one bell, never
 * zero, for that mention); a bell write that fails releases its window.
 *
 * Real dispatcher, MentionExtractor, MentionPolicy, NotificationPrefs and
 * PushDispatcher::enqueue() over tests/Stubs/mention-audience-stubs.php, whose
 * transient store honours TTLs against a test clock (`now`). A quote is a
 * status post that carries act_repost_id, written through createStatus(), so
 * it reaches the dispatcher exactly as a post does (bcc_post_created).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MentionBellWindowTest extends TestCase
{
    private const AUTHOR  = 7;
    private const MEMBER  = 11;
    private const OTHER   = 13;
    private const AUTHOR2 = 14;

    private const POST         = 500;   // a status post, and its act id
    private const ACT          = 900;
    private const COMMENT_POST = 501;   // a comment on it (links to the parent act)
    private const QUOTE_POST   = 502;   // a quote, its own post and act
    private const QUOTE_ACT    = 902;

    private const T0 = 1_800_000_000;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/mention-audience-stubs.php';
        $GLOBALS['__bcc_mna'] = [
            'meta' => [], 'unmentionable' => [], 'bells' => [], 'pushes' => [],
            'transients' => [], 'now' => self::T0, 'deleted' => [], 'logs' => [],
        ];
        foreach ([self::AUTHOR, self::MEMBER, self::OTHER, self::AUTHOR2] as $id) {
            $GLOBALS['__bcc_mna']['meta'][$id] = [
                'bcc_handle'                        => 'member' . $id,
                'bcc_notif_pref_push_master'        => '1',
                'bcc_notif_pref_push_event_mention' => '1',
            ];
        }
    }

    /** @param list<int> $refused members the permalink gate refuses */
    private function dispatcher(array $refused = []): NotificationDispatcher
    {
        $bare = static fn(string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return new NotificationDispatcher(
            $bare(PageOwnerResolver::class),
            new PushDispatcher($bare(PushSubscriptionRepository::class)),
            $bare(CommentRepository::class),
            static fn(int $actId, int $viewerId): bool => !in_array($viewerId, $refused, true)
        );
    }

    private static function body(int ...$ids): string
    {
        return implode(' ', array_map(static fn(int $id): string => "hey @peepso_user_{$id}(Member {$id})", $ids));
    }

    private function post(NotificationDispatcher $d, int ...$mentioned): void
    {
        $d->dispatchMentionsFor(self::AUTHOR, self::POST, self::body(...$mentioned), self::ACT, false);
    }

    /** @return list<array{0: int, 1: int}> [to, from] per bell */
    private static function bells(): array
    {
        return array_map(static fn(array $b): array => [$b['to'], $b['from']], $GLOBALS['__bcc_mna']['bells']);
    }

    private static function pushCount(int $recipient): int
    {
        $queued = $GLOBALS['__bcc_mna']['transients']['bcc_push_q_' . $recipient . '_mention']['value'] ?? null;
        return is_array($queued) ? (int) ($queued['count'] ?? 0) : 0;
    }

    private static function windowKey(int $author, int $member): string
    {
        return 'bcc_mention_bell_' . $author . '_' . $member;
    }

    // ── the window ────────────────────────────────────────────────────────

    public function testAPostACommentAndAQuoteRingOneBellAndThePushCountsAllThree(): void
    {
        $d = $this->dispatcher();
        $this->post($d, self::MEMBER);
        $d->dispatchMentionsFor(self::AUTHOR, self::COMMENT_POST, self::body(self::MEMBER), self::ACT, true);
        $d->dispatchMentionsFor(self::AUTHOR, self::QUOTE_POST, self::body(self::MEMBER), self::QUOTE_ACT, false);

        self::assertSame([[self::MEMBER, self::AUTHOR]], self::bells(), 'one bell: the first mention');
        self::assertSame(self::POST, $GLOBALS['__bcc_mna']['bells'][0]['external_id']);
        self::assertSame(3, self::pushCount(self::MEMBER), 'the push still counts every mention');
    }

    public function testTheWindowIsFiveMinutes(): void
    {
        $this->post($this->dispatcher(), self::MEMBER);

        self::assertSame(300, $GLOBALS['__bcc_mna']['transients'][self::windowKey(self::AUTHOR, self::MEMBER)]['ttl']);
    }

    public function testTheWindowReopensAfterFiveMinutes(): void
    {
        $d = $this->dispatcher();
        $this->post($d, self::MEMBER);
        $GLOBALS['__bcc_mna']['now'] += 299;
        $this->post($d, self::MEMBER);
        $GLOBALS['__bcc_mna']['now'] += 1;
        $this->post($d, self::MEMBER);

        self::assertCount(2, self::bells(), 'one in the window, one after it');
    }

    public function testEachMentionerAndEachMemberHasItsOwnWindow(): void
    {
        $d = $this->dispatcher();
        $this->post($d, self::MEMBER, self::OTHER);
        $d->dispatchMentionsFor(self::AUTHOR2, self::QUOTE_POST, self::body(self::MEMBER), self::QUOTE_ACT, false);

        self::assertSame(
            [[self::MEMBER, self::AUTHOR], [self::OTHER, self::AUTHOR], [self::MEMBER, self::AUTHOR2]],
            self::bells()
        );
    }

    /** The window lives in onMentioned(), so every caller of it is covered, not just the orchestrator. */
    public function testTheWindowHoldsForADirectCallerToo(): void
    {
        $d = $this->dispatcher();
        $d->onMentioned(self::AUTHOR, self::MEMBER, self::POST, self::ACT, false);
        $d->onMentioned(self::AUTHOR, self::MEMBER, self::QUOTE_POST, self::QUOTE_ACT, false);

        self::assertCount(1, self::bells());
    }

    // ── the order of the gates ────────────────────────────────────────────

    /** A mention the audience gate refuses claims no window, so it cannot eat a later, visible mention's bell. */
    public function testAMentionTheAudienceRefusesClaimsNoWindowAndAddsNoPush(): void
    {
        $this->post($this->dispatcher([self::MEMBER]), self::MEMBER);

        self::assertSame([], self::bells());
        self::assertArrayNotHasKey(self::windowKey(self::AUTHOR, self::MEMBER), $GLOBALS['__bcc_mna']['transients']);
        self::assertSame(0, self::pushCount(self::MEMBER));

        $this->post($this->dispatcher(), self::MEMBER);

        self::assertSame([[self::MEMBER, self::AUTHOR]], self::bells(), 'the visible mention still rings');
    }

    public function testASelfMentionClaimsNoWindow(): void
    {
        $this->post($this->dispatcher(), self::AUTHOR, self::MEMBER);

        self::assertSame([[self::MEMBER, self::AUTHOR]], self::bells());
        self::assertArrayNotHasKey(self::windowKey(self::AUTHOR, self::AUTHOR), $GLOBALS['__bcc_mna']['transients']);
    }

    // ── failure ───────────────────────────────────────────────────────────

    /** A window that cannot be read is treated as open: the mention gets its one bell, never none. */
    public function testAFailedWindowReadStillRingsExactlyOneBell(): void
    {
        $d = $this->dispatcher();
        $this->post($d, self::MEMBER);                    // the window is now claimed
        $GLOBALS['__bcc_mna']['transient_read_fails'] = true;
        $d->dispatchMentionsFor(self::AUTHOR, self::QUOTE_POST, self::body(self::MEMBER, self::MEMBER), self::QUOTE_ACT, false);

        self::assertCount(2, self::bells(), 'the unreadable window reads as open: one more bell, not two');
    }

    public function testAFailedBellWriteReleasesItsWindow(): void
    {
        $d = $this->dispatcher();
        $GLOBALS['__bcc_mna']['bell_write_fails'] = true;
        $this->post($d, self::MEMBER);

        self::assertSame([], self::bells());
        self::assertSame([self::windowKey(self::AUTHOR, self::MEMBER)], $GLOBALS['__bcc_mna']['deleted']);
        self::assertArrayNotHasKey(self::windowKey(self::AUTHOR, self::MEMBER), $GLOBALS['__bcc_mna']['transients']);

        $GLOBALS['__bcc_mna']['bell_write_fails'] = false;
        $d->dispatchMentionsFor(self::AUTHOR, self::QUOTE_POST, self::body(self::MEMBER), self::QUOTE_ACT, false);

        self::assertSame([[self::MEMBER, self::AUTHOR]], self::bells(), 'the next mention in the window still rings');
    }

    /** A member who turned mention bells off is owed nothing; that is not a failed write, so nothing is released. */
    public function testABellTurnedOffKeepsItsWindow(): void
    {
        $GLOBALS['__bcc_mna']['meta'][self::MEMBER]['bcc_notif_pref_bell_bcc_mention'] = '0';
        $this->post($this->dispatcher(), self::MEMBER);

        self::assertSame([], self::bells());
        self::assertSame([], $GLOBALS['__bcc_mna']['deleted']);
    }
}
