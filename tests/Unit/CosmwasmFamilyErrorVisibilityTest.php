<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Repositories\NftSpamContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot as H;
use BCC\Trust\Onchain\Support\CosmwasmScanEligibility;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * A scanned chain whose families hold unresolved errors is NOT healthy.
 *
 * ── THE MEASURED BUG ────────────────────────────────────────────────────
 * Dungeon canary, 2026-08-19. After the pass: 15 code families held
 * `Circuit breaker open for chain 17`, one contract was unreachable — and
 * the panel rendered the chain GREEN, labelled "Nothing is blocking this
 * chain — it is in the rotation whenever discovery runs."
 *
 * The panel's only error input was `cw_last_error` on the checkpoint, and
 * {@see ChainCheckpointRepository::advanceCwCodeWatermark()} clears that
 * field whenever the code read succeeds — which it had. Family-level
 * failures reached no status at all.
 *
 * ── THE LINE THIS FILE DEFENDS ──────────────────────────────────────────
 * `$chain['last_error'] !== null || $chain['families_errored'] > 0`, in
 * {@see H::deriveStatus()}. Two independent ways a scanned chain is
 * degraded, OR-ed, counted only over SCANNABLE chains.
 *
 * ── AND THE LINE IT DEFENDS AGAINST ─────────────────────────────────────
 * Equally important: an ordinary DeFi contract that answers "I do not
 * implement num_tokens" is a SUCCESSFUL classification. It settles
 * `not_cw721` and records NO error, so a chain full of them stays GREEN.
 * A panel that went yellow for those would be a panel operators learn to
 * ignore, which is the same failure as never showing the colour.
 */
