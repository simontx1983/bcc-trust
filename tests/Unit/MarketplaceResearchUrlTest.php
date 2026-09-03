<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\ValueObjects\MarketplaceResearchUrl as Url;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PR 7 — the marketplace research-link boundary.
 *
 * ── ⚠ THE ALLOWLIST IS AN OWNER-APPROVED POLICY ─────────────────────────
 * Ratified 2026-09-03, per CHAIN FAMILY:
 *
 *   evm     → opensea.io
 *   cosmos  → stargaze.zone
 *   solana  → magiceden.io, magiceden.us
 *
 * It is NOT an inference from the per-chain TEMPLATE map in
 * `GroupsDiscoveryEndpoint` — that map serves a different, pre-existing
 * feature and approves nothing about per-collection storage. An earlier draft
 * of this class shipped the list EMPTY for exactly that reason.
 *
 * Two things are therefore tested separately:
 *
 *   1. the POLICY — every approved pairing accepted, every withheld pairing
 *      refused, against the REAL hosts;
 *   2. the PARSING RULES — lookalikes, credential spoofs, encoded hosts,
 *      tracking stripping — against a fixture host on a reserved TLD, so they
 *      stay covered no matter how the policy later changes.
 */
final class MarketplaceResearchUrlTest extends TestCase
{
    /**
     * A fixture host on a RESERVED TLD, so the parsing rules can be exercised
     * independently of the live policy and can never be mistaken for an
     * approval of a real marketplace.
     *
     * @return array{ok: bool, reason: string, url: string|null}
     */
    private static function validateWithFixtureHost(mixed $raw): array
    {
        return Url::validateAgainst($raw, ['opensea.test']);
    }

    // ── ⚠ the owner-approved policy ─────────────────────────────────────

    /**
     * The ratified pairing, exactly as approved.
     *
     * Pinned as a whole map rather than host-by-host: adding a host, or a
     * family, is a PRODUCT decision and must fail this assertion until the
     * policy is changed deliberately.
     */
    public function testTheApprovedPolicyIsExactlyWhatTheOwnerRatified(): void
    {
        self::assertSame(
            [
                Url::FAMILY_EVM    => ['opensea.io'],
                Url::FAMILY_COSMOS => ['stargaze.zone'],
                Url::FAMILY_SOLANA => ['magiceden.io', 'magiceden.us'],
            ],
            Url::approvedHostPolicy()
        );
    }

    /** @return array<string, array{string, string}> */
    public static function approvedPairings(): array
    {
        return [
            'opensea on evm'      => [Url::FAMILY_EVM,    'https://opensea.io/assets/ethereum/0xabc/1'],
            'stargaze on cosmos'  => [Url::FAMILY_COSMOS, 'https://stargaze.zone/marketplace/stars1abc'],
            'magiceden.io solana' => [Url::FAMILY_SOLANA, 'https://magiceden.io/marketplace/abc'],
            'magiceden.us solana' => [Url::FAMILY_SOLANA, 'https://magiceden.us/marketplace/abc'],
        ];
    }

    #[DataProvider('approvedPairings')]
    public function testEveryApprovedPairingIsAccepted(string $family, string $url): void
    {
        $result = Url::validateForFamily($url, $family);

        self::assertTrue($result['ok'], "{$family} must accept {$url}: {$result['reason']}");
        self::assertSame($url, $result['url']);
    }

    /**
     * ⚠ The pairings the owner deliberately WITHHELD.
     *
     * Both marketplaces really do operate on more chains than they are
     * approved for here, so every one of these would look plausible to a
     * validator that checked the union of all approved hosts. Withholding
     * them is a product decision; accepting one would be the validator
     * making that decision on the owner's behalf.
     *
     * @return array<string, array{string, string}>
     */
    public static function withheldPairings(): array
    {
        return [
            'magic eden NOT approved for evm'     => [Url::FAMILY_EVM,    'https://magiceden.io/marketplace/abc'],
            'magiceden.us NOT approved for evm'   => [Url::FAMILY_EVM,    'https://magiceden.us/marketplace/abc'],
            'opensea NOT approved for solana'     => [Url::FAMILY_SOLANA, 'https://opensea.io/assets/solana/abc'],
            'opensea NOT approved for cosmos'     => [Url::FAMILY_COSMOS, 'https://opensea.io/assets/abc'],
            'stargaze NOT approved for evm'       => [Url::FAMILY_EVM,    'https://stargaze.zone/marketplace/abc'],
            'stargaze NOT approved for solana'    => [Url::FAMILY_SOLANA, 'https://stargaze.zone/marketplace/abc'],
            'magic eden NOT approved for cosmos'  => [Url::FAMILY_COSMOS, 'https://magiceden.io/marketplace/abc'],
        ];
    }

