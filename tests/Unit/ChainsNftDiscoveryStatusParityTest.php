<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\NftDiscoveryPage;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository;
use BCC\Trust\Onchain\Services\CosmwasmDiscoveryHealthSnapshot;
use BCC\Trust\Onchain\Services\NftDiscoveryControlPlaneSnapshot;
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
#[CoversClass(NftDiscoveryPage::class)]
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
        ChainNftCapabilityRepository::reset();
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


    // ── The inventory ───────────────────────────────────────────────────


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




    // ── last_error handling ─────────────────────────────────────────────



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
            'credentialed url' => ['GET https://cosmos-api.polkachu.com.com/path?api_key=SUPERSECRET99 failed', 'SUPERSECRET99'],
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

    #[DataProvider('hostileErrors')]
    public function testTheSameRedactionAppliesToTheOldScannerPanel(string $stored, string $forbidden): void
    {
        // The two surfaces must not diverge on what an operator may see.
        $safe = \BCC\Trust\Onchain\Admin\AdminActionSupport::operatorSafeExcerpt($stored);

        $this->assertStringNotContainsString($forbidden, $safe);
    }


    public function testARedactedMessageStillTellsTheOperatorSomething(): void
    {
        $safe = \BCC\Trust\Onchain\Admin\AdminActionSupport::operatorSafeExcerpt(
            'could not reach https://cosmos-api.polkachu.com.com/x?api_key=SECRET — node down'
        );

        $this->assertStringNotContainsString('SECRET', $safe);
        $this->assertStringContainsString('could not reach', $safe);
        $this->assertStringContainsString('node down', $safe);
    }

    // ── families_by_classification: the MEANINGFUL rendered values ──────




    // ── The renderer derives nothing ────────────────────────────────────




    // ── Scope: still engine-specific, still not slander ──────────────────


    // ── VC-B3a adds NO controls ─────────────────────────────────────────



}
