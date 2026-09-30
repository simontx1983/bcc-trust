<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * IS THE PROVIDER BEHIND *THIS SPECIFIC DRIVER* USABLE ON *THIS CHAIN*,
 * RIGHT NOW — DERIVED AT READ TIME, NEVER STORED.
 *
 * ── READINESS IS PER DRIVER, NOT PER CHAIN ──────────────────────────────
 * There is deliberately no `forChain(ChainRow): bool`. A chain routinely
 * carries several drivers with completely different prerequisites, and one
 * ready provider must never make an unrelated driver look ready.
 *
 * Solana is the standing proof, and it is worse than it first looks. On a
 * chain row carrying the public RPC, with `BCC_HELIUS_API_KEY` configured:
 *
 *   - `magiceden` (CURATED_FEED) is ready — a public marketplace API needing
 *     no per-chain credential;
 *   - `das_helius` (METADATA) is ready — `getAsset` goes to the Helius
 *     constants and ignores the chain row entirely;
 *   - `das_rpc` (WALLET_DISCOVERY / OWNERSHIP) is NOT ready — those calls go
 *     through `rpcCall()` to the CHAIN ROW's `rpc_url`, which is the public
 *     endpoint, which does not implement DAS.
 *
 * Three drivers, one chain, one instant, and not one answer covers them.
 *
 * ── "DAS" WAS ONE DRIVER AND HAD TO BECOME TWO ──────────────────────────
 * The last of those was originally folded into a single `das` driver whose
 * readiness read the Helius credential. That produced precisely the defect
 * this model exists to prevent: configure Helius, leave the chain row on the
 * public RPC, and the model reported wallet discovery READY while every
 * `getAssetsByOwner` call went to an endpoint with no DAS and failed.
 *
 * A capability answer must describe the endpoint the OPERATION actually
 * uses. Since the two DAS paths resolve their endpoints differently, they
 * are two drivers, and {@see SolanaEndpoints} resolves both so the
 * description and the call cannot drift apart.
 *
 * So the API is {@see isReady()} — (chain, driverKey) — and
 * {@see readinessMap()} when a caller wants several at once.
 *
 * ── WHY NOTHING IS PERSISTED ────────────────────────────────────────────
 * A stored `provider_ready` column would be stale the moment a key rotated,
 * an endpoint was repointed, or a `wp-config.php` constant changed — and it
 * would be stale in the direction that says "yes" after the answer became
 * "no". The same reasoning already keeps runtime conditions out of the
 * CosmWasm scanner's shared verdict: a panel reporting a transient as a
 * configuration is describing the wrong kind of fact.
 *
 * Every method here reads live configuration on every call. They are cheap
 * — constant reads, a string match, and at most one autoloaded option — and
 * none performs network I/O. Readiness answers "is this CONFIGURED and
 * believed usable", never "did the last call succeed". Liveness belongs to
 * the circuit breaker; observed provider failures belong to the eventual
 * job's structured outcome.
 *
 * ── UNKNOWN DRIVERS ARE NOT READY ───────────────────────────────────────
 * The `default` arm returns `false`. A driver key from a newer build, a
 * typo, or a database row naming something this build never implemented is
 * NOT ready — the direction that costs a refusal rather than a false
 * "everything is configured".
 *
 * @see NftDriverRegistry  which drivers exist at all (code-owned)
 * @see NftChainCapability the verdict that combines both
 */
