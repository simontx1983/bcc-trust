<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ClaimRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * ClaimRepository::upsert() is the wallet claim path. When it lands on an
 * existing row (the user/entity unique key), the row becomes a wallet claim:
 * a once staff-approved claim re-claimed by wallet is not left as 'staff'
 * (which a wallet unlink and the re-verify sweep would skip, leaving the
 * entity held with nothing behind it), and a page team row that becomes
 * the owner row loses its team columns.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(ClaimRepository::class)]
final class ClaimUpsertIsTheWalletPathIntegrationTest extends TestCase
{
    private const USER = 941001;

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
        $wpdb->query($wpdb->prepare('DELETE FROM `' . ClaimRepository::table() . '` WHERE user_id = %d', self::USER));
    }

    /** @return array<string, string|null> */
    private static function row(int $id): array
    {
        global $wpdb;
        /** @var array<string, string|null> $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT wallet_address, chain_id, claim_role, status, verification_method, verified_by, invited_by,
                    invite_expires_at, accepted_at, added_by_staff, reconfirmed, show_publicly
               FROM `' . ClaimRepository::table() . '` WHERE id = %d',
            $id
        ), ARRAY_A);

        return $row;
    }

    public function testAStaffApprovedClaimReClaimedByWalletBecomesAWalletClaim(): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(ClaimRepository::table(), [
            'user_id' => self::USER, 'entity_type' => 'validator', 'entity_id' => 9301,
            'wallet_address' => '', 'chain_id' => 0, 'claim_role' => 'operator', 'status' => 'revoked',
            'verification_method' => 'staff', 'verified_by' => 77,
        ]), $wpdb->last_error);
        $id = (int) $wpdb->insert_id;

        $result = ClaimRepository::upsert(self::USER, 'validator', 9301, 'cosmos1www', 7, 'operator');

        self::assertIsArray($result);
        self::assertSame($id, $result['id'], 'the same row (the unique key)');
        $row = self::row($id);
        self::assertSame('cosmos1www', $row['wallet_address']);
        self::assertSame('verified', $row['status']);
        self::assertSame('wallet', $row['verification_method'], 'no longer staff-approved');
        self::assertNull($row['verified_by']);
    }

    public function testATeamRowThatBecomesTheOwnerRowLosesItsTeamColumns(): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(ClaimRepository::table(), [
            'user_id' => self::USER, 'entity_type' => 'page', 'entity_id' => 9302,
            'wallet_address' => '', 'chain_id' => 0, 'claim_role' => 'administrator', 'status' => 'active',
            'verification_method' => 'staff', 'invited_by' => 55, 'invite_expires_at' => '2026-12-01 00:00:00',
            'accepted_at' => '2026-11-24 00:00:00', 'added_by_staff' => 1, 'reconfirmed' => 1, 'show_publicly' => 1,
        ]), $wpdb->last_error);
        $id = (int) $wpdb->insert_id;

        ClaimRepository::upsert(self::USER, 'page', 9302, 'cosmos1www', 7, 'operator');

        self::assertSame([
            'wallet_address' => 'cosmos1www', 'chain_id' => '7', 'claim_role' => 'operator', 'status' => 'verified',
            'verification_method' => 'wallet', 'verified_by' => null, 'invited_by' => null,
            'invite_expires_at' => null, 'accepted_at' => null, 'added_by_staff' => '0', 'reconfirmed' => '0',
            'show_publicly' => '0',
        ], self::row($id));
    }

    public function testANewRowIsAWalletClaim(): void
    {
        $result = ClaimRepository::upsert(self::USER, 'validator', 9303, 'cosmos1www', 7, 'operator');

        self::assertIsArray($result);
        self::assertSame('wallet', self::row($result['id'])['verification_method']);
    }
}
