<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\VerifyCollectionsPage;
use BCC\Trust\Onchain\Services\CollectionDemandService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE INVARIANT: rendering Verify Collections sends nothing anywhere.
 *
 * Rendering the page must make zero external HTTP requests, write zero
 * options, transients or cache records, claim zero breaker probes, put no
 * linked wallet address into the markup, the log or any request, and stay
 * usable when every outside service is down.
 *
 * ── THE DEFECT ──────────────────────────────────────────────────────────
 * Opening this page used to send every linked Cosmos Hub wallet address — up
 * to 200 — to `marketplace-api.cosmos.stargaze-apis.com`, one request per
 * wallet, up to 3 pages each, retried. On staging, where there is no
 * persistent object cache, that happened on EVERY load.
 *
 * ── WHY THIS IS A BEHAVIOURAL TEST ──────────────────────────────────────
 * The REAL `render_page()` runs, with the REAL `StargazeMarketplaceApi`
 * behind it. Every transport a PHP file here can reach is a recorder that
 * returns "unavailable". 200 linked Hub wallets sit in the wallet
 * repository, so the old code path would have had everything it needed to
 * send them — the fixture is built so that absence of traffic means the
 * render did not TRY, not that it had nothing to try with.
 *
 * `testTheSpyWouldCatchARestoredCall` is the anti-vacuity control: it drives
 * the production Stargaze client directly and proves this harness records
 * the request and the address it carries.
 *
 * ── ONE DOCUMENTED EXCEPTION, NOT EXERCISED HERE ────────────────────────
 * `takeNotices()` deletes a one-shot notice transient when the page is the
 * landing of an earlier administrator POST (post/redirect/get). That write is
 * the consumption of a result the operator already submitted, it happens only
 * when such a notice exists, and it is unchanged by this work. Every render
 * here is a clean GET with no pending notice.
 */
