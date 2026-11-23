<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-521: ClaimRepository::verifiedEntityClaimIdsForUser(), on a real engine —
 * the read a wallet unlink and an account deletion revoke from before they
 * purge. Only VERIFIED validator/collection claims; the same wallet/chain
 * match as deleteByUserAndWallet(); fail-closed and bounded.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(ClaimRepository::class)]
final class ClaimRevokeAllIdsIntegrationTest extends TestCase
{
    private const USER  = 921001;
    private const OTHER = 921002;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wpdb']->clearFaultInjection();
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM `' . ClaimRepository::table() . '` WHERE user_id IN (%d, %d)', self::USER, self::OTHER));
    }

    private function claim(int $userId, string $type, int $entityId, string $wallet, int $chainId, string $status, string $role = 'operator'): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(ClaimRepository::table(), [
            'user_id'        => $userId,
            'entity_type'    => $type,
            'entity_id'      => $entityId,
            'wallet_address' => $wallet,
            'chain_id'       => $chainId,
            'claim_role'     => $role,
            'status'         => $status,
            'verified_at'    => $status === 'verified' ? gmdate('Y-m-d H:i:s') : null,
        ]), $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    public function testOnlyVerifiedEntityClaimsAreReturnedAndScopedByWalletAndChain(): void
    {
        $onWalletChain7 = $this->claim(self::USER, 'validator', 9101, 'cosmos1aaa', 7, 'verified');
        $onWalletChain8 = $this->claim(self::USER, 'validator', 9102, 'cosmos1aaa', 8, 'verified');
        $collection     = $this->claim(self::USER, 'collection', 9103, 'cosmos1aaa', 7, 'verified', 'creator');
        $otherWallet    = $this->claim(self::USER, 'validator', 9104, 'cosmos1bbb', 7, 'verified');
        $this->claim(self::USER, 'validator', 9105, 'cosmos1aaa', 7, 'revoked');
        $this->claim(self::USER, 'validator', 9106, 'cosmos1aaa', 7, 'pending');
        $this->claim(self::USER, 'page', 9107, 'cosmos1aaa', 7, 'verified'); // a page mirror: revoke() handles it
        $this->claim(self::OTHER, 'validator', 9108, 'cosmos1aaa', 7, 'verified');

        self::assertSame(
            [$onWalletChain7, $collection],
            ClaimRepository::verifiedEntityClaimIdsForUser(self::USER, 'cosmos1aaa', 7),
            'one wallet on one chain'
        );
        self::assertSame(
            [$onWalletChain7, $onWalletChain8, $collection],
            ClaimRepository::verifiedEntityClaimIdsForUser(self::USER, 'cosmos1aaa'),
            'one wallet, any chain (an unresolved chain id)'
        );
        self::assertSame(
            [$onWalletChain7, $onWalletChain8, $collection, $otherWallet],
            ClaimRepository::verifiedEntityClaimIdsForUser(self::USER),
            'every verified entity claim (an account deletion)'
        );
        self::assertSame([], ClaimRepository::verifiedEntityClaimIdsForUser(0));
    }

    public function testAFailedReadThrowsRatherThanAnsweringNone(): void
    {
        $this->claim(self::USER, 'validator', 9111, 'cosmos1aaa', 7, 'verified');
        $GLOBALS['wpdb']->failQueriesMatching = '/' . preg_quote(ClaimRepository::table(), '/') . '/';

        $this->expectException(RepositoryReadFailure::class);
        ClaimRepository::verifiedEntityClaimIdsForUser(self::USER);
    }

    public function testMoreThanTheBoundIsRefusedNotTruncated(): void
    {
        for ($i = 0; $i <= ClaimRepository::REVOKE_ALL_MAX; $i++) {
            $this->claim(self::USER, 'validator', 920000 + $i, 'cosmos1aaa', 7, 'verified');
        }

        $this->expectException(RepositoryReadFailure::class);
        ClaimRepository::verifiedEntityClaimIdsForUser(self::USER);
    }
}
