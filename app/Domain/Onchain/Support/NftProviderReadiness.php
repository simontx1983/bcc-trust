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
 * Solana is the standing proof. On the seeded configuration:
 *
 *   - `magiceden` (CURATED_FEED) is ready — it is a public marketplace API
 *     needing no per-chain credential;
 *   - `das` (WALLET_DISCOVERY / VALIDATION / METADATA / OWNERSHIP) is NOT
 *     ready — the seeded `api.mainnet-beta.solana.com` has no DAS and no
 *     Helius key is configured.
 *
 * A chain-wide boolean would have to pick one of those and be wrong about
 * the other. Worse, it would be wrong in the permissive direction the moment
 * ANY driver was ready, which is exactly how an operator ends up starting a
 * job that cannot make a single successful call.
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

            // Stargaze's marketplace API is externally hosted and takes no
            // per-chain credential, so there is nothing an operator could
            // configure wrongly. Not "always true" as a shortcut — true
            // because the prerequisite set is genuinely empty.
            NftDriverRegistry::DRIVER_STARGAZE_MARKETPLACE => true,

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

            // ── Solana ──────────────────────────────────────────────────
            // Needs a Helius (or other DAS-capable) endpoint AND no observed
            // "method not found" FOR THE ENDPOINT CURRENTLY IN USE. The
            // second half matters: a key can be present and still point at
            // an endpoint that has already told us it cannot serve
            // getAssets*.
            NftDriverRegistry::DRIVER_DAS => HeliusEndpoint::isConfigured()
                && !self::dasMarkApplies($chainId, $rpcUrl),

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
     * The mark records `redactEndpoint($chain->rpc_url)` as observed by
     * `SolanaFetcher::rpcCall()`, so the current endpoint is put through the
     * SAME redaction and compared. A changed endpoint does not inherit the
     * previous one's verdict; an unchanged endpoint keeps its refusal.
     *
     * ── UNATTRIBUTABLE MARKS DO NOT APPLY ───────────────────────────────
     * A non-array option, an empty one, or one carrying no `rpc_url` cannot
     * be tied to an endpoint. Those are treated as NOT applying, for the
     * same reason the malformed case always was: this is a NEGATIVE signal,
     * and an unreadable one must not permanently disable a driver an
     * operator has correctly configured with no way to clear it. The
     * decision is also self-correcting — if the endpoint really is
     * DAS-incapable, the very next call re-writes the mark, this time WITH
     * an endpoint attached.
     *
     * ⚠️ Comparison is on the REDACTED form, so two endpoints differing only
     * in their query string (e.g. the same host with a rotated `?api-key=`)
     * compare EQUAL. That is deliberate and conservative here: a rotated key
     * on a host that has already proven it cannot serve DAS is still not
     * going to serve DAS, and the redaction is what keeps the secret out of
     * the stored option in the first place.
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

        $markedEndpoint = isset($flag['rpc_url']) ? trim((string) $flag['rpc_url']) : '';
        if ($markedEndpoint === '') {
            return false;
        }

        return $markedEndpoint === HeliusEndpoint::redactEndpoint($currentRpcUrl);
    }
}
