<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\CreatorGalleryEndpoint;
use BCC\Trust\Core\REST\GroupsDiscoveryEndpoint;
use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Core\Services\GroupsService;
use BCC\Trust\Core\Services\HallsService;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use BCC\Trust\Onchain\REST\HolderGroupsEndpoint;
use BCC\Trust\Onchain\ValueObjects\GatedGroupConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-187: collection-level NFT images (a collection's cover art) are served on
 * BCC_IPFS_GATEWAY with the ipfs.io copy as `image_fallback_url`, like the
 * token-level images of F-165.
 *
 * Collection images are stored canonical (`https://ipfs.io/ipfs/…`) by the
 * F-165 sanitizer; each emit point now PRESENTS that URL. present() only — a
 * non-IPFS URL (an uploaded page crest, a CDN cover) passes through unchanged
 * with a null fallback, and is never sanitized away.
 *
 * Each test runs in its own process so BCC_IPFS_GATEWAY can be defined.
 */
#[CoversClass(CardViewService::class)]
#[CoversClass(GroupsDiscoveryEndpoint::class)]
#[CoversClass(HolderGroupsEndpoint::class)]
#[CoversClass(CreatorGalleryEndpoint::class)]
#[CoversClass(HallsService::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CollectionImageGatewayTest extends TestCase
{
    private const CID      = 'QmYwAPJzv5CZsnA625s3Xf2nemtYgPpHdWEz79ojWnPbdG';
    private const CANON    = 'https://ipfs.io/ipfs/' . self::CID . '/cover.png';
    private const GATEWAY  = 'amber-perfect-butterfly-459.mypinata.cloud';
    private const PRESENTED = 'https://' . self::GATEWAY . '/ipfs/' . self::CID . '/cover.png';

    protected function setUp(): void
    {
        parent::setUp();
        define('BCC_IPFS_GATEWAY', self::GATEWAY);
    }

    /** @return array<string, mixed> */
    private static function crest(string $imageUrl): array
    {
        $m = new \ReflectionMethod(CardViewService::class, 'buildCrest');
        /** @var array<string, mixed> $out */
        $out = $m->invoke(null, 'Bad Kids', 'chain', 'cosmos', $imageUrl);
        return $out;
    }

    public function testTheCrestServesAnIpfsCoverOnTheGatewayWithAFallback(): void
    {
        $c = self::crest(self::CANON);

        self::assertSame(self::PRESENTED, $c['image_url']);
        self::assertSame(self::CANON, $c['image_fallback_url']);
    }

    public function testTheCrestLeavesNonIpfsImagesExactlyAsTheyWere(): void
    {
        // An uploaded page crest — possibly http:// on a dev host. present()
        // must not sanitize it away.
        foreach (['https://example.test/wp-content/uploads/a.png', 'http://bcc.local/wp-content/uploads/a.png'] as $url) {
            $c = self::crest($url);
            self::assertSame($url, $c['image_url'], $url);
            self::assertNull($c['image_fallback_url']);
        }

        $none = self::crest('');
        self::assertNull($none['image_url'], 'no image is still null — the monogram fallback');
        self::assertNull($none['image_fallback_url']);
    }

    public function testDiscoveryItemsServeTheGatewayWithAFallback(): void
    {
        $endpoint = (new \ReflectionClass(GroupsDiscoveryEndpoint::class))->newInstanceWithoutConstructor();
        $m        = new \ReflectionMethod(GroupsDiscoveryEndpoint::class, 'composeItem');
        $ctx      = new GroupContext(7, GroupType::Nft, PeepSoPrivacy::Open, false, 'collection', 3, null);

        /** @var array<string, mixed> $item */
        $item = $m->invoke($endpoint, $ctx, null, [], self::CANON, null, null, null, null, null, []);
        self::assertSame(self::PRESENTED, $item['image_url']);
        self::assertSame(self::CANON, $item['image_fallback_url']);

        /** @var array<string, mixed> $plain */
        $plain = $m->invoke($endpoint, $ctx, null, [], null, null, null, null, null, null, []);
        self::assertNull($plain['image_url']);
        self::assertNull($plain['image_fallback_url']);
    }

    public function testHolderGroupCollectionsServeTheGatewayWithAFallback(): void
    {
        $endpoint = (new \ReflectionClass(HolderGroupsEndpoint::class))->newInstanceWithoutConstructor();
        $m        = new \ReflectionMethod(HolderGroupsEndpoint::class, 'composeItem');
        $cfg      = new GatedGroupConfig(7, 1, '0xabc', 1, 3);
        $activity = ['posts_last_7d' => 0, 'last_activity_at' => null, 'heat' => 'cold', 'heat_label' => 'Quiet'];
        $coll     = (object) [
            'id' => '3', 'chain_id' => '1', 'contract_address' => '0xabc', 'collection_name' => 'Demo',
            'image_url' => self::CANON, 'chain_slug' => 'ethereum', 'chain_type' => 'evm',
        ];

        /** @var array{collection: array<string, mixed>} $item */
        $item = $m->invoke($endpoint, $cfg, null, $coll, $activity);
        self::assertSame(self::PRESENTED, $item['collection']['image_url']);
        self::assertSame(self::CANON, $item['collection']['image_fallback_url']);

        /** @var array{collection: array<string, mixed>} $none */
        $none = $m->invoke($endpoint, $cfg, null, null, $activity);
        self::assertNull($none['collection']['image_url']);
        self::assertNull($none['collection']['image_fallback_url']);
    }

    public function testCreatorGalleryItemsServeTheGatewayWithAFallback(): void
    {
        $m   = new \ReflectionMethod(CreatorGalleryEndpoint::class, 'shapeRow');
        $row = (object) [
            'id' => 9, 'contract_address' => '0xabc', 'chain_slug' => 'ethereum', 'chain_name' => 'Ethereum',
            'collection_name' => 'Demo', 'image_url' => self::CANON,
        ];

        /** @var array<string, mixed>|null $item */
        $item = $m->invoke(null, $row);
        self::assertIsArray($item);
        self::assertSame(self::PRESENTED, $item['image_url']);
        self::assertSame(self::CANON, $item['image_fallback_url']);
    }

    public function testHallCollectionsServeTheGatewayWithAFallback(): void
    {
        $out = HallsService::previewFromRows([], [
            (object) ['id' => 1, 'collection_name' => 'A', 'image_url' => self::CANON, 'contract_address' => '0xa'],
            (object) ['id' => 2, 'collection_name' => 'B', 'image_url' => 'https://cdn.example/b.png', 'contract_address' => '0xb'],
            (object) ['id' => 3, 'collection_name' => 'C', 'image_url' => '', 'contract_address' => '0xc'],
        ]);

        self::assertSame(self::PRESENTED, $out['collections'][0]['image_url']);
        self::assertSame(self::CANON, $out['collections'][0]['image_fallback_url']);
        self::assertSame('https://cdn.example/b.png', $out['collections'][1]['image_url']);
        self::assertNull($out['collections'][1]['image_fallback_url']);
        self::assertNull($out['collections'][2]['image_url']);
        self::assertNull($out['collections'][2]['image_fallback_url']);
    }

    /**
     * GroupsService::getGroup() needs PeepSo, membership and stats
     * collaborators with no unit harness. Pin the wiring: the detail payload's
     * image goes through IpfsImageUrl::present().
     */
    public function testTheGroupDetailPresentsItsImage(): void
    {
        $m     = new \ReflectionMethod(GroupsService::class, 'getGroup');
        $lines = file((string) $m->getFileName());
        self::assertIsArray($lines);
        $body  = implode('', array_slice($lines, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));

        self::assertMatchesRegularExpression('/\$image\s*=\s*\\\\BCC\\\\Trust\\\\Onchain\\\\ValueObjects\\\\IpfsImageUrl::present\(\$imageUrl\)/', $body);
        self::assertMatchesRegularExpression("/'image_url'\\s*=>\\s*\\\$image\\['url'\\]/", $body);
        self::assertMatchesRegularExpression("/'image_fallback_url'\\s*=>\\s*\\\$image\\['fallback_url'\\]/", $body);
    }
}
