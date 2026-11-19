<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Integration\PeepSoIntegration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;

/**
 * F-334 (user decision) — a REVOKED claim frees the page, and the next
 * verified operator's claim takes it over, score rows included.
 *
 * Owner-0 (user decision 2026-11-17): the release moves the page's score
 * rows off the old owner — to 0, or to an admin-reassigned owner — so
 * getPageForOwner() stops resolving them to a page they lost.
 *
 * Runs the real PeepSoIntegration against tests/Stubs/page-follow-drain-stubs.php
 * (post_author, PeepSo page membership, score-row owner, the "still held"
 * read, audit rows — all in memory).
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
final class PageClaimReleaseTest extends TestCase
{
    private const PAGE = 7;
    private const OLD  = 500;
    private const NEW  = 600;

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wp/');
        }

        $GLOBALS['__bcc_drain'] = [
            'rows' => [], 'reads' => 0, 'follows' => 0, 'delete_calls' => [], 'deleted' => [],
            'by_page_id' => 0, 'meta' => [], 'info' => [], 'errors' => [], 'warnings' => [],
            'post_author' => [], 'author_reads' => 0, 'page_members' => [], 'page_writes' => [],
            'page_counts' => [], 'page_count_drops' => [], 'score_owner' => [], 'page_held' => [],
            'audits' => [],
        ];

        require_once __DIR__ . '/../Stubs/page-follow-drain-stubs.php';

        // The state a verified claim leaves (F-299): author + PeepSo owner.
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE]               = self::OLD;
        $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD] = 'member_owner';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_drain']);
        parent::tearDown();
    }

    private function integration(): PeepSoIntegration
    {
        $instance = (new ReflectionClass(PeepSoIntegration::class))->newInstanceWithoutConstructor();
        foreach (['userInfoRepo' => \BCC\Trust\Core\Repositories\UserInfoRepository::class,
                  'scoreRepo'    => \BCC\Trust\Core\Repositories\ScoreRepository::class] as $prop => $class) {
            $p = new ReflectionProperty(PeepSoIntegration::class, $prop);
            $p->setAccessible(true);
            $p->setValue($instance, new $class());
        }

        return $instance;
    }

    /**
     * The stubs' AuditLogger records keyed rows (`action`, `target`, `meta`);
     * normalised here to [action, target, meta] for terse assertions.
     *
     * @return list<array{0: string, 1: int|null, 2: array<string, mixed>}>
     */
    private static function audits(string $action): array
    {
        $out = [];
        foreach ($GLOBALS['__bcc_drain']['audits'] as $a) {
            if (($a['action'] ?? null) === $action) {
                $out[] = [$a['action'], $a['target'] ?? null, $a['meta'] ?? []];
            }
        }

        return $out;
    }

    // ── the release ─────────────────────────────────────────────────────

    public function testARevokedClaimFreesThePage(): void
    {
        self::assertTrue($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(0, $GLOBALS['__bcc_drain']['post_author'][self::PAGE], 'post_author is reset to 0');
        self::assertSame('member', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD], 'owner demoted, not removed');
        self::assertSame([[self::OLD, self::PAGE]], $GLOBALS['__bcc_drain']['page_count_drops']);

        $released = self::audits('page_owner_released');
        self::assertCount(1, $released, 'one audit row per page');
        self::assertSame(self::PAGE, $released[0][1]);
        self::assertSame(['old_owner' => self::OLD, 'author_reset' => true, 'owner_row' => 'demoted', 'score_rows_moved' => 0, 'score_owner' => 0], $released[0][2]);
    }

    // ── owner-0: the score rows leave the old owner ─────────────────────

    public function testTheReleaseMovesTheScoreRowsToNobody(): void
    {
        $GLOBALS['__bcc_drain']['score_owner'][self::PAGE] = self::OLD;

        self::assertTrue($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(0, $GLOBALS['__bcc_drain']['score_owner'][self::PAGE], 'owned by nobody, like the page');
        self::assertSame([[self::PAGE, self::OLD, 0]], $GLOBALS['__bcc_drain']['release_owner_calls'], 'guarded on the old owner');
        $meta = self::audits('page_owner_released')[0][2];
        self::assertSame(1, $meta['score_rows_moved']);
        self::assertSame(0, $meta['score_owner']);
    }

    public function testAReassignedPagesRowsFollowItsNewOwner(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE] = 999;
        $GLOBALS['__bcc_drain']['score_owner'][self::PAGE] = self::OLD;

        self::assertTrue($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(999, $GLOBALS['__bcc_drain']['score_owner'][self::PAGE]);
        self::assertSame(999, self::audits('page_owner_released')[0][2]['score_owner']);
    }

    public function testRowsAlreadyMovedByAnotherWriterAreNotUndone(): void
    {
        $GLOBALS['__bcc_drain']['score_owner'][self::PAGE] = 777; // a concurrent reassignment got there first

        self::assertTrue($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(777, $GLOBALS['__bcc_drain']['score_owner'][self::PAGE]);
        self::assertSame(0, self::audits('page_owner_released')[0][2]['score_rows_moved']);
    }

    public function testAFailedRowMoveIsAuditedAndLoggedAndTheReleaseStands(): void
    {
        $GLOBALS['__bcc_drain']['score_owner'][self::PAGE] = self::OLD;
        $GLOBALS['__bcc_drain']['release_owner_fails']     = true;

        self::assertTrue($this->integration()->releaseClaimedPage(self::PAGE, self::OLD), 'the page IS released');

        self::assertSame(0, $GLOBALS['__bcc_drain']['post_author'][self::PAGE]);
        self::assertTrue(self::audits('page_owner_released')[0][2]['score_rows_move_failed'] ?? false);
        self::assertCount(1, $GLOBALS['__bcc_drain']['errors'], 'logged, never thrown');
    }

    public function testAFailedAuthorResetMovesNoScoreRow(): void
    {
        $GLOBALS['__bcc_drain']['fail_author_write'] = true;
        $GLOBALS['__bcc_drain']['score_owner'][self::PAGE] = self::OLD;

        $this->integration()->releaseClaimedPage(self::PAGE, self::OLD);

        self::assertSame(self::OLD, $GLOBALS['__bcc_drain']['score_owner'][self::PAGE], 'the page is still theirs');
        self::assertArrayNotHasKey('release_owner_calls', $GLOBALS['__bcc_drain']);
    }

    /** A multi-chain page can still be held through another validator. */
    public function testAPageStillHeldByAnotherVerifiedClaimIsNotTouched(): void
    {
        $GLOBALS['__bcc_drain']['page_held'][self::PAGE] = true;

        self::assertFalse($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(self::OLD, $GLOBALS['__bcc_drain']['post_author'][self::PAGE]);
        self::assertSame('member_owner', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD]);
        self::assertSame([], $GLOBALS['__bcc_drain']['audits']);
    }

    /** Unknown is never "free": a failed hold read changes nothing. */
    public function testAnUnreadableHoldStateNeverFreesThePage(): void
    {
        $GLOBALS['__bcc_drain']['held_read_fails'] = true;

        self::assertFalse($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(self::OLD, $GLOBALS['__bcc_drain']['post_author'][self::PAGE]);
        self::assertSame('member_owner', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD]);
        self::assertCount(1, $GLOBALS['__bcc_drain']['errors'], 'logged, never thrown');
    }

    /** An admin reassignment since the claim is not undone by a release. */
    public function testAPageReassignedSinceKeepsItsNewAuthor(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE] = 999;

        self::assertTrue($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(999, $GLOBALS['__bcc_drain']['post_author'][self::PAGE]);
        self::assertSame([], $GLOBALS['__bcc_drain']['page_count_drops']);
        self::assertSame('member', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD], 'their owner row still goes');
        self::assertFalse(self::audits('page_owner_released')[0][2]['author_reset']);
    }

    // ── failure order: the page is never half-freed ────────────────────

    /**
     * The demotion runs FIRST. When it fails, `post_author` was never
     * touched, so the next claimer is refused (the page still has an author)
     * instead of becoming its author while the old owner row keeps PeepSo
     * ownership.
     */
    public function testAFailedDemotionLeavesThePageFullyOwned(): void
    {
        $GLOBALS['__bcc_drain']['fail_modify'] = true;
        $integration = $this->integration();

        self::assertFalse($integration->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(self::OLD, $GLOBALS['__bcc_drain']['post_author'][self::PAGE], 'post_author is never reset after a failed demotion');
        self::assertSame('member_owner', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD]);
        self::assertSame([], $GLOBALS['__bcc_drain']['page_count_drops']);
        self::assertSame([], self::audits('page_owner_released'), 'nothing changed, so there is no release to record');
        self::assertCount(1, $GLOBALS['__bcc_drain']['errors'], 'logged, never thrown');

        $GLOBALS['__bcc_drain']['fail_modify'] = false;
        $integration->onPageClaimed(self::NEW, self::PAGE);

        self::assertSame(self::OLD, $GLOBALS['__bcc_drain']['post_author'][self::PAGE], 'the next claimer does not become the author');
        self::assertArrayNotHasKey(self::NEW, $GLOBALS['__bcc_drain']['page_members'][self::PAGE], 'and gets no owner row');
    }

    /**
     * The other order: the demotion lands, then the author reset fails.
     * `post_author` stays on the old owner, so the next claimer is still
     * refused, and the demotion that did happen is audited.
     */
    public function testAFailedAuthorResetAfterTheDemotionStillNeverSplitsThePage(): void
    {
        $GLOBALS['__bcc_drain']['fail_author_write'] = true;
        $integration = $this->integration();

        self::assertFalse($integration->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(self::OLD, $GLOBALS['__bcc_drain']['post_author'][self::PAGE]);
        self::assertSame('member', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD], 'the demotion ran first');
        self::assertSame([], $GLOBALS['__bcc_drain']['page_count_drops'], 'no count drop without the author reset');
        $released = self::audits('page_owner_released');
        self::assertCount(1, $released);
        self::assertSame(
            ['old_owner' => self::OLD, 'author_reset' => false, 'owner_row' => 'demoted', 'author_reset_failed' => true],
            $released[0][2]
        );
        self::assertCount(1, $GLOBALS['__bcc_drain']['errors']);

        $integration->onPageClaimed(self::NEW, self::PAGE);

        self::assertSame(self::OLD, $GLOBALS['__bcc_drain']['post_author'][self::PAGE], 'the next claimer does not become the author');
        self::assertArrayNotHasKey(self::NEW, $GLOBALS['__bcc_drain']['page_members'][self::PAGE], 'and gets no owner row');
    }

    // ── F-360: several rows for the old owner (no unique (page, user) key)

    /**
     * Deciding on PeepSo's one-row `is_owner`: [member_owner, banned] read as
     * owner, and the demotion (which rewrites EVERY row of the pair) lifted
     * the ban.
     */
    public function testAnOwnerRowAheadOfABanIsLeftForManualReview(): void
    {
        $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD] = ['member_owner', 'banned'];

        self::assertFalse($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(['member_owner', 'banned'], $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD], 'the ban is never lifted');
        self::assertSame(self::OLD, $GLOBALS['__bcc_drain']['post_author'][self::PAGE], 'and the page is not half-freed');
        self::assertSame([], $GLOBALS['__bcc_drain']['page_writes']);
        self::assertSame([['page_owner_release_skipped', self::PAGE, ['old_owner' => self::OLD, 'reason' => 'banned']]], self::audits('page_owner_release_skipped'));
        self::assertSame([], self::audits('page_owner_released'));
    }

    /** ... and [banned, member_owner] read as NOT owner: the author was reset while the owner row stayed. */
    public function testABanAheadOfAnOwnerRowIsLeftForManualReview(): void
    {
        $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD] = ['banned', 'member_owner'];

        self::assertFalse($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(['banned', 'member_owner'], $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD]);
        self::assertSame(self::OLD, $GLOBALS['__bcc_drain']['post_author'][self::PAGE], 'no author reset beside a leftover owner row');
        self::assertSame([], $GLOBALS['__bcc_drain']['page_count_drops']);
        self::assertCount(1, self::audits('page_owner_release_skipped'));
    }

    /** No ban: an owner row behind an older `member` row is still found and demoted. */
    public function testAnOwnerRowBehindAMemberRowIsStillDemoted(): void
    {
        $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD] = ['member', 'member_owner'];

        self::assertTrue($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(['member', 'member'], $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD], 'no owner row is left behind');
        self::assertSame(0, $GLOBALS['__bcc_drain']['post_author'][self::PAGE]);
        self::assertSame('demoted', self::audits('page_owner_released')[0][2]['owner_row']);
    }

    public function testAnUnreadableOwnerStatusNeverFreesThePage(): void
    {
        $GLOBALS['__bcc_drain']['status_read_fails'] = true;

        self::assertFalse($this->integration()->releaseClaimedPage(self::PAGE, self::OLD));

        self::assertSame(self::OLD, $GLOBALS['__bcc_drain']['post_author'][self::PAGE]);
        self::assertSame('member_owner', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD]);
        self::assertSame([], $GLOBALS['__bcc_drain']['page_writes']);
        self::assertSame([], $GLOBALS['__bcc_drain']['audits']);
        self::assertCount(1, $GLOBALS['__bcc_drain']['errors'], 'logged, never thrown');
    }

    // ── the takeover ────────────────────────────────────────────────────

    public function testTheNextOperatorsClaimTakesThePageOverIncludingTheScoreRow(): void
    {
        $GLOBALS['__bcc_drain']['score_owner'][self::PAGE] = self::OLD; // history under the old owner
        $integration = $this->integration();

        self::assertTrue($integration->releaseClaimedPage(self::PAGE, self::OLD));
        self::assertSame(0, $GLOBALS['__bcc_drain']['score_owner'][self::PAGE], 'released to nobody');
        $integration->onPageClaimed(self::NEW, self::PAGE);

        self::assertSame(self::NEW, $GLOBALS['__bcc_drain']['post_author'][self::PAGE], 'the new operator is the author');
        self::assertSame('member_owner', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::NEW], 'and the PeepSo owner');
        self::assertSame('member', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::OLD]);
        self::assertSame(self::NEW, $GLOBALS['__bcc_drain']['score_owner'][self::PAGE], 'the score rows move to the new owner');

        $changed = self::audits('page_owner_changed');
        self::assertCount(1, $changed);
        self::assertSame(['old_owner' => 0, 'new_owner' => self::NEW, 'via' => 'claim_after_release'], $changed[0][2]);
    }

    /** A row whose release move failed is still keyed to the old owner: the claim takes it from there. */
    public function testAClaimTakesRowsStillKeyedToTheOldOwner(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE] = 0;
        $GLOBALS['__bcc_drain']['score_owner'][self::PAGE] = self::OLD;

        $this->integration()->onPageClaimed(self::NEW, self::PAGE);

        self::assertSame(self::NEW, $GLOBALS['__bcc_drain']['score_owner'][self::PAGE]);
        self::assertSame(self::OLD, self::audits('page_owner_changed')[0][2]['old_owner']);
    }

    // ── handleOwnerChange (an admin edit of post_author) ────────────────

    private function ownerChange(int $newOwner): void
    {
        $m = new \ReflectionMethod(PeepSoIntegration::class, 'handleOwnerChange');
        $m->setAccessible(true);
        $m->invoke($this->integration(), self::PAGE, $newOwner);
    }

    public function testAnAdminAssignedOwnerTakesAReleasedPagesRows(): void
    {
        $GLOBALS['__bcc_drain']['score_owner'][self::PAGE] = 0;

        $this->ownerChange(self::NEW);

        self::assertSame(self::NEW, $GLOBALS['__bcc_drain']['score_owner'][self::PAGE]);
        self::assertSame([[self::NEW, self::PAGE]], $GLOBALS['__bcc_drain']['page_counts'], 'counted for the new owner');
        self::assertSame([], $GLOBALS['__bcc_drain']['page_count_drops'], 'nobody to drop');
        self::assertSame(['old_owner' => 0, 'new_owner' => self::NEW], self::audits('page_owner_changed')[0][2]);
    }

    public function testAPageWithNoScoreRowHasNothingToMove(): void
    {
        $this->ownerChange(self::NEW);

        self::assertArrayNotHasKey(self::PAGE, $GLOBALS['__bcc_drain']['score_owner']);
        self::assertSame([], self::audits('page_owner_changed'));
    }

    public function testAnOwnerToOwnerChangeTransfersTheCount(): void
    {
        $GLOBALS['__bcc_drain']['score_owner'][self::PAGE] = self::OLD;

        $this->ownerChange(self::NEW);

        self::assertSame(self::NEW, $GLOBALS['__bcc_drain']['score_owner'][self::PAGE]);
        self::assertSame([[self::OLD, self::PAGE]], $GLOBALS['__bcc_drain']['page_count_drops']);
        self::assertSame([[self::NEW, self::PAGE]], $GLOBALS['__bcc_drain']['page_counts']);
    }

    public function testAnOwnerRemovedByAnAdminLeavesTheRowsWithNobody(): void
    {
        $GLOBALS['__bcc_drain']['score_owner'][self::PAGE] = self::OLD;

        $this->ownerChange(0);

        self::assertSame(0, $GLOBALS['__bcc_drain']['score_owner'][self::PAGE]);
        self::assertSame([[self::OLD, self::PAGE]], $GLOBALS['__bcc_drain']['page_count_drops']);
        self::assertSame([], $GLOBALS['__bcc_drain']['page_counts'], 'nobody gains a page');
    }

    public function testAFirstEverClaimMovesNoScoreRow(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE] = 0; // a never-claimed placeholder: no score row

        $this->integration()->onPageClaimed(self::NEW, self::PAGE);

        self::assertArrayNotHasKey(self::PAGE, $GLOBALS['__bcc_drain']['score_owner']);
        self::assertSame([], self::audits('page_owner_changed'));
    }
}
