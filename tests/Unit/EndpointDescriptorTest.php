<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Support\EndpointDescriptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Deny-by-default endpoint description, and identity kept separate from it.
 *
 * ── ⚠⚠⚠ THE INCIDENT THIS EXISTS TO PREVENT ─────────────────────────────
 * The previous redactor masked the QUERY STRING only:
 *
 *     redactEndpoint('https://host/v2/SECRET')      -> 'https://host/v2/SECRET'
 *     redactEndpoint('https://host/?api-key=SECRET') -> 'https://host/?***REDACTED***'
 *
 * It was an ALLOW-BY-PATTERN redactor: it enumerated the one credential shape
 * its author had in mind. Alchemy puts the key in the PATH, and
 * `AlchemyEndpoint::nftBaseFromRpcUrl()` rewrites that path to `/nft/v3/<KEY>`,
 * producing a third shape nobody had listed. During the 2026-09-30 staging pass
 * a diagnostic printed exactly that rewritten form and leaked a live key, which
 * then had to be rotated.
 *
 * So description here is DENY-BY-DEFAULT: scheme and host survive, and
 * everything else is discarded without being asked what it is. A new URL shape
 * cannot leak by simply not matching a pattern, because nothing is matched.
 *
 * ── AND WHY IDENTITY IS A SEPARATE CONCEPT ──────────────────────────────
 * `NftProviderReadiness::dasMarkApplies()` decided whether a stored
 * DAS-unsupported mark still describes the endpoint in use by comparing
 * redactions. Under a scheme+host-only redaction that comparison collapses:
 * two different credentials — or two different paths — on one host would
 * compare EQUAL, so a stale mark could silently attach to a rotated endpoint
 * and keep a working driver disabled.
 *
 * Description and identity are therefore different functions with different
 * rules: description is lossy on purpose and safe to render; identity is
 * collision-resistant over the WHOLE URL, keyed to this site, and must never be
 * rendered or logged.
 */
#[CoversClass(EndpointDescriptor::class)]
final class EndpointDescriptorTest extends TestCase
{
    /** Every fixture the review named, plus the shape that actually leaked. */
    private const SECRET = 'S3cr3tK3yMaterial0123456789';

    /** @return array<string, array{0: string}> */
    public static function credentialBearingUrls(): array
    {
        $s = self::SECRET;

        return [
            'alchemy v2 path key'        => ["https://eth-mainnet.g.alchemy.com/v2/{$s}"],
            'alchemy nft v3 path key'    => ["https://eth-mainnet.g.alchemy.com/nft/v3/{$s}"],
            'quicknode v1 path key'      => ["https://example.quiknode.pro/v1/{$s}"],
            'helius api-key query'       => ["https://mainnet.helius-rpc.com/?api-key={$s}"],
            'multiple query parameters'  => ["https://host.example/rpc?api-key={$s}&mode=fast&token={$s}"],
            'userinfo credentials'       => ["https://admin:{$s}@host.example/rpc"],
            'username only'              => ["https://{$s}@host.example/"],
            'fragment'                   => ["https://host.example/rpc#{$s}"],
            'path and query and fragment'=> ["https://host.example/v1/{$s}?api-key={$s}#{$s}"],
            'explicit port with key'     => ["https://host.example:8443/v2/{$s}"],
            'uppercase host'             => ["https://HOST.Example/v2/{$s}"],
            'trailing slash'             => ["https://host.example/v2/{$s}/"],
            'encoded key'                => ['https://host.example/?api-key=' . rawurlencode($s)],

            // ⚠⚠⚠ QUERY PARAMETER NAMES NOBODY ENUMERATED. Every fixture above
            // this block used `api-key`, and a mutation that kept any query
            // string NOT containing the literal `api-key` survived the whole
            // suite. That is the incident's exact shape reproduced in the tests
            // themselves: a fixture set that only covers the names its author
            // thought of proves nothing about deny-by-default.
            //
            // These names are all real: `key` is QuickNode and Infura, `token`
            // is Ankr and Blast, `apikey` and `x-api-key` are common variants,
            // and `auth` is generic.
            'key query'                  => ["https://host.example/rpc?key={$s}"],
            'apikey query no hyphen'     => ["https://host.example/rpc?apikey={$s}"],
            'x-api-key query'            => ["https://host.example/rpc?x-api-key={$s}"],
            'token query'                => ["https://host.example/rpc?token={$s}"],
            'auth query'                 => ["https://host.example/rpc?auth={$s}"],
            'unnamed query value'        => ["https://host.example/rpc?{$s}"],
            'two unknown parameters'     => ["https://host.example/rpc?token={$s}&secret={$s}"],
            'key in second parameter'    => ["https://host.example/rpc?mode=fast&token={$s}"],
        ];
    }

