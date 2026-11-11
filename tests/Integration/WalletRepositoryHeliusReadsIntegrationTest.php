<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use BCC\Trust\Onchain\Repositories\WalletRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-64: the two wallet_links reads the Helius address list now depends on,
 * against real MySQL / MariaDB.
 *
 *   - listForChainBySolanaSyncOrThrow() must THROW when its query fails. The
 *     resync PUTs its result as the whole webhook list, and the fail-safe
 *     read returns [] on error — which used to wipe every subscription.
 *   - countOtherLinksForChainAddress() answers "does another link still hold
 *     this address?" before an unsubscribe takes it off the shared list
 *     (an unlink's queued unsubscribe can run after someone else re-links the
 *     same address). It must match the address EXACTLY — Solana base58 is
 *     case-sensitive, while the column collation is not — and must throw,
 *     never answer 0, when it cannot run.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(WalletRepository::class)]
final class WalletRepositoryHeliusReadsIntegrationTest extends TestCase
{
    private const CHAIN_ID = 9;
    private const OTHER_CHAIN = 10;
    private const ADDR = 'So1anaCaseSensitiveAddressXyZ1111111111111';

    protected function setUp(): void
    {
        global $wpdb;
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_wallet_links`');
    }

    private function link(int $userId, string $address, int $chainId = self::CHAIN_ID, int $managed = 0): int
    {
        global $wpdb;
        $ok = $wpdb->insert($wpdb->prefix . 'bcc_wallet_links', [
            'user_id'        => $userId,
            'post_id'        => 0,
            'wallet_address' => $address,
            'chain_id'       => $chainId,
            'wallet_type'    => 'user',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
            'helius_managed' => $managed,
        ]);
        self::assertNotFalse($ok, 'fixture insert');
        return (int) $wpdb->insert_id;
    }

    /**
     * Run $fn while the table cannot be read, then put it back.
     */
    private function withTableMissing(callable $fn): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'bcc_wallet_links';
        $moved = $table . '_f64_moved';
        self::assertNotFalse($wpdb->query("RENAME TABLE `{$table}` TO `{$moved}`"));
        try {
            $fn();
        } finally {
            $wpdb->query("RENAME TABLE `{$moved}` TO `{$table}`");
        }
    }

    public function testTheThrowingListReadReturnsTheRowsWhenItRuns(): void
    {
        $managed   = $this->link(701, 'ManagedAddr111111111111111111111111111111', self::CHAIN_ID, 1);
        $unmanaged = $this->link(702, 'UnmanagedAddr1111111111111111111111111111', self::CHAIN_ID, 0);
        $this->link(703, 'OtherChainAddr111111111111111111111111111', self::OTHER_CHAIN, 1);

        self::assertSame(
            [['id' => $managed, 'wallet_address' => 'ManagedAddr111111111111111111111111111111']],
            WalletRepository::listForChainBySolanaSyncOrThrow(self::CHAIN_ID, true)
        );
        self::assertSame(
            [['id' => $unmanaged, 'wallet_address' => 'UnmanagedAddr1111111111111111111111111111']],
            WalletRepository::listForChainBySolanaSyncOrThrow(self::CHAIN_ID, false)
        );
    }

    public function testTheThrowingListReadThrowsWhenItCannotRun(): void
    {
        $this->withTableMissing(function (): void {
            try {
                WalletRepository::listForChainBySolanaSyncOrThrow(self::CHAIN_ID, true);
                self::fail('a failed read must throw, not return []');
            } catch (RepositoryReadFailure $e) {
                self::assertSame('listForChainBySolanaSyncOrThrow', $e->repositoryMethod());
            }
        });
    }

    public function testTheFailSafeListReadStillReturnsEmptyOnFailure(): void
    {
        // Unchanged for status(): a failed count reads as zero there.
        $this->withTableMissing(function (): void {
            self::assertSame([], WalletRepository::listForChainBySolanaSync(self::CHAIN_ID, true));
        });
    }

    public function testCountOtherLinksExcludesTheGivenRowAndOtherChains(): void
    {
        $mine = $this->link(711, self::ADDR);
        $this->link(712, self::ADDR, self::OTHER_CHAIN);

        self::assertSame(0, WalletRepository::countOtherLinksForChainAddress(self::CHAIN_ID, self::ADDR, $mine));
        self::assertSame(1, WalletRepository::countOtherLinksForChainAddress(self::CHAIN_ID, self::ADDR, 0), 'the deleted-row case passes 0');
    }

    public function testCountOtherLinksMatchesTheAddressExactly(): void
    {
        // A case variant is a DIFFERENT Solana key, though the collation would
        // call it equal.
        $this->link(721, self::ADDR);

        self::assertSame(0, WalletRepository::countOtherLinksForChainAddress(self::CHAIN_ID, strtolower(self::ADDR), 0));
        self::assertSame(0, WalletRepository::countOtherLinksForChainAddress(self::CHAIN_ID, strtoupper(self::ADDR), 0));
        self::assertSame(1, WalletRepository::countOtherLinksForChainAddress(self::CHAIN_ID, self::ADDR, 0));
    }

    public function testCountOtherLinksThrowsWhenItCannotRun(): void
    {
        $this->withTableMissing(function (): void {
            $this->expectException(RepositoryReadFailure::class);
            WalletRepository::countOtherLinksForChainAddress(self::CHAIN_ID, self::ADDR, 0);
        });
    }
}
