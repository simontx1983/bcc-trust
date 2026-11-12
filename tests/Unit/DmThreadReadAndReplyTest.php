<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Core\Services\MessagesService;
use BCC\Trust\Core\Support\MemberCardPrefetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * F-300: a failed DM read is a retryable 503, never an empty inbox or
 * thread, and a thread that could not be read is NOT marked read.
 *
 * F-246: the thread view-model's `can_reply` answers "would the reply
 * POST be accepted?" with exactly the reply path's gates, so the composer
 * learns "can't reply" (and the right copy) before the user writes; and
 * member cards carry the viewer's live `can_message` for the
 * /messages/new recipient picker.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(MessagesService::class)]
#[CoversClass(CardViewService::class)]
final class DmThreadReadAndReplyTest extends TestCase
{
    private const VIEWER = 7;
    private const PEER   = 8;
    private const OTHER  = 9;
    private const ROOT   = 500;

    private const ALLOWED = ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];
    private const SHIELD  = ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'messaging_unavailable'];

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/dm-thread-stubs.php';
        \DmWorld::reset();
        \DmWorld::$participants = [self::VIEWER, self::PEER];
    }

    private static function service(): MessagesService
    {
        /** @var MessagesService $svc */
        $svc = (new ReflectionClass(MessagesService::class))->newInstanceWithoutConstructor();
        return $svc;
    }

    /** @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null} */
    private static function canReply(): array
    {
        $m = new ReflectionMethod(MessagesService::class, 'canReply');
        /** @var array{allowed: bool, unlock_hint: string|null, reason_code: string|null} */
        return $m->invoke(null, self::VIEWER, self::ROOT);
    }

    // ── F-300 ───────────────────────────────────────────────────────────

    public function testAFailedInboxReadIsA503NotAnEmptyInbox(): void
    {
        \DmWorld::$readFails = true;

        $out = self::service()->listInbox(self::VIEWER, 1, 20);

        $this->assertSame('bcc_unavailable', $out['error'] ?? null);
        $this->assertArrayNotHasKey('items', $out);
    }

    public function testAFailedThreadReadIsA503AndIsNotMarkedRead(): void
    {
        \DmWorld::$readFails = true;

        $out = self::service()->getThread(self::VIEWER, self::ROOT, 1, 20);

        $this->assertSame('bcc_unavailable', $out['error'] ?? null);
        $this->assertSame([], \DmWorld::$viewedCalls, 'a thread the viewer never saw must not be marked read');
    }

    public function testAGenuinelyEmptyInboxIsStillAnEmptyList(): void
    {
        $out = self::service()->listInbox(self::VIEWER, 1, 20);

        $this->assertSame([], $out['items'] ?? null);
    }

    // ── F-246 can_reply ─────────────────────────────────────────────────

    public function testAnOrdinaryOneOnOneIsRepliable(): void
    {
        $this->assertSame(self::ALLOWED, self::canReply());
    }

    public function testOwnDmsOffIsSenderChatDisabledWithAResolvableHint(): void
    {
        \DmWorld::$chatEnabled[self::VIEWER] = false;

        $perm = self::canReply();

        $this->assertFalse($perm['allowed']);
        $this->assertSame('sender_chat_disabled', $perm['reason_code']);
        $this->assertNotNull($perm['unlock_hint']);
    }

    /** The reply POST answers bcc_fraud_locked here; the read gate must not show an enabled composer. */
    public function testASuspendedSenderIsSenderRestricted(): void
    {
        \DmWorld::$suspended[self::VIEWER] = true;

        $this->assertSame('sender_restricted', self::canReply()['reason_code']);
        $this->assertFalse(self::canReply()['allowed']);
    }

    /** §4.19 shield: peer DMs off and a mutual block are indistinguishable. */
    public function testPeerDmsOffAndAMutualBlockGiveTheIdenticalShieldPayload(): void
    {
        \DmWorld::$chatEnabled[self::PEER] = false;
        $chatOff = self::canReply();

        \DmWorld::reset();
        \DmWorld::$participants = [self::VIEWER, self::PEER];
        \DmWorld::$blocked      = true;
        $blocked = self::canReply();

        $this->assertSame(self::SHIELD, $chatOff);
        $this->assertSame(self::SHIELD, $blocked);
    }

    /**
     * PeepSo's viewer-filtered participant list DROPS a blocked peer, so a
     * gate that resolved the peer from it would find no peer and answer
     * "allowed" — while the reply POST (which reads the unfiltered list)
     * 404s. can_reply must read the list the reply path reads.
     */
    public function testABlockedPeerHiddenFromTheDisplayListStillBlocksTheReply(): void
    {
        \DmWorld::$blocked                       = true;
        \DmWorld::$hiddenFromViewer[self::PEER]  = true;

        $this->assertSame(self::SHIELD, self::canReply());
        $this->assertSame([null], \DmWorld::$participantReads, 'the unfiltered participant list, as the reply path reads it');
    }

    public function testAnUnreadableBlockTableIsUnavailableNotAllowed(): void
    {
        \DmWorld::$blocked = null;

        $this->assertSame(
            ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'unavailable'],
            self::canReply()
        );
    }

    public function testAFriendsOnlyPeerCarriesItsHint(): void
    {
        \DmWorld::$friendsOnly[self::PEER] = true;

        $perm = self::canReply();

        $this->assertSame('friends_only', $perm['reason_code']);
        $this->assertSame('This member only accepts messages from friends.', $perm['unlock_hint']);
    }

    public function testAGroupThreadIsGatedOnlyByTheSender(): void
    {
        \DmWorld::$participants              = [self::VIEWER, self::PEER, self::OTHER];
        \DmWorld::$chatEnabled[self::PEER]   = false; // no 1-on-1 peer gate in a group

        $this->assertSame(self::ALLOWED, self::canReply());
    }

    /**
     * DRIFT GUARD: can_reply and the reply POST must give the same answer.
     * A blocked pair whose peer the display list hides: the POST refuses
     * (the §4.19 404 shield) and can_reply says messaging_unavailable.
     * Both go through ONE method, existingConversationGate().
     */
    public function testTheReplyPostAndCanReplyAgreeOnABlockedPair(): void
    {
        \DmWorld::$blocked                      = true;
        \DmWorld::$hiddenFromViewer[self::PEER] = true;

        $post = self::service()->sendMessage(self::VIEWER, null, self::ROOT, 'hello');

        $this->assertSame('bcc_not_found', $post['error'] ?? null, 'the POST refuses');
        $this->assertSame(self::SHIELD, self::canReply(), 'and can_reply says so up front');

        $src = (string) file_get_contents((string) (new ReflectionClass(MessagesService::class))->getFileName());
        $this->assertSame(
            2,
            substr_count($src, 'self::existingConversationGate('),
            'exactly two callers: the reply POST and can_reply'
        );
    }

    // ── F-246 member-card can_message ───────────────────────────────────

    /** Contract: anonymous member cards are unchanged — never auth_required. */
    public function testAnAnonymousViewerGetsNotApplicable(): void
    {
        $m = new ReflectionMethod(CardViewService::class, 'memberMessagePermission');

        $this->assertSame(
            ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'not_applicable'],
            $m->invoke(null, self::PEER, 0, null)
        );
    }

    public function testAMemberCardPrefersTheBatchedEligibility(): void
    {
        $m = new ReflectionMethod(CardViewService::class, 'memberMessagePermission');
        $friendsOnly = ['allowed' => false, 'unlock_hint' => 'x', 'reason_code' => 'friends_only'];

        $this->assertSame(
            $friendsOnly,
            $m->invoke(null, self::PEER, self::VIEWER, ['message_eligibility' => [self::PEER => $friendsOnly]])
        );
    }

    public function testASingleMemberCardFallsBackToTheLiveGate(): void
    {
        $m = new ReflectionMethod(CardViewService::class, 'memberMessagePermission');

        $this->assertSame(self::ALLOWED, $m->invoke(null, self::PEER, self::VIEWER, null));

        \DmWorld::$chatEnabled[self::PEER] = false;
        $this->assertSame(self::SHIELD, $m->invoke(null, self::PEER, self::VIEWER, null));
    }

    /** So the /messages/new picker can never pin the viewer themself. */
    public function testTheViewersOwnCardIsSelfActionBlocked(): void
    {
        $m = new ReflectionMethod(CardViewService::class, 'memberMessagePermission');

        $this->assertSame('self_action_blocked', $m->invoke(null, self::VIEWER, self::VIEWER, null)['reason_code']);
        $this->assertSame(
            'self_action_blocked',
            MessagesService::canMessageMany(self::VIEWER, [self::VIEWER])[self::VIEWER]['reason_code']
        );
    }

    /** The list paths batch it (one canMessageMany per page), viewer-scoped and empty for anon. */
    public function testThePrefetcherBatchesEligibilityForSignedInViewersOnly(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(MemberCardPrefetcher::class))->getFileName());

        $this->assertMatchesRegularExpression(
            '/\$map\[\'message_eligibility\'\]\s*=\s*\$viewerId > 0 && \$idList !== \[\]\s*\?\s*MessagesService::canMessageMany\(\$viewerId, \$idList\)\s*:\s*\[\];/',
            $src
        );
    }
}
