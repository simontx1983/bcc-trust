<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Admin\NftDiscoveryPage;
use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository;
use BCC\Trust\Onchain\Support\NftCapabilityOptionState;
use BCC\Trust\Onchain\Support\NftChainCapability;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * What the Add-a-collection panel TELLS the operator about validation.
 *
 * ── THE DEFECT THIS EXISTS TO PREVENT ───────────────────────────────────
 * The panel's disclosure was computed by a helper hardcoded to
 * `$family === 'cosmos'`. Its own comment promised that registering an EVM or
 * Solana validation driver would "flip this copy" — the `$family` conjunct
 * meant it never could. PR E registered OP_VALIDATION for `evm_rpc` and
 * `das_helius`, so the page went on telling Ethereum, Base and Solana
 * operators their submissions were accepted on shape alone while the service
 * was in fact probing the chain.
 *
 * Both halves of that failure are lies, in opposite directions, and the
 * second is the dangerous one:
 *
 *   under-claiming  an operator distrusts a row that WAS validated
 *   over-claiming   an operator trusts a row that was NOT — and DECISION 7
 *                   deliberately leaves most EVM chains unvalidated, so this
 *                   is a reachable state, not a hypothetical
 *
 * The disclosure is therefore driven by `manual_intake`, the SAME predicate
 * the capability editor grants on, and asked PER CHAIN because two chains in
 * one family legitimately differ.
 */
