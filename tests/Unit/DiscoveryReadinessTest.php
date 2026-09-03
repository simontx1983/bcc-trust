<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\CosmwasmScanEligibility;
use BCC\Trust\Onchain\Support\DiscoveryReadiness;
use BCC\Trust\Onchain\ValueObjects\DiscoveryRunError;
use BCC\Trust\Onchain\ValueObjects\DiscoveryScanMode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * The one readiness decision, tested where it is PURE.
 *
 * ── WHY THE PURE ENTRY POINT IS THE RIGHT TARGET ────────────────────────
 * `evaluate()` takes all seven facts as arguments, so every combination is
 * reachable without defining a constant, seeding a chain row or touching a
 * repository. The wired entry points get their own coverage elsewhere;
 * what must be exhaustive is the RULE, and in particular its ORDER — which
 * is the part that carries the security property.
 */
#[CoversClass(DiscoveryReadiness::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class DiscoveryReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/discovery-readiness-stubs.php';
    }

    /** Everything green, so each refusal below is caused by ONE change. */
    private static function eligibleArgs(): array
    {
        return [
            'chainType'        => 'cosmos',
            'nftSupported'     => true,
            'discoveryEnabled' => true,
            'backfillEnabled'  => true,
            'scanMode'         => DiscoveryScanMode::HISTORICAL,
            'chainVerdict'     => CosmwasmScanEligibility::ELIGIBLE,
            'activeRunExists'  => false,
        ];
    }

    private static function evaluate(array $overrides = []): string
    {
        $a = array_merge(self::eligibleArgs(), $overrides);

        return DiscoveryReadiness::evaluate(
            $a['chainType'],
            $a['nftSupported'],
            $a['discoveryEnabled'],
            $a['backfillEnabled'],
            $a['scanMode'],
            $a['chainVerdict'],
            $a['activeRunExists']
        );
    }

    /** The control: without it, every refusal below proves nothing. */
    public function testTheBaselineFixtureIsActuallyEligible(): void
    {
        self::assertSame(CosmwasmScanEligibility::ELIGIBLE, self::evaluate());
        self::assertTrue(DiscoveryReadiness::isEligible(self::evaluate()));
    }

    // ── (1) product support ─────────────────────────────────────────────

    public function testProductSupportOffRefuses(): void
    {
        self::assertSame(
            DiscoveryRunError::NFT_DISCOVERY_UNSUPPORTED,
            self::evaluate(['nftSupported' => false])
        );
    }

    /**
     * ⚠ NULL is "the column could not be read", not "false with extra
     * steps" — a pre-migration install, or a projection built before the
     * ALTER. It must refuse, because the alternative is walking a chain the
     * product never approved on the strength of an unreadable column.
     */
    public function testUnreadableProductSupportRefuses(): void
    {
        self::assertSame(
            DiscoveryRunError::NFT_DISCOVERY_UNSUPPORTED,
            self::evaluate(['nftSupported' => null])
        );
    }

    // ── (2)(3)(4) support is asked FIRST, so nothing can override it ────

    /**
     * THE SECURITY PROPERTY OF THIS CLASS, STATED AS A TEST.
     *
     * Every one of these fixtures is an unsupported chain with something
     * ELSE turned on that an operator or an environment could plausibly
     * set. All must still refuse for the SAME reason — proving the later
     * gates are never even consulted, rather than merely agreeing.
     *
     * @param array<string, mixed> $override
     */
    #[DataProvider('overridesThatMustNotRescueAnUnsupportedChain')]
    public function testNothingCanOverrideProductSupport(array $override): void
    {
        self::assertSame(
            DiscoveryRunError::NFT_DISCOVERY_UNSUPPORTED,
            self::evaluate(array_merge(['nftSupported' => false], $override))
        );
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function overridesThatMustNotRescueAnUnsupportedChain(): array
    {
        return [
            // A per-chain opt-in row — the thing an administrator CAN set
            // from the discovery screen.
            'opted in and eligible'      => [['chainVerdict' => CosmwasmScanEligibility::ELIGIBLE]],
            // The canary allowlist naming this very chain. An allowlist can
            // only ever NARROW; it must never promote.
            'named by the allowlist'     => [['chainVerdict' => CosmwasmScanEligibility::ELIGIBLE, 'discoveryEnabled' => true]],
            'global discovery armed'     => [['discoveryEnabled' => true, 'backfillEnabled' => true]],
            'incremental mode'           => [['scanMode' => DiscoveryScanMode::INCREMENTAL]],
            'no active run'              => [['activeRunExists' => false]],
        ];
    }

    /** A non-Cosmos chain is refused before support is even considered. */
    public function testANonCosmosChainIsRefusedAsUnsupportedFamily(): void
    {
        self::assertSame(DiscoveryRunError::CHAIN_UNSUPPORTED, self::evaluate(['chainType' => 'evm']));
        self::assertSame(DiscoveryRunError::CHAIN_UNSUPPORTED, self::evaluate(['chainType' => null]));
    }

    // ── (5)(6) the environment switches, gated BY MODE ──────────────────

    public function testHistoricalWithGlobalDiscoveryOffRefusesAndNamesIt(): void
    {
        self::assertSame(
            DiscoveryRunError::DISCOVERY_GLOBALLY_DISABLED,
            self::evaluate(['discoveryEnabled' => false, 'backfillEnabled' => false])
        );
    }

    public function testHistoricalWithOnlyBackfillOffRefusesAndNamesIt(): void
    {
        self::assertSame(
            DiscoveryRunError::HISTORICAL_BACKFILL_DISABLED,
            self::evaluate(['discoveryEnabled' => true, 'backfillEnabled' => false])
        );
    }

    /**
     * ⚠ THE ASYMMETRY IS DELIBERATE AND IS THE POINT.
     *
     * `runSupervisedSingleChainPass()` — the incremental pass — reads
     * NEITHER switch. Refusing incremental on a switch the executor never
     * consults would block a supervised operator run that would in fact
     * have succeeded, which is the failure direction nobody notices in
     * review. Readiness gates by mode precisely so the request gate and
     * the executor cannot disagree.
     */
    public function testIncrementalIsNotGatedOnTheEnvironmentSwitches(): void
    {
        self::assertSame(
            CosmwasmScanEligibility::ELIGIBLE,
            self::evaluate([
                'scanMode'         => DiscoveryScanMode::INCREMENTAL,
                'discoveryEnabled' => false,
                'backfillEnabled'  => false,
            ])
        );
    }

    // ── (7) the per-chain rules, returned in their owner's vocabulary ───

    /**
     * @param string $verdict  what CosmwasmScanEligibility said
     * @param string $expected what readiness reports
     */
    #[DataProvider('perChainVerdictMappings')]
    public function testThePerChainVerdictIsMappedNotReinvented(string $verdict, string $expected): void
    {
        self::assertSame($expected, self::evaluate(['chainVerdict' => $verdict]));
    }

    /** @return array<string, array{string, string}> */
    public static function perChainVerdictMappings(): array
    {
        return [
            'not opted in'       => [CosmwasmScanEligibility::NOT_OPTED_IN, CosmwasmScanEligibility::NOT_OPTED_IN],
            'paused'             => [CosmwasmScanEligibility::PAUSED, CosmwasmScanEligibility::PAUSED],
            'allowlist excluded' => [CosmwasmScanEligibility::ALLOWLIST_EXCLUDED, CosmwasmScanEligibility::ALLOWLIST_EXCLUDED],
            'measured no wasm'   => [CosmwasmScanEligibility::UNSUPPORTED, DiscoveryRunError::CHAIN_UNSUPPORTED],
            // "nobody could answer" and "a verdict from a newer build" both
            // fail closed rather than being guessed at.
            'unknown'            => [CosmwasmScanEligibility::UNKNOWN, DiscoveryRunError::DISCOVERY_DISABLED],
            'from a newer build' => ['some_future_verdict', DiscoveryRunError::DISCOVERY_DISABLED],
            'empty string'       => ['', DiscoveryRunError::DISCOVERY_DISABLED],
        ];
    }

    // ── the scan-mode rule, including the case nobody remembers ─────────

    /**
     * @param string|null $completedAt `cw_backfill_completed_at`
     */
    #[DataProvider('completionTimestamps')]
    public function testTheServerDerivesTheModeFromCompletion(?string $completedAt, string $expected): void
    {
        self::assertSame($expected, DiscoveryScanMode::forCompletedAt($completedAt));
    }

    /** @return array<string, array{string|null, string}> */
    public static function completionTimestamps(): array
    {
        return [
            'never walked'      => [null, DiscoveryScanMode::HISTORICAL],
            'empty string'      => ['', DiscoveryScanMode::HISTORICAL],
            'whitespace only'   => ['   ', DiscoveryScanMode::HISTORICAL],
            // ⚠ MySQL hands the zero date back as a STRING on some
            // configurations. It means "never", not "completed at year
            // zero" — and reading it as a completion would silently switch
            // a chain that has never been walked onto the incremental path,
            // skipping the entire backfill and the switch that guards it.
            'zero date'         => ['0000-00-00 00:00:00', DiscoveryScanMode::HISTORICAL],
            'zero date, short'  => ['0000-00-00', DiscoveryScanMode::HISTORICAL],
            'a real completion' => ['2026-08-19 17:29:32', DiscoveryScanMode::INCREMENTAL],
        ];
    }

    /**
     * And the mode decides whether the backfill switch is consulted, so the
     * zero date must not be able to skip that gate either.
     */
    public function testTheZeroDateStillRequiresTheBackfillSwitch(): void
    {
        self::assertSame(
            DiscoveryRunError::HISTORICAL_BACKFILL_DISABLED,
            self::evaluate([
                'scanMode'        => DiscoveryScanMode::forCompletedAt('0000-00-00 00:00:00'),
                'backfillEnabled' => false,
            ])
        );
    }

    // ── (8) an active run ───────────────────────────────────────────────

    public function testAnActiveRunRefusesADuplicate(): void
    {
        self::assertSame(DiscoveryRunError::ALREADY_ACTIVE, self::evaluate(['activeRunExists' => true]));
    }

    // ── isEligible is an identity test, not a negation ──────────────────

    /**
     * Written as identity so an unrecognised value is NOT eligible. A
     * negated check would treat every future or corrupted reason as a yes.
     */
    public function testOnlyTheEligibleReasonIsEligible(): void
    {
        self::assertTrue(DiscoveryReadiness::isEligible(CosmwasmScanEligibility::ELIGIBLE));

        foreach ([
            DiscoveryRunError::NFT_DISCOVERY_UNSUPPORTED,
            DiscoveryRunError::DISCOVERY_GLOBALLY_DISABLED,
            DiscoveryRunError::HISTORICAL_BACKFILL_DISABLED,
            DiscoveryRunError::CHAIN_UNSUPPORTED,
            DiscoveryRunError::DISCOVERY_DISABLED,
            DiscoveryRunError::ALREADY_ACTIVE,
            CosmwasmScanEligibility::NOT_OPTED_IN,
            CosmwasmScanEligibility::PAUSED,
            CosmwasmScanEligibility::ALLOWLIST_EXCLUDED,
            '',
            'anything_else',
        ] as $reason) {
            self::assertFalse(DiscoveryReadiness::isEligible($reason), $reason . ' must not be eligible');
        }
    }

    // ── the display surface ─────────────────────────────────────────────

    public function testOnlySupportedCosmosChainsAreAnNftScanSurface(): void
    {
        $supported = (object) ['id' => 8, 'chain_type' => 'cosmos', 'bcc_supports_nft_collections' => '1'];
        $off       = (object) ['id' => 15, 'chain_type' => 'cosmos', 'bcc_supports_nft_collections' => '0'];
        $evm       = (object) ['id' => 1, 'chain_type' => 'evm', 'bcc_supports_nft_collections' => '1'];
        $noColumn  = (object) ['id' => 9, 'chain_type' => 'cosmos'];

        self::assertTrue(DiscoveryReadiness::isNftDiscoverySurface($supported));
        self::assertFalse(DiscoveryReadiness::isNftDiscoverySurface($off));
        self::assertFalse(DiscoveryReadiness::isNftDiscoverySurface($evm));
        self::assertFalse(DiscoveryReadiness::isNftDiscoverySurface($noColumn));
    }

    // ── the page renders only the supported set ─────────────────────────

    /**
     * The scan surface is FILTERED, and filtered by the product switch.
     *
     * ── WHY THIS IS ASSERTED OVER THE REAL PAGE SOURCE ──────────────────
     * `VerifyCollectionsPage::render()` needs the whole admin bootstrap —
     * capabilities, tabs, four repository reads — so unit-rendering it
     * would test the harness. What must not regress is narrower and is
     * checkable directly: the render loop iterates a set produced by
     * `isNftDiscoverySurface()`, not `ChainRepository::getActive('cosmos')`
     * whole. PR 7 shipped the unfiltered version and put a Scan button on
     * Jackal and Osmosis.
     *
     * ⚠ Asserted over COMMENT-STRIPPED source: the explanation above the
     * loop legitimately mentions `getActive`, and matching prose would make
     * the comment the failure.
     */
    public function testTheAdminPageRendersOnlyTheSupportedScanSurface(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Admin/VerifyCollectionsPage.php'
        );

        $code = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        // The filter exists and is the product-support one.
        self::assertStringContainsString(
            'DiscoveryReadiness::isNftDiscoverySurface',
            $code,
            'the scan surface must be filtered by product support'
        );

        // And the panel loop consumes the FILTERED set, never the raw
        // active-cosmos list. This is the assertion that fails if the
        // filter is computed and then ignored.
        self::assertMatchesRegularExpression(
            '/foreach\s*\(\s*\$scanChains\s+as\s+\$scanChain\s*\)/',
            $code,
            'the render loop must iterate the filtered set'
        );
        self::assertDoesNotMatchRegularExpression(
            '/foreach\s*\(\s*ChainRepository::getActive\(\s*[\'"]cosmos[\'"]\s*\)\s+as\s+\$scanChain\s*\)/',
            $code,
            'the render loop must not iterate every active cosmos chain'
        );

        // The readiness reason reaches the panel rather than a hardcoded one.
        self::assertStringContainsString('DiscoveryReadiness::forSummaryRow', $code);
    }

    // ── (22) no chain identity is hardcoded ─────────────────────────────

    /**
     * TODAY'S SUPPORTED SET IS DATA, NOT CODE.
     *
     * Cosmos Hub and Injective are the two chains BCC currently offers NFT
     * discovery on, and that fact lives in
     * `wp_bcc_chains.bcc_supports_nft_collections` where an administrator
     * can change it. If a chain id, slug or name appears in the eligibility
     * logic, enabling a future chain becomes a deploy instead of a toggle —
     * and the "simple toggle on if they ever do get NFTs" promise is gone.
     */
    public function testNoChainIdentityIsHardcodedInTheEligibilityLogic(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2) . '/app/Domain/Onchain/Support/DiscoveryReadiness.php'
        );

        // Strip comments: the prose legitimately names chains when it
        // explains WHY the rule exists, and asserting over prose would
        // forbid the explanation rather than the behaviour.
        $code = '';
        foreach (token_get_all($src) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        foreach (['cosmoshub', 'cosmos_hub', 'injective', 'jackal', 'osmosis', 'kujira', 'dungeon', 'stargaze'] as $needle) {
            self::assertStringNotContainsStringIgnoringCase(
                $needle,
                $code,
                'chain identity must be data, not code: ' . $needle
            );
        }

        // And no bare chain-id list either.
        self::assertDoesNotMatchRegularExpression('/\[\s*8\s*,\s*13\s*\]/', $code);
    }
}
