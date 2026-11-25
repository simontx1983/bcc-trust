<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Services\PageOwnershipAligner as A;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The page ownership aligner against security's rules: the desired owner is
 * read fresh and fail-closed; promotion and release go through the existing
 * writers; an author the claims do not name is never overwritten; the
 * departing author is re-checked before a release; one run per page; a page
 * not yet aligned stays queued for the sweep.
 */
#[CoversClass(A::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class PageOwnershipAlignerTest extends TestCase
{
    private const PAGE = 501;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/page-ownership-aligner-stubs.php';
        \AlignWorld::reset();
        A::schedule(self::PAGE); // queued, as after a committed change
    }

    public function testAnUnownedPageIsPromotedToTheClaimedOwner(): void
    {
        \AlignWorld::$desired = ['state' => 'RESOLVED', 'user_id' => 5];

        self::assertSame('aligned', A::align(self::PAGE));
        self::assertSame(['claimed:501:5'], \AlignWorld::$calls);
        self::assertSame(5, \AlignWorld::$author[self::PAGE]);
        self::assertSame('owner_aligned', \AlignWorld::$logRows[0]['action']);
        self::assertSame([], A::pending(), 'no longer queued');
    }

    public function testAnAlignedPageIsANoOpButTheOwnerRowIsEnsured(): void
    {
        \AlignWorld::$desired           = ['state' => 'RESOLVED', 'user_id' => 5];
        \AlignWorld::$author[self::PAGE] = 5;

        self::assertSame('noop', A::align(self::PAGE));
        self::assertSame(['claimed:501:5'], \AlignWorld::$calls);
        self::assertSame([], \AlignWorld::$logRows);
        self::assertSame([], A::pending());
    }

    public function testAnAuthorTheClaimsDoNotNameIsNeverOverwritten(): void
    {
        \AlignWorld::$desired           = ['state' => 'RESOLVED', 'user_id' => 5];
        \AlignWorld::$author[self::PAGE] = 9; // a manual reassignment, or a swap this step does not make

        self::assertSame('misaligned', A::align(self::PAGE));
        self::assertSame([], \AlignWorld::$calls, 'nothing touched');
        self::assertSame(9, \AlignWorld::$author[self::PAGE]);
        self::assertContains('validator_relationships/page_owner_misaligned', \AlignWorld::$metrics);
        self::assertSame([self::PAGE], A::pending(), 'still queued and listed for the health view');
    }

    /** A wallet proof superseded the staff-approved author: the page moves to the new owner. */
    public function testADepartedOwnerIsSwappedToTheNewOwner(): void
    {
        \AlignWorld::$desired           = ['state' => 'RESOLVED', 'user_id' => 5];
        \AlignWorld::$author[self::PAGE] = 9;
        \AlignWorld::$hadRevoked        = true;

        self::assertSame('aligned', A::align(self::PAGE));
        self::assertSame(['swap:501:9:5'], \AlignWorld::$calls);
        self::assertSame(5, \AlignWorld::$author[self::PAGE]);
        self::assertSame('owner_aligned', \AlignWorld::$logRows[0]['action']);
        self::assertSame([], A::pending());
    }

    public function testAnAuthorWhoseAccountIsGoneIsSwapped(): void
    {
        \AlignWorld::$desired           = ['state' => 'RESOLVED', 'user_id' => 5];
        \AlignWorld::$author[self::PAGE] = 9;
        \AlignWorld::$deletedUsers      = [9];

        self::assertSame('aligned', A::align(self::PAGE));
        self::assertSame(['swap:501:9:5'], \AlignWorld::$calls);
    }

    public function testAnAuthorWhoStillHoldsThePageIsNeverSwapped(): void
    {
        \AlignWorld::$desired           = ['state' => 'RESOLVED', 'user_id' => 5];
        \AlignWorld::$author[self::PAGE] = 9;
        \AlignWorld::$hadRevoked        = true;
        \AlignWorld::$holds             = true;

        self::assertSame('misaligned', A::align(self::PAGE));
        self::assertSame([], \AlignWorld::$calls);
        self::assertSame(9, \AlignWorld::$author[self::PAGE]);
    }

    /** A failed existence read is not "the account is gone". */
    public function testAnUnreadableAccountNeverSwaps(): void
    {
        \AlignWorld::$desired            = ['state' => 'RESOLVED', 'user_id' => 5];
        \AlignWorld::$author[self::PAGE]  = 9;
        \AlignWorld::$existenceReadFails = true;

        self::assertSame('unknown', A::align(self::PAGE));
        self::assertSame([], \AlignWorld::$calls);
    }

    /** The pending list is updated only under its own lock (never a lost update). */
    public function testThePendingListIsNotRewrittenWithoutItsLock(): void
    {
        \AlignWorld::$busyLocks = ['bcc_validator_pages_to_align'];

        A::schedule(777);

        self::assertSame([self::PAGE], A::pending(), 'nothing written while another writer holds the list');
        self::assertContains([A::HOOK, [777]], \AlignWorld::$scheduled, 'the page\'s own single event still runs');
    }

    public function testAnUnreadableDepartureNeverSwaps(): void
    {
        \AlignWorld::$desired           = ['state' => 'RESOLVED', 'user_id' => 5];
        \AlignWorld::$author[self::PAGE] = 9;
        \AlignWorld::$hadRevoked        = null;

        self::assertSame('unknown', A::align(self::PAGE));
        self::assertSame([], \AlignWorld::$calls);
        self::assertContains('validator_relationships/page_owner_unknown', \AlignWorld::$metrics);
    }

    public function testASkippedSwapIsReportedAndStaysQueued(): void
    {
        \AlignWorld::$desired           = ['state' => 'RESOLVED', 'user_id' => 5];
        \AlignWorld::$author[self::PAGE] = 9;
        \AlignWorld::$hadRevoked        = true;
        \AlignWorld::$swapSkips         = true;

        self::assertSame('misaligned', A::align(self::PAGE));
        self::assertSame(['swap:501:9:5'], \AlignWorld::$calls);
        self::assertSame(9, \AlignWorld::$author[self::PAGE]);
        self::assertSame([self::PAGE], A::pending());
    }

    public function testAPageWithNoOwnerLeftIsReleased(): void
    {
        \AlignWorld::$author[self::PAGE] = 5;

        self::assertSame('aligned', A::align(self::PAGE));
        self::assertSame(['release:501:5', 'bonus_clear:501'], \AlignWorld::$calls);
        self::assertSame(0, \AlignWorld::$author[self::PAGE]);
    }

    public function testTheDepartingAuthorIsReCheckedBeforeARelease(): void
    {
        \AlignWorld::$author[self::PAGE] = 5;
        \AlignWorld::$holds               = true; // still holds the page through another claim

        self::assertSame('noop', A::align(self::PAGE));
        self::assertSame([], \AlignWorld::$calls);

        \AlignWorld::$holds = null; // the re-check cannot be read
        A::schedule(self::PAGE);
        self::assertSame('unknown', A::align(self::PAGE));
        self::assertSame([], \AlignWorld::$calls, 'unknown never demotes');
        self::assertSame([self::PAGE], A::pending());
    }

    public function testAnUnreadableOrAmbiguousPageDoesNothing(): void
    {
        \AlignWorld::$author[self::PAGE] = 5;
        foreach ([null, ['state' => 'AMBIGUOUS', 'user_id' => null]] as $desired) {
            \AlignWorld::$desired = $desired;
            self::assertSame('unknown', A::align(self::PAGE));
        }
        self::assertSame([], \AlignWorld::$calls);
        self::assertSame(5, \AlignWorld::$author[self::PAGE]);
        self::assertContains('validator_relationships/page_owner_unknown', \AlignWorld::$metrics);
    }

    public function testASkippedReleaseStaysQueued(): void
    {
        \AlignWorld::$author[self::PAGE] = 5;
        \AlignWorld::$releaseSkips       = true; // e.g. banned on the page (F-360)

        self::assertSame('misaligned', A::align(self::PAGE));
        self::assertSame(['release:501:5'], \AlignWorld::$calls, 'no bonus clear for a page still owned');
        self::assertSame([self::PAGE], A::pending());
    }

    public function testOneRunPerPage(): void
    {
        \AlignWorld::$lockFree = false;
        \AlignWorld::$desired  = ['state' => 'RESOLVED', 'user_id' => 5];

        self::assertSame('locked', A::align(self::PAGE));
        self::assertSame([], \AlignWorld::$calls);
        self::assertSame([self::PAGE], A::pending());
    }

    public function testSchedulingQueuesOnceAndTheQueueIsBounded(): void
    {
        self::assertSame([[A::HOOK, [self::PAGE]]], \AlignWorld::$scheduled);
        A::schedule(self::PAGE);
        self::assertCount(1, \AlignWorld::$scheduled, 'one pending event per page');

        for ($p = 1000; $p < 1000 + A::MAX_PENDING + 5; $p++) {
            A::schedule($p);
        }
        self::assertCount(A::MAX_PENDING, A::pending());
        self::assertContains('validator_relationships/align_backlog_full', \AlignWorld::$metrics);
    }

    public function testTheSweepAlignsABoundedBatch(): void
    {
        \AlignWorld::$options[A::PENDING] = []; // only the pages below
        for ($p = 2000; $p < 2000 + A::SWEEP_BATCH + 10; $p++) {
            A::schedule($p);
            \AlignWorld::$author[$p] = 7;
        }
        \AlignWorld::$calls = [];

        A::sweep();

        self::assertCount(A::SWEEP_BATCH, array_filter(\AlignWorld::$calls, static fn (string $c): bool => str_starts_with($c, 'release:')));
    }
}
