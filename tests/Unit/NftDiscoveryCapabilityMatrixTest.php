<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository;
use BCC\Trust\Onchain\Support\HeliusEndpoint;
use BCC\Trust\Onchain\Support\NftCapabilityOptionState;
use BCC\Trust\Onchain\Support\NftChainCapability;
use BCC\Trust\Onchain\Support\NftDriverRegistry;
use BCC\Trust\Onchain\ValueObjects\ChainNftCapabilityOverrides;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * THE LADDER: every rung, every reason, and the two orderings that matter.
 *
 * `operationMatrix()` is what the NFT Discovery page prints and what its
 * one provider-consuming control gates on. It answers six operations per
 * chain, and for each one it has to say not just "no" but WHICH no —
 * because "no driver exists" and "you have not configured the driver that
 * does" send an operator to two completely different places, and one of
 * them is a place that does not exist.
 *
 * Every unsure branch must refuse. There is no rung that falls through to
 * "no restriction".
 */
#[CoversClass(NftChainCapability::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class NftDiscoveryCapabilityMatrixTest extends TestCase
{
    private const CHAIN_ID = 7;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/nft-discovery-matrix-stubs.php';

        ChainNftCapabilityRepository::reset();
        ChainCheckpointRepository::reset();
        NftCapabilityOptionState::reset();
    }

    /**
     * A Cosmos chain with an LCD endpoint — the only family with an
     * enumeration driver, and the only one that can ever reach READY for it.
     */
    private static function cosmos(
        bool $bccSupports = true,
        bool $manualEnabled = true,
        string $rest = 'https://lcd.example'
    ): object {
        return (object) [
            'id'                                  => (string) self::CHAIN_ID,
            'slug'                                => 'cosmos',
            'name'                                => 'Cosmos Hub',
            'chain_type'                          => 'cosmos',
            'rpc_url'                             => '',
            'rest_url'                            => $rest,
            'bcc_supports_nft_collections'        => $bccSupports ? '1' : '0',
            'manual_collection_discovery_enabled' => $manualEnabled ? '1' : '0',
        ];
    }

    private static function evm(string $rpc = ''): object
    {
        return (object) [
            'id'                                  => (string) self::CHAIN_ID,
            'slug'                                => 'ethereum',
            'name'                                => 'Ethereum',
            'chain_type'                          => 'evm',
            'rpc_url'                             => $rpc,
            'rest_url'                            => '',
            'bcc_supports_nft_collections'        => '1',
            'manual_collection_discovery_enabled' => '1',
        ];
    }

    private static function solana(string $rpc = ''): object
    {
        return (object) [
            'id'                                  => (string) self::CHAIN_ID,
            'slug'                                => 'solana',
            'name'                                => 'Solana',
            'chain_type'                          => 'solana',
            'rpc_url'                             => $rpc,
            'rest_url'                            => '',
            'bcc_supports_nft_collections'        => '1',
            'manual_collection_discovery_enabled' => '1',
        ];
    }

    /** @return array<string, mixed> */
    private static function op(object $chain, string $operation): array
    {
        $matrix = NftChainCapability::operationMatrix($chain);

        return $matrix['operations'][$operation];
    }

    // ── Shape ───────────────────────────────────────────────────────────

    public function testEveryOperationGetsARow(): void
    {
        $matrix = NftChainCapability::operationMatrix(self::cosmos());

        self::assertSame(
            NftDriverRegistry::operations(),
            array_keys($matrix['operations']),
            'the matrix covers every registry operation, in the registry order'
        );
    }

    /**
     * ONE override read per chain, however many operations are answered.
     *
     * Six reads would be six chances for the six rows an operator sees to
     * disagree with each other about what that operator configured.
     */
    public function testTheOverrideStoreIsReadExactlyOncePerChain(): void
    {
        NftChainCapability::operationMatrix(self::cosmos());

        self::assertSame(1, ChainNftCapabilityRepository::$reads);
    }

    // ── Rung 1: the override store is unreadable ────────────────────────

    /** @return array<string, array{0: string}> */
    public static function unavailableReasons(): array
    {
        return [
            'read failed'   => [ChainNftCapabilityOverrides::REASON_READ_FAILED],
            'overflow'      => [ChainNftCapabilityOverrides::REASON_OVERFLOW],
            'malformed'     => [ChainNftCapabilityOverrides::REASON_MALFORMED],
            'invalid chain' => [ChainNftCapabilityOverrides::REASON_INVALID_CHAIN],
        ];
    }

    #[DataProvider('unavailableReasons')]
    public function testAnUnreadableOverrideStoreMakesEveryOperationUnknown(string $reason): void
    {
        ChainNftCapabilityRepository::seedUnavailable(self::CHAIN_ID, $reason);

        $matrix = NftChainCapability::operationMatrix(self::cosmos());

        self::assertFalse($matrix['overrides_available']);
        self::assertSame($reason, $matrix['overrides_reason']);

        foreach ($matrix['operations'] as $operation => $row) {
            self::assertSame(
                NftChainCapability::OP_UNKNOWN,
                $row['status'],
                "{$operation} must be unknown when operator intent cannot be read"
            );
            self::assertStringContainsString($reason, $row['reason']);

            // And no driver list is offered from registry defaults, which
            // would silently restore a driver somebody had disabled.
            self::assertSame([], $row['registered']);
            self::assertSame([], $row['drivers']);
            self::assertSame([], $row['ready']);
        }
    }

    /**
     * A read at its ceiling is a SUBSET, and a subset is not an answer.
     *
     * The repository asks for `LIMIT n+1` precisely so it can tell "full"
     * from "complete". Applying a truncated override set would honour some
     * restrictions and silently drop others — fail-open by another route.
     */
    public function testAnOverflowingReadIsRefusedRatherThanPartiallyApplied(): void
    {
        ChainNftCapabilityRepository::seedUnavailable(
            self::CHAIN_ID,
            ChainNftCapabilityOverrides::REASON_OVERFLOW
        );

        $row = self::op(self::cosmos(), NftDriverRegistry::OP_ENUMERATION);

        self::assertSame(NftChainCapability::OP_UNKNOWN, $row['status']);
        self::assertFalse(NftChainCapability::isOperationReady($row['status']));
    }

    /**
     * Zero override rows is NOT the same as an unreadable store.
     *
     * `loaded([])` means "read fine, this chain has no overrides, registry
     * defaults apply" and must reach READY. Collapsing the two would make
     * every normally-configured chain permanently unknown.
     */
    public function testZeroOverrideRowsIsAvailableAndCanReachReady(): void
    {
        ChainNftCapabilityRepository::seedLoaded(self::CHAIN_ID, []);

        $matrix = NftChainCapability::operationMatrix(self::cosmos());

        self::assertTrue($matrix['overrides_available']);
        self::assertNull($matrix['overrides_reason']);
        self::assertSame(
            NftChainCapability::OP_READY,
            $matrix['operations'][NftDriverRegistry::OP_ENUMERATION]['status']
        );
    }

    // ── Rung 2: a capability column is absent ───────────────────────────

    /**
     * The PRODUCT column is global: it says whether BCC supports NFT
     * collections on this chain at all, so an install that cannot store it
     * cannot describe any operation.
     */
    public function testAnAbsentProductColumnMakesEveryOperationUnknown(): void
    {
        $chain = self::cosmos();
        unset($chain->bcc_supports_nft_collections);

        foreach (NftChainCapability::operationMatrix($chain)['operations'] as $operation => $row) {
            self::assertSame(
                NftChainCapability::OP_UNKNOWN,
                $row['status'],
                "{$operation}: an install that cannot store the answer must not claim one"
            );
        }
    }

    /**
     * The MANUAL column is scoped: it is permission to START a discovery,
     * so an install that cannot store it cannot say whether one may be
     * started — and that is the whole of what it cannot say.
     */
    public function testAnAbsentManualColumnMakesTheStartedOperationUnknown(): void
    {
        $chain = self::cosmos();
        unset($chain->manual_collection_discovery_enabled);

        $row = self::op($chain, NftDriverRegistry::OP_ENUMERATION);

        self::assertSame(NftChainCapability::OP_UNKNOWN, $row['status']);
        self::assertSame(NftChainCapability::REASON_MANUAL_COLUMN_ABSENT, $row['reason']);
    }

    /**
     * ⚠️ AND IT LEAVES THE OTHERS ALONE.
     *
     * Nothing but the operator-started operations reads this column.
     * Marking metadata, ownership, validation, curated feeds and wallet
     * discovery `UNKNOWN` because of it blamed five working operations on
     * a switch none of them consults — and on a stock install, where the
     * column is present but the projection could stop carrying it, that
     * would black out the entire page.
     */
    public function testAnAbsentManualColumnDoesNotMakeNonStartedOperationsUnknown(): void
    {
        $chain = self::cosmos();
        unset($chain->manual_collection_discovery_enabled);

        $matrix = NftChainCapability::operationMatrix($chain)['operations'];

        foreach (NftDriverRegistry::operations() as $operation) {
            if ($operation === NftDriverRegistry::OP_ENUMERATION) {
                continue;
            }

            self::assertNotSame(
                NftChainCapability::REASON_MANUAL_COLUMN_ABSENT,
                $matrix[$operation]['reason'],
                "{$operation} must not be refused by a column it never reads"
            );
        }

        // The LCD-backed ones answer for themselves, and answer READY.
        self::assertSame(
            NftChainCapability::OP_READY,
            $matrix[NftDriverRegistry::OP_METADATA]['status']
        );
        self::assertSame(
            NftChainCapability::OP_READY,
            $matrix[NftDriverRegistry::OP_OWNERSHIP]['status']
        );
        self::assertSame(
            NftChainCapability::OP_READY,
            $matrix[NftDriverRegistry::OP_VALIDATION]['status']
        );
    }

    /**
     * Permission FALSE refuses the started operation and, again, only it.
     */
    public function testManualPermissionFalseRefusesOnlyTheStartedOperation(): void
    {
        $matrix = NftChainCapability::operationMatrix(self::cosmos(true, false))['operations'];

        self::assertSame(
            NftChainCapability::OP_MANUAL_DISABLED,
            $matrix[NftDriverRegistry::OP_ENUMERATION]['status']
        );

        foreach ([
            NftDriverRegistry::OP_METADATA,
            NftDriverRegistry::OP_OWNERSHIP,
            NftDriverRegistry::OP_VALIDATION,
        ] as $operation) {
            self::assertSame(
                NftChainCapability::OP_READY,
                $matrix[$operation]['status'],
                "{$operation} must not be refused by the manual-START permission"
            );
        }
    }

    // ── Rung 3: the measured refusal, and where it sits ─────────────────

    public function testAMeasuredRefusalRefusesEnumeration(): void
    {
        ChainCheckpointRepository::seedCwState(
            self::CHAIN_ID,
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED
        );

        $matrix = NftChainCapability::operationMatrix(self::cosmos());

        self::assertTrue($matrix['measured_unsupported']);
        self::assertSame(
            NftChainCapability::OP_CHAIN_UNSUPPORTED,
            $matrix['operations'][NftDriverRegistry::OP_ENUMERATION]['status']
        );
        self::assertSame(
            NftChainCapability::REASON_MEASURED_NO_WASM,
            $matrix['operations'][NftDriverRegistry::OP_ENUMERATION]['reason']
        );
    }

    /**
     * ⚠️ AND IT REFUSES NOTHING ELSE.
     *
     * `cw_discovery_state` is evidence about whether the wasm module can be
     * WALKED to enumerate the chain. A chain with no wasm module can still
     * validate a CW-721 contract an operator hands us, report its owner and
     * return its metadata — those go through `cw721_lcd`, which needs an
     * LCD endpoint and never touches the code listing.
     *
     * An earlier version of this model marked all six operations
     * `CHAIN_UNSUPPORTED` from this one measurement, calling a chain wholly
     * incapable on the strength of a fact about one of its operations. The
     * test that covered it claimed "every operation" in its name while
     * asserting only enumeration, so the over-reach was invisible.
     */
    public function testAMeasuredRefusalDoesNotRefuseAnyOtherOperation(): void
    {
        ChainCheckpointRepository::seedCwState(
            self::CHAIN_ID,
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED
        );

        $matrix = NftChainCapability::operationMatrix(self::cosmos());

        foreach ($matrix['operations'] as $operation => $row) {
            if ($operation === NftDriverRegistry::OP_ENUMERATION) {
                continue;
            }

            self::assertNotSame(
                NftChainCapability::OP_CHAIN_UNSUPPORTED,
                $row['status'],
                "{$operation} must not inherit a measurement about the code listing"
            );
            self::assertNotSame(NftChainCapability::REASON_MEASURED_NO_WASM, $row['reason']);
        }
    }

    /**
     * Each non-enumeration operation gets the answer ITS OWN drivers and
     * readiness dictate — the same answer it would have with no
     * measurement present at all.
     *
     * Asserted as an equality against the unmeasured chain rather than as a
     * list of "not this": that catches a measurement leaking into any
     * status, including one this test did not think to name.
     */
    public function testNonEnumerationOperationsAreUnchangedByTheMeasurement(): void
    {
        $withoutMeasurement = NftChainCapability::operationMatrix(self::cosmos())['operations'];

        ChainCheckpointRepository::seedCwState(
            self::CHAIN_ID,
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED
        );
        $withMeasurement = NftChainCapability::operationMatrix(self::cosmos())['operations'];

        foreach (NftDriverRegistry::operations() as $operation) {
            if ($operation === NftDriverRegistry::OP_ENUMERATION) {
                continue;
            }

            self::assertSame(
                $withoutMeasurement[$operation]['status'],
                $withMeasurement[$operation]['status'],
                "{$operation} changed answer because of a measurement that does not describe it"
            );
        }

        // And the LCD-backed operations really are answering for
        // themselves, so the equality above is not two refusals matching.
        self::assertSame(
            NftChainCapability::OP_READY,
            $withMeasurement[NftDriverRegistry::OP_VALIDATION]['status']
        );
        self::assertSame(
            NftChainCapability::OP_READY,
            $withMeasurement[NftDriverRegistry::OP_METADATA]['status']
        );
        self::assertSame(
            NftChainCapability::OP_READY,
            $withMeasurement[NftDriverRegistry::OP_OWNERSHIP]['status']
        );
    }

    /**
     * ⚠️ THE ORDERING THAT DIFFERS FROM `verdict()`, ON PURPOSE.
     *
     * `verdict()` names the measured refusal FIRST, because for a decision
     * the thing no operator can change is the most useful thing to say.
     *
     * The matrix produces a DISPLAY, and a display that prints a confident
     * "this chain has no wasm module" while the capability store is
     * unreadable has converted "we could not read our own configuration"
     * into a statement about the blockchain. So when the read failed, the
     * answer is UNKNOWN and the measurement is supporting detail — it may
     * not upgrade an unavailable read into a confident verdict.
     */
    public function testAMeasuredRefusalNeverUpgradesAnUnreadableStoreIntoAConfidentVerdict(): void
    {
        ChainCheckpointRepository::seedCwState(
            self::CHAIN_ID,
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED
        );
        ChainNftCapabilityRepository::seedUnavailable(
            self::CHAIN_ID,
            ChainNftCapabilityOverrides::REASON_READ_FAILED
        );

        $matrix = NftChainCapability::operationMatrix(self::cosmos());

        // The measurement is still reported as evidence…
        self::assertTrue($matrix['measured_unsupported']);

        // …but the STATUS is unknown, not chain_unsupported.
        self::assertSame(
            NftChainCapability::OP_UNKNOWN,
            $matrix['operations'][NftDriverRegistry::OP_ENUMERATION]['status']
        );

        // And `verdict()` is untouched by any of this: it still leads with
        // the measurement, exactly as it always did.
        self::assertSame(NftChainCapability::CHAIN_UNSUPPORTED, $matrix['verdict']);
    }

    /**
     * A `cw_*` value on a non-Cosmos row means nothing and is not read.
     *
     * The checkpoint table is shared with the EVM indexer, so an Ethereum
     * row can carry a stale or defaulted `cw_discovery_state`. Reading it
     * would answer "this chain has no wasm module" — true, irrelevant, and
     * it would MASK the real reason an EVM scan is refused.
     */
    public function testACosmosMeasurementIsIgnoredOnAnEvmChain(): void
    {
        ChainCheckpointRepository::seedCwState(
            self::CHAIN_ID,
            ChainCheckpointRepository::CW_STATE_UNSUPPORTED
        );

        $matrix = NftChainCapability::operationMatrix(self::evm());

        self::assertFalse($matrix['measured_unsupported']);
        self::assertSame(
            NftChainCapability::OP_NO_DRIVER,
            $matrix['operations'][NftDriverRegistry::OP_ENUMERATION]['status'],
            'the real reason must survive, not be masked by an irrelevant measurement'
        );
    }

    // ── Rung 4: the product decision ────────────────────────────────────

    public function testProductSupportOffRefusesEveryOperation(): void
    {
        foreach (NftChainCapability::operationMatrix(self::cosmos(false))['operations'] as $row) {
            self::assertSame(NftChainCapability::OP_NO_BCC_SUPPORT, $row['status']);
            self::assertSame(NftChainCapability::REASON_PRODUCT_SUPPORT_DISABLED, $row['reason']);
        }
    }

    // ── Rung 5 vs 6: structural absence vs an operator switch ───────────

    /**
     * THE DISTINCTION THIS WHOLE CLASS EXISTS FOR.
     *
     * `no_driver` is permanent: no provider sells chain-wide NFT contract
     * enumeration on EVM or Solana, so no credential and no override will
     * ever change it. `disabled` is an override row, and deleting it will.
     *
     * Fusing them would leave a chain looking one API key away from
     * something nobody sells.
     */
    public function testEveryEvmChainHasNoEnumerationDriverPermanently(): void
    {
        $row = self::op(self::evm('https://eth-mainnet.g.alchemy.com/v2/realkey'), NftDriverRegistry::OP_ENUMERATION);

        self::assertSame(NftChainCapability::OP_NO_DRIVER, $row['status']);
        self::assertSame(NftChainCapability::REASON_NO_REGISTERED_DRIVER, $row['reason']);
        self::assertSame([], $row['registered'], 'the registry offers nothing to disable');
    }

    public function testSolanaAlsoHasNoEnumerationDriverPermanently(): void
    {
        $row = self::op(self::solana('https://mainnet.helius-rpc.com/?api-key=k'), NftDriverRegistry::OP_ENUMERATION);

        self::assertSame(NftChainCapability::OP_NO_DRIVER, $row['status']);
    }

    public function testADriverDisabledByAnOverrideIsReportedAsDisabledNotAbsent(): void
    {
        ChainNftCapabilityRepository::seedLoaded(self::CHAIN_ID, [[
            'operation'  => NftDriverRegistry::OP_ENUMERATION,
            'driver_key' => NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
            'enabled'    => false,
            'priority'   => 0,
        ]]);

        $row = self::op(self::cosmos(), NftDriverRegistry::OP_ENUMERATION);

        self::assertSame(NftChainCapability::OP_DISABLED, $row['status']);
        self::assertSame(NftChainCapability::REASON_ALL_DRIVERS_DISABLED, $row['reason']);

        // The baseline proves the registry DID offer one — which is the
        // whole basis for calling this "disabled" rather than "absent".
        self::assertSame(
            [NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION],
            $row['registered']
        );
        self::assertSame([], $row['drivers']);
    }

    // ── Rung 7: the manual permission ───────────────────────────────────

    public function testManualPermissionOffRefusesTheOperatorStartedOperation(): void
    {
        $row = self::op(self::cosmos(true, false), NftDriverRegistry::OP_ENUMERATION);

        self::assertSame(NftChainCapability::OP_MANUAL_DISABLED, $row['status']);
        self::assertSame(NftChainCapability::REASON_MANUAL_PERMISSION_DISABLED, $row['reason']);
        self::assertTrue($row['operator_started']);
    }

    /**
     * …and NOT the ones nobody starts by pressing a button.
     *
     * `manual_collection_discovery_enabled` is permission to START a
     * discovery. Applying it to metadata or ownership — which run as a
     * consequence of other work — would report those as blocked by a switch
     * that has nothing to do with them.
     */
    public function testManualPermissionOffDoesNotRefuseNonStartedOperations(): void
    {
        $chain = self::cosmos(true, false);

        foreach ([
            NftDriverRegistry::OP_METADATA,
            NftDriverRegistry::OP_VALIDATION,
            NftDriverRegistry::OP_OWNERSHIP,
        ] as $operation) {
            $row = self::op($chain, $operation);

            self::assertFalse($row['operator_started'], "{$operation} is not operator-started");
            self::assertNotSame(
                NftChainCapability::OP_MANUAL_DISABLED,
                $row['status'],
                "{$operation} must not be blamed on the manual-start permission"
            );
        }
    }

    /**
     * Provider readiness ALONE never makes an operator-started operation
     * available.
     *
     * A perfectly configured LCD endpoint on a chain nobody granted the
     * permission for is still a refusal, and it is refused with a reason
     * that names the permission rather than the endpoint.
     */
    public function testAConfiguredProviderDoesNotSubstituteForThePermission(): void
    {
        $row = self::op(self::cosmos(true, false, 'https://lcd.example'), NftDriverRegistry::OP_ENUMERATION);

        self::assertSame(NftChainCapability::OP_MANUAL_DISABLED, $row['status']);
        self::assertSame(
            [NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION],
            $row['ready'],
            'the driver really is ready — and it is still refused'
        );
    }

    // ── Rung 8: configured, or not ──────────────────────────────────────

    public function testADriverWithNoEndpointIsProviderUnavailableNotAbsent(): void
    {
        $row = self::op(self::cosmos(true, true, ''), NftDriverRegistry::OP_ENUMERATION);

        self::assertSame(NftChainCapability::OP_PROVIDER_UNAVAILABLE, $row['status']);
        self::assertSame(NftChainCapability::REASON_NO_READY_DRIVER, $row['reason']);
        self::assertSame([NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION], $row['drivers']);
        self::assertSame([], $row['ready']);
    }

    // ── Rung 9 ──────────────────────────────────────────────────────────

    public function testAFullyPermittedAndConfiguredCosmosChainIsReady(): void
    {
        $row = self::op(self::cosmos(), NftDriverRegistry::OP_ENUMERATION);

        self::assertSame(NftChainCapability::OP_READY, $row['status']);
        self::assertSame(NftChainCapability::REASON_READY, $row['reason']);
        self::assertTrue(NftChainCapability::isOperationReady($row['status']));
    }

    /** An unrecognised status is never "ready" — identity test, not exclusion list. */
    public function testAnUnrecognisedStatusIsNotReady(): void
    {
        foreach (['', 'ready', 'OP_READY', 'op_ready_v2', 'scannable'] as $nonsense) {
            self::assertFalse(NftChainCapability::isOperationReady($nonsense));
        }
    }

    // ── The Solana split ────────────────────────────────────────────────

    /**
     * TWO DAS drivers, TWO endpoints, TWO answers — on ONE chain, at ONE
     * instant.
     *
     * This is the defect the split exists to prevent: configure Helius,
     * leave the chain row on the public RPC, and a single `das` driver
     * reported wallet discovery READY while every `getAssetsByOwner` call
     * went to an endpoint with no DAS.
     */
    public function testDasRpcAndDasHeliusAnswerDifferentlyOnTheSameChain(): void
    {
        define('BCC_HELIUS_API_KEY', 'a-real-key');

        // Chain row left on the PUBLIC RPC: das_rpc cannot work.
        $chain = self::solana('https://api.mainnet-beta.solana.com');

        $walletDiscovery = self::op($chain, NftDriverRegistry::OP_WALLET_DISCOVERY);
        $metadata        = self::op($chain, NftDriverRegistry::OP_METADATA);

        self::assertContains(NftDriverRegistry::DRIVER_DAS_RPC, $walletDiscovery['drivers']);
        self::assertNotContains(
            NftDriverRegistry::DRIVER_DAS_RPC,
            $walletDiscovery['ready'],
            'the chain row is on the public RPC, which has no DAS'
        );
        self::assertSame(NftChainCapability::OP_PROVIDER_UNAVAILABLE, $walletDiscovery['status']);

        self::assertContains(NftDriverRegistry::DRIVER_DAS_HELIUS, $metadata['ready']);
        self::assertSame(NftChainCapability::OP_READY, $metadata['status']);
    }

    /** Plain `das` is gone and must not come back under any operation. */
    public function testThePlainDasDriverIsAbsentEverywhere(): void
    {
        define('BCC_HELIUS_API_KEY', 'a-real-key');

        $matrix = NftChainCapability::operationMatrix(self::solana('https://rpc.example/x'));

        foreach ($matrix['operations'] as $operation => $row) {
            foreach ([$row['registered'], $row['drivers'], $row['ready']] as $list) {
                self::assertNotContains('das', $list, "plain `das` reappeared under {$operation}");
            }
        }
    }

    // ── Endpoint-bound refusal, and its recovery ────────────────────────

    /**
     * A mark written against THIS endpoint still applies, and is surfaced
     * as evidence beside the refusal.
     */
    public function testAnEndpointBoundRefusalIsReportedAgainstTheEndpointThatEarnedIt(): void
    {
        $rpc = 'https://das.example/rpc';
        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(self::CHAIN_ID)] = [
            'rpc_url'     => HeliusEndpoint::redactEndpoint($rpc),
            'code'        => -32601,
            'message'     => 'Method not found',
            'detected_at' => 1,
        ];

        $row = self::op(self::solana($rpc), NftDriverRegistry::OP_WALLET_DISCOVERY);

        self::assertSame(NftChainCapability::OP_PROVIDER_UNAVAILABLE, $row['status']);
        self::assertArrayHasKey(NftDriverRegistry::DRIVER_DAS_RPC, $row['endpoint_refusals']);
        self::assertSame(-32601, $row['endpoint_refusals'][NftDriverRegistry::DRIVER_DAS_RPC]['code']);
    }

    /**
     * Repointing the chain at a DIFFERENT endpoint clears it.
     *
     * Without this the operator hits a dead end they cannot escape: the
     * seeded public RPC answers "method not found", the mark is stored, and
     * readiness stays false forever however good the new endpoint is.
     */
    public function testRepointingTheChainClearsAStaleEndpointBoundRefusal(): void
    {
        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(self::CHAIN_ID)] = [
            'rpc_url'     => 'https://old.example/rpc',
            'code'        => -32601,
            'message'     => 'Method not found',
            'detected_at' => 1,
        ];

        $row = self::op(self::solana('https://new.example/rpc'), NftDriverRegistry::OP_WALLET_DISCOVERY);

        self::assertSame([], $row['endpoint_refusals'], 'a mark for another endpoint must not apply');
        self::assertContains(NftDriverRegistry::DRIVER_DAS_RPC, $row['ready']);
        self::assertSame(NftChainCapability::OP_READY, $row['status']);
    }

    /** `das_helius` is never endpoint-marked — nothing writes one against it. */
    public function testTheMetadataDriverIsNeverReportedAsEndpointRefused(): void
    {
        define('BCC_HELIUS_API_KEY', 'a-real-key');

        NftCapabilityOptionState::$options[HeliusEndpoint::dasUnsupportedOptionKey(self::CHAIN_ID)] = [
            'rpc_url'     => HeliusEndpoint::redactEndpoint('https://das.example/rpc'),
            'code'        => -32601,
            'message'     => 'Method not found',
            'detected_at' => 1,
        ];

        $row = self::op(self::solana('https://das.example/rpc'), NftDriverRegistry::OP_METADATA);

        self::assertSame([], $row['endpoint_refusals']);
    }
}
