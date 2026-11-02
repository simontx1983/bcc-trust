<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A validator placeholder page gets NO score row, and that is correct.
 *
 * ── THE INTENDED BEHAVIOUR, AS THE CODE ALREADY DEFINES IT ──────────────
 * `bcc_trust_page_scores` is an OWNER-KEYED table: `page_owner_id` is a
 * real column, and `ScoreRepository::createIfNotExists()` resolves the
 * owner (treating a passed `0` as "resolve it for me") and THROWS
 * 'No owner ID found for page N' when the page has none.
 *
 * That throw is the DESIGN, not a defect to be worked around. A validator
 * placeholder is unowned on purpose — `ValidatorPageMinter` inserts it with
 * `post_author => 0` so it can carry the WANTED claim CTA until an operator
 * claims it — so the refusal is the correct answer every single time.
 *
 * Measured on production 2026-11-02, which is what makes this a statement
 * about the system rather than about taste:
 *   - 2,920 `peepso-page` rows, ZERO with a score row, all rendering with
 *     `trust_score: 50` / `reputation_tier: neutral`;
 *   - 8 score rows in the whole database, ZERO with `page_owner_id = 0`.
 *
 * Nothing needs a seeded row, because `PageReadModelRepository::syncPage()`
 * distinguishes "no score row" (project the neutral default) from "the read
 * did not run" (fail closed). The row is created later, with a REAL owner,
 * by `PeepSoIntegration::onPageCreated()` or lazily by `VoteService`.
 *
 * ── WHAT WENT WRONG ─────────────────────────────────────────────────────
 * The minter called `createDefault($pageId, 0)` in a try/catch that
 * swallowed the guaranteed throw and logged it at ERROR. The 2026-11-02
 * Cosmos backfill therefore minted 76 pages correctly while writing 76
 * production ERROR lines, and the CLI still printed `errors=0` because the
 * catch kept the failure out of the result counter. Pages, read model and
 * payloads were all fine; only the log lied.
 *
 * ⚠ THE WRONG FIX WOULD BE TO MAKE `createIfNotExists()` ACCEPT OWNER 0.
 * That would start writing ownerless score rows — real data — to silence a
 * log line. Section 3 below pins the refusal so that cannot happen quietly.
 */
#[CoversNothing]
final class ValidatorPlaceholderScoreTest extends TestCase
{
    private static function read(string $relative): string
    {
        $path = dirname(__DIR__, 2) . '/' . $relative;
        $src  = file_get_contents($path);
        self::assertNotFalse($src, "could not read {$relative}");
        self::assertNotSame('', $src, "{$relative} is empty");

        return (string) $src;
    }

    private static function minter(): string
    {
        return self::read('app/Domain/Onchain/Services/ValidatorPageMinter.php');
    }

    /**
     * Strip `//` and block comments so a prose mention of a symbol can never
     * be mistaken for a call to it. Without this, the whole of section 1
     * would fail on the explanatory comment the fix leaves behind.
     */
    private static function code(string $src): string
    {
        $out = preg_replace('!/\*.*?\*/!s', '', $src) ?? '';

        return (string) preg_replace('!//[^\r\n]*!', '', $out);
    }

    // ── (0) anti-vacuity: the files are really being read ────────────────

    public function testTheSourcesUnderTestAreActuallyLoaded(): void
    {
        self::assertStringContainsString(
            'final class ValidatorPageMinter',
            self::minter(),
            'the minter source must be the file being asserted about'
        );
        self::assertStringContainsString(
            'function createIfNotExists',
            self::read('app/Domain/Core/Repositories/ScoreRepository.php'),
            'the score repository source must be the file being asserted about'
        );
    }

    /**
     * The comment stripper must actually strip. If it ever stopped working,
     * every "does not call" assertion below would be satisfied by prose.
     */
    public function testTheCommentStripperRemovesProseButKeepsCode(): void
    {
        $sample = "\$a = 1; // createDefault(\$x, 0)\n/* scoreRepository() */\n\$b = callSite();";
        $code   = self::code($sample);

        self::assertStringNotContainsString('createDefault', $code);
        self::assertStringNotContainsString('scoreRepository', $code);
        self::assertStringContainsString('callSite()', $code);
    }

    // ── (1) the minter seeds no score row ───────────────────────────────

    public function testTheMinterDoesNotCreateAScoreRow(): void
    {
        $code = self::code(self::minter());

        self::assertStringNotContainsString(
            'createDefault',
            $code,
            'minting a placeholder must not attempt an ownerless score row'
        );
        self::assertStringNotContainsString(
            'scoreRepository',
            $code,
            'the minter must not reach the score repository at all'
        );
        self::assertStringNotContainsString(
            'createIfNotExists',
            $code,
            'nor reach it under its other name'
        );
    }

