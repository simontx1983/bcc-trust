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
 * #122: no live "you have new chats" ping to a recipient who has blocked
 * the sender.
 *
 * ⚠⚠ THIS VETO IS THE DANGEROUS ONE, and the tests are weighted
 * accordingly. It hooks `update_user_metadata`, which carries EVERY
 * user-meta write in the process, and WordPress short-circuits on ANY
 * non-null return (`if (null !== $check) return (bool) $check;`). Two
 * mistakes are therefore available that are silent and site-wide for the
 * duration of a send:
 *
 *   1. returning `true` to allow — which would make every passing
 *      user-meta write report success WITHOUT writing. The sibling mail
 *      veto allows by returning the recipient id, so copying its shape
 *      gets this exactly wrong. The allow path must hand back the
 *      incoming `$check`.
 *   2. matching too many keys — a veto that fired on the wrong meta key
 *      would silently drop unrelated writes for the blocker.
 *
 * Both are pinned below, and the key pattern is tested against
 * lookalikes rather than only against the keys it should match.
 *
 * ⚠ What this suppresses is NOT SSE. `PeepSoSSEEvents::trigger()`
 * returns at its first statement with the comment "SSE is not working
 * yet", so every SSE call site is already a no-op and a test asserting
 * "no SSE event" would pass vacuously. The live signal PeepSo actually
 * uses is this user option, polled by `chat.js`.
 */
#[CoversClass(MessagesService::class)]
final class BlockedRecipientChatPingVetoTest extends TestCase
{
    private const SENDER = 10;
    private const BLOCKER = 20;
    private const ORDINARY = 21;

    /** The key PeepSo writes, under the default single-site prefix. */
    private const KEY = 'wp_peepso_should_get_chats';

    protected function setUp(): void
    {
        PeepSoBlockRepository::reset();
    }

    private static function veto(int $senderId): \Closure
    {
        $m = (new ReflectionClass(MessagesService::class))->getMethod('blockedRecipientChatPingVeto');
        $m->setAccessible(true);
        /** @var \Closure $c */
        $c = $m->invoke(null, $senderId);
        return $c;
    }

    // ── the suppression itself ──────────────────────────────────────────

    public function testTheBlockersPingIsSuppressed(): void
    {
        PeepSoBlockRepository::$blocking = [[self::BLOCKER, self::SENDER]];

        $out = self::veto(self::SENDER)(null, self::BLOCKER, self::KEY);

        $this->assertFalse($out, 'A recipient who blocked the sender must not be told to refetch.');
    }

    /**
     * ⚠ MISTAKE 1. The allow path hands back the INCOMING `$check`, which
     * is normally null. `true` here would short-circuit every user-meta
     * write in the process and report success without writing.
     */
    public function testAnOrdinaryRecipientAllowsByReturningNullNotTrue(): void
    {
        PeepSoBlockRepository::$blocking = [[self::BLOCKER, self::SENDER]];

        $out = self::veto(self::SENDER)(null, self::ORDINARY, self::KEY);

        $this->assertNull($out);
        $this->assertNotSame(true, $out, 'Returning true would make WordPress skip the write and claim success.');
        $this->assertNotSame(false, $out, 'Returning false would suppress an unblocked recipient.');
    }

    /** It only ever ADDS a refusal; an earlier filter's decision survives. */
    public function testAnEarlierFiltersDecisionIsPreserved(): void
    {
        $out = self::veto(self::SENDER)(true, self::ORDINARY, self::KEY);

        $this->assertTrue($out, 'A non-null $check from an earlier filter must pass through unchanged.');
    }

    /** ⚠ Direction. A sender who blocked the recipient still notifies them. */
    public function testASenderWhoBlockedTheRecipientDoesNotSuppressTheirPing(): void
    {
        PeepSoBlockRepository::$blocking = [[self::SENDER, self::BLOCKER]];

        $this->assertNull(self::veto(self::SENDER)(null, self::BLOCKER, self::KEY));
    }

    // ── MISTAKE 2: the key pattern ──────────────────────────────────────

