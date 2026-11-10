<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Repositories\PeepSoMessageRepository;
use BCC\Trust\Core\Services\MessagesService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/../Stubs/blocked-sender-stubs.php';

/**
 * `MessagesService::revealMessageBody` — the tap-to-reveal fetch behind
 * the substituted thread body (#122).
 *
 * The route's whole risk is that it is an id-addressed read of private
 * text, so the tests are weighted towards what it must REFUSE and
 * towards refusing identically:
 *
 *   ⚠ every rejection must be the SAME 404 with the SAME message. If
 *     "not a participant" differed from "no such message", the route
 *     would be an oracle: a stranger could walk message ids and learn
 *     which exist, and which conversations they belong to, from the
 *     status or body alone. The tests compare the refusals to each
 *     other, not merely to an expected code.
 *
 *   ⚠ it must mark nothing read. `getThread` clears the unread badge as
 *     a side effect of serving a page; if a reveal did that, revealing
 *     one message would silently dismiss unread messages the viewer
 *     never saw.
 *
 *   ⚠ it must NOT consult the block. It returns the body whether or not
 *     the sender is blocked — it is the viewer asking for one message
 *     whose metadata they can already see. Gating on the block would
 *     break the instant they unblocked someone, and would leak block
 *     state through a status code.
 *
 * ⚠ These run in SEPARATE PROCESSES. Several other stub files in this
 * directory declare guarded `WP_Post` / `get_post`, and at least one
 * omits `post_content`, which this code reads — in a shared process the
 * winner would be decided by load order. See the stub file's note.
 */