    #[DataProvider('withheldPairings')]
    public function testWithheldPairingsAreRefused(string $family, string $url): void
    {
        $result = Url::validateForFamily($url, $family);

        self::assertFalse($result['ok'], "{$family} must NOT accept {$url}");
        self::assertSame(Url::HOST_NOT_ALLOWED, $result['reason']);
        self::assertNull($result['url']);
    }

    /** An unknown chain family approves nothing — closed by default. */
    public function testAnUnknownChainFamilyApprovesNothing(): void
    {
        self::assertSame([], Url::approvedHostsForFamily('bitcoin'));
        self::assertSame([], Url::approvedHostsForFamily(''));
        self::assertSame([], Url::approvedHostsForFamily('near'));

        foreach (Url::allApprovedHosts() as $host) {
            self::assertFalse(
                Url::validateForFamily("https://{$host}/x", 'bitcoin')['ok'],
                "an unknown family must not accept {$host}"
            );
        }
    }

    public function testTheFamilyKeyIsTrimmedAndCaseFolded(): void
    {
        self::assertSame(['opensea.io'], Url::approvedHostsForFamily(' EVM '));
    }

    /**
     * ⚠ `www.stargaze.zone` is NOT an approved hostname.
     *
     * It is a real working URL an administrator may well paste, but admitting
     * a `www.` variant into the allowlist starts exactly the subdomain
     * erosion that exact matching exists to prevent.
     */
    public function testWwwVariantsAreNotApprovedHostnames(): void
    {
        foreach (['www.stargaze.zone', 'www.opensea.io', 'www.magiceden.io', 'www.magiceden.us'] as $www) {
            self::assertNotContains($www, Url::allApprovedHosts());
        }

        self::assertFalse(
            Url::validateForFamily('https://www.stargaze.zone/marketplace/abc', Url::FAMILY_COSMOS)['ok'],
            'a www. URL must not validate directly'
        );
    }

    public function testACandidateWwwHostNormalizesToTheCanonicalHost(): void
    {
        self::assertSame('stargaze.zone', Url::canonicalCandidateHost('www.stargaze.zone'));
        self::assertSame('stargaze.zone', Url::canonicalCandidateHost('WWW.Stargaze.Zone.'));
        self::assertSame('opensea.io', Url::canonicalCandidateHost('www.opensea.io'));

        // ⚠ Only an APPROVED host has its `www.` folded. An arbitrary host is
        // returned untouched, because rewriting one would be the validator
        // inventing a destination the administrator did not type — and the
        // normalizer must never widen what can be stored.
        self::assertSame('www.evil.com', Url::canonicalCandidateHost('www.evil.com'));
        self::assertFalse(Url::validateForFamily('https://www.evil.com/x', Url::FAMILY_COSMOS)['ok']);
        self::assertFalse(Url::validateForFamily('https://evil.com/x', Url::FAMILY_COSMOS)['ok']);
    }

    /** API hosts, help centres, creator and studio portals are all refused. */
    public function testNonCanonicalSubdomainsAreRefused(): void
    {
        foreach ([
            [Url::FAMILY_EVM,    'https://api.opensea.io/api/v2/collection/x'],
            [Url::FAMILY_EVM,    'https://support.opensea.io/x'],
            [Url::FAMILY_EVM,    'https://studio.opensea.io/x'],
            [Url::FAMILY_COSMOS, 'https://api.stargaze.zone/x'],
            [Url::FAMILY_COSMOS, 'https://graphql.stargaze.zone/x'],
            [Url::FAMILY_SOLANA, 'https://api.magiceden.io/v2/x'],
            [Url::FAMILY_SOLANA, 'https://help.magiceden.io/x'],
            [Url::FAMILY_SOLANA, 'https://creators.magiceden.us/x'],
        ] as [$family, $url]) {
            $result = Url::validateForFamily($url, $family);
            self::assertFalse($result['ok'], "must refuse subdomain: {$url}");
            self::assertSame(Url::HOST_NOT_ALLOWED, $result['reason']);
        }
    }

    /** Lookalikes of the REAL approved hosts, not merely of a fixture. */
    public function testLookalikesOfApprovedHostsAreRefused(): void
    {
        foreach ([
            [Url::FAMILY_EVM,    'https://opensea.io.evil.com/x'],
            [Url::FAMILY_EVM,    'https://notopensea.io/x'],
            [Url::FAMILY_EVM,    'https://opensea-io.com/x'],
            [Url::FAMILY_EVM,    'https://0pensea.io/x'],
            [Url::FAMILY_COSMOS, 'https://stargaze.zone.evil.com/x'],
            [Url::FAMILY_COSMOS, 'https://stargaze-zone.com/x'],
            [Url::FAMILY_SOLANA, 'https://magiceden.io.evil.com/x'],
            [Url::FAMILY_SOLANA, 'https://magiceden.com/x'],
            [Url::FAMILY_SOLANA, 'https://rnagiceden.io/x'],
        ] as [$family, $url]) {
            self::assertFalse(Url::validateForFamily($url, $family)['ok'], "must refuse lookalike: {$url}");
        }
    }

