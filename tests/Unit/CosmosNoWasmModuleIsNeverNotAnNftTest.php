<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Services\Validation\CosmosContractProbe;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\ContractValidationVerdict;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * ⚠⚠⚠ THE NAMED EVIDENCE GATE FOR DECISION 3.
 *
 * A chain with no wasm module — HTTP 501, or the `not_implemented` error kind
 * — must resolve to **UNAVAILABLE / "could not validate"** and NEVER to
 * "not an NFT".
 *
 * ── WHY THIS TEST IS LOAD-BEARING ───────────────────────────────────────
 * `cw_discovery_state = 'unsupported'` exists to durably record "this chain
 * answered 501, stop asking". DECISION 3 removes that concept in PR H — but
 * only **after** targeted validation proves it fails closed on its own. If the
 * validator reported a 501 chain as `not_cw721`, removing the durable state
 * would turn a measured chain-level fact into a stream of negative
 * authenticity verdicts about individual collections nobody examined.
 *
 * ⚠ DO NOT DELETE OR WEAKEN THIS TEST TO MAKE A LATER PR PASS. It is cited by
 * name in the retirement plan as the gate PR H depends on. If it fails, the
 * `cw_discovery_state` dependency stays.
 */
#[CoversClass(CosmosContractProbe::class)]
final class CosmosNoWasmModuleIsNeverNotAnNftTest extends TestCase
{
    private const CONTRACT = 'cosmos1abcdefghijklmnopqrstuvwxyz0123456789abcd';

    private function budget(): ProviderRequestBudget
    {
        return new ProviderRequestBudget(10, 30);
    }

    /**
     * A fetcher double whose probe set reports the chain has no wasm module.
     */
    private function fetcherAnsweringNotImplemented(): object
    {
        return new class extends FakeCosmosFetcherForValidation {
            public function probeCw721(string $contract): array
            {
                return [
                    [
                        'probe'   => CosmwasmClassifier::PROBE_NUM_TOKENS,
                        'ok'      => false,
                        'kind'    => 'not_implemented',
                        'excerpt' => 'not implemented',
                    ],
                ];
            }
        };
    }

    // ── HTTP 501 is the authoritative signal ──────────────────

    /**
     * THE PRODUCTION SHAPE, AND THE REGRESSION THIS CLOSES.
     *
     * A real 501 reaches the probe as KIND_NODE_ERROR carrying whatever
     * excerpt the gateway chose: errorKindFromMessage() maps every status
     * >= 500 to that kind, and probeKind() discards the status. So before the
     * HTTP side-channel existed this set was indistinguishable from an outage
     * and resolved to provider_error - correct about safety, wrong about why.
     *
     * The excerpt deliberately does NOT contain "not implemented": that is the
     * gateway's wording to choose, and the point is that we no longer depend
     * on it.
     */
    public function testAnHttp501IsNoWasmEvenWhenTheKindAndExcerptDoNotSaySo(): void
    {
        $fetcher = new class extends FakeCosmosFetcherForValidation {
            public function probeCw721(string $contract): array
            {
                $this->chainHasNoWasm = true;   // the status the fetcher observed

                return [
                    ['probe' => CosmwasmClassifier::PROBE_NUM_TOKENS, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_NODE_ERROR, 'excerpt' => 'unknown query path'],
                    ['probe' => CosmwasmClassifier::PROBE_CONTRACT_INFO, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_NODE_ERROR, 'excerpt' => 'unknown query path'],
                    ['probe' => CosmwasmClassifier::PROBE_COLLECTION_INFO, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_NODE_ERROR, 'excerpt' => 'unknown query path'],
                ];
            }
        };

        $verdict = (new CosmosContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $verdict->state());
        self::assertNotSame(ContractValidationVerdict::INVALID, $verdict->state());
        self::assertFalse($verdict->mayPersist());
        self::assertTrue(
            $verdict->hasEvidence(ContractValidationVerdict::EV_CHAIN_HAS_NO_WASM),
            'a 501 must be reported as "this chain has no wasm module", not as an outage'
        );
    }