final class NftProviderReadiness
{
    /**
     * PURE-ish (reads constants + one option; no network).
     *
     * Is the provider this driver depends on configured and usable for this
     * chain?
     *
     * @param object $chain     a `ChainRow`-shaped projection
     * @param string $driverKey one of {@see NftDriverRegistry}'s DRIVER_* keys
     */
    public static function isReady(object $chain, string $driverKey): bool
    {
        // A driver that does not serve this chain is never "ready" for it.
        // Without this, `isReady($solanaChain, DRIVER_EVM_RPC)` would answer
        // yes on the strength of Solana's rpc_url being non-empty.
        if (!NftDriverRegistry::isDriver($driverKey)
            || !NftDriverRegistry::driverSupportsChain($driverKey, $chain)
        ) {
            return false;
        }

        $rpcUrl  = trim((string) ($chain->rpc_url  ?? ''));
        $restUrl = trim((string) ($chain->rest_url ?? ''));
        $chainId = (int) ($chain->id ?? 0);

        return match ($driverKey) {
            // ── Cosmos ──────────────────────────────────────────────────
            // Both speak wasmd over the chain's LCD/REST endpoint. Whether
            // that endpoint actually exposes a wasm module is MEASURED
            // (checkpoint `cw_discovery_state = 'unsupported'`, an observed
            // 501) and folded in by NftChainCapability as CHAIN_UNSUPPORTED
            // — a different kind of fact from "we have somewhere to ask".
            NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
            NftDriverRegistry::DRIVER_CW721_LCD => $restUrl !== '',

            // Talis reads Injective's whitelist contract over the same LCD.
            NftDriverRegistry::DRIVER_TALIS_WHITELIST => $restUrl !== '',

            // ⚠ The Stargaze marketplace driver was REMOVED in PR 7.12 —
            // it is no longer a registry key, so it cannot reach this
            // match. Cosmos wallet discovery has no driver at all now:
            // wasmd has no owner→contracts index, and the marketplace API
            // that papered over that gap was undocumented and took member
            // wallet addresses off-platform.

            // ── EVM ─────────────────────────────────────────────────────
            // Both Alchemy drivers need a KEYED Alchemy endpoint. The
            // seeded URLs (`https://eth-mainnet.g.alchemy.com/v2/`) carry no
            // key and correctly fail; Avalanche and BSC point at public RPCs
            // that never match at all.
            NftDriverRegistry::DRIVER_ALCHEMY_NFT,
            NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS => AlchemyEndpoint::isConfigured($rpcUrl),

            // `eth_call balanceOf` works on any JSON-RPC endpoint, which is
            // why Avalanche and BSC keep ERC-721 gating with no Alchemy key.
            NftDriverRegistry::DRIVER_EVM_RPC => $rpcUrl !== '',

            // ── Solana: TWO DAS paths, TWO answers ──────────────────────
            //
            // `getAssetsByOwner` (wallet discovery, ownership) goes through
            // SolanaFetcher::rpcCall() to the CHAIN ROW's rpc_url. Helius
            // credentials are irrelevant to it — they are not on that call.
            // Reading them here is what let a Helius key report "ready" for
            // a path that was still hitting the public RPC.
            //
            // The endpoint comes from the SHARED resolver, including its
            // public-default fallback, so what we describe is exactly what
            // rpcCall() will POST to.
            NftDriverRegistry::DRIVER_DAS_RPC => SolanaEndpoints::rpcSupportsDas($chain)
                && !self::dasMarkApplies($chainId, SolanaEndpoints::rpcEndpoint($chain)),

            // `getAsset` (metadata) goes to the Helius constants and ignores
            // the chain row entirely. No DAS-unsupported mark is ever
            // written against this path — markDasUnsupported() is reached
            // only from rpcCall() — so there is nothing to re-check.
            NftDriverRegistry::DRIVER_DAS_HELIUS => SolanaEndpoints::metadataEndpoint() !== null,

            // Public marketplace API; no per-chain credential.
            NftDriverRegistry::DRIVER_MAGICEDEN => true,

            default => false,
        };
    }

    /**
     * Readiness for several drivers at once, preserving input order.
     *
     * @param object       $chain
     * @param list<string> $driverKeys
     * @return array<string, bool> driver key => ready
     */
    public static function readinessMap(object $chain, array $driverKeys): array
    {
        $map = [];
        foreach ($driverKeys as $key) {
            $map[$key] = self::isReady($chain, $key);
        }

        return $map;
    }

