<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Services\PageClaimRolePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The reads behind the page card's owner_verification and viewer_page_role,
 * on a real engine: the owner resolution also carries how the owner proved
 * it (only for a RESOLVED page, only the owner's own claims), in the same
 * query; and a viewer's ACTIVE team row gives their role, never an owner one.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(ClaimRepository::class)]
final class PageOwnerVerificationReadIntegrationTest extends TestCase
{
    private const OWNER = 941001;
    private const OTHER = 941002;
    private const REP   = 941003;
    private const PAGE  = 941101;
    private const PAGE2 = 941102;

    /** @var list<int> */
    private array $validatorIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();
        global $wpdb;
        $wpdb->insert(ChainRepository::table(), ['slug' => 'ovr-chain', 'name' => 'OVR', 'chain_type' => 'cosmos']);
        $chainId = (int) $wpdb->insert_id;
        foreach ([self::PAGE, self::PAGE2] as $page) {
            $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
                'wallet_link_id' => null, 'operator_address' => 'cosmosvaloper1ovr' . uniqid(), 'chain_id' => $chainId,
                'moniker' => 'OVR Fixture', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
            ]);
            $validatorId          = (int) $wpdb->insert_id;
            $this->validatorIds[] = $validatorId;
            $wpdb->insert($wpdb->postmeta, ['post_id' => $page, 'meta_key' => '_bcc_onchain_validator_id', 'meta_value' => (string) $validatorId]);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM `' . ClaimRepository::table() . '` WHERE user_id IN (%d, %d, %d)', self::OWNER, self::OTHER, self::REP));
        $wpdb->query("DELETE FROM `{$wpdb->prefix}bcc_onchain_validators` WHERE moniker = 'OVR Fixture'");
        $wpdb->query("DELETE FROM `" . ChainRepository::table() . "` WHERE slug = 'ovr-chain'");
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->postmeta}` WHERE post_id IN (%d, %d) AND meta_key = '_bcc_onchain_validator_id'", self::PAGE, self::PAGE2));
    }

    private function claim(int $userId, string $type, int $entity, string $role, string $status, string $method): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(ClaimRepository::table(), [
            'user_id' => $userId, 'entity_type' => $type, 'entity_id' => $entity,
            'wallet_address' => $method === 'staff' ? '' : 'cosmos1' . $userId, 'chain_id' => $method === 'staff' ? 0 : 7,
            'claim_role' => $role, 'status' => $status, 'verification_method' => $method,
            'verified_at' => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
    }

    public function testAResolvedOwnerCarriesTheMethodOfTheirClaim(): void
    {
        $this->claim(self::OWNER, 'validator', $this->validatorIds[0], 'operator', 'verified', 'staff');

        $resolved = ClaimRepository::resolveVerifiedOperatorsWithMethodsForPages([self::PAGE, self::PAGE2]);

        self::assertSame(['state' => 'RESOLVED', 'user_id' => self::OWNER], $resolved['operators'][self::PAGE] ?? null);
        self::assertSame(['staff'], $resolved['methods'][self::PAGE] ?? null);
        self::assertArrayNotHasKey(self::PAGE2, $resolved['methods'], 'an unclaimed page has no method, so no badge');
        self::assertSame($resolved['operators'], ClaimRepository::resolveVerifiedOperatorForPages([self::PAGE, self::PAGE2]), 'the same resolution as the card resolver');
    }

    public function testAnAmbiguousPageCarriesNoMethod(): void
    {
        $this->claim(self::OWNER, 'validator', $this->validatorIds[0], 'operator', 'verified', 'wallet');
        $this->claim(self::OTHER, 'validator', $this->validatorIds[0], 'operator', 'verified', 'staff');

        $resolved = ClaimRepository::resolveVerifiedOperatorsWithMethodsForPages([self::PAGE]);

        self::assertSame('AMBIGUOUS', $resolved['operators'][self::PAGE]['state'] ?? null);
        self::assertSame([], $resolved['methods'], 'no badge for a contested page');
    }

    public function testOnlyAnActiveTeamRowGivesTheViewerARole(): void
    {
        $this->claim(self::REP, 'page', self::PAGE, 'representative', 'active', 'staff');
        $this->claim(self::REP, 'page', self::PAGE2, 'administrator', 'invited', 'staff');

        self::assertNull(ClaimRepository::getUserClaim(self::REP, 'page', self::PAGE, PageClaimRolePolicy::OWNER_ROLES), 'not an owner row');
        self::assertSame([self::PAGE => 'representative'], ClaimRepository::activeTeamRolesOnPages(self::REP, [self::PAGE, self::PAGE2]), 'an invite holds no role');
        self::assertSame([], ClaimRepository::activeTeamRolesOnPages(self::OWNER, [self::PAGE]));
        $m = new \ReflectionMethod(CardViewService::class, 'viewerPageRole');
        self::assertSame('representative', $m->invoke(null, false, 'representative'));
    }
}
