<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\DigestService;
use BCC\Trust\Core\Services\NotificationViewService;
use BCC\Trust\Core\Support\NotificationType;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-412: a notification names its actor by their CURRENT @handle.
 *
 * NotificationDispatcher stores "@handle …" in the row's text when it writes
 * the bell. After a rename, the bell showed that old handle beside the
 * actor object's current one, and the weekly digest mailed it. That ties a
 * retired handle to its new one for every member who saw the row. Both now
 * re-render the leading @handle at read time.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NotificationActorHandleTest extends TestCase
{
    private const ACTOR     = 7;
    private const OTHER     = 8;
    private const RECIPIENT = 9;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/notification-actor-handle-stubs.php';
        $GLOBALS['__bcc_nah'] = [
            'users' => [
                self::ACTOR => ['login' => 'u7', 'display' => 'Seven'],
                self::OTHER => ['login' => 'u8', 'display' => 'Eight'],
            ],
            'meta'  => [
                self::ACTOR => ['bcc_handle' => 'newname'],
                self::OTHER => ['bcc_handle' => 'otherone'],
            ],
        ];
    }

    private static function row(int $fromUserId, string $message, int $actId = 42): object
    {
        return (object) [
            'not_id'           => 1,
            'not_user_id'      => self::RECIPIENT,
            'not_from_user_id' => $fromUserId,
            'not_external_id'  => 0,
            'not_act_id'       => $actId,
            'not_type'         => NotificationType::REACTION,
            'not_message'      => $message,
            'not_timestamp'    => '2026-11-19 10:00:00',
            'not_read'         => 0,
        ];
    }

    /** @return array<string, mixed> */
    private static function shape(object $row): array
    {
        $m = new \ReflectionMethod(NotificationViewService::class, 'shapeRow');
        $shaped = $m->invoke(null, $row);
        self::assertIsArray($shaped);
        return $shaped;
    }

    /** @param list<object> $rows */
    private static function digestBody(array $rows): string
    {
        $svc = (new \ReflectionClass(DigestService::class))->newInstanceWithoutConstructor();
        $m   = new \ReflectionMethod(DigestService::class, 'composeBody');
        return (string) $m->invoke($svc, 'recipient', $rows, false, 10, 'https://example.test/unsub');
    }

    // ── the bell ────────────────────────────────────────────────────────

    public function testTheBellNamesARenamedActorByTheirCurrentHandle(): void
    {
        $shaped = self::shape(self::row(self::ACTOR, '@oldname reacted to your post.'));

        self::assertSame('@newname reacted to your post.', $shaped['message']);
        self::assertSame('newname', $shaped['actor']['handle'], 'the message and the actor now agree');
    }

    public function testTheBellKeepsTheStoredTextForAnActorThatNoLongerResolves(): void
    {
        $shaped = self::shape(self::row(99, '@gonename reacted to your post.'));

        self::assertSame('@gonename reacted to your post.', $shaped['message'], 'no "@ reacted…" with an empty handle');
        self::assertSame('', $shaped['actor']['handle']);
    }

    /**
     * F-513: the bell's reader is its recipient. A member's own action on
     * their own bell shows their own name, even with "Hide my real name"
     * on and even when that flag can't be read; another actor's hidden
     * name stays masked.
     */
    public function testASelfSentBellShowsTheMembersOwnName(): void
    {
        $GLOBALS['__bcc_nah']['users'][self::RECIPIENT] = ['login' => 'u9', 'display' => 'Nine'];
        $GLOBALS['__bcc_nah']['meta'][self::RECIPIENT]  = ['bcc_handle' => 'nine', 'bcc_privacy_real_name_hidden' => '1'];
        $GLOBALS['__bcc_nah']['meta'][self::OTHER]['bcc_privacy_real_name_hidden'] = '1';

        self::assertSame('Nine', self::shape(self::row(self::RECIPIENT, '@nine earned a rank.'))['actor']['display_name']);
        self::assertSame('@otherone', self::shape(self::row(self::OTHER, '@otherone vouched for you.'))['actor']['display_name'], 'control: another member stays masked');

        \BCC\Core\Repositories\UserMetaRepository::$unreadable = [self::RECIPIENT => true];
        self::assertSame('Nine', self::shape(self::row(self::RECIPIENT, '@nine earned a rank.'))['actor']['display_name'], 'unreadable: still their own name');
    }

    // ── the digest ──────────────────────────────────────────────────────

    public function testTheDigestNamesEachActorByTheirCurrentHandle(): void
    {
        $body = self::digestBody([
            self::row(self::ACTOR, '@oldname reacted to your post.'),
            self::row(self::OTHER, '@formerother vouched for you.'),
            self::row(self::ACTOR, '@oldname mentioned you in a post.'),
        ]);

        self::assertStringContainsString("- @newname reacted to your post.\n", $body);
        self::assertStringContainsString("- @otherone vouched for you.\n", $body);
        self::assertStringContainsString('- @newname mentioned you in a post.', $body);
        self::assertStringNotContainsString('oldname', $body);
        self::assertStringNotContainsString('formerother', $body);
        self::assertStringStartsWith("Hey @recipient,\n", $body, 'the greeting is still the recipient\'s');
    }

    // ── the rule itself ─────────────────────────────────────────────────

    public function testOnlyTheLeadingActorHandleIsReplaced(): void
    {
        self::assertSame(
            '@newname replied to your review of @someone-elses page.',
            NotificationViewService::withCurrentActorHandle('@oldname replied to your review of @someone-elses page.', 'newname')
        );
    }

    /** @return iterable<string, array{0: string}> */
    public static function untouched(): iterable
    {
        yield 'a system message'                  => ['You earned the Journeyman rank.'];
        yield 'a system message quoting an @'     => ['Your announcement "@launch" was reviewed and restored.'];
        yield 'an empty message'                  => [''];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('untouched')]
    public function testAMessageThatDoesNotLeadWithTheActorIsUntouched(string $message): void
    {
        self::assertSame($message, NotificationViewService::withCurrentActorHandle($message, 'newname'));
    }

    public function testTheCurrentHandleAlreadyThereIsUnchanged(): void
    {
        self::assertSame('@newname reacted to your post.', NotificationViewService::withCurrentActorHandle('@newname reacted to your post.', 'newname'));
    }

    public function testNoCurrentHandleKeepsTheStoredText(): void
    {
        self::assertSame('@oldname reacted to your post.', NotificationViewService::withCurrentActorHandle('@oldname reacted to your post.', ''));
    }

    public function testALongerHandleNeverPushesTheMessagePastTheColumnWidth(): void
    {
        $stored   = '@a ' . str_repeat('x', NotificationViewService::MESSAGE_MAX_LENGTH - 3);
        $rendered = NotificationViewService::withCurrentActorHandle($stored, 'a-twenty-char-handle');

        self::assertSame(NotificationViewService::MESSAGE_MAX_LENGTH, mb_strlen($stored));
        self::assertSame(NotificationViewService::MESSAGE_MAX_LENGTH, mb_strlen($rendered));
        self::assertStringStartsWith('@a-twenty-char-handle x', $rendered);
    }
}
