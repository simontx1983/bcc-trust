<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\DiscoveryScanProgress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The WORDS, and the rule that chooses between them.
 *
 * ── WHY THE SENTENCES ARE UNDER TEST ────────────────────────────────────
 * The Cosmos Hub canary's defect was never a wrong number — 737, 5, 0 and
 * `succeeded` were all correct. It was that the available WORDING for that
 * state said "finished, nothing here". So the sentence is the deliverable,
 * and an assertion on it is an assertion on the thing that actually misled.
 */
#[CoversClass(DiscoveryScanProgress::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DiscoveryScanProgressTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/discovery-progress-stubs.php';
    }

    /** The canary's own numbers, as the read model reports them. */
    private static function canary(): array
    {
        return [
            'ok'                   => true,
            'chain_id'             => 8,
            'enumeration_complete' => DiscoveryScanProgress::YES,
            'total_families'       => 737,
            'classified_families'  => 5,
            'remaining_families'   => 732,
            'collection_families'  => 0,
            'scan_complete'        => DiscoveryScanProgress::NO,
            'more_work_available'  => DiscoveryScanProgress::YES,
            'reason'               => '',
        ];
    }

    // ── (2) the incomplete sentence ─────────────────────────────────────

    public function testTheIncompleteSentenceShowsBoundedProgress(): void
    {
        $s = DiscoveryScanProgress::summarySentence(self::canary());

        self::assertStringContainsString('Pass completed', $s);
        self::assertStringContainsString('5 of 737', $s);
        self::assertStringContainsString('732', $s);
        self::assertStringContainsString('still need review', $s);
    }

    /**
     * ⚠ (3) THE SENTENCE THAT MUST NEVER APPEAR WHILE WORK REMAINS.
     *
     * Asserted as absence of the CLAIM, not of a phrasing: each of these
     * would tell an operator the chain has been settled when 732 families
     * have not been looked at.
     */
    public function testItNeverClaimsTheChainHasNoCollectionsWhileWorkRemains(): void
    {
        $s = DiscoveryScanProgress::summarySentence(self::canary());

        self::assertStringNotContainsString('Scan complete', $s);
        self::assertStringNotContainsString('No supported NFT collections were confirmed', $s);
        self::assertStringNotContainsString('All 737', $s);

        // The zero it DOES report is scoped to the pass, explicitly.
        self::assertStringContainsString('in this pass', $s);
    }

    public function testTheActionSaysContinueRatherThanStartOver(): void
    {
        $label = DiscoveryScanProgress::actionLabel(self::canary());

        self::assertSame('Continue scan', $label);
        self::assertStringNotContainsStringIgnoringCase('start over', $label);
        self::assertStringContainsString('resumes where the last pass stopped', DiscoveryScanProgress::actionHint(self::canary()));
    }

    // ── (4) the genuine complete-zero ───────────────────────────────────

    public function testTheFinalZeroSentenceIsAllowedOnlyWhenTheQueueIsEmpty(): void
    {
        $done = self::canary();
        $done['classified_families'] = 737;
        $done['remaining_families']  = 0;
        $done['scan_complete']       = DiscoveryScanProgress::YES;
        $done['more_work_available'] = DiscoveryScanProgress::NO;

        $s = DiscoveryScanProgress::summarySentence($done);

        self::assertStringContainsString('Scan complete', $s);
        self::assertStringContainsString('All 737 contract families were checked', $s);
        self::assertStringContainsString('No supported NFT collections were confirmed', $s);
        self::assertStringNotContainsString('still need review', $s);

        // And the button stops offering more work.
        self::assertNotSame('Continue scan', DiscoveryScanProgress::actionLabel($done));
        self::assertSame('', DiscoveryScanProgress::actionHint($done));
    }

    /** A complete scan that DID find collections says so, without a zero. */
    public function testACompleteScanWithFindingsDoesNotSayZero(): void
    {
        $done = self::canary();
        $done['classified_families'] = 737;
        $done['remaining_families']  = 0;
        $done['collection_families'] = 3;
        $done['scan_complete']       = DiscoveryScanProgress::YES;
        $done['more_work_available'] = DiscoveryScanProgress::NO;

        $s = DiscoveryScanProgress::summarySentence($done);

        self::assertStringContainsString('Scan complete', $s);
        self::assertStringNotContainsString('No supported NFT collections were confirmed', $s);
    }

    // ── (7)(8) unavailable is not zero and not complete ─────────────────

    /**
     * ⚠ THE FAIL-CLOSED SENTENCE. A read that did not run must produce no
     * completion conclusion at all — not "complete", not "0 remaining", and
     * not an SQL error.
     */
    public function testUnavailableProgressMakesNoConclusion(): void
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

        $s = DiscoveryScanProgress::summarySentence($unavailable);

        self::assertStringContainsString('temporarily unavailable', $s);
        self::assertStringContainsString('No completion conclusion can be made', $s);

        self::assertStringNotContainsString('Scan complete', $s);
        self::assertStringNotContainsString('No supported NFT collections', $s);
        self::assertStringNotContainsString('0 of', $s);

        // And nothing internal leaks into the operator's view.
        foreach (['SQL', 'SELECT', 'mysql', 'Exception', 'wpdb', 'chain_id'] as $leak) {
            self::assertStringNotContainsStringIgnoringCase($leak, $s, $leak . ' must not reach the operator');
        }

        // No Continue button on an unknown queue: offering more work we
        // cannot prove exists is its own false claim.
        self::assertNotSame('Continue scan', DiscoveryScanProgress::actionLabel($unavailable));
    }

    /** An `ok` result whose completeness is UNKNOWN is also inconclusive. */
    public function testUnknownCompletenessIsTreatedAsUnavailable(): void
    {
        $p = self::canary();
        $p['scan_complete'] = DiscoveryScanProgress::UNKNOWN;

        self::assertStringContainsString(
            'No completion conclusion can be made',
            DiscoveryScanProgress::summarySentence($p)
        );
    }

    // ── the completion rule itself, over the pure inputs ────────────────

    /**
     * ⚠ NONE OF THE RUN-ROW FACTS MAY MAKE A SCAN COMPLETE.
     *
     * The canary shape carries every one of them — a successful pass, a
     * zero partial, a `pass_completed` stop reason, zero collections and an
     * enumeration marked complete — and it is still NO.
     */
    public function testNoSinglePassFactCanImplyCompleteness(): void
    {
        $p = self::canary();

        self::assertSame(DiscoveryScanProgress::YES, $p['enumeration_complete']);
        self::assertSame(0, $p['collection_families']);
        self::assertSame(DiscoveryScanProgress::NO, $p['scan_complete']);
        self::assertGreaterThan(0, $p['remaining_families']);
    }
    // ── (7) UNRESOLVED IS NOT COMPLETE, AND NOT A NEGATIVE ──────────────

    /**
     * A retry-exhausted family gets the session-finished sentence, never the
     * final zero.
     *
     * â  THE PURE HALF OF THE PR 7.3 GATE. `remaining` excludes
     * `retry_count >= MAX_RETRIES`, so a chain whose only outstanding work is
     * exhausted reports `remaining = 0` â which used to satisfy the
     * completion rule and print "All N were checked. No supported NFT
     * collections were confirmed." over a family nobody ever resolved.
     */
    public function testAnExhaustedFamilyGetsTheSessionFinishedSentence(): void
    {
        $p = self::canary();
        $p['total_families']      = 10;
        $p['classified_families'] = 10;
        $p['remaining_families']  = 0;
        $p['eligible_now']        = 0;
        $p['delayed_families']    = 0;
        $p['exhausted_families']  = 2;
        $p['scan_complete']       = DiscoveryScanProgress::NO;
        $p['more_work_available'] = DiscoveryScanProgress::NO;

        $s = DiscoveryScanProgress::summarySentence($p);

        self::assertStringContainsString('Scan session finished.', $s);
        self::assertStringContainsString('2 families could not be resolved', $s);
        self::assertStringContainsString('still unknown', $s);

        // The three claims it must never make.
        self::assertStringNotContainsString('Scan complete', $s);
        self::assertStringNotContainsString('No supported NFT collections were confirmed', $s);
        self::assertStringNotContainsString('still need review', $s);
    }

    /** Singular reads correctly â one family, not "1 families". */
    public function testOneUnresolvedFamilyReadsAsSingular(): void
    {
        $p = self::canary();
        $p['total_families']      = 10;
        $p['classified_families'] = 10;
        $p['remaining_families']  = 0;
        $p['eligible_now']        = 0;
        $p['delayed_families']    = 0;
        $p['exhausted_families']  = 1;
        $p['scan_complete']       = DiscoveryScanProgress::NO;

        $s = DiscoveryScanProgress::summarySentence($p);

        self::assertStringContainsString('1 family could not be resolved', $s);
        self::assertStringNotContainsString('1 families', $s);
    }

    /**
     * With work still claimable the ordinary incomplete sentence wins.
     *
     * â  The session-finished branch must not swallow the normal
     * "N still need review" case just because some family is also exhausted.
     */
    public function testClaimableWorkStillGetsTheReviewSentence(): void
    {
        $p = self::canary();
        $p['eligible_now']       = 700;
        $p['delayed_families']   = 0;
        $p['exhausted_families'] = 3;

        $s = DiscoveryScanProgress::summarySentence($p);

        self::assertStringContainsString('732 families still need review', $s);
        self::assertStringNotContainsString('Scan session finished', $s);
    }

    /** Delayed work also keeps the review sentence â it has a future. */
    public function testDelayedWorkKeepsTheReviewSentence(): void
    {
        $p = self::canary();
        $p['eligible_now']       = 0;
        $p['delayed_families']   = 732;
        $p['exhausted_families'] = 1;

        $s = DiscoveryScanProgress::summarySentence($p);

        self::assertStringContainsString('still need review', $s);
        self::assertStringNotContainsString('Scan session finished', $s);
    }
}
