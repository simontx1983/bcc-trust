<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Trust\Core\Services\MessagesService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/../Stubs/blocked-sender-stubs.php';

/**
 * #122: no email to a recipient who has blocked the sender.
 *
 * The veto is a closure handed to PeepSo's `peepso_mailqueue_allowed`
 * filter, whose consumer is `if (!apply_filters(...)) return FALSE;`.
 * Three things about that shape are easy to get wrong and invisible from
 * the call site:
 *
 *   1. the filtered VALUE is the recipient id and the sender is not
 *      passed at all, so the sender must be closed over. A callback that
 *      tried to read the sender from the hook would compare against
 *      nothing and silently never fire;
 *   2. the "allow" return must be the ORIGINAL value, not `true`. The
 *      pre-existing precedent in ValidatorMsgQueueWorker returns a bare
 *      `false` because it vetoes every recipient of that send; copying
 *      that shape here would replace each allowed recipient's user id
 *      with `true` for any filter later in the chain;
 *   3. the direction. The veto fires when THE RECIPIENT has blocked THE
 *      SENDER — not the reverse. A sender who has blocked the recipient
 *      still gets their own message delivered and notified.
 *
 * ⚠ What this test can honestly prove is which question the veto asks
 * and what it does with the answer. The block predicate itself is
 * stubbed (bcc-trust's unit jobs do not autoload bcc-core); the real SQL
 * is covered by bcc-core's capturing-$wpdb tests and by the integration
 * suite against a real engine.
 */
#[CoversClass(MessagesService::class)]
final class BlockedRecipientMailVetoTest extends TestCase
{
    protected function setUp(): void
    {
        PeepSoBlockRepository::reset();
    }

    private static function veto(int $senderId): \Closure
    {
        $m = (new ReflectionClass(MessagesService::class))->getMethod('blockedRecipientMailVeto');
        $m->setAccessible(true);
        /** @var \Closure $c */
        $c = $m->invoke(null, $senderId);
        return $c;
    }

    public function testARecipientWhoBlockedTheSenderIsVetoed(): void
    {
        PeepSoBlockRepository::$blocking = [[20, 10]]; // recipient 20 blocks sender 10

        $veto = self::veto(10);

        $this->assertFalse($veto(20), 'A recipient who blocked the sender must get no email.');
    }

    /** ⚠ Point 2: the allow path returns the ORIGINAL value, never `true`. */
    public function testAnOrdinaryRecipientGetsTheOriginalValueBackNotTrue(): void
    {
        PeepSoBlockRepository::$blocking = [[20, 10]];

        $out = self::veto(10)(21);

        $this->assertSame(21, $out);
        $this->assertNotSame(true, $out, 'Returning true would overwrite the recipient id for later filters.');
    }

    /** ⚠ Point 3: the reverse block must NOT suppress the email. */
    public function testASenderWhoBlockedTheRecipientDoesNotSuppressTheirOwnMessage(): void
    {
        PeepSoBlockRepository::$blocking = [[10, 20]]; // sender 10 blocks recipient 20

        $this->assertSame(20, self::veto(10)(20), 'Only the recipient having blocked the sender suppresses the email.');
    }

    /** ⚠ Point 1, made checkable: the arguments, in order. */
    public function testTheProbeAsksTheOneDirectionalQuestionRecipientFirst(): void
    {
        self::veto(10)(20);

        $this->assertSame(
            [[20, 10]],
            PeepSoBlockRepository::$isBlockingCalls,
            'isBlocking($recipientId, $senderId) — the recipient is the blocker.'
        );
    }

    public function testAZeroRecipientIsAllowedThroughUnprobed(): void
    {
        $this->assertSame(0, self::veto(10)(0));
        $this->assertSame([], PeepSoBlockRepository::$isBlockingCalls, 'A non-user recipient must not cost a query.');
    }

    public function testEachRecipientOfOneSendIsJudgedIndependently(): void
    {
        PeepSoBlockRepository::$blocking = [[20, 10], [22, 10]];

        $veto = self::veto(10);

        // A group send fans out per recipient inside PeepSo's writer.
        $this->assertFalse($veto(20));
        $this->assertSame(21, $veto(21));
        $this->assertFalse($veto(22));
        $this->assertCount(3, PeepSoBlockRepository::$isBlockingCalls);
    }

    /**
     * Anti-vacuity: with no pairs configured nothing is vetoed, so a
     * suite of only "allowed" cases would pass against a veto that can
     * never fire. Both states are exercised on one closure.
     */
    public function testTheVetoCanActuallyFire(): void
    {
        $veto = self::veto(10);
        $this->assertSame(20, $veto(20), 'with no block configured: allowed');

        PeepSoBlockRepository::$blocking = [[20, 10]];
        $this->assertFalse($veto(20), 'with the block configured: vetoed');
    }

    /**
     * The scoping discipline copied from ValidatorMsgQueueWorker: the
     * filter is added around the write and removed in a `finally`, never
     * registered globally — because the hook cannot tell the callback
     * which send it belongs to.
     */
    public function testBothSendPathsAddAndRemoveTheFilterAroundTheWrite(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Services/MessagesService.php'
        );
        $this->assertNotSame('', $src, 'Could not read MessagesService.php — the assertions below would be vacuous.');

        $this->assertSame(
            2,
            substr_count($src, "add_filter('peepso_mailqueue_allowed', \$veto, 99);"),
            'Both send paths must register the veto.'
        );
        $this->assertSame(
            2,
            substr_count($src, "remove_filter('peepso_mailqueue_allowed', \$veto, 99);"),
            'Every registration must be paired with a removal.'
        );
        $this->assertSame(
            2,
            substr_count($src, '} finally {'),
            'The removal must sit in a finally, so a throwing write cannot leave the veto installed.'
        );
    }
}
