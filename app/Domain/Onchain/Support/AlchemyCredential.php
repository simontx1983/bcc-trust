<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * THE ONE SECURE SOURCE OF THE ALCHEMY CREDENTIAL.
 *
 * ── ⚠⚠⚠ WHY THIS EXISTS ─────────────────────────────────────────────────
 * The Alchemy API key lived in two places at once:
 *
 *   1. `BCC_ALCHEMY_API_KEY` in `wp-config.php` — outside the database, outside
 *      version control, not in a backup of `wp_bcc_chains`, used by
 *      {@see \BCC\Trust\Core\Services\Wallet\BlockchainQueryService}
 *   2. a COMPLETE CREDENTIALED URL in the `bcc_chains.rpc_url` column, used by
 *      {@see \BCC\Trust\Onchain\Fetchers\EvmFetcher} and
 *      {@see \BCC\Trust\Onchain\Workers\NftEthIndexerWorker}
 *
 * (2) is the problem. A credential in a table column is in every database
 * backup, every export, every `wp db export`, every read-only MySQL session
 * opened for debugging, and on screen in any admin page that prints the row —
 * which is how a live key was leaked on 2026-09-30. It is also a SECOND COPY:
 * rotating the constant silently leaves the database on the old key, and the
 * two disagreeing is invisible until a call fails.
 *
 * So the credential comes from the constant, and the chain row keeps only the
 * things that are genuinely per-chain configuration.
 *
 * ── ⚠⚠ WHY THE HOST IS NOT TAKEN FROM THE ROW ───────────────────────────
 * The obvious design is to keep the row's keyless template
 * (`https://base-mainnet.g.alchemy.com/v2/`) and inject the credential into it.
 * It is rejected, and the reason is worth stating because the design reads as
 * more flexible:
 *
 * That interpolates a SECRET into a HOST THE DATABASE CONTROLS. Anything that
 * can write one column — a compromised admin session, a bad migration, a
 * restored-from-the-wrong-environment row, a typo — redirects the credential to
 * a host of its choosing, and the request carrying it succeeds in leaking it
 * before anything notices it was the wrong host. {@see AlchemyEndpoint}'s
 * docblock already flagged exactly this hazard for its own regex.
 *
 * So the network host comes from a CLOSED MAP in code, keyed on
 * `chain_id_hex`. The database selects among fixed options and cannot introduce
 * one.
 *
 * ── ⚠⚠⚠ WHY THE MAP IS THE TWO LAUNCH CHAINS AND NOT THE FIVE SEEDED ────
 * `schema-chains.php` seeds FIVE chains with Alchemy templates — Ethereum,
 * Polygon, Arbitrum, Optimism, Base. Putting all five in this map would be a
 * behaviour change disguised as a refactor.
 *
 * {@see NftDriverRegistry::driverSupportsChain()} gates the Alchemy drivers on
 * `chain_type === 'evm'` ALONE. Today Polygon, Arbitrum and Optimism are held
 * back only by their rpc_url being a keyless template, i.e. by
 * {@see AlchemyEndpoint::isConfigured()} returning false. A resolver that built
 * a working URL for them from the constant would flip all three to READY the
 * instant the credential is present — silently launching three chains that
 * DECISION 7 excludes, with no operator action and no migration to point at.
 *
 * So the map holds Ethereum (`0x1`) and Base (`0x2105`), the two launch chains,
 * and adding a third is a deliberate edit here rather than a side effect of
 * configuring a key.
 *
 * ── RESOLUTION ORDER, AND WHY THE ROW STILL WINS ─────────────────────────
 *   1. the row already carries a COMPLETE KEYED Alchemy URL  -> use it
 *   2. else the constant is set AND the chain is in the map  -> build it
 *   3. else                                                  -> null
 *
 * Step 1 is transitional and deliberate. Production and staging rows currently
 * hold real credentials, and an operator may have hand-configured a chain that
 * is not in the map. Removing those rows is a separate, guarded, post-deployment
 * procedure — see `docs/alchemy-credential-cleanup.md` — precisely so that this
 * change cannot break a working install the moment it deploys. Once the rows are
 * cleaned, step 1 stops matching and step 2 carries everything.
 *
 * ⚠ Step 3 returns null and callers must treat that as UNAVAILABLE — a
 * configuration state an operator can fix — and never as INVALID, which is a
 * verdict about the thing being checked. Conflating them is how a missing key
 * becomes a permanent false negative recorded against a contract.
 *
 * ── WHAT NEVER HAPPENS HERE ─────────────────────────────────────────────
 * The resolved URL is returned to a caller that immediately POSTs to it. It is
 * never written to a row, an option, a transient, a cache, an exception or a
 * log. {@see ProviderConfigStatus} is how this is reported, and
 * `scripts/endpoint-exposure-guard.php` fails the build if it reaches an output.
 *
 * @see EndpointDescriptor for describing an endpoint safely
 */