#[CoversClass(VerifyCollectionsPage::class)]
#[CoversClass(CollectionDemandService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class VerifyCollectionsRenderIsolationTest extends TestCase
{
    private const HUB_CHAIN = 8;

    private const COSMOS_CONTRACT = 'cosmos1qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqzz';
    private const EVM_COUNTED     = '0x1111111111111111111111111111111111111111';
    private const EVM_UNCOUNTED   = '0x2222222222222222222222222222222222222222';
    private const SOLANA_MINT     = 'So1anaMint1111111111111111111111111111111111';

    /** Column index of "Linked holders" in a data row (0-based). */
    private const LINKED_HOLDERS_TD = 8;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/verify-collections-render-isolation-stubs.php';

        \BccRenderWorld::reset();
        $_GET     = [];
        $_POST    = [];
        $_REQUEST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $this->seedWorld();
    }

    // ── fixture ─────────────────────────────────────────────────────────

    /** 200 distinct, VALID Cosmos Hub account addresses (bech32, 38-char body). */
    private static function hubWallets(): array
    {
        $cs  = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
        $out = [];
        for ($i = 0; $i < 200; $i++) {
            $body = $cs[intdiv($i, 32)] . $cs[$i % 32];
            for ($p = 2; $p < 38; $p++) {
                $body .= $cs[($i * 7 + $p * 13) % 32];
            }
            $out[] = 'cosmos1' . $body;
        }

        return $out;
    }

    private function seedWorld(): void
    {
        \BccRenderWorld::$hubWallets = self::hubWallets();

        \BccRenderWorld::$chains = [
            (object) ['id' => 1, 'slug' => 'ethereum', 'name' => 'Ethereum', 'chain_type' => 'evm', 'is_active' => 1, 'bcc_supports_nft_collections' => '1', 'cosmwasm_nft_discovery_enabled' => '0'],
            (object) ['id' => self::HUB_CHAIN, 'slug' => 'cosmos', 'name' => 'Cosmos Hub', 'chain_type' => 'cosmos', 'is_active' => 1, 'rest_url' => 'https://cosmos-api.polkachu.com', 'bcc_supports_nft_collections' => '1', 'cosmwasm_nft_discovery_enabled' => '1'],
            (object) ['id' => 20, 'slug' => 'solana', 'name' => 'Solana', 'chain_type' => 'solana', 'is_active' => 1, 'bcc_supports_nft_collections' => '1', 'cosmwasm_nft_discovery_enabled' => '0'],
        ];

        $row = static fn(int $id, int $chainId, string $slug, string $type, string $contract, string $name): object => (object) [
            'id'                        => $id,
            'contract_address'          => $contract,
            'canonical_identifier'      => $contract,
            'collection_name'           => $name,
            'image_url'                 => null,
            'token_standard'            => $type === 'cosmos' ? 'CW-721' : 'ERC-721',
            'chain_id'                  => $chainId,
            'chain_slug'                => $slug,
            'chain_type'                => $type,
            'is_verified'               => 0,
            'is_hidden'                 => 0,
            'source'                    => 'discovery',
            'unique_holders'            => null,
            'has_community'             => 0,
            'provisioning_state'        => 'none',
            'provisioning_failure_code' => null,
        ];

        \BccRenderWorld::$rows = [
            $row(101, self::HUB_CHAIN, 'cosmos', 'cosmos', self::COSMOS_CONTRACT, 'Hub Collection'),
            $row(102, 1, 'ethereum', 'evm', self::EVM_COUNTED, 'Counted EVM'),
            $row(103, 1, 'ethereum', 'evm', self::EVM_UNCOUNTED, 'Uncounted EVM'),
            $row(104, 20, 'solana', 'solana', self::SOLANA_MINT, 'Solana Collection'),
        ];

        \BccRenderWorld::$index = [
            (object) ['chain_id' => '1', 'contract_address' => self::EVM_COUNTED, 'wallets' => '3'],
        ];
    }

    private function render(): string
    {
        ob_start();
        try {
            VerifyCollectionsPage::render_page();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** @return array<int, string> collection id => trimmed "Linked holders" cell text */
    private static function linkedHolderCells(string $html): array
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
        libxml_clear_errors();

        $cells = [];
        foreach ($doc->getElementsByTagName('tr') as $tr) {
            if (!$tr instanceof \DOMElement || !$tr->hasAttribute('data-collection-id')) {
                continue;
            }
            $tds = [];
            foreach ($tr->childNodes as $n) {
                if ($n instanceof \DOMElement && $n->tagName === 'td') {
                    $tds[] = $n;
                }
            }
            $cells[(int) $tr->getAttribute('data-collection-id')] = trim((string) ($tds[self::LINKED_HOLDERS_TD]->textContent ?? ''));
        }

        return $cells;
    }

    // ═══════════════════════════════════════════════════════════════════
    //  0. ANTI-VACUITY
    // ═══════════════════════════════════════════════════════════════════

    public function testTheFixtureIsOneTheOldCodeWouldHaveSent(): void
    {
        $wallets = \BccRenderWorld::$hubWallets;
        self::assertCount(200, $wallets);
        self::assertCount(200, array_unique($wallets), 'every wallet is distinct');
        foreach ($wallets as $w) {
            // The exact acceptance rule of StargazeMarketplaceApi::profileCollections():
            // every one of these would genuinely have been sent.
            self::assertMatchesRegularExpression('/^cosmos1[02-9ac-hj-np-z]{38}$/', $w);
        }
    }

    /**
     * The harness catches what it claims to catch: driving the REAL Stargaze
     * client issues a recorded request carrying the wallet address. Without
     * this, "zero requests" could mean "the spy is not wired".
     */
    public function testTheSpyWouldCatchARestoredCall(): void
    {
        $wallet = \BccRenderWorld::$hubWallets[0];

        $result = \BCC\Trust\Onchain\Support\StargazeMarketplaceApi::profileCollections($wallet);

        self::assertNull($result, 'unavailable, as every outside service is here');
        self::assertCount(1, \BccRenderWorld::$http);
        self::assertStringContainsString('stargaze-apis.com', \BccRenderWorld::$http[0]['url']);
        self::assertStringContainsString($wallet, \BccRenderWorld::$http[0]['url'], 'the address rides in the URL path');
    }

    public function testThePageActuallyRendersEveryRow(): void
    {
        $html = $this->render();

        self::assertStringContainsString('Verify Collections', $html);
        $ids = array_keys(self::linkedHolderCells($html));
        sort($ids);
        self::assertSame([101, 102, 103, 104], $ids);
    }

    /**
     * The queue ranks by what is KNOWN. The indexed holder sorts first; every
     * row without an indexed count — Not calculated, None indexed or
     * Unavailable — carries no evidence and ties, falling through to the id
     * tie-break. No row is promoted or demoted by a count nobody measured.
     */
    public function testRankingUsesOnlyMeasuredCounts(): void
    {
        self::assertSame(
            [102, 104, 103, 101],
            array_keys(self::linkedHolderCells($this->render())),
            'the counted row first, then the id tie-break for everything unmeasured'
        );

        \BccRenderWorld::$indexReadFails = true;
        self::assertSame(
            [104, 103, 102, 101],
            array_keys(self::linkedHolderCells($this->render())),
            'with the read failed NOTHING is measured, so the demand key promotes no row'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  1. NOTHING LEAVES
    // ═══════════════════════════════════════════════════════════════════

    public function testRenderingMakesZeroOutboundRequestsWith200LinkedHubWallets(): void
    {
        $this->render();

        self::assertSame([], \BccRenderWorld::$http, 'rendering an administrator page must not contact any outside service');
    }

    public function testRenderingNeverLooksUpLinkedWalletAddresses(): void
    {
        $this->render();

        self::assertSame([], \BccRenderWorld::$walletLookups, 'there is no reason to read member wallet addresses to render this page');
    }

    /**
     * ⚠ NO LINKED WALLET ADDRESS LEAVES THROUGH RENDERING — checked in every
     * channel it could leave by: the markup, the application log, and any
     * outbound request.
     */
    public function testNoLinkedWalletAddressLeavesThroughAnyChannel(): void
    {
        $html     = $this->render();
        $logText  = implode("\n", \BccRenderWorld::$log);
        $httpText = implode("\n", array_column(\BccRenderWorld::$http, 'url'));

        foreach (\BccRenderWorld::$hubWallets as $wallet) {
            self::assertStringNotContainsString($wallet, $html, 'wallet address in markup');
            self::assertStringNotContainsString($wallet, $logText, 'wallet address in the log');
            self::assertStringNotContainsString($wallet, $httpText, 'wallet address in a request');
        }
    }

    /**
     * Contract addresses are the page's own content for an administrator (the
     * Contract column), so they are checked where they must NOT go: out.
     */
    public function testNoContractAddressLeavesInARequest(): void
    {
        $this->render();

        $httpText = implode("\n", array_column(\BccRenderWorld::$http, 'url'));
        foreach ([self::COSMOS_CONTRACT, self::EVM_COUNTED, self::EVM_UNCOUNTED, self::SOLANA_MINT] as $contract) {
            self::assertStringNotContainsString($contract, $httpText);
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  2. NOTHING IS WRITTEN, NOTHING IS CLAIMED
    // ═══════════════════════════════════════════════════════════════════

    public function testRenderingWritesNoOptionTransientOrCacheRecord(): void
    {
        $this->render();

        self::assertSame([], \BccRenderWorld::$writes);
    }

    public function testRenderingClaimsNoBreakerProbe(): void
    {
        $this->render();

        self::assertSame([], \BccRenderWorld::$probes, 'no advisory lock and no isOpen() — the probe is a single-use resource');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  3. EVERY CELL TELLS THE TRUTH
    // ═══════════════════════════════════════════════════════════════════

    public function testEachLinkedHoldersCellStatesWhatIsActuallyKnown(): void
    {
        $cells = self::linkedHolderCells($this->render());

        self::assertSame('Not calculated', $cells[101], 'Cosmos Hub is never counted');
        self::assertSame('3', $cells[102], 'an indexed count is shown');
        self::assertSame('None indexed', $cells[103], 'a complete read with no entry says so in words');
        self::assertSame('None indexed', $cells[104], 'Solana is an indexed chain type');
    }

    /** ⚠ The failure that must never render as nobody-holds-it. */
    public function testAFailedIndexReadRendersUnavailableNeverZero(): void
    {
        \BccRenderWorld::$indexReadFails = true;

        $cells = self::linkedHolderCells($this->render());

        self::assertSame('Not calculated', $cells[101]);
        self::assertSame('Unavailable', $cells[102], 'even a row that WOULD have a count is unknown when the read failed');
        self::assertSame('Unavailable', $cells[103]);
        self::assertSame('Unavailable', $cells[104]);
    }

    public function testNoLinkedHoldersCellIsEverABareZeroOrDash(): void
    {
        foreach ([false, true] as $fails) {
            \BccRenderWorld::$indexReadFails = $fails;
            foreach (self::linkedHolderCells($this->render()) as $id => $text) {
                self::assertNotSame('0', $text, "row {$id}");
                self::assertNotSame('—', $text, "row {$id}");
                self::assertNotSame('', $text, "row {$id}");
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    //  4. USABLE WHEN EVERYTHING OUTSIDE IS DOWN
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Every transport already answers "unavailable" in this harness; here the
     * index read fails too. The page must still render every row, every tab
     * and every control — only the demand cells may say they do not know.
     */
    public function testThePageRemainsUsableWhenEveryOutsideServiceIsUnavailable(): void
    {
        \BccRenderWorld::$indexReadFails = true;

        $html = $this->render();

        $ids = array_keys(self::linkedHolderCells($html));
        sort($ids);
        self::assertSame([101, 102, 103, 104], $ids, 'every row still renders');
        self::assertStringContainsString('nav-tab', $html, 'the state tabs render');
        self::assertStringContainsString('Hub Collection', $html);
        self::assertSame([], \BccRenderWorld::$http);
        self::assertSame([], \BccRenderWorld::$writes);
    }

    public function testNoExceptionTextOrCredentialReachesTheMarkup(): void
    {
        \BccRenderWorld::$indexReadFails = true;

        $html = $this->render();

        foreach (['Exception', 'Stack trace', 'SQLSTATE', 'db_error', 'Bearer ', 'api_key', 'http_request_failed'] as $leak) {
            self::assertStringNotContainsString($leak, $html);
        }
    }
}
