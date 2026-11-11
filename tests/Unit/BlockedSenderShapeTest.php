<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\MessagesService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

require_once __DIR__ . '/../Stubs/blocked-sender-stubs.php';

/**
 * #122, policy (b) "hide-on-read": the two row shapers and the
 * per-recipient mail veto.
 *
 * The decision recorded on the issue draws a line that is easy to get
 * backwards, so it is asserted from both sides:
 *
 *   - the THREAD keeps the real body (tap-to-reveal), and
 *   - the INBOX PREVIEW does not, because an inbox row has no reveal
 *     affordance.
 *
 * ⚠ The inbox assertion that matters is not "the preview equals the
 * placeholder" — it is that the real text is NOWHERE in the shaped row.
 * Those differ: a shaper could set the placeholder and still leave the
 * body in a sibling key, and the first assertion would pass.
 */
#[CoversClass(MessagesService::class)]
final class BlockedSenderShapeTest extends TestCase
{
    private const REAL_BODY = 'SENTINEL-the-actual-private-message-text';

    private static function shaper(string $name): ReflectionMethod
    {
        $m = (new ReflectionClass(MessagesService::class))->getMethod($name);
        $m->setAccessible(true);
        return $m;
    }

    /** @return array<string, mixed> */
    private static function inboxRow(int $authorId): array
    {
        return [
            'last_msg_id'         => 501,
            'last_msg_author_id'  => $authorId,
            'last_msg_content'    => self::REAL_BODY,
            'last_msg_posted_at'  => '2026-11-10 12:00:00',
        ];
    }

    /** @return array<string, mixed> */
    private static function threadRow(int $authorId): array
    {
        return [
            'id'        => 777,
            'author_id' => $authorId,
            'body'      => self::REAL_BODY,
            'posted_at' => '2026-11-10 12:00:00',
            'post_type' => 'peepso-message',
        ];
    }

    /** @return array<int, array{id: int, handle: string, display_name: string, avatar_url: string}> */
    private static function userViews(): array
    {
        return [
            11 => ['id' => 11, 'handle' => 'blocked_one', 'display_name' => 'Blocked One', 'avatar_url' => ''],
            12 => ['id' => 12, 'handle' => 'ordinary', 'display_name' => 'Ordinary', 'avatar_url' => ''],
        ];
    }

    // ── inbox preview: the one place the text is withheld ───────────────

    public function testTheInboxPreviewIsSubstitutedForABlockedSender(): void
    {
        $out = self::shaper('shapeInboxLastMessage')
            ->invoke(null, self::inboxRow(11), self::userViews(), [11 => true]);

        $this->assertTrue($out['sender_blocked_by_viewer']);
        $this->assertSame('Message from someone you have blocked', $out['preview']);
    }

    /**
     * ⚠ The assertion that actually protects the viewer: the real text is
     * absent from the ENTIRE shaped row, not merely absent from
     * `preview`. Serialising the row and searching it is what catches a
     * body left behind in a sibling key.
     */
    public function testTheRealTextIsNowhereInABlockedInboxRow(): void
    {
        $out = self::shaper('shapeInboxLastMessage')
            ->invoke(null, self::inboxRow(11), self::userViews(), [11 => true]);

        $serialised = (string) json_encode($out);
        $this->assertStringNotContainsString(
            self::REAL_BODY,
            $serialised,
            'The blocked sender\'s message text must not appear anywhere in the inbox payload.'
        );
    }

    public function testAnOrdinarySenderStillGetsTheRealPreview(): void
    {
        $out = self::shaper('shapeInboxLastMessage')
            ->invoke(null, self::inboxRow(12), self::userViews(), [11 => true]);

        $this->assertFalse($out['sender_blocked_by_viewer']);
        $this->assertStringContainsString(self::REAL_BODY, (string) $out['preview']);
    }

    /**
     * Anti-vacuity: if the shaper substituted for EVERYONE the two tests
     * above would both still pass in isolation, so pin that the
     * placeholder appears only for the blocked case.
     */
    public function testTheSubstitutionIsNotAppliedUnconditionally(): void
    {
        $blocked = self::shaper('shapeInboxLastMessage')
            ->invoke(null, self::inboxRow(11), self::userViews(), [11 => true]);
        $ordinary = self::shaper('shapeInboxLastMessage')
            ->invoke(null, self::inboxRow(12), self::userViews(), [11 => true]);

        $this->assertNotSame($blocked['preview'], $ordinary['preview']);
    }

    public function testAnEmptyBlockedSetSubstitutesNothing(): void
    {
        $out = self::shaper('shapeInboxLastMessage')
            ->invoke(null, self::inboxRow(11), self::userViews(), []);

        $this->assertFalse($out['sender_blocked_by_viewer']);
        $this->assertStringContainsString(self::REAL_BODY, (string) $out['preview']);
    }

    // ── thread: keeps the real body, by decision ────────────────────────

