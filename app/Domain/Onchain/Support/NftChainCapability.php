<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

use BCC\Trust\Onchain\Repositories\ChainCheckpointRepository;
use BCC\Trust\Onchain\Repositories\ChainNftCapabilityRepository;
use BCC\Trust\Onchain\Repositories\ChainRepository;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE ONE ANSWER TO "MAY AN ADMINISTRATOR START A CHAIN-WIDE NFT COLLECTION
 * DISCOVERY ON THIS CHAIN, AND IF NOT, WHY NOT?"
 *
 * ── WHY THIS CLASS EXISTS ───────────────────────────────────────────────
 * It is the cross-family sibling of {@see CosmwasmScanEligibility}, built on
 * the same discipline and for the same reason. That class's docblock records
 * what happens without it: "scannable" was defined twice — once by the
 * worker that acted on it, once by the panel that displayed it — they were
 * written to agree, and they drifted anyway, twice in one fortnight. A
 * dashboard that disagrees with the worker is worse than no dashboard,
 * because it is believed.
 *
 * So this is again exactly ONE definition, and the admin surface, the job
 * starter and the capability editor will all call it.
 *
 * ── HOW IT DIFFERS FROM CosmwasmScanEligibility ─────────────────────────
 * They answer different questions and both stay:
 *
 *   CosmwasmScanEligibility — "will the CosmWasm scanner walk this COSMOS
 *   chain?" Knows about `BCC_COSMWASM_CHAIN_ALLOWLIST`, the pause state and
 *   the per-chain CosmWasm opt-in. Still gates the supervised CLI.
 *
 *   NftChainCapability (here) — "may an administrator start an NFT
 *   discovery on ANY chain?" Knows about BCC product support, the manual
 *   permission, the driver registry and per-driver provider readiness.
 *
 * The one fact they share — the MEASURED `cw_discovery_state = 'unsupported'`
 * — is read from {@see ChainCheckpointRepository} by both. It is not
 * re-derived here, because a chain's wasm module answering HTTP 501 is a
 * measurement, and measurements get exactly one home.
 *
 * ── AUTHORED, MEASURED, DERIVED — KEPT APART ────────────────────────────
 * Collapsing these into one hand-maintained column would let an operator
 * assert a capability a chain does not have, and the measured 501 would have
 * nowhere to live:
 *
 *   AUTHORED  `wp_bcc_chains.bcc_supports_nft_collections`
 *             — BCC's PRODUCT decision. Never a claim about the blockchain.
 *   AUTHORED  `wp_bcc_chains.manual_collection_discovery_enabled`
 *             — permission to start one. NO CRON READS IT; after the
 *               automatic-discovery retirement there is no cron left that
 *               could.
 *   MEASURED  `wp_bcc_chain_checkpoints.cw_discovery_state`
 *             — observed, never authored.
 *   DERIVED   {@see NftDriverRegistry}   — what the CODE can do.
 *   DERIVED   {@see NftProviderReadiness} — what the CONFIG allows, per
 *             driver, at read time. Never stored.
 *
 * ── EXACTLY ONE VERDICT MEANS YES ───────────────────────────────────────
 * `SCANNABLE`. Every other value — including the one that means "we could
 * not tell" — is a NO. {@see isScannable()} is an identity test against that
 * single value rather than a list of exclusions, so a verdict from a newer
 * build, a typo, an empty string, or a value a partially-populated row never
 * set is NOT scannable. That direction costs a refusal; the other direction
 * costs an operator concluding a chain is covered when it is not.
 */
final class NftChainCapability
{
    // ── THE VERDICTS ────────────────────────────────────────────────────

    /** Nothing is blocking it: an administrator may start a discovery. */
    public const SCANNABLE = 'scannable';

    /**
     * MEASURED CAPABILITY: the chain's wasm module answered with a 501.
     * No operator action can make a wasm module appear, which is why this
     * is named before every reason an operator CAN change.
     */
    public const CHAIN_UNSUPPORTED = 'chain_unsupported';

    /**
     * A column is absent from the projection — a pre-migration install, or
     * a stale pre-migration transient.
     *
     * A SEPARATE value from {@see NO_BCC_SUPPORT} because the two are
     * different facts: one says somebody decided no, the other says nobody
     * has been able to decide anything yet. Both are NOT scannable, and
     * telling somebody they declined something they were never offered
     * sends them looking for a switch that is not there.
     */
    public const UNKNOWN = 'unknown';