#[CoversClass(MessagesService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class MessageRevealTest extends TestCase
{
    private const VIEWER = 7;
    private const STRANGER = 8;
    private const CONVERSATION = 500;
    private const OTHER_CONVERSATION = 600;
    private const MESSAGE = 900;
    private const FOREIGN_MESSAGE = 901;
    private const REAL_BODY = 'SENTINEL-the-actual-private-message-text';

    protected function setUp(): void
    {
        PeepSoMessageRepository::reset();
        $GLOBALS['__bcc_122_posts'] = [];
    }

    /**
     * `revealMessageBody` touches no instance state — zero `$this->`
     * references — so constructing without the constructor is honest
     * here rather than a way to dodge a dependency.
     */
    private static function service(): MessagesService
    {
        /** @var MessagesService $svc */
        $svc = (new ReflectionClass(MessagesService::class))->newInstanceWithoutConstructor();
        return $svc;
    }

    private static function seedMessage(int $id, string $type = 'peepso-message', string $status = 'publish'): void
    {
        $post               = new \WP_Post();
        $post->ID           = $id;
        $post->post_type    = $type;
        $post->post_status  = $status;
        $post->post_content = self::REAL_BODY;
        $GLOBALS['__bcc_122_posts'][$id] = $post;
    }

    /** The happy path: viewer participates, message belongs to the conversation. */
    private static function seedHappyPath(): void
    {
        PeepSoMessageRepository::$roots = [
            self::CONVERSATION => self::CONVERSATION,
            self::MESSAGE      => self::CONVERSATION,
        ];
        PeepSoMessageRepository::$participants = [
            self::VIEWER . ':' . self::CONVERSATION => true,
        ];
        self::seedMessage(self::MESSAGE);
    }

    // ── the happy path ──────────────────────────────────────────────────

    public function testAParticipantGetsTheRealBody(): void
    {
        self::seedHappyPath();

        $out = self::service()->revealMessageBody(self::VIEWER, self::CONVERSATION, self::MESSAGE);

        $this->assertSame(['id' => self::MESSAGE, 'body' => self::REAL_BODY], $out);
    }

    /** ⚠ It is the explicit reveal, so the block is irrelevant to it. */
    public function testTheBodyIsReturnedWhetherOrNotTheSenderIsBlocked(): void
    {
        self::seedHappyPath();

        // No block state is configured at all, and none is consulted:
        // the service asks only the three conversation questions.
        $out = self::service()->revealMessageBody(self::VIEWER, self::CONVERSATION, self::MESSAGE);

        $this->assertSame(self::REAL_BODY, $out['body']);
        $this->assertSame(
            [
                'findRootConversationId(' . self::CONVERSATION . ')',
                'userIsParticipant(' . self::VIEWER . ',' . self::CONVERSATION . ')',
                'findRootConversationId(' . self::MESSAGE . ')',
            ],
            PeepSoMessageRepository::$calls,
            'The gate order is fixed: resolve the conversation, check participation, then check the message belongs to it.'
        );
    }

    /** ⚠ A reveal must not clear the unread badge. */
    public function testRevealingMarksNothingRead(): void
    {
        self::seedHappyPath();

        self::service()->revealMessageBody(self::VIEWER, self::CONVERSATION, self::MESSAGE);

        $this->assertSame([], PeepSoMessageRepository::$viewedCalls);
    }

    // ── the refusals, and that they are identical ───────────────────────

    public function testANonParticipantIsRefused(): void
    {
        self::seedHappyPath();

        $out = self::service()->revealMessageBody(self::STRANGER, self::CONVERSATION, self::MESSAGE);

        $this->assertSame('bcc_not_found', $out['error']);
    }

    public function testAMessageFromAnotherConversationIsRefused(): void
    {
        self::seedHappyPath();
        PeepSoMessageRepository::$roots[self::FOREIGN_MESSAGE] = self::OTHER_CONVERSATION;
        self::seedMessage(self::FOREIGN_MESSAGE);

        $out = self::service()->revealMessageBody(self::VIEWER, self::CONVERSATION, self::FOREIGN_MESSAGE);

        $this->assertSame('bcc_not_found', $out['error']);
    }

    /**
     * ⚠⚠ THE ORACLE TEST. Four different causes of refusal must produce
     * byte-identical results, so the route cannot be used to probe which
     * message ids exist or which conversations a stranger belongs to.
     * Comparing them to each OTHER is what makes that checkable —
     * asserting each against an expected code would pass even if the
     * messages differed.
     */
    public function testEveryRefusalIsByteIdentical(): void
    {
        self::seedHappyPath();
        PeepSoMessageRepository::$roots[self::FOREIGN_MESSAGE] = self::OTHER_CONVERSATION;
        self::seedMessage(self::FOREIGN_MESSAGE);

        $svc = self::service();

        $notAParticipant   = $svc->revealMessageBody(self::STRANGER, self::CONVERSATION, self::MESSAGE);
        $wrongConversation = $svc->revealMessageBody(self::VIEWER, self::CONVERSATION, self::FOREIGN_MESSAGE);
        $noSuchMessage     = $svc->revealMessageBody(self::VIEWER, self::CONVERSATION, 999999);
        $noSuchConversation = $svc->revealMessageBody(self::VIEWER, 777777, self::MESSAGE);

        $this->assertSame($notAParticipant, $wrongConversation);
        $this->assertSame($notAParticipant, $noSuchMessage);
        $this->assertSame($notAParticipant, $noSuchConversation);
        $this->assertSame(
            ['error' => 'bcc_not_found', 'message' => 'Conversation not found.'],
            $notAParticipant
        );
    }

    public function testAnUnauthenticatedViewerIsRefusedBeforeAnyLookup(): void
    {
        self::seedHappyPath();

        $out = self::service()->revealMessageBody(0, self::CONVERSATION, self::MESSAGE);

        $this->assertSame('bcc_unauthorized', $out['error']);
        $this->assertSame([], PeepSoMessageRepository::$calls, 'An anonymous request must not cost a query.');
    }

    public function testAZeroMessageIdIsRefusedBeforeAnyLookup(): void
    {
        self::seedHappyPath();

        $out = self::service()->revealMessageBody(self::VIEWER, self::CONVERSATION, 0);

        $this->assertSame('bcc_not_found', $out['error']);
        $this->assertSame([], PeepSoMessageRepository::$calls);
    }

    public function testANonMessagePostTypeIsRefused(): void
    {
        self::seedHappyPath();
        self::seedMessage(self::MESSAGE, 'peepso-page');

        $out = self::service()->revealMessageBody(self::VIEWER, self::CONVERSATION, self::MESSAGE);

        $this->assertSame('bcc_not_found', $out['error']);
    }

    public function testAnUnpublishedMessageIsRefused(): void
    {
        self::seedHappyPath();
        self::seedMessage(self::MESSAGE, 'peepso-message', 'trash');

        $out = self::service()->revealMessageBody(self::VIEWER, self::CONVERSATION, self::MESSAGE);

        $this->assertSame('bcc_not_found', $out['error']);
    }

    public function testAnInlineNoticeIsRevealable(): void
    {
        self::seedHappyPath();
        self::seedMessage(self::MESSAGE, 'peepso-message-notic');

        $out = self::service()->revealMessageBody(self::VIEWER, self::CONVERSATION, self::MESSAGE);

        $this->assertSame(self::REAL_BODY, $out['body']);
    }

    /**
     * Anti-vacuity. Every refusal case above would also pass against an
     * implementation that refused EVERYTHING, so the happy path and the
     * refusals are exercised against one service in one case.
     */
    public function testTheRouteCanBothAllowAndRefuse(): void
    {
        self::seedHappyPath();
        $svc = self::service();

        $allowed = $svc->revealMessageBody(self::VIEWER, self::CONVERSATION, self::MESSAGE);
        $refused = $svc->revealMessageBody(self::STRANGER, self::CONVERSATION, self::MESSAGE);

        $this->assertArrayHasKey('body', $allowed);
        $this->assertArrayHasKey('error', $refused);
    }

    /**
     * ⚠⚠ THE LOAD-ORDER GUARD, and the reason this is an assertion
     * rather than a comment.
     *
     * Several other stub files in this directory declare guarded
     * `WP_Post`, and at least one does NOT declare `post_content` —
     * which `revealMessageBody` reads. If one of those wins the
     * `class_exists` race, every reveal test above still PASSES the
     * refusal cases and fails the happy path for a reason that looks
     * nothing like "the wrong stub loaded": `post_content` becomes a
     * PHP 8.2 dynamic property on write and an empty string on read.
     *
     * `#[RunTestsInSeparateProcesses]` is the mitigation, but a mitigation
     * that is silently not in force is worse than none — and I could not
     * tell from run times alone whether it was. So this asserts the
     * PROPERTY the code depends on, which holds whether isolation is
     * working, is switched off, or is replaced by something else later.
     */
    public function testTheWpPostStubInPlayDeclaresPostContent(): void
    {
        $this->assertTrue(class_exists('WP_Post'), 'No WP_Post stub is loaded at all.');

        $rc = new ReflectionClass('WP_Post');
        $this->assertTrue(
            $rc->hasProperty('post_content'),
            'The WP_Post stub in play has no post_content property — another stub file won the '
            . 'class_exists race, so these reveal tests are not exercising what they appear to.'
        );

        // And the one that would read as an empty body rather than a failure.
        $post = new \WP_Post();
        $post->post_content = 'round-trip';
        $this->assertSame('round-trip', $post->post_content);
    }

    /**
     * The route and its headers, read from source: the contract requires
     * `Cache-Control: private, no-store` (from `ok()`) plus
     * `Vary: Authorization, Cookie`, because the same message id answers
     * a body to a participant and a 404 to everyone else.
     */
    public function testTheRouteIsRegisteredWithBothCacheHeaders(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/REST/MessagesEndpoint.php'
        );
        $this->assertNotSame('', $src, 'Could not read MessagesEndpoint.php — the assertions below would be vacuous.');

        $this->assertStringContainsString(
            "'/me/conversations/(?P<id>\\d+)/messages/(?P<message_id>\\d+)/reveal'",
            $src
        );
        $this->assertStringContainsString("'callback'            => [\$instance, 'revealMessage'],", $src);
        // Set on BOTH the success and the refusal, so a 404 is no more
        // cacheable than a body.
        $this->assertSame(2, substr_count($src, "\$resp->header('Vary', 'Authorization, Cookie');"));
    }
}
