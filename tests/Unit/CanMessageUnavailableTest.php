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
 * F-99 PR 2b — the can-message READ paths say "unavailable" instead of
 * promising a Message button that will 503 on submit.
 *
 * #400 made the WRITE paths refuse an unreadable block table with a
 * retryable `bcc_unavailable` (503) and deliberately left the READS
 * alone, because `canMessage()` goes through `evaluatePolicy()` while
 * `canMessageMany()` checks blocks through its own batch call. Flipping
 * one without the other would have made two reads of the same question
 * disagree. This PR flips them together.
 *
 * ── ⚠⚠ WHAT THIS FILE DOES *NOT* COVER, AND WHY ────────────────────
 *
 * `canMessage()` and `canMessageMany()` are NOT exercised end to end
 * here. Both run `senderMessagingGate()` — and `canMessage()` also
 * `userExists()` — BEFORE reaching the block check, and that gate needs
 * `PeepSoChatModel::chat_enabled()`, `Permissions::is_not_suspended()`
 * (which calls `user_can()` and an HMAC'd cache), `get_userdata()` and
 * `cache_users()`.
 *
 * Measured: `PeepSoChatModel` is stubbed NOWHERE in this suite, and
 * `get_userdata` / `cache_users` / `Permissions` are declared across
 * four-plus guarded stub files. Building that stack would risk exactly
 * the guarded-stub load-order race this project has already been bitten
 * by, and neither method has ANY existing unit or integration coverage
 * to extend — so there is no established seam, and creating one is a
 * piece of work in its own right rather than part of this change.
 *
 * So the chain is verified LINK BY LINK at seams that do exist, with
 * the composition pinned at source:
 *
 *   1. `evaluatePolicy(..., refuseOnUnknownBlock: true)` returns
 *      `reason_code 'unavailable'` + `unlock_hint null`
 *            — behavioural, in BlockReadFailClosedTest (#400).
 *   2. `eligibilityDeny()` turns those values into the wire payload
 *            — behavioural, below.
 *   3. both call sites feed step 2 from step 1's values
 *            — pinned at source, below.
 *
 * That is weaker than an end-to-end assertion and is stated rather than
 * dressed up. An end-to-end test belongs in the integration suite, with
 * a real WordPress, and is filed as follow-up work.
 */
#[CoversClass(MessagesService::class)]
final class CanMessageUnavailableTest extends TestCase
{
    /** The shape both read paths must put on the wire. */
    private const UNAVAILABLE = [
        'allowed'     => false,
        'unlock_hint' => null,
        'reason_code' => 'unavailable',
    ];

    protected function setUp(): void
    {
        PeepSoBlockRepository::reset();
    }

    private static function source(): string
    {
        return (string) file_get_contents(
            (string) (new ReflectionClass(MessagesService::class))->getFileName()
        );
    }

    // ── link 2: the payload ─────────────────────────────────────────

    /**
     * ⚠⚠ THE PAIRING IS LOAD-BEARING, NOT COSMETIC.
     * `reason_code: 'unavailable'` must ALWAYS carry
     * `unlock_hint: null`. Frontend #226 added an explicit omit branch
     * for the code, but the member card's `MessagePill` hides only when
     * `unlock_hint` is null or empty — so `'unavailable'` WITH a hint
     * string renders a DISABLED PILL on any frontend deployed before
     * #226, which is the outcome the whole change exists to avoid.
     */
    public function testTheUnavailablePayloadIsDeniedWithANullHint(): void
    {
        $m = new ReflectionMethod(MessagesService::class, 'eligibilityDeny');
        $m->setAccessible(true);

        $this->assertSame(self::UNAVAILABLE, $m->invoke(null, null, 'unavailable'));
    }

    /**
     * ⚠ And a hint would survive if anyone passed one — the pairing is
     * the CALLER's responsibility, which is why the source pins below
     * assert `null` at both sites rather than trusting this helper.
     */
    public function testEligibilityDenyWouldHappilyCarryAHintIfGivenOne(): void
    {
        $m = new ReflectionMethod(MessagesService::class, 'eligibilityDeny');
        $m->setAccessible(true);

        $this->assertSame('do a thing', $m->invoke(null, 'do a thing', 'unavailable')['unlock_hint']);
    }

    // ── link 3: both call sites, pinned at source ───────────────────

    /** `canMessage()` opts into the refusal and forwards the gate's own values. */
    public function testCanMessageOptsInAndForwardsTheGatesValues(): void
    {
        $src = self::source();

        $this->assertStringContainsString('refuseOnUnknownBlock: true', $src);
        $this->assertStringContainsString(
            "return self::eligibilityDeny(\$gate['unlock_hint'], \$gate['reason_code']);",
            $src,
            "canMessage must forward the gate's own values, not re-derive them"
        );
    }

    /**
     * ⚠⚠ THE BATCH MUST PRODUCE THE SAME PAYLOAD — this is the property
     * that splitting #400 from this PR exists to protect. The profile
     * surface and the card lists answer the same question through
     * different code, and a viewer seeing "unavailable" on one and a
     * live Message button on the other is worse than either being wrong
     * consistently.
     */
    public function testTheBatchEmitsTheIdenticalUnavailablePayload(): void
    {
        $this->assertStringContainsString(
            "array_fill_keys(\$ids, self::eligibilityDeny(null, 'unavailable'))",
            self::source(),
            'the batch must deny with a NULL hint and the same code as the single path'
        );
    }

    /**
     * ⚠ And the batch must still be ONE query. "Fixing" it by calling
     * the per-pair probe in a loop reintroduces the N+1 the batch exists
     * to avoid — card lists resolve up to ~50 operators per request.
     */
    public function testTheBatchStillUsesOneBatchQueryNotAPerCandidateLoop(): void
    {
        $src = self::source();

        $this->assertSame(
            1,
            substr_count($src, 'getMutuallyBlockedSetOrUnknown('),
            'exactly one batch call — not one per candidate'
        );
        $this->assertSame(
            0,
            substr_count($src, 'PeepSoBlockRepository::getMutuallyBlockedSet('),
            'the fail-open batch probe must no longer be called'
        );
    }

    // ── the profile view-model ──────────────────────────────────────

    /**
     * ⭐ `UserViewService` was the OUTLIER, not the convention:
     * `CardViewService` emits the whole `canMessage()` return for its own
     * `can_message`, so the card surfaces always carried three keys
     * while the profile carried two. Without this, `/u/[handle]` cannot
     * see `'unavailable'` at all and the frontend's omit branch would
     * appear to work only by accident, via the `unlock_hint: null`
     * pairing — a safeguard, not the signal.
     */
    public function testTheProfileViewModelForwardsReasonCode(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(\BCC\Trust\Core\Services\UserViewService::class))
                ->getFileName()
        );

        $this->assertStringContainsString("'reason_code' => \$canMessagePerm['reason_code'],", $src);
    }

    // ── link 1 is behavioural, and still holds here ─────────────────

    /**
     * ⚠ Anti-vacuity for the source pins: they would all pass against a
     * gate that never actually produced `'unavailable'`. This re-asserts
     * link 1 behaviourally in this file, so the chain cannot be green
     * end to end while its first link is broken.
     */
    public function testTheGateReallyProducesUnavailableWithANullHint(): void
    {
        PeepSoBlockRepository::$readFails = true;

        $m = new ReflectionMethod(MessagesService::class, 'evaluatePolicy');
        $m->setAccessible(true);
        /** @var array<string, mixed>|null $gate */
        $gate = $m->invoke(null, 'normal_dm', 7, 9, 'Recipient not found.', true);

        $this->assertIsArray($gate);
        $this->assertSame('unavailable', $gate['reason_code']);
        $this->assertNull($gate['unlock_hint']);
    }

    /**
     * ⚠ And with the read SUCCEEDING, a real block still answers the
     * §4.19 shield code — `'unavailable'` must not swallow it, or a
     * deliberate shield becomes a distinguishable signal.
     */
    public function testARealBlockStillAnswersTheShieldCodeNotUnavailable(): void
    {
        PeepSoBlockRepository::$readFails = false;
        PeepSoBlockRepository::$blocking  = [[9, 7]];

        $m = new ReflectionMethod(MessagesService::class, 'evaluatePolicy');
        $m->setAccessible(true);
        /** @var array<string, mixed>|null $gate */
        $gate = $m->invoke(null, 'normal_dm', 7, 9, 'Recipient not found.', true);

        $this->assertIsArray($gate);
        $this->assertSame('messaging_unavailable', $gate['reason_code']);
        $this->assertNotSame('unavailable', $gate['reason_code']);
    }
}
