<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WHICH COSMOS ENDPOINTS ARE APPROVED, AND WHAT NETWORK EACH MUST BE.
 *
 * ── ONE POLICY, TWO CONSUMERS ───────────────────────────────────────────
 * Before this class the codebase held two disagreeing answers:
 *
 *   - DISCOVERY read `wp_bcc_chains.rest_url` and validated NOTHING. Any
 *     host in that column was contacted, whatever it served.
 *   - WALLET HOLDINGS validated against a hard-coded three-host list in
 *     {@see \BCC\Trust\Core\Services\wallet\BlockchainQueryService} that
 *     never consulted the chain row at all.
 *
 * So an operator could repoint the chain row and have discovery follow it
 * while holdings refused the same host — or vice versa. Both now read THIS
 * class, so the two cannot drift.
 *
 * ── WHY THE EXPECTED NETWORK LIVES HERE AND NOT IN THE DATABASE ─────────
 * `wp_bcc_chains.chain_id_hex` is EMPTY for all nine Cosmos chains, is
 * semantically the EVM hex chain id, and is a DOCUMENTED public API field
 * (see `WalletController` and docs/api-contract-v1.md). Repurposing it to
 * carry `cosmoshub-4` would be an API contract change and a category error.
 *
 * A chain's network id is a PROTOCOL CONSTANT, not operator configuration.
 * Putting it in a column invites drift and a typo that silently disables the
 * only check standing between us and another chain's data. It lives in code,
 * reviewed, beside the hosts approved to serve it — the same reasoning that
 * keeps {@see NftDriverRegistry} code-owned.
 *
 * ⚠ `cosmoshub-4` also appears in bcc-core's `CosmosSignatureVerifier` and
 * `WalletVerifier` as the ADR-036 signing default. Those make no HTTP
 * request and are not endpoint policy, but the literal must not drift —
 * `CosmosEndpointPolicyTest` pins it byte-for-byte.
 *
 * ── GOVERNED vs UNGOVERNED ──────────────────────────────────────────────
 * Only slugs with an entry here are governed. Nine Cosmos chains share this
 * fetcher; shipping a policy for all of them would require verifying eight
 * more network ids against live nodes, and getting one wrong would refuse a
 * working chain. PR 7.9 therefore governs `cosmos` alone and leaves the rest
 * on exactly their previous behaviour. Extending the map is the mechanism
 * for extending the guarantee — deliberately additive, never a silent
 * widening.
 *
 * ── WHY THE INCUMBENT IS APPROVED ───────────────────────────────────────
 * `rest.cosmos.directory/cosmoshub` is what chain 8 is RUNNING right now. A
 * policy that approved only the intended successor would make the live
 * endpoint unapproved the moment this merged, and {@see CosmosFetcher} would
 * refuse to construct — an outage caused by a safety feature. The incumbent
 * is approved and stays approved until an administrator performs the audited
 * switch and someone removes it in a later PR.
 *
 * ⚠ PublicNode (`cosmos-rest.publicnode.com`) is deliberately ABSENT. It was
 * the leading candidate on six hours of reliability data (200/200 requests,
 * median 179 ms) and is nonetheless unusable here: it systematically
 * refuses `/cosmos/staking/v1beta1/validators/{valoper}/delegations` with
 * HTTP 503 and `{"error":"Please use your own full node."}`. That route
 * feeds `delegator_count` for every Cosmos validator, and a 503 costs FOUR
 * breaker charges against a threshold of five — so pinning it would corrupt
 * validator cards with zeros AND open the breaker after two validators.
 * Measured 2026-09-10 over six attempts, two validators, with and without
 * `pagination.count_total`. Reliability is not the same question as route
 * coverage, and this class records the answer to the second one.
 */
final class CosmosEndpointPolicy
{
    /** The endpoint an administrator should move to. */
    public const ROLE_PRIMARY = 'primary';

    /** Approved, operator-selectable, never chosen automatically. */
    public const ROLE_ALTERNATIVE = 'alternative';

    /** What the chain is running today; approved so merging is not an outage. */
    public const ROLE_INCUMBENT = 'incumbent';

    /**
     * slug => [network => <chain id>, endpoints => [<normalized url> => role]]
     *
     * Keys MUST already be normalized ({@see normalize()}): lowercase https
     * scheme and host, no explicit :443, no trailing slash, no query.
     *
     * @var array<string, array{network: string, endpoints: array<string, string>}>
     */
    private const POLICY = [
        'cosmos' => [
            'network'   => 'cosmoshub-4',
            'endpoints' => [
                'https://cosmos-api.polkachu.com'         => self::ROLE_PRIMARY,
                'https://rest.cosmos.directory/cosmoshub' => self::ROLE_INCUMBENT,
            ],
        ],
    ];

    /** Is this chain subject to endpoint policy at all? */
    public static function isGoverned(string $slug): bool
    {
        return isset(self::POLICY[$slug]);
    }

    /** @return list<string> every governed slug, for tests and admin display. */
    public static function governedSlugs(): array
    {
        return array_keys(self::POLICY);
    }

    /** The exact `default_node_info.network` a governed chain must report. */
    public static function expectedNetwork(string $slug): ?string
    {
        return self::POLICY[$slug]['network'] ?? null;
    }

