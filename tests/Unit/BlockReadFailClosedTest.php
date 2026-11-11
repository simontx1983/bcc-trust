<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Trust\Core\Services\MessagesService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

require_once __DIR__ . '/../Stubs/blocked-sender-stubs.php';

/**
 * F-99 — what each caller does when the block table CANNOT BE READ.
 *
 * bcc-core now answers `null` ("I could not tell") from the `*OrUnknown`
 * probes instead of silently reporting "not blocked". The direction to
 * fail is NOT the same at every call site, and this suite is where each
 * choice is pinned:
 *
 *   WITHHOLD   the two #122 preview/body sites — `[]` would show every
 *              sender's text, the exact disclosure the feature prevents.
 *   SUPPRESS   the two notification vetoes — one lost email/ping for
 *              one send, versus notifying someone who blocked this
 *              sender about a message PeepSo will not deliver to them.
 *   REFUSE 503 the two write paths — a RETRYABLE failure, deliberately
 *              NOT the privacy-shaped 404 a real block produces.
 *   UNCHANGED  the two read paths — see below.
 *
 * ⚠⚠ THE LAST ONE IS THE POINT OF THIS FILE, not an afterthought.
 * `canMessage` goes through `evaluatePolicy`, but `canMessageMany`
 * checks blocks through its OWN batch `getMutuallyBlockedSet` and does
 * not. Flipping only the former would make the single and batch reads
 * of the same question disagree, for the same viewer and recipient, on
 * the same error — worse than both being consistently wrong. So the
 * reads are deliberately left byte-identical here and are changed
 * together in the follow-up. Without a test, "deliberately unchanged"
 * is indistinguishable from "forgotten".
 */
#[CoversClass(MessagesService::class)]
final class BlockReadFailClosedTest extends TestCase
{
    private const VIEWER = 7;
    private const SENDER = 9;

    protected function setUp(): void
    {
        PeepSoBlockRepository::reset();
    }

    private static function service(): MessagesService
    {
        /** @var MessagesService $svc */
        $svc = (new ReflectionClass(MessagesService::class))->newInstanceWithoutConstructor();
        return $svc;
    }

    /** @return mixed */
    private static function callPrivate(string $method, array $args)
    {
        $m = new ReflectionMethod(MessagesService::class, $method);
        $m->setAccessible(true);
        return $m->invokeArgs(null, $args);
    }

    // ── SUPPRESS: the two notification vetoes ──────────────────

    public function testTheMailVetoSuppressesWhenTheBlockReadFails(): void
    {
        PeepSoBlockRepository::$readFails = true;

        /** @var \Closure $veto */
        $veto = self::callPrivate('blockedRecipientMailVeto', [self::SENDER]);

        $this->assertFalse(
            $veto(self::VIEWER),
            'An unreadable block table must suppress the email, not send it.'
        );
    }

    public function testThePingVetoSuppressesWhenTheBlockReadFails(): void
    {
        PeepSoBlockRepository::$readFails = true;

        /** @var \Closure $veto */
        $veto = self::callPrivate('blockedRecipientChatPingVeto', [self::SENDER]);

        $this->assertFalse(
            $veto(null, self::VIEWER, 'peepso_should_get_chats'),
            'An unreadable block table must suppress the chat ping.'
        );
    }

    /**
     * ⚠ Anti-vacuity for both vetoes: with `$readFails` set everything
     * returns null, so a veto that suppressed unconditionally would also
     * pass the two cases above. An ORDINARY recipient must still be
     * allowed through when the read SUCCEEDS.
     */
    public function testBothVetoesStillAllowAnOrdinaryRecipientWhenTheReadWorks(): void
    {
        PeepSoBlockRepository::$readFails = false;
        PeepSoBlockRepository::$blocking  = [];

        /** @var \Closure $mail */
        $mail = self::callPrivate('blockedRecipientMailVeto', [self::SENDER]);
        /** @var \Closure $ping */
        $ping = self::callPrivate('blockedRecipientChatPingVeto', [self::SENDER]);

        $this->assertSame(self::VIEWER, $mail(self::VIEWER), 'allow = return the recipient id');
        $this->assertNull($ping(null, self::VIEWER, 'peepso_should_get_chats'), 'allow = return $check');
    }

    // ── REFUSE 503: the write paths, via evaluatePolicy ──────────

    /**
     * ⚠⚠ A RETRYABLE 503, NOT THE PRIVACY-SHAPED 404. A real block
     * answers `bcc_not_found` so it cannot be distinguished from "no
     * such recipient". A DB failure must NOT borrow that shape: it would
     * tell the sender their conversation does not exist, permanently, for
     * a transient fault — and it would be indistinguishable from a real
     * block, misinforming rather than protecting.
     * `MessagesEndpoint` maps `bcc_unavailable => 503`.
     */
    public function testTheWritePathRefusesWithARetryable503(): void
    {
        PeepSoBlockRepository::$readFails = true;

        $gate = self::callPrivate(
            'evaluatePolicy',
            ['normal_dm', self::VIEWER, self::SENDER, 'Recipient not found.', true]
        );

        $this->assertIsArray($gate);
        $this->assertSame('bcc_unavailable', $gate['error']);
        $this->assertSame('block_read_failed', $gate['gate']);
        $this->assertSame('unavailable', $gate['reason_code']);
        $this->assertNull($gate['unlock_hint'], 'unlock_hint must be null — see the frontend omit branch.');
        $this->assertNotSame('bcc_not_found', $gate['error']);
    }

