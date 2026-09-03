<?php

declare(strict_types=1);

/**
 * The validation boundary for an administrator-approved marketplace
 * RESEARCH link.
 *
 * ── WHAT THIS LINK IS FOR ───────────────────────────────────────────────
 * Someone looking at a gated community needs a way to find out how to obtain
 * the NFT that community requires. That is a doorway for their own research —
 * "Research on marketplace", never "Buy now". BCC does not become a storefront
 * because it points at one, and no price, sale or volume figure may ever
 * appear beside the link.
 *
 * ── ⚠ THE ALLOWLIST IS AN OWNER-APPROVED POLICY, PER CHAIN ──────────────
 * `approvedHostsForFamily()` is an explicit security policy ratified by the
 * product owner on 2026-09-03 — NOT an inference from anything already in the
 * codebase. An earlier draft shipped it EMPTY precisely because no such
 * ratification existed, and the hosts that appear in
 * `GroupsDiscoveryEndpoint`'s per-chain TEMPLATE map were deliberately not
 * promoted: that map builds a link for a different, pre-existing feature and
 * approves nothing about per-collection storage.
 *
 * The pairing is per CHAIN FAMILY, not global, because "this marketplace is
 * legitimate" and "this marketplace lists this chain" are different claims:
 *
 *   evm     → opensea.io
 *   cosmos  → stargaze.zone
 *   solana  → magiceden.io, magiceden.us
 *
 * ⚠ Magic Eden is NOT approved for EVM and OpenSea is NOT approved for
 * Solana in this first version, even though both marketplaces operate on
 * those chains. That is a deliberate narrowing, not an oversight — widening
 * it is an owner decision, and a validator that quietly accepted a plausible
 * pairing would be making that decision on the owner's behalf.
 *
 * ⚠ `www.stargaze.zone` is NOT approved. The canonical host is
 * `stargaze.zone`; a `www.` variant is a DIFFERENT hostname under exact
 * matching, and admitting it would start the subdomain erosion this list
 * exists to prevent. {@see canonicalCandidateHost()} normalizes an
 * administrator-facing candidate instead.
 *
 * ── THIS CLASS DOES NOT BUILD LINKS ─────────────────────────────────────
 * {@see \BCC\Trust\Onchain\Support\MarketplaceLinkBuilder} already renders a
 * template into a URL and stays the only place that does. This class only
 * decides whether a URL an administrator typed may be persisted, and never
 * derives one from a name, a symbol or a search query.
 *
 * @package BCC\Trust\Onchain\ValueObjects
 */

namespace BCC\Trust\Onchain\ValueObjects;

if (!defined('ABSPATH')) {
    exit;
}

final class MarketplaceResearchUrl
{
    /** Bounded refusal codes. Never free text, never the rejected URL. */
    public const OK                 = 'ok';
    public const EMPTY_URL          = 'empty';
    public const NOT_A_URL          = 'not_a_url';
    public const SCHEME_NOT_HTTPS   = 'scheme_not_https';
    public const HOST_NOT_ALLOWED   = 'host_not_allowed';
    public const HAS_CREDENTIALS    = 'has_credentials';
    public const HAS_PORT           = 'has_port';
    public const TOO_LONG           = 'too_long';
    public const CONTAINS_CONTROL   = 'contains_control';

    /**
     * Query parameters stripped before storage.
     *
     * Tracking and affiliate parameters are removed rather than refused: the
     * destination is still the right collection, and a referral code merely
     * has no business being carried by a community platform.
     *
     * @return list<string>
     */
    public static function strippedQueryParams(): array
    {
        return [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
            'ref', 'referrer', 'referral', 'refer', 'r',
            'aff', 'affiliate', 'affiliate_id', 'partner', 'partnerId',
            'fbclid', 'gclid', 'gbraid', 'wbraid', 'msclkid', 'twclid', 'igshid', 'mc_eid',
            's', 'source', 'campaign', 'via', 'invite', 'inviteCode',
        ];
    }

    /** Chain families the allowlist is keyed by. */
    public const FAMILY_EVM    = 'evm';
    public const FAMILY_COSMOS = 'cosmos';
    public const FAMILY_SOLANA = 'solana';