    /** A trailing dot is the same host and must not be a bypass either way. */
    public function testATrailingDotResolvesToTheSameApprovedHost(): void
    {
        self::assertTrue(Url::validateForFamily('https://opensea.io./assets/x', Url::FAMILY_EVM)['ok']);
        self::assertFalse(Url::validateForFamily('https://evil.com./x', Url::FAMILY_EVM)['ok']);
    }

    /**
     * ⚠ A percent-encoded host is refused, not decoded.
     *
     * `%6Fpensea.io` decodes to `opensea.io` but is not that string. Decoding
     * it would mean guessing what a browser will do rather than reading what
     * was written, and that guess is where host-confusion bugs live.
     */
    public function testEncodedHostsAreRefused(): void
    {
        foreach ([
            'https://%6Fpensea.io/x',
            'https://opensea%2Eio/x',
        ] as $url) {
            self::assertFalse(Url::validateForFamily($url, Url::FAMILY_EVM)['ok'], $url);
        }
    }

    public function testCredentialSpoofsAgainstRealHostsAreRefused(): void
    {
        self::assertSame(
            Url::HAS_CREDENTIALS,
            Url::validateForFamily('https://opensea.io@evil.com/x', Url::FAMILY_EVM)['reason']
        );
    }

    public function testPlainHttpIsRefusedForApprovedHostsToo(): void
    {
        self::assertSame(
            Url::SCHEME_NOT_HTTPS,
            Url::validateForFamily('http://opensea.io/assets/x', Url::FAMILY_EVM)['reason']
        );
    }

    public function testTrackingIsStrippedFromAnApprovedUrl(): void
    {
        $result = Url::validateForFamily(
            'https://opensea.io/assets/ethereum/0xabc?utm_source=bcc&ref=partner&tokenId=7#frag',
            Url::FAMILY_EVM
        );

        self::assertTrue($result['ok']);
        $url = (string) $result['url'];

        self::assertStringNotContainsString('utm_source', $url);
        self::assertStringNotContainsString('ref=', $url);
        self::assertStringNotContainsString('#', $url);
        self::assertStringContainsString('tokenId=7', $url);
    }

    // ── parsing rules, against the fixture host ─────────────────────────

    /** @return array<string, array{string, string}> */
    public static function refusalCases(): array
    {
        return [
            // A space is 0x20, inside the refused control range — a URL
            // containing one is malformed and is refused whole rather than
            // repaired, because repairing it invents a destination.
            'spaces in the url'    => ['not a url at all', Url::CONTAINS_CONTROL],
            'no scheme'            => ['opensea.test/assets/1', Url::NOT_A_URL],
            'plain http'           => ['http://opensea.test/a', Url::SCHEME_NOT_HTTPS],
            'javascript'           => ['javascript:alert(1)', Url::NOT_A_URL],
            'ftp'                  => ['ftp://opensea.test/a', Url::SCHEME_NOT_HTTPS],

            // ⚠ Each CONTAINS the approved host as a substring, so a
            // `str_contains` check would accept every one of them.
            'suffix attack'        => ['https://opensea.test.evil.com/a', Url::HOST_NOT_ALLOWED],
            'prefix attack'        => ['https://notopensea.test/a', Url::HOST_NOT_ALLOWED],
            'subdomain not listed' => ['https://api.opensea.test/a', Url::HOST_NOT_ALLOWED],
            'hyphen lookalike'     => ['https://opensea-test.com/a', Url::HOST_NOT_ALLOWED],

            // `https://opensea.test@evil.com/` parses with host evil.com.
            'credential spoof'     => ['https://opensea.test@evil.com/a', Url::HAS_CREDENTIALS],
            'userpass spoof'       => ['https://u:p@opensea.test/a', Url::HAS_CREDENTIALS],

            'explicit port'        => ['https://opensea.test:8443/a', Url::HAS_PORT],
            'embedded newline'     => ["https://opensea.test/a\nSet-Cookie: x", Url::CONTAINS_CONTROL],
            'empty'                => ['', Url::EMPTY_URL],
            'whitespace only'      => ['   ', Url::EMPTY_URL],
        ];
    }

    #[DataProvider('refusalCases')]
    public function testRefusals(string $url, string $expectedReason): void
    {
        $result = self::validateWithFixtureHost($url);

        self::assertFalse($result['ok'], "must refuse: {$url}");
        self::assertSame($expectedReason, $result['reason']);
        self::assertNull($result['url'], 'a refused URL must never yield a storable value');
    }