    /**
     * ⚠ The veto must be inert for every other meta key, EVEN FOR A
     * BLOCKER. Without this it would silently drop unrelated metadata
     * writes — capabilities, session tokens, anything — for that user,
     * for the duration of a send.
     */
    public function testAnUnrelatedMetaKeyIsUntouchedEvenForABlocker(): void
    {
        PeepSoBlockRepository::$blocking = [[self::BLOCKER, self::SENDER]];
        $veto = self::veto(self::SENDER);

        foreach (['wp_capabilities', 'session_tokens', 'last_name', 'peepso_user_field_1'] as $key) {
            $this->assertNull($veto(null, self::BLOCKER, $key), "key {$key} must pass through");
        }
        $this->assertSame([], PeepSoBlockRepository::$isBlockingCalls, 'A non-matching key must not even cost a block probe.');
    }

    /**
     * The three spellings that ARE the flag: unprefixed, single-site, and
     * multisite. A hardcoded `wp_` would miss the last one.
     */
    public function testEveryLegitimatePrefixMatches(): void
    {
        PeepSoBlockRepository::$blocking = [[self::BLOCKER, self::SENDER]];
        $veto = self::veto(self::SENDER);

        foreach ([
            'peepso_should_get_chats',
            'wp_peepso_should_get_chats',
            'wp_3_peepso_should_get_chats',
        ] as $key) {
            $this->assertFalse($veto(null, self::BLOCKER, $key), "key {$key} must be recognised as the flag");
        }
    }

    /**
     * ⚠ And the lookalikes that must NOT match. An earlier version of the
     * pattern was `^[a-z0-9_]*peepso_should_get_chats$`, whose head could
     * absorb bare letters, so ANY key ending in the literal matched.
     * Requiring each head segment to end in `_` means only a real table
     * prefix can precede it.
     */
    public function testALookalikeKeyDoesNotMatch(): void
    {
        PeepSoBlockRepository::$blocking = [[self::BLOCKER, self::SENDER]];
        $veto = self::veto(self::SENDER);

        foreach (['evilpeepso_should_get_chats', 'xpeepso_should_get_chats'] as $key) {
            $this->assertNull($veto(null, self::BLOCKER, $key), "key {$key} must NOT be treated as the flag");
        }
    }

    /**
     * ⚠⚠ THE PATTERN CARRIES `/i`, AND THE REASON IS MySQL, NOT PHP.
     *
     * It is tempting to argue the other way: PeepSo reads the flag with
     * `get_user_option('peepso_should_get_chats')`
     * (`peepso-messages/peepsomessages.php:205`), which resolves through
     * the user-meta cache — a PHP array lookup, so a differently-cased
     * STORED key would never be read, and `/i` would buy nothing. That
     * reasoning is about the read, and the veto sits on the WRITE.
     *
     * `update_metadata()` decides between update and insert with a
     * direct `SELECT ... WHERE meta_key = %s`, and `wp_usermeta.meta_key`
     * is `utf8mb4_unicode_520_ci` on this platform (measured, not
     * assumed). So a write keyed `PeepSo_should_get_chats` finds the
     * CANONICAL lowercase row and updates IT — setting the real flag
     * that `get_user_option` then reads as truthy. A case-sensitive veto
     * would wave that write through and the blocker's client would start
     * polling, with nothing else behind it to catch the miss.
     *
     * The same collation is why `REGEXP '[A-Z]'` matches lowercase on
     * this database: PHP's case sensitivity and MySQL's do not agree,
     * and a gate on a key has to be as loose as the comparison that key
     * will eventually be subjected to.
     */
    public function testACaseVariantOfTheFlagIsStillSuppressed(): void
    {
        PeepSoBlockRepository::$blocking = [[self::BLOCKER, self::SENDER]];
        $veto = self::veto(self::SENDER);

        foreach ([
            'PeepSo_should_get_chats',
            'PEEPSO_SHOULD_GET_CHATS',
            'WP_3_PeepSo_Should_Get_Chats',
        ] as $key) {
            $this->assertFalse(
                $veto(null, self::BLOCKER, $key),
                "key {$key} differs only in case, so update_metadata's _ci SELECT would land it on the canonical row"
            );
        }
    }

    /**
     * ⚠ And the tightening SURVIVES the case-insensitivity. `/i` makes
     * the head segments case-insensitive too, so it would be easy to
     * assume the lookalike now slips through. It does not — each head
     * segment must still end in `_`, which is structural and unaffected
     * by case. Asserted rather than assumed, because the two changes
     * pull in opposite directions: one deliberately loosens the pattern,
     * and the point of this case is that it did not loosen THAT.
     */
    public function testTheLookalikeStillDoesNotMatchUnderCaseInsensitivity(): void
    {
        PeepSoBlockRepository::$blocking = [[self::BLOCKER, self::SENDER]];
        $veto = self::veto(self::SENDER);

        foreach ([
            'EvilPeepSo_should_get_chats',
            'XPEEPSO_SHOULD_GET_CHATS',
            'evilpeepso_should_get_chats',
        ] as $key) {
            $this->assertNull(
                $veto(null, self::BLOCKER, $key),
                "key {$key} has no separator before the literal, so it is not a prefixed flag"
            );
        }
    }

