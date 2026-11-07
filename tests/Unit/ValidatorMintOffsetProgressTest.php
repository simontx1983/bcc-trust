<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\ValidatorPageMinter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A validator that cannot be minted must cost ONE scan per run, not the
 * whole cross-chain budget.
 *
 * ── THE DEFECT, AND WHY IT WAS PERMANENT RATHER THAN WASTEFUL ───────────
 * mintMissing() walks candidates in batches and deliberately did NOT
 * advance $offset on the live path. The reasoning was sound as far as it
 * went: a row that MINTS stops matching findMissingPlaceholderPage(), so
 * the result set shrinks under the cursor and the next call at the same
 * offset sees new rows.
 *
 * ⚠⚠ But a row that does NOT mint does not leave the result set. An
 * errored row still has no page, so it matches again; a skipped row is one
 * the two queries disagree about. With the offset pinned at 0 the next
 * iteration re-read exactly those rows, and `scanned` climbed without a
 * single new row being examined — so ONE permanently unmintable validator
 * consumed all of maxToMint (500).
 *
 * ⛔ That is not merely wasted work. ChainRefreshService decrements its
 * shared MINT_SCAN_BUDGET by `scanned` and `break`s at zero, iterating
 * chains in a stable order — a property its own suite asserts in
 * testAnExhaustedBudgetSkipsTheRemainingChains(). So the chain holding the
 * bad row spent the entire budget every run, and every chain after it in
 * that order minted NOTHING, on every run, with no self-healing. The
 * user-visible effect is validator pages that never appear.
 *
 * ── WHAT THIS SUITE ASSERTS ─────────────────────────────────────────────
 * ⭐ The sharp one is testTheWindowMovesPastRowsThatDidNotMint(): it reads
 * the OFFSETS the fetcher is asked for. Fixed, those are [0, 100]. Before
 * the fix the second call came back at offset 0 — the same hundred rows.
 * That is a difference no source-spelling assertion could see, which is
 * how the defect survived a suite that already covered the budget.
 */
