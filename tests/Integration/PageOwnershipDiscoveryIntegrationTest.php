<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Repositories\ChainRepository;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The aligner's discovery read on a real engine: a validator page is found
 * only when its author has DEPARTED (their account is gone, or their owner
 * row on that page is revoked) and holds no verified claim bound to it. A
 * hand-reassigned page with a living author who never owned it through a
 * claim, and a page whose author still holds it, are never found.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(ClaimRepository::class)]
final class PageOwnershipDiscoveryIntegrationTest extends TestCase
{
    private const GONE      = 951001; // no wp_users row
    private const RELEASED  = 951002; // revoked owner row on its page
    private const REASSIGN  = 951003; // living, no claim history
    private const HOLDER    = 951004; // still holds the page
    private const P_GONE    = 951101;
    private const P_RELEASE = 951102;
    private const P_HAND    = 951103;
    private const P_HELD    = 951104;

    /** @var array<int, int> page => validator */
    private array $validators = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();
        global $wpdb;
        foreach ([self::RELEASED, self::REASSIGN, self::HOLDER] as $id) {
            $wpdb->insert($wpdb->users, ['ID' => $id, 'user_login' => 'pod_' . $id]);
        }
        $wpdb->insert(ChainRepository::table(), ['slug' => 'pod-chain', 'name' => 'POD', 'chain_type' => 'cosmos']);
        $chainId = (int) $wpdb->insert_id;
        foreach ([self::P_GONE => self::GONE, self::P_RELEASE => self::RELEASED, self::P_HAND => self::REASSIGN, self::P_HELD => self::HOLDER] as $page => $author) {
            $wpdb->insert($wpdb->posts, ['ID' => $page, 'post_type' => 'peepso-page', 'post_status' => 'publish', 'post_author' => $author]);
            $wpdb->insert($wpdb->prefix . 'bcc_onchain_validators', [
                'wallet_link_id' => null, 'operator_address' => 'cosmosvaloper1pod' . uniqid(), 'chain_id' => $chainId,
                'moniker' => 'POD Fixture', 'expires_at' => gmdate('Y-m-d H:i:s', time() + 86400),
            ]);
            $this->validators[$page] = (int) $wpdb->insert_id;
            $wpdb->insert($wpdb->postmeta, ['post_id' => $page, 'meta_key' => '_bcc_onchain_validator_id', 'meta_value' => (string) $this->validators[$page]]);
        }
        $this->claim(self::RELEASED, 'page', self::P_RELEASE, 'revoked');
        $this->claim(self::RELEASED, 'validator', $this->validators[self::P_RELEASE], 'revoked');
        $this->claim(self::HOLDER, 'validator', $this->validators[self::P_HELD], 'verified');
        $this->claim(self::HOLDER, 'page', self::P_HELD, 'revoked'); // an old revoked row does not matter while it holds the page
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        global $wpdb;
        $ids   = [self::GONE, self::RELEASED, self::REASSIGN, self::HOLDER];
        $pages = [self::P_GONE, self::P_RELEASE, self::P_HAND, self::P_HELD];
        $wpdb->query($wpdb->prepare('DELETE FROM `' . ClaimRepository::table() . '` WHERE user_id IN (%d, %d, %d, %d)', ...$ids));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->users}` WHERE ID IN (%d, %d, %d, %d)", ...$ids));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->posts}` WHERE ID IN (%d, %d, %d, %d)", ...$pages));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->postmeta}` WHERE post_id IN (%d, %d, %d, %d) AND meta_key = '_bcc_onchain_validator_id'", ...$pages));
        $wpdb->query("DELETE FROM `{$wpdb->prefix}bcc_onchain_validators` WHERE moniker = 'POD Fixture'");
        $wpdb->query("DELETE FROM `" . ChainRepository::table() . "` WHERE slug = 'pod-chain'");
    }

    private function claim(int $userId, string $type, int $entity, string $status): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(ClaimRepository::table(), [
            'user_id' => $userId, 'entity_type' => $type, 'entity_id' => $entity,
            'wallet_address' => 'cosmos1' . $userId, 'chain_id' => 7, 'claim_role' => 'operator',
            'status' => $status, 'verification_method' => 'wallet', 'verified_at' => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
    }

    public function testOnlyDepartedAuthorsPagesAreFound(): void
    {
        self::assertSame([self::P_GONE, self::P_RELEASE], ClaimRepository::pagesOwnedByDepartedAuthors(self::P_GONE - 1, 20));
    }

    /** The approved refinement: a hand-reassigned page is never queued. */
    public function testAHandReassignedPageWithALivingAuthorIsNeverFound(): void
    {
        self::assertNotContains(self::P_HAND, ClaimRepository::pagesOwnedByDepartedAuthors(0, 100));
    }

    public function testAnAuthorWhoStillHoldsThePageIsNeverFound(): void
    {
        self::assertNotContains(self::P_HELD, ClaimRepository::pagesOwnedByDepartedAuthors(0, 100));
    }

    public function testTheCursorAndTheLimitBoundEachPass(): void
    {
        self::assertSame([self::P_GONE], ClaimRepository::pagesOwnedByDepartedAuthors(self::P_GONE - 1, 1));
        self::assertSame([self::P_RELEASE], ClaimRepository::pagesOwnedByDepartedAuthors(self::P_GONE, 20));
    }
}
