<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\CollectionRepository;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-143 — CollectionRepository::getForProject() pages a creator's gallery
 * with LIMIT/OFFSET ordered by a NON-unique column (total_volume by
 * default). Collections tied on it (e.g. all with no volume yet) came back
 * in no fixed order: MySQL's sort for ORDER BY … LIMIT is not stable, so
 * consecutive pages skipped some collections and repeated others. The query
 * now ends in the unique `c.id DESC` tiebreak.
 *
 * REAL query, REAL server, the bootstrap's real tables; rows are scoped to
 * a post id no other test uses and deleted afterwards.
 */
#[Group('integration')]
#[Group('mariadb')]
final class CollectionGalleryPagingIntegrationTest extends TestCase
{
    private const POST_ID  = 914301;
    private const COUNT    = 41;
    private const PER_PAGE = 6;

    private int $chainId  = 0;
    private int $walletId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        global $wpdb;
        $wpdb->clearFaultInjection();
        $this->cleanup();

        $wpdb->insert(ChainRepository::table(), ['slug' => 'f143-chain', 'name' => 'F143', 'chain_type' => 'evm']);
        self::assertSame('', $wpdb->last_error, 'chain fixture');
        $this->chainId = (int) $wpdb->get_var("SELECT MAX(id) FROM " . ChainRepository::table());

        $wpdb->insert(WalletRepository::table(), [
            'user_id' => 1, 'post_id' => self::POST_ID, 'wallet_address' => '0xf143', 'chain_id' => $this->chainId,
        ]);
        self::assertSame('', $wpdb->last_error, 'wallet fixture');
        $this->walletId = (int) $wpdb->get_var("SELECT MAX(id) FROM " . WalletRepository::table());

        for ($i = 0; $i < self::COUNT; $i++) {
            $wpdb->insert(CollectionRepository::table(), [
                'wallet_link_id'   => $this->walletId,
                'contract_address' => sprintf('0xf143%04d', $i),
                'chain_id'         => $this->chainId,
                'collection_name'  => 'Tied',
                'total_volume'     => 0,       // every row ties on the default sort
                'floor_price'      => 0,
                'unique_holders'   => 0,
                'total_supply'     => 0,
                'show_on_profile'  => 1,
                'expires_at'       => '2030-01-01 00:00:00',
            ]);
            self::assertSame('', $wpdb->last_error, 'collection fixture');
        }
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM " . CollectionRepository::table() . " WHERE contract_address LIKE '0xf143%'");
        $wpdb->query($wpdb->prepare("DELETE FROM " . WalletRepository::table() . " WHERE post_id = %d", self::POST_ID));
        $wpdb->query("DELETE FROM " . ChainRepository::table() . " WHERE slug = 'f143-chain'");
    }

    /**
     * @return list<int> ids in page order across every page
     */
    private function pageThrough(string $orderBy): array
    {
        $ids   = [];
        $pages = (int) ceil(self::COUNT / self::PER_PAGE);
        for ($page = 1; $page <= $pages; $page++) {
            $result = CollectionRepository::getForProject(self::POST_ID, $page, self::PER_PAGE, $orderBy, true);
            foreach ($result['items'] as $item) {
                $ids[] = (int) $item->id;
            }
        }
        return $ids;
    }

    public function testPagingTiedCollectionsVisitsEachExactlyOnceInIdOrder(): void
    {
        foreach (['total_volume', 'floor_price', 'unique_holders', 'total_supply', 'collection_name'] as $orderBy) {
            $ids = $this->pageThrough($orderBy);

            self::assertCount(self::COUNT, $ids, "{$orderBy}: every collection appears");
            self::assertSame(array_values(array_unique($ids)), $ids, "{$orderBy}: no collection repeats across pages");

            $expected = $ids;
            rsort($expected);
            self::assertSame($expected, $ids, "{$orderBy}: ties break by id, newest first");
        }
    }
}