#[CoversNothing]
#[RunTestsInSeparateProcesses]
final class ValidatorMintOffsetProgressTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/mint-offset-stubs.php';
    }

    /**
     * A candidate row in the shape findMissingPlaceholderPage() returns.
     */
    private static function row(int $id): object
    {
        return (object) [
            'id'               => $id,
            'operator_address' => 'cosmosvaloper1stub' . $id,
            'moniker'          => 'stub-' . $id,
            'status'           => 'active',
            'chain_id'         => 8,
            'chain_slug'       => 'cosmos',
            'chain_name'       => 'Cosmos Hub',
        ];
    }

    // ── the behavioural regression ──────────────────────────────────────

    /**
     * ⭐ THE REGRESSION TEST.
     *
     * Every mint attempt fails here, and it fails for a real reason rather
     * than a contrived one: this harness has no WordPress, so
     * mintForValidator() throws on its first core call. That throw is
     * caught by the per-row `catch (\Throwable)`, which is precisely the
     * errored-row path the defect lived on.
     *
     * With maxToMint 150 and BATCH_SIZE 100, a correct walk asks for
     * offset 0 (limit 100), then offset 100 (limit 50). A walk that does
     * not step asks for offset 0 twice and re-examines the same rows.
     */
    public function testTheWindowMovesPastRowsThatDidNotMint(): void
    {
        $asked = [];
        $handedOut = [];

        $fetcher = static function (?int $chainId, int $limit, int $offset, ?array $only) use (&$asked, &$handedOut): array {
            $asked[] = ['offset' => $offset, 'limit' => $limit];

            $rows = [];
            for ($i = 0; $i < $limit; $i++) {
                $id = $offset + $i + 1;
                if ($id > 150) {
                    break;
                }
                $handedOut[] = $id;
                $rows[]      = self::row($id);
            }

            return $rows;
        };

        $result = ValidatorPageMinter::mintMissing(8, false, 150, null, $fetcher);

        self::assertSame(
            [['offset' => 0, 'limit' => 100], ['offset' => 100, 'limit' => 50]],
            $asked,
            'the second batch must start AFTER the hundred rows that did not mint'
        );

        self::assertSame(150, $result['scanned'], 'scanned must count distinct rows, not re-reads');
        self::assertSame(150, $result['errors'], 'every row errored, which is the path under test');
        self::assertSame(0, $result['minted']);

        self::assertSame(
            $handedOut,
            array_unique($handedOut),
            'no validator may be examined twice in one run'
        );
        self::assertCount(150, $handedOut);
    }

    /**
     * The bound that matters operationally: a single stuck row must not
     * be able to spend the caller's whole budget.
     *
     * One unmintable row, and nothing else to find. Before the fix this
     * re-read that row until `scanned` reached maxToMint; the budget
     * ChainRefreshService then subtracted was the whole 500.
     */
    public function testOneStuckRowCostsOneScanNotTheWholeBudget(): void
    {
        $asked = 0;

        $fetcher = static function (?int $chainId, int $limit, int $offset, ?array $only) use (&$asked): array {
            $asked++;

            return $offset === 0 ? [self::row(1)] : [];
        };

        $result = ValidatorPageMinter::mintMissing(8, false, 500, null, $fetcher);

        self::assertSame(1, $result['scanned'], 'one row examined, once');
        self::assertSame(1, $result['errors']);
        self::assertSame(
            1,
            $asked,
            'a short batch ends the walk, so the stuck row is not re-read even within the run'
        );
    }

    public function testADryRunStillWalksTheWholeTable(): void
    {
        $asked = [];

        $fetcher = static function (?int $chainId, int $limit, int $offset, ?array $only) use (&$asked): array {
            $asked[] = $offset;

            $rows = [];
            for ($i = 0; $i < $limit; $i++) {
                $id = $offset + $i + 1;
                if ($id > 150) {
                    break;
                }
                $rows[] = self::row($id);
            }

            return $rows;
        };

        $result = ValidatorPageMinter::mintMissing(8, true, 150, null, $fetcher);

        self::assertSame([0, 100], $asked, 'a dry run writes nothing, so it must step by the whole batch');
        self::assertSame(150, $result['scanned']);
        self::assertSame(150, $result['minted'], 'dry-run reports what it WOULD mint');
        self::assertSame(0, $result['errors']);
    }

    /**
     * ⛔ An empty allowlist must still mean MINT NONE, and must not even
     * reach the fetcher. Guarding the seam did not widen that.
     */
    public function testAnEmptyAllowlistNeverReachesTheFetcher(): void
    {
        $called = false;

        $fetcher = static function () use (&$called): array {
            $called = true;

            return [];
        };

        $result = ValidatorPageMinter::mintMissing(8, false, 500, [], $fetcher);

        self::assertFalse($called, 'mint-none must short-circuit before any query');
        self::assertSame(0, $result['scanned']);
        self::assertSame(0, $result['minted']);
    }

    /**
     * The production path must keep using the repository when no seam is
     * passed — the seam is for tests, not a new way to inject a source.
     */
    public function testTheSeamDefaultsToTheRepository(): void
    {
        $src = file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Services/ValidatorPageMinter.php'
        );
        self::assertIsString($src);

        self::assertStringContainsString(
            '$rowFetcher ??= self::fetchCandidateRows(...);',
            $src,
            'the seam must DEFAULT to production, never replace it'
        );
        self::assertStringContainsString(
            'return ValidatorRepository::findMissingPlaceholderPage(',
            $src,
            'and that default must be the real repository query'
        );
    }

    // ── the offset step, asserted by value ──────────────────────────────

    /**
     * @return list<array{0: string, 1: bool, 2: int, 3: int, 4: int, 5: int}>
     */
    public static function offsetSteps(): array
    {
        return [
            // label, dryRun, batchSize, errored, skipped, expected
            ['all minted: the set shrinks, so no step', false, 100, 0, 0, 0],
            ['all errored: step past every one', false, 100, 100, 0, 100],
            ['all skipped: they stayed too', false, 100, 0, 100, 100],
            ['mixed: step past both kinds', false, 100, 3, 2, 5],
            ['partial batch', false, 50, 7, 0, 7],
            ['dry run steps the whole batch', true, 100, 0, 0, 100],
            ['dry run ignores the counters', true, 50, 9, 9, 50],
            ['negative counters cannot rewind the window', false, 100, -5, -5, 0],
            ['negative batch cannot rewind either', true, -10, 0, 0, 0],
        ];
    }

    #[DataProvider('offsetSteps')]
    public function testTheOffsetStepIsComputedFromWhatStayed(
        string $label,
        bool $dryRun,
        int $batchSize,
        int $errored,
        int $skipped,
        int $expected
    ): void {
        self::assertSame(
            $expected,
            ValidatorPageMinter::offsetStepFor($dryRun, $batchSize, $errored, $skipped),
            $label
        );
    }

    /**
     * ⭐ The property the old code violated, stated on its own: a live
     * batch in which nothing minted MUST move the window. Returning 0 here
     * is the defect, and it is what the pre-fix code did unconditionally.
     */
    public function testALiveBatchThatMintedNothingAlwaysMovesTheWindow(): void
    {
        for ($n = 1; $n <= 100; $n++) {
            self::assertGreaterThan(
                0,
                ValidatorPageMinter::offsetStepFor(false, $n, $n, 0),
                'a batch of ' . $n . ' rows that all failed must advance the window'
            );
        }
    }
}
