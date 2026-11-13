<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Repositories\ReviewReplyRepository;
use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\Feed\ActivityStreamWriter;
use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Services\PageOwnerResolver;
use BCC\Trust\Core\Services\ReviewReplyService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-345 review replies — the rules (user decisions 2026-11-12):
 * only the reviewed party replies (member self-page → the member; validator
 * page → its verified operator; any other page → its owner, so an unowned
 * placeholder has nobody), never the reviewer; ONE reply per review (a
 * hidden reply still holds the slot); ≤ 1000 chars; the author edits
 * (edited_at) and deletes; reads fail closed.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(ReviewReplyService::class)]
final class ReviewReplyServiceTest extends TestCase
{
    private const MEMBER   = 501;   // owns the self-page
    private const REVIEWER = 502;
    private const STRANGER = 503;
    private const OPERATOR = 504;
    private const OWNER    = 505;

    private const VOTE           = 70;
    private const VALIDATOR_PAGE = 800;
    private const PROJECT_PAGE   = 801;
    private const PLACEHOLDER    = 802;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/review-reply-service-stubs.php';
        \ReplyWorld::reset();
        \ReplyWorld::$reviews[self::VOTE] = ['voter' => self::REVIEWER, 'page' => self::selfPage()];
    }

    private static function selfPage(): int
    {
        return MemberSelfPageService::ID_BASE + self::MEMBER;
    }

    private static function service(): ReviewReplyService
    {
        return new ReviewReplyService(new VoteRepository(), new ReviewReplyRepository(), new ActivityStreamWriter(), new PageOwnerResolver());
    }

    /** @param array<string, mixed> $result */
    private static function code(array $result): ?string
    {
        return isset($result['error']) ? (string) $result['error'] : null;
    }

    // ── who may reply ───────────────────────────────────────────────────

    public function testTheReviewedMemberRepliesAndTheReviewerIsBelled(): void
    {
        $out = self::service()->create(self::MEMBER, self::VOTE, '  Thanks for the review!  ');

        self::assertNull(self::code($out));
        self::assertSame('Thanks for the review!', $out['reply']['body']);
        self::assertSame(self::MEMBER, $out['reply']['author_id']);
        self::assertSame('feed_' . (9000 + self::VOTE), $out['reply']['feed_id'], 'the report target the existing control sends');
        self::assertSame(
            [['bcc_review_reply_published', [self::MEMBER, self::REVIEWER, self::selfPage(), self::VOTE, 9000 + self::VOTE]]],
            \ReplyWorld::$actions
        );
    }

    public function testTheReviewerCannotReplyToTheirOwnReview(): void
    {
        \ReplyWorld::$reviews[self::VOTE] = ['voter' => self::MEMBER, 'page' => self::selfPage()];

        self::assertSame('bcc_forbidden', self::code(self::service()->create(self::MEMBER, self::VOTE, 'me again')));
    }

    public function testAThirdPartyCannotReply(): void
    {
        self::assertSame('bcc_forbidden', self::code(self::service()->create(self::STRANGER, self::VOTE, 'drive-by')));
        self::assertSame([], \ReplyWorld::$published, 'nothing written');
    }

    public function testOnAValidatorPageOnlyTheVerifiedOperatorReplies(): void
    {
        \ReplyWorld::$reviews[self::VOTE]                    = ['voter' => self::REVIEWER, 'page' => self::VALIDATOR_PAGE];
        \ReplyWorld::$validatorOperators[self::VALIDATOR_PAGE] = self::OPERATOR;
        \ReplyWorld::$owners[self::VALIDATOR_PAGE]             = self::OWNER; // the page owner is NOT the answerer here

        self::assertSame('bcc_forbidden', self::code(self::service()->create(self::OWNER, self::VOTE, 'not me')));
        self::assertNull(self::code(self::service()->create(self::OPERATOR, self::VOTE, 'operator here')));
    }

    public function testAnAmbiguousOrUnclaimedValidatorPageHasNoAnswerer(): void
    {
        \ReplyWorld::$reviews[self::VOTE]                    = ['voter' => self::REVIEWER, 'page' => self::VALIDATOR_PAGE];
        \ReplyWorld::$validatorOperators[self::VALIDATOR_PAGE] = 0;

        self::assertSame('bcc_forbidden', self::code(self::service()->create(self::OPERATOR, self::VOTE, 'x')));
    }

    public function testAClaimedProjectPageIsAnsweredByItsOwnerAndAnUnownedPlaceholderByNobody(): void
    {
        \ReplyWorld::$reviews[self::VOTE] = ['voter' => self::REVIEWER, 'page' => self::PROJECT_PAGE];
        \ReplyWorld::$reviews[71]         = ['voter' => self::REVIEWER, 'page' => self::PLACEHOLDER];
        \ReplyWorld::$owners[self::PROJECT_PAGE] = self::OWNER;
        \ReplyWorld::$owners[self::PLACEHOLDER]  = 0;

        self::assertNull(self::code(self::service()->create(self::OWNER, self::VOTE, 'owner reply')));
        self::assertSame('bcc_forbidden', self::code(self::service()->create(self::OWNER, 71, 'x')));
        self::assertFalse(self::service()->answersFor(self::PLACEHOLDER, 0));
    }

    // ── one reply, and the review's state ───────────────────────────────

    public function testASecondReplyIsAConflictEvenWhenTheFirstIsHidden(): void
    {
        self::service()->create(self::MEMBER, self::VOTE, 'first');
        \ReplyWorld::$replies[self::VOTE]['hidden'] = true;

        self::assertSame('bcc_conflict', self::code(self::service()->create(self::MEMBER, self::VOTE, 'second')));
    }

    public function testARemovedOrHiddenReviewIsNotFound(): void
    {
        unset(\ReplyWorld::$reviews[self::VOTE]);

        self::assertSame('bcc_not_found', self::code(self::service()->create(self::MEMBER, self::VOTE, 'x')));
    }

    public function testAFailedReadIsUnavailableNeverAGuess(): void
    {
        \ReplyWorld::$readFails = true;

        self::assertSame('bcc_unavailable', self::code(self::service()->create(self::MEMBER, self::VOTE, 'x')));
        self::assertSame([], \ReplyWorld::$published);
    }

    // ── input, standing and rate ────────────────────────────────────────

    public function testBodyLimits(): void
    {
        self::assertSame('bcc_invalid_request', self::code(self::service()->create(self::MEMBER, self::VOTE, '   ')));
        self::assertSame('bcc_invalid_request', self::code(self::service()->create(self::MEMBER, self::VOTE, str_repeat('a', 1001))));
        self::assertNull(self::code(self::service()->create(self::MEMBER, self::VOTE, str_repeat('a', 1000))), '1000 is allowed');
    }

    public function testASuspendedMemberCannotReplyAndTheThrottleApplies(): void
    {
        \ReplyWorld::$suspended[self::MEMBER] = true;
        self::assertSame('bcc_forbidden', self::code(self::service()->create(self::MEMBER, self::VOTE, 'x')));

        \ReplyWorld::$suspended = [];
        \ReplyWorld::$throttled = true;
        self::assertSame('bcc_rate_limited', self::code(self::service()->create(self::MEMBER, self::VOTE, 'x')));
    }

    public function testAnonymousIsUnauthorized(): void
    {
        self::assertSame('bcc_unauthorized', self::code(self::service()->create(0, self::VOTE, 'x')));
    }

    // ── edit and delete ─────────────────────────────────────────────────

    public function testOnlyTheAuthorEditsAndTheEditIsStamped(): void
    {
        self::service()->create(self::MEMBER, self::VOTE, 'first draft');

        self::assertSame('bcc_forbidden', self::code(self::service()->update(self::REVIEWER, self::VOTE, 'vandalised')));

        $out = self::service()->update(self::MEMBER, self::VOTE, 'second draft');
        self::assertNull(self::code($out));
        self::assertSame('second draft', $out['reply']['body']);
        self::assertNotNull($out['reply']['edited_at'], 'edited_at is shown after an edit');
    }

    public function testOnlyTheAuthorDeletesAndTheReviewerCannotRemoveIt(): void
    {
        self::service()->create(self::MEMBER, self::VOTE, 'reply');

        self::assertSame('bcc_forbidden', self::code(self::service()->delete(self::REVIEWER, self::VOTE)));
        self::assertSame([], \ReplyWorld::$deleted);

        self::assertSame(['deleted' => true], self::service()->delete(self::MEMBER, self::VOTE));
        self::assertSame([[5000 + self::VOTE, 9000 + self::VOTE]], \ReplyWorld::$deleted, 'post and activity both removed');
    }

    public function testEditingOrDeletingANonexistentReplyIsNotFound(): void
    {
        self::assertSame('bcc_not_found', self::code(self::service()->update(self::MEMBER, self::VOTE, 'x')));
        self::assertSame('bcc_not_found', self::code(self::service()->delete(self::MEMBER, self::VOTE)));
    }

    // ── the Reviews-tab bundle ──────────────────────────────────────────

    public function testTheListBundleShowsVisibleRepliesAndTheViewersCanReply(): void
    {
        \ReplyWorld::$reviews[71] = ['voter' => self::REVIEWER, 'page' => self::selfPage()];
        \ReplyWorld::$reviews[72] = ['voter' => self::REVIEWER, 'page' => self::selfPage()];
        self::service()->create(self::MEMBER, self::VOTE, 'visible');
        self::service()->create(self::MEMBER, 71, 'hidden one');
        \ReplyWorld::$replies[71]['hidden'] = true;

        $rows = [
            ['id' => self::VOTE, 'page_id' => self::selfPage(), 'voter_user_id' => self::REVIEWER],
            ['id' => 71, 'page_id' => self::selfPage(), 'voter_user_id' => self::REVIEWER],
            ['id' => 72, 'page_id' => self::selfPage(), 'voter_user_id' => self::REVIEWER],
        ];

        $asMember = self::service()->forReviews(self::MEMBER, $rows);
        self::assertSame('visible', $asMember[self::VOTE]['reply']['body'] ?? null);
        self::assertSame('already_replied', $asMember[self::VOTE]['can_reply']['reason_code']);
        self::assertNull($asMember[71]['reply'], 'a hidden reply renders as no reply');
        self::assertSame('already_replied', $asMember[71]['can_reply']['reason_code'], 'but still holds the slot');
        self::assertTrue($asMember[72]['can_reply']['allowed'], 'the reviewed party may reply where there is none');

        $asAnon = self::service()->forReviews(0, $rows);
        self::assertSame('not_applicable', $asAnon[72]['can_reply']['reason_code']);
        $asReviewer = self::service()->forReviews(self::REVIEWER, $rows);
        self::assertSame('not_applicable', $asReviewer[72]['can_reply']['reason_code'], 'the reviewer cannot respond');
    }

    public function testAFailedListReadShowsNoReplyAndOffersNoReply(): void
    {
        \ReplyWorld::$readFails = true;

        $out = self::service()->forReviews(self::MEMBER, [['id' => self::VOTE, 'page_id' => self::selfPage(), 'voter_user_id' => self::REVIEWER]]);

        self::assertNull($out[self::VOTE]['reply']);
        self::assertSame('unavailable', $out[self::VOTE]['can_reply']['reason_code']);
    }
}
