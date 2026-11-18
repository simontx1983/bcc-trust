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
 * F-345 review replies — the rules (user decisions 2026-11-12, hand-over
 * rule 2026-11-17):
 * only the reviewed party replies (member self-page → the member; validator
 * page → its verified operator; any other page → its owner, so an unowned
 * placeholder has nobody), never the reviewer; ONE reply per review per
 * party (a hidden reply still holds the slot and is frozen); ≤ 1000 chars;
 * the author edits (edited_at, only while they still answer for the page)
 * and deletes; when the page changes hands the old reply stops showing and
 * the slot is free; reads fail closed.
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
    private const NEW_OPERATOR = 506;

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

    /** @return list<array<string, mixed>> */
    private static function rows(int ...$voteIds): array
    {
        $rows = [];
        foreach ($voteIds as $id) {
            $r      = \ReplyWorld::$reviews[$id];
            $rows[] = ['id' => $id, 'page_id' => $r['page'], 'voter_user_id' => $r['voter']];
        }
        return $rows;
    }

    private function onValidatorPage(int $operator): void
    {
        \ReplyWorld::$reviews[self::VOTE]                      = ['voter' => self::REVIEWER, 'page' => self::VALIDATOR_PAGE];
        \ReplyWorld::$validatorOperators[self::VALIDATOR_PAGE] = $operator;
    }

    // ── who may reply ───────────────────────────────────────────────────

    public function testTheReviewedMemberRepliesAndTheReviewerIsBelled(): void
    {
        $out = self::service()->create(self::MEMBER, self::VOTE, '  Thanks for the review!  ');

        self::assertNull(self::code($out));
        self::assertSame('Thanks for the review!', $out['reply']['body']);
        self::assertSame(self::MEMBER, $out['reply']['author_id']);
        $actId = \ReplyWorld::$replies[self::VOTE][0]['act_id'];
        self::assertSame('feed_' . $actId, $out['reply']['feed_id'], 'the report target the existing control sends');
        self::assertSame(
            [['bcc_review_reply_published', [self::MEMBER, self::REVIEWER, self::selfPage(), self::VOTE, $actId]]],
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
        $this->onValidatorPage(self::OPERATOR);
        \ReplyWorld::$owners[self::VALIDATOR_PAGE] = self::OWNER; // the page owner is NOT the answerer here

        self::assertSame('bcc_forbidden', self::code(self::service()->create(self::OWNER, self::VOTE, 'not me')));
        self::assertNull(self::code(self::service()->create(self::OPERATOR, self::VOTE, 'operator here')));
    }

    public function testAnAmbiguousOrUnclaimedValidatorPageHasNoAnswerer(): void
    {
        $this->onValidatorPage(0);

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
        \ReplyWorld::hide(self::VOTE, self::MEMBER);

        self::assertSame('bcc_conflict', self::code(self::service()->create(self::MEMBER, self::VOTE, 'second')));
        self::assertCount(1, \ReplyWorld::$published);
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

    public function testACreateHoldsAPerReviewLockAndABusyLockWritesNothing(): void
    {
        self::service()->create(self::MEMBER, self::VOTE, 'first');
        self::assertSame(['acquire:bcc_review_reply_' . self::VOTE, 'release:bcc_review_reply_' . self::VOTE], \ReplyWorld::$locks);

        \ReplyWorld::$lockBusy = true;
        \ReplyWorld::$reviews[71] = ['voter' => self::REVIEWER, 'page' => self::selfPage()];
        self::assertSame('bcc_unavailable', self::code(self::service()->create(self::MEMBER, 71, 'concurrent')));
        self::assertCount(1, \ReplyWorld::$published, 'a create that cannot take the lock writes nothing');
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

        self::assertSame('bcc_not_found', self::code(self::service()->update(self::REVIEWER, self::VOTE, 'vandalised')), 'the reviewer has no reply here');

        $out = self::service()->update(self::MEMBER, self::VOTE, 'second draft');
        self::assertNull(self::code($out));
        self::assertSame('second draft', $out['reply']['body']);
        self::assertNotNull($out['reply']['edited_at'], 'edited_at is shown after an edit');
    }

    public function testOnlyTheAuthorDeletesAndTheReviewerCannotRemoveIt(): void
    {
        self::service()->create(self::MEMBER, self::VOTE, 'reply');
        $row = \ReplyWorld::$replies[self::VOTE][0];

        self::assertSame('bcc_not_found', self::code(self::service()->delete(self::REVIEWER, self::VOTE)));
        self::assertSame([], \ReplyWorld::$deleted);

        self::assertSame(['deleted' => true], self::service()->delete(self::MEMBER, self::VOTE));
        self::assertSame([[$row['post_id'], $row['act_id']]], \ReplyWorld::$deleted, 'post and activity both removed');
    }

    public function testEditingOrDeletingANonexistentReplyIsNotFound(): void
    {
        self::assertSame('bcc_not_found', self::code(self::service()->update(self::MEMBER, self::VOTE, 'x')));
        self::assertSame('bcc_not_found', self::code(self::service()->delete(self::MEMBER, self::VOTE)));
    }

    public function testAModerationHiddenReplyIsFrozenItsAuthorCanNeitherDeleteNorEditIt(): void
    {
        self::service()->create(self::MEMBER, self::VOTE, 'abusive');
        \ReplyWorld::hide(self::VOTE, self::MEMBER);

        self::assertSame('bcc_not_found', self::code(self::service()->delete(self::MEMBER, self::VOTE)), 'same answer as no reply');
        self::assertSame([], \ReplyWorld::$deleted, 'the hidden act an open report points at survives');
        self::assertSame('bcc_not_found', self::code(self::service()->update(self::MEMBER, self::VOTE, 'cleaned up')));
        self::assertSame('bcc_conflict', self::code(self::service()->create(self::MEMBER, self::VOTE, 'again')), 'and it keeps the slot');
        self::assertCount(1, \ReplyWorld::$published, 'no re-post, so no second bell');
    }

    // ── the hand-over rule ──────────────────────────────────────────────

    public function testWhenThePageChangesHandsTheOldReplyStopsShowingAndTheSlotIsFree(): void
    {
        $this->onValidatorPage(self::OPERATOR);
        self::service()->create(self::OPERATOR, self::VOTE, 'from the old operator');

        \ReplyWorld::$validatorOperators[self::VALIDATOR_PAGE] = self::NEW_OPERATOR;

        foreach ([0, self::REVIEWER, self::OPERATOR, self::NEW_OPERATOR] as $viewer) {
            self::assertNull(self::service()->forReviews($viewer, self::rows(self::VOTE))[self::VOTE]['reply'], "viewer {$viewer}: the stale reply is not the page's response");
        }
        self::assertTrue(self::service()->forReviews(self::NEW_OPERATOR, self::rows(self::VOTE))[self::VOTE]['can_reply']['allowed'], 'the slot is free for the current party');

        self::assertNull(self::code(self::service()->create(self::NEW_OPERATOR, self::VOTE, 'from the new operator')));
        self::assertSame('from the new operator', self::service()->forReviews(0, self::rows(self::VOTE))[self::VOTE]['reply']['body'] ?? null);
    }

    public function testTheOldAuthorCanDeleteButNotEditAStaleReply(): void
    {
        $this->onValidatorPage(self::OPERATOR);
        self::service()->create(self::OPERATOR, self::VOTE, 'old');
        \ReplyWorld::$validatorOperators[self::VALIDATOR_PAGE] = self::NEW_OPERATOR;

        self::assertSame('bcc_forbidden', self::code(self::service()->update(self::OPERATOR, self::VOTE, 'edited after losing the page')));
        self::assertSame('old', \ReplyWorld::$replies[self::VOTE][0]['body']);
        self::assertSame(['deleted' => true], self::service()->delete(self::OPERATOR, self::VOTE));
    }

    // ── the Reviews-tab bundle ──────────────────────────────────────────

    public function testTheListBundleShowsVisibleRepliesAndTheViewersCanReply(): void
    {
        \ReplyWorld::$reviews[71] = ['voter' => self::REVIEWER, 'page' => self::selfPage()];
        \ReplyWorld::$reviews[72] = ['voter' => self::REVIEWER, 'page' => self::selfPage()];
        self::service()->create(self::MEMBER, self::VOTE, 'visible');
        self::service()->create(self::MEMBER, 71, 'hidden one');
        \ReplyWorld::hide(71, self::MEMBER);

        $rows = self::rows(self::VOTE, 71, 72);

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

        $out = self::service()->forReviews(self::MEMBER, self::rows(self::VOTE));

        self::assertNull($out[self::VOTE]['reply']);
        self::assertSame('unavailable', $out[self::VOTE]['can_reply']['reason_code']);
    }

    // ── reply.permissions (server-computed, §1.4.5) ─────────────────────

    public function testTheReplyCarriesWhatThisViewerMayDoWithIt(): void
    {
        self::service()->create(self::MEMBER, self::VOTE, 'thanks');
        $perms = static fn(int $viewer): array => self::service()->forReviews($viewer, self::rows(self::VOTE))[self::VOTE]['reply']['permissions'];

        $author = $perms(self::MEMBER);
        self::assertTrue($author['can_edit']['allowed']);
        self::assertTrue($author['can_delete']['allowed']);
        self::assertSame('own_reply', $author['can_report']['reason_code']);

        $reviewer = $perms(self::REVIEWER);
        self::assertSame('not_author', $reviewer['can_edit']['reason_code']);
        self::assertSame('not_author', $reviewer['can_delete']['reason_code']);
        self::assertTrue($reviewer['can_report']['allowed'], 'the reviewer can report it');

        $anon = $perms(0);
        self::assertSame('sign_in_required', $anon['can_report']['reason_code']);
        self::assertSame('Sign in to report.', $anon['can_report']['unlock_hint']);

        \ReplyWorld::$suspended[self::STRANGER] = true;
        self::assertSame('sender_restricted', $perms(self::STRANGER)['can_report']['reason_code']);

        \ReplyWorld::$suspended[self::MEMBER] = true;
        $restrictedAuthor = $perms(self::MEMBER);
        self::assertSame('sender_restricted', $restrictedAuthor['can_edit']['reason_code']);
        self::assertTrue($restrictedAuthor['can_delete']['allowed'], 'removing one\'s own words is never gated on suspension');
    }

    public function testTheCreateResponseCarriesTheAuthorsPermissions(): void
    {
        $out = self::service()->create(self::MEMBER, self::VOTE, 'hi');

        self::assertTrue($out['reply']['permissions']['can_edit']['allowed']);
        self::assertSame('own_reply', $out['reply']['permissions']['can_report']['reason_code']);
    }
}