    /**
     * WHAT A DIAGNOSTIC IS ALLOWED TO KNOW about a driver's provider.
     *
     * ── ⚠⚠⚠ WHY THIS EXISTS RATHER THAN A HELPER AT EACH CALL SITE ──────
     * {@see isReady()} answers a bool, which is correct for gating and useless
     * for an operator staring at a red panel: "not ready" does not say whether a
     * credential is missing, whether the endpoint is a keyless template, or
     * whether there is any resolver for this driver at all.
     *
     * The obvious fix — hand the panel the resolved endpoint and let it print
     * the interesting part — is precisely the defect this whole change exists to
     * close. Every such call site independently decided how much of a URL was
     * safe, and on 2026-09-30 one of them was wrong and leaked a live key.
     *
     * So the endpoint never leaves this method. What leaves is a
     * {@see ProviderConfigStatus}: provider name, configured, hostname,
     * resolver-available, and nothing else. It cannot be asked for a URL.
     *
     * ── ⚠⚠ IT ANSWERS A DIFFERENT QUESTION FROM isReady() ───────────────
     * `configured` is NOT `ready`, and they must not be conflated:
     *
     *   das_rpc   a configured DAS endpoint that has ALREADY answered
     *             "method not found" is configured and NOT ready
     *   evm_rpc   a keyless public RPC is configured for `eth_call` and is
     *             not configured for Alchemy's NFT API on the same row
     *
     * Readiness folds in observed negative signals and per-driver capability;
     * this folds in neither. A panel that wants "can I run this" asks
     * {@see isReady()}; a panel that wants "what did the operator configure"
     * asks here. Presenting this one as readiness would re-open the dead end at
     * {@see dasMarkApplies()}.
     *
     * ── RESOLVER-AVAILABLE ──────────────────────────────────────────────
     * False when there is no code path that could produce an endpoint for this
     * pair: an unknown driver key, or a driver that does not serve this chain
     * type. That is a developer-facing fact, and keeping it separate from
     * `configured` stops an operator being sent to `wp-config.php` to fix
     * something no credential can fix.
     *
     * No network call is made.
     */
    public static function configStatus(object $chain, string $driverKey): ProviderConfigStatus
    {
        if (!NftDriverRegistry::isDriver($driverKey)
            || !NftDriverRegistry::driverSupportsChain($driverKey, $chain)
        ) {
            return ProviderConfigStatus::unconfigured(self::providerName($driverKey), false);
        }

        $rpcUrl  = trim((string) ($chain->rpc_url  ?? ''));
        $restUrl = trim((string) ($chain->rest_url ?? ''));
        $provider = self::providerName($driverKey);

        // ⚠ Each arm resolves the endpoint the DRIVER ACTUALLY USES, through the
        // same resolver the driver calls. A hostname derived from the wrong
        // column is worse than none: it reads as confirmation while naming a
        // host the driver will never contact. That is the exact shape of the
        // original Solana defect, where a configured Helius key was reported for
        // a path still hitting the public RPC.
        return match ($driverKey) {
            NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
            NftDriverRegistry::DRIVER_CW721_LCD,
            NftDriverRegistry::DRIVER_TALIS_WHITELIST
                => ProviderConfigStatus::describing($provider, $restUrl),

            // Alchemy's NFT drivers need a KEYED endpoint, so a keyless seeded
            // template must report unconfigured even though the row is non-empty
            // and `EndpointDescriptor` would happily describe its host.
            NftDriverRegistry::DRIVER_ALCHEMY_NFT,
            NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS
                => AlchemyEndpoint::isConfigured($rpcUrl)
                    ? ProviderConfigStatus::describing($provider, $rpcUrl)
                    : ProviderConfigStatus::unconfigured($provider),

            NftDriverRegistry::DRIVER_EVM_RPC  => ProviderConfigStatus::describing($provider, $rpcUrl),
            NftDriverRegistry::DRIVER_DAS_RPC  => ProviderConfigStatus::describing($provider, SolanaEndpoints::rpcEndpoint($chain)),
            NftDriverRegistry::DRIVER_DAS_HELIUS => ProviderConfigStatus::describing($provider, SolanaEndpoints::metadataEndpoint()),

            // A public API with no per-chain credential. Configured by
            // definition — there is nothing an operator could set — and its host
            // is a constant, not configuration, so there is none to report.
            NftDriverRegistry::DRIVER_MAGICEDEN => ProviderConfigStatus::describing($provider, null),

            // ⚠ A registered driver this method has no arm for. Reported as
            // resolver-unavailable rather than unconfigured: the honest answer is
            // that nothing here knows how to resolve it, and saying
            // "unconfigured" would send an operator to fix a credential.
            default => ProviderConfigStatus::unconfigured($provider, false),
        };
    }