final class AlchemyCredential
{
    /**
     * `chain_id_hex` => Alchemy network subdomain. CLOSED. See the docblock for
     * why this is not the five seeded networks.
     *
     * Keys are lowercase; {@see networkFor()} lowercases before lookup, because
     * `0x2105` and `0x2105` differing only in case must not be two chains.
     *
     * @var array<string, string>
     */
    private const NETWORKS = [
        '0x1'    => 'eth-mainnet',
        '0x2105' => 'base-mainnet',
    ];

    /** The only host suffix a credential may ever be sent to. */
    private const HOST_SUFFIX = '.g.alchemy.com';

    /**
     * Is a usable Alchemy credential configured AT ALL, chain aside?
     *
     * ⚠ A BOOLEAN, and the value is never returned. `defined()` is not enough:
     * `define('BCC_ALCHEMY_API_KEY', '')` — a stripped staging config, a secret
     * that failed to inject, a half-finished provisioning step — is `defined()`
     * and useless. Read as configured it would flip a chain to ready and hand an
     * operator a job that cannot make one successful call.
     */
    public static function isConfigured(): bool
    {
        return self::key() !== null;
    }

    /** Does this build know how to reach Alchemy for this chain at all? */
    public static function supportsChain(object $chain): bool
    {
        return self::networkFor($chain) !== null;
    }

    /**
     * The JSON-RPC endpoint for this chain, or null when none can be resolved.
     *
     * ⚠⚠⚠ THE RETURN VALUE IS A CREDENTIAL. Send it; do not store it, log it,
     * render it, or put it in an exception. Report configuration with
     * {@see ProviderConfigStatus} instead.
     */
    public static function rpcUrlFor(object $chain): ?string
    {
        // 1. A row that already carries a complete keyed endpoint keeps working.
        //    Transitional — see the docblock and the cleanup procedure.
        $row = trim((string) ($chain->rpc_url ?? ''));
        if (AlchemyEndpoint::isConfigured($row)) {
            return $row;
        }

        // 2. Build it from the secure source.
        $network = self::networkFor($chain);
        $key     = self::key();
        if ($network === null || $key === null) {
            return null;
        }

        // ⚠ rawurlencode: the key is interpolated into a URL PATH. A key
        // containing `/`, `?` or `#` would otherwise change the request's shape
        // rather than being carried by it.
        return 'https://' . $network . self::HOST_SUFFIX . '/v2/' . rawurlencode($key);
    }

    /**
     * The endpoint for a STANDARD JSON-RPC call, or null when there is none.
     *
     * ── ⚠⚠⚠ WHY THIS IS SEPARATE FROM rpcUrlFor() ───────────────────────
     * `eth_call`, `eth_blockNumber` and friends are standard and work on any
     * node. Avalanche (`api.avax.network`) and BSC (`bsc-dataseed.binance.org`)
     * are seeded with PUBLIC RPCs and no credential, and they keep ERC-721
     * ownership gating on exactly that basis —
     * {@see NftProviderReadiness::isReady()} gates `DRIVER_EVM_RPC` on a
     * non-empty rpc_url alone, never on Alchemy.
     *
     * Requiring an Alchemy credential for standard JSON-RPC would silently remove
     * token gating from two live chains, and would have broken every existing
     * fixture that points a test chain at a non-Alchemy host. ⚠ A first draft of
     * this change did exactly that and 15 tests caught it.
     *
     * ── ⚠⚠ WHY TRUSTING THE ROW IS SAFE *HERE* ──────────────────────────
     * The rule that shapes {@see rpcUrlFor()} — never interpolate a secret into a
     * host the database controls — does not apply, because NOTHING SECRET IS
     * BEING SENT. This returns the row verbatim; no credential is added to it. A
     * hostile row can misdirect a public `eth_call`, which is a correctness
     * problem for that chain, not a credential disclosure.
     *
     * Alchemy is preferred when resolvable: one endpoint for head polling and the
     * transfer walk means the two cannot disagree across a reorg, which would let
     * a checkpoint advance past a range the transfer endpoint never served.
     */
    public static function jsonRpcUrlFor(object $chain): ?string
    {
        $alchemy = self::rpcUrlFor($chain);
        if ($alchemy !== null) {
            return $alchemy;
        }

        $row = trim((string) ($chain->rpc_url ?? ''));

        // ⚠ A keyless Alchemy TEMPLATE is not a usable public node — it is a
        // guaranteed 401. Reject it rather than firing a request that cannot
        // succeed and then reads as a node failure.
        if ($row === '' || str_ends_with($row, '/v2/')) {
            return null;
        }

        return $row;
    }

