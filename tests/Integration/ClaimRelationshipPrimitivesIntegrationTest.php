<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Security\TransactionManager;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The claim primitives the validator relationship service composes inside
 * ONE transaction, on a real engine: a staff/proof owner claim and its page
 * mirror (never an upsert, never over a verified claim, a team row on the
 * page reported when it becomes the owner row); the in-transaction revoke;
 * and the fail-closed operator resolver and page-hold check.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(ClaimRepository::class)]
final class ClaimRelationshipPrimitivesIntegrationTest extends TestCase
{
    private const OWNER = 961001;
    private const STAFF = 961002;
    private const PAGE  = 961101;

    private int $validatorId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
            'wallet_link_id'   => null,
            'operator_address' => 'cosmosvaloper1primitive' . uniqid(),
            'chain_id'         => 7,
            'moniker'          => 'Primitive Fixture',
            'expires_at'       => gmdate('Y-m-d H:i:s', time() + 86400),
        ]);
        $this->validatorId = (int) $wpdb->insert_id;
        $wpdb->insert($wpdb->postmeta, ['post_id' => self::PAGE, 'meta_key' => '_bcc_onchain_validator_id', 'meta_value' => (string) $this->validatorId]);
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
        $wpdb->query($wpdb->prepare('DELETE FROM `' . ClaimRepository::table() . '` WHERE user_id = %d', self::OWNER));
        $wpdb->query("DELETE FROM `{$wpdb->prefix}bcc_onchain_validators` WHERE moniker = 'Primitive Fixture'");
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->postmeta}` WHERE post_id = %d AND meta_key = '_bcc_onchain_validator_id'", self::PAGE));
    }

    /** @return array<string, mixed> */
    private function writeStaff(): array
    {
        return TransactionManager::run(fn (): array => ClaimRepository::writeRelationshipClaim(
            self::OWNER, 'validator', $this->validatorId, 'operator', 'staff', self::STAFF, '', 0,
            ClaimRepository::boundPagesFor('validator', $this->validatorId)
        ));
    }

    /** @return array<string, string|null>|null */
    private static function row(string $type, int $entityId): ?array
    {
        global $wpdb;
        /** @var array<string, string|null>|null $row */
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, wallet_address, chain_id, claim_role, status, verification_method, verified_by FROM `'
            . ClaimRepository::table() . '` WHERE user_id = %d AND entity_type = %s AND entity_id = %d',
            self::OWNER, $type, $entityId
        ), ARRAY_A);

        return $row;
    }

    private function seed(string $type, int $entityId, string $role, string $status, string $method = 'wallet'): int
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(ClaimRepository::table(), [
            'user_id' => self::OWNER, 'entity_type' => $type, 'entity_id' => $entityId,
            'wallet_address' => $method === 'wallet' ? 'cosmos1own' : '', 'chain_id' => $method === 'wallet' ? 7 : 0,
            'claim_role' => $role, 'status' => $status, 'verification_method' => $method,
        ]), $wpdb->last_error);

        return (int) $wpdb->insert_id;
    }

    public function testAStaffClaimAndItsMirrorAreWrittenTogether(): void
    {
        $result = $this->writeStaff();

        self::assertTrue($result['ok']);
        self::assertSame([self::PAGE], $result['mirror_page_ids']);
        self::assertSame([], $result['converted_team_roles']);
        foreach ([['validator', $this->validatorId], ['page', self::PAGE]] as [$type, $id]) {
            $row = self::row($type, $id);
            self::assertSame(['', '0', 'operator', 'verified', 'staff', (string) self::STAFF], [
                $row['wallet_address'] ?? null, $row['chain_id'] ?? null, $row['claim_role'] ?? null,
                $row['status'] ?? null, $row['verification_method'] ?? null, $row['verified_by'] ?? null,
            ], $type);
        }
        self::assertSame(['state' => 'RESOLVED', 'user_id' => self::OWNER], ClaimRepository::resolveVerifiedOperatorForPageStrict(self::PAGE));
        self::assertTrue(ClaimRepository::userHoldsPageThroughAnyClaim(self::OWNER, self::PAGE));
    }

    public function testARollbackLeavesNothing(): void
    {
        try {
            TransactionManager::run(function (): void {
                ClaimRepository::writeRelationshipClaim(self::OWNER, 'validator', $this->validatorId, 'operator', 'staff', self::STAFF, '', 0, [self::PAGE]);
                throw new \RuntimeException('the log append failed');
            });
            self::fail('must rethrow');
        } catch (\RuntimeException $e) {
            self::assertSame('the log append failed', $e->getMessage());
        }

        self::assertNull(self::row('validator', $this->validatorId));
        self::assertNull(self::row('page', self::PAGE));
    }

    public function testThePrimitivesRefuseToRunOutsideATransaction(): void
    {
        foreach ([
            fn () => ClaimRepository::writeRelationshipClaim(self::OWNER, 'validator', $this->validatorId, 'operator', 'staff', self::STAFF, '', 0, []),
            fn () => ClaimRepository::revokeInTransaction(1),
        ] as $call) {
            try {
                $call();
                self::fail('must refuse outside a transaction');
            } catch (\LogicException) {
                self::assertNull(self::row('validator', $this->validatorId));
            }
        }
    }

    public function testAVerifiedClaimIsNeverOverwritten(): void
    {
        $this->seed('validator', $this->validatorId, 'operator', 'verified');

        self::assertSame(['ok' => false, 'reason' => 'already_verified'], $this->writeStaff());
        self::assertSame('wallet', self::row('validator', $this->validatorId)['verification_method'] ?? null);
    }

    public function testARevokedRowIsReusedNotDuplicated(): void
    {
        $id = $this->seed('validator', $this->validatorId, 'operator', 'revoked');

        $result = $this->writeStaff();

        self::assertSame($id, $result['claim_id']);
        self::assertSame(['verified', 'staff', ''], [
            self::row('validator', $this->validatorId)['status'] ?? null,
            self::row('validator', $this->validatorId)['verification_method'] ?? null,
            self::row('validator', $this->validatorId)['wallet_address'] ?? null,
        ]);
    }

    public function testATeamRowOnThePageBecomesTheOwnerRowAndIsReported(): void
    {
        $this->seed('page', self::PAGE, 'administrator', 'active', 'staff');

        $result = $this->writeStaff();

        self::assertSame([self::PAGE => 'administrator'], $result['converted_team_roles']);
        self::assertSame(['operator', 'verified'], [self::row('page', self::PAGE)['claim_role'] ?? null, self::row('page', self::PAGE)['status'] ?? null]);
    }

    public function testInvalidMethodAndWalletCombinationsAreRefused(): void
    {
        $refused = 0;
        foreach ([
            ['staff', self::STAFF, 'cosmos1x', 7],   // staff with a wallet
            ['staff', null, '', 0],                  // staff without an approver
            ['siws', null, '', 0],                   // a proof without an address
            ['siws', self::STAFF, 'sol1x', 9],       // a proof naming an approver
            ['wallet', null, 'cosmos1x', 7],         // the wallet path is upsert()
        ] as [$method, $by, $wallet, $chain]) {
            try {
                TransactionManager::run(fn () => ClaimRepository::writeRelationshipClaim(self::OWNER, 'validator', $this->validatorId, 'operator', $method, $by, $wallet, $chain, []));
                self::fail("{$method} must be refused");
            } catch (\InvalidArgumentException) {
                ++$refused;
            }
        }
        self::assertSame(5, $refused);
        self::assertNull(self::row('validator', $this->validatorId));
    }

    public function testTheInTransactionRevokeFlipsWithTheCallerAndRollsBack(): void
    {
        $claimId = $this->writeStaff()['claim_id'];

        try {
            TransactionManager::run(function () use ($claimId): void {
                $r = ClaimRepository::revokeInTransaction($claimId);
                self::assertSame([self::PAGE], $r['mirror_page_ids']);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }
        self::assertSame('verified', self::row('validator', $this->validatorId)['status'] ?? null, 'rolled back with the caller');

        $r = TransactionManager::run(fn (): array => ClaimRepository::revokeInTransaction($claimId));
        self::assertTrue($r['revoked']);
        self::assertSame('revoked', self::row('validator', $this->validatorId)['status'] ?? null);
        self::assertSame('revoked', self::row('page', self::PAGE)['status'] ?? null, 'the mirror flips with it');
        self::assertFalse(ClaimRepository::userHoldsPageThroughAnyClaim(self::OWNER, self::PAGE));
    }

    public function testTheStrictResolverAndHoldCheckThrowWhenTheReadFails(): void
    {
        $this->writeStaff();
        $GLOBALS['wpdb']->failQueriesMatching = '/' . preg_quote(ClaimRepository::table(), '/') . '/';

        self::assertSame('UNCLAIMED', ClaimRepository::resolveVerifiedOperatorForPage(self::PAGE)['state'], 'the lenient resolver reads a failure as unclaimed');
        foreach ([
            static fn () => ClaimRepository::resolveVerifiedOperatorForPageStrict(self::PAGE),
            static fn () => ClaimRepository::userHoldsPageThroughAnyClaim(self::OWNER, self::PAGE),
        ] as $call) {
            try {
                $call();
                self::fail('a failed read must throw');
            } catch (RepositoryReadFailure) {
                self::assertTrue(true);
            }
        }
    }
}
