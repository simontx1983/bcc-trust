<?php

declare(strict_types=1);

/**
 * IPFS-hosted NFT images (F-165).
 *
 * Browsers cannot load `ipfs://`, so an IPFS image needs an HTTP gateway.
 * Two halves, deliberately split:
 *
 * ── STORAGE: gateway-neutral, always valid https ────────────────────────
 * {@see canonicalize()} turns every IPFS form we recognise into ONE canonical
 * public-gateway URL, `https://ipfs.io/ipfs/<cid>[/path]`, and that is what
 * {@see CollectionMetadataRules::sanitizeImageUrl()} stores. A surface that
 * never learns about gateways still renders a working https image, and
 * switching gateways is a config change, not a data migration.
 *
 * ── PRESENTATION: the configured gateway first, ipfs.io as fallback ─────
 * {@see present()} rewrites a canonical URL onto `BCC_IPFS_GATEWAY` and
 * returns the ipfs.io URL beside it. The client loads `url`, falls back to
 * `fallback_url` on error, then to a placeholder (api-contract-v1, F-165).
 *
 * ⚠ NO KEY, EVER. The gateway setting is a BARE HOSTNAME — no scheme, path,
 * port, query or userinfo — so there is nowhere to put a token. A Pinata
 * dedicated gateway is opened to our origins ("Host Origins"), not by key.
 * Anything else falls back to ipfs.io.
 *
 * @package BCC\Trust\Onchain\ValueObjects
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class IpfsImageUrl
{
    /** The public gateway used for storage and as the fallback. */
    public const DEFAULT_GATEWAY = 'ipfs.io';

    private const CANONICAL_PREFIX = 'https://ipfs.io/ipfs/';

    /**
     * Gateways whose `/ipfs/<cid>` path we normalise. An explicit list: an
     * `/ipfs/` path on any other host is just a path, not a gateway.
     * cloudflare-ipfs.com is retired (2024) — listed so its URLs are RESCUED.
     */
    private const PATH_GATEWAYS = [
        'ipfs.io',
        'gateway.ipfs.io',
        'dweb.link',
        'cloudflare-ipfs.com',
        'gateway.pinata.cloud',
        'nftstorage.link',
    ];

    /** `<cid>.ipfs.<host>` subdomain gateways. */
    private const SUBDOMAIN_GATEWAYS = [
        'dweb.link',
        'nftstorage.link',
    ];

    /** Dedicated Pinata gateways: `<name>.mypinata.cloud/ipfs/<cid>`. */
    private const PINATA_DEDICATED_SUFFIX = '.mypinata.cloud';

    /** CIDv0 (base58btc multihash) or CIDv1 (base32, lower case). */
    private const CID_PATTERN = '/^(?:Qm[1-9A-HJ-NP-Za-km-z]{44}|b[a-z2-7]{50,100})$/';

    /** One path segment: RFC 3986 unreserved + sub-delims + ':' '@' + %XX. */
    private const SEGMENT_PATTERN = "/^(?:[A-Za-z0-9._~!$&'()*+,;=:@-]|%[0-9A-Fa-f]{2})+$/";

    private static bool $loggedBadGateway = false;

    /**
     * The canonical `https://ipfs.io/ipfs/<cid>[/path]` for an IPFS image, or
     * null when the value is not one we recognise (it then goes on to the
     * ordinary https rule, unchanged).
     */
    public static function canonicalize(string $url): ?string
    {
        if (str_starts_with(strtolower($url), 'ipfs://')) {
            $rest = substr($url, 7);
            if (str_starts_with($rest, 'ipfs/')) {
                $rest = substr($rest, 5);
            }
            return self::fromCidAndPath($rest);
        }

        if (!str_starts_with(strtolower($url), 'https://')) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';

        // Path gateways: https://<gateway>/ipfs/<cid>[/path]
        if (self::isPathGateway($host) && str_starts_with($path, '/ipfs/')) {
            return self::fromCidAndPath(substr($path, 6));
        }

        // Subdomain gateways: https://<cid>.ipfs.<gateway>[/path]
        foreach (self::SUBDOMAIN_GATEWAYS as $gw) {
            $suffix = '.ipfs.' . $gw;
            if (str_ends_with($host, $suffix)) {
                $cid = substr($host, 0, -strlen($suffix));
                return self::fromCidAndPath($cid . $path);
            }
        }

        return null;
    }

    /**
     * The configured gateway's URL for a stored image, plus the ipfs.io URL to
     * fall back to. A non-IPFS URL passes through with no fallback; so does an
     * IPFS URL when the configured gateway is ipfs.io itself.
     *
     * @return array{url: ?string, fallback_url: ?string}
     */
    public static function present(?string $url): array
    {
        if ($url === null || !str_starts_with($url, self::CANONICAL_PREFIX)) {
            return ['url' => $url, 'fallback_url' => null];
        }

        $host = self::gatewayHost();
        if ($host === self::DEFAULT_GATEWAY) {
            return ['url' => $url, 'fallback_url' => null];
        }

        return [
            'url'          => 'https://' . $host . '/ipfs/' . substr($url, strlen(self::CANONICAL_PREFIX)),
            'fallback_url' => $url,
        ];
    }

    /** The gateway from `BCC_IPFS_GATEWAY`, or ipfs.io. */
    public static function gatewayHost(): string
    {
        $configured = defined('BCC_IPFS_GATEWAY') ? constant('BCC_IPFS_GATEWAY') : null;
        $host = self::gatewayHostFrom($configured);

        if ($configured !== null && $host === self::DEFAULT_GATEWAY
            && strtolower(trim(is_string($configured) ? $configured : '')) !== self::DEFAULT_GATEWAY
            && !self::$loggedBadGateway) {
            self::$loggedBadGateway = true;
            \BCC\Core\Log\Logger::warning('[bcc-trust] BCC_IPFS_GATEWAY is not a bare hostname; using ipfs.io');
        }

        return $host;
    }

    /**
     * A configured gateway, accepted ONLY as a bare hostname. Anything that
     * could carry a key or redirect elsewhere — a scheme, path, port, query,
     * userinfo — falls back to ipfs.io.
     */
    public static function gatewayHostFrom(mixed $configured): string
    {
        if (!is_string($configured)) {
            return self::DEFAULT_GATEWAY;
        }

        $host = strtolower(trim($configured));
        $label = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';
        if (strlen($host) > 253 || preg_match('/^(?:' . $label . '\.)+[a-z]{2,63}$/', $host) !== 1) {
            return self::DEFAULT_GATEWAY;
        }

        return $host;
    }

    private static function isPathGateway(string $host): bool
    {
        // The configured gateway too: a presented URL handed back to a
        // write path (the picker -> save) canonicalizes back to ipfs.io.
        if (in_array($host, self::PATH_GATEWAYS, true) || $host === self::gatewayHost()) {
            return true;
        }
        $suffix = self::PINATA_DEDICATED_SUFFIX;
        return str_ends_with($host, $suffix) && strlen($host) > strlen($suffix)
            && preg_match('/^[a-z0-9-]+$/', substr($host, 0, -strlen($suffix))) === 1;
    }

    /**
     * `<cid>[/seg/…]` → canonical URL, or null if any part is unsafe. The CID
     * pattern and SEGMENT_PATTERN are allowlists, so a query, fragment,
     * backslash, space or control character fails one of them.
     */
    private static function fromCidAndPath(string $rest): ?string
    {
        if ($rest === '') {
            return null;
        }

        $segments = explode('/', $rest);
        $cid = array_shift($segments);
        if (preg_match(self::CID_PATTERN, $cid) !== 1) {
            return null;
        }

        // A trailing slash is harmless; empty, '.' or '..' segments are not.
        if ($segments !== [] && end($segments) === '') {
            array_pop($segments);
        }
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..' || preg_match(self::SEGMENT_PATTERN, $segment) !== 1) {
                return null;
            }
        }

        return self::CANONICAL_PREFIX . $cid . ($segments === [] ? '' : '/' . implode('/', $segments));
    }
}