    /**
     * ⚠⚠ DELIBERATELY INVERTED on 2026-11-10, and kept rather than
     * deleted so the reversal is legible.
     *
     * This case previously asserted the thread kept the REAL body, which
     * was correct for the first decision on #122: send the text, let the
     * client collapse it, no round trip to reveal. The decision then
     * became "fetch only when tapped", because the earlier shape left
     * the text in the viewer's browser whether they revealed it or not.
     *
     * So the assertion is now the opposite: the thread carries the
     * placeholder, and `revealMessageBody` is the only path to the real
     * text. A test flipped in place with its history written down is
     * worth more than a deleted one.
     */
    public function testTheThreadNowSubstitutesTheBodyForABlockedSender(): void
    {
        $out = self::shaper('shapeThreadMessageRow')
            ->invoke(null, self::threadRow(11), self::userViews(), [11 => true]);

        $this->assertTrue($out['sender_blocked_by_viewer']);
        $this->assertSame('Message from someone you have blocked', $out['body']);
    }

    /**
     * The assertion that actually protects the viewer, and the reason
     * the one above is not enough on its own: the real text must be
     * absent from the ENTIRE serialised row, not merely replaced in
     * `body`. A shaper could substitute `body` and leave the text in a
     * sibling key, and the assertion above would still pass.
     */
    public function testTheRealTextIsNowhereInABlockedThreadRow(): void
    {
        $out = self::shaper('shapeThreadMessageRow')
            ->invoke(null, self::threadRow(11), self::userViews(), [11 => true]);

        $this->assertStringNotContainsString(
            self::REAL_BODY,
            (string) json_encode($out),
            'A blocked sender\'s text must not reach the browser until the viewer taps to reveal it.'
        );
    }

    /**
     * The other half of the substitution: a participant who has NOT
     * blocked the sender still receives the real text in the thread.
     * Without this, "substitute when blocked" and "substitute always"
     * are indistinguishable.
     */
    public function testTheThreadFlagIsFalseForAnOrdinarySender(): void
    {
        $out = self::shaper('shapeThreadMessageRow')
            ->invoke(null, self::threadRow(12), self::userViews(), [11 => true]);

        $this->assertFalse($out['sender_blocked_by_viewer']);
        $this->assertSame(self::REAL_BODY, $out['body']);
    }

    /**
     * ⚠⚠ ALSO DELIBERATELY INVERTED on 2026-11-10 — same reversal as
     * above. It asserted the thread never carried the placeholder;
     * carrying it is now the point. Kept and flipped rather than
     * removed.
     *
     * ⚠ And it pins that BOTH surfaces use the SAME words. The frontend
     * matches this string, so an inbox/thread divergence would be a
     * frontend bug with a backend cause.
     */
    public function testBothSurfacesUseTheSamePlaceholderWords(): void
    {
        $thread = self::shaper('shapeThreadMessageRow')
            ->invoke(null, self::threadRow(11), self::userViews(), [11 => true]);
        $inbox = self::shaper('shapeInboxLastMessage')
            ->invoke(null, self::inboxRow(11), self::userViews(), [11 => true]);

        $this->assertSame('Message from someone you have blocked', $thread['body']);
        $this->assertSame($thread['body'], $inbox['preview']);
    }

    public function testBothShapersAlwaysEmitTheFlag(): void
    {
        $inbox = self::shaper('shapeInboxLastMessage')
            ->invoke(null, self::inboxRow(12), self::userViews(), []);
        $thread = self::shaper('shapeThreadMessageRow')
            ->invoke(null, self::threadRow(12), self::userViews(), []);

        // Absent would make the frontend read `undefined` rather than
        // false, so the key must exist on every row either way.
        $this->assertArrayHasKey('sender_blocked_by_viewer', $inbox);
        $this->assertArrayHasKey('sender_blocked_by_viewer', $thread);
    }

    public function testTheInlineNoticeFlagStillSurvivesTheShaper(): void
    {
        $row = self::threadRow(12);
        $row['post_type'] = 'peepso-message-notic';

        $out = self::shaper('shapeThreadMessageRow')->invoke(null, $row, self::userViews(), []);

        $this->assertTrue($out['is_inline_notice']);
    }

    // ── the endpoint must not whitelist row keys ────────────────────────

    /**
     * Both handlers currently pass `$result['items']` through wholesale,
     * whitelisting only the top-level keys. That is the only reason the
     * new field reaches the client without an endpoint change — so if
     * someone later adds a per-row whitelist, this fails rather than the
     * field silently vanishing from the API.
     */
    public function testTheEndpointPassesRowsThroughWithoutAKeyWhitelist(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Core/REST/MessagesEndpoint.php'
        );
        $this->assertNotSame('', $src, 'Could not read MessagesEndpoint.php — the assertion below would be vacuous.');

        $this->assertStringContainsString("'items'      => \$result['items'],", $src);
        $this->assertStringContainsString("'items'        => \$result['items'],", $src);
        $this->assertStringNotContainsString('last_message\'  =>', $src);
        $this->assertStringNotContainsString('sender_blocked_by_viewer', $src);
    }
}
