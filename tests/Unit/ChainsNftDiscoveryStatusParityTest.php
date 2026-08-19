<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\ChainsPage;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * VC-B3a: scanner-status parity at Chains ▸ NFT Discovery ▸ CosmWasm/CW-721.
 *
 * ── WHY PARITY COMES BEFORE THE CONTROLS ────────────────────────────────
 * VC-B3b moves Pause / Resume / Backfill / Retry here. Those controls are
 * unusable without the state they act on — "should I resume this chain?"
 * cannot be answered from an eligibility verdict — so the readout lands
 * and is proven first. Until then the four controls stay put.
 *
 * ── WHAT THIS FILE DEFENDS ──────────────────────────────────────────────
 * The old panel reads 19 status fields; the VC-B2 tab read 8, of which 7
 * overlapped. Twelve values were therefore missing here. The failure this
 * guards against is a SILENT one: if a field quietly stops rendering, an
 * operator concludes a chain is healthy when the snapshot says it is
 * erroring — which is exactly the regression PR #196 existed to prevent.
 *
 * Every assertion uses SENTINEL values no calculation would produce, so a
 * renderer that recomputed a label instead of printing the supplied one
 * fails rather than coincidentally agreeing.
 */
#[CoversClass(ChainsPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ChainsNftDiscoveryStatusParityTest extends TestCase
{
    private const CHAIN_ID = 4;

    /**
     * The twelve values that were missing from the tab before VC-B3a.
     * `state` and `state_label` are a raw/derived PAIR — the row carries
     * both and the tab prints the derived one — so the count of distinct
     * *rendered* concepts is eleven, from twelve supplied keys.
     */
    private const MIGRATED_KEYS = [
        'state', 'state_label', 'progress_label',
        'families_pending', 'families_by_classification', 'families_errored',
        'contracts_inspected', 'contracts_denied', 'candidates',
        'last_discovery_age_seconds', 'metadata_refreshed_at', 'last_error',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/chains-cw-discovery-stubs.php';

        \BccAdminTestState::reset();
        ChainRepository::reset();
        CosmwasmDiscoveryWorker::reset();
        CosmwasmDiscoveryHealthSnapshot::reset();

        $_GET  = [];
        $_POST = [];
    }

    /**
     * A row whose every migrated value is distinctive.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function sentinelRow(array $overrides = []): array
    {
        return array_merge([
            'chain_id'                   => self::CHAIN_ID,
            'slug'                       => 'cosmos',
            'name'                       => 'Cosmos Hub',
            'discovery_opted_in'         => true,
            'unsupported'                => false,
            'paused'                     => false,
            'eligibility'                => CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ELIGIBLE,
            'eligibility_reason'         => 'SENTINEL-REASON.',
            'state'                      => 'backfilling',
            'state_label'                => 'SENTINEL-STATE-LABEL',
            'progress_label'             => 'SENTINEL-PROGRESS-LABEL',
            'families_pending'           => 7771,
            'families_by_classification' => ['confirmed_cw721' => 331, 'probable_cw721' => 442],
            'families_errored'           => 0,
            'contracts_inspected'        => 5551,
            'contracts_denied'           => 6661,
            'candidates'                 => 4441,
            'last_discovery_age_seconds' => 9991,
            'metadata_refreshed_at'      => '2026-08-19 11:22:33',
            'last_error'                 => null,
        ], $overrides);
    }

    /** @param list<array<string, mixed>> $rows */
    private function render(array $rows): string
    {
        ob_start();
        ChainsPage::render_cw_discovery_section($rows);

        return (string) ob_get_clean();
    }

    // ── The inventory ───────────────────────────────────────────────────

    public function testEveryMigratedStatusValueRenders(): void
    {
        $html = $this->render([$this->sentinelRow(['last_error' => 'SENTINEL-UPSTREAM-ERROR'])]);

        // Derived labels: printed as supplied, not recomputed.
        $this->assertStringContainsString('SENTINEL-STATE-LABEL', $html);
        $this->assertStringContainsString('SENTINEL-PROGRESS-LABEL', $html);

        // Classification queue.
        $this->assertStringContainsString('7771', $html, 'families_pending');
        $this->assertStringContainsString('773', $html, 'cw721Total of the supplied breakdown (331+442)');

        // Inventory.
        $this->assertStringContainsString('5551', $html, 'contracts_inspected');
        $this->assertStringContainsString('6661', $html, 'contracts_denied');
        $this->assertStringContainsString('4441', $html, 'candidates');

        // Freshness.
        $this->assertStringContainsString('9991', $html, 'last_discovery_age_seconds');
        $this->assertStringContainsString('2026-08-19 11:22:33', $html, 'metadata_refreshed_at');

        // Errors.
        $this->assertStringContainsString('SENTINEL-UPSTREAM-ERROR', $html, 'last_error');
    }

    public function testTheMigratedKeySetIsExactlyTheTwelveIdentified(): void
    {
        // Guards the inventory itself: if someone adds a status field to the
        // snapshot and renders it in the panel but not here, the parity
        // claim silently narrows. This pins the agreed set.
        $this->assertCount(12, self::MIGRATED_KEYS);
        $this->assertSame(self::MIGRATED_KEYS, array_values(array_unique(self::MIGRATED_KEYS)));

        $row = $this->sentinelRow();
        foreach (self::MIGRATED_KEYS as $key) {
            $this->assertArrayHasKey($key, $row, "the snapshot row must carry `{$key}`");
        }
    }

    // ── PR #196 ─────────────────────────────────────────────────────────

    public function testFamiliesErroredRendersAndIsVisuallyDistinct(): void
    {
        $html = $this->render([$this->sentinelRow(['families_errored' => 8881])]);

        $this->assertStringContainsString('8881', $html);
        $this->assertStringContainsString('errored', $html);
        // Stated in the alert colour, not folded into a neutral total.
        $this->assertMatchesRegularExpression('/#d63638[^<]*>\s*8881/s', $html);
    }

    public function testAChainWithFamilyErrorsIsNotShownAsClean(): void
    {
        $clean   = $this->render([$this->sentinelRow(['families_errored' => 0])]);
        $errored = $this->render([$this->sentinelRow(['families_errored' => 8881])]);

        $this->assertStringNotContainsString('errored', $clean, 'a clean chain must not claim errors');
        $this->assertStringContainsString('errored', $errored);
        $this->assertNotSame($clean, $errored, 'the two states must be distinguishable on screen');
    }

    public function testFamiliesErroredFailsClosedOnRubbishInput(): void
    {
        foreach ([null, 'not-a-number', [], false] as $rubbish) {
            $html = $this->render([$this->sentinelRow(['families_errored' => $rubbish])]);
            $this->assertStringNotContainsString(
                'errored',
                $html,
                'an unreadable count must read as zero errored, never as an invented alarm'
            );
        }
    }

    // ── last_error handling ─────────────────────────────────────────────

    public function testLastErrorIsEscapedAndKeptBehindADisclosure(): void
    {
        $hostile = '<script>alert(1)</script> & "quoted" <b>bold</b>';
        $html    = $this->render([$this->sentinelRow(['last_error' => $hostile])]);

        // Escaped, not injected.
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);

        // Same treatment the panel gives it: labelled as upstream, behind a
        // disclosure, not pasted inline as though we wrote it.
        $this->assertStringContainsString('<details>', $html);
        $this->assertStringContainsString('Last recorded reason', $html);
    }

    public function testNoErrorMeansNoDisclosureAtAll(): void
    {
        $html = $this->render([$this->sentinelRow(['last_error' => null])]);

        $this->assertStringNotContainsString('Last recorded reason', $html);
    }

    /**
     * NEGATIVE: prohibited detail must not survive to either surface.
     *
     * `cw_last_error` demonstrably carries `$e->getMessage()` and raw LCD
     * response bodies — CosmwasmClassifier::sanitizeExcerpt() only strips
     * control characters and truncates, so the stored text is arbitrary.
     * esc_html() would render every one of these perfectly safely and still
     * disclose them.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hostileErrors(): array
    {
        return [
            'credentialed url' => ['GET https://lcd.example.com/path?api_key=SUPERSECRET99 failed', 'SUPERSECRET99'],
            'bare url'         => ['could not reach https://rpc.internal.example:26657/status', 'rpc.internal.example'],
            'windows path'     => ['failed opening C:\\Users\\simon\\secrets\\key.pem', 'C:\\Users\\simon'],
            'posix path'       => ['include failed in /home/deploy/app/wp-config.php', '/home/deploy'],
            'sql'              => ['SELECT * FROM wp_bcc_chains WHERE id = 4', 'wp_bcc_chains'],
            'exception class'  => ['GuzzleHttp\\Exception\\ConnectException: node down', 'ConnectException'],
            'stack frame'      => ['#0 /var/www/app/Worker.php(88): run()', 'Worker.php'],
            'api key param'    => ['auth failed: api_key=abc123def456ghi789', 'abc123def456ghi789'],
            'long hex token'   => ['signature 0a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f6071 rejected', '0a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f6071'],
        ];
    }

    #[DataProvider('hostileErrors')]
    public function testProhibitedDetailNeverReachesTheNftDiscoveryPage(string $stored, string $forbidden): void
    {
        $html = $this->render([$this->sentinelRow(['last_error' => $stored])]);

        $this->assertStringNotContainsString($forbidden, $html);
        // …and not merely because it was HTML-escaped into a different shape.
        $this->assertStringNotContainsString($forbidden, html_entity_decode($html, ENT_QUOTES));
    }

    #[DataProvider('hostileErrors')]
    public function testTheSameRedactionAppliesToTheOldScannerPanel(string $stored, string $forbidden): void
    {
        // The two surfaces must not diverge on what an operator may see.
        $safe = \BCC\Trust\Onchain\Admin\AdminActionSupport::operatorSafeExcerpt($stored);

        $this->assertStringNotContainsString($forbidden, $safe);
    }

    /**
     * POSITIVE: the useful half survives. A redactor that returned "[error]"
     * for everything would pass every negative test above and be useless.
     */
    public function testControlledOperationalReasonsSurviveIntact(): void
    {
        foreach ([
            'wasm module not available (HTTP 501)',
            'resumed pagination cursor returned an empty final page — restarting walk',
            'incremental reverse read did not reach the watermark within its page budget',
        ] as $reason) {
            $safe = \BCC\Trust\Onchain\Admin\AdminActionSupport::operatorSafeExcerpt($reason);
            $this->assertSame($reason, $safe, 'a controlled operational reason must not be redacted');

            $html = $this->render([$this->sentinelRow(['last_error' => $reason])]);
            $this->assertStringContainsString($reason, html_entity_decode($html, ENT_QUOTES));
        }
    }

    public function testARedactedMessageStillTellsTheOperatorSomething(): void
    {
        $safe = \BCC\Trust\Onchain\Admin\AdminActionSupport::operatorSafeExcerpt(
            'could not reach https://lcd.example.com/x?api_key=SECRET — node down'
        );

        $this->assertStringNotContainsString('SECRET', $safe);
        $this->assertStringContainsString('could not reach', $safe);
        $this->assertStringContainsString('node down', $safe);
    }

    // ── families_by_classification: the MEANINGFUL rendered values ──────

    /**
     * The panel renders three values from the breakdown — CW-721 total,
     * settled non-NFT, and pending. Parity means all three, not just the
     * total. Asserting the key was "supplied" would prove nothing.
     */
    public function testTheClassificationBreakdownMatchesThePanelsThreeValues(): void
    {
        $html = $this->render([$this->sentinelRow([
            'families_by_classification' => [
                'confirmed_cw721' => 111,
                'probable_cw721'  => 222,
                'not_cw721'       => 3331,
            ],
            'families_pending' => 4441,
        ])]);

        // CW-721 total = confirmed + probable, via the snapshot's own helper.
        $this->assertStringContainsString('333 CW-721', $html);
        // Settled non-NFT, read by its classifier constant.
        $this->assertStringContainsString('3331 non-NFT', $html);
        // Still awaiting classification.
        $this->assertStringContainsString('4441 pending', $html);
    }

    public function testAnAbsentBreakdownRendersZerosRatherThanBlanks(): void
    {
        $html = $this->render([$this->sentinelRow([
            'families_by_classification' => [],
            'families_pending'           => 0,
        ])]);

        $this->assertStringContainsString('0 CW-721', $html);
        $this->assertStringContainsString('0 non-NFT', $html);
        $this->assertStringContainsString('0 pending', $html);
    }

    public function testTheBreakdownIsNeverDumpedAsARawArray(): void
    {
        $html = $this->render([$this->sentinelRow([
            'families_by_classification' => ['confirmed_cw721' => 1, 'not_cw721' => 2],
        ])]);

        $this->assertStringNotContainsString('Array', $html);
        $this->assertStringNotContainsString('confirmed_cw721', $html, 'internal keys are not operator vocabulary');
    }

    // ── The renderer derives nothing ────────────────────────────────────

    public function testASuppliedVerdictWinsOverAnythingTheRendererCouldInfer(): void
    {
        // Opted in and not paused — a renderer that re-derived would say
        // "Eligible". The supplied verdict says otherwise and must win.
        $html = $this->render([$this->sentinelRow([
            'eligibility'        => CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ALLOWLIST_EXCLUDED,
            'eligibility_reason' => 'SENTINEL-REASON-ALLOWLIST',
        ])]);

        $this->assertStringContainsString(
            CosmwasmDiscoveryHealthSnapshot::eligibilityLabel(
                CosmwasmDiscoveryHealthSnapshot::ELIGIBILITY_ALLOWLIST_EXCLUDED
            ),
            $html
        );
        $this->assertStringContainsString('SENTINEL-REASON-ALLOWLIST', $html);
    }

    public function testTheSectionRendererTouchesNoRepositoryOrSnapshot(): void
    {
        $this->render([$this->sentinelRow()]);

        $this->assertSame(
            0,
            CosmwasmDiscoveryHealthSnapshot::$summaryCalls,
            'handed completed rows, the renderer must not fetch status of its own accord'
        );
        $this->assertSame([], ChainRepository::$discoveryWrites);
        $this->assertSame(0, ChainRepository::$cacheBusts);
        $this->assertSame(0, CosmwasmDiscoveryWorker::$passes, 'rendering must never start scanner work');
    }

    public function testTheTabFetchesTheSharedSummaryExactlyOnce(): void
    {
        CosmwasmDiscoveryHealthSnapshot::$chains = [$this->sentinelRow()];

        $m = new \ReflectionMethod(ChainsPage::class, 'render_nft_discovery_tab');
        $m->setAccessible(true);

        ob_start();
        $m->invoke(null);
        ob_get_clean();

        $this->assertSame(1, CosmwasmDiscoveryHealthSnapshot::$summaryCalls);
    }

    // ── Scope: still engine-specific, still not slander ──────────────────

    public function testNonCosmwasmChainsAreAbsentWithoutBeingCalledIncapable(): void
    {
        CosmwasmDiscoveryHealthSnapshot::$chains = [$this->sentinelRow()];

        $m = new \ReflectionMethod(ChainsPage::class, 'render_nft_discovery_tab');
        $m->setAccessible(true);

        ob_start();
        $m->invoke(null);
        $html = (string) ob_get_clean();

        $text = preg_replace('/\s+/', ' ', strip_tags($html)) ?? '';

        // No non-CosmWasm chain appears as a ROW. The table body is the
        // part that carries verdicts, so that is where their absence
        // matters; the prose may name them, and does.
        $this->assertSame(1, preg_match('#<tbody>(.*)</tbody>#s', $html, $m));
        $body = strtolower(strip_tags($m[1]));
        $this->assertStringNotContainsString('ethereum', $body);
        $this->assertStringNotContainsString('solana', $body);
        $this->assertStringNotContainsString('helius', $body);

        // And where the prose DOES name them, it says out-of-scope, never
        // incapable.
        $this->assertStringContainsString('not being described as unable to support NFTs', $text);
        $this->assertStringContainsString('not managed by this engine', $text);
        foreach ([
            'Solana is not eligible',
            'EVM chains are not eligible',
            'does not support NFTs',
            'cannot do NFT discovery',
        ] as $slander) {
            $this->assertStringNotContainsString($slander, $text);
        }
    }

    // ── VC-B3a adds NO controls ─────────────────────────────────────────

    /**
     * THE STATUS ROW IS STILL READ-ONLY. The scope moved; the rule did not.
     *
     * These two assertions were written in VC-B3a, when the section held
     * nothing but the discovery opt-in, and they said "no scanner control
     * exists anywhere in this section". VC-B3b deliberately adds four —
     * that is the batch. Deleting the guard would have been a real loss of
     * coverage, and raising its counts would have made it assert nothing.
     *
     * So it now targets the STATUS ROW itself, which is where the original
     * concern actually lives: status is a read-only presentation of the
     * snapshot, and a control mixed into it would be a mutation offered
     * inside a display an operator reads as a report. Controls belong in
     * the separate operations row, asserted below and owned in full by
     * ChainsCwScannerOperationsDomTest.
     */
    public function testTheStatusRowCarriesNoMutationControl(): void
    {
        $html = $this->renderStatusRow($this->sentinelRow(['last_error' => 'x']));

        foreach ([
            ChainsPage::ACTION_CW_PAUSE,
            ChainsPage::ACTION_CW_RESUME,
            ChainsPage::ACTION_CW_BACKFILL,
            ChainsPage::ACTION_CW_RETRY,
            ChainsPage::ACTION_CW_DISCOVERY_ENABLE,
            ChainsPage::ACTION_CW_DISCOVERY_DISABLE,
        ] as $route) {
            $this->assertStringNotContainsString($route, $html, 'status display must offer no route');
        }

        $this->assertSame(0, substr_count($html, '<form'), 'no form inside the status display');
        $this->assertSame(0, substr_count($html, '<button'), 'no button inside the status display');
        $this->assertSame(0, substr_count($html, '<input'), 'no input inside the status display');
        $this->assertSame(0, substr_count($html, 'data-nonce-action'), 'no nonce inside the status display');
    }

    /**
     * And the controls really are in their own row — so the separation
     * above is a structural fact, not an accident of where the sentinel
     * happened to put them.
     */
    public function testTheControlsLiveInASeparateRowFromTheStatus(): void
    {
        $html = $this->render([$this->sentinelRow()]);

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();

        $statusRows = 0;
        $opsRows    = 0;
        foreach ($doc->getElementsByTagName('tr') as $tr) {
            if (!$tr instanceof \DOMElement) {
                continue;
            }
            $class = $tr->getAttribute('class');
            if (str_contains($class, 'bcc-cw-status-row')) {
                $statusRows++;
                $this->assertSame(0, $tr->getElementsByTagName('form')->length);
            }
            if (str_contains($class, 'bcc-cw-operations-row')) {
                $opsRows++;
            }
        }

        $this->assertSame(1, $statusRows, 'one status row per chain');
        $this->assertSame(1, $opsRows, 'one operations row per chain');
    }

    /** @param array<string, mixed> $row */
    private function renderStatusRow(array $row): string
    {
        $m = new \ReflectionMethod(ChainsPage::class, 'render_cw_status_row');
        $m->setAccessible(true);

        ob_start();
        $m->invoke(null, $row);

        return (string) ob_get_clean();
    }
}
