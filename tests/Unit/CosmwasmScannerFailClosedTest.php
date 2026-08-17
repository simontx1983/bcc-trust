<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\Views\CosmwasmScannerPanel;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;
use BCC\Trust\Onchain\Repositories\CosmwasmContractRepository;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The scanner panel FAILS CLOSED: a failed DB read is reported as
 * "unavailable", never as a healthy, empty, up-to-date scanner.
 *
 * ── THE SHAPE OF THE BUG THIS PREVENTS ──────────────────────────────────
 * `buildSummary()` composes four reads and derives everything else in
 * PHP. Every one of those reads used to answer a SQL error with its empty
 * shape, and the derivation is perfectly happy with empty input: zero
 * families, zero contracts, nothing pending, no chain errors, no stale
 * chains — which lands on GREEN with a tidy row of zeroes. An operator
 * looking at that page is being told the scanner has looked and found
 * nothing, when in fact nobody managed to look.
 *
 * Three states have to stay distinguishable and all three are asserted
 * here: populated, genuinely empty, and failed.
 */
#[CoversClass(CosmwasmDiscoveryHealthSnapshot::class)]
#[CoversClass(CosmwasmScannerPanel::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmwasmScannerFailClosedTest extends TestCase
{
    private const CHAIN_ID = 8;
    private const CONTRACT = 'cosmos1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-discovery-stubs.php';

        CosmwasmCodeFamilyRepository::reset();
        CosmwasmContractRepository::reset();
        CollectionRepository::reset();
        ChainCheckpointRepository::reset();
        ChainRepository::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestCronStore::reset();

        ChainRepository::seed(self::CHAIN_ID, 'cosmos');
        ChainCheckpointRepository::ensureExists(self::CHAIN_ID);
    }

    /** Populate the scanner with one classified family and one candidate. */
    private function seedInventory(): void
    {
        CosmwasmCodeFamilyRepository::seed(
            self::CHAIN_ID,
            434,
            CosmwasmClassifier::CONFIRMED,
            'deadbeef',
            CosmwasmClassifier::VERSION,
            '2026-08-06 00:00:00'
        );
        CosmwasmContractRepository::seed(
            self::CHAIN_ID,
            self::CONTRACT,
            CosmwasmClassifier::CONFIRMED,
            434,
            false,
            false,
            CosmwasmClassifier::VERSION,
            '2026-08-06 00:00:00'
        );
    }

    // ── STATE 1: the reads work and there is data ───────────────────────

    public function test_a_populated_scanner_reports_its_counts(): void
    {
        $this->seedInventory();

        $summary = CosmwasmDiscoveryHealthSnapshot::buildSummary();

        self::assertFalse($summary['data_unavailable']);
        self::assertNull($summary['unavailable_reason']);
        self::assertIsArray($summary['totals']);
        self::assertSame(1, $summary['totals']['families']);
        self::assertSame(1, $summary['totals']['candidates']);
        self::assertCount(1, $summary['chains']);
    }

    // ── STATE 2: the reads work and there is genuinely nothing ──────────

    /**
     * The state that MUST stay distinct from a failure: a real, empty
     * scanner. Zeroes here are an answer, and the panel is right to print
     * them.
     */
    public function test_a_genuinely_empty_scanner_reports_zeroes_and_stays_available(): void
    {
        $summary = CosmwasmDiscoveryHealthSnapshot::buildSummary();

        self::assertFalse($summary['data_unavailable'], 'empty is not the same as unreadable');
        self::assertIsArray($summary['totals']);
        self::assertSame(0, $summary['totals']['families']);
        self::assertSame(0, $summary['totals']['contracts']);
        self::assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_UNAVAILABLE, $summary['status']);
    }

    // ── STATE 3: a read did not run ─────────────────────────────────────

    /**
     * Each of the four reads, one at a time. Any single failure is enough
     * to make the whole picture untrustworthy — three good aggregates and
     * one missing one still compose into confident wrong numbers.
     *
     * @return list<array{0: string}>
     */
    public static function failingRead(): array
    {
        return [
            ['checkpoints'],
            ['countsByChainAndClassification'],
            ['pendingCountsByChain'],
            ['inventoryByChain'],
        ];
    }

    #[DataProvider('failingRead')]
    public function test_any_failed_read_makes_the_whole_summary_unavailable(string $read): void
    {
        $this->seedInventory();
        $this->armFailure($read);

        $summary = CosmwasmDiscoveryHealthSnapshot::buildSummary();

        self::assertTrue($summary['data_unavailable'], $read . ' failure was absorbed');
        self::assertSame(CosmwasmDiscoveryHealthSnapshot::STATUS_UNAVAILABLE, $summary['status']);
        self::assertNull($summary['totals'], 'a failed read must not be reported as counts of any kind');
        self::assertSame([], $summary['chains']);
        self::assertNull($summary['working_chain']);
        self::assertNull($summary['next_chain']);
        self::assertIsString($summary['unavailable_reason']);
        self::assertStringContainsString('database read failed', (string) $summary['unavailable_reason']);
    }

    /**
     * The specific lie that must be impossible: GREEN with zeroes.
     *
     * `status` is checked against every "the scanner is fine" value, not
     * just green, because yellow/disabled would be equally misread as
     * "the system answered".
     *
     * IDLE IS ON THAT LIST AND IS THE MOST DANGEROUS ENTRY. It is the one
     * status that a failed read could reach honestly-looking: the failure
     * path carries an EMPTY chain list, and "no chain is opted in" is
     * trivially true of no chains at all. A database outage reported as
     * "Idle — nothing to do" is exactly the green-with-zeroes lie in a
     * calmer voice, and it is worse, because idle invites no action at all.
     *
     * BLOCKED IS THE SAME SHAPE OF DANGER and is asserted for the same
     * reason: it is a confident, explanatory verdict about a SELECTION,
     * and the failure path knows nothing about any selection because the
     * read that carries the opt-ins is the one that did not run. Telling
     * an operator to go fix their chain choices during a database outage
     * is a wrong instruction delivered calmly. `unavailable` outranks it,
     * exactly as it outranks idle.
     */
    #[DataProvider('failingRead')]
    public function test_a_failed_read_can_never_report_a_healthy_zero(string $read): void
    {
        $this->armFailure($read);

        $summary = CosmwasmDiscoveryHealthSnapshot::buildSummary();

        self::assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN, $summary['status']);
        self::assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_YELLOW, $summary['status']);
        self::assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_DISABLED, $summary['status']);
        self::assertNotSame(CosmwasmDiscoveryHealthSnapshot::STATUS_RED, $summary['status']);
        self::assertNotSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_IDLE,
            $summary['status'],
            '"we could not look" must never be reported as "there is nothing to do"'
        );
        self::assertNotSame(
            CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED,
            $summary['status'],
            '"we could not look" must never be reported as "your selection cannot be scanned"'
        );
        self::assertNull($summary['totals']);
    }

    /**
     * The same precedence at the RENDER layer: a failed read gets the
     * unavailable treatment, never the idle one. The panel branches on
     * `data_unavailable` first for this reason — an idle notice on a page
     * whose numbers could not be read would tell an operator the calmest
     * possible untruth.
     */
    public function test_the_panel_never_shows_the_idle_notice_for_a_failed_read(): void
    {
        $this->armFailure('checkpoints');

        $html = $this->renderPanel(CosmwasmDiscoveryHealthSnapshot::buildSummary());

        self::assertStringContainsString('Scanner figures are unavailable', $html);
        self::assertStringNotContainsString('Idle — no chains enabled', $html);
        self::assertStringNotContainsString('IDLE', $html);
    }

    /**
     * The same precedence for the other calm verdict. A failed read must
     * not produce the blocked notice either: that copy tells an operator
     * to go and change which chains they have opted in, and it would be
     * telling them so on the strength of a read that never ran.
     */
    public function test_the_panel_never_shows_the_blocked_notice_for_a_failed_read(): void
    {
        $this->armFailure('checkpoints');

        $html = $this->renderPanel(CosmwasmDiscoveryHealthSnapshot::buildSummary());

        self::assertStringContainsString('Scanner figures are unavailable', $html);
        self::assertStringNotContainsString('No opted-in chain can be scanned', $html);
        self::assertStringNotContainsString('BLOCKED', $html);
    }

    /**
     * The cron picture is NOT a database read, and it is the one thing
     * still worth knowing when the DB is unreachable — otherwise a
     * stalled cron and a broken database look identical.
     */
    public function test_the_cron_schedule_survives_an_unavailable_summary(): void
    {
        $this->armFailure('inventoryByChain');

        $summary = CosmwasmDiscoveryHealthSnapshot::buildSummary();

        self::assertCount(4, $summary['schedule']);
    }

    // ── the rendered panel ──────────────────────────────────────────────

    public function test_the_panel_prints_no_invented_numbers_when_data_is_unavailable(): void
    {
        $this->seedInventory();
        $this->armFailure('countsByChainAndClassification');

        $html = $this->renderPanel(CosmwasmDiscoveryHealthSnapshot::buildSummary());

        self::assertStringContainsString('Scanner figures are unavailable', $html);
        self::assertStringContainsString('UNAVAILABLE', $html);

        // The count tiles are gone entirely — not rendered as 0.
        self::assertStringNotContainsString('Code families known', $html);
        self::assertStringNotContainsString('Unverified candidates', $html);
        self::assertStringNotContainsString('Hidden by a rule', $html);
        self::assertStringContainsString('counts unavailable', $html);

        // And the chain table does not claim there are no Cosmos chains.
        self::assertStringNotContainsString('No active Cosmos chains are registered', $html);
    }

    public function test_the_panel_still_prints_real_counts_when_the_reads_work(): void
    {
        $this->seedInventory();

        $html = $this->renderPanel(CosmwasmDiscoveryHealthSnapshot::buildSummary());

        self::assertStringContainsString('Code families known', $html);
        self::assertStringContainsString('cosmos', $html);
        self::assertStringNotContainsString('Scanner figures are unavailable', $html);
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function armFailure(string $read): void
    {
        switch ($read) {
            case 'checkpoints':
                ChainCheckpointRepository::$failGetAll = true;

                return;
            case 'inventoryByChain':
                CosmwasmContractRepository::$failReads = ['inventoryByChain'];

                return;
            default:
                CosmwasmCodeFamilyRepository::$failReads = [$read];
        }
    }

    /** @param array<string, mixed> $summary */
    private function renderPanel(array $summary): string
    {
        ob_start();
        CosmwasmScannerPanel::render($summary);
        $html = ob_get_clean();

        self::assertIsString($html);

        return $html;
    }
}
