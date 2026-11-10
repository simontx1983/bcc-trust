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
     * ⚠ This is the operator's explicit correction to the original plan:
     * the thread sends the real body plus the flag, and the client
     * collapses it. A future "tidy-up" that substitutes here too would
     * break tap-to-reveal, so the real body is asserted PRESENT.
     */
    public function testTheThreadKeepsTheRealBodyForABlockedSender(): void
    {
        $out = self::shaper('shapeThreadMessageRow')
            ->invoke(null, self::threadRow(11), self::userViews(), [11 => true]);

        $this->assertTrue($out['sender_blocked_by_viewer']);
        $this->assertSame(self::REAL_BODY, $out['body']);
    }

    public function testTheThreadFlagIsFalseForAnOrdinarySender(): void
    {
        $out = self::shaper('shapeThreadMessageRow')
            ->invoke(null, self::threadRow(12), self::userViews(), [11 => true]);

        $this->assertFalse($out['sender_blocked_by_viewer']);
        $this->assertSame(self::REAL_BODY, $out['body']);
    }

    public function testTheThreadRowNeverCarriesThePlaceholder(): void
    {
        $out = self::shaper('shapeThreadMessageRow')
            ->invoke(null, self::threadRow(11), self::userViews(), [11 => true]);

        $this->assertStringNotContainsString(
            'someone you have blocked',
            (string) json_encode($out),
            'The thread must not substitute: the client reveals the real body on tap.'
        );
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
