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
 * F-299 — a verified claim makes the claimer the page's PeepSo OWNER.
 *
 * onPageClaimed() moved `post_author` and nothing else, and no code in
 * bcc-trust or bcc-core wrote a `peepso_page_members` `member_owner` row. But
 * every directory and card owner signal reads that row (the /members
 * VALIDATORS filter and its chip count, owned-page counts on member cards), so
 * a verified operator never appeared as the owner of the page they claimed.
 *
 * The real PeepSoIntegration runs against tests/Stubs/page-follow-drain-stubs.php,
 * whose PeepSoPageUser models PeepSo's own join/modify semantics in memory.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
final class PageClaimOwnerMembershipTest extends TestCase
{
    private const PAGE = 7;
    private const USER = 500;

    protected function setUp(): void
    {
        parent::setUp();

        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wp/');
        }

        $GLOBALS['__bcc_drain'] = [
            'rows'         => [],
            'reads'        => 0,
            'follows'      => 0,
            'delete_calls' => [],
            'deleted'      => [],
            'by_page_id'   => 0,
            'meta'         => [],
            'info'         => [],
            'errors'       => [],
            'warnings'     => [],
            'post_author'  => [],
            'author_reads' => 0,
            'page_members' => [],
            'page_writes'  => [],
            'page_counts'  => [],
        ];

        require_once __DIR__ . '/../Stubs/page-follow-drain-stubs.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_drain']);
        parent::tearDown();
    }

    private function integration(): PeepSoIntegration
    {
        $instance = (new ReflectionClass(PeepSoIntegration::class))->newInstanceWithoutConstructor();
        $prop = new ReflectionProperty(PeepSoIntegration::class, 'userInfoRepo');
        $prop->setAccessible(true);
        $prop->setValue($instance, new \BCC\Trust\Core\Repositories\UserInfoRepository());

        return $instance;
    }

    private function ownerStatus(): string|false
    {
        return $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::USER] ?? false;
    }

    // ── a fresh claim ───────────────────────────────────────────────────

    public function testAClaimOfAnUnownedPageWritesTheOwnerRow(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE] = 0;

        $this->integration()->onPageClaimed(self::USER, self::PAGE);

        self::assertSame(self::USER, $GLOBALS['__bcc_drain']['post_author'][self::PAGE], 'authorship still transfers');
        self::assertSame('member_owner', $this->ownerStatus(), 'and the claimer is now the PeepSo owner');
        self::assertSame(
            [['join', self::PAGE, self::USER], ['modify', self::PAGE, self::USER, 'member_owner']],
            $GLOBALS['__bcc_drain']['page_writes'],
            "PeepSo's own join + promote, exactly as PeepSoPage::create() does for a creator"
        );
        self::assertSame([[self::USER, self::PAGE]], $GLOBALS['__bcc_drain']['page_counts']);
        self::assertSame([], $GLOBALS['__bcc_drain']['errors']);
    }

    public function testAClaimerWhoAlreadyAuthorsThePageGetsAMissingOwnerRowHealed(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE] = self::USER;

        $this->integration()->onPageClaimed(self::USER, self::PAGE);

        self::assertSame('member_owner', $this->ownerStatus());
        self::assertSame([], $GLOBALS['__bcc_drain']['page_counts'], 'no second page-count bump');
    }

    /** No automatic transfer, by design — and no owner row for a non-author either. */
    public function testANonAuthorClaimerIsNotMadeOwner(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE] = 999;

        $this->integration()->onPageClaimed(self::USER, self::PAGE);

        self::assertFalse($this->ownerStatus());
        self::assertSame([], $GLOBALS['__bcc_drain']['page_writes']);
        self::assertCount(1, $GLOBALS['__bcc_drain']['warnings'], 'the mismatch is reported, as before');
    }

    // ── the resubmitted-claim path heals pre-F-299 claims ──────────────

    public function testTheDrainRetryHealsAMissingOwnerRowAndStillDrains(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE] = self::USER;

        $this->integration()->onClaimFollowersDrain(self::USER, self::PAGE);

        self::assertSame('member_owner', $this->ownerStatus());
        self::assertSame(1, $GLOBALS['__bcc_drain']['reads'], 'the follower drain still runs');
    }

    public function testAnExistingOwnerIsLeftAlone(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE]                = self::USER;
        $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::USER] = 'member_owner';

        $this->integration()->onClaimFollowersDrain(self::USER, self::PAGE);

        self::assertSame([], $GLOBALS['__bcc_drain']['page_writes'], 'idempotent: no write at all');
        self::assertSame('member_owner', $this->ownerStatus());
    }

    /** A join on an existing row would rewrite it to plain `member` first; promote it directly. */
    public function testAnExistingRowIsPromotedWithoutAJoin(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE]                = self::USER;
        $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::USER] = 'member';

        $this->integration()->onClaimFollowersDrain(self::USER, self::PAGE);

        self::assertSame([['modify', self::PAGE, self::USER, 'member_owner']], $GLOBALS['__bcc_drain']['page_writes']);
        self::assertSame('member_owner', $this->ownerStatus());
    }

    // ── failure is logged, never thrown ─────────────────────────────────

    public function testAFailedPromotionIsLoggedAndTheDrainStillRuns(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE] = self::USER;
        $GLOBALS['__bcc_drain']['fail_modify']            = true;

        $this->integration()->onClaimFollowersDrain(self::USER, self::PAGE);

        self::assertNotSame('member_owner', $this->ownerStatus());
        self::assertCount(1, $GLOBALS['__bcc_drain']['errors']);
        self::assertStringContainsString('member_owner', $GLOBALS['__bcc_drain']['errors'][0]['message']);
        self::assertSame(1, $GLOBALS['__bcc_drain']['reads'], 'a failed owner write never blocks the drain');
    }

    public function testAFailedJoinIsLoggedAndNothingIsPromoted(): void
    {
        $GLOBALS['__bcc_drain']['fail_join'] = true;

        self::assertFalse(PeepSoIntegration::ensurePageOwnerMembership(self::PAGE, self::USER));
        self::assertSame([['join', self::PAGE, self::USER]], $GLOBALS['__bcc_drain']['page_writes']);
        self::assertCount(1, $GLOBALS['__bcc_drain']['errors']);
    }

    public function testNonPositiveIdsWriteNothing(): void
    {
        self::assertFalse(PeepSoIntegration::ensurePageOwnerMembership(0, self::USER));
        self::assertFalse(PeepSoIntegration::ensurePageOwnerMembership(self::PAGE, 0));
        self::assertSame([], $GLOBALS['__bcc_drain']['page_writes']);
    }