    public function testAnExactApprovedHostIsAccepted(): void
    {
        $result = self::validateWithFixtureHost('https://opensea.test/assets/ethereum/0xabc');

        self::assertTrue($result['ok']);
        self::assertSame('https://opensea.test/assets/ethereum/0xabc', $result['url']);
    }

    public function testHostComparisonIsCaseFolded(): void
    {
        self::assertTrue(self::validateWithFixtureHost('https://OpenSea.Test/assets/1')['ok']);
        self::assertTrue(self::validateWithFixtureHost('https://opensea.test./assets/1')['ok']);
    }

    public function testTrackingAndAffiliateParametersAreStripped(): void
    {
        $result = self::validateWithFixtureHost(
            'https://opensea.test/a?utm_source=x&ref=partner123&fbclid=y&tokenId=42&aff=me'
        );

        self::assertTrue($result['ok']);
        $url = (string) $result['url'];

        foreach (['utm_source', 'ref=', 'fbclid', 'aff='] as $banned) {
            self::assertStringNotContainsString($banned, $url, "{$banned} must be stripped");
        }
        self::assertStringContainsString('tokenId=42', $url);
    }

    public function testTheFragmentIsAlwaysDropped(): void
    {
        $result = self::validateWithFixtureHost('https://opensea.test/a#anything');

        self::assertTrue($result['ok']);
        self::assertStringNotContainsString('#', (string) $result['url']);
    }

    /**
     * The stored URL is REBUILT from parsed components, so nothing the parser
     * did not understand can ride along into storage.
     */
    public function testTheStoredUrlIsRebuiltNotEchoed(): void
    {
        $result = self::validateWithFixtureHost('https://OPENSEA.TEST/Path?utm_id=9#frag');

        self::assertTrue($result['ok']);
        self::assertSame('https://opensea.test/Path', $result['url']);
    }

    public function testNoWalletOrUserIdentifierCanBeCarried(): void
    {
        $result = self::validateWithFixtureHost(
            'https://opensea.test/a?ref=0xWALLETADDRESS&utm_source=bcc'
        );

        self::assertTrue($result['ok']);
        self::assertStringNotContainsString('0xWALLETADDRESS', (string) $result['url']);
    }

    public function testAnOverlongUrlIsRefused(): void
    {
        $long = 'https://opensea.test/' . str_repeat('a', 600);
        self::assertSame(Url::TOO_LONG, self::validateWithFixtureHost($long)['reason']);
    }

    // ── no derivation, no search fallback ───────────────────────────────

    /**
     * ⚠ Nothing here derives a URL, and nothing falls back to a search page.
     *
     * A method that built a link from a name, a symbol or a query string
     * would be the "omit rather than link to a search page" rule broken in
     * code. {@see \BCC\Trust\Onchain\Support\MarketplaceLinkBuilder} stays the
     * only place a link is rendered from a template.
     */
    public function testTheValidatorNeverConstructsOrSearchesForAUrl(): void
    {
        $reflection = new \ReflectionClass(Url::class);
        $source     = (string) file_get_contents((string) $reflection->getFileName());

        $codeOnly = '';
        foreach (token_get_all($source) as $tok) {
            if (is_array($tok) && in_array($tok[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codeOnly .= is_array($tok) ? $tok[1] : $tok;
        }

        foreach (['/search', 'q=', 'sprintf(', 'collection_name', 'symbol', 'query='] as $banned) {
            self::assertStringNotContainsString(
                $banned,
                $codeOnly,
                "the validator must not derive or search for a URL ({$banned})"
            );
        }
    }

    /**
     * ⚠ The explicit-host seam must have ZERO production callers.
     *
     * `validateAgainst()` lets the caller supply its own host list, which is
     * precisely the control the policy exists to enforce. Production must go
     * through `validateForFamily()`. Same shape as
     * `LegacyAliasRouteCompatibilityTest::testTheLegacyLookupHasNoProductionCallers`.
     */
    public function testTheHostListSeamHasNoProductionCallers(): void
    {
        $root    = dirname(__DIR__, 2);
        $callers = [];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/app'));
        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            // The declaring file legitimately delegates
            // validateForFamily() -> validateAgainst(); everything else is a
            // bypass.
            if (str_contains((string) $file->getRealPath(), 'MarketplaceResearchUrl.php')) {
                continue;
            }

            $source = (string) file_get_contents((string) $file->getRealPath());
            if (str_contains($source, '::validateAgainst(')) {
                $callers[] = str_replace($root . DIRECTORY_SEPARATOR, '', (string) $file->getRealPath());
            }
        }

        self::assertSame(
            [],
            $callers,
            "validateAgainst() must not be called from production code:\n" . implode("\n", $callers)
        );
    }
}
