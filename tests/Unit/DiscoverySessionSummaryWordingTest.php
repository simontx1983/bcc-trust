<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\DiscoveryScanProgress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The PR 7.4 wording: what a SESSION did, versus what the CHAIN knows.
 *
 * ── THE SENTENCE THAT CONTRADICTED THE PANEL AROUND IT ──────────────────
 * The 2026-09-06 Cosmos Hub session emitted two collection records and left
 * five CW-721 families confirmed. The panel printed, in this order:
 *
 *   Found 2 new collection(s) from 1,248 contract(s) examined.
 *   5 NFT collection families confirmed so far
 *   … No NFT collections were confirmed in this pass.
 *
 * The last line was hardcoded. It had been true for as long as discovery
 * found nothing, and became a flat contradiction the first time it worked.
 *
 * ── THREE NUMBERS THAT ARE NOT THE SAME NUMBER ──────────────────────────
 * Two of the three cannot be derived from the other:
 *
 *   emitted (2)  — collection ROWS this session stored. Run ledger.
 *   confirmed(5) — collection FAMILIES the chain knows about. Derived from
 *                  classification, across every session that ever ran.
 *   remaining    — families still to review. Derived.
 *
 * Emission is separately bounded, so `confirmed >= emitted` routinely. Every
 * test below exists to stop one of them standing in for another.
 */