    /**
     * The Alchemy NFT API v3 base for this chain, or null.
     *
     * Derived from {@see rpcUrlFor()} through {@see AlchemyEndpoint} so "the
     * fetcher can build an NFT base" and "readiness says Alchemy is configured"
     * stay the same computation rather than two that are written to agree.
     *
     * ⚠⚠⚠ ALSO A CREDENTIAL, and the one whose leak started this. The 2026-09-30
     * incident was this exact value: the `/nft/v3/<KEY>` rewrite printed by a
     * diagnostic whose redactor only knew about `/v2/<KEY>` and query strings.
     */
    public static function nftBaseFor(object $chain): ?string
    {
        $rpc = self::rpcUrlFor($chain);

        return $rpc === null ? null : AlchemyEndpoint::nftBaseFromRpcUrl($rpc);
    }

    /**
     * Configuration status, safe to render, log or return over REST.
     *
     * ⚠ `resolverAvailable` is false when this build has no network mapping for
     * the chain — an unlaunched or non-Alchemy EVM chain. That is a different
     * fact from "no credential", and keeping them apart stops an operator being
     * sent to `wp-config.php` to fix something no credential can fix.
     */
    public static function status(object $chain): ProviderConfigStatus
    {
        if (!self::supportsChain($chain)) {
            // ⚠ A row with a hand-configured keyed endpoint is genuinely usable
            // even off-map, so it must not report as unresolvable.
            $row = trim((string) ($chain->rpc_url ?? ''));
            if (AlchemyEndpoint::isConfigured($row)) {
                return ProviderConfigStatus::describing('alchemy', $row);
            }

            return ProviderConfigStatus::unconfigured('alchemy', false);
        }

        return ProviderConfigStatus::describing('alchemy', self::rpcUrlFor($chain));
    }

    /**
     * The Alchemy network subdomain for a chain, or null when unsupported.
     *
     * Keyed on `chain_id_hex`, per DECISION 7. Not on `slug`, which is operator
     * data and can be renamed, and not on the row's host, which is the whole
     * point of not trusting the row.
     */
    private static function networkFor(object $chain): ?string
    {
        if ((string) ($chain->chain_type ?? '') !== 'evm') {
            return null;
        }

        $hex = strtolower(trim((string) ($chain->chain_id_hex ?? '')));

        return self::NETWORKS[$hex] ?? null;
    }

    /**
     * The credential, or null when not usably configured. PRIVATE, and it stays
     * private — nothing outside this class has a reason to hold it.
     *
     * Trimmed before it is judged usable: a constant holding "  " or a value
     * injected with a trailing newline is non-empty, reads as configured, and
     * produces a request that cannot authenticate. Trimming here also means the
     * key never carries whitespace into an outbound URL.
     *
     * ── ⚠⚠ WHY THE SHAPE IS VALIDATED AND NOT JUST ENCODED ──────────────
     * A key containing a character outside `[A-Za-z0-9_-]` cannot form a valid
     * Alchemy endpoint. {@see AlchemyEndpoint}'s pattern — the shared definition
     * of "is this a keyed Alchemy URL?" — allows only those characters in the key
     * segment, and `rawurlencode()` introduces `%`, which is not among them.
     *
     * So without this check, a malformed key made {@see rpcUrlFor()} return a
     * URL that {@see nftBaseFor()} then rejected: two methods on one class
     * disagreeing about whether the same chain is usable, which is precisely the
     * failure mode {@see AlchemyEndpoint} was extracted to end.
     *
     * Rejecting here makes them agree, and makes the answer fail-closed: a key
     * that cannot address Alchemy is not a credential. `rawurlencode()` at the
     * call site stays as defence in depth — if this validation is ever loosened,
     * the encoding still stops a `/` or `?` from changing a request's shape.
     */
    private static function key(): ?string
    {
        if (!defined('BCC_ALCHEMY_API_KEY')) {
            return null;
        }
        $key = trim((string) constant('BCC_ALCHEMY_API_KEY'));
        if ($key === '') {
            return null;
        }

        return preg_match('~^[A-Za-z0-9_-]+$~', $key) === 1 ? $key : null;
    }
}
