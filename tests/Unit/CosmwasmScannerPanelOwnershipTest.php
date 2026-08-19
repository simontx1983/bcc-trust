<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\ChainsPage;
use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use BCC\Trust\Onchain\Admin\Views\CosmwasmScannerPanel;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * What the scanner panel still owns after VC-B3b — and what it must not.
 *
 * ── THE PROBLEM THIS FILE IS THE ANSWER TO ──────────────────────────────
 * The per-chain table was duplicated deliberately during VC-B3a: status on
 * two surfaces, one authority, controls on one. That was bounded and
 * temporary, and the boundary was a promise to remove the copy once parity
 * was proven. VC-B3b removes it.
 *
 * A deletion is easy to get wrong in two opposite directions, and both are
 * asserted here. Delete too little and the two surfaces disagree about the
 * same chain, which is the failure the duplication was accepted to avoid
 * becoming permanent. Delete too much and information nothing else reports
 * simply vanishes — which is what happened to the scanner-wide coverage
 * and rotation lines on the first pass of this batch, before the tests
 * below caught it.
 *
 * ── AND A RUNTIME CHECK PHP LINT CANNOT DO ──────────────────────────────
 * `php -l` parses; it does not resolve a static call. This file renders
 * the panel for real, which is the only way an undefined-method fatal in
 * a rarely-taken branch shows up before an operator finds it.
 */
