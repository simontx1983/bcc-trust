<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\CommentRepository;
use BCC\Trust\Core\Repositories\PushSubscriptionRepository;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Services\NotificationDispatcher;
use BCC\Trust\Core\Services\PageOwnerResolver;
use BCC\Trust\Core\Services\PushDispatcher;
use BCC\Trust\Core\Support\NotificationType;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-578: the bell NotificationDispatcher writes for a review reply. On a card
 * page it is in the page's voice and names no one; the replier stays the
 * row's sender, so PeepSo's block suppression and the bell list's
 * blocked-actor filter still apply. On a member's own profile review it still
 * names the member. Rationale: F-578 (private tracker).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ReviewReplyBellWriteTest extends TestCase
{
    private const REPLIER  = 7;
    private const REVIEWER = 11;
    private const PAGE     = 4521;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/mention-audience-stubs.php';
        require_once __DIR__ . '/../Stubs/page-title-post-stubs.php';
        $GLOBALS['__bcc_mna'] = [
            'meta' => [
                self::REPLIER  => ['bcc_handle' => 'hiddenop'],
                self::REVIEWER => ['bcc_handle' => 'reviewer'],
            ],
            'unmentionable' => [], 'bells' => [], 'pushes' => [], 'transients' => [],
            'now' => 1_800_000_000, 'deleted' => [], 'logs' => [],
        ];
        $GLOBALS['__bcc_page_titles'] = [self::PAGE => 'Acme Validator'];
    }

    private function dispatcher(): NotificationDispatcher
    {
        $bare = static fn(string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return new NotificationDispatcher(
            $bare(PageOwnerResolver::class),
            new PushDispatcher($bare(PushSubscriptionRepository::class)),
            $bare(CommentRepository::class),
            static fn(int $actId, int $viewerId): bool => true
        );
    }

    public function testACardPagesReplyBellIsWrittenInThePagesVoice(): void
    {
        $this->dispatcher()->onReviewReplyPublished(self::REPLIER, self::REVIEWER, self::PAGE, 70, 900);

        self::assertCount(1, $GLOBALS['__bcc_mna']['bells']);
        $bell = $GLOBALS['__bcc_mna']['bells'][0];
        self::assertSame('Acme Validator replied to your review.', $bell['message']);
        self::assertStringNotContainsString('hiddenop', $bell['message']);
        self::assertSame(self::REPLIER, $bell['from'], 'the replier stays the sender, for block suppression');
        self::assertSame([self::REVIEWER, NotificationType::REVIEW_REPLY, self::PAGE], [$bell['to'], $bell['type'], $bell['external_id']]);
    }

    public function testAMembersOwnProfileReplyBellStillNamesTheMember(): void
    {
        $this->dispatcher()->onReviewReplyPublished(self::REPLIER, self::REVIEWER, MemberSelfPageService::selfPageId(self::REPLIER), 70, 900);

        self::assertSame('@hiddenop replied to your review.', $GLOBALS['__bcc_mna']['bells'][0]['message'] ?? null);
    }
}
