<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\DigestService;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Services\NotificationViewService;
use BCC\Trust\Core\Support\NotificationType;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-578: the reviewer's bell for a reply on a card PAGE's review speaks as
 * the page ("{page} replied to your review."), with no person behind it, on
 * the bell list and in the weekly digest. A reply on a member's own profile
 * review still names that member. Rationale: F-578 (private tracker).
 *
 * The bell list and digest run for real (shapeRow / composeBody) over the
 * F-412 identity doubles; a page is named from its post title.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReviewReplyBellVoiceTest extends TestCase
{
    private const REPLIER   = 7;
    private const REVIEWER  = 9;
    private const PAGE      = 4521;

    protected function setUp(): void
    {
        parent::setUp();
        require_once dirname(__DIR__) . '/Stubs/notification-actor-handle-stubs.php';
        require_once dirname(__DIR__) . '/Stubs/page-title-post-stubs.php';
        $GLOBALS['__bcc_nah'] = [
            'users' => [self::REPLIER => ['login' => 'u7', 'display' => 'Hidden Operator']],
            'meta'  => [self::REPLIER => ['bcc_handle' => 'hiddenop']],
        ];
        $GLOBALS['__bcc_page_titles'] = [self::PAGE => 'Acme Validator'];
    }

    private static function row(int $externalId, string $message): object
    {
        return (object) [
            'not_id'           => 1,
            'not_user_id'      => self::REVIEWER,
            'not_from_user_id' => self::REPLIER,
            'not_external_id'  => $externalId,
            'not_act_id'       => 0,
            'not_type'         => NotificationType::REVIEW_REPLY,
            'not_message'      => $message,
            'not_timestamp'    => '2026-11-24 10:00:00',
            'not_read'         => 0,
        ];
    }

    /** @return array<string, mixed> */
    private static function shape(object $row): array
    {
        $shaped = (new \ReflectionMethod(NotificationViewService::class, 'shapeRow'))->invoke(null, $row);
        self::assertIsArray($shaped);
        return $shaped;
    }

    /** @param list<object> $rows */
    private static function digest(array $rows): string
    {
        $svc = (new \ReflectionClass(DigestService::class))->newInstanceWithoutConstructor();
        return (string) (new \ReflectionMethod(DigestService::class, 'composeBody'))
            ->invoke($svc, 'reviewer', $rows, false, 10, 'https://example.test/unsub');
    }

    public function testACardPagesReplyBellSpeaksAsThePageWithNoPersonBehindIt(): void
    {
        $shaped = self::shape(self::row(self::PAGE, 'Acme Validator replied to your review.'));

        self::assertSame('Acme Validator replied to your review.', $shaped['message']);
        self::assertSame(['id' => 0, 'handle' => '', 'display_name' => '', 'avatar_url' => ''], $shaped['actor'], 'no person as the actor');
        $wire = (string) json_encode($shaped);
        self::assertStringNotContainsString('hiddenop', $wire);
        self::assertStringNotContainsString('Hidden Operator', $wire);
        self::assertStringNotContainsString('"id":' . self::REPLIER, $wire, 'the replier id appears nowhere');
    }

    /** A row written before F-578 ("@handle replied to your review of …") is shown in the page's voice too. */
    public function testAnOlderRowNamingTheReplierIsReRenderedAsThePage(): void
    {
        $shaped = self::shape(self::row(self::PAGE, '@hiddenop replied to your review of Acme Validator.'));

        self::assertSame('Acme Validator replied to your review.', $shaped['message']);
        self::assertStringNotContainsString('hiddenop', (string) json_encode($shaped));
    }

    public function testAPageWithNoTitleGetsANeutralLine(): void
    {
        self::assertSame('Your review got a reply.', self::shape(self::row(4522, '@hiddenop replied to your review.'))['message']);
    }

    /** Control: a reply on a member's own profile review still names the member, who is its subject. */
    public function testAMembersOwnProfileReplyStillNamesTheMember(): void
    {
        $shaped = self::shape(self::row(MemberSelfPageService::selfPageId(self::REPLIER), '@hiddenop replied to your review.'));

        self::assertSame('@hiddenop replied to your review.', $shaped['message']);
        self::assertSame('hiddenop', $shaped['actor']['handle']);
    }

    public function testTheDigestSpeaksAsThePageToo(): void
    {
        $body = self::digest([
            self::row(self::PAGE, '@hiddenop replied to your review of Acme Validator.'),
            self::row(MemberSelfPageService::selfPageId(self::REPLIER), '@hiddenop replied to your review.'),
        ]);

        self::assertStringContainsString("- Acme Validator replied to your review.\n", $body);
        self::assertStringContainsString('- @hiddenop replied to your review.', $body, 'control: the member reply keeps its name');
        self::assertSame(1, substr_count($body, 'hiddenop'), 'only on the member reply');
    }

    public function testOnlyACardPagesReplyBellSpeaksAsThePage(): void
    {
        self::assertTrue(NotificationViewService::speaksAsPage(NotificationType::REVIEW_REPLY, self::PAGE));
        self::assertFalse(NotificationViewService::speaksAsPage(NotificationType::REVIEW_REPLY, MemberSelfPageService::selfPageId(self::REPLIER)));
        self::assertFalse(NotificationViewService::speaksAsPage(NotificationType::REVIEW_REPLY, 0));
        self::assertFalse(NotificationViewService::speaksAsPage(NotificationType::REVIEW, self::PAGE), 'only the reply bell');
    }
}
