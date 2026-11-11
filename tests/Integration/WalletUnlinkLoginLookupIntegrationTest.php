<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\WalletRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * After an owner unlinks a wallet, a wallet-login with that wallet can no
 * longer reach the account — against real MySQL / MariaDB.
 *
 * Wallet login resolves the account with
 * WalletRepository::findUserIdByAddress(chain_id, address)
 * (WalletAuthController), and DELETE /wallets/{id} removes the row with
 * WalletRepository::delete(id, caller). This pins the two together on the
 * real table: the owner's delete makes the lookup miss, and a delete by
 * anyone else is a no-op that leaves the lookup — and the account — intact.
 * (Session revocation on unlink is unit-tested in WalletUnlinkSessionTest.)
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(WalletRepository::class)]
final class WalletUnlinkLoginLookupIntegrationTest extends TestCase
{
    private const OWNER    = 611;
    private const STRANGER = 612;
    private const CHAIN_ID = 4;

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_wallet_links`');
    }

    private function link(int $userId, string $address): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
            'user_id'        => $userId,
            'post_id'        => 0,
            'wallet_address' => $address,
            'chain_id'       => self::CHAIN_ID,
            'wallet_type'    => 'user',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
        ]);
        self::assertNotFalse($ok, 'fixture insert');
        return (int) $wpdb->insert_id;
    }

    public function testOwnerUnlinkMakesTheWalletLoginLookupMiss(): void
    {
        $address = 'cosmos1unlinkfixture' . uniqid();
        $id      = $this->link(self::OWNER, $address);

        self::assertSame(self::OWNER, WalletRepository::findUserIdByAddress(self::CHAIN_ID, $address), 'control: wallet-login finds the account');

        self::assertTrue(WalletRepository::delete($id, self::OWNER));

        self::assertSame(0, WalletRepository::findUserIdByAddress(self::CHAIN_ID, $address), 'wallet-login can no longer reach the account');
        self::assertSame(0, WalletRepository::countForUserByChain(self::OWNER, self::CHAIN_ID));
    }

    public function testADeleteByAnyoneElseIsANoOp(): void
    {
        $address = 'cosmos1unlinkfixture' . uniqid();
        $id      = $this->link(self::OWNER, $address);

        self::assertFalse(WalletRepository::delete($id, self::STRANGER), 'a foreign id removes nothing');

        self::assertSame(self::OWNER, WalletRepository::findUserIdByAddress(self::CHAIN_ID, $address));
    }

    public function testOnlyTheUnlinkedWalletIsAffected(): void
    {
        $gone = 'cosmos1unlinkfixture' . uniqid();
        $kept = 'cosmos1unlinkfixture' . uniqid();
        $id   = $this->link(self::OWNER, $gone);
        $this->link(self::OWNER, $kept);

        self::assertTrue(WalletRepository::delete($id, self::OWNER));

        self::assertSame(0, WalletRepository::findUserIdByAddress(self::CHAIN_ID, $gone));
        self::assertSame(self::OWNER, WalletRepository::findUserIdByAddress(self::CHAIN_ID, $kept));
        self::assertSame(1, WalletRepository::countForUserByChain(self::OWNER, self::CHAIN_ID));
    }
}
