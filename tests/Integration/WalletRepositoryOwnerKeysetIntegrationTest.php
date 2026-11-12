<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-11: the owner read behind the daily on-chain refresh, against real MySQL /
 * MariaDB.
 *
 * The refresh paged owners by OFFSET over an unordered DISTINCT, which neither
 * engine keeps stable across ticks. It now reads a KEYSET — ascending user_id
 * after a cursor — and a failed read throws instead of reading as "no more
 * owners". The offset method (behind bcc-core's WalletLinkReadInterface) is
 * ordered too.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(WalletRepository::class)]
final class WalletRepositoryOwnerKeysetIntegrationTest extends TestCase
{
    private int $eth  = 0;
    private int $sol  = 0;
    private int $near = 0;

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE `' . WalletRepository::table() . '`');
        $this->eth  = $this->chain('f11-eth', 'evm');
        $this->sol  = $this->chain('f11-sol', 'solana');
        $this->near = $this->chain('f11-near', 'near');
    }

    private function chain(string $slug, string $type): int
    {
        global $wpdb;
        $table = ChainRepository::table();
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM `{$table}` WHERE slug = %s", $slug));
        if ($id !== null) {
            return (int) $id;
        }
        $ok = $wpdb->insert($table, ['slug' => $slug, 'name' => $slug, 'chain_type' => $type, 'is_active' => 1]);
        self::assertNotFalse($ok, 'chain fixture: ' . $wpdb->last_error);
        return (int) $wpdb->insert_id;
    }

    private function link(int $userId, int $chainId, string $address): void
    {
        global $wpdb;
        $ok = $wpdb->insert(WalletRepository::table(), [
            'user_id'        => $userId,
            'post_id'        => 0,
            'wallet_address' => $address,
            'chain_id'       => $chainId,
            'wallet_type'    => 'user',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($ok, 'wallet fixture: ' . $wpdb->last_error);
    }

    private function seed(): void
    {
        // Inserted out of order; user 30 has two links (one per chain); user
        // 99 only on a chain that is not asked for.
        $this->link(50, $this->eth, '0x50');
        $this->link(10, $this->sol, 'So10');
        $this->link(30, $this->eth, '0x30');
        $this->link(30, $this->sol, 'So30');
        $this->link(20, $this->eth, '0x20');
        $this->link(40, $this->sol, 'So40');
        $this->link(99, $this->near, 'n99.near');
    }

    public function testTheKeysetIsAscendingDistinctAndScopedToTheChains(): void
    {
        $this->seed();

        self::assertSame(
            [10, 20, 30, 40, 50],
            WalletRepository::getUserIdsWithChainSlugsAfter(['f11-eth', 'f11-sol'], 0, 100),
            'ascending, each owner once (30 has two links), the unasked chain excluded'
        );
    }

    public function testTheKeysetResumesStrictlyAfterTheCursor(): void
    {
        $this->seed();

        self::assertSame([40, 50], WalletRepository::getUserIdsWithChainSlugsAfter(['f11-eth', 'f11-sol'], 30, 100), 'the cursor owner itself is not repeated');
        self::assertSame([20, 30], WalletRepository::getUserIdsWithChainSlugsAfter(['f11-eth', 'f11-sol'], 10, 2), 'the limit bounds the page');
        self::assertSame([], WalletRepository::getUserIdsWithChainSlugsAfter(['f11-eth', 'f11-sol'], 50, 100), 'past the last owner is an empty page, not an error');
    }

    public function testPagingTheKeysetVisitsEveryOwnerExactlyOnce(): void
    {
        $this->seed();

        $seen   = [];
        $cursor = 0;
        for ($i = 0; $i < 10; $i++) {
            $page = WalletRepository::getUserIdsWithChainSlugsAfter(['f11-eth', 'f11-sol'], $cursor, 2);
            if ($page === []) {
                break;
            }
            array_push($seen, ...$page);
            $cursor = end($page);
        }

        self::assertSame([10, 20, 30, 40, 50], $seen);
    }

    public function testAFailedReadThrowsRatherThanReadingAsNoMoreOwners(): void
    {
        $this->seed();
        global $wpdb;
        $wpdb->failQueriesMatching = '/' . preg_quote(WalletRepository::table(), '/') . '/';

        try {
            $this->expectException(RepositoryReadFailure::class);
            WalletRepository::getUserIdsWithChainSlugsAfter(['f11-eth', 'f11-sol'], 0, 100);
        } finally {
            $wpdb->clearFaultInjection();
        }
    }

    public function testTheOffsetMethodIsOrderedToo(): void
    {
        $this->seed();

        self::assertSame([10, 20, 30, 40, 50], WalletRepository::getUserIdsWithChainSlugs(['f11-eth', 'f11-sol'], 100, 0));
        self::assertSame([30, 40], WalletRepository::getUserIdsWithChainSlugs(['f11-eth', 'f11-sol'], 2, 2));
    }
}