#[CoversClass(CosmwasmScannerPanel::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmwasmScannerPanelOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The CosmWasm stub family, NOT the admin-action one: this file
        // renders a read-only panel and drives no route, so it needs the
        // snapshot vocabulary (the STATUS_* constants) rather than the
        // wp_die / nonce shims.
        require_once __DIR__ . '/../Stubs/cosmwasm-discovery-stubs.php';

        $_GET = [];
    }

    /**
     * A complete summary, so the panel takes its ordinary path rather than
     * an early return.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function summary(array $overrides = []): array
    {
        return array_merge([
            'status'               => CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN,
            'discovery_enabled'    => true,
            'backfill_enabled'     => true,
            'disabled_reason'      => null,
            'data_unavailable'     => false,
            'unavailable_reason'   => null,
            'issues'               => [],
            'schedule'             => [
                [
                    'label'   => 'Metadata refresh',
                    'cadence' => 'daily',
                    'hook'    => 'bcc_cosmwasm_metadata_refresh',
                    'next'    => null,
                ],
            ],
            'totals'               => ['families' => 12, 'contracts' => 34, 'candidates' => 5],
            'eligible_chain_count' => 1,
            'allowlist_chain_ids'  => null,
            'working_chain'        => ['slug' => 'cosmos'],
            'next_chain'           => ['slug' => 'juno'],
            'chains'               => [
                ['chain_id' => 4, 'slug' => 'cosmos', 'name' => 'Cosmos Hub'],
                ['chain_id' => 9, 'slug' => 'juno', 'name' => 'Juno'],
            ],
        ], $overrides);
    }

    /** @param array<string, mixed> $overrides */
    private function render(array $overrides = []): string
    {
        ob_start();
        CosmwasmScannerPanel::render($this->summary($overrides));
        $html = ob_get_clean();

        self::assertIsString($html);

        return $html;
    }

    // ── Runtime coherence ───────────────────────────────────────────────

    /**
     * THE REGRESSION THIS FILE WAS OPENED FOR.
     *
     * Mid-batch the panel called a `renderMovedNotice()` that did not
     * exist yet. `php -l` reported the file clean — it parses the call
     * without resolving it — so nothing failed until the page was actually
     * rendered. Every branch below is rendered, not linted.
     */
    public function testThePanelRendersWithoutAFatal(): void
    {
        $this->assertNotSame('', $this->render());
    }

    public function testEveryStatusBranchRendersWithoutAFatal(): void
    {
        foreach ([
            CosmwasmDiscoveryHealthSnapshot::STATUS_GREEN,
            CosmwasmDiscoveryHealthSnapshot::STATUS_YELLOW,
            CosmwasmDiscoveryHealthSnapshot::STATUS_RED,
            CosmwasmDiscoveryHealthSnapshot::STATUS_IDLE,
            CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED,
            CosmwasmDiscoveryHealthSnapshot::STATUS_DISABLED,
            CosmwasmDiscoveryHealthSnapshot::STATUS_UNAVAILABLE,
        ] as $status) {
            $this->assertNotSame('', $this->render(['status' => $status]), $status);
        }

        // The two gate-off branches and the no-chains branch as well.
        $this->assertNotSame('', $this->render(['discovery_enabled' => false]));
        $this->assertNotSame('', $this->render(['backfill_enabled' => false]));
        $this->assertNotSame('', $this->render(['chains' => []]));
    }

    /**
     * The unavailable path hides every DB-derived block, so it is the one
     * most likely to reach an undefined helper unnoticed.
     */
    public function testTheUnavailableBranchRendersWithoutAFatal(): void
    {
        $html = $this->render([
            'data_unavailable'   => true,
            'unavailable_reason' => 'the read failed',
            'totals'             => null,
        ]);

        $this->assertStringContainsString('Scanner figures are unavailable', $html);
    }

    // ── What it must NOT own ────────────────────────────────────────────

    public function testThePerChainTableIsGone(): void
    {
        $html = $this->render();

        // The identity of a specific chain no longer appears in a row of
        // its own — the rotation lines below still NAME a chain, which is
        // a different, aggregate fact.
        $this->assertStringNotContainsString('Cosmos Hub', $html, 'the per-chain table moved');
        $this->assertStringNotContainsString('<table class="widefat striped">', $html);
    }

    public function testTheFiveRemovedRenderersAreGone(): void
    {
        foreach ([
            'renderChains',
            'renderChainRow',
            'renderDiscoveryCell',
            'startTitle',
            'stateColor',
        ] as $method) {
            $this->assertFalse(
                method_exists(CosmwasmScannerPanel::class, $method),
                "{$method}() moved to ChainsPage; two copies is what this batch removed"
            );
        }
    }

    public function testThePanelIsAReadOnlySurface(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('data-nonce-action', $html);
        $this->assertStringNotContainsString('bcc_vc_action', $html);
    }

    // ── What it still owns, and why ─────────────────────────────────────

    /**
     * The aggregate coverage line. NFT Discovery is one row per chain and
     * never states "N of M are eligible", so removing this with the table
     * would have deleted the answer to "how many of these will ever be
     * scanned?" from every surface.
     */
    public function testTheScannerWideCoverageLineRemains(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('listed chains are eligible for the scanner', $html);
        $this->assertStringContainsString('<strong>1', $html);
    }

    /** Fail-closed: an uncountable eligibility says so, never "0 of 2". */
    public function testAnUncountableEligibilityIsNotPrintedAsZero(): void
    {
        $html = $this->render(['eligible_chain_count' => null]);

        $this->assertStringContainsString('could not be worked out', $html);
        $this->assertStringNotContainsString('0 of 2', $html);
    }

    /**
     * The rotation line — also scanner-wide, and also absent from NFT
     * Discovery. It names a chain, which is exactly why the panel test
     * that asserted a slug still passes: that assertion was always about
     * the rotation, not about the deleted table.
     */
    public function testTheRotationLineRemains(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Currently working through', $html);
        $this->assertStringContainsString('Next backfill slice goes to', $html);
    }

    public function testTheAggregateTotalsAndScheduleRemain(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Code families known', $html);
        $this->assertStringContainsString('bcc_cosmwasm_metadata_refresh', $html);
    }

    /**
     * Per-COLLECTION detail, rendered from the Verify Collections row loop.
     * NFT Discovery is organised per chain and does not own it, so it stays
     * — and it stays PUBLIC, because that page calls it.
     */
    public function testCandidateDetailRemainsAvailableToItsCaller(): void
    {
        $this->assertTrue(method_exists(CosmwasmScannerPanel::class, 'renderCandidateDetail'));

        $method = new \ReflectionMethod(CosmwasmScannerPanel::class, 'renderCandidateDetail');
        $this->assertTrue($method->isPublic(), 'VerifyCollectionsPage calls this from its row loop');

        $source = (string) file_get_contents(
            __DIR__ . '/../../app/Domain/Onchain/Admin/VerifyCollectionsPage.php'
        );
        $this->assertStringContainsString('CosmwasmScannerPanel::renderCandidateDetail', $source);
        $this->assertStringContainsString('CosmwasmScannerPanel::render(', $source);
    }

    // ── The forwarding notice ───────────────────────────────────────────

    public function testTheMovedNoticeLinksToTheCanonicalPageExactlyOnce(): void
    {
        $html = $this->render();

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();

        $matching = [];
        foreach ($doc->getElementsByTagName('a') as $a) {
            if (!$a instanceof \DOMElement) {
                continue;
            }
            $href = $a->getAttribute('href');
            if (str_contains($href, 'subtab=' . ChainsPage::SUBTAB_NFT_DISCOVERY)) {
                $matching[] = $a;
            }
        }

        $this->assertCount(1, $matching, 'one link, not a wall of them');

        // Parsed through the DOM so the entity-encoded `&` in the rendered
        // href cannot hide a wrong target.
        $href = $matching[0]->getAttribute('href');
        $this->assertStringContainsString('page=' . ChainsPage::PAGE_SLUG, $href);
        $this->assertSame('Open NFT Discovery', trim($matching[0]->textContent));
    }

    /**
     * The notice is navigation, not a control: no form, no nonce, and
     * nothing that reads.
     */
    public function testTheMovedNoticeIsInert(): void
    {
        $method = new \ReflectionMethod(CosmwasmScannerPanel::class, 'renderMovedNotice');
        $method->setAccessible(true);

        ob_start();
        $method->invoke(null);
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('nonce', $html);
        $this->assertStringNotContainsString('<button', $html);
        $this->assertStringNotContainsString('<input', $html);

        // It takes no arguments and reads no source of truth: a notice
        // that queried would put a database read on the fail-closed path
        // this panel deliberately keeps clear.
        $this->assertSame(0, $method->getNumberOfParameters());
    }

    /**
     * It renders even when the summary is unavailable. A failed database
     * read is exactly the moment an operator goes looking for the controls
     * this panel used to carry, and a pointer that disappears with the
     * numbers is a pointer missing when it is most needed.
     */
    public function testTheMovedNoticeSurvivesAnUnavailableSummary(): void
    {
        $html = $this->render(['data_unavailable' => true, 'totals' => null]);

        $this->assertStringContainsString('Open NFT Discovery', $html);
    }

    /**
     * TWO CLAIMS THE NOTICE MUST NOT MAKE.
     *
     * NFT discovery is not a Cosmos concern — EVM NFTs have their own
     * worker and Solana arrives through Helius — so a notice implying the
     * destination is CosmWasm-only would misdescribe the page it is
     * sending the operator to. And following a link must never read as
     * starting work.
     */
    public function testTheMovedNoticeMakesNeitherFalseClaim(): void
    {
        $html = strtolower(strip_tags($this->render()));

        $this->assertStringNotContainsString('nft discovery only supports', $html);
        $this->assertStringNotContainsString('cosmos chains only', $html);
        $this->assertStringNotContainsString('starts a scan', $html);
        $this->assertStringNotContainsString('begins scanning', $html);

        // And it says the true version of both out loud.
        $this->assertStringContainsString('starts no scan', $html);
        $this->assertStringContainsString('other nft engines', $html);
    }

    // ── Copy that pointed at the deleted table ──────────────────────────

    /**
     * Directions to a table that is no longer there are worse than no
     * directions. Every notice that used to say "below" now names the
     * canonical page.
     */
    public function testNoNoticeStillPointsAtTheRemovedColumns(): void
    {
        foreach ([
            ['status' => CosmwasmDiscoveryHealthSnapshot::STATUS_IDLE],
            ['status' => CosmwasmDiscoveryHealthSnapshot::STATUS_BLOCKED],
            ['discovery_enabled' => false],
            ['backfill_enabled' => false],
        ] as $overrides) {
            $html = $this->render($overrides);

            $this->assertStringNotContainsString('columns below', $html);
            $this->assertStringNotContainsString('controls below', $html);
            $this->assertStringNotContainsString('a chain below', $html);
            $this->assertStringNotContainsString('Start controls below', $html);
        }
    }

    // ── Dead code ───────────────────────────────────────────────────────

    /**
     * The removed handlers and constants have no reference left anywhere
     * in `app/` or `tests/` — the check the owner asked for by name, run
     * against the tree rather than asserted from memory.
     */
    public function testTheRemovedScannerHandlersHaveZeroReferences(): void
    {
        $root = dirname(__DIR__, 2);

        $needles = [
            'handleScannerPause',
            'handleScannerBackfill',
            'handleScannerForceRetry',
            'VerifyCollectionsPage::NONCE_KEY',
            'VerifyCollectionsPage::NONCE_NAME',
        ];

        // A dead-code guard has to NAME what it forbids, so the two files
        // doing the forbidding are skipped — otherwise this check could
        // never pass, and the usual way that gets "fixed" is by deleting
        // the guard. Listed explicitly rather than pattern-matched: the
        // exemption is two files wide, and a third one appearing should be
        // a decision rather than a silent widening.
        $guards = ['CosmwasmScannerPanelOwnershipTest.php', 'VerifyCollectionsHideToggleTest.php'];

        $hits = [];
        foreach ([$root . '/app', $root . '/tests'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
            foreach ($it as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                if (in_array($file->getFilename(), $guards, true)) {
                    continue;
                }

                $contents = (string) file_get_contents($file->getPathname());
                foreach ($needles as $needle) {
                    if (str_contains($contents, $needle)) {
                        $hits[] = $needle . ' in ' . $file->getFilename();
                    }
                }
            }
        }

        $this->assertSame([], $hits, 'removed symbols must have no surviving reference');
    }

    /** No import left behind by the move. */
    public function testTheMovedImportsAreGoneFromBothFiles(): void
    {
        $vcp = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Admin/VerifyCollectionsPage.php'
        );

        foreach ([
            'use BCC\Trust\Onchain\Support\CosmwasmTickBudget;',
            'use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;',
            'use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;',
            'use BCC\Trust\Onchain\Support\CosmwasmDiscoveryGate;',
        ] as $import) {
            $this->assertStringNotContainsString($import, $vcp, 'unused import must not survive the move');
        }

        // And the one that legitimately stays, because it still has a caller.
        $this->assertStringContainsString(
            'use BCC\Trust\Onchain\Repositories\CosmwasmCodeFamilyRepository;',
            $vcp
        );
        $this->assertStringContainsString('CosmwasmCodeFamilyRepository::findManyForChains', $vcp);

        $panel = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Admin/Views/CosmwasmScannerPanel.php'
        );

        foreach ([
            'use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;',
            'use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;',
        ] as $import) {
            $this->assertStringNotContainsString($import, $panel);
        }
    }

    /**
     * The legacy dispatcher is retained ONLY as an inert refusal, and it
     * still has a caller — so this is a live path, not dead code kept for
     * sentiment.
     */
    public function testTheInertLegacyRefusalStillHasACaller(): void
    {
        $vcp = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Admin/VerifyCollectionsPage.php'
        );

        $this->assertStringContainsString('self::handlePost()', $vcp);
        $this->assertTrue(method_exists(VerifyCollectionsPage::class, 'render_page'));
    }
}
