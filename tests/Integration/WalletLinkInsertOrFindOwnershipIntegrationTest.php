<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\WalletRepository;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-37: WalletRepository::insertOrFind() must never hand back another
 * user's wallet link.
 *
 * bcc_wallet_links has TWO unique keys: user_chain_wallet (user_id,
 * chain_id, wallet_address) and uq_chain_address (chain_id,
 * wallet_address). The upsert's ON DUPLICATE KEY UPDATE id =
 * LAST_INSERT_ID(id) fires on EITHER, so when another user already holds
 * the address (e.g. two users racing to link it), it returned that
 * user's row id as if it were the caller's, and linkWallet() then
 * verified it and reported success.
 *
 * Real engine, real table (the bootstrap creates it through the real
 * installer, with both keys), real collation (case-insensitive on the
 * address).
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversMethod(WalletRepository::class, 'insertOrFind')]
final class WalletLinkInsertOrFindOwnershipIntegrationTest extends TestCase
{
    private const CHAIN = 9;
    private const ADDR  = 'cosmos1fixtureaddressaaaaaaaaaaaaaaaaaaaa';
    private const ALICE = 501;
    private const BOB   = 502;

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_wallet_links`');
    }

    /** @return array{id: int, inserted: bool} */
    private static function link(int $userId, string $address = self::ADDR): array
    {
        return WalletRepository::insertOrFind([
            'user_id'        => $userId,
            'post_id'        => 0,
            'wallet_address' => $address,
            'chain_id'       => self::CHAIN,
            'wallet_type'    => 'user',
        ]);
    }

    private static function rowCount(): int
    {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM `' . $wpdb->prefix . 'bcc_wallet_links`');
    }

    public function testFirstLinkInserts(): void
    {
        $r = self::link(self::ALICE);
        self::assertTrue($r['inserted']);
        self::assertGreaterThan(0, $r['id']);
    }

    public function testSameUserRelinkFindsTheirOwnRow(): void
    {
        $first  = self::link(self::ALICE);
        $second = self::link(self::ALICE);

        self::assertSame($first['id'], $second['id']);
        self::assertFalse($second['inserted']);
        self::assertSame(1, self::rowCount());
    }

    public function testAnotherUsersAddressIsNeverReturned(): void
    {
        $alice = self::link(self::ALICE);
        $bob   = self::link(self::BOB);

        self::assertSame(['id' => 0, 'inserted' => false], $bob, 'must not return Alice\'s row to Bob');
        self::assertNotSame($alice['id'], $bob['id']);
        self::assertSame(1, self::rowCount(), 'Bob gets no row either');
    }

    public function testCaseVariantOfAnotherUsersAddressIsNeverReturned(): void
    {
        // The address column collates case-insensitively, so this collides
        // on uq_chain_address too.
        self::link(self::ALICE);
        self::assertSame(['id' => 0, 'inserted' => false], self::link(self::BOB, strtoupper(self::ADDR)));
    }

    public function testTheOtherUsersRowIsLeftUntouched(): void
    {
        global $wpdb;
        $alice = self::link(self::ALICE);
        self::link(self::BOB);

        $owner = $wpdb->get_var($wpdb->prepare(
            'SELECT user_id FROM `' . $wpdb->prefix . 'bcc_wallet_links` WHERE id = %d',
            $alice['id']
        ));
        self::assertSame((string) self::ALICE, (string) $owner);
    }
}
