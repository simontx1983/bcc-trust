<?php

namespace BCC\Trust\Onchain\Services;

use BCC\Trust\Onchain\Factories\FetcherFactory;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Fetchers\EvmFetcher;
use BCC\Trust\Onchain\Fetchers\SolanaFetcher;
use BCC\Trust\Onchain\Services\Validation\CosmosContractProbe;
use BCC\Trust\Onchain\Services\Validation\EvmContractProbe;
use BCC\Trust\Onchain\Services\Validation\SolanaContractProbe;
use BCC\Trust\Onchain\Support\NftLaunchChains;
use BCC\Trust\Onchain\Support\ProviderRequestBudget;
use BCC\Trust\Onchain\ValueObjects\ContractValidationVerdict;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validate ONE administrator-submitted contract or mint, on ONE chain.
 *
 * ── THE SHAPE OF THIS SERVICE IS THE PRODUCT INVARIANT ──────────────────
 * It takes a single chain and a single address. It has no list, no cursor, no
 * pagination, no chain-selection logic and no loop over contracts. There is
 * deliberately no `validateMany()` and no `validateChain()`: the absence of a
 * plural entry point is what makes "one submission cannot fan out" a property
 * of the type rather than a rule someone has to remember.
 *
 * It is not the scanner and shares no code with it. The scanner's 13 entry
 * points stay frozen; nothing here unfreezes, restarts or replaces them.
 *
 * ── BOUNDED BY CONSTRUCTION ─────────────────────────────────────────────
 * Every provider call is charged to a {@see ProviderRequestBudget} the caller
 * owns. The per-family ceilings are small and fixed:
 *
 *   Cosmos  ≤3 smart queries (the probe set) + 1 contract-info read
 *   EVM     ≤2 `eth_call`s + ≤1 Alchemy metadata call
 *   Solana  exactly 1 DAS call
 *
 * An exhausted budget is UNAVAILABLE, never a negative verdict.
 *
 * ── NOTHING HAPPENS BECAUSE A PAGE RENDERED ─────────────────────────────
 * This service is only ever reached from an administrator's POST. No admin
 * page render, no cron tick and no REST read calls it. That is asserted by
 * test, because "a page load costs nothing" is the kind of property that
 * decays silently.
 *
 * @phpstan-import-type ChainRow from ChainRepository
 */
final class ContractValidator
{
    /** Cosmos: 3 probe queries + 1 metadata read. */
    public const BUDGET_COSMOS = 4;

    /** EVM: 2 interface calls + 1 metadata call. */
    public const BUDGET_EVM = 3;

    /** Solana: one DAS call. */
    public const BUDGET_SOLANA = 1;

    /** Wall-clock ceiling for one validation, seconds. */
    public const RUNTIME_SECONDS = 20;

    /**
     * Validate one address against one chain.
     *
     * @param ChainRow $chain   a `wp_bcc_chains` row
     * @param string $address   already canonicalised by the caller
     * @param ProviderRequestBudget|null $budget injected by tests; a
     *        family-sized budget is created when omitted
     */
    public function validate(
        object $chain,
        string $address,
        ?ProviderRequestBudget $budget = null
    ): ContractValidationVerdict {
        $family = strtolower((string) ($chain->chain_type ?? ''));

        if ($address === '') {
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_MALFORMED_RESPONSE,
            ]);
        }

        // ── ⚠⚠⚠ THE LAUNCH GATE, BEFORE ANY TRANSPORT ───────────────────
        // DECISION 7 approves Ethereum and Base only. `EvmContractProbe` works
        // on any EVM RPC and `NftDriverRegistry` offers the EVM drivers to
        // every EVM chain, so without this a capability flag on Polygon would
        // put an unapproved chain straight into intake — and on a chain with
        // no Alchemy key it would fail at metadata time, after the provider
        // requests had already been made. Checked here, a non-launch chain
        // costs exactly zero requests.
        //
        // ⚠ EVM ONLY. Cosmos and Solana have their own gates and are not
        // judged by this list.
        if ($family === 'evm' && !NftLaunchChains::isLaunchChain($chain)) {
            return ContractValidationVerdict::unsupported(null, [
                ContractValidationVerdict::EV_FAMILY_UNSUPPORTED,
            ]);
        }

        $budget ??= new ProviderRequestBudget($this->budgetFor($family), self::RUNTIME_SECONDS);

        // ⚠ The factory is asked for a fetcher ONCE, and a family whose
        // fetcher is not the expected class is UNSUPPORTED rather than
        // coerced. A mis-seeded chain row must not reach a probe that will
        // misread its answers.
        try {
            $fetcher = FetcherFactory::make_for_chain($chain);
        } catch (\Throwable $e) {
            return ContractValidationVerdict::unavailable([
                ContractValidationVerdict::EV_PROVIDER_ERROR,
            ]);
        }

        switch ($family) {
            case 'cosmos':
                if (!$fetcher instanceof CosmosFetcher) {
                    return ContractValidationVerdict::unavailable([
                        ContractValidationVerdict::EV_FAMILY_UNSUPPORTED,
                    ]);
                }

                return (new CosmosContractProbe($fetcher))->validate($address, $budget);

            case 'evm':
                if (!$fetcher instanceof EvmFetcher) {
                    return ContractValidationVerdict::unavailable([
                        ContractValidationVerdict::EV_FAMILY_UNSUPPORTED,
                    ]);
                }

                return (new EvmContractProbe($fetcher))->validate($address, $budget);

            // ⚠ unreachable: the launch gate above already refused every EVM
            // chain outside the allowlist. Kept adjacent so the two stay
            // visibly paired.

            case 'solana':
                if (!$fetcher instanceof SolanaFetcher) {
                    return ContractValidationVerdict::unavailable([
                        ContractValidationVerdict::EV_FAMILY_UNSUPPORTED,
                    ]);
                }

                return (new SolanaContractProbe($fetcher))->validate($address, $budget);
        }

        // A family BCC has no validator for. UNAVAILABLE, not INVALID — we
        // did not ask the contract anything, so we have nothing to say about
        // it.
        return ContractValidationVerdict::unavailable([
            ContractValidationVerdict::EV_FAMILY_UNSUPPORTED,
        ]);
    }

    private function budgetFor(string $family): int
    {
        return match ($family) {
            'cosmos' => self::BUDGET_COSMOS,
            'evm'    => self::BUDGET_EVM,
            'solana' => self::BUDGET_SOLANA,
            default  => 1,
        };
    }
}
