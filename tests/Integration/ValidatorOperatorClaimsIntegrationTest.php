<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ClaimRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Pins ClaimRepository::listVerifiedValidatorOperatorClaims() — the
 * work-list behind the delegator-community provisioning backfill — against
 * a real MySQL, exercising the genuine INNER JOIN + filters.
 *
 * The load-bearing assertions:
 *   1. A verified validator-operator claim whose validator row EXISTS is
 *      returned (this is what mints a community).
 *   2. A claim whose validator row is GONE is excluded — the INNER JOIN is
 *      what stops a stale claim from minting a group for a deleted
 *      validator.
 *   3. Wrong role (creator/holder), wrong entity_type (collection), and
 *      non-verified status are all excluded — a community is armed only by
 *      a VERIFIED OPERATOR claim, which is the wallet-signature proof the
 *      design leans on.
 *   4. The LIMIT is honoured and ordering is deterministic (id ASC), so the
 *      bounded backfill makes forward progress instead of re-reading a
 *      random slice.
 */
#[Group('integration')]
#[CoversClass(ClaimRepository::class)]
final class ValidatorOperatorClaimsIntegrationTest extends TestCase
{
    private const USER_ID  = 240;
    private const CHAIN_ID = 8;

    protected function setUp(): void
    {
        global $wpdb;
        foreach (['bcc_onchain_claims', 'bcc_onchain_validators', 'bcc_onchain_collections'] as $t) {
            $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . $t . '`');
        }
    }

    private function insertValidator(string $moniker = 'Fixture Validator'): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'wallet_link_id'   => null,
            'operator_address' => 'cosmosvaloper1fixture' . uniqid(),
            'chain_id'         => self::CHAIN_ID,
            'moniker'          => $moniker,
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        return (int) $wpdb->insert_id;
    }

    private function insertCollection(): int
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bcc_onchain_collections', [
            'wallet_link_id'   => null,
            'contract_address' => '0xfixture' . uniqid(),
            'chain_id'         => self::CHAIN_ID,
            'collection_name'  => 'Fixture Collection',
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        return (int) $wpdb->insert_id;
    }

    /** Verified fixtures go through the REAL write path the live claim flow uses. */
    private function insertVerifiedClaim(
        string $entityType,
        int $entityId,
        string $role,
        int $userId = self::USER_ID
    ): void {
        $result = ClaimRepository::upsert(
            $userId,
            $entityType,
            $entityId,
            'cosmos1fixture' . $userId,
            self::CHAIN_ID,
            $role,
            'verified'
        );
        self::assertNotFalse($result, 'fixture claim upsert must succeed');
    }

    /**
     * Non-verified rows are inserted directly: upsert()'s WP-style prepare()
     * renders the NULL verified_at as '', which strict-mode MySQL rejects for
     * DATETIME. Mirrors UserVerifiedClaimOnPageIntegrationTest's rationale.
     */
    private function insertPendingClaim(string $entityType, int $entityId, string $role): void
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bcc_onchain_claims', [
            'user_id'        => self::USER_ID,
            'entity_type'    => $entityType,
            'entity_id'      => $entityId,
            'wallet_address' => 'cosmos1fixture' . self::USER_ID,
            'chain_id'       => self::CHAIN_ID,
            'claim_role'     => $role,
            'status'         => 'pending',
            'created_at'     => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<int> entity_ids returned by the query */
    private static function entityIds(int $limit = 200): array
    {
        return array_map(
            static fn(object $row): int => (int) $row->entity_id,
            ClaimRepository::listVerifiedValidatorOperatorClaims($limit)
        );
    }

    public function testReturnsVerifiedOperatorClaimWithLiveValidator(): void
    {
        $validatorId = $this->insertValidator();
        $this->insertVerifiedClaim('validator', $validatorId, 'operator');

        self::assertSame([$validatorId], self::entityIds());
    }

    public function testExcludesClaimWhoseValidatorRowIsGone(): void
    {
        $validatorId = $this->insertValidator();
        $this->insertVerifiedClaim('validator', $validatorId, 'operator');

        global $wpdb;
        $wpdb->query('TRUNCATE TABLE `' . $wpdb->prefix . 'bcc_onchain_validators`');

        // The claim row survives, but the INNER JOIN drops it — a stale
        // claim must never mint a community for a deleted validator.
        self::assertSame([], self::entityIds());
    }

    public function testExcludesNonOperatorRolesAndOtherEntityTypes(): void
    {
        $operatorValidator = $this->insertValidator('Operator Validator');
        $creatorValidator  = $this->insertValidator('Creator Validator');
        $holderValidator   = $this->insertValidator('Holder Validator');
        $collectionId      = $this->insertCollection();

        $this->insertVerifiedClaim('validator', $operatorValidator, 'operator');
        $this->insertVerifiedClaim('validator', $creatorValidator, 'creator');
        $this->insertVerifiedClaim('validator', $holderValidator, 'holder');
        $this->insertVerifiedClaim('collection', $collectionId, 'operator');

        self::assertSame([$operatorValidator], self::entityIds());
    }

    public function testExcludesNonVerifiedStatus(): void
    {
        $validatorId = $this->insertValidator();
        $this->insertPendingClaim('validator', $validatorId, 'operator');

        self::assertSame([], self::entityIds());
    }

    public function testIsBoundedAndOrderedByIdAscending(): void
    {
        $first  = $this->insertValidator('First');
        $second = $this->insertValidator('Second');
        $third  = $this->insertValidator('Third');

        // Distinct users: the claims table is UNIQUE on
        // (user_id, entity_type, entity_id), and operator exclusivity is
        // enforced by createExclusiveClaim (not by this bounded read).
        $this->insertVerifiedClaim('validator', $first, 'operator', 301);
        $this->insertVerifiedClaim('validator', $second, 'operator', 302);
        $this->insertVerifiedClaim('validator', $third, 'operator', 303);

        self::assertSame([$first, $second, $third], self::entityIds());

        // Bounded: the backfill walks a capped slice, oldest claim first.
        self::assertSame([$first, $second], self::entityIds(2));

        // A non-positive limit is a no-op rather than an unbounded read.
        self::assertSame([], self::entityIds(0));
    }
}