// ── F-299 live guard: the backfill's decision, on the claim path ──────

    public function testABannedClaimerStaysBannedAndIsFlaggedForReview(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE]                = self::USER;
        $GLOBALS['__bcc_drain']['page_members'][self::PAGE][self::USER] = 'banned';

        $this->integration()->onClaimFollowersDrain(self::USER, self::PAGE);

        self::assertSame('banned', $this->ownerStatus(), 'a claim never lifts a ban');
        self::assertSame([], $GLOBALS['__bcc_drain']['page_writes'] ?? [], 'no join, no promote');
        self::assertSame(
            [['action' => 'page_owner_membership_skipped', 'target' => self::PAGE, 'meta' => ['user_id' => self::USER, 'reason' => 'skip_banned', 'other_owner' => 0]]],
            $GLOBALS['__bcc_drain']['audits'] ?? [],
            'recorded in the admin audit log for manual review'
        );
        self::assertNotSame([], $GLOBALS['__bcc_drain']['warnings'] ?? [], 'and logged');
    }

    public function testAPageWithADifferentOwnerNeverGetsASecondOne(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE]           = self::USER;
        $GLOBALS['__bcc_drain']['page_members'][self::PAGE][900]     = 'member_owner';

        $this->integration()->onClaimFollowersDrain(self::USER, self::PAGE);

        self::assertFalse($this->ownerStatus(), 'the claimer gets no row at all');
        self::assertSame('member_owner', $GLOBALS['__bcc_drain']['page_members'][self::PAGE][900], 'the existing owner is untouched');
        self::assertSame([], $GLOBALS['__bcc_drain']['page_writes'] ?? []);
        self::assertSame('skip_other_owner', $GLOBALS['__bcc_drain']['audits'][0]['meta']['reason'] ?? null);
        self::assertSame(900, $GLOBALS['__bcc_drain']['audits'][0]['meta']['other_owner'] ?? null);
    }

    public function testAnUnreadableOwnerCheckWritesNothing(): void
    {
        $GLOBALS['__bcc_drain']['post_author'][self::PAGE] = self::USER;
        $GLOBALS['__bcc_drain']['owner_read_fails']        = true;

        $this->integration()->onClaimFollowersDrain(self::USER, self::PAGE);

        self::assertFalse($this->ownerStatus(), 'never risk a second owner on a failed read');
        self::assertSame([], $GLOBALS['__bcc_drain']['page_writes'] ?? []);
        self::assertSame(
            ['[bcc-trust] page owner membership not written - owner check failed'],
            array_column($GLOBALS['__bcc_drain']['errors'] ?? [], 'message'),
            'refused by the owner-check guard itself, not by a later error'
        );
    }

    /** The one decision both paths use (the backfill's plan() reads it too). */
    public function testTheSharedDecision(): void
    {
        $d = \BCC\Trust\Core\Support\PageOwnerDecision::class;

        self::assertSame($d::JOIN, $d::decide(null, 0));
        self::assertSame($d::PROMOTE, $d::decide('member', 0));
        self::assertSame($d::SKIP_BANNED, $d::decide('banned', 0));
        self::assertSame($d::SKIP_OTHER_OWNER, $d::decide('member', 900));
        self::assertSame($d::SKIP_OTHER_OWNER, $d::decide('banned', 900), 'another owner wins over a ban, as plan() always did');
        self::assertSame($d::ALREADY_OWNER, $d::decide('member_owner', 0));
        self::assertTrue($d::isSkip($d::SKIP_BANNED) && $d::isSkip($d::SKIP_OTHER_OWNER));
        self::assertFalse($d::isSkip($d::JOIN) || $d::isSkip($d::PROMOTE));
    }

    public function testTheBackfillPlanUsesTheSharedDecision(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/CLI/BackfillPageOwnersCommand.php');

        self::assertStringContainsString('PageOwnerDecision::decide($row[\'current_role\'], $row[\'other_owner\'])', $src);
    }
}
