<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\NftCollectionPiecesRepository;
use BCC\Trust\Onchain\Repositories\NftHoldingsRepository;
use BCC\Trust\Onchain\Repositories\NftSelectionRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-165: the three token-image write paths store the CANONICAL image, against
 * real MySQL / MariaDB.
 *
 * They stored upstream values raw, so `http://`, `ipfs://` and worse reached
 * `<img>`; the piece cache's COALESCE then kept a raw value for good. Each now
 * stores `https://ipfs.io/ipfs/<cid>/…` for IPFS, the https URL otherwise,
 * and NULL for anything a browser must not load.
 *
 * The suite uses ids/keys of its own and deletes only those.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(NftHoldingsRepository::class)]
#[CoversClass(NftCollectionPiecesRepository::class)]
#[CoversClass(NftSelectionRepository::class)]
final class NftImageWriteRulesIntegrationTest extends TestCase
{
    private const V0       = 'QmYwAPJzv5CZsnA625s3Xf2nemtYgPpHdWEz79ojWnPbdG';
    private const CANON    = 'https://ipfs.io/ipfs/' . self::V0 . '/1.png';
    private const CONTRACT = '0xf165f165f165f165f165f165f165f165f165f165';
    private const WALLET   = 916500;
    private const USER     = 916501;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM `' . NftHoldingsRepository::table() . '` WHERE wallet_link_id = %d', self::WALLET));
        $wpdb->query($wpdb->prepare('DELETE FROM `' . NftCollectionPiecesRepository::table() . '` WHERE contract_address = %s', self::CONTRACT));
        $wpdb->query($wpdb->prepare('DELETE FROM `' . NftSelectionRepository::table() . '` WHERE user_id = %d', self::USER));
    }

    private function holding(string $tokenId): int
    {
        global $wpdb;
        $ok = $wpdb->insert(NftHoldingsRepository::table(), [
            'wallet_link_id'  => self::WALLET,
            'chain_id'        => 1,
            'contract_address' => self::CONTRACT,
            'token_id'        => $tokenId,
            'last_seen_block' => 1,
            'confirmed_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($ok, 'holding fixture: ' . $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    private function storedHoldingImage(int $id): ?string
    {
        global $wpdb;
        $v = $wpdb->get_var($wpdb->prepare('SELECT image_url FROM `' . NftHoldingsRepository::table() . '` WHERE id = %d', $id));
        return $v === null ? null : (string) $v;
    }

    public function testHoldingEnrichmentStoresTheCanonicalImage(): void
    {
        $ipfs = $this->holding('1');
        $http = $this->holding('2');
        $ok   = $this->holding('3');

        self::assertTrue(NftHoldingsRepository::applyEnrichment($ipfs, ['image_url' => 'ipfs://' . self::V0 . '/1.png']));
        self::assertTrue(NftHoldingsRepository::applyEnrichment($http, ['image_url' => 'http://cdn.example/2.png']));
        self::assertTrue(NftHoldingsRepository::applyEnrichment($ok, ['image_url' => 'https://cdn.example/3.png']));

        self::assertSame(self::CANON, $this->storedHoldingImage($ipfs));
        self::assertNull($this->storedHoldingImage($http), 'http:// is not stored');
        self::assertSame('https://cdn.example/3.png', $this->storedHoldingImage($ok));
    }

    public function testThePieceCacheStoresTheCanonicalImage(): void
    {
        self::assertTrue(NftCollectionPiecesRepository::upsert(1, self::CONTRACT, '7', [
            'name'            => 'Seven',
            'image_url'       => 'https://cloudflare-ipfs.com/ipfs/' . self::V0 . '/1.png',
            'image_url_thumb' => 'javascript:alert(1)',
        ]));

        global $wpdb;
        /** @var object{image_url: ?string, image_url_thumb: ?string}|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT image_url, image_url_thumb FROM `' . NftCollectionPiecesRepository::table() . '` WHERE contract_address = %s AND token_id = %s',
            self::CONTRACT,
            '7'
        ));
        self::assertNotNull($row);
        self::assertSame(self::CANON, $row->image_url, 'the retired gateway URL is rescued onto ipfs.io');
        self::assertNull($row->image_url_thumb, 'javascript: never reaches the cache, where COALESCE would keep it');
    }

    public function testASelectionStoresTheCanonicalImageNotAPresentedOne(): void
    {
        $base = [
            'user_id'          => self::USER,
            'wallet_link_id'   => self::WALLET,
            'chain_id'         => 1,
            'contract_address' => self::CONTRACT,
            'collection_name'  => 'Demo',
            'name'             => 'Demo',
            'metadata_uri'     => null,
            'token_standard'   => 'ERC-721',
        ];
        // What the picker hands save(): a URL already PRESENTED on a gateway.
        self::assertNotFalse(NftSelectionRepository::save($base + [
            'token_id'  => '1',
            'image_url' => 'https://amber-perfect-butterfly-459.mypinata.cloud/ipfs/' . self::V0 . '/1.png',
        ]));
        self::assertNotFalse(NftSelectionRepository::save($base + ['token_id' => '2', 'image_url' => 'http://cdn.example/2.png']));

        global $wpdb;
        $get = static fn (string $token): ?string => $wpdb->get_var($wpdb->prepare(
            'SELECT image_url FROM `' . NftSelectionRepository::table() . '` WHERE user_id = %d AND token_id = %s',
            self::USER,
            $token
        ));
        self::assertSame(self::CANON, $get('1'));
        self::assertNull($get('2'));
    }
}
