<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\MessagesService;
use BCC\Trust\Core\Services\MessagingPolicy;
use BCC\Trust\Core\Support\PeepSoChatPrefs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * H6: every DM gate refuses when a chat setting cannot be READ, and none of
 * them writes a setting.
 *
 * The gates used to read through PeepSoChatModel::chat_enabled(), which
 * writes a default when its read is empty.
 *
 * This file drives MessagesService with the REAL PeepSoChatPrefs and
 * bcc-core UserMetaRepository over the $wpdb double, so the stale-rows
 * (pre-flush) case reaches the gates through the real read. Everything
 * else is the DmWorld stubs; their PeepSoChatModel THROWS if called.
 *
 * What a failed read answers, by surface:
 *   - the sends (new conversation, reply): a retryable `bcc_unavailable`
 *     503, the same as an unreadable block table;
 *   - can_message / can_reply / the batch: `unavailable` (omit the button);
 *   - the first-claim release: nothing is read (its preference gates are
 *     bypassed), so nothing can fail.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(MessagesService::class)]
final class ChatPrefsReadFailClosedTest extends TestCase
{
    private const VIEWER = 7;
    private const PEER   = 8;
    private const OTHER  = 9;
    private const ROOT   = 500;

    private const UNAVAILABLE = ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'unavailable'];
    private const ALLOWED     = ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null];
    private const SHIELD      = ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'messaging_unavailable'];

    private \TokenStateWpdb $db;

    protected function setUp(): void
    {
        parent::setUp();
        // Order matters: the real reader first, so dm-thread-stubs.php's
        // DmWorld stand-in for it steps aside.
        require_once __DIR__ . '/../Stubs/token-state-wpdb-stubs.php';
        class_exists(PeepSoChatPrefs::class);
        require_once __DIR__ . '/../Stubs/dm-thread-stubs.php';
        \DmWorld::reset();
        \DmWorld::$participants = [self::VIEWER, self::PEER];

        $this->db = new \TokenStateWpdb();
        $GLOBALS['wpdb'] = $this->db;
    }

    private static function service(): MessagesService
    {
        /** @var MessagesService $svc */
        $svc = (new ReflectionClass(MessagesService::class))->newInstanceWithoutConstructor();
        return $svc;
    }

    /**
     * @param list<mixed> $args
     * @return mixed
     */
    private static function callPrivate(string $method, array $args)
    {
        $m = new ReflectionMethod(MessagesService::class, $method);
        return $m->invoke(null, ...$args);
    }

    /** @return array<string, mixed>|null */
    private static function policy(string $context, bool $refuseOnUnknownBlock = true): ?array
    {
        /** @var array<string, mixed>|null */
        return self::callPrivate('evaluatePolicy', [$context, self::VIEWER, self::PEER, 'Recipient not found.', $refuseOnUnknownBlock]);
    }

    /** The member's real choice: DMs OFF. */
    private function peerTurnedDmsOff(): void
    {
        $this->db->put(self::PEER, PeepSoChatPrefs::ENABLED_KEY, '0');
    }

    /**
     * ⚠⚠ The pre-flush trap at the gate: the stored choice is OFF, the
     * query bails before flush(), and last_result still holds an earlier
     * query's rows saying ON.
     */
    private function staleRowsSayOn(int $userId): void
    {
        $this->db->last_result = [(object) [
            'user_id'    => (string) $userId,
            'meta_key'   => PeepSoChatPrefs::ENABLED_KEY,
            'meta_value' => '1',
        ]];
        $this->db->mode = 'false_early';
    }

    // ── The recipient gate (evaluatePolicy) ─────────────────────────────

    public function testAnUnreadableRecipientSettingIsARetryable503(): void
    {
        $this->peerTurnedDmsOff();
        $this->db->mode = 'error';

        $gate = self::policy(MessagingPolicy::NORMAL_DM);

        self::assertIsArray($gate);
        self::assertSame('bcc_unavailable', $gate['error']);
        self::assertSame('chat_prefs_read_failed', $gate['gate']);
        self::assertSame('unavailable', $gate['reason_code']);
        self::assertNull($gate['unlock_hint']);
    }

    public function testStaleRowsSayingOnAreUnavailableNotOn(): void
    {
        $this->peerTurnedDmsOff();
        $this->staleRowsSayOn(self::PEER);

        $gate = self::policy(MessagingPolicy::NORMAL_DM);

        self::assertIsArray($gate, 'must not admit the message');
        self::assertSame('bcc_unavailable', $gate['error']);
    }

    /** Unlike an unknown BLOCK, an unknown setting refuses for every caller. */
    public function testItRefusesEvenForACallerThatToleratesAnUnknownBlock(): void
    {
        $this->db->mode = 'error';

        $gate = self::policy(MessagingPolicy::NORMAL_DM, false);

        self::assertSame('bcc_unavailable', $gate['error'] ?? null);
    }

    public function testAStoredOffIsStillTheShieldedForbidden(): void
    {
        $this->peerTurnedDmsOff();

        $gate = self::policy(MessagingPolicy::NORMAL_DM);

        self::assertSame('bcc_forbidden', $gate['error'] ?? null);
        self::assertSame('recipient_chat_disabled', $gate['gate'] ?? null);
        self::assertSame('messaging_unavailable', $gate['reason_code'] ?? null);
    }

    public function testNoRowIsEnabled(): void
    {
        self::assertNull(self::policy(MessagingPolicy::NORMAL_DM));
    }

    /** The release bypasses the preference gates, so it reads nothing. */
    public function testTheFirstClaimReleaseReadsNoSettings(): void
    {
        $this->db->mode = 'error';

        self::assertNull(self::policy(MessagingPolicy::VALIDATOR_FIRST_CLAIM_RELEASE));
        self::assertSame([], $this->db->queries);
    }

    // ── The sends ───────────────────────────────────────────────────────

    /**
     * Only the SENDER's read fails; the recipient's would succeed. The send
     * must stop at the sender gate, before the recipient is even read (the
     * recipient gate would answer the same 503 and hide a sender gate that
     * let the send through).
     */
    public function testAnUnreadableSenderSettingRefusesANewConversation(): void
    {
        $this->db->put(self::VIEWER, PeepSoChatPrefs::ENABLED_KEY, '0');
        $this->db->last_result = [(object) [
            'user_id'    => (string) self::VIEWER,
            'meta_key'   => PeepSoChatPrefs::ENABLED_KEY,
            'meta_value' => '1',
        ]];
        $this->db->modes = [0 => 'false_early'];

        $out = self::service()->sendMessage(self::VIEWER, self::PEER, null, 'hello');

        self::assertSame('bcc_unavailable', $out['error'] ?? null);
        self::assertSame('Messaging is temporarily unavailable. Please try again.', $out['message'] ?? null);
        self::assertCount(1, $this->db->queries, 'refused at the sender gate, before the recipient is read');
    }

    public function testASendersStoredOffIsStillForbidden(): void
    {
        $this->db->put(self::VIEWER, PeepSoChatPrefs::ENABLED_KEY, '0');

        $out = self::service()->sendMessage(self::VIEWER, self::PEER, null, 'hello');

        self::assertSame('bcc_forbidden', $out['error'] ?? null);
        self::assertSame('Messaging is disabled in your account settings.', $out['message'] ?? null);
    }

    /** The reply re-checks the peer; the sender's read works, the peer's does not. */
    public function testAReplyRefusesWhenThePeersSettingCannotBeRead(): void
    {
        $this->peerTurnedDmsOff();
        $this->db->modes = [1 => 'error'];

        $out = self::service()->sendMessage(self::VIEWER, null, self::ROOT, 'hello');

        self::assertSame('bcc_unavailable', $out['error'] ?? null);
        self::assertCount(2, $this->db->queries, 'sender, then peer');
    }

    // ── The read paths ──────────────────────────────────────────────────

    public function testCanMessageSaysUnavailable(): void
    {
        $this->db->modes = [1 => 'error'];

        self::assertSame(self::UNAVAILABLE, MessagesService::canMessage(self::VIEWER, self::PEER));
    }

    public function testCanReplySaysUnavailable(): void
    {
        $this->db->modes = [1 => 'error'];

        self::assertSame(self::UNAVAILABLE, self::callPrivate('canReply', [self::VIEWER, self::ROOT]));
    }

    public function testTheViewersOwnUnreadableSettingIsUnavailableNotDisabled(): void
    {
        $this->db->mode = 'error';

        self::assertSame(self::UNAVAILABLE, MessagesService::senderEligibility(self::VIEWER));
        self::assertSame(
            [self::PEER => self::UNAVAILABLE, self::OTHER => self::UNAVAILABLE],
            MessagesService::canMessageMany(self::VIEWER, [self::PEER, self::OTHER])
        );
    }

    public function testTheBatchSaysUnavailableForEveryoneWhenTheRecipientsCannotBeRead(): void
    {
        $this->db->modes = [1 => 'error'];

        self::assertSame(
            [self::PEER => self::UNAVAILABLE, self::OTHER => self::UNAVAILABLE],
            MessagesService::canMessageMany(self::VIEWER, [self::PEER, self::OTHER])
        );
    }

    /** One read for the viewer, ONE for every recipient: flat in N. */
    public function testTheBatchReadsEveryRecipientInOneQuery(): void
    {
        $this->peerTurnedDmsOff();

        self::assertSame(
            [self::PEER => self::SHIELD, self::OTHER => self::ALLOWED],
            MessagesService::canMessageMany(self::VIEWER, [self::PEER, self::OTHER])
        );
        self::assertCount(2, $this->db->queries);
    }

    /** can_message and the batch answer the same pair the same way. */
    public function testTheSingleAndBatchReadsAgreeOnAFailedRead(): void
    {
        $this->db->modes = [1 => 'error'];
        $single = MessagesService::canMessage(self::VIEWER, self::PEER);

        // The same fresh request for the batch: the viewer's read (query 0)
        // works, the recipients' (query 1) fails. Forget the viewer's
        // trusted answer remembered by the first call, so the batch reads
        // in the same order.
        \BCC\Core\Repositories\UserMetaRepository::forget(self::VIEWER);
        $this->db->queries = [];
        $this->db->modes   = [1 => 'error'];
        $batch = MessagesService::canMessageMany(self::VIEWER, [self::PEER]);

        self::assertSame($single, $batch[self::PEER]);
    }

    /** Nothing on any path above may write a setting. */
    public function testNoGateEverWritesASetting(): void
    {
        $this->peerTurnedDmsOff();
        $this->staleRowsSayOn(self::PEER);

        self::policy(MessagingPolicy::NORMAL_DM);
        $this->db->mode = 'ok';
        MessagesService::canMessageMany(self::VIEWER, [self::PEER, self::OTHER]);
        self::service()->sendMessage(self::VIEWER, self::PEER, null, 'hello');

        self::assertCount(1, $this->db->rows, 'still only the member\'s own "0"');
        self::assertSame('0', $this->db->rows[0]['meta_value']);
        foreach ($this->db->queries as $sql) {
            self::assertStringStartsWith('SELECT', ltrim($sql));
        }
    }
}
