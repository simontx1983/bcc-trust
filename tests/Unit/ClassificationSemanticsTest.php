<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\DiscoveryScanProgress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * `probable_cw721` is NOT `confirmed_cw721`.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 * `countCollectionFamiliesOrThrow()` counted CONFIRMED **and** PROBABLE, the
 * read model exposed the sum as `collection_families`, and the panel printed
 * it under the word "confirmed". On 2026-09-07 Cosmos Hub held
 *
 *     confirmed_cw721 = 12      probable_cw721 = 1
 *
 * and the operator was told **"13 NFT collection families are confirmed so
 * far"**. Thirteen were not confirmed. Twelve were; one was a candidate the
 * administrator had not looked at.
 *
 * ── THE DEFINITIONS THIS FILE ENFORCES ──────────────────────────────────
 * confirmed — the scanner has sufficient evidence to call the family CW-721.
 * probable  — evidence suggests CW-721; a human still has to decide.
 * "Confirmed" means `confirmed_cw721` and nothing else. "Probable" is always
 * reported separately, never added in, never described as confirmed,
 * verified, or a supported collection.
 */
#[CoversClass(DiscoveryScanProgress::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ClassificationSemanticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/discovery-progress-stubs.php';
    }

    /**
     * ⚠ THE EXACT LIVE STAGING FIXTURE, 2026-09-07, after run 5.
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
            'classified_families'  => 546,
            'remaining_families'   => 196,
            'confirmed_families'   => 12,
            'probable_families'    => 1,
            'eligible_now'         => 196,
            'delayed_families'     => 0,
            'exhausted_families'   => 0,
            'negative_families'    => 195,
            'scan_complete'        => DiscoveryScanProgress::NO,
            'more_work_available'  => DiscoveryScanProgress::YES,
            'reason'               => '',
        ];
    }

    // ── (1) THE LIVE REGRESSION ─────────────────────────────────────────

    /** 12 confirmed + 1 probable renders as 12 confirmed and 1 probable. */
    public function testTheLiveFixtureRendersTwelveConfirmedAndOneProbable(): void
    {
        $s = DiscoveryScanProgress::summarySentence(self::live(), 6);

        self::assertSame(
            'This session added 6 new collection records. '
            . '12 NFT collection families confirmed. '
            . '1 possible NFT collection family needs administrator review. '
            . 'Checked 546 of 742 contract families; 196 still need scanning.',
            $s
        );
    }

    /** ⚠ THIRTEEN MUST NOT APPEAR AS A CONFIRMED COUNT, in any phrasing. */
    public function testThirteenIsNeverPresentedAsConfirmed(): void
    {
        $s = DiscoveryScanProgress::summarySentence(self::live(), 6);

        self::assertStringNotContainsString('13', $s);
        self::assertStringNotContainsString('13 NFT collection families are confirmed', $s);
        self::assertStringContainsString('12 NFT collection families confirmed.', $s);
    }

    /** A probable family is never called confirmed, verified or supported. */
    public function testProbableIsNeverDescribedAsConfirmedOrVerified(): void
    {
        $p = self::live();
        $p['confirmed_families'] = 0;
        $p['probable_families']  = 1;

        $s = DiscoveryScanProgress::summarySentence($p, 0);

        self::assertStringContainsString('1 possible NFT collection family needs administrator review.', $s);
        self::assertStringNotContainsString('confirmed.', $s);
        self::assertStringNotContainsStringIgnoringCase('verified', $s);
        self::assertStringNotContainsStringIgnoringCase('supported collection', $s);
    }

    /**
     * ⚠ PROBABLE NEVER INCREMENTS THE CONFIRMED COUNT.
     *
     * Sweeping the probable count across a range while confirmed is fixed:
     * the confirmed clause must not move.
     */
    #[DataProvider('probableCounts')]
    public function testProbableNeverIncrementsTheConfirmedCount(int $probable): void
    {
        $p = self::live();
        $p['probable_families'] = $probable;

        $s = DiscoveryScanProgress::summarySentence($p, 0);

        self::assertStringContainsString('12 NFT collection families confirmed.', $s);
        foreach ([13, 14, 15, 20, 112] as $wrong) {
            self::assertStringNotContainsString("{$wrong} NFT collection families confirmed", $s);
        }
    }

    /** @return array<string, array{int}> */
    public static function probableCounts(): array
    {
        return ['one' => [1], 'two' => [2], 'three' => [3], 'eight' => [8], 'hundred' => [100]];
    }

    // ── (2) SINGULAR AND PLURAL ─────────────────────────────────────────

    /**
     * @param int    $confirmed confirmed families
     * @param int    $probable  probable families
     */
    #[DataProvider('grammarCases')]
    public function testSingularAndPluralWording(int $confirmed, int $probable, string $expected): void
    {
        $p = self::live();
        $p['confirmed_families'] = $confirmed;
        $p['probable_families']  = $probable;

        self::assertStringContainsString($expected, DiscoveryScanProgress::summarySentence($p, 0));
    }

    /** @return array<string, array{int, int, string}> */
    public static function grammarCases(): array
    {
        return [
            'one confirmed'   => [1, 0, '1 NFT collection family confirmed.'],
            'two confirmed'   => [2, 0, '2 NFT collection families confirmed.'],
            'one probable'    => [0, 1, '1 possible NFT collection family needs administrator review.'],
            'two probable'    => [0, 2, '2 possible NFT collection families need administrator review.'],
            'thousands group' => [1200, 0, '1,200 NFT collection families confirmed.'],
        ];
    }

    /** The singular forms never render a plural verb, and vice versa. */
    public function testGrammarDoesNotCross(): void
    {
        $p = self::live();
        $p['confirmed_families'] = 1;
        $p['probable_families']  = 1;

        $s = DiscoveryScanProgress::summarySentence($p, 0);

        self::assertStringNotContainsString('1 NFT collection families', $s);
        self::assertStringNotContainsString('1 possible NFT collection families', $s);
        self::assertStringNotContainsString('families need administrator review', $s);
    }

    // ── (3) COMPLETION AND THE FINAL ZERO ───────────────────────────────

    /**
     * ⚠ A PROBABLE-ONLY COMPLETED CHAIN MUST NOT SAY "NO NFT COLLECTIONS".
     *
     * Scanner work is finished, so the heading says scanning is complete —
     * but a candidate is waiting on a human, and claiming nothing was found
     * would be false in exactly the way this PR exists to stop.
     */
    public function testAProbableOnlyCompleteChainDoesNotClaimNoCollections(): void
    {
        $p = self::completed(confirmed: 0, probable: 1);

        $s = DiscoveryScanProgress::summarySentence($p, 0);

        self::assertStringContainsString('Scanning complete.', $s);
        self::assertStringContainsString('1 possible NFT collection family needs administrator review.', $s);
        self::assertStringNotContainsString('No supported NFT collections were confirmed', $s);
    }

    /**
     * ⚠ THE FINAL ZERO NEEDS SIX PROOFS. Zero confirmed AND zero probable
     * are two of them.
     */
    public function testTheFinalZeroIsAllowedOnlyWithNothingConfirmedAndNothingProbable(): void
    {
        $s = DiscoveryScanProgress::summarySentence(self::completed(confirmed: 0, probable: 0), 0);

        self::assertStringContainsString(
            'Scan complete. All 742 contract families were checked. No supported NFT collections were confirmed.',
            $s
        );
    }

    /** With confirmed families the complete branch reports them, not a zero. */
    public function testACompleteChainWithConfirmedFamiliesReportsThem(): void
    {
        $s = DiscoveryScanProgress::summarySentence(self::completed(confirmed: 12, probable: 1), 0);

        self::assertStringContainsString('Scanning complete.', $s);
        self::assertStringContainsString('12 NFT collection families confirmed.', $s);
        self::assertStringContainsString('1 possible NFT collection family needs administrator review.', $s);
        self::assertStringNotContainsString('No supported NFT collections were confirmed', $s);
    }

    /** ⚠ Unresolved work still blocks completion, and probable does not mask it. */
    public function testExhaustedFamiliesStillBlockCompletionAlongsideProbable(): void
    {
        $p = self::live();
        $p['classified_families'] = 742;
        $p['remaining_families']  = 0;
        $p['eligible_now']        = 0;
        $p['delayed_families']    = 0;
        $p['exhausted_families']  = 3;
        $p['confirmed_families']  = 12;
        $p['probable_families']   = 1;
        $p['scan_complete']       = DiscoveryScanProgress::NO;

        $s = DiscoveryScanProgress::summarySentence($p, 0);

        self::assertStringContainsString('3 families could not be resolved', $s);
        self::assertStringContainsString('12 NFT collection families confirmed.', $s);
        self::assertStringContainsString('1 possible NFT collection family needs administrator review.', $s);
        self::assertStringNotContainsString('No supported NFT collections were confirmed', $s);
    }

    /** A failed read still concludes nothing, whatever the counts would be. */
    public function testAFailedReadStillConcludesNothing(): void
    {
        $p = self::live();
        $p['ok']                 = false;
        $p['scan_complete']      = DiscoveryScanProgress::UNKNOWN;
        $p['confirmed_families'] = null;
        $p['probable_families']  = null;

        self::assertSame(
            'Scan progress is temporarily unavailable. No completion conclusion can be made.',
            DiscoveryScanProgress::summarySentence($p, 6)
        );
    }

    // ── (4) THE COUNTS STAY DISTINCT ────────────────────────────────────

    /**
     * ⚠ SEVEN COUNTS, SEVEN ROLES. Each is given a value no other shares, so
     * a sentence that printed the wrong one shows up immediately.
     */
    public function testEveryCountKeepsItsOwnRole(): void
    {
        $p = [
            'ok'                   => true,
            'chain_id'             => 8,
            'enumeration_complete' => DiscoveryScanProgress::YES,
            'total_families'       => 900,
            'classified_families'  => 700,
            'remaining_families'   => 200,
            'confirmed_families'   => 11,
            'probable_families'    => 22,
            'eligible_now'         => 150,
            'delayed_families'     => 50,
            'exhausted_families'   => 0,
            'negative_families'    => 333,
            'scan_complete'        => DiscoveryScanProgress::NO,
            'more_work_available'  => DiscoveryScanProgress::YES,
            'reason'               => '',
        ];

        $s = DiscoveryScanProgress::summarySentence($p, 7);

        self::assertStringContainsString('7 new collection records', $s);
        self::assertStringContainsString('11 NFT collection families confirmed', $s);
        self::assertStringContainsString('22 possible NFT collection families need administrator review', $s);
        self::assertStringContainsString('Checked 700 of 900 contract families; 200 still need scanning.', $s);

        // The terminal-negative count is never rendered as any of the above.
        self::assertStringNotContainsString('333', $s);
    }

    /**
     * A completed chain fixture.
     *
     * @return array<string, mixed>
     */
    private static function completed(int $confirmed, int $probable): array
    {
        $p = self::live();
        $p['classified_families'] = 742;
        $p['remaining_families']  = 0;
        $p['eligible_now']        = 0;
        $p['delayed_families']    = 0;
        $p['exhausted_families']  = 0;
        $p['confirmed_families']  = $confirmed;
        $p['probable_families']   = $probable;
        $p['scan_complete']       = DiscoveryScanProgress::YES;
        $p['more_work_available'] = DiscoveryScanProgress::NO;

        return $p;
    }
}