    /** A REAL block still produces the 404 shield, unchanged. */
    public function testARealBlockStillProducesTheNotFoundShield(): void
    {
        PeepSoBlockRepository::$readFails = false;
        PeepSoBlockRepository::$blocking  = [[self::SENDER, self::VIEWER]];

        $gate = self::callPrivate(
            'evaluatePolicy',
            ['normal_dm', self::VIEWER, self::SENDER, 'Recipient not found.', true]
        );

        $this->assertIsArray($gate);
        $this->assertSame('bcc_not_found', $gate['error']);
        $this->assertSame('mutual_block', $gate['gate']);
    }

    // ── ⚠⚠ UNCHANGED: the read paths ───────────────────────

    /**
     * ⚠⚠ The read paths must behave EXACTLY as before. With
     * `$refuseOnUnknownBlock` left at its default, an unreadable block
     * table must NOT short-circuit — `evaluatePolicy` carries on to the
     * other rules, which is today's behaviour.
     *
     * This is what keeps `canMessage` and `canMessageMany` agreeing until
     * they are flipped together. If someone later makes the refusal
     * unconditional, this fails — which is the whole reason it exists.
     */
    public function testTheReadPathIsUnaffectedByAFailedBlockRead(): void
    {
        PeepSoBlockRepository::$readFails = true;

        $gate = self::callPrivate(
            'evaluatePolicy',
            ['normal_dm', self::VIEWER, self::SENDER, 'Recipient not found.']
        );

        $this->assertNotSame(
            'bcc_unavailable',
            is_array($gate) ? ($gate['error'] ?? null) : null,
            'A read must not start refusing on an unreadable block table while the batch path still does not.'
        );
    }

    /**
     * And the default really is false — asserted on the signature, so a
     * future edit that flips the default is caught even if every
     * behavioural case above were deleted.
     */
    public function testRefuseOnUnknownBlockDefaultsToFalse(): void
    {
        $m = new ReflectionMethod(MessagesService::class, 'evaluatePolicy');
        $params = $m->getParameters();
        $last = $params[count($params) - 1];

        $this->assertSame('refuseOnUnknownBlock', $last->getName());
        $this->assertTrue($last->isDefaultValueAvailable());
        $this->assertFalse($last->getDefaultValue(), 'Reads rely on the default being false.');
    }

    /**
     * ⚠⚠ THE TWO WITHHOLD SITES, pinned at the SOURCE rather than
     * behaviourally, and I would rather say that plainly than imply
     * stronger coverage than exists: exercising `listInbox`/`getThread`
     * end to end needs the whole read-model fixture, and the shaping
     * itself is already covered by BlockedSenderShapeTest.
     *
     * What this catches is the one mistake actually available here —
     * `?? []` instead of `?? $authorIds`. Both are "handle the null",
     * both type-check, and `?? []` means SHOW EVERY BLOCKED SENDER'S
     * TEXT on a DB error: the precise disclosure the feature exists to
     * prevent, inverted into the fail-open this PR is closing. The
     * author-id maps are already keyed `[id => true]`, so they ARE the
     * "everyone is blocked" set.
     */
    public function testBothWithholdSitesFallBackToEveryoneBlockedNotToEmpty(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(MessagesService::class))->getFileName()
        );

        $this->assertStringContainsString('?? $lastAuthorIds', $src, 'inbox preview must withhold ALL');
        $this->assertStringContainsString('?? $pageAuthorIds', $src, 'thread body must withhold ALL');
        $this->assertSame(
            2,
            substr_count($src, 'getBlockedSetOrUnknown('),
            'both withhold sites must use the OrUnknown probe'
        );
        $this->assertSame(
            0,
            substr_count($src, 'PeepSoBlockRepository::getBlockedSet('),
            'the fail-open legacy probe must no longer be called from this file'
        );
    }

    /**
     * ⚠ And the two WRITE call sites must actually pass `true`. A
     * correct default plus callers that never opt in would leave the
     * send gate exactly as fail-open as before, with every behavioural
     * test above still green because they call evaluatePolicy directly.
     */
    public function testBothWriteCallSitesOptIn(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(MessagesService::class))->getFileName()
        );

        $this->assertSame(
            2,
            substr_count($src, 'WRITE path: refuse with a retryable 503'),
            'sendToRecipient and sendToExistingConversation must both opt in.'
        );
    }
}