    /**
     * Status for several drivers at once, preserving input order.
     *
     * @param list<string> $driverKeys
     * @return array<string, ProviderConfigStatus> driver key => status
     */
    public static function configStatusMap(object $chain, array $driverKeys): array
    {
        $map = [];
        foreach ($driverKeys as $key) {
            $map[$key] = self::configStatus($chain, $key);
        }

        return $map;
    }

    /**
     * The PROVIDER behind a driver key — a name, from a closed set.
     *
     * Several drivers share one provider (both Alchemy drivers, both DAS
     * drivers on different endpoints), which is the whole reason the provider is
     * named separately from the driver rather than inferred from the key.
     */
    private static function providerName(string $driverKey): string
    {
        return match ($driverKey) {
            NftDriverRegistry::DRIVER_ALCHEMY_NFT,
            NftDriverRegistry::DRIVER_ALCHEMY_TRANSFERS  => 'alchemy',
            NftDriverRegistry::DRIVER_DAS_HELIUS         => 'helius',
            NftDriverRegistry::DRIVER_DAS_RPC            => 'solana-rpc',
            NftDriverRegistry::DRIVER_MAGICEDEN          => 'magiceden',
            NftDriverRegistry::DRIVER_EVM_RPC            => 'evm-rpc',
            NftDriverRegistry::DRIVER_COSMWASM_ENUMERATION,
            NftDriverRegistry::DRIVER_CW721_LCD,
            NftDriverRegistry::DRIVER_TALIS_WHITELIST    => 'cosmos-lcd',
            default                                      => 'unknown',
        };
    }

    /**
     * The subset of `$driverKeys` that are ready, in the order given.
     *
     * This is the shape {@see NftChainCapability} wants: it asks the
     * registry for ordered ENUMERATION drivers, filters them through here,
     * and distinguishes "the list was empty to begin with"
     * (`NO_ENUMERATION_DRIVER`) from "the list emptied here"
     * (`PROVIDER_UNAVAILABLE`).
     *
     * @param object       $chain
     * @param list<string> $driverKeys
     * @return list<string>
     */
    public static function readyDrivers(object $chain, array $driverKeys): array
    {
        $ready = [];
        foreach ($driverKeys as $key) {
            if (self::isReady($chain, $key)) {
                $ready[] = $key;
            }
        }

        return $ready;
    }