    /**
     * @return array<string, string> normalized url => role, empty when ungoverned.
     */
    public static function approvedEndpoints(string $slug): array
    {
        return self::POLICY[$slug]['endpoints'] ?? [];
    }

    /** The role an approved url plays, or null when it is not approved. */
    public static function roleFor(string $slug, string $url): ?string
    {
        $normalized = self::normalize($url);
        if ($normalized === null) {
            return null;
        }

        return self::approvedEndpoints($slug)[$normalized] ?? null;
    }

    /**
     * EXACT match against the approved set. Never a prefix or suffix test:
     * `rest.cosmos.directory.evil.test` must not pass because it ends with an
     * approved host, and `https://host/cosmoshub/../osmosis` must not pass
     * because it begins with an approved path.
     */
    public static function isApproved(string $slug, string $url): bool
    {
        return self::roleFor($slug, $url) !== null;
    }

    /**
     * Every approved HOST across every governed chain, lowercased.
     *
     * Exists so the wallet-holdings validator can admit exactly what
     * discovery may contact. Host-only by necessity — that validator is
     * chain-agnostic and receives a bare URL, so it cannot know which slug's
     * path rules to apply. That is a deliberate weakening at that one call
     * site and NOT a substitute for {@see isApproved()}, which is what the
     * discovery path uses and which compares the full identity.
     *
     * @return list<string>
     */
    public static function approvedHosts(): array
    {
        $hosts = [];
        foreach (self::POLICY as $entry) {
            foreach (array_keys($entry['endpoints']) as $url) {
                $host = parse_url($url, PHP_URL_HOST);
                if (is_string($host) && $host !== '') {
                    $hosts[strtolower($host)] = true;
                }
            }
        }

        return array_keys($hosts);
    }

    /** The endpoint an administrator should be steered towards. */
    public static function primaryFor(string $slug): ?string
    {
        foreach (self::approvedEndpoints($slug) as $url => $role) {
            if ($role === self::ROLE_PRIMARY) {
                return $url;
            }
        }

        return null;
    }

    /**
     * PURE. Canonical string form of an endpoint identity, or null if the URL
     * cannot be one.
     *
     * ⚠ HOSTNAME ALONE IS NOT AN IDENTITY. `rest.cosmos.directory/cosmoshub`
     * and `rest.cosmos.directory/osmosis` are DIFFERENT CHAINS on one host,
     * and a path-scoped provider such as `rest.lavenderfive.com/cosmoshub`
     * collides the same way. Scheme, host, effective port and base path are
     * all load-bearing.
     *
     * Rejects (returns null) anything that is not a plain https origin:
     * non-https schemes, missing host, embedded credentials, and any query or
     * fragment — a policy key with a query string would be a different
     * request than the one we approved.
     */
    public static function normalize(string $url): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '') {
            return null;
        }

        // ⚠ NATIVE parse_url, NOT WordPress's wrapper. This value object is
        // built on the CosmosFetcher hot path, so depending on a WP function
        // put a shim requirement into every suite that constructs a fetcher
        // — fifteen of them broke at once. A pure value object should not
        // need WordPress loaded to answer a question about a URL string, and
        // the wrapper delegates to exactly this call for absolute URLs.
        $parts = parse_url($trimmed);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host   = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            return null;
        }
        // Credentials in a provider URL are never legitimate here and would
        // also smuggle a secret into a fingerprint and an audit row.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : 443;
        if ($port < 1 || $port > 65535) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        $path = rtrim($path, '/');
        // Reject traversal outright rather than trying to resolve it.
        if (strpos($path, '..') !== false) {
            return null;
        }
        if ($path !== '' && $path[0] !== '/') {
            return null;
        }

        return 'https://' . $host . ($port === 443 ? '' : ':' . $port) . $path;
    }

    /** The effective port an endpoint is contacted on (443 unless explicit). */
    public static function effectivePort(string $url): ?int
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        return isset($parts['port']) ? (int) $parts['port'] : 443;
    }

    /**
     * PURE. The COMPLETE endpoint identity, hashed.
     *
     * Covers scheme, host, effective port, base path AND the expected
     * network, so that:
     *
     *   - two chains sharing a host (`/cosmoshub` vs `/osmosis`) cannot
     *     collide, and
     *   - if the expected network for a slug is ever corrected, every record
     *     minted under the old expectation is recognisably foreign.
     *
     * Returned truncated to 16 hex characters: this is a change-detector, not
     * a secret, and it is stored inside breaker state that other code reads.
     */
    public static function fingerprint(string $slug, string $url): ?string
    {
        $normalized = self::normalize($url);
        $network    = self::expectedNetwork($slug);
        if ($normalized === null || $network === null) {
            return null;
        }

        return substr(hash('sha256', $normalized . '|' . $network), 0, 16);
    }

    /** Length of a fingerprint, for the breaker's read-side validation. */
    public static function fingerprintLength(): int
    {
        return 16;
    }

    /** Is this string shaped like one of our fingerprints? */
    public static function isFingerprint(string $candidate): bool
    {
        return (bool) preg_match('/^[0-9a-f]{16}$/', $candidate);
    }
}
