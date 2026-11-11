<?php

namespace BCC\Trust\Onchain\Services\Validation;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Services\CosmwasmClassifier;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\ContractValidationVerdict;
use BCC\Trust\Onchain\ValueObjects\IntakeMetadata;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validate ONE submitted CosmWasm contract address as CW-721 / SG721.
 *
 * ── WHAT THIS IS NOT ────────────────────────────────────────────────────
 * It is not the scanner. It never lists code ids, never enumerates a code
 * family's contracts, never walks a cursor and never touches a watermark. It
 * receives one address an administrator typed and asks that one address about
 * itself. The scanner's enumeration surface is frozen and stays frozen.
 *
 * ── BOUNDED COST ────────────────────────────────────────────────────────
 * `probeCw721()` sends `num_tokens`, then `contract_info`, then (only if
 * `contract_info` yielded nothing) `get_collection_info_and_extension` — so
 * at most three smart queries. `fetchContractInfo()` parses the SAME
 * contract-info shape for metadata and is called once. Every call is charged
 * against the caller's budget, and a exhausted budget is UNAVAILABLE, never a
 * negative verdict.
 *
 * ── ⚠⚠⚠ 501 / NO WASM MODULE IS NEVER "NOT AN NFT" ──────────────────────
 * A chain that answers `/cosmwasm/wasm/v1/code` with HTTP 501 has no wasm
 * module at all. That is a fact about the CHAIN, and the contract was never
 * asked anything. Recording it as `not_cw721` would publish a negative
 * authenticity verdict about a collection nobody examined — and it is exactly
 * the conflation `cw_discovery_state = 'unsupported'` was invented to avoid.
 * It resolves to UNAVAILABLE + `chain_has_no_wasm`.
 *
 * {@see \BCC\Trust\Tests\Unit\CosmosNoWasmModuleIsNeverNotAnNftTest} is the
 * named evidence gate for that behaviour. It must pass before anything removes
 * the `cw_discovery_state` dependency.
 */
final class CosmosContractProbe
{
    /** Smart queries `probeCw721()` may send, worst case. */
    private const PROBE_REQUEST_COST = 3;

    /** The single metadata read. */
    private const METADATA_REQUEST_COST = 1;

    public function __construct(private CosmosFetcher $fetcher)
    {
    }

