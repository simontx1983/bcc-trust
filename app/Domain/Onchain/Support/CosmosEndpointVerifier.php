<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Support;

use BCC\Trust\Onchain\ValueObjects\CosmosEndpointPolicy;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * IS THIS CHAIN'S ENDPOINT AN APPROVED HOST SERVING THE EXPECTED NETWORK —
 * PROVEN NOW, NOT ASSUMED.
 *
 * ── EVERY ARM REFUSES ───────────────────────────────────────────────────
 * The whole value of this class is that it has no lenient path. Not https,
 * not an approved host, unreachable, unreadable identity, wrong network —
 * each returns `ok = false`. In particular:
 *
 * ⚠ **UNAVAILABLE MUST NOT PASS.** The tempting failure mode is to treat "I
 * could not reach the node" as "probably fine, carry on", which converts the
 * one check standing between us and another chain's data into a no-op
 * exactly when the provider is misbehaving. A verification that cannot be
 * completed is a verification that failed.
 *
 * ── WHY THIS DOES NOT GO THROUGH ApiRetry ───────────────────────────────
 * Verification is a GATE, not work. Routing it through {@see ApiRetry} would
 * retry it three times and charge {@see OnchainCircuitBreaker} four times per
 * attempt, so merely *asking whether we may proceed* could open the breaker
 * and block the traffic it was protecting. It therefore calls
 * {@see \BCC\Core\Http\SafeHttpClient} directly — which still supplies the
 * scheme allowlist, the private/reserved-IP block, DNS pinning and, crucially,
 * `redirection => 0`.
 *
 * ⚠ **REDIRECTS ARE FORBIDDEN, AND ALREADY WERE.** `SafeHttpClient` defaults
 * `redirection` to 0 and this class does not override it, so a 30x is simply
 * a non-200 and fails the check. An endpoint that answers only via a redirect
 * cannot be verified — which is the intended answer, because the host that
 * would ultimately serve the request is not the host we approved.
 *
 * ── THE CACHE STORES SUCCESSES ONLY ─────────────────────────────────────
 * A short positive cache keeps this off the hot path without ever letting a
 * failure masquerade as a pass: nothing is written unless the network matched.
 * The key is scoped to the endpoint FINGERPRINT, so repointing the chain row
 * invalidates the cached proof by construction rather than by remembering to
 * flush it.
 */
final class CosmosEndpointVerifier
{
    public const OK                  = 'ok';
    public const NOT_GOVERNED        = 'not_governed';
    public const MALFORMED_URL       = 'malformed_url';
    public const HOST_NOT_APPROVED   = 'host_not_approved';
    public const UNREACHABLE         = 'unreachable';
    public const IDENTITY_UNREADABLE = 'identity_unreadable';
    public const NETWORK_MISMATCH    = 'network_mismatch';

    /** Matches the chains-cache TTL: long enough to stay off the hot path. */
    private const CACHE_TTL = 300;

    private const NODE_INFO_PATH = '/cosmos/base/tendermint/v1beta1/node_info';

    private const TIMEOUT = 10;

    /**
     * @param  object $chain a `ChainRow`-shaped projection.
     * @return array{ok: bool, reason: string, network: string|null, fingerprint: string|null}
     */
    public static function verify(object $chain, bool $useCache = true): array
    {
        $slug = (string) ($chain->slug ?? '');
        $url  = trim((string) ($chain->rest_url ?? ''));

        if (!CosmosEndpointPolicy::isGoverned($slug)) {
            return self::fail(self::NOT_GOVERNED, null);
        }

        // Normalisation rejects non-https, credentials, queries and traversal.
        $normalized = CosmosEndpointPolicy::normalize($url);
        if ($normalized === null) {
            return self::fail(self::MALFORMED_URL, null);
        }

        if (!CosmosEndpointPolicy::isApproved($slug, $normalized)) {
            return self::fail(self::HOST_NOT_APPROVED, null);
        }

        $expected    = (string) CosmosEndpointPolicy::expectedNetwork($slug);
        $fingerprint = CosmosEndpointPolicy::fingerprint($slug, $normalized);
        $chainId     = (int) ($chain->id ?? 0);

        if ($useCache && $fingerprint !== null && $chainId > 0) {
            $cached = get_transient(self::cacheKey($chainId, $fingerprint));
            if (is_string($cached) && $cached === $expected) {
                return [
                    'ok'          => true,
                    'reason'      => self::OK,
                    'network'     => $expected,
                    'fingerprint' => $fingerprint,
                ];
            }
        }

        $response = \BCC\Core\Http\SafeHttpClient::get(
            $normalized . self::NODE_INFO_PATH,
            ['timeout' => self::TIMEOUT, 'headers' => ['Accept' => 'application/json']]
        );

        if (is_wp_error($response)) {
            return self::fail(self::UNREACHABLE, $fingerprint);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            // Includes every 3xx: with redirects disabled a redirect is simply
            // not a 200, and the host that would have answered is not ours.
            return self::fail(self::UNREACHABLE, $fingerprint);
        }

        $network = self::readNetwork((string) wp_remote_retrieve_body($response));
        if ($network === null) {
            return self::fail(self::IDENTITY_UNREADABLE, $fingerprint);
        }
        if ($network !== $expected) {
            return self::fail(self::NETWORK_MISMATCH, $fingerprint);
        }

        if ($fingerprint !== null && $chainId > 0) {
            // ⚠ The ONLY write, and it happens only here — after an exact
            // match. There is deliberately no branch that caches a failure.
            set_transient(self::cacheKey($chainId, $fingerprint), $network, self::CACHE_TTL);
        }

        return [
            'ok'          => true,
            'reason'      => self::OK,
            'network'     => $network,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * PURE. `default_node_info.network` out of a node_info body, or null.
     *
     * Reads only that one field. A body that is not JSON, not an object, or
     * carries no readable network is UNREADABLE — never "probably right".
     */
    public static function readNetwork(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }

        $info = $decoded['default_node_info'] ?? null;
        if (!is_array($info)) {
            // Older gaia used `node_info`; accept it, still exactly one field.
            $info = $decoded['node_info'] ?? null;
        }
        if (!is_array($info)) {
            return null;
        }

        $network = $info['network'] ?? null;
        if (!is_string($network)) {
            return null;
        }

        $network = trim($network);

        return $network === '' ? null : $network;
    }

    /** Human-facing reason, safe to render. Never echoes provider text. */
    public static function label(string $reason): string
    {
        switch ($reason) {
            case self::OK:                  return 'Endpoint verified';
            case self::NOT_GOVERNED:        return 'No endpoint policy for this chain';
            case self::MALFORMED_URL:       return 'Endpoint URL is not a plain HTTPS address';
            case self::HOST_NOT_APPROVED:   return 'Endpoint host is not on the approved list';
            case self::UNREACHABLE:         return 'Endpoint did not answer the identity check';
            case self::IDENTITY_UNREADABLE: return 'Endpoint answered without a readable network id';
            case self::NETWORK_MISMATCH:    return 'Endpoint reports a different network';
            default:                        return 'Endpoint could not be verified';
        }
    }

    private static function cacheKey(int $chainId, string $fingerprint): string
    {
        return 'bcc_cosmos_ep_ok_' . $chainId . '_' . $fingerprint;
    }

    /**
     * @return array{ok: bool, reason: string, network: string|null, fingerprint: string|null}
     */
    private static function fail(string $reason, ?string $fingerprint): array
    {
        return ['ok' => false, 'reason' => $reason, 'network' => null, 'fingerprint' => $fingerprint];
    }
}