    /** PRODUCT DECISION: `bcc_supports_nft_collections = 0`. */
    public const NO_BCC_SUPPORT = 'no_bcc_support';

    /**
     * STRUCTURAL: no driver in this build can enumerate this chain.
     *
     * True for every EVM chain and for Solana, permanently, because no
     * provider offers chain-wide NFT contract enumeration on those families.
     * This is NOT a configuration gap and NOT the same as
     * {@see PROVIDER_UNAVAILABLE}: no amount of Alchemy or Helius
     * credentials can change it. Alchemy enumerates a WALLET's contracts,
     * which is a different question.
     */
    public const NO_ENUMERATION_DRIVER = 'no_enumeration_driver';

    /** OPERATOR PERMISSION: `manual_collection_discovery_enabled = 0`. */
    public const MANUAL_DISABLED = 'manual_disabled';

    /**
     * An enumerating driver EXISTS for this chain, but none of them is
     * currently configured — a missing LCD endpoint, an unkeyed RPC URL, an
     * absent Helius credential.
     *
     * Distinct from {@see NO_ENUMERATION_DRIVER} on purpose, and the
     * distinction is the load-bearing one in this class: "we cannot do this
     * at all" and "we could, once you finish configuring it" send an
     * operator to two completely different places. Fusing them would let a
     * chain look one API key away from something no provider sells.
     */
    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    /**
     * PURE. The verdict for one chain.
     *
     * ── ORDER IS EXPLANATION, NOT LOGIC ─────────────────────────────────
     * Every condition is an AND, so the SET of scannable chains is the same
     * whatever order they are asked in. The order chosen is the one that
     * produces the most useful SENTENCE when the answer is no:
     *
     *   1. CHAIN_UNSUPPORTED       nothing anyone does can change it
     *   2. UNKNOWN                 nobody can even read the setting yet
     *   3. NO_BCC_SUPPORT          a product decision, not a technical one
     *   4. NO_ENUMERATION_DRIVER   the code cannot, on any configuration
     *   5. MANUAL_DISABLED         a permission, one click away
     *   6. PROVIDER_UNAVAILABLE    configuration, and possibly spend
     *   7. SCANNABLE
     *
     * (4) precedes (5) deliberately: telling an operator to flip a
     * permission on a chain nothing can enumerate sends them to a switch
     * that will not help. (5) precedes (6) for the same reason in reverse —
     * a permission the operator controls outright is worth naming before
     * work that may require provisioning a paid network.
     *
     * ── EVERY UNSURE BRANCH RETURNS A REFUSAL ───────────────────────────
     * A null opt-in (column absent from the projection) is a NO, not a
     * skipped filter. An unresolvable chain is a NO. An empty driver list is
     * a NO. There is no branch that falls through to "no restriction" — that
     * fall-through is the fail-OPEN shape this codebase already shipped once.
     *
     * @param string|null $cwDiscoveryState     null = no checkpoint row yet, which is
     *                                          NOT "unsupported": no pass has measured
     *                                          this chain, and refusing an unmeasured
     *                                          chain would be a permanent deadlock
     *                                          dressed up as caution.
     * @param bool|null   $bccSupportsNft       null = the column is absent from the projection
     * @param bool|null   $manualEnabled        null = the column is absent from the projection
     * @param list<string> $enumerationDrivers  ordered, from {@see NftDriverRegistry}
     * @param list<string> $readyEnumerationDrivers subset of the above that
     *                                          {@see NftProviderReadiness} accepts
     * @return string one of the seven verdict constants on this class
     */
    public static function verdict(
        ?string $cwDiscoveryState,
        ?bool $bccSupportsNft,
        ?bool $manualEnabled,
        array $enumerationDrivers,
        array $readyEnumerationDrivers
    ): string {
        if ($cwDiscoveryState === ChainCheckpointRepository::CW_STATE_UNSUPPORTED) {
            return self::CHAIN_UNSUPPORTED;
        }
        if ($bccSupportsNft === null || $manualEnabled === null) {
            return self::UNKNOWN;
        }
        if ($bccSupportsNft === false) {
            return self::NO_BCC_SUPPORT;
        }
        if ($enumerationDrivers === []) {
            return self::NO_ENUMERATION_DRIVER;
        }
        if ($manualEnabled === false) {
            return self::MANUAL_DISABLED;
        }
        if ($readyEnumerationDrivers === []) {
            return self::PROVIDER_UNAVAILABLE;
        }

        return self::SCANNABLE;
    }

