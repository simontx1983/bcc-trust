<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\AdminValidatorReadService as Service;
use BCC\Trust\Onchain\Repositories\AdminValidatorRepository as Reads;
use BCC\Trust\Onchain\Repositories\ClaimRepository as Claims;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminValidatorReadServiceTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/admin-validator-read-stubs.php';
        require_once __DIR__ . '/../../app/Domain/Core/Services/AdminValidatorReadService.php';
        Reads::$bindings = [self::binding(10, 101, 'alpha')];
        Reads::$claims = Reads::$users = Reads::$calls = Claims::$owners = [];
        Reads::$health = Reads::$event = null;
        Reads::$fail = null;
        Claims::$fail = false;
    }

    private static function binding(int $page, int $validator, string $chain): array
    {
        return ['page_id' => $page, 'validator_id' => $validator, 'title' => 'Validator ' . $page,
            'slug' => 'validator-' . $page, 'chain_slug' => $chain, 'chain_name' => ucfirst($chain),
            'chain_type' => 'cosmos', 'operator_address' => 'operator-' . $validator];
    }

    private static function owner(int $page, int $claim, int $user, int $validator, string $method = 'staff'): void
    {
        Claims::$owners[$page][] = ['claim_id' => $claim, 'user_id' => $user, 'verification_method' => $method];
        Reads::$claims[$claim] = ['id' => $claim, 'user_id' => $user, 'entity_type' => 'validator', 'entity_id' => $validator,
            'verification_method' => $method, 'status' => 'verified', 'claim_role' => 'operator',
            'verified_by' => 7, 'verified_at' => '2026-01-01 00:00:00'];
        Reads::$users[$user] = ['user_id' => $user, 'handle' => 'member-' . $user, 'display_name' => 'private-login', 'user_login' => 'private-login'];
    }

    public function testUnknownMethodRemainsLockedAndLoginIsNeverProjected(): void
    {
        self::owner(10, 1, 22, 101, 'future_method');
        $result = Service::relationships(10);
        self::assertTrue($result['owner']['locked_for_staff']);
        self::assertFalse($result['owner']['cancellable']);
        self::assertSame('member-22', $result['owner']['user']['display_name']);
        self::assertStringNotContainsString('private-login', json_encode($result, JSON_THROW_ON_ERROR));
        self::assertNotContains('linkEvent', array_column(Reads::$calls, 0));
    }

    public function testCompetingOwnersAreAConflictWithoutAnActionableOwner(): void
    {
        self::owner(10, 1, 22, 101);
        self::owner(10, 2, 23, 101, 'wallet');
        $result = Service::relationships(10);
        self::assertSame('conflict', $result['state']);
        self::assertNull($result['owner']);
        self::assertCount(2, $result['conflict_users']);
    }

    public function testDeletedAccountCannotBecomeAnUnclaimedPage(): void
    {
        self::owner(10, 1, 22, 101);
        Reads::$users = [];
        $this->expectException(RepositoryReadFailure::class);
        Service::relationships(10);
    }

    public function testClaimFromAnotherValidatorFailsClosed(): void
    {
        self::owner(10, 1, 22, 999);
        $this->expectException(RepositoryReadFailure::class);
        Service::relationships(10);
    }

    public function testClaimResolverFailurePropagates(): void
    {
        Claims::$fail = true;
        $this->expectException(RepositoryReadFailure::class);
        Service::directory('', '', '', 0, 10);
    }

    public function testClaimSnapshotDriftCannotProjectAnotherUserAsOwner(): void
    {
        self::owner(10, 1, 22, 101);
        Reads::$claims[1]['user_id'] = 23;
        $this->expectException(RepositoryReadFailure::class);
        Service::relationships(10);
    }

    public function testAccountReadFailurePropagates(): void
    {
        self::owner(10, 1, 22, 101);
        Reads::$fail = 'users';
        $this->expectException(RepositoryReadFailure::class);
        Service::relationships(10);
    }

    public function testEvidenceUsesTheSelectedClaimsValidatorRatherThanFirstPageBinding(): void
    {
        Reads::$bindings[] = self::binding(10, 102, 'beta');
        self::owner(10, 1, 22, 102);
        Reads::$event = ['created_at' => '2026-01-01 00:00:00', 'actor_user_id' => 7,
            'payload' => '{"evidence_type":"website"}', 'evidence_note' => 'Reviewed ownership', 'evidence_ref' => 'https://example.test/proof'];
        $result = Service::relationships(10);
        self::assertSame(1, $result['owner']['link_id']);
        self::assertSame('Reviewed ownership', $result['owner']['evidence_note']);
        self::assertContains(['linkEvent', [10, 22, 102]], Reads::$calls);
    }

    public function testMultiChainFilterRetainsPageAndCountsUncoveredValidators(): void
    {
        Reads::$bindings[] = self::binding(10, 102, 'beta');
        self::owner(10, 1, 22, 101, 'wallet');
        $result = Service::directory('beta', '', '', 0, 10);
        self::assertSame([10], array_column($result['items'], 'page_id'));
        self::assertSame(1, $result['items'][0]['uncovered_validator_count']);
        $facets = array_column($result['chains'], null, 'slug');
        self::assertSame(1, $facets['alpha']['counts']['wallet_verified']);
        self::assertSame(1, $facets['beta']['counts']['wallet_verified']);
        self::assertSame(1, $result['total_counts']['wallet_verified']);
    }

    public function testCursorAndSearchDoNotChangeFacetsOrTotalCounts(): void
    {
        Reads::$bindings[] = self::binding(20, 201, 'beta');
        Reads::$bindings[] = self::binding(30, 301, 'alpha');
        $first = Service::directory('', '', '', 0, 1);
        self::assertTrue($first['pagination']['has_more']);
        self::assertSame('10', $first['pagination']['next_cursor']);
        $next = Service::directory('', '', '', 10, 1);
        self::assertSame([20], array_column($next['items'], 'page_id'));
        $empty = Service::directory('beta', 'staff_verified', 'no match', 20, 1);
        self::assertSame([], $empty['items']);
        self::assertSame($first['chains'], $empty['chains']);
        self::assertSame($first['total_counts'], $empty['total_counts']);
        self::assertSame(3, $first['total_counts']['unclaimed']);
    }

    public function testStoredHealthDoesNotRunVerifierAndCannotTreatWarningAsHealthy(): void
    {
        self::assertNull(Service::health());
        Reads::$health = ['status' => 'warning', 'chain_ok' => true, 'checked_at' => '2026-01-01 00:00:00', 'rows_checked' => 5, 'head_seq' => 5];
        self::assertFalse(Service::health()['chain_ok']);
        Reads::$health['status'] = 'ok';
        self::assertTrue(Service::health()['chain_ok']);
        self::assertSame(['logHealth', 'logHealth', 'logHealth'], array_column(Reads::$calls, 0));
    }

    public function testDirectoryHydratesOnlySelectedPageWithoutLosingGlobalCounts(): void
    {
        Reads::$bindings[] = self::binding(20, 201, 'beta');
        self::owner(10, 1, 22, 101, 'wallet');
        self::owner(20, 2, 23, 201, 'staff');
        $result = Service::directory('', '', '', 0, 1);
        self::assertSame(1, $result['total_counts']['wallet_verified']);
        self::assertSame(1, $result['total_counts']['staff_verified']);
        foreach (Reads::$calls as [$method, $ids]) {
            if ($method === 'users') {
                self::assertSame([22], array_values(array_unique($ids)));
            }
            if ($method === 'claims') {
                self::assertSame([1], array_values(array_unique($ids)));
            }
        }
        self::assertSame([10], array_column($result['items'], 'page_id'));
    }

    public function testMalformedStoredHealthCannotClaimVerification(): void
    {
        $valid = ['status' => 'ok', 'chain_ok' => true, 'checked_at' => '2026-01-01 00:00:00', 'rows_checked' => 5, 'head_seq' => 5];
        foreach ([['checked_at' => ''], ['checked_at' => 'invalid'], ['rows_checked' => -1], ['head_seq' => -1]] as $invalid) {
            Reads::$health = array_replace($valid, $invalid);
            self::assertNull(Service::health());
        }
    }

    public function testFailedBindingReadCannotBeAnEmptyDirectory(): void
    {
        Reads::$fail = 'bindings';
        $this->expectException(RepositoryReadFailure::class);
        Service::directory('', '', '', 0, 10);
    }
}
