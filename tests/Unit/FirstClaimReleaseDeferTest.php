<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Core\Repositories\PeepSoBlockRepository;
use BCC\Trust\Core\Services\MessagesService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

require_once __DIR__ . '/../Stubs/blocked-sender-stubs.php';

/**
 * F-99 / M1 — the first-claim backlog release must DEFER, not guess, when
 * the block table cannot be read.
 *
 * ⚠⚠ WHY THIS IS THE WORST PLACE TO FAIL OPEN *OR* CLOSED.
 * `MessagesService::firstClaimReleaseBlocked()` used to return a plain
 * `bool`, which collapsed "could not read the block table" into
 * **release** — so a transient DB fault during the 5-minute queue sweep
 * would DELIVER a queued first-claim message to an operator who had
 * blocked the sender.
 *
 * Failing the other way is not a fix: the worker's suppression is
 * TERMINAL. `ValidatorMsgQueueWorker::resolveSuppression`'s non-null
 * result reaches `markSuppressed()`, which the file itself describes as
 * "a terminal `suppressed` state — never delivered, never retried". So
 * treating unknown as "blocked" would let one blip PERMANENTLY DESTROY a
 * legitimate message, labelled `mutual_block`.
 *
 * Hence the third answer. Unknown now defers through the worker's
 * ordinary transient-failure path: bounded exponential backoff, and at
 * the attempt cap a loud `failed_terminal` carrying a metric, an audit
 * row and `wp bcc-trust vmq status` visibility.
 *
 * ⚠ The defer burns the SAME 8-attempt budget as a delivery failure:
 * 300+600+1200+2400+4800+9600+19200 = 38,100s ≈ **10.6 hours**. A block
 * table unreadable for longer than that turns a legitimate message into
 * `failed_terminal`. Accepted because it is loud, not silent — and
 * stated here so it is a known property rather than a surprise.
 */
#[CoversClass(MessagesService::class)]
final class FirstClaimReleaseDeferTest extends TestCase
{
    private const SENDER   = 9;
    private const OPERATOR = 7;

    protected function setUp(): void
    {
        PeepSoBlockRepository::reset();
    }

    // ── the tri-state ───────────────────────────────────────────────

    /** No block, readable table → RELEASE. */
    public function testADefiniteNoBlockReleases(): void
    {
        PeepSoBlockRepository::$readFails = false;
        PeepSoBlockRepository::$blocking  = [];

        $this->assertFalse(
            MessagesService::firstClaimReleaseBlocked(self::SENDER, self::OPERATOR)
        );
    }

    /** A real block → SUPPRESS, in either direction (the probe is either-way). */
    public function testADefiniteBlockSuppresses(): void
    {
        PeepSoBlockRepository::$readFails = false;
        PeepSoBlockRepository::$blocking  = [[self::OPERATOR, self::SENDER]];

        $this->assertTrue(
            MessagesService::firstClaimReleaseBlocked(self::SENDER, self::OPERATOR)
        );

        // And the other direction, since the underlying probe is
        // either-direction despite its name.
        PeepSoBlockRepository::$blocking = [[self::SENDER, self::OPERATOR]];
        $this->assertTrue(
            MessagesService::firstClaimReleaseBlocked(self::SENDER, self::OPERATOR)
        );
    }

    /**
     * ⚠⚠ THE WHOLE POINT: an unreadable block table is NULL — neither
     * release nor suppress. `false` here would deliver to a blocker;
     * `true` would permanently destroy a good message.
     */
    public function testAnUnreadableBlockTableIsNullNotAGuess(): void
    {
        PeepSoBlockRepository::$readFails = true;

        $out = MessagesService::firstClaimReleaseBlocked(self::SENDER, self::OPERATOR);

        $this->assertNull($out);
        $this->assertNotFalse($out, 'false would RELEASE to a possible blocker');
        $this->assertNotTrue($out, 'true would TERMINALLY suppress a possibly-fine message');
    }

    /**
     * ⚠⚠ A DEFER MUST NOT POISON THE ROW. After any number of UNKNOWN
     * sweeps, a sweep that CAN read the table must release normally.
     *
     * This is what separates "retry" from "slow suppression": if the
     * answer were memoised, cached, or if an earlier unknown left state
     * behind, a message would be deferred to `failed_terminal` even
     * though the table recovered — which is exactly the outcome the
     * defer exists to avoid.
     */
    public function testADefiniteFalseAfterEarlierUnknownsStillReleases(): void
    {
        // Three failed sweeps.
        PeepSoBlockRepository::$readFails = true;
        for ($i = 0; $i < 3; $i++) {
            $this->assertNull(
                MessagesService::firstClaimReleaseBlocked(self::SENDER, self::OPERATOR),
                "sweep {$i} must defer"
            );
        }

        // The table recovers; nobody is blocked.
        PeepSoBlockRepository::$readFails = false;
        PeepSoBlockRepository::$blocking  = [];

        $this->assertFalse(
            MessagesService::firstClaimReleaseBlocked(self::SENDER, self::OPERATOR),
            'A recovered read must RELEASE — an earlier defer must leave no state behind.'
        );
    }