    /**
     * The endpoint-bound refusal currently in force for one driver, if any.
     *
     * ── WHY A SURFACE NEEDS THIS AND MUST NOT DERIVE IT ─────────────────
     * {@see isReady()} answers yes/no. An operator staring at a `das_rpc`
     * that reads "not ready" needs the next sentence: is a credential
     * missing, or has this exact endpoint already been OBSERVED refusing
     * DAS? Those are different problems with different fixes, and the
     * second one is invisible without the stored mark.
     *
     * The obvious alternative — have the admin page read the option and
     * compare it itself — would put a second copy of the attribution rule
     * ({@see dasMarkApplies()}) outside the class that owns it, and would
     * be free to disagree with the readiness answer printed beside it. So
     * the question is answered HERE, by the same predicate, and the caller
     * only formats.
     *
     * ── IT IS EVIDENCE, NOT A VERDICT ──────────────────────────────────
     * A non-null return NEVER means "refused" on its own — it means "there
     * is a mark and it attaches to the endpoint currently in use". The
     * refusal itself is still {@see isReady()}'s to state. A caller that
     * treated this as the answer would resurrect exactly the failure the
     * split exists to prevent.
     *
     * ── ⚠⚠⚠ WHAT COMES BACK IS DESCRIPTION ONLY ────────────────────────
     * `endpoint_display` is `scheme://host` and nothing else — see
     * {@see EndpointDescriptor::display()}. The stored comparison token
     * (`endpoint_id`) is deliberately NOT exported: it is a persisted
     * identity, and a caller handed it could render or log it, which is
     * precisely what splitting description from identity exists to prevent.
     *
     * The shape this replaced returned `rpc_url` — a query-only redaction
     * that carried a path-embedded credential straight into admin HTML.
     *
     * `message` is UPSTREAM PROVIDER TEXT and is NOT sanitised here — a
     * caller that displays it must put it through
     * {@see \BCC\Trust\Onchain\Admin\AdminActionSupport::operatorSafeExcerpt()}
     * first.
     *
     * No network call is made.
     *
     * @return array{endpoint_display: string, code: int, message: string, detected_at: int}|null
     *         null when no mark applies — including when the driver is not
     *         endpoint-marked at all
     */
    public static function endpointRefusal(object $chain, string $driverKey): ?array
    {
        // Only `das_rpc` is ever endpoint-marked. `das_helius` resolves
        // through the constants and never carries a mark; saying otherwise
        // would re-fuse the two endpoints the split pulled apart.
        if ($driverKey !== NftDriverRegistry::DRIVER_DAS_RPC) {
            return null;
        }
        if (!NftDriverRegistry::driverSupportsChain($driverKey, $chain)) {
            return null;
        }

        $chainId = (int) ($chain->id ?? 0);
        if ($chainId <= 0) {
            return null;
        }

        // The SAME predicate readiness uses. Asked first, so a mark that no
        // longer attaches to the current endpoint is reported as absent
        // rather than as stale evidence an operator cannot clear.
        if (!self::dasMarkApplies($chainId, SolanaEndpoints::rpcEndpoint($chain))) {
            return null;
        }

        $flag = get_option(HeliusEndpoint::dasUnsupportedOptionKey($chainId), null);
        if (!is_array($flag)) {
            return null;
        }

        // ⚠ `endpoint_id` is NEVER included. Only the description leaves here.
        //
        // The stored display value is put through `display()` AGAIN rather than
        // trusted. It was written by this version of the writer today, but the
        // option is long-lived: it outlives deployments, it is restored from
        // backups, and an administrator can edit it. Re-describing costs one
        // parse and means a hand-edited or rolled-back payload cannot smuggle a
        // path back out through a field whose name promises there isn't one.
        return [
            'endpoint_display' => isset($flag['endpoint_display'])
                ? EndpointDescriptor::display((string) $flag['endpoint_display'])
                : EndpointDescriptor::UNRECOGNISED,
            'code'             => isset($flag['code']) ? (int) $flag['code'] : 0,
            'message'          => isset($flag['message']) ? (string) $flag['message'] : '',
            'detected_at'      => isset($flag['detected_at']) ? (int) $flag['detected_at'] : 0,
        ];
    }