    /**
     * The owner-approved policy: which exact hosts may back a link, per chain
     * family. Ratified 2026-09-03.
     *
     * ⚠ Comparison is EXACT and case-folded — never a substring, suffix or
     * subdomain test. `opensea.io.evil.com` and `notopensea.io` both CONTAIN
     * `opensea.io` and neither is OpenSea. A subdomain that should be allowed
     * must be listed in full, and none currently is: no `www.`, no API hosts,
     * no help centres, no creator or studio portals.
     *
     * @return array<string, list<string>>
     */
    public static function approvedHostPolicy(): array
    {
        return [
            self::FAMILY_EVM    => ['opensea.io'],
            self::FAMILY_COSMOS => ['stargaze.zone'],
            self::FAMILY_SOLANA => ['magiceden.io', 'magiceden.us'],
        ];
    }

    /**
     * Hosts approved for one chain family. Unknown family → nothing.
     *
     * @return list<string>
     */
    public static function approvedHostsForFamily(string $family): array
    {
        return self::approvedHostPolicy()[strtolower(trim($family))] ?? [];
    }

    /**
     * Every approved host, across all families.
     *
     * ⚠ Useful for reporting; NOT a validation input. Validating against the
     * union would approve Magic Eden for EVM and OpenSea for Solana, which is
     * exactly the pairing the policy withholds.
     *
     * @return list<string>
     */
    public static function allApprovedHosts(): array
    {
        $all = [];
        foreach (self::approvedHostPolicy() as $hosts) {
            foreach ($hosts as $host) {
                $all[] = $host;
            }
        }

        return $all;
    }

    /**
     * Normalize an administrator-facing CANDIDATE host.
     *
     * `www.stargaze.zone` is a real, working URL an administrator may well
     * paste, but it is not the approved hostname. Rather than admit a `www.`
     * variant into the allowlist — which starts the subdomain erosion exact
     * matching exists to prevent — the candidate is normalized to the
     * canonical host and then validated normally.
     *
     * ⚠ This is a convenience on INPUT only. It never widens what may be
     * stored: the normalized host still has to appear in the policy for the
     * declared chain family.
     */
    public static function canonicalCandidateHost(string $host): string
    {
        $host = strtolower(rtrim(trim($host), '.'));

        // Only a leading `www.` is folded, and only when the remainder is
        // itself an approved host. `www.evil.com` normalizes to `evil.com`
        // and is still refused; nothing else is rewritten.
        if (str_starts_with($host, 'www.')) {
            $bare = substr($host, 4);
            if (in_array($bare, self::allApprovedHosts(), true)) {
                return $bare;
            }
        }

        return $host;
    }

    /**
     * The production entry point.
     *
     * ⚠ Requires the CHAIN FAMILY. The host list is per family by policy, so
     * there is deliberately no signature that validates a URL without naming
     * the chain — a caller that could omit it would be validating against the
     * union, and the union approves pairings the owner withheld.
     *
     * @return array{ok: bool, reason: string, url: string|null}
     *         `url` is the sanitized value to store, present only when ok.
     */
    public static function validateForFamily(mixed $raw, string $family): array
    {
        $hosts = self::approvedHostsForFamily($family);

        // An unknown or empty family has no approved hosts, so everything is
        // refused — closed by default, not open by default.
        return self::validateAgainst($raw, $hosts);
    }