#[CoversClass(NftChainCapability::class)]
#[CoversClass(NftDiscoveryPage::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ManualIntakeValidationDisclosureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Escaping and URL helpers first, then the capability collaborators —
        // the matrix stubs deliberately load their namespace-scoped
        // `get_option` shim last. Nothing that DECIDES the disclosure is
        // stubbed: NftChainCapability, NftDriverRegistry, NftProviderReadiness
        // and NftLaunchChains all run for real.
        require_once __DIR__ . '/../Stubs/manual-intake-disclosure-stubs.php';
        require_once __DIR__ . '/../Stubs/nft-discovery-matrix-stubs.php';

        ChainNftCapabilityRepository::reset();
        ChainCheckpointRepository::reset();
        NftCapabilityOptionState::reset();
    }

    private static function chain(string $slug, string $type, string $chainId = ''): object
    {
        return (object) [
            'id'                                  => '7',
            'slug'                                => $slug,
            'name'                                => ucfirst($slug),
            'chain_type'                          => $type,
            // ⚠ `chain_id_hex`, the EIP-155 identity — NOT the slug. Renaming a
            // row must not move it in or out of launch scope.
            'chain_id_hex'                        => $chainId,
            'rpc_url'                             => 'https://rpc.example.test',
            'rest_url'                            => 'https://api.example.test',
            'bcc_supports_nft_collections'        => '1',
            'manual_collection_discovery_enabled' => '1',
        ];
    }

    // ── The predicate the disclosure and the grant share ─────────────────

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function intakeCapableChains(): array
    {
        return [
            'cosmos hub' => ['cosmos', 'cosmos', ''],
            'ethereum'   => ['ethereum', 'evm', '0x1'],
            'base'       => ['base', 'evm', '0x2105'],
            'solana'     => ['solana', 'solana', ''],
        ];
    }

    #[DataProvider('intakeCapableChains')]
    public function testAValidatedChainReportsItself(string $slug, string $type, string $chainId): void
    {
        self::assertTrue(
            NftChainCapability::canTakeManualIntake(self::chain($slug, $type, $chainId)),
            "{$slug} has a validation driver and is inside the approved scope"
        );
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function nonLaunchEvmChains(): array
    {
        return [
            'polygon'  => ['polygon', '0x89'],
            'arbitrum' => ['arbitrum', '0xa4b1'],
            'optimism' => ['optimism', '0xa'],
            'bnb'      => ['bnb', '0x38'],
        ];
    }

    /**
     * ⚠ THE OVER-CLAIM CASE. An EVM chain the validator *could* technically
     * probe but DECISION 7 did not approve must report NOT validated.
     */
    #[DataProvider('nonLaunchEvmChains')]
    public function testANonLaunchEvmChainDoesNotClaimValidation(string $slug, string $chainId): void
    {
        self::assertFalse(
            NftChainCapability::canTakeManualIntake(self::chain($slug, 'evm', $chainId)),
            "{$slug} is outside the approved EVM launch scope and must not claim validation"
        );
    }

    // ── What the panel actually prints ───────────────────────────────────

    /** @param list<array<string, mixed>> $rows */
    private static function renderPanel(string $family, array $rows): string
    {
        $method = new \ReflectionMethod(NftDiscoveryPage::class, 'render_add_collection');
        $method->setAccessible(true);

        // finally, so a throw inside the panel does not leave the buffer open
        // and turn one clear error into a cascade of risky tests.
        ob_start();
        try {
            $method->invoke(null, $family, $rows);
        } finally {
            $markup = (string) ob_get_clean();
        }

        return $markup;
    }

    /** @param bool|null $intake */
    private static function row(string $slug, $intake): array
    {
        return [
            'chain_id'       => 7,
            'slug'           => $slug,
            'name'           => ucfirst($slug),
            'is_active'      => true,
            'bcc_supports'   => true,
            'manual_enabled' => true,
            'eligible'       => true,
            'manual_intake'  => $intake,
        ];
    }

    public function testEveryChainValidatedPrintsTheValidatedDisclosure(): void
    {
        $markup = self::renderPanel('evm', [self::row('ethereum', true), self::row('base', true)]);

        self::assertStringContainsString('checked against the chain', $markup);
        self::assertStringNotContainsString('Not validated on the chains listed here', $markup);
        self::assertStringNotContainsString('Validation differs by chain here', $markup);
    }

    public function testNoChainValidatedPrintsTheUnvalidatedWarning(): void
    {
        $markup = self::renderPanel('evm', [self::row('polygon', false), self::row('arbitrum', false)]);

        self::assertStringContainsString('Not validated on the chains listed here', $markup);
        self::assertStringContainsString('A valid address is not a verified collection', $markup);
        self::assertStringNotContainsString('checked against the chain', $markup);
    }

    /**
     * ⚠ THE MIXED CASE IS THE WHOLE REASON THIS IS PER CHAIN. One family-wide
     * answer would have to lie to one half of the list.
     */
    public function testAMixedFamilyPrintsTheMixedWarningWithACount(): void
    {
        $markup = self::renderPanel('evm', [
            self::row('ethereum', true),
            self::row('polygon', false),
            self::row('arbitrum', false),
        ]);

        self::assertStringContainsString('Validation differs by chain here', $markup);
        self::assertStringContainsString('1 of 3', $markup);
        self::assertStringNotContainsString('Not validated on the chains listed here', $markup);
    }

    /**
     * An UNREADABLE capability store yields null, and null must not read as
     * validated — the panel fails closed onto the warning.
     */
    public function testAnUnreadableCapabilityDoesNotClaimValidation(): void
    {
        $markup = self::renderPanel('evm', [self::row('ethereum', null)]);

        self::assertStringContainsString('Not validated on the chains listed here', $markup);
        self::assertStringNotContainsString('checked against the chain', $markup);
    }

    /**
     * The grant copy must promise ONE contract, not a chain-wide run. The
     * permission it grants confers no enumeration authority whatsoever.
     */
    public function testThePanelNeverPromisesAChainWideRun(): void
    {
        $markup = self::renderPanel('cosmos', [self::row('cosmos', true)]);

        foreach (['chain-wide', 'start a discovery', 'full scan'] as $overclaim) {
            self::assertStringNotContainsStringIgnoringCase(
                $overclaim,
                $markup,
                'manual intake is one contract; it must never be described as a run'
            );
        }
    }
}