    /**
     * Does a stored "this endpoint has no DAS" mark apply to the endpoint we
     * would use RIGHT NOW?
     *
     * ── A NEGATIVE OBSERVATION BELONGS TO THE ENDPOINT THAT PRODUCED IT ──
     * The mark is written only on an OBSERVED `-32601` / `-32603` from a
     * `getAssets*` call, so its presence is evidence rather than a guess —
     * but it is evidence about ONE endpoint. Treating any stored mark as
     * permanent produced a dead end an operator could not escape:
     *
     *   1. the seeded public RPC answers "method not found"
     *   2. the mark is stored
     *   3. the operator repoints `chains.rpc_url` at a DAS-capable endpoint
     *   4. readiness stays false forever, because nobody ever compared the
     *      mark against the endpoint now in use
     *
     * The mark records the endpoint `SolanaFetcher::rpcCall()` actually
     * POSTed to — the RESOLVED endpoint from
     * {@see SolanaEndpoints::rpcEndpoint()}, not the raw `rpc_url` column. The
     * distinction is load-bearing: `rpcEndpoint()` falls back to the public
     * default when the column is NULL or blank, and such a chain still makes
     * calls. Comparing the raw nullable column would therefore never match the
     * mark on exactly the rows most likely to carry one.
     *
     * So the current endpoint is put through the SAME resolution before
     * comparing. A changed endpoint does not inherit the previous one's
     * verdict; an unchanged endpoint keeps its refusal.
     *
     * ── ⚠⚠⚠ COMPARISON IS ON IDENTITY, NOT ON THE DESCRIPTION ──────────
     * This used to compare two `redactEndpoint()` outputs, and its docblock
     * argued the resulting query-blindness was "deliberate and conservative":
     * a rotated key on a host already proven DAS-incapable would not suddenly
     * serve DAS.
     *
     * That argument does not survive the redaction being FIXED. A deny-by-
     * default description is `scheme://host`, so comparing descriptions makes
     * EVERY endpoint on a host identical — not just a rotated credential, but a
     * different path, a different API version, a different product. Under the
     * new description the old comparison would have silently widened from
     * "same host, different key" to "same host, anything at all", and the
     * dead end at the top of this docblock would have come straight back for
     * any operator whose fix was a path change on the same provider.
     *
     * It is also the wrong claim on its own terms. The two DAS drivers already
     * exist BECAUSE one host serves different things down different paths; a
     * per-host verdict is exactly the conflation `das_rpc`/`das_helius` was
     * split to undo.
     *
     * So identity is its own value — {@see EndpointDescriptor::identity()}, an
     * HMAC over the whole URL — compared with {@see hash_equals()} and never
     * rendered or logged. Hardening the description REQUIRED pulling identity
     * out of it; the two cannot be one field.
     *
     * ── UNATTRIBUTABLE MARKS DO NOT APPLY ───────────────────────────────
     * A non-array option, an empty one, or one carrying no `endpoint_id`
     * cannot be tied to an endpoint. Those are treated as NOT applying, for
     * the same reason the malformed case always was: this is a NEGATIVE
     * signal, and an unreadable one must not permanently disable a driver an
     * operator has correctly configured with no way to clear it. The
     * decision is also self-correcting — if the endpoint really is
     * DAS-incapable, the very next call re-writes the mark, this time WITH
     * an identity attached.
     *
     * ⚠ That covers LEGACY marks too, which stored `rpc_url` and no identity.
     * They are unverifiable, so they are ignored rather than migrated. There is
     * nothing to migrate to: the stored value was already lossy, so the
     * original URL — and therefore its identity — cannot be recovered from it.
     *
     * No network call is made.
     */
    private static function dasMarkApplies(int $chainId, string $currentRpcUrl): bool
    {
        if ($chainId <= 0) {
            return false;
        }

        $flag = get_option(HeliusEndpoint::dasUnsupportedOptionKey($chainId), null);
        if (!is_array($flag) || $flag === []) {
            return false;
        }

        $markedId = isset($flag['endpoint_id']) ? trim((string) $flag['endpoint_id']) : '';
        if ($markedId === '') {
            return false;
        }

        // ⚠ '' when the current endpoint is unusable, which can never match a
        // real identity — `hash_equals()` compares it and loses.
        $currentId = EndpointDescriptor::identity($currentRpcUrl);
        if ($currentId === '') {
            return false;
        }

        return hash_equals($markedId, $currentId);
    }
}