    /** And the mirror: unknowns then a real block must still suppress. */
    public function testADefiniteBlockAfterEarlierUnknownsStillSuppresses(): void
    {
        PeepSoBlockRepository::$readFails = true;
        $this->assertNull(MessagesService::firstClaimReleaseBlocked(self::SENDER, self::OPERATOR));

        PeepSoBlockRepository::$readFails = false;
        PeepSoBlockRepository::$blocking  = [[self::OPERATOR, self::SENDER]];

        $this->assertTrue(MessagesService::firstClaimReleaseBlocked(self::SENDER, self::OPERATOR));
    }

    /**
     * ⚠ The return type must be NULLABLE. A future edit narrowing it back
     * to `bool` would silently restore the fail-open — PHP would coerce
     * the null to false at the boundary and the worker would release.
     */
    public function testTheReturnTypeIsNullable(): void
    {
        $t = (new ReflectionMethod(MessagesService::class, 'firstClaimReleaseBlocked'))->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $t);
        $this->assertSame('bool', $t->getName());
        $this->assertTrue($t->allowsNull(), 'null is the DEFER answer; a plain bool loses it.');
    }

    // ── the worker wiring ───────────────────────────────────────────

    /**
     * ⚠⚠ THE ORDER OF SUPPRESSION REASONS IS PRESERVED, and that is why
     * the old single `resolveSuppression()` was SPLIT rather than having
     * a block pre-check hoisted above it.
     *
     * Before this change the order was: sender checks → block → body. A
     * pre-check placed ahead of the sender checks would have made a row
     * that is BOTH from a deleted sender AND blocked record
     * `block_read_failed`/`mutual_block` instead of `sender_deleted` —
     * silently changing the audit trail for such rows. No test pinned
     * that order, so nothing would have caught it.
     */
    public function testTheWorkerEvaluatesSenderThenBlockThenBody(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(\BCC\Trust\Onchain\Workers\ValidatorMsgQueueWorker::class))
                ->getFileName()
        );

        $sender = strpos($src, 'resolveSenderSuppression($senderId, $operatorId)');
        $block  = strpos($src, 'MessagesService::firstClaimReleaseBlocked($senderId, $operatorId)');
        $body   = strpos($src, 'resolveBodySuppression((string) $row->body)');

        $this->assertIsInt($sender, 'the sender-side check must exist');
        $this->assertIsInt($block, 'the block check must exist');
        $this->assertIsInt($body, 'the body check must exist');

        $this->assertLessThan($block, $sender, 'sender reasons must still win over the block');
        $this->assertLessThan($body, $block, 'the block must still be decided before body validity');
    }

    /**
     * The defer routes through the worker's EXISTING transient-failure
     * path rather than a new mechanism, so it inherits the bounded
     * backoff and the loud `failed_terminal` at the cap.
     */
    public function testUnknownDefersThroughHandleFailureAndNeverSuppresses(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(\BCC\Trust\Onchain\Workers\ValidatorMsgQueueWorker::class))
                ->getFileName()
        );

        $this->assertStringContainsString(
            "self::handleFailure(\$rowId, \$leaseToken, \$attempts, 'block_read_failed')",
            $src,
            'unknown must defer through the ordinary retry path'
        );
        $this->assertSame(
            0,
            substr_count($src, "markSuppressed(\$rowId, \$leaseToken, 'block_read_failed')"),
            'an unreadable block table must NEVER produce a terminal suppression'
        );
    }

    /**
     * ⚠ `handleFailure` takes the REASON, not an exception. F-99's
     * `block_read_failed` has no `\Throwable` behind it, and fabricating
     * one purely to smuggle a reason code through the signature would be
     * the hacky version of the same call.
     */
    public function testHandleFailureTakesAReasonStringNotAnException(): void
    {
        $m = new ReflectionMethod(
            \BCC\Trust\Onchain\Workers\ValidatorMsgQueueWorker::class,
            'handleFailure'
        );
        $params = $m->getParameters();
        $last   = $params[count($params) - 1];

        $this->assertSame('reason', $last->getName());
        $t = $last->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $t);
        $this->assertSame('string', $t->getName());
    }
}