    // ── 1. Description leaks nothing ────────────────────────────────────

    #[DataProvider('credentialBearingUrls')]
    public function testDescriptionNeverContainsTheSecret(string $url): void
    {
        $d = EndpointDescriptor::display($url);

        self::assertStringNotContainsString(self::SECRET, $d, "leaked through: {$d}");
        self::assertStringNotContainsString(rawurlencode(self::SECRET), $d);
        self::assertStringNotContainsString(strtolower(self::SECRET), strtolower($d));
    }

    #[DataProvider('credentialBearingUrls')]
    public function testDescriptionContainsOnlySchemeAndHost(string $url): void
    {
        $d = EndpointDescriptor::display($url);

        // Exactly `scheme://host`, nothing else — no userinfo, port, path,
        // query or fragment can survive this shape.
        self::assertMatchesRegularExpression(
            '~^[a-z][a-z0-9+.-]*://[a-z0-9._-]+$~',
            $d,
            "not a bare scheme://host: {$d}"
        );
        foreach (['@', '?', '#', ':8443'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, substr($d, 8), "contains {$forbidden}: {$d}");
        }
        // No path segment: exactly two slashes, both from the scheme separator.
        self::assertSame(2, substr_count($d, '/'), "path survived: {$d}");
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function expectedDescriptions(): array
    {
        $s = self::SECRET;

        return [
            'alchemy'  => ["https://eth-mainnet.g.alchemy.com/v2/{$s}", 'https://eth-mainnet.g.alchemy.com'],
            'nft base' => ["https://eth-mainnet.g.alchemy.com/nft/v3/{$s}", 'https://eth-mainnet.g.alchemy.com'],
            'helius'   => ["https://mainnet.helius-rpc.com/?api-key={$s}", 'https://mainnet.helius-rpc.com'],
            'userinfo' => ["https://admin:{$s}@host.example/rpc", 'https://host.example'],
            'port'     => ["https://host.example:8443/v2/{$s}", 'https://host.example'],
            'uppercase'=> ["https://HOST.Example/v2/{$s}", 'https://host.example'],
        ];
    }

    #[DataProvider('expectedDescriptions')]
    public function testDescriptionIsExactlySchemeAndLowercasedHost(string $url, string $expected): void
    {
        self::assertSame($expected, EndpointDescriptor::display($url));
    }

    /** @return array<string, array{0: mixed}> */
    public static function unusableInputs(): array
    {
        return [
            'empty'            => [''],
            'whitespace'       => ['   '],
            'null'             => [null],
            'not a url'        => ['not a url at all'],
            'scheme only'      => ['https://'],
            'no host'          => ['https:///path'],
            'bare host'        => ['host.example'],
            'javascript'       => ['javascript:alert(1)'],
            'data uri'         => ['data:text/plain,hello'],
            'control chars'    => ["https://host.example/\x00\x01"],
        ];
    }

    /**
     * ⚠ A URL we cannot parse must NOT be echoed back. Falling through to the
     * raw input is how a malformed-but-credentialed string would leak.
     */
    #[DataProvider('unusableInputs')]
    public function testUnusableInputYieldsABoundedPlaceholderNotTheInput(mixed $url): void
    {
        $d = EndpointDescriptor::display($url);

        self::assertContains($d, [EndpointDescriptor::UNCONFIGURED, EndpointDescriptor::UNRECOGNISED], $d);
        if (is_string($url) && trim($url) !== '') {
            self::assertStringNotContainsString(trim($url), $d);
        }
    }

    public function testAMalformedCredentialBearingStringIsNotEchoed(): void
    {
        $d = EndpointDescriptor::display('::::' . self::SECRET . '::::');

        self::assertStringNotContainsString(self::SECRET, $d);
        self::assertSame(EndpointDescriptor::UNRECOGNISED, $d);
    }

    // ── 2. Description carries no derived credential material ───────────

    #[DataProvider('credentialBearingUrls')]
    public function testDescriptionCarriesNoHashOrLengthOfTheSecret(string $url): void
    {
        $d = EndpointDescriptor::display($url);

        foreach (['md5', 'sha1', 'sha256', 'crc32b'] as $algo) {
            self::assertStringNotContainsString(hash($algo, self::SECRET), $d);
            self::assertStringNotContainsString(hash($algo, $url), $d);
        }
        self::assertStringNotContainsString((string) strlen(self::SECRET), $d);
        self::assertStringNotContainsString(base64_encode(self::SECRET), $d);
    }

    // ── 3. Identity: collision-resistant over the WHOLE url ─────────────

    public function testIdentityDistinguishesDifferentCredentialsOnOneHost(): void
    {
        $a = EndpointDescriptor::identity('https://host.example/v2/KEY-AAA');
        $b = EndpointDescriptor::identity('https://host.example/v2/KEY-BBB');

        self::assertNotSame($a, $b, 'two credentials on one host must not share an identity');
    }

    public function testIdentityDistinguishesDifferentPathsOnOneHost(): void
    {
        self::assertNotSame(
            EndpointDescriptor::identity('https://host.example/v2/KEY'),
            EndpointDescriptor::identity('https://host.example/nft/v3/KEY')
        );
    }

    public function testIdentityDistinguishesDifferentQueryValuesOnOneHost(): void
    {
        self::assertNotSame(
            EndpointDescriptor::identity('https://host.example/?api-key=AAA'),
            EndpointDescriptor::identity('https://host.example/?api-key=BBB')
        );
    }

    public function testIdentityMatchesForTheSameEndpoint(): void
    {
        $u = 'https://mainnet.helius-rpc.com/?api-key=' . self::SECRET;

        self::assertSame(EndpointDescriptor::identity($u), EndpointDescriptor::identity($u));
    }

    /**
     * ⚠ Equivalent-but-differently-written endpoints must still match, or a
     * mark would stop applying after a cosmetic change and a real negative
     * signal would be silently dropped.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function equivalentPairs(): array
    {
        return [
            'host case'       => ['https://Host.Example/v2/KEY', 'https://host.example/v2/KEY'],
            'surrounding ws'  => ['  https://host.example/v2/KEY  ', 'https://host.example/v2/KEY'],
            'default port'    => ['https://host.example:443/v2/KEY', 'https://host.example/v2/KEY'],
        ];
    }

    #[DataProvider('equivalentPairs')]
    public function testEquivalentEndpointsShareAnIdentity(string $a, string $b): void
    {
        self::assertSame(EndpointDescriptor::identity($a), EndpointDescriptor::identity($b));
    }

    public function testIdentityIsEmptyForUnusableInput(): void
    {
        foreach (['', '   ', 'not a url'] as $bad) {
            self::assertSame('', EndpointDescriptor::identity($bad), "should not mint an identity for: {$bad}");
        }
        self::assertSame('', EndpointDescriptor::identity(null));
    }

    // ── 4. ⚠⚠⚠ Identity is not reversible and not a bare public hash ────

    public function testIdentityIsNotAPlainUnsaltedDigestOfTheUrl(): void
    {
        $u = 'https://host.example/v2/' . self::SECRET;
        $id = EndpointDescriptor::identity($u);

        foreach (['md5', 'sha1', 'sha256', 'sha512'] as $algo) {
            self::assertNotSame(hash($algo, $u), $id, "identity is a bare {$algo} of the URL — brute-forceable");
            self::assertNotSame(hash($algo, self::SECRET), $id);
        }
    }

    public function testIdentityDoesNotContainTheSecretOrTheHost(): void
    {
        $id = EndpointDescriptor::identity('https://host.example/v2/' . self::SECRET);

        self::assertStringNotContainsString(self::SECRET, $id);
        self::assertStringNotContainsString('host.example', $id);
        self::assertMatchesRegularExpression('~^[0-9a-f]{32,128}$~', $id, 'opaque hex digest expected');
    }

    /** It is site-keyed: a different key yields a different identity. */
    public function testIdentityIsKeyedToThisSite(): void
    {
        $u = 'https://host.example/v2/KEY';
        $withRealKey = EndpointDescriptor::identity($u);
        $withOtherKey = hash_hmac('sha256', 'https://host.example/v2/KEY', 'some-other-key');

        self::assertNotSame($withOtherKey, $withRealKey, 'identity must depend on a site secret');
    }

    // ── 5. configured/unconfigured is a boolean ──────────────────────────

    public function testIsConfiguredIsABooleanAndLeaksNothing(): void
    {
        self::assertTrue(EndpointDescriptor::isConfigured('https://host.example/v2/' . self::SECRET));
        self::assertFalse(EndpointDescriptor::isConfigured(''));
        self::assertFalse(EndpointDescriptor::isConfigured(null));
        self::assertFalse(EndpointDescriptor::isConfigured('not a url'));
    }

    public function testHostIsAvailableOnItsOwnForDiagnostics(): void
    {
        self::assertSame(
            'mainnet.helius-rpc.com',
            EndpointDescriptor::host('https://mainnet.helius-rpc.com/?api-key=' . self::SECRET)
        );
        self::assertSame('', EndpointDescriptor::host('not a url'));
    }
}