    /**
     * @param string $contract a canonicalised bech32 address
     */
    public function validate(string $contract, ProviderRequestBudget $budget): ContractValidationVerdict
    {
        if (!$budget->canSpend(self::PROBE_REQUEST_COST)) {
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_BUDGET_EXHAUSTED,
            ]);
        }

        try {
            $outcomes = $this->fetcher->probeCw721($contract);
        } catch (\Throwable $e) {
            // A throw is an absence of evidence, never evidence of absence.
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_PROVIDER_ERROR,
            ]);
        }
        $budget->spend(self::PROBE_REQUEST_COST);

        if ($outcomes === []) {
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_PROVIDER_ERROR,
            ]);
        }

        // ⚠ Checked BEFORE the classifier. The classifier reasons about a
        // contract's answers; a chain with no wasm module produced none, and
        // handing it a set of failures would let it reach `not_cw721`.
        //
        // The HTTP status is asked FIRST, because it is the only operand that
        // is a fact rather than a wording. The outcome-shape check behind it
        // is kept as a fallback for a gateway that says "not implemented"
        // under some other status — it is weaker, not wrong.
        if ($this->fetcher->chainHasNoWasmFor($contract)
            || $this->anyProbeSaysChainHasNoWasm($outcomes)
        ) {
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_CHAIN_HAS_NO_WASM,
            ]);
        }

        $verdict = CosmwasmClassifier::classify($outcomes);
        $class   = (string) ($verdict['classification'] ?? CosmwasmClassifier::INCONCLUSIVE);

        // INCONCLUSIVE and UNREACHABLE are both "we could not tell" — the
        // first from a mixed answer set, the second from transport. Neither is
        // a statement about the contract, so both fail closed.
        if ($class === CosmwasmClassifier::INCONCLUSIVE || $class === CosmwasmClassifier::UNREACHABLE) {
            return ContractValidationVerdict::unavailable(
                $this->transportEvidence($outcomes)
            );
        }

        if ($class === CosmwasmClassifier::NOT_CW721) {
            // A DECIDED negative: the contract answered, and its answers are
            // not CW-721 shaped.
            return ContractValidationVerdict::invalid([
                ContractValidationVerdict::EV_PROBE_ANSWERED,
                ContractValidationVerdict::EV_INTERFACE_DENIED,
            ]);
        }

        // ── ⚠⚠ PROBABLE IS NOT PROOF ────────────────────────────────────
        // The classifier distinguishes `confirmed_cw721` from
        // `probable_cw721` and describes the latter as needing administrator
        // review: evidence that leans one way, not a decision. This probe used
        // to map both to VALID, and VALID is the single verdict that writes a
        // collection row — so "probably" was quietly creating collections.
        //
        // Under the four-state contract only CONFIRMED evidence validates.
        // Probable evidence is UNAVAILABLE: BCC reached no conclusion, the
        // operator can retry, and the bounded evidence says the probes did
        // not all answer.
        if ($class !== CosmwasmClassifier::CONFIRMED) {
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_PROBE_ANSWERED,
                ContractValidationVerdict::EV_PROBE_REFUSED,
            ]);
        }

        $metadata = $this->collectMetadata($contract, $outcomes, $budget);

        return ContractValidationVerdict::valid('CW-721', $metadata, [
            ContractValidationVerdict::EV_PROBE_ANSWERED,
            ContractValidationVerdict::EV_INTERFACE_CONFIRMED,
        ]);
    }

    /**
     * Metadata for a contract already established as CW-721.
     *
     * ⚠ Every field starts UNKNOWN. A field only becomes ABSENT when
     * `fetchContractInfo()` returned a parsed payload that genuinely lacked
     * it — never because the read failed. That is the whole point of
     * {@see IntakeMetadata}.
     *
     * @param list<array{probe: string, ok: bool, kind: string, excerpt: string}> $outcomes
     */
    private function collectMetadata(
        string $contract,
        array $outcomes,
        ProviderRequestBudget $budget
    ): IntakeMetadata {
        $metadata = IntakeMetadata::forFamily('cosmos');

        // ── Supply, from the probe set we already paid for ───────────────
        // `num_tokens` either answered or it did not; there is no third case
        // and no extra request.
        $numTokens = null;
        foreach ($outcomes as $o) {
            if (($o['probe'] ?? '') === CosmwasmClassifier::PROBE_NUM_TOKENS) {
                $numTokens = $o;
                break;
            }
        }
        if (is_array($numTokens) && ($numTokens['ok'] ?? false) === true) {
            // The probe answered. The count itself is not carried on the
            // outcome, so supply stays UNKNOWN unless the fetcher exposes it —
            // an answered probe is not the same as a readable count.
            $count = $this->fetcher->numTokensCountFor($contract);
            if ($count !== null) {
                $metadata = $metadata->withAnswered('total_supply', $count);
            }
        }

        // ── Name / symbol / description / image, one read ─────────────────
        if (!$budget->canSpend(self::METADATA_REQUEST_COST)) {
            return $metadata; // fields stay UNKNOWN; state() reports the shortfall
        }

        try {
            $info = $this->fetcher->fetchContractInfo($contract);
        } catch (\Throwable $e) {
            return $metadata;
        }
        $budget->spend(self::METADATA_REQUEST_COST);

        if (!is_array($info)) {
            // Read failed. Fields stay UNKNOWN — NOT absent.
            return $metadata;
        }

        // The payload parsed, so every field it covers is now ANSWERED:
        // present → KNOWN, missing → ABSENT.
        return $metadata
            ->withAnswered('name', $info['name'] ?? null)
            ->withAnswered('symbol', $info['symbol'] ?? null)
            ->withAnswered('description', $info['description'] ?? null)
            ->withAnswered('image_url', $info['image_url'] ?? null);
    }

    /**
     * FALLBACK ONLY. The authoritative signal is the HTTP status, which
     * {@see \BCC\Trust\Onchain\Fetchers\CosmosFetcher::chainHasNoWasmFor()}
     * carries; this reads what survives in the outcome shape.
     *
     * ⚠ Both arms below are weaker than the status, and the first is
     * unreachable in production: `errorKindFromMessage()` maps every status
     * >= 500 to KIND_NODE_ERROR, so no production path emits
     * `not_implemented` as a kind. It is retained because the excerpt arm
     * depends on the gateway's choice of words, and dropping both would make
     * a 501 detectable only where the status survives.
     *
     * @param list<array{probe: string, ok: bool, kind: string, excerpt: string}> $outcomes
     */
    private function anyProbeSaysChainHasNoWasm(array $outcomes): bool
    {
        foreach ($outcomes as $o) {
            $kind = (string) ($o['kind'] ?? '');
            if ($kind === 'not_implemented') {
                return true;
            }
            // The excerpt is a bounded classifier token, not free provider
            // text — safe to inspect, and it is where a 501 surfaces.
            $excerpt = strtolower((string) ($o['excerpt'] ?? ''));
            if ($excerpt !== '' && str_contains($excerpt, 'not implemented')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Name the transport shape behind an undecidable answer set, for the
     * admin screen. Bounded tokens only.
     *
     * @param list<array{probe: string, ok: bool, kind: string, excerpt: string}> $outcomes
     * @return list<string>
     */
    private function transportEvidence(array $outcomes): array
    {
        foreach ($outcomes as $o) {
            switch ((string) ($o['kind'] ?? '')) {
                case CosmwasmClassifier::KIND_TRANSPORT:
                    return [ContractValidationVerdict::EV_PROVIDER_TIMEOUT];
                case CosmwasmClassifier::KIND_NODE_ERROR:
                    return [ContractValidationVerdict::EV_PROVIDER_ERROR];
                case CosmwasmClassifier::KIND_MALFORMED:
                    return [ContractValidationVerdict::EV_MALFORMED_RESPONSE];
            }
        }

        return [ContractValidationVerdict::EV_PROBE_REFUSED];
    }
}
