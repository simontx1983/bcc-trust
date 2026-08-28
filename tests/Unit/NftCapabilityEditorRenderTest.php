<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\NftDiscoveryPage;
use BCC\Trust\Onchain\Admin\Views\NftCapabilityEditorPanel;
use BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Services\NftCapabilityEditor;
use BCC\Trust\Onchain\Services\NftDiscoveryControlPlaneSnapshot;
use BCC\Trust\Onchain\Support\NftChainCapability;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * WHAT THE EDITOR OFFERS, AND WHAT IT REFUSES TO OFFER.
 *
 * ── RENDERED THROUGH THE REAL PIPELINE ──────────────────────────────────
 * The rows are built by the REAL {@see NftChainCapability::operationMatrix()}
 * over stubbed storage, rather than hand-assembled — so "only registry-valid
 * triples are editable" is a claim about the model and the panel together,
 * which is where it has to hold. A hand-built fixture could show anything.
 *
 * ── LOOKING AT THE PAGE CHANGES NOTHING ─────────────────────────────────
 * Asserted after every render: no write, no generation bump, no worker
 * pass. An editor that materialised a row per default the first time
 * somebody opened it would look completely normal on screen.
 */
#[CoversClass(NftCapabilityEditorPanel::class)]
#[CoversClass(NftDiscoveryPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftCapabilityEditorRenderTest extends TestCase
{
    private const CHAIN_ID = 4;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-capability-editor-stubs.php';

        \BccAdminTestState::reset();
        \BCC\Core\Log\Logger::reset();
        \BCC\Trust\Core\Security\AuditLogger::reset();
        ChainRepository::reset();
        ChainNftCapabilityRepository::reset();
        CosmwasmDiscoveryWorker::reset();

        $_GET  = [];
        $_POST = [];
    }

    /**
     * Render the whole page for one family, exactly as a GET would.
     *
     * Goes through `render_page()` rather than calling the panel directly,
     * so the SELECTION path — a `?chain=` matched against the snapshot's own
     * rows — is what is under test rather than a value handed in.
     */
    private function page(array $query = []): string
    {
        $_GET = array_merge(['page' => NftDiscoveryPage::PAGE_SLUG, 'family' => 'cosmos'], $query);

        ob_start();
        NftDiscoveryPage::render_page();

        return (string) ob_get_clean();
    }

    private function assertRenderChangedNothing(): void
    {
        $this->assertSame([], ChainNftCapabilityRepository::$writes, 'rendering writes no override');
        $this->assertSame([], ChainNftCapabilityRepository::$bumps, 'rendering bumps no generation');
        $this->assertSame([], ChainRepository::$capabilityWrites, 'rendering writes no capability');
        $this->assertSame(0, CosmwasmDiscoveryWorker::$passes, 'rendering starts no discovery');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE SELECTED CHAIN COMES FROM THE CANONICAL ROWS
    // ═══════════════════════════════════════════════════════════════════

    public function testNoChainSelectedRendersAPromptAndNoControls(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);

        $html = $this->page();

        $this->assertStringContainsString('Capability editor', $html);
        $this->assertStringContainsString('Choose <strong>Edit capability</strong>', $html);
        $this->assertStringNotContainsString(NftDiscoveryPage::ACTION_CAP_PRODUCT_ENABLE, $html);
        $this->assertRenderChangedNothing();
    }

    public function testTheSelectedChainIsSourcedFromTheCanonicalRows(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);

        $html = $this->page(['chain' => (string) self::CHAIN_ID]);

        $this->assertStringContainsString('Capability editor — Injective', $html);
        $this->assertStringContainsString('injective', $html);
        $this->assertRenderChangedNothing();
    }

    /**
     * ⚠️ A `?chain=` THAT MATCHES NO ROW SELECTS NOTHING.
     *
     * The parameter chooses among rows the snapshot already built from
     * `ChainRepository::getAll()`. It cannot introduce a chain, reach a
     * chain of another family, or produce a row of its own — so the editor
     * cannot be pointed at anything the matrix above it is not also showing.
     *
     * @return array<string, array{0: string}>
     */
    public static function unselectableChainParams(): array
    {
        return [
            'no such chain'  => ['999'],
            'zero'           => ['0'],
            'negative'       => ['-4'],
            'word'           => ['injective'],
            'sql-looking'    => ['4 OR 1=1'],
            'trailing lf'    => ["4\n"],
            'path traversal' => ['../../etc/passwd'],
            'overflow'       => ['99999999999999999999'],
        ];
    }

    #[DataProvider('unselectableChainParams')]
    public function testAnUnmatchedChainParamSelectsNothing(string $param): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);

        $html = $this->page(['chain' => $param]);

        $this->assertStringContainsString('Choose <strong>Edit capability</strong>', $html);
        $this->assertStringNotContainsString(NftDiscoveryPage::ACTION_CAP_PRODUCT_ENABLE, $html);
        $this->assertRenderChangedNothing();
    }

    /** A chain of ANOTHER family is not reachable from this family's tab. */
    public function testAChainOfAnotherFamilyCannotBeSelected(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'ethereum', false, true, true, 'evm');

        $html = $this->page(['family' => 'cosmos', 'chain' => (string) self::CHAIN_ID]);

        $this->assertStringContainsString('Choose <strong>Edit capability</strong>', $html);
        $this->assertRenderChangedNothing();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  CURRENT VALUES RENDER, INCLUDING THE THIRD ANSWER
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, array{0: bool, 1: bool, 2: string, 3: string}> */
    public static function flagStates(): array
    {
        return [
            'both on'  => [true, true, 'Supported', 'Permitted'],
            'both off' => [false, false, 'Not supported', 'Not permitted'],
            'split'    => [true, false, 'Supported', 'Not permitted'],
        ];
    }

    #[DataProvider('flagStates')]
    public function testCurrentValuesRender(
        bool $product,
        bool $manual,
        string $productWord,
        string $manualWord
    ): void {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, $product, $manual);

        $html = $this->page(['chain' => (string) self::CHAIN_ID]);

        $this->assertStringContainsString($productWord, $html);
        $this->assertStringContainsString($manualWord, $html);
        $this->assertRenderChangedNothing();
    }

    /**
     * A pre-migration projection is shown as UNKNOWN, not as "off".
     *
     * Telling an operator they declined something they were never offered
     * sends them looking for a switch that is not there.
     */
    public function testAnAbsentColumnRendersAsUnknownAndOffersNoControl(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);
        ChainRepository::dropColumn(self::CHAIN_ID, 'bcc_supports_nft_collections');

        $html = $this->page(['chain' => (string) self::CHAIN_ID]);

        $this->assertStringContainsString('Unknown', $html);
        $this->assertStringContainsString('this install cannot store the value', $html);
        $this->assertRenderChangedNothing();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ONLY REGISTRY-VALID TRIPLES ARE EDITABLE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠️ THE EDITOR OFFERS EXACTLY THE CODE-REGISTERED TRIPLES.
     *
     * Nothing to press for a capability the build does not have — which is
     * what makes "configuration cannot invent a capability" true of the UI
     * as well as of the write path.
     */
    public function testOnlyRegistryValidTriplesAreOffered(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);

        $html = $this->page(['chain' => (string) self::CHAIN_ID]);

        // Injective's real drivers.
        $this->assertStringContainsString(NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION, $html);
        $this->assertStringContainsString(NftDriverRegistry::DRIVER_TALIS_WHITELIST, $html);
        $this->assertStringContainsString(NftDriverRegistry::DRIVER_CW721_LCD, $html);

        // Drivers that serve OTHER families are absent entirely.
        foreach ([
            NftDriverRegistry::DRIVER_ALCHEMY_NFT,
            NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS,
            NftDriverRegistry::DRIVER_EVM_RPC,
            NftDriverRegistry::DRIVER_DAS_RPC,
            NftDriverRegistry::DRIVER_DAS_HELIUS,
            NftDriverRegistry::DRIVER_MAGICEDEN,
        ] as $foreign) {
            $this->assertStringNotContainsString(
                $foreign,
                $html,
                $foreign . ' does not serve a Cosmos chain and must not be offered'
            );
        }

        // And the retired driver name appears nowhere as a control.
        $this->assertStringNotContainsString('value="das"', $html);
        $this->assertRenderChangedNothing();
    }

    /** The three states each render with their own words. */
    public function testTheThreeOverrideStatesRenderDistinctly(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);

        // Default (no row).
        $this->assertStringContainsString('Code default', $this->page(['chain' => (string) self::CHAIN_ID]));

        ChainNftCapabilityRepository::seedRow(
            self::CHAIN_ID,
            NftDriverRegistry::OP_ENUMERATION,
            NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
            false,
            10
        );
        $this->assertStringContainsString('Disabled', $this->page(['chain' => (string) self::CHAIN_ID]));

        ChainNftCapabilityRepository::seedRow(
            self::CHAIN_ID,
            NftDriverRegistry::OP_ENUMERATION,
            NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
            true,
            250
        );
        $html = $this->page(['chain' => (string) self::CHAIN_ID]);
        $this->assertStringContainsString('Enabled', $html);
        $this->assertStringContainsString('priority 250', $html);
        $this->assertStringContainsString('code default 10', $html);

        $this->assertRenderChangedNothing();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  STALE ROWS ARE LABELLED INERT
    // ═══════════════════════════════════════════════════════════════════

    public function testStaleRowsAreShownAndLabelledInert(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);
        ChainNftCapabilityRepository::seedRow(self::CHAIN_ID, 'teleportation', 'moonbeam_nft', true, 0);

        $html = $this->page(['chain' => (string) self::CHAIN_ID]);

        $this->assertStringContainsString('Leftover override rows (inert)', $html);
        $this->assertStringContainsString('teleportation', $html);
        $this->assertStringContainsString('moonbeam_nft', $html);
        $this->assertStringContainsString('This build has no such operation.', $html);
        $this->assertStringContainsString('already do nothing', $html);

        // Its removal control is the STALE route, never the inherit route.
        $this->assertStringContainsString(NftDiscoveryPage::ACTION_CAP_STALE_REMOVE, $html);
        $this->assertRenderChangedNothing();
    }

    /** A chain with no leftovers shows no leftovers section at all. */
    public function testNoStaleSectionWhenThereAreNoLeftovers(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);

        $html = $this->page(['chain' => (string) self::CHAIN_ID]);

        $this->assertStringNotContainsString('Leftover override rows', $html);
        $this->assertStringNotContainsString(NftDiscoveryPage::ACTION_CAP_STALE_REMOVE, $html);
    }

    /** An unreadable override set withholds every override control. */
    public function testAnUnreadableOverrideSetWithholdsTheDriverControls(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);
        ChainNftCapabilityRepository::seedUnavailable(
            self::CHAIN_ID,
            \BCC\Trust\Onchain\ValueObjects\ChainNftCapabilityOverrides::REASON_OVERFLOW
        );

        $html = $this->page(['chain' => (string) self::CHAIN_ID]);

        $this->assertStringContainsString('could not be established', $html);
        $this->assertStringNotContainsString(NftDiscoveryPage::ACTION_CAP_DRIVER_ENABLE, $html);
        $this->assertStringNotContainsString(NftDiscoveryPage::ACTION_CAP_DRIVER_DISABLE, $html);

        // The two chain-row permissions are unaffected and stay editable.
        $this->assertStringContainsString(NftDiscoveryPage::ACTION_CAP_PRODUCT_DISABLE, $html);
        $this->assertRenderChangedNothing();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  EVM AND SOLANA KEEP THE STRUCTURAL EXPLANATION
    // ═══════════════════════════════════════════════════════════════════

    /** @return array<string, array{0: string, 1: string}> */
    public static function nonEnumerableFamilies(): array
    {
        return [
            'ethereum (evm)' => ['ethereum', 'evm'],
            'solana'         => ['solana', 'solana'],
        ];
    }

    /**
     * ⚠️ NO MANUAL-PERMISSION CONTROL, AND THE REASON IS STRUCTURAL.
     *
     * A blank space where Cosmos has a control reads as "not set up yet",
     * and an operator would go looking for the credential that turns it on.
     * There is no such credential.
     */
    #[DataProvider('nonEnumerableFamilies')]
    public function testEvmAndSolanaKeepTheStructuralNoEnumerationExplanation(
        string $slug,
        string $chainType
    ): void {
        ChainRepository::seed(self::CHAIN_ID, $slug, false, true, false, $chainType);

        $_GET['family'] = $chainType;
        $html = $this->page(['family' => $chainType, 'chain' => (string) self::CHAIN_ID]);

        $this->assertStringContainsString('Not applicable to this chain.', $html);
        $this->assertStringContainsString('no setting can add chain-wide NFT enumeration', $html);
        $this->assertStringContainsString('getContractsForOwner', $html);
        $this->assertStringNotContainsString(NftDiscoveryPage::ACTION_CAP_MANUAL_ENABLE, $html);
        $this->assertRenderChangedNothing();
    }

    /**
     * But a chain that already holds the stored value gets a way to clear
     * it — the only route out of a restored-backup state.
     */
    #[DataProvider('nonEnumerableFamilies')]
    public function testAStoredPermissionOnSuchAChainOffersOnlyWithdrawal(
        string $slug,
        string $chainType
    ): void {
        ChainRepository::seed(self::CHAIN_ID, $slug, false, true, true, $chainType);

        $html = $this->page(['family' => $chainType, 'chain' => (string) self::CHAIN_ID]);

        $this->assertStringContainsString('stored as ON', $html);
        $this->assertStringContainsString(NftDiscoveryPage::ACTION_CAP_MANUAL_DISABLE, $html);
        $this->assertStringNotContainsString(NftDiscoveryPage::ACTION_CAP_MANUAL_ENABLE, $html);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  A MEASURED REFUSAL IS NOT OVERRIDDEN BY INTENT
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚠️ THE PERMISSION DOES NOT BEAT A MEASUREMENT, AND THE PAGE SAYS SO.
     *
     * A Cosmos chain whose wasm module answered 501 keeps the control — it
     * IS an enumerable family — but the panel labels the measured refusal
     * prominently, and the backfill stays unavailable.
     */
    public function testAMeasuredRefusalIsLabelledAndTheBackfillStaysUnavailable(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', true, true, true);
        \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::seed(
            self::CHAIN_ID,
            \BCC\Trust\Onchain\Repositories\ChainCheckpointRepository::CW_STATE_UNSUPPORTED
        );

        $html = $this->page(['chain' => (string) self::CHAIN_ID]);

        $this->assertStringContainsString('no CosmWasm module', $html);
        $this->assertStringContainsString('will not change that', $html);

        // And the capability model still refuses, so the backfill control
        // is not offered anywhere on the page.
        $chain = ChainRepository::getById(self::CHAIN_ID);
        self::assertNotNull($chain);
        $matrix = NftChainCapability::operationMatrix($chain);
        $this->assertSame(
            NftChainCapability::OP_CHAIN_UNSUPPORTED,
            $matrix['operations'][NftDriverRegistry::OP_ENUMERATION]['status']
        );
        $this->assertFalse(NftChainCapability::isScannable($matrix['verdict']));
        $this->assertRenderChangedNothing();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  READINESS IS READ-ONLY, AND NOTHING BULK EXISTS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Provider readiness is shown and is not editable.
     *
     * No credential field, no RPC field, no "test provider" button — a
     * stored readiness answer would be stale the moment a key rotated, and
     * in the direction that says "yes" after the answer became "no".
     */
    public function testProviderReadinessIsObservedNotEditable(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);

        $html = $this->page(['chain' => (string) self::CHAIN_ID]);

        $this->assertMatchesRegularExpression('/configured/', $html);

        foreach ([
            'name="rpc_url"',
            'name="rest_url"',
            'name="api_key"',
            'name="helius',
            'Test provider',
            'test_provider',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html, 'readiness is not editable here');
        }
    }

    /** No bulk, family-wide or automatic control is rendered. */
    public function testNoBulkOrAutomaticControlIsRendered(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);
        ChainRepository::seed(5, 'osmosis', false, true, true);

        $html = $this->page(['chain' => (string) self::CHAIN_ID]);

        foreach ([
            'Enable all',
            'enable_all',
            'Apply to all',
            'select_all',
            'type="checkbox"',
            'Schedule',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    /**
     * ⚠️ SAVING CONFIGURATION NEVER SUBMITS THE BACKFILL ROUTE.
     *
     * The editor's forms and the backfill control live on the same page, so
     * the cheapest possible mistake is a form whose `action` names the
     * wrong one. Every form in the editor posts to a `bcc_nft_cap_*` route.
     */
    public function testEveryEditorFormPostsToACapabilityRoute(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);
        ChainNftCapabilityRepository::seedRow(self::CHAIN_ID, 'teleportation', 'moonbeam_nft', true, 0);

        $panel = $this->renderPanelOnly();

        preg_match_all('/name="action"\s+value="([^"]+)"/', $panel, $matches);

        $this->assertNotSame([], $matches[1], 'the editor renders forms');
        foreach ($matches[1] as $action) {
            $this->assertStringStartsWith(
                'bcc_nft_cap_',
                $action,
                'an editor form must never post to a work-starting route'
            );
        }
        $this->assertStringNotContainsString(NftDiscoveryPage::ACTION_CW_BACKFILL, $panel);
    }

    /** Render just the panel, so the assertion is about the editor's own forms. */
    private function renderPanelOnly(): string
    {
        $snapshot = NftDiscoveryControlPlaneSnapshot::buildForFamily('cosmos');
        $selected = null;
        foreach ($snapshot['chains'] as $row) {
            if ((int) $row['chain_id'] === self::CHAIN_ID) {
                $selected = $row;
            }
        }
        self::assertNotNull($selected);

        ob_start();
        NftCapabilityEditorPanel::render($snapshot, $selected);

        return (string) ob_get_clean();
    }

    // ═══════════════════════════════════════════════════════════════════
    //  THE COPY SAYS WHAT THE PAGE CAN AND CANNOT DO
    // ═══════════════════════════════════════════════════════════════════

    /**
     * The PR 3 line claiming the page "cannot grant" capability is gone,
     * and what replaced it is the set of things that are still true.
     */
    public function testTheCopyExplainsTheBoundaryWithoutClaimingItCannotEdit(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);

        $html = $this->page(['chain' => (string) self::CHAIN_ID]);

        $this->assertStringNotContainsString('it cannot grant it', $html);

        foreach ([
            'Nothing on this page starts work',
            'does not start a discovery',
            'only allows an administrator to start one later',
            'can never add a capability',
            'observed here, never edited',
            'separate, explicit action',
        ] as $promise) {
            $this->assertStringContainsString($promise, $html, 'the operator copy must state: ' . $promise);
        }
    }

    /**
     * ⚠️ THE PRODUCT-SUPPORT RULE IS IN THE ALWAYS-VISIBLE INTRODUCTION.
     *
     * ── WHY THIS SLICES INSTEAD OF SEARCHING THE PAGE ───────────────────
     * The submit confirmation already carries equivalent wording ("It does
     * NOT grant the manual discovery permission — that is a separate
     * action"), and for a while that was the ONLY place the rule appeared.
     * A whole-page `assertStringContainsString` would have passed against
     * that defect — the confirmation text is in the DOM either way — so it
     * would have false-greened the exact gap an acceptance pass found.
     *
     * A confirmation is also the wrong place for the rule on its own: it is
     * read after the decision, by somebody already reaching for the button.
     *
     * So this isolates the section from its heading up to the FIRST table
     * or form inside it — everything an operator reads before any control
     * exists — strips the tags, and asserts on what is left. The dialog text
     * lives in a form's `onsubmit`, which is beyond that boundary and cannot
     * contribute.
     */
    public function testTheVisibleProductSupportIntroStatesBothRules(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);

        $visible = $this->visibleProductSupportIntro($this->renderPanelOnly());

        $this->assertStringContainsString('starts nothing', $visible);
        $this->assertStringContainsString('does not grant the manual discovery permission', $visible);

        // The four points the correction had to preserve.
        $this->assertStringContainsString('product', $visible);
        $this->assertStringContainsString('never a claim about the blockchain', $visible);
        $this->assertStringContainsString('does not claim any provider is configured', $visible);

        $this->assertRenderChangedNothing();
    }

    /**
     * The slice really is a slice: the confirmation text is on the page and
     * NOT in it.
     *
     * Without this, a future edit that widened the boundary to include the
     * forms would silently restore the false-green the test above exists to
     * prevent — and nothing would fail.
     */
    public function testTheIsolatedIntroExcludesTheConfirmationDialogText(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);

        $panel   = $this->renderPanelOnly();
        $visible = $this->visibleProductSupportIntro($panel);

        // The dialog wording IS present on the page …
        $this->assertStringContainsString(
            'It does NOT grant the manual discovery permission',
            $panel,
            'the confirmation keeps its copy — it is now a reminder, not the only statement'
        );
        // … and is NOT what the assertion above is reading.
        $this->assertStringNotContainsString('It does NOT grant the manual discovery permission', $visible);
        $this->assertStringNotContainsString('onsubmit', $visible);
        $this->assertStringNotContainsString('<form', $visible);
        $this->assertStringNotContainsString('<table', $visible);
    }

    /**
     * The always-visible copy of the "BCC product support" section: from the
     * heading to the first table or form inside it, tags stripped.
     */
    private function visibleProductSupportIntro(string $html): string
    {
        $heading = strpos($html, 'BCC product support');
        self::assertNotFalse($heading, 'the product-support heading must exist');

        $afterHeading = strpos($html, '</h3>', $heading);
        self::assertNotFalse($afterHeading, 'the heading must be closed');
        $afterHeading += strlen('</h3>');

        $bounds = [];
        foreach (['<table', '<form'] as $stop) {
            $at = strpos($html, $stop, $afterHeading);
            if ($at !== false) {
                $bounds[] = $at;
            }
        }
        self::assertNotSame([], $bounds, 'the section must contain a control to bound the intro');

        $intro = substr($html, $afterHeading, min($bounds) - $afterHeading);

        return trim((string) preg_replace('/\s+/', ' ', strip_tags($intro)));
    }

    /** Each notice code renders a sentence that does not overclaim. */
    #[DataProvider('noticeCodes')]
    public function testEveryNoticeCodeRendersAndClaimsNoWork(string $code): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);

        $html = $this->page(['bcc_nftcap' => $code]);

        $this->assertStringContainsString('notice notice-', $html);

        foreach (['discovery ran', 'collection was discovered', 'provider was contacted'] as $overclaim) {
            $this->assertStringNotContainsString($overclaim, $html);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function noticeCodes(): array
    {
        $codes = [
            NftCapabilityEditor::RESULT_PRODUCT_ENABLED,
            NftCapabilityEditor::RESULT_PRODUCT_DISABLED,
            NftCapabilityEditor::RESULT_PRODUCT_DISABLED_CASCADE,
            NftCapabilityEditor::RESULT_PRODUCT_NOOP_ENABLED,
            NftCapabilityEditor::RESULT_PRODUCT_NOOP_DISABLED,
            NftCapabilityEditor::RESULT_PRODUCT_WRITE_FAILED,
            NftCapabilityEditor::RESULT_PRODUCT_UNVERIFIED,
            NftCapabilityEditor::RESULT_MANUAL_ENABLED,
            NftCapabilityEditor::RESULT_MANUAL_DISABLED,
            NftCapabilityEditor::RESULT_MANUAL_NOOP_ENABLED,
            NftCapabilityEditor::RESULT_MANUAL_NOOP_DISABLED,
            NftCapabilityEditor::RESULT_MANUAL_NO_PRODUCT,
            NftCapabilityEditor::RESULT_MANUAL_NO_STARTABLE,
            NftCapabilityEditor::RESULT_MANUAL_WRITE_FAILED,
            NftCapabilityEditor::RESULT_MANUAL_UNVERIFIED,
            NftCapabilityEditor::RESULT_OVERRIDE_DISABLED,
            NftCapabilityEditor::RESULT_OVERRIDE_ENABLED,
            NftCapabilityEditor::RESULT_OVERRIDE_INHERITED,
            NftCapabilityEditor::RESULT_OVERRIDE_NOOP,
            NftCapabilityEditor::RESULT_OVERRIDE_UNREADABLE,
            NftCapabilityEditor::RESULT_OVERRIDE_INVALID_TRIPLE,
            NftCapabilityEditor::RESULT_OVERRIDE_INVALID_PRIORITY,
            NftCapabilityEditor::RESULT_OVERRIDE_WRITE_FAILED,
            NftCapabilityEditor::RESULT_OVERRIDE_UNVERIFIED,
            NftCapabilityEditor::RESULT_STALE_REMOVED,
            NftCapabilityEditor::RESULT_STALE_NOT_FOUND,
            NftCapabilityEditor::RESULT_STALE_STILL_VALID,
            NftCapabilityEditor::RESULT_UNKNOWN_CHAIN,
            NftCapabilityEditor::RESULT_COLUMN_ABSENT,
            NftDiscoveryPage::CAP_RESULT_ERROR,
        ];

        $out = [];
        foreach ($codes as $code) {
            $out[$code] = [$code];
        }

        return $out;
    }

    /**
     * ⚠️ REMOVING A STALE ROW IS NEVER DESCRIBED AS ENABLING SOMETHING.
     *
     * It grants nothing — the row was already discarded at every read — and
     * the notice has to say so, because "removed" next to a capability page
     * is easy to read as "changed what this chain can do".
     */
    public function testTheStaleRemovalNoticeDoesNotClaimACapabilityChange(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);

        $html = $this->page(['bcc_nftcap' => NftCapabilityEditor::RESULT_STALE_REMOVED]);

        $this->assertStringContainsString('already inert', $html);
        $this->assertStringContainsString('nothing was enabled', $html);
        $this->assertStringContainsString('no capability changed', $html);
    }

    /** An unrecognised notice code renders no notice rather than raw text. */
    public function testAnUnknownNoticeCodeRendersNothing(): void
    {
        ChainRepository::seed(self::CHAIN_ID, 'injective', false, true, true);

        $html = $this->page(['bcc_nftcap' => 'totally_made_up_code']);

        $this->assertStringNotContainsString('totally_made_up_code', $html);
    }
}