    /** No metadata read - the chain was never asked about a contract. */
    public function testAnHttp501AttemptsNoMetadataRead(): void
    {
        $fetcher = new class extends FakeCosmosFetcherForValidation {
            public function probeCw721(string $contract): array
            {
                $this->chainHasNoWasm = true;

                return [
                    ['probe' => CosmwasmClassifier::PROBE_NUM_TOKENS, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_NODE_ERROR, 'excerpt' => ''],
                ];
            }
        };

        (new CosmosContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(0, $fetcher->contractInfoCalls);
    }

    /**
     * The probe must not INVENT a no-wasm verdict when the fetcher reports no
     * 501: an outage stays a provider error.
     *
     * ⚠ This pins the probe CONSUMING the signal, not the 501 threshold
     * itself — the fake sets the flag directly, so widening the fetcher to
     * >= 500 would not fail here. That threshold is pinned at the wire, by
     * CosmosFetcherWasmWireTest::testAnOrdinaryBadGatewayDoesNotMarkTheChain.
     */
    public function testAnOrdinaryBadGatewayIsAProviderErrorNotNoWasm(): void
    {
        $fetcher = new class extends FakeCosmosFetcherForValidation {
            public function probeCw721(string $contract): array
            {
                // chainHasNoWasm stays false: the fetcher saw 502, not 501.
                return [
                    ['probe' => CosmwasmClassifier::PROBE_NUM_TOKENS, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_NODE_ERROR, 'excerpt' => 'bad gateway'],
                    ['probe' => CosmwasmClassifier::PROBE_CONTRACT_INFO, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_NODE_ERROR, 'excerpt' => 'bad gateway'],
                    ['probe' => CosmwasmClassifier::PROBE_COLLECTION_INFO, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_NODE_ERROR, 'excerpt' => 'bad gateway'],
                ];
            }
        };

        $verdict = (new CosmosContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $verdict->state());
        self::assertFalse(
            $verdict->hasEvidence(ContractValidationVerdict::EV_CHAIN_HAS_NO_WASM),
            'an outage is not a statement about the chain'
        );
        self::assertTrue($verdict->hasEvidence(ContractValidationVerdict::EV_PROVIDER_ERROR));
    }

    /** The status outranks a decisive contract refusal, as the kind arm already does. */
    public function testTheStatusWinsOverADecisiveContractRefusal(): void
    {
        $fetcher = new class extends FakeCosmosFetcherForValidation {
            public function probeCw721(string $contract): array
            {
                $this->chainHasNoWasm = true;

                return [
                    ['probe' => CosmwasmClassifier::PROBE_NUM_TOKENS, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, 'excerpt' => 'unknown variant'],
                    ['probe' => CosmwasmClassifier::PROBE_CONTRACT_INFO, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, 'excerpt' => 'unknown variant'],
                    ['probe' => CosmwasmClassifier::PROBE_COLLECTION_INFO, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, 'excerpt' => 'unknown variant'],
                ];
            }
        };

        $verdict = (new CosmosContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $verdict->state());
        self::assertTrue($verdict->hasEvidence(ContractValidationVerdict::EV_CHAIN_HAS_NO_WASM));
    }

    // ── The gate ────────────────────────────────────────────────────────

    public function testAChainWithNoWasmModuleIsUnavailableNotInvalid(): void
    {
        $probe   = new CosmosContractProbe($this->fetcherAnsweringNotImplemented());
        $verdict = $probe->validate(self::CONTRACT, $this->budget());

        self::assertSame(
            ContractValidationVerdict::UNAVAILABLE,
            $verdict->state(),
            'a chain with no wasm module tells us nothing about this contract'
        );
        self::assertNotSame(ContractValidationVerdict::INVALID, $verdict->state());
        self::assertFalse($verdict->isDecided(), 'no decision was reached about the contract');
        self::assertFalse($verdict->mayPersist(), 'nothing may be written');
        self::assertTrue(
            $verdict->hasEvidence(ContractValidationVerdict::EV_CHAIN_HAS_NO_WASM),
            'the reason must be nameable, so the operator copy can explain it'
        );
    }

    public function testTheNoWasmCheckRunsBeforeTheClassifier(): void
    {
        // Every probe failed, which on its own could let a classifier reach a
        // settled negative. The 501 signal must win.
        $fetcher = new class extends FakeCosmosFetcherForValidation {
            public function probeCw721(string $contract): array
            {
                return [
                    ['probe' => CosmwasmClassifier::PROBE_NUM_TOKENS, 'ok' => false, 'kind' => 'not_implemented', 'excerpt' => 'not implemented'],
                    ['probe' => CosmwasmClassifier::PROBE_CONTRACT_INFO, 'ok' => false, 'kind' => 'not_implemented', 'excerpt' => 'not implemented'],
                    ['probe' => CosmwasmClassifier::PROBE_COLLECTION_INFO, 'ok' => false, 'kind' => 'not_implemented', 'excerpt' => 'not implemented'],
                ];
            }
        };

        $verdict = (new CosmosContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $verdict->state());
        self::assertTrue($verdict->hasEvidence(ContractValidationVerdict::EV_CHAIN_HAS_NO_WASM));
    }

    public function testNoMetadataReadIsAttemptedForANoWasmChain(): void
    {
        $fetcher = $this->fetcherAnsweringNotImplemented();
        $budget  = $this->budget();

        (new CosmosContractProbe($fetcher))->validate(self::CONTRACT, $budget);

        self::assertSame(
            0,
            $fetcher->contractInfoCalls,
            'asking a chain with no wasm module for metadata is a guaranteed-wasted request'
        );
    }

    // ── The contrast cases: these MUST still be able to decide ──────────

    public function testAContractThatAnswersAndIsNotCw721IsInvalid(): void
    {
        $fetcher = new class extends FakeCosmosFetcherForValidation {
            public function probeCw721(string $contract): array
            {
                // Answered, and the answers are a settled negative: the
                // classifier's `not_cw721` shape.
                return [
                    ['probe' => CosmwasmClassifier::PROBE_NUM_TOKENS, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, 'excerpt' => 'unknown variant'],
                    ['probe' => CosmwasmClassifier::PROBE_CONTRACT_INFO, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, 'excerpt' => 'unknown variant'],
                    ['probe' => CosmwasmClassifier::PROBE_COLLECTION_INFO, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, 'excerpt' => 'unknown variant'],
                ];
            }
        };

        $verdict = (new CosmosContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertTrue(
            $verdict->isDecided(),
            'a contract that refuses every CW-721 query HAS answered — this must stay decidable'
        );
        self::assertSame(ContractValidationVerdict::INVALID, $verdict->state());
    }

    public function testATransportFailureIsUnavailableNotInvalid(): void
    {
        $fetcher = new class extends FakeCosmosFetcherForValidation {
            public function probeCw721(string $contract): array
            {
                return [
                    ['probe' => CosmwasmClassifier::PROBE_NUM_TOKENS, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_TRANSPORT, 'excerpt' => 'timeout'],
                    ['probe' => CosmwasmClassifier::PROBE_CONTRACT_INFO, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_TRANSPORT, 'excerpt' => 'timeout'],
                    ['probe' => CosmwasmClassifier::PROBE_COLLECTION_INFO, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_TRANSPORT, 'excerpt' => 'timeout'],
                ];
            }
        };

        $verdict = (new CosmosContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $verdict->state());
        self::assertFalse($verdict->mayPersist());
    }

    public function testAThrownFetcherIsUnavailableNotInvalid(): void
    {
        $fetcher = new class extends FakeCosmosFetcherForValidation {
            public function probeCw721(string $contract): array
            {
                throw new \RuntimeException('connection reset');
            }
        };

        $verdict = (new CosmosContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $verdict->state());
        self::assertFalse($verdict->isDecided());
    }

    public function testAnEmptyProbeSetIsUnavailableNotInvalid(): void
    {
        $fetcher = new class extends FakeCosmosFetcherForValidation {
            public function probeCw721(string $contract): array
            {
                return [];
            }
        };

        $verdict = (new CosmosContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $verdict->state());
    }

    /**
     * ⚠⚠ A PROBABLE CLASSIFICATION IS NOT A VALIDATION.
     *
     * `CosmwasmClassifier` distinguishes `confirmed_cw721` from
     * `probable_cw721`, and describes the latter as needing administrator
     * review — it is evidence that leans one way, not proof. Under the
     * four-state contract only CONFIRMED evidence may produce VALID, because
     * VALID is the one verdict that writes a row.
     *
     * Probable evidence resolves to UNAVAILABLE: BCC did not reach a decision
     * about the contract, and the bounded evidence says why.
     */
    public function testProbableOnlyEvidenceDoesNotValidateAndCreatesNothing(): void
    {
        // `num_tokens` answered but neither info variant did — the classifier's
        // documented "probable" shape.
        $fetcher = new class extends FakeCosmosFetcherForValidation {
            public function probeCw721(string $contract): array
            {
                return [
                    ['probe' => CosmwasmClassifier::PROBE_NUM_TOKENS, 'ok' => true, 'kind' => 'none', 'excerpt' => ''],
                    ['probe' => CosmwasmClassifier::PROBE_CONTRACT_INFO, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, 'excerpt' => 'unknown variant'],
                    ['probe' => CosmwasmClassifier::PROBE_COLLECTION_INFO, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, 'excerpt' => 'unknown variant'],
                ];
            }
        };

        $verdict = (new CosmosContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertNotSame(
            ContractValidationVerdict::VALID,
            $verdict->state(),
            'probable is not proof, and VALID is the verdict that writes a row'
        );
        self::assertFalse($verdict->mayPersist(), 'no collection may be created from probable evidence');
        self::assertTrue(
            $verdict->hasEvidence(ContractValidationVerdict::EV_PROBE_REFUSED),
            'the bounded evidence must explain why validation was incomplete'
        );
    }

    public function testProbableEvidenceDoesNotEvenAttemptAMetadataRead(): void
    {
        $fetcher = new class extends FakeCosmosFetcherForValidation {
            public function probeCw721(string $contract): array
            {
                return [
                    ['probe' => CosmwasmClassifier::PROBE_NUM_TOKENS, 'ok' => true, 'kind' => 'none', 'excerpt' => ''],
                    ['probe' => CosmwasmClassifier::PROBE_CONTRACT_INFO, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, 'excerpt' => 'unknown variant'],
                    ['probe' => CosmwasmClassifier::PROBE_COLLECTION_INFO, 'ok' => false, 'kind' => CosmwasmClassifier::KIND_QUERY_UNSUPPORTED, 'excerpt' => 'unknown variant'],
                ];
            }
        };

        (new CosmosContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(0, $fetcher->contractInfoCalls, 'nothing is persisted, so nothing needs fetching');
    }

    public function testConfirmedEvidenceStillValidates(): void
    {
        $fetcher = new class extends FakeCosmosFetcherForValidation {
            public function probeCw721(string $contract): array
            {
                return [
                    ['probe' => CosmwasmClassifier::PROBE_NUM_TOKENS, 'ok' => true, 'kind' => 'none', 'excerpt' => ''],
                    ['probe' => CosmwasmClassifier::PROBE_CONTRACT_INFO, 'ok' => true, 'kind' => 'none', 'excerpt' => ''],
                ];
            }
        };
        $fetcher->contractInfo = ['name' => 'Confirmed Collection', 'symbol' => 'CC', 'description' => null, 'image_url' => null];

        $verdict = (new CosmosContractProbe($fetcher))->validate(self::CONTRACT, $this->budget());

        self::assertSame(ContractValidationVerdict::VALID, $verdict->state());
        self::assertSame('CW-721', $verdict->standard());
    }

    public function testAnExhaustedBudgetIsUnavailableAndMakesNoRequest(): void
    {
        $fetcher = new class extends FakeCosmosFetcherForValidation {
            public int $probeCalls = 0;

            public function probeCw721(string $contract): array
            {
                $this->probeCalls++;
                return [];
            }
        };

        // Not enough for the 3-query probe set.
        $verdict = (new CosmosContractProbe($fetcher))->validate(self::CONTRACT, new ProviderRequestBudget(1, 30));

        self::assertSame(ContractValidationVerdict::UNAVAILABLE, $verdict->state());
        self::assertTrue($verdict->hasEvidence(ContractValidationVerdict::EV_BUDGET_EXHAUSTED));
        self::assertSame(0, $fetcher->probeCalls, 'an exhausted budget must not reach the provider');
    }
}

/**
 * Base double. Overriding only what a case needs keeps each test's intent
 * visible instead of burying it in setup.
 */
class FakeCosmosFetcherForValidation extends \BCC\Trust\Onchain\Fetchers\CosmosFetcher
{
    public int $contractInfoCalls = 0;

    /** @var array{name: ?string, symbol: ?string, description: ?string, image_url: ?string}|null */
    public ?array $contractInfo = null;

    public ?int $numTokens = null;

    /**
     * Mirrors the real fetcher's HTTP-501 side-channel. Set true to stand in
     * for a chain whose wasm endpoints answer 501.
     */
    public bool $chainHasNoWasm = false;

    public function __construct()
    {
        // Deliberately does NOT call parent::__construct(): these tests never
        // touch transport, and a real constructor would demand a chain row.
    }

    public function probeCw721(string $contract): array
    {
        return [];
    }

    public function fetchContractInfo(string $contract, ?callable $authorizeRequest = null): ?array
    {
        $this->contractInfoCalls++;

        return $this->contractInfo;
    }

    public function chainHasNoWasmFor(string $contract): bool
    {
        return $this->chainHasNoWasm;
    }

    public function numTokensCountFor(string $contract): ?int
    {
        return $this->numTokens;
    }
}