    /**
     * PURE. Is this verdict the one that permits starting a discovery?
     *
     * Identity test against ONE value, never a list of exclusions — see the
     * class docblock for why that direction is the safe one.
     */
    public static function isScannable(string $verdict): bool
    {
        return $verdict === self::SCANNABLE;
    }

    /**
     * Composed entry point: resolve every input for one chain and return the
     * verdict.
     *
     * NOTHING IN PRODUCTION CALLS THIS YET — PR 2 is a scaffold. It exists
     * so the admin surface and the job starter that land later share this
     * resolution instead of each assembling their own, which is precisely
     * how the two-definitions drift starts.
     *
     * An unresolvable chain returns {@see UNKNOWN}: a chain we cannot read
     * is one we cannot make any claim about, and `UNKNOWN` is not scannable.
     */
    public static function forChainId(int $chainId): string
    {
        $chain = ChainRepository::getById($chainId);
        if ($chain === null) {
            return self::UNKNOWN;
        }

        return self::forChain($chain);
    }

    /**
     * Composed entry point for an already-resolved chain row.
     *
     * Follows the sequence the verdict documents: ask the registry for
     * ordered ENUMERATION drivers FIRST, then evaluate readiness for exactly
     * those drivers — never a chain-wide readiness flag. That ordering is
     * what keeps `NO_ENUMERATION_DRIVER` and `PROVIDER_UNAVAILABLE`
     * distinguishable.
     *
     * @param object $chain a `ChainRow`-shaped projection
     */
    public static function forChain(object $chain): string
    {
        $chainId = (int) ($chain->id ?? 0);

        $overrides  = $chainId > 0 ? ChainNftCapabilityRepository::getForChain($chainId) : [];
        $enumeration = NftDriverRegistry::driversFor($chain, NftDriverRegistry::OP_ENUMERATION, $overrides);
        $ready       = NftProviderReadiness::readyDrivers($chain, $enumeration);

        $checkpoint = $chainId > 0 ? ChainCheckpointRepository::get($chainId) : null;
        $cwState    = $checkpoint !== null && isset($checkpoint->cw_discovery_state)
            ? (string) $checkpoint->cw_discovery_state
            : null;

        return self::verdict(
            $cwState,
            self::bccNftSupportState($chain),
            self::manualDiscoveryState($chain),
            $enumeration,
            $ready
        );
    }

    /**
     * ONE READER, THREE ANSWERS — yes, no, or "this install cannot say".
     *
     * Typed `object` rather than the `ChainRow` shape on purpose, because
     * the honest answer depends on something the shape cannot express:
     * whether the row was projected BEFORE or AFTER the migration that adds
     * the column. A pre-migration row simply has no such property, and
     * reading it would raise a PHP warning and evaluate to null — so the
     * PRESENCE check comes first and answers `null`.
     *
     * The third answer is kept rather than collapsed to `false` here, and
     * collapsed by whoever needs a boolean: {@see verdict()} turns it into
     * {@see UNKNOWN}. Mirrors
     * {@see \BCC\Trust\Onchain\Workers\CosmwasmDiscoveryWorker::discoveryOptInState()},
     * which established this pattern for the CosmWasm opt-in column.
     *
     * @return bool|null null = the projection carries no such property
     */
    public static function bccNftSupportState(object $chain): ?bool
    {
        return self::tinyintState($chain, 'bcc_supports_nft_collections');
    }

    /**
     * The manual-discovery PERMISSION, read with the same three-answer
     * discipline as {@see bccNftSupportState()}.
     *
     * @return bool|null null = the projection carries no such property
     */
    public static function manualDiscoveryState(object $chain): ?bool
    {
        return self::tinyintState($chain, 'manual_collection_discovery_enabled');
    }

    /** @return bool|null null = the projection carries no such property */
    private static function tinyintState(object $chain, string $column): ?bool
    {
        $vars = get_object_vars($chain);
        if (!array_key_exists($column, $vars)) {
            return null;
        }

        return (int) $vars[$column] === 1;
    }
}