#[CoversClass(DiscoveryScanProgress::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DiscoverySessionSummaryWordingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/discovery-progress-stubs.php';
    }

    /**
     * The live 2026-09-06 session, exactly as the read model reported it.
     *
     * @return array<string, mixed>
     */
    private static function live(): array
    {
        return [
            'ok'                   => true,
            'chain_id'             => 8,
            'enumeration_complete' => DiscoveryScanProgress::YES,
            'total_families'       => 742,
            'classified_families'  => 365,
            'remaining_families'   => 377,
            'collection_families'  => 5,
            'eligible_now'         => 377,
            'delayed_families'     => 0,
            'exhausted_families'   => 0,
            'negative_families'    => 105,
            'scan_complete'        => DiscoveryScanProgress::NO,
            'more_work_available'  => DiscoveryScanProgress::YES,
            'reason'               => '',
        ];
    }

    // ── (7)(8)(9)(10) THE LIVE REGRESSION, IN WORDS ─────────────────────

    /**
     * ⚠ THE REGRESSION. All four facts in one sentence, none of them
     * standing in for another.
     */
    public function testTheLiveSessionSentenceReportsAddedConfirmedAndRemaining(): void
    {
        $s = DiscoveryScanProgress::summarySentence(self::live(), 2);

        self::assertSame(
            'This session added 2 new collection records. '
            . 'Overall, 5 NFT collection families are confirmed so far. '
            . 'Checked 365 of 742 contract families; 377 still need review.',
            $s
        );
    }

    /** The contradictory sentence is gone, in every phrasing of the claim. */
    public function testTheContradictoryZeroSentenceIsAbsent(): void
    {
        $s = DiscoveryScanProgress::summarySentence(self::live(), 2);

        self::assertStringNotContainsString('No NFT collections were confirmed in this pass', $s);
        self::assertStringNotContainsString('did not confirm', $s);
        self::assertStringNotContainsString('No supported NFT collections', $s);
        self::assertStringNotContainsString('Scan complete', $s);
    }

    /**
     * ⚠ FIVE CONFIRMED FAMILIES ARE NOT FIVE SAVED COLLECTIONS.
     *
     * The one substitution that would look right and read plausibly: using
     * the chain's confirmed-family count as the session's emitted count.
     */
    public function testConfirmedFamiliesAreNeverReportedAsAddedRecords(): void
    {
        $s = DiscoveryScanProgress::summarySentence(self::live(), 2);

        self::assertStringContainsString('added 2 new collection records', $s);
        self::assertStringNotContainsString('added 5', $s);
        self::assertStringNotContainsString('5 new collection', $s);
        self::assertStringContainsString('5 NFT collection families are confirmed', $s);
    }

    /** Work remains, so the exact counts and the Continue offer stay. */
    public function testWorkRemainingKeepsExactCountsAndContinue(): void
    {
        $p = self::live();

        self::assertStringContainsString('377 still need review', DiscoveryScanProgress::summarySentence($p, 2));
        self::assertSame('Continue scan', DiscoveryScanProgress::actionLabel($p));
    }

    // ── (11)(12) NOTHING EMITTED, SOMETHING CONFIRMED ───────────────────

    /**
     * A session that added no row, on a chain that already has confirmed
     * families, says exactly that — and never that the chain has no NFTs.
     */
    public function testZeroEmittedWithExistingConfirmedFamiliesIsHonest(): void
    {
        $s = DiscoveryScanProgress::summarySentence(self::live(), 0);

        self::assertSame(
            'This session added no new collection record. '
            . 'Overall, 5 NFT collection families are confirmed so far. '
            . 'Checked 365 of 742 contract families; 377 still need review.',
            $s
        );
    }

    /**
     * ⚠ CONFIRMED THIS SESSION, EMISSION DEFERRED.
     *
     * Emission is its own bounded stage: a family can be confirmed in the
     * chunk that ran out of budget, with its collection row written later.
     * The sentence must credit the confirmation without claiming a record
     * was stored — the two halves are asserted separately because the defect
     * would be to merge them.
     */
    public function testAConfirmedFamilyWithDeferredEmissionIsDistinguished(): void
    {
        $p = self::live();
        $p['collection_families'] = 1;

        $s = DiscoveryScanProgress::summarySentence($p, 0);

        self::assertStringContainsString('added no new collection record', $s);
        self::assertStringContainsString('1 NFT collection family is confirmed so far', $s);

        // Not a single phrasing that would imply a stored record.
        self::assertStringNotContainsString('added 1', $s);
        self::assertStringNotContainsString('saved', $s);
    }

    // ── (13) NOTHING EMITTED, NOTHING CONFIRMED ─────────────────────────

    /**
     * ⚠ SCOPED TO THE SESSION, AND ONLY THE SESSION.
     *
     * With 377 families never examined, "this chain has no NFT collections"
     * is the exact claim this class exists to make unsayable.
     */
    public function testZeroEmittedAndZeroConfirmedIsScopedToTheSession(): void
    {
        $p = self::live();
        $p['collection_families'] = 0;

        $s = DiscoveryScanProgress::summarySentence($p, 0);

        self::assertSame(
            'This session did not confirm a new NFT collection. '
            . 'Checked 365 of 742 contract families; 377 still need review.',
            $s
        );

        foreach (['this chain has no', 'no NFT collections exist', 'Scan complete'] as $overreach) {
            self::assertStringNotContainsStringIgnoringCase($overreach, $s);
        }
    }

    /**
     * ⚠ NULL IS NOT ZERO. With no session to speak for — a chain that has
     * never been scanned — the sentence says nothing about a session rather
     * than reporting one that added nothing.
     */
    public function testNoSessionMeansNoSessionClaim(): void
    {
        $p = self::live();
        $p['collection_families'] = 0;

        $s = DiscoveryScanProgress::summarySentence($p);

        self::assertStringNotContainsString('This session', $s);
        self::assertStringContainsString('No NFT collection family is confirmed on this chain yet.', $s);
        self::assertStringContainsString('377 still need review', $s);
    }

    // ── (14) SINGULAR AND PLURAL ────────────────────────────────────────

    /**
     * @param int    $emitted   collection rows the session added
     * @param int    $confirmed collection families the chain knows
     */
    #[DataProvider('grammarCases')]
    public function testSingularAndPluralWording(int $emitted, int $confirmed, string $expected): void
    {
        $p = self::live();
        $p['collection_families'] = $confirmed;

        self::assertStringContainsString($expected, DiscoveryScanProgress::summarySentence($p, $emitted));
    }

    /** @return array<string, array{int, int, string}> */
    public static function grammarCases(): array
    {
        return [
            'one record added'      => [1, 5, 'This session added 1 new collection record.'],
            'two records added'     => [2, 5, 'This session added 2 new collection records.'],
            'one family confirmed'  => [1, 1, 'Overall, 1 NFT collection family is confirmed so far.'],
            'two families confirmed' => [1, 2, 'Overall, 2 NFT collection families are confirmed so far.'],
            'thousands are grouped'  => [1200, 2000, 'This session added 1,200 new collection records.'],
        ];
    }

    /** ⚠ The plural forms must not appear for a count of one. */
    public function testSingularNeverRendersAPluralForm(): void
    {
        $p = self::live();
        $p['collection_families'] = 1;

        $s = DiscoveryScanProgress::summarySentence($p, 1);

        self::assertStringNotContainsString('1 new collection records', $s);
        self::assertStringNotContainsString('1 NFT collection families', $s);
        self::assertStringNotContainsString('families are confirmed', $s);
    }

    // ── (15)(16)(17) THE BRANCHES PR 7.4 MUST NOT WEAKEN ────────────────

    /**
     * A genuinely complete chain may still say the final zero — and now also
     * reports what the session itself contributed.
     */
    public function testCompletionWithNoConfirmedCollectionStaysReachable(): void
    {
        $p = self::live();
        $p['classified_families']  = 742;
        $p['remaining_families']   = 0;
        $p['eligible_now']         = 0;
        $p['collection_families']  = 0;
        $p['exhausted_families']   = 0;
        $p['scan_complete']        = DiscoveryScanProgress::YES;
        $p['more_work_available']  = DiscoveryScanProgress::NO;

        $s = DiscoveryScanProgress::summarySentence($p, 0);

        self::assertStringContainsString('This session added no new collection record.', $s);
        self::assertStringContainsString(
            'Scan complete. All 742 contract families were checked. No supported NFT collections were confirmed.',
            $s
        );
        self::assertNotSame('Continue scan', DiscoveryScanProgress::actionLabel($p));
    }

    /**
     * ⚠ AN UNRESOLVED FAMILY IS NOT A NEGATIVE RESULT — and a session that
     * emitted records before running out of resolvable work still says so.
     */
    public function testExhaustedFamiliesForbidCleanZeroWordingButKeepTheSessionResult(): void
    {
        $p = self::live();
        $p['classified_families'] = 742;
        $p['remaining_families']  = 0;
        $p['eligible_now']        = 0;
        $p['delayed_families']    = 0;
        $p['exhausted_families']  = 3;
        $p['scan_complete']       = DiscoveryScanProgress::NO;

        $s = DiscoveryScanProgress::summarySentence($p, 2);

        self::assertStringContainsString('This session added 2 new collection records.', $s);
        self::assertStringContainsString('3 families could not be resolved', $s);
        self::assertStringContainsString('still unknown', $s);

        self::assertStringNotContainsString('Scan complete', $s);
        self::assertStringNotContainsString('No supported NFT collections were confirmed', $s);
    }

    /**
     * ⚠ A FAILED PROGRESS READ CONCLUDES NOTHING — and a session count must
     * not smuggle a conclusion in beside it.
     */
    public function testAProgressReadFailureNeverGetsSessionWording(): void
    {
        $unavailable = [
            'ok'                   => false,
            'chain_id'             => 8,
            'enumeration_complete' => DiscoveryScanProgress::UNKNOWN,
            'total_families'       => null,
            'classified_families'  => null,
            'remaining_families'   => null,
            'collection_families'  => null,
            'scan_complete'        => DiscoveryScanProgress::UNKNOWN,
            'more_work_available'  => DiscoveryScanProgress::UNKNOWN,
            'reason'               => 'progress_unavailable',
        ];

        foreach ([null, 0, 2] as $emitted) {
            $s = DiscoveryScanProgress::summarySentence($unavailable, $emitted);

            self::assertSame(
                'Scan progress is temporarily unavailable. No completion conclusion can be made.',
                $s,
                'a failed read concludes nothing, whatever the session count says'
            );
        }
    }

    // ── PURITY ──────────────────────────────────────────────────────────

    /**
     * ⚠ THE SENTENCE READS NOTHING. It is handed a progress array and a
     * count; if it ever queried, the panel's four sections could disagree
     * and a page load would cost four extra round trips.
     */
    public function testTheSentenceIsPure(): void
    {
        $p = self::live();

        $first  = DiscoveryScanProgress::summarySentence($p, 2);
        $second = DiscoveryScanProgress::summarySentence($p, 2);

        self::assertSame($first, $second);
        self::assertSame($p, self::live(), 'the input array is never mutated');
    }
}