    /**
     * ⚠ The sibling key `peepso_should_get_chats_delay` is NOT the
     * ping and is deliberately outside the pattern (it is anchored with
     * `$`). Its only writer passes `get_current_user_id()`
     * (`peepsomessages.php:220`) — the viewer's own polling interval,
     * never a recipient's — so it is not part of the send fan-out and
     * vetoing it would suppress a write that tells nobody anything.
     * Pinned so the anchor is not "tidied" into a prefix match later.
     */
    public function testTheDelaySiblingKeyIsNotVetoed(): void
    {
        PeepSoBlockRepository::$blocking = [[self::BLOCKER, self::SENDER]];

        $this->assertNull(
            self::veto(self::SENDER)(null, self::BLOCKER, 'peepso_should_get_chats_delay')
        );
    }

    public function testANonStringMetaKeyIsPassedThrough(): void
    {
        PeepSoBlockRepository::$blocking = [[self::BLOCKER, self::SENDER]];

        $this->assertNull(self::veto(self::SENDER)(null, self::BLOCKER, 123));
    }

    public function testAZeroRecipientIsPassedThroughUnprobed(): void
    {
        $this->assertNull(self::veto(self::SENDER)(null, 0, self::KEY));
        $this->assertSame([], PeepSoBlockRepository::$isBlockingCalls);
    }

    public function testTheProbeAsksTheOneDirectionalQuestionRecipientFirst(): void
    {
        self::veto(self::SENDER)(null, self::BLOCKER, self::KEY);

        $this->assertSame([[self::BLOCKER, self::SENDER]], PeepSoBlockRepository::$isBlockingCalls);
    }

    /**
     * Anti-vacuity: every "allow" case above would also pass against a
     * veto that can never fire, so both states are exercised on one
     * closure.
     */
    public function testTheVetoCanBothAllowAndSuppress(): void
    {
        $veto = self::veto(self::SENDER);
        $this->assertNull($veto(null, self::BLOCKER, self::KEY), 'no block configured: allowed');

        PeepSoBlockRepository::$blocking = [[self::BLOCKER, self::SENDER]];
        $this->assertFalse($veto(null, self::BLOCKER, self::KEY), 'block configured: suppressed');
    }

    // ── registration discipline, read from source ───────────────────────

    /**
     * ⚠ `accepted_args` MUST be 3. `add_filter` defaults it to 1, and
     * with 1 the callback never receives `$object_id` or `$meta_key` —
     * it would fatal on PHP 8, or with parameter defaults would match
     * every key for every user. Same footgun as `pre_wp_mail`'s
     * two-argument signature.
     */
    public function testBothSendPathsRegisterTheVetoWithThreeAcceptedArgs(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Services/MessagesService.php'
        );
        $this->assertNotSame('', $src, 'Could not read MessagesService.php — the assertions below would be vacuous.');

        $this->assertSame(
            2,
            substr_count($src, "add_filter('update_user_metadata', \$pingVeto, 99, 3);"),
            'Both send paths must register the ping veto, with accepted_args 3.'
        );
        $this->assertSame(
            2,
            substr_count($src, "remove_filter('update_user_metadata', \$pingVeto, 99);"),
            'Every registration must be paired with a removal.'
        );
        $this->assertSame(
            0,
            substr_count($src, "add_filter('update_user_metadata', \$pingVeto, 99);"),
            'A registration without accepted_args would receive only $check and match every key.'
        );
    }

    /**
     * Both vetoes must come off in the same `finally`, so a throwing
     * write cannot leave either installed for the rest of the request.
     */
    public function testBothVetoesAreRemovedInAFinally(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/Services/MessagesService.php'
        );

        $this->assertSame(2, substr_count($src, '} finally {'));
        // Each finally removes BOTH filters: 2 finallys x 2 removals.
        $this->assertSame(2, substr_count($src, "remove_filter('update_user_metadata', \$pingVeto, 99);"));
        $this->assertSame(2, substr_count($src, "remove_filter('peepso_mailqueue_allowed', \$veto, 99);"));
    }
}