#[CoversClass(H::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmwasmFamilyErrorVisibilityTest extends TestCase
{
    private const NOW   = 1787100000;
    private const CHAIN = 17;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-discovery-stubs.php';

        CosmwasmCodeFamilyRepository::reset();
        CosmwasmContractRepository::reset();
        CollectionRepository::reset();
        ChainCheckpointRepository::reset();
        ChainRepository::reset();
        NftSpamContractRepository::reset();
        \BccTestObjectCache::reset();
        \BccTestOptionStore::reset();
        \BccTestCronStore::reset();
    }

    // ── row construction ────────────────────────────────────────────────

    /**
     * One chain row, derived by the PRODUCTION function.
     *
     * @param array<string, int> $familyCounts
     */
    private function row(
        string $state = ChainCheckpointRepository::CW_STATE_BACKFILLED,
        int $familiesErrored = 0,
        ?string $checkpointError = null,
        bool $optedIn = true,
        ?array $allowlist = null,
        array $familyCounts = []
    ): array {
        $checkpoint = (object) [
            'chain_id'                 => (string) self::CHAIN,
            'cw_discovery_state'       => $state,
            'cw_code_cursor'           => null,
            'cw_max_code_id'           => '179',
            'cw_backfill_completed_at' => '2026-08-18 00:00:00',
            'cw_last_discovery_at'     => gmdate('Y-m-d H:i:s', self::NOW - 600),
            'cw_metadata_refreshed_at' => null,
            'cw_last_error'            => $checkpointError,
        ];

        return H::deriveChainRow(
            self::CHAIN,
            'dungeon',
            'Dungeon',
            $checkpoint,
            $familyCounts,
            0,
            $familiesErrored,
            null,
            self::NOW,
            $optedIn,
            $allowlist
        );
    }

    /** @return list<array<string, mixed>> */
    private function schedule(): array
    {
        $out = [];
        foreach (['bcc_cosmwasm_daily_discovery', 'bcc_cosmwasm_weekly_retry', 'bcc_cosmwasm_metadata_refresh'] as $hook) {
            $out[] = [
                'hook' => $hook, 'label' => $hook, 'interval' => 'daily',
                'scheduled' => true, 'next_run_at' => self::NOW + 3600, 'overdue_seconds' => 0,
            ];
        }

        return $out;
    }

    // ── (1) the repair ──────────────────────────────────────────────────

    /** THE REGRESSION TEST: one errored family turns a scanned chain yellow. */
    public function testAScannableChainWithOneFamilyErrorIsYellow(): void
    {
        $row = $this->row(familiesErrored: 1);

        self::assertSame(CosmwasmScanEligibility::ELIGIBLE, $row['eligibility'], 'precondition: scannable');
        self::assertSame(1, $row['families_errored']);
        self::assertSame(H::STATUS_YELLOW, H::deriveStatus(true, [$row], $this->schedule()));
    }

    /**
     * THE EXACT DUNGEON SHAPE: checkpoint error NULL, families errored 15.
     *
     * Before this change these inputs produced GREEN.
     */
    public function testNullCheckpointErrorPlusFamilyErrorsIsYellow(): void
    {
        $row = $this->row(
            familiesErrored: 15,
            checkpointError: null,
            familyCounts: [CosmwasmClassifier::INCONCLUSIVE => 99, CosmwasmClassifier::UNREACHABLE => 1]
        );

        self::assertNull($row['last_error'], 'the checkpoint really does say "no error"');
        self::assertSame(15, $row['families_errored']);
        self::assertSame(H::STATUS_YELLOW, H::deriveStatus(true, [$row], $this->schedule()));
    }

    /**
     * BEFORE / AFTER, on the preserved canary's real numbers.
     *
     * Staging still holds exactly this state: 100 Dungeon families (99
     * `inconclusive`, 1 `temporarily_unreachable`), 15 of them carrying an
     * unresolved `last_error`, and a checkpoint whose `cw_last_error` is
     * NULL because the code read succeeded.
     *
     * One input differs between the two rows — the count the old code had
     * no way to see. That is the whole repair, stated as a diff.
     */
    public function testThePreservedDungeonStateFlipsFromGreenToYellow(): void
    {
        $dungeonCounts = [
            CosmwasmClassifier::INCONCLUSIVE => 99,
            CosmwasmClassifier::UNREACHABLE  => 1,
        ];

        // BEFORE: family errors were invisible, so the panel saw zero.
        $before = $this->row(familiesErrored: 0, checkpointError: null, familyCounts: $dungeonCounts);
        self::assertSame(
            H::STATUS_GREEN,
            H::deriveStatus(true, [$before], $this->schedule()),
            'this is the green the operator was shown while 15 families were blocked'
        );

        // AFTER: the same chain, with the count it always had.
        $after = $this->row(familiesErrored: 15, checkpointError: null, familyCounts: $dungeonCounts);
        self::assertSame(H::STATUS_YELLOW, H::deriveStatus(true, [$after], $this->schedule()));

        // Everything else about the row is identical.
        self::assertSame($before['eligibility'], $after['eligibility']);
        self::assertSame($before['last_error'], $after['last_error']);
        self::assertSame($before['families_by_classification'], $after['families_by_classification']);
    }

    /** Breaker-open skips are unresolved errors and must show. */
    public function testBreakerOpenFamilyErrorsAreYellow(): void
    {
        CosmwasmCodeFamilyRepository::seed(self::CHAIN, 90);
        CosmwasmCodeFamilyRepository::recordAttemptFailure(self::CHAIN, 90, 'Circuit breaker open for chain 17', 1);

        $counts = CosmwasmCodeFamilyRepository::erroredCountsByChain();
        self::assertSame(1, $counts[self::CHAIN] ?? 0);

        $row = $this->row(familiesErrored: $counts[self::CHAIN]);
        self::assertSame(H::STATUS_YELLOW, H::deriveStatus(true, [$row], $this->schedule()));
    }

    /** The grouped aggregate returns the right count, per chain. */
    public function testTheAggregateGroupsErrorsByChain(): void
    {
        foreach ([80, 81, 82] as $codeId) {
            CosmwasmCodeFamilyRepository::seed(self::CHAIN, $codeId);
            CosmwasmCodeFamilyRepository::recordAttemptFailure(self::CHAIN, $codeId, 'node unreachable', 1);
        }
        CosmwasmCodeFamilyRepository::seed(8, 5);
        CosmwasmCodeFamilyRepository::recordAttemptFailure(8, 5, 'other chain problem', 1);
        // A clean family on chain 17 must not inflate the count.
        CosmwasmCodeFamilyRepository::seed(self::CHAIN, 83);

        $counts = CosmwasmCodeFamilyRepository::erroredCountsByChain();

        self::assertSame(3, $counts[self::CHAIN] ?? 0, 'three errored on Dungeon');
        self::assertSame(1, $counts[8] ?? 0, 'one on cosmos, kept separate');
    }

    // ── (2) what must NOT go yellow ─────────────────────────────────────

    /**
     * THE CONTROL THAT KEEPS THE COLOUR MEANINGFUL.
     *
     * A chain of ordinary DeFi contracts settles `not_cw721` with no
     * error. That is the scanner working, and it stays GREEN.
     */
    public function testSettledNotCw721FamiliesWithNoErrorsAreGreen(): void
    {
        foreach (range(80, 100) as $codeId) {
            CosmwasmCodeFamilyRepository::seed(self::CHAIN, $codeId);
            CosmwasmCodeFamilyRepository::recordClassification(
                self::CHAIN,
                $codeId,
                ['classification' => CosmwasmClassifier::NOT_CW721, 'reason' => 'no_cw721_queries',
                 'probes_ok' => '', 'probes_failed' => '', 'last_error' => '',
                 'classifier_version' => CosmwasmClassifier::VERSION],
                null,
                1
            );
        }

        $counts = CosmwasmCodeFamilyRepository::erroredCountsByChain();
        self::assertSame(0, $counts[self::CHAIN] ?? 0, 'a settled negative is not an error');

        $row = $this->row(
            familiesErrored: $counts[self::CHAIN] ?? 0,
            familyCounts: [CosmwasmClassifier::NOT_CW721 => 21]
        );
        self::assertSame(H::STATUS_GREEN, H::deriveStatus(true, [$row], $this->schedule()));
    }

    /** An empty family table is a legitimate zero, not a hidden failure. */
    public function testAnEmptyFamilyTableIsALegitimateZero(): void
    {
        self::assertSame([], CosmwasmCodeFamilyRepository::erroredCountsByChain());

        $row = $this->row(familiesErrored: 0);
        self::assertSame(0, $row['families_errored']);
        self::assertSame(H::STATUS_GREEN, H::deriveStatus(true, [$row], $this->schedule()));
    }

    // ── (3) precedence is preserved exactly ─────────────────────────────

    /** Errors on a chain nobody opted in → still IDLE, not yellow. */
    public function testErrorsOnANotOptedInChainStayIdle(): void
    {
        $row = $this->row(familiesErrored: 99, optedIn: false);

        self::assertSame(CosmwasmScanEligibility::NOT_OPTED_IN, $row['eligibility']);
        self::assertSame(99, $row['families_errored'], 'the row still reports it');
        self::assertSame(H::STATUS_IDLE, H::deriveStatus(true, [$row], $this->schedule()));
    }

    /**
     * Errors on paused / unsupported / allowlist-excluded chains never
     * enter the RGB arithmetic. Opted in but not scannable → BLOCKED.
     */
    public function testErrorsOnUnscannableChainsDoNotEnterTheArithmetic(): void
    {
        $cases = [
            'paused'      => $this->row(state: ChainCheckpointRepository::CW_STATE_PAUSED, familiesErrored: 42),
            'unsupported' => $this->row(state: ChainCheckpointRepository::CW_STATE_UNSUPPORTED, familiesErrored: 42),
            'excluded'    => $this->row(familiesErrored: 42, allowlist: [999]),
        ];

        foreach ($cases as $label => $row) {
            self::assertNotSame(CosmwasmScanEligibility::ELIGIBLE, $row['eligibility'], $label . ' precondition');
            self::assertSame(42, $row['families_errored'], $label . ' still reports on its own row');
            self::assertSame(
                H::STATUS_BLOCKED,
                H::deriveStatus(true, [$row], $this->schedule()),
                $label . ' is blocked, never yellow'
            );
        }
    }

    /**
     * A MIXED registry: one healthy scannable chain plus an excluded chain
     * carrying errors. The excluded chain must not drag the overall
     * status off green.
     */
    public function testAnExcludedChainsErrorsDoNotDegradeAHealthyOne(): void
    {
        $healthy  = $this->row(familiesErrored: 0);
        $excluded = $this->row(state: ChainCheckpointRepository::CW_STATE_PAUSED, familiesErrored: 500);

        self::assertSame(H::STATUS_GREEN, H::deriveStatus(true, [$healthy, $excluded], $this->schedule()));
    }

    /** The disabled gate still outranks green/yellow. */
    public function testTheDisabledGateStillOutranksTheNewSignal(): void
    {
        $row = $this->row(familiesErrored: 15);

        self::assertSame(H::STATUS_DISABLED, H::deriveStatus(false, [$row], $this->schedule()));
    }

    // ── (4) fail-closed ─────────────────────────────────────────────────

    /**
     * A FAILED AGGREGATE READ MUST NEVER BECOME ZERO ERRORS.
     *
     * Zero is the sentence that turns the panel green, so this read is
     * inside buildSummary()'s fail-closed try and must reach
     * STATUS_UNAVAILABLE.
     */
    public function testAFailedAggregateReadIsUnavailableNeverGreen(): void
    {
        ChainRepository::seed(self::CHAIN, 'dungeon', 'https://api.dungeongames.io', 'cosmos', 1);
        ChainCheckpointRepository::ensureExists(self::CHAIN);
        CosmwasmCodeFamilyRepository::$failReads = ['erroredCountsByChain'];

        $summary = H::buildSummary();

        self::assertSame(H::STATUS_UNAVAILABLE, $summary['status']);
        self::assertTrue($summary['data_unavailable']);
        self::assertNotSame(H::STATUS_GREEN, $summary['status']);
        self::assertNull($summary['totals'], 'no invented numbers');
        self::assertNull($summary['eligible_chain_count']);
    }

    // ── (5) the operator surface says it in words ───────────────────────
    //
    // RELOCATED IN VC-B3b, NOT WEAKENED.
    //
    // These two assertions were written against
    // CosmwasmScannerPanel::renderChainRow(). That method is gone: the
    // per-chain table moved to Chains ▸ NFT Discovery ▸ CosmWasm / CW-721
    // Discovery, which is now its single owner. The assertions follow the
    // surface — same strings, same singular/plural sentence, checked
    // against ChainsPage::render_cw_status_row().
    //
    // The sentence itself moved with them. The new status row already
    // showed "N errored"; the count alone is the half that sends an
    // operator looking for a rebuild, so the "eligible for retry" wording
    // was carried across verbatim rather than dropped as redundant. It now
    // sits on the same page as the Retry control that acts on it.

    /** The operator sees the count, and is told the work is retryable. */
    public function testTheStatusRowReportsTheUnresolvedCount(): void
    {
        $html = self::renderStatusRow($this->row(familiesErrored: 15));

        self::assertStringContainsString('15 code families have unresolved discovery errors', $html);
        self::assertStringContainsString('remain eligible for retry', $html);
    }

    /** One error is one sentence, not "1 code families". */
    public function testTheSingularSentenceSurvivedTheMove(): void
    {
        $html = self::renderStatusRow($this->row(familiesErrored: 1));

        self::assertStringContainsString('1 code family has unresolved discovery errors', $html);
        self::assertStringContainsString('remains eligible for retry', $html);
    }

    /** A clean chain says nothing about errors. */
    public function testTheStatusRowIsSilentWhenThereAreNoFamilyErrors(): void
    {
        $html = self::renderStatusRow($this->row(familiesErrored: 0));

        self::assertStringNotContainsString('unresolved discovery errors', $html);
    }

    /**
     * THE GUARANTEE, STATED AS A GUARANTEE.
     *
     * PR #196's real claim is not "some string appears" — it is that a
     * chain holding family errors is never presented as a clean one. So
     * this renders both and asserts they differ, which no amount of copy
     * editing on either surface can accidentally satisfy.
     */
    public function testAChainWithFamilyErrorsIsNotRenderedLikeACleanOne(): void
    {
        $errored = self::renderStatusRow($this->row(familiesErrored: 15));
        $clean   = self::renderStatusRow($this->row(familiesErrored: 0));

        self::assertNotSame($clean, $errored, 'errored and clean must not render identically');
        self::assertStringContainsString('15 errored', $errored);
        self::assertStringNotContainsString('errored', $clean);
    }

    /**
     * The panel that used to carry this no longer offers the method, so a
     * future edit cannot quietly restore a second, divergent copy of the
     * per-chain error display.
     */
    public function testTheOldPanelRowRendererIsGone(): void
    {
        self::assertFalse(
            method_exists(\BCC\Trust\Onchain\Admin\Views\CosmwasmScannerPanel::class, 'renderChainRow'),
            'the per-chain renderer moved to ChainsPage; two copies is what VC-B3b removed'
        );
    }

    /** @param array<string, mixed> $row */
    private static function renderStatusRow(array $row): string
    {
        $m = new \ReflectionMethod(
            \BCC\Trust\Onchain\Admin\ChainsPage::class,
            'render_cw_status_row'
        );
        $m->setAccessible(true);

        ob_start();
        $m->invoke(null, $row);

        return (string) ob_get_clean();
    }
}
