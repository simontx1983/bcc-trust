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

    private function claim(int $userId, string $type, int $entityId, string $wallet, int $chainId, string $status, string $role = 'operator', string $method = 'wallet'): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(ClaimRepository::table(), [
            'user_id'             => $userId,
            'entity_type'         => $type,
            'entity_id'           => $entityId,
            'wallet_address'      => $wallet,
            'chain_id'            => $chainId,
            'claim_role'          => $role,
            'status'              => $status,
            'verified_at'         => $status === 'verified' ? gmdate('Y-m-d H:i:s') : null,
            'verification_method' => $method,
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

    /**
     * A staff-approved claim stores no wallet ('' / chain 0) and is never a
     * wallet's claim: an unlink neither revokes nor purges it, whatever the
     * address, while an account deletion (no wallet) still revokes it.
     */
    public function testAStaffClaimBesideAWalletClaimIsNeverAWalletsClaim(): void
    {
        $walletClaim = $this->claim(self::USER, 'validator', 9201, 'cosmos1aaa', 7, 'verified');
        $staffClaim  = $this->claim(self::USER, 'validator', 9202, '', 0, 'verified', 'operator', 'staff');
        $siwsClaim   = $this->claim(self::USER, 'validator', 9203, 'cosmos1aaa', 7, 'verified', 'operator', 'siws');

        self::assertSame([$walletClaim], ClaimRepository::verifiedEntityClaimIdsForUser(self::USER, 'cosmos1aaa', 7));
        self::assertSame([$walletClaim], ClaimRepository::verifiedEntityClaimIdsForUser(self::USER, 'cosmos1aaa'));
        self::assertSame([$walletClaim, $staffClaim, $siwsClaim], ClaimRepository::verifiedEntityClaimIdsForUser(self::USER), 'an account deletion revokes every claim');

        self::assertSame(1, ClaimRepository::deleteByUserAndWallet(self::USER, 'cosmos1aaa', 7), 'only the wallet claim is purged');
        global $wpdb;
        $left = $wpdb->get_col($wpdb->prepare('SELECT id FROM `' . ClaimRepository::table() . '` WHERE user_id = %d ORDER BY id', self::USER));
        self::assertSame([(string) $staffClaim, (string) $siwsClaim], $left);
    }

    public function testABlankAddressIsRefusedByTheReadAndThePurge(): void
    {
        $staffClaim = $this->claim(self::USER, 'validator', 9211, '', 0, 'verified', 'operator', 'staff');

        foreach (['', '   '] as $blank) {
            foreach ([
                static fn () => ClaimRepository::verifiedEntityClaimIdsForUser(self::USER, $blank),
                static fn () => ClaimRepository::verifiedEntityClaimIdsForUser(self::USER, $blank, 0),
                static fn () => ClaimRepository::deleteByUserAndWallet(self::USER, $blank),
                static fn () => ClaimRepository::deleteByUserAndWallet(self::USER, $blank, 0),
            ] as $call) {
                try {
                    $call();
                    self::fail('a blank address must be refused');
                } catch (\InvalidArgumentException) {
                }
            }
        }

        global $wpdb;
        self::assertSame([(string) $staffClaim], $wpdb->get_col($wpdb->prepare('SELECT id FROM `' . ClaimRepository::table() . '` WHERE user_id = %d', self::USER)));
    }

    public function testMoreThanTheBoundIsRefusedNotTruncated(): void
    {
        for ($i = 0; $i <= ClaimRepository::REVOKE_ALL_MAX; $i++) {
            $this->claim(self::USER, 'validator', 920000 + $i, 'cosmos1aaa', 7, 'verified');
        }

        try {
            ClaimRepository::verifiedEntityClaimIdsForUser(self::USER);
            self::fail('more than the bound must be refused');
        } catch (RepositoryReadFailure $e) {
            self::assertStringContainsString('wp bcc-trust claims-revoke-user ' . self::USER, $e->getMessage(), 'the refusal tells ops what to run');
        }

        $batch = ClaimRepository::verifiedEntityClaimIdsBatch(self::USER, ClaimRepository::REVOKE_ALL_MAX);
        self::assertCount(ClaimRepository::REVOKE_ALL_MAX, $batch, 'the batch read answers a full batch instead');
        $sorted = $batch;
        sort($sorted);
        self::assertSame($sorted, $batch, 'lowest ids first');
    }
}