    /**
     * The swallowing catch is what made the failure invisible to the CLI's
     * own error counter while still shouting in the log. It must be gone.
     */
    public function testTheSwallowedScoreErrorLogIsGone(): void
    {
        self::assertStringNotContainsString(
            'createDefault failed',
            self::minter(),
            'the per-page ERROR log line must no longer exist in any form'
        );
    }

    // ── (2) nothing else about minting changed ──────────────────────────

    public function testTheMinterStillProjectsIntoTheReadModel(): void
    {
        $code = self::code(self::minter());

        self::assertStringContainsString(
            'pageReadModelRepository()',
            $code,
            'syncPage is what makes a new page appear in the directory'
        );
        self::assertStringContainsString(
            'syncPage($pageId)',
            $code,
            'the read-model projection must survive this change'
        );
    }

    public function testTheMinterStillInsertsAnUnownedPublishedPlaceholder(): void
    {
        $code = self::code(self::minter());

        self::assertStringContainsString('wp_insert_post(', $code);
        self::assertStringContainsString("'post_type'    => 'peepso-page'", $code);
        self::assertStringContainsString("'post_status'  => 'publish'", $code);
        self::assertStringContainsString("'post_author'  => 0", $code);
        self::assertStringContainsString('_bcc_onchain_validator_id', $code);
        self::assertStringContainsString('_bcc_page_type', $code);
    }

    /**
     * `post_author => 0` is the premise of the whole change: it is WHY the
     * score row cannot exist. If a future edit gave the placeholder an owner,
     * this test should fail and the decision be re-made deliberately.
     */
    public function testThePlaceholderIsStillUnownedWhichIsWhyThereIsNoScoreRow(): void
    {
        self::assertSame(
            1,
            substr_count(self::code(self::minter()), "'post_author'  => 0"),
            'exactly one unowned insert; an owner here would change the analysis'
        );
    }

    public function testTheSyncPageFailureIsStillReportedBecauseThatOneCanGenuinelyFail(): void
    {
        self::assertStringContainsString(
            'syncPage failed',
            self::minter(),
            'a read-model projection CAN fail for real and must stay logged'
        );
    }

    // ── (3) the designed refusal must not be weakened ───────────────────

    public function testAnOwnerlessScoreRowIsStillRefusedByTheRepository(): void
    {
        $src = self::read('app/Domain/Core/Repositories/ScoreRepository.php');

        self::assertStringContainsString(
            'No owner ID found for page',
            $src,
            'the refusal is the intended behaviour and must remain'
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$ownerId\s*\)\s*\{\s*throw new Exception\(/',
            self::code($src),
            'the throw must still guard a falsy owner id'
        );
    }

    /**
     * The owned path is untouched. A page created BY a user still gets its
     * score row seeded with that user's id, which is what keeps a claimed
     * validator page behaving exactly as it did before.
     */
    public function testTheOwnedPageCreationPathStillSeedsItsScoreRow(): void
    {
        $code = self::code(self::read('app/Domain/Core/Integration/PeepSoIntegration.php'));

        self::assertStringContainsString(
            'createDefault($pageId, $userId)',
            $code,
            'PeepSo page creation must still seed a score row for a real owner'
        );
    }

    /** The lazy path that creates the row on first vote, with a real owner. */
    public function testTheLazyFirstVotePathStillCreatesTheRow(): void
    {
        self::assertStringContainsString(
            'createDefault($pageId, $ownerId)',
            self::code(self::read('app/Domain/Core/Services/VoteService.php')),
            'the first-vote path is where a placeholder eventually gets a score row'
        );
    }

    // ── (4) the property the deletion depends on ────────────────────────

    /**
     * The fix is only safe because syncPage projects a score-less page at the
     * neutral default, and fails closed ONLY when the read did not run. If
     * that distinction were ever lost, a missing score row would start
     * overwriting real scores — which is the defect its own comment records.
     */
    public function testTheReadModelStillToleratesAMissingScoreRow(): void
    {
        $code = self::code(self::read('app/Domain/Core/Repositories/PageReadModelRepository.php'));

        self::assertStringContainsString(
            'readScoreRowOrThrow',
            $code,
            'the fail-closed score read must remain'
        );
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$score\s*===\s*null\s*\)/',
            $code,
            'syncPage must still have an explicit "no score row" branch'
        );
        self::assertStringContainsString(
            '$ownerId = $score ? (int) $score->page_owner_id : 0;',
            $code,
            'a null score row must still resolve to owner 0 rather than throwing'
        );
    }
}
