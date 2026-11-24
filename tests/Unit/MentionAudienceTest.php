<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use BCC\Trust\Core\Services\NotificationDispatcher;
use BCC\Trust\Core\Services\PageOwnerResolver;
use BCC\Trust\Core\Services\PushDispatcher;
use BCC\Trust\Core\Support\NotificationType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A mention bell is owed only to a member who can open the post it links to
 * (bug finder, 2026-11-18).
 *
 * MentionPolicy decides WHO may be mentioned; it never asked whether they can
 * see the post. A post or comment in a secret or closed group, or a post its
 * author restricted, could ping any mentionable member with a bell, a push and
 * a link: the permalink stayed gated, but the bell revealed that the hidden
 * post exists, its author, its timing and its act id.
 *
 * dispatchMentionsFor() now asks the permalink's own gate
 * (FeedRankingService::canView(), the same answer as GET /feed/{id}; its
 * situations are covered by FeedPostVisibilityWiringTest) about each
 * mentionee, and drops a refused one silently: no bell, no push, nothing
 * logged against them, and nothing to tell the author.
 *
 * Runs the real dispatcher, MentionExtractor, MentionPolicy, NotificationPrefs
 * and PushDispatcher::enqueue() over tests/Stubs/mention-audience-stubs.php;
 * the gate is a closure that records who it was asked about.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MentionAudienceTest extends TestCase
{
    private const AUTHOR = 7;
    private const SEES   = 11;
    private const BLIND  = 12;
    private const POST   = 500;   // the wp_post holding the body
    private const ACT    = 900;   // the act id the bell links to

    /** @var list<array{0: int, 1: int}> */
    private array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/mention-audience-stubs.php';
        $GLOBALS['__bcc_mna'] = ['meta' => [], 'unmentionable' => [], 'bells' => [], 'pushes' => [], 'logs' => []];
        foreach ([self::AUTHOR, self::SEES, self::BLIND, 13] as $id) {
            $GLOBALS['__bcc_mna']['meta'][$id] = [
                'bcc_handle'                       => 'member' . $id,
                'bcc_notif_pref_push_master'       => '1',
                'bcc_notif_pref_push_event_mention' => '1',
            ];
        }
        $this->asked = [];
    }

    /** A dispatcher whose permalink gate refuses the ids in $refused (or throws for $broken). */
    private function dispatcher(array $refused = [], array $broken = []): NotificationDispatcher
    {
        $gate = function (int $actId, int $viewerId) use ($refused, $broken): bool {
            $this->asked[] = [$actId, $viewerId];
            if (in_array($viewerId, $broken, true)) {
                throw new \RuntimeException('read did not run');
            }
            return !in_array($viewerId, $refused, true);
        };
        $bare = static fn(string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return new NotificationDispatcher(
            $bare(PageOwnerResolver::class),
            new PushDispatcher($bare(PushSubscriptionRepository::class)),
            $bare(CommentRepository::class),
            \Closure::fromCallable($gate)
        );
    }

    private static function body(int ...$ids): string
    {
        return implode(' ', array_map(static fn(int $id): string => "hey @peepso_user_{$id}(Member {$id})", $ids));
    }

    /** @return list<int> */
    private static function belled(): array
    {
        return array_column($GLOBALS['__bcc_mna']['bells'], 'to');
    }

    private static function pushedTo(int $id): bool
    {
        return in_array('bcc_push_q_' . $id . '_mention', $GLOBALS['__bcc_mna']['pushes'], true);
    }

    // ── the control: a mentionee who can open the post is still notified ──

    public function testAMentioneeWhoCanOpenThePostGetsTheBellAndThePush(): void
    {
        $this->dispatcher()->dispatchMentionsFor(self::AUTHOR, self::POST, self::body(self::SEES), self::ACT, false);

        self::assertSame([self::SEES], self::belled());
        $bell = $GLOBALS['__bcc_mna']['bells'][0];
        self::assertSame(NotificationType::MENTION, $bell['type']);
        self::assertSame(self::ACT, $bell['act_id'], 'it links to the post');
        self::assertSame(self::POST, $bell['external_id']);
        self::assertTrue(self::pushedTo(self::SEES));
        self::assertSame([[self::ACT, self::SEES]], $this->asked, 'asked about the mentionee, for the linked act');
    }

    // ── a mentionee the permalink refuses gets nothing ────────────────────

    /**
     * The dispatcher does not know WHY the permalink refuses; it asks. The
     * reasons are the permalink's (see FeedPostVisibilityWiringTest). A
     * comment is gated on its PARENT post, which is the act id its bell
     * links to.
     *
     * @return array<string, array{0: bool}>
     */
    public static function hiddenFromTheMentionee(): array
    {
        return [
            'a post in a secret group, mentionee not a member'    => [false],
            'a post in a closed group, mentionee not a member'    => [false],
            'a followers-only or only-me post'                     => [false],
            'a comment in a secret group (gated on its parent post)' => [true],
        ];
    }

    #[DataProvider('hiddenFromTheMentionee')]
    public function testAMentioneeThePermalinkRefusesGetsNoBellNoPushAndNoTrace(bool $isComment): void
    {
        $this->dispatcher([self::BLIND])->dispatchMentionsFor(
            self::AUTHOR,
            self::POST,
            self::body(self::BLIND, self::SEES),
            self::ACT,
            $isComment
        );

        self::assertSame([self::SEES], self::belled(), 'only the member who can open it');
        self::assertFalse(self::pushedTo(self::BLIND), 'no push either');
        self::assertTrue(self::pushedTo(self::SEES));
        self::assertSame([], $GLOBALS['__bcc_mna']['logs'], 'dropped silently: a refusal is not an error');
        self::assertSame([[self::ACT, self::BLIND], [self::ACT, self::SEES]], $this->asked);
    }

    public function testEveryMentioneeTheLinkRefusesIsDroppedNotJustTheFirst(): void
    {
        $this->dispatcher([self::BLIND, 13])->dispatchMentionsFor(self::AUTHOR, self::POST, self::body(self::BLIND, 13, self::SEES), self::ACT, false);

        self::assertSame([self::SEES], self::belled());
    }

    // ── fail closed ───────────────────────────────────────────────────────

    public function testAnAudienceThatCannotBeReadOwesNoBell(): void
    {
        $this->dispatcher([], [self::BLIND])->dispatchMentionsFor(self::AUTHOR, self::POST, self::body(self::BLIND, self::SEES), self::ACT, false);

        self::assertSame([self::SEES], self::belled(), 'unknown is not yes; the others still go');
        self::assertFalse(self::pushedTo(self::BLIND));
        self::assertCount(1, $GLOBALS['__bcc_mna']['logs'], 'the failed read is logged');
        self::assertStringContainsString('audience check failed', $GLOBALS['__bcc_mna']['logs'][0]);
    }

    public function testWithNoActIdThereIsNoPermalinkAndNoBell(): void
    {
        $this->dispatcher()->dispatchMentionsFor(self::AUTHOR, self::POST, self::body(self::SEES), 0, false);

        self::assertSame([], self::belled());
        self::assertSame([], $this->asked, 'nothing to ask about');
    }

    // ── the order of the gates ────────────────────────────────────────────

    public function testOnlyMentionableMembersAreAskedAbout(): void
    {
        $GLOBALS['__bcc_mna']['unmentionable'] = [self::BLIND];

        $this->dispatcher()->dispatchMentionsFor(self::AUTHOR, self::POST, self::body(self::BLIND, self::SEES), self::ACT, false);

        self::assertSame([self::SEES], self::belled());
        self::assertSame([[self::ACT, self::SEES]], $this->asked, 'MentionPolicy first, then the audience');
    }

    public function testASelfMentionIsNeitherCheckedNorNotified(): void
    {
        $this->dispatcher()->dispatchMentionsFor(self::AUTHOR, self::POST, self::body(self::AUTHOR, self::SEES), self::ACT, false);

        self::assertSame([self::SEES], self::belled());
        self::assertSame([[self::ACT, self::SEES]], $this->asked);
    }

    // ── production wiring ─────────────────────────────────────────────────

    /**
     * The dispatcher cannot be built without a gate (the constructor
     * requires it), and production hands it the permalink's own one.
     */
    public function testProductionGivesTheDispatcherThePermalinksGate(): void
    {
        $ctor = new \ReflectionMethod(NotificationDispatcher::class, '__construct');
        $last = $ctor->getParameters()[3];
        self::assertSame('canViewActivity', $last->getName());
        self::assertFalse($last->isOptional(), 'no dispatcher without an audience gate');

        $plugin = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Plugin.php');
        self::assertStringContainsString(
            'fn (int $actId, int $viewerId): bool => $this->feedRankingService()->canView($actId, $viewerId)',
            $plugin
        );
    }
}
