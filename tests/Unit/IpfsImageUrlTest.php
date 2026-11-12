<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Services\HoldingsService;
use BCC\Trust\Onchain\Services\NftSelectionService;
use BCC\Trust\Onchain\ValueObjects\CollectionMetadataRules as Rules;
use BCC\Trust\Onchain\ValueObjects\IpfsImageUrl;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-165: IPFS-hosted NFT images.
 *
 * STORAGE is gateway-neutral — every IPFS form becomes
 * `https://ipfs.io/ipfs/<cid>[/path]`, a working https URL on any surface.
 * PRESENTATION moves it onto `BCC_IPFS_GATEWAY` (a bare hostname, never a
 * key) with the ipfs.io URL as `fallback_url`.
 *
 * Each test runs in its own process so `BCC_IPFS_GATEWAY` can be defined (or
 * not) per test.
 */
#[CoversClass(IpfsImageUrl::class)]
#[CoversClass(Rules::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class IpfsImageUrlTest extends TestCase
{
    private const V0 = 'QmYwAPJzv5CZsnA625s3Xf2nemtYgPpHdWEz79ojWnPbdG';
    private const V1 = 'bafybeigdyrzt5sfp7udm7hu76uh7y26nf3efuylqabf3oclgtqy55fbzdi';
    private const PINATA = 'amber-perfect-butterfly-459.mypinata.cloud';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/ipfs-image-url-stubs.php';
        \BCC\Core\Log\Logger::$warnings = [];
    }

    /** @return array<string, array{string, string}> */
    public static function canonicalCases(): array
    {
        $v0 = self::V0;
        $v1 = self::V1;
        return [
            'ipfs:// v0'                   => ["ipfs://{$v0}", "https://ipfs.io/ipfs/{$v0}"],
            'ipfs:// v0 with path'         => ["ipfs://{$v0}/7.png", "https://ipfs.io/ipfs/{$v0}/7.png"],
            'ipfs://ipfs/ prefix'          => ["ipfs://ipfs/{$v0}/a/b.json", "https://ipfs.io/ipfs/{$v0}/a/b.json"],
            'ipfs:// v1'                   => ["ipfs://{$v1}/1.png", "https://ipfs.io/ipfs/{$v1}/1.png"],
            'upper-case scheme'            => ["IPFS://{$v1}", "https://ipfs.io/ipfs/{$v1}"],
            'trailing slash dropped'       => ["ipfs://{$v1}/", "https://ipfs.io/ipfs/{$v1}"],
            'percent-encoded segment'      => ["ipfs://{$v1}/My%20Art.png", "https://ipfs.io/ipfs/{$v1}/My%20Art.png"],
            'already canonical'            => ["https://ipfs.io/ipfs/{$v0}/x.png", "https://ipfs.io/ipfs/{$v0}/x.png"],
            'gateway.ipfs.io'              => ["https://gateway.ipfs.io/ipfs/{$v0}", "https://ipfs.io/ipfs/{$v0}"],
            'dweb.link path'               => ["https://dweb.link/ipfs/{$v1}/x", "https://ipfs.io/ipfs/{$v1}/x"],
            'retired cloudflare rescued'   => ["https://cloudflare-ipfs.com/ipfs/{$v0}/1.png", "https://ipfs.io/ipfs/{$v0}/1.png"],
            'public pinata'                => ["https://gateway.pinata.cloud/ipfs/{$v0}", "https://ipfs.io/ipfs/{$v0}"],
            'nftstorage.link path'         => ["https://nftstorage.link/ipfs/{$v1}", "https://ipfs.io/ipfs/{$v1}"],
            'dedicated pinata'             => ["https://someone-else.mypinata.cloud/ipfs/{$v0}/1.png", "https://ipfs.io/ipfs/{$v0}/1.png"],
            'subdomain dweb.link'          => ["https://{$v1}.ipfs.dweb.link/1.png", "https://ipfs.io/ipfs/{$v1}/1.png"],
            'subdomain nftstorage.link'    => ["https://{$v1}.ipfs.nftstorage.link", "https://ipfs.io/ipfs/{$v1}"],
        ];
    }

    #[DataProvider('canonicalCases')]
    public function testIpfsFormsCanonicalizeToIpfsIo(string $in, string $out): void
    {
        self::assertSame($out, IpfsImageUrl::canonicalize($in));
        self::assertSame($out, Rules::sanitizeImageUrl($in), 'the stored value is the canonical URL');
    }

    /** @return array<string, array{string}> */
    public static function notIpfsCases(): array
    {
        $v0 = self::V0;
        return [
            'short CID'                    => ['ipfs://QmHash'],
            'CIDv0 with a 0'               => ['ipfs://Qm0wAPJzv5CZsnA625s3Xf2nemtYgPpHdWEz79ojWnPbdG'],
            'upper-case CIDv1'             => ['ipfs://BAFYBEIGDYRZT5SFP7UDM7HU76UH7Y26NF3EFUYLQABF3OCLGTQY55FBZDI'],
            'dot-dot segment'              => ["ipfs://{$v0}/../etc/passwd"],
            'dot segment'                  => ["ipfs://{$v0}/./a"],
            'empty segment'                => ["ipfs://{$v0}//a"],
            'backslash'                    => ["ipfs://{$v0}/a\\b"],
            'query'                        => ["ipfs://{$v0}/a?x=1"],
            'fragment'                     => ["ipfs://{$v0}/a#f"],
            'space'                        => ["ipfs://{$v0}/a b"],
            'unlisted host /ipfs/ path'    => ["https://evil.example/ipfs/{$v0}"],
            'look-alike host'              => ["https://ipfs.io.evil.example/ipfs/{$v0}"],
            'bare mypinata.cloud'          => ["https://mypinata.cloud/ipfs/{$v0}"],
            'dotted mypinata label'        => ["https://a.b.mypinata.cloud/ipfs/{$v0}"],
            'userinfo'                     => ["https://user@ipfs.io/ipfs/{$v0}"],
            'port'                         => ["https://ipfs.io:8443/ipfs/{$v0}"],
            'gateway query'                => ["https://ipfs.io/ipfs/{$v0}?filename=a.png"],
            'http gateway'                 => ["http://ipfs.io/ipfs/{$v0}"],
            'not an /ipfs/ path'           => ["https://ipfs.io/ipns/{$v0}"],
            'ordinary https'               => ['https://cdn.example/a.png'],
            'javascript'                   => ['javascript:alert(1)'],
        ];
    }

    #[DataProvider('notIpfsCases')]
    public function testAnythingElseIsNotCanonicalized(string $in): void
    {
        self::assertNull(IpfsImageUrl::canonicalize($in));
    }

    public function testRejectedIpfsNeverReachesStorageAndHttpsIsUntouched(): void
    {
        $v0 = self::V0;
        // Unparseable ipfs:// stays refused; an unlisted /ipfs/ host is just
        // an https URL; a gateway URL with a query is kept as https as-is.
        self::assertNull(Rules::sanitizeImageUrl("ipfs://{$v0}/../x"));
        self::assertNull(Rules::sanitizeImageUrl("http://ipfs.io/ipfs/{$v0}"));
        self::assertSame("https://evil.example/ipfs/{$v0}", Rules::sanitizeImageUrl("https://evil.example/ipfs/{$v0}"));
        self::assertSame('https://cdn.example/a.png', Rules::sanitizeImageUrl('https://cdn.example/a.png'));
        self::assertNull(Rules::sanitizeImageUrl("ipfs://{$v0}/" . str_repeat('a', 500)), 'over the column limit');
    }

    /** @return array<string, array{mixed, string}> */
    public static function gatewayCases(): array
    {
        return [
            'pinata dedicated'     => [self::PINATA, self::PINATA],
            'trimmed, lower-cased' => ['  IPFS.IO ', 'ipfs.io'],
            'not a string'         => [123, 'ipfs.io'],
            'null'                 => [null, 'ipfs.io'],
            'empty'                => ['', 'ipfs.io'],
            'scheme'               => ['https://gw.example', 'ipfs.io'],
            'path'                 => ['gw.example/ipfs', 'ipfs.io'],
            'query (a key)'        => ['gw.example?pinataGatewayToken=abc', 'ipfs.io'],
            'userinfo'             => ['user:pass@gw.example', 'ipfs.io'],
            'port'                 => ['gw.example:8080', 'ipfs.io'],
            'single label'         => ['localhost', 'ipfs.io'],
            'leading hyphen'       => ['-gw.example', 'ipfs.io'],
            'empty label'          => ['gw..example', 'ipfs.io'],
        ];
    }

    #[DataProvider('gatewayCases')]
    public function testTheGatewaySettingIsABareHostnameOrIpfsIo(mixed $configured, string $expected): void
    {
        self::assertSame($expected, IpfsImageUrl::gatewayHostFrom($configured));
    }

    public function testWithNoGatewayConfiguredThereIsNoFallback(): void
    {
        $url = 'https://ipfs.io/ipfs/' . self::V0 . '/1.png';
        self::assertSame(['url' => $url, 'fallback_url' => null], IpfsImageUrl::present($url));
    }

    public function testTheConfiguredGatewayLeadsAndIpfsIoFollows(): void
    {
        define('BCC_IPFS_GATEWAY', self::PINATA);
        $url = 'https://ipfs.io/ipfs/' . self::V0 . '/1.png';

        self::assertSame(
            ['url' => 'https://' . self::PINATA . '/ipfs/' . self::V0 . '/1.png', 'fallback_url' => $url],
            IpfsImageUrl::present($url)
        );
        self::assertSame(
            ['url' => 'https://cdn.example/a.png', 'fallback_url' => null],
            IpfsImageUrl::present('https://cdn.example/a.png'),
            'a non-IPFS image is not rewritten'
        );
        self::assertSame(['url' => null, 'fallback_url' => null], IpfsImageUrl::present(null));
    }

    public function testABadGatewaySettingFallsBackToIpfsIoAndLogsOnce(): void
    {
        define('BCC_IPFS_GATEWAY', 'gw.example?pinataGatewayToken=secret');
        $url = 'https://ipfs.io/ipfs/' . self::V0;

        self::assertSame(['url' => $url, 'fallback_url' => null], IpfsImageUrl::present($url));
        IpfsImageUrl::present($url);

        self::assertCount(1, \BCC\Core\Log\Logger::$warnings, 'logged once, not per image');
        self::assertStringNotContainsString('secret', \BCC\Core\Log\Logger::$warnings[0], 'the setting value is never logged');
    }

    public function testAPresentedUrlCanonicalizesBackOnWrite(): void
    {
        // The picker hands a PRESENTED url to save(); it must be stored
        // canonical even when the gateway is not on the built-in list.
        define('BCC_IPFS_GATEWAY', 'img.bcc.example');
        $canonical = 'https://ipfs.io/ipfs/' . self::V1 . '/9.png';
        $presented = IpfsImageUrl::present($canonical)['url'];

        self::assertSame('https://img.bcc.example/ipfs/' . self::V1 . '/9.png', $presented);
        self::assertSame($canonical, Rules::sanitizeImageUrl($presented));
    }

    /** @return array<string, array{mixed, array{url: ?string, fallback_url: ?string}}> */
    public static function presentImageCases(): array
    {
        $v0 = self::V0;
        return [
            'raw ipfs://'    => ["ipfs://{$v0}/1.png", ['url' => 'https://' . self::PINATA . "/ipfs/{$v0}/1.png", 'fallback_url' => "https://ipfs.io/ipfs/{$v0}/1.png"]],
            'http'           => ['http://cdn.example/a.png', ['url' => null, 'fallback_url' => null]],
            'javascript'     => ['javascript:alert(1)', ['url' => null, 'fallback_url' => null]],
            'data'           => ['data:image/png;base64,AAAA', ['url' => null, 'fallback_url' => null]],
            'https kept'     => ['https://cdn.example/a.png', ['url' => 'https://cdn.example/a.png', 'fallback_url' => null]],
            'null'           => [null, ['url' => null, 'fallback_url' => null]],
            'not a string'   => [42, ['url' => null, 'fallback_url' => null]],
        ];
    }

    /** @param array{url: ?string, fallback_url: ?string} $expected */
    #[DataProvider('presentImageCases')]
    public function testPresentImageSanitizesAnyStoredValue(mixed $raw, array $expected): void
    {
        define('BCC_IPFS_GATEWAY', self::PINATA);
        self::assertSame($expected, Rules::presentImage($raw));
    }

    // ── the surfaces that emitted images RAW ────────────────────────────

    public function testHoldingsItemsArePresented(): void
    {
        define('BCC_IPFS_GATEWAY', self::PINATA);
        $present = new \ReflectionMethod(HoldingsService::class, 'presentImages');

        $out = $present->invoke(null, [
            ['token_id' => '1', 'image_url' => 'ipfs://' . self::V0 . '/1.png'],
            ['token_id' => '2', 'image_url' => 'http://cdn.example/2.png'],
            ['token_id' => '3'],
        ]);

        self::assertSame('https://' . self::PINATA . '/ipfs/' . self::V0 . '/1.png', $out[0]['image_url']);
        self::assertSame('https://ipfs.io/ipfs/' . self::V0 . '/1.png', $out[0]['image_fallback_url']);
        self::assertNull($out[1]['image_url'], 'a raw http:// holding image is no longer emitted');
        self::assertNull($out[1]['image_fallback_url']);
        self::assertNull($out[2]['image_url']);
        self::assertSame('1', $out[0]['token_id'], 'other fields untouched');
    }

    /**
     * The gallery/picker payload is built in getForUser(), whose wallet,
     * chain and fetcher collaborators have no unit harness. Pin the wiring:
     * its returned items pass through presentImages().
     */
    public function testGetForUserItemsPassThroughPresentImages(): void
    {
        $m     = new \ReflectionMethod(HoldingsService::class, 'getForUser');
        $lines = file((string) $m->getFileName());
        self::assertIsArray($lines);
        $body  = implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));

        self::assertMatchesRegularExpression(
            "/'items'\\s*=>\\s*self::presentImages\\(/",
            $body,
            'getForUser() must present every item image (raw holdings images reached <img>)'
        );
    }

    public function testStoredSelectionsArePresented(): void
    {
        define('BCC_IPFS_GATEWAY', self::PINATA);

        $out = NftSelectionService::presentSelections([
            (object) ['id' => 5, 'image_url' => 'ipfs://' . self::V1, 'chain_slug' => 'ethereum'],
            (object) ['id' => 6, 'image_url' => 'javascript:alert(1)', 'chain_slug' => 'solana'],
        ]);

        self::assertSame('https://' . self::PINATA . '/ipfs/' . self::V1, $out[0]['image_url']);
        self::assertSame('https://ipfs.io/ipfs/' . self::V1, $out[0]['image_fallback_url']);
        self::assertSame(5, $out[0]['id']);
        self::assertSame('ethereum', $out[0]['chain_slug']);
        self::assertNull($out[1]['image_url']);
    }
}