    /**
     * Same rules, against an EXPLICIT host list.
     *
     * ⚠ INTERNAL AND TEST-ONLY. Production must go through
     * {@see validateForFamily()}: this signature lets a caller supply its own
     * allowlist, which is precisely the control the policy exists to enforce.
     * `MarketplaceResearchUrlTest` pins that with a caller inventory, the same
     * way `LegacyAliasRouteCompatibilityTest` pins the legacy lookup.
     *
     * It stays public so the parsing rules — lookalike hosts, credential
     * spoofs, encoded hosts, tracking stripping — can be exercised against a
     * fixture host on a RESERVED TLD, independently of whatever the live
     * policy happens to contain.
     *
     * @param  list<string> $allowedHosts
     * @return array{ok: bool, reason: string, url: string|null}
     */
    public static function validateAgainst(mixed $raw, array $allowedHosts): array
    {
        if (!is_string($raw)) {
            return self::refuse(self::NOT_A_URL);
        }

        $url = trim($raw);
        if ($url === '') {
            return self::refuse(self::EMPTY_URL);
        }

        // A URL with a control character or whitespace is malformed. Refuse
        // rather than strip: repairing it invents a destination, and embedded
        // newlines are a classic header/redirect-splitting vector.
        if (preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return self::refuse(self::CONTAINS_CONTROL);
        }

        if (strlen($url) > CollectionMetadataRules::MAX_MARKETPLACE_URL) {
            return self::refuse(self::TOO_LONG);
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return self::refuse(self::NOT_A_URL);
        }

        // ── Parsed scheme, not a substring test ─────────────────────────
        // `strpos($url, 'https') === 0` would accept "https:evil" and
        // "httpsx://…". The parser is the authority.
        if (strtolower((string) $parts['scheme']) !== 'https') {
            return self::refuse(self::SCHEME_NOT_HTTPS);
        }

        // `https://opensea.io@evil.com/` parses with host `evil.com` and user
        // `opensea.io`. Credentials have no legitimate use in a public
        // research link and their presence is a spoofing attempt.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return self::refuse(self::HAS_CREDENTIALS);
        }

        // A non-default port on a public marketplace is not a marketplace.
        if (isset($parts['port'])) {
            return self::refuse(self::HAS_PORT);
        }

        // Trailing dot is the FQDN form of the same host, so it is folded
        // rather than treated as a distinct name — `opensea.io.` resolves
        // identically and must not be a bypass.
        $host = strtolower(rtrim((string) $parts['host'], '.'));

        // ⚠ ONE charset rule, deliberately. Only letters, digits, hyphens and
        // dots are a hostname here, which refuses in a single check:
        //
        //   • percent-encoded hosts — `%6Fpensea.io` decodes to `opensea.io`
        //     but is NOT that string, and decoding it would mean guessing what
        //     a browser will do rather than reading what was written;
        //   • IDN/punycode lookalikes, rather than trying to compare
        //     confusable scripts — a problem no allowlist wins;
        //   • anything else that is not a plain hostname.
        //
        // An earlier draft ALSO had a separate `str_contains($host, '%')`
        // branch. A mutation control that deleted it SURVIVED, which is the
        // proof it was redundant: `%` is not in this character class, so the
        // charset rule already refused it. Two checks for one property is one
        // more place for them to disagree.
        if (preg_match('/^[a-z0-9.-]+$/', $host) !== 1) {
            return self::refuse(self::HOST_NOT_ALLOWED);
        }

        // EXACT membership. Never a suffix or substring match — both
        // `opensea.io.evil.com` and `notopensea.io` contain `opensea.io`.
        $allowed = array_map('strtolower', $allowedHosts);
        if (!in_array($host, $allowed, true)) {
            return self::refuse(self::HOST_NOT_ALLOWED);
        }

        return ['ok' => true, 'reason' => self::OK, 'url' => self::rebuild($parts, $host)];
    }

    /**
     * Reassemble from PARSED components, dropping the fragment and the
     * stripped query parameters.
     *
     * Rebuilding rather than editing the original string means anything the
     * parser did not recognise cannot ride along, and the stored URL is
     * exactly what the validator understood.
     *
     * @param array<string, mixed> $parts
     */
    private static function rebuild(array $parts, string $host): string
    {
        $path = isset($parts['path']) ? (string) $parts['path'] : '';

        $query = '';
        if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $pairs);
            $strip = array_map('strtolower', self::strippedQueryParams());
            $kept  = [];
            foreach ($pairs as $k => $v) {
                if (in_array(strtolower((string) $k), $strip, true)) {
                    continue;
                }
                $kept[(string) $k] = $v;
            }
            if ($kept !== []) {
                $query = '?' . http_build_query($kept);
            }
        }

        // The fragment is dropped unconditionally — it is never part of the
        // destination the server sees, and it is a convenient carrier for
        // client-side redirect tricks.
        return 'https://' . $host . $path . $query;
    }

    /** @return array{ok: false, reason: string, url: null} */
    private static function refuse(string $reason): array
    {
        return ['ok' => false, 'reason' => $reason, 'url' => null];
    }
}
