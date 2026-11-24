<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Onchain\Repositories\ClaimRepository;
use BCC\Trust\Onchain\Services\PageClaimRolePolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * D1, on a real engine: a page TEAM row (administrator, representative),
 * even a verified one, is never read as the page's owner. It does not make
 * the page "claimed", it does not make its holder "the claimer" (which
 * drives can_edit_bio, can_edit_image and can_post_as_entity), and the
 * batched readers the cards list uses agree with the single ones.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(ClaimRepository::class)]
final class PageTeamRowsAreNotOwnersIntegrationTest extends TestCase
{
    private const OWNER = 931001;
    private const ADMIN = 931002;
    private const REP   = 931003;

    /** Owned by OWNER, with an administrator and a representative. */
    private const OWNED_PAGE = 931101;
    /** No owner: only team rows. */
    private const TEAM_ONLY_PAGE = 931102;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanUp();
        global $wpdb;
        foreach ([self::OWNER, self::ADMIN, self::REP] as $id) {
            self::assertNotFalse($wpdb->insert($wpdb->users, ['ID' => $id, 'user_login' => 'ptr_' . $id]), $wpdb->last_error);
        }
        $this->pageRow(self::OWNER, self::OWNED_PAGE, 'operator');
        $this->pageRow(self::ADMIN, self::OWNED_PAGE, 'administrator');
        $this->pageRow(self::REP, self::OWNED_PAGE, 'representative');
        $this->pageRow(self::ADMIN, self::TEAM_ONLY_PAGE, 'administrator');
        $this->pageRow(self::REP, self::TEAM_ONLY_PAGE, 'representative');
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        global $wpdb;
        $ids = [self::OWNER, self::ADMIN, self::REP];
        $wpdb->query($wpdb->prepare('DELETE FROM `' . ClaimRepository::table() . '` WHERE user_id IN (%d, %d, %d)', ...$ids));
        $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->users}` WHERE ID IN (%d, %d, %d)", ...$ids));
    }

    private function pageRow(int $userId, int $pageId, string $role): void
    {
        global $wpdb;
        self::assertNotFalse($wpdb->insert(ClaimRepository::table(), [
            'user_id'        => $userId,
            'entity_type'    => 'page',
            'entity_id'      => $pageId,
            'wallet_address' => 'cosmos1' . $userId,
            'chain_id'       => 7,
            'claim_role'     => $role,
            'status'         => 'verified',
            'verified_at'    => gmdate('Y-m-d H:i:s'),
        ]), $wpdb->last_error);
    }

    private static function card(string $method, int ...$args): bool
    {
        $m = new \ReflectionMethod(CardViewService::class, $method);

        return (bool) $m->invoke(null, ...$args);
    }

    public function testAPageWithOnlyTeamRowsIsNotClaimed(): void
    {
        self::assertSame([], ClaimRepository::getForEntity('page', self::TEAM_ONLY_PAGE, PageClaimRolePolicy::OWNER_ROLES));
        self::assertFalse(self::card('isPageClaimed', self::TEAM_ONLY_PAGE));
        self::assertTrue(self::card('isPageClaimed', self::OWNED_PAGE), 'control: the owned page is claimed');

        $owners = ClaimRepository::getForEntity('page', self::OWNED_PAGE, PageClaimRolePolicy::OWNER_ROLES);
        self::assertSame([self::OWNER], array_map(static fn (object $r): int => (int) $r->user_id, $owners), 'only the owner, never the team');
    }

    public function testATeamMemberIsNeverTheClaimer(): void
    {
        foreach ([self::ADMIN, self::REP] as $member) {
            self::assertNull(ClaimRepository::getUserClaim($member, 'page', self::OWNED_PAGE, PageClaimRolePolicy::OWNER_ROLES));
            self::assertFalse(self::card('viewerHasClaim', $member, self::OWNED_PAGE), "member {$member}");
            self::assertFalse(self::card('viewerHasClaim', $member, self::TEAM_ONLY_PAGE), "member {$member}");
        }
        self::assertTrue(self::card('viewerHasClaim', self::OWNER, self::OWNED_PAGE), 'control: the owner is the claimer');
    }

    public function testTheBatchedReadersAgreeWithTheSingleOnes(): void
    {
        $pages = [self::OWNED_PAGE, self::TEAM_ONLY_PAGE];

        $batch = ClaimRepository::getForEntityBatch('page', $pages, PageClaimRolePolicy::OWNER_ROLES);
        self::assertSame([self::OWNED_PAGE], array_keys($batch), 'the team-only page is not claimed in the list view either');
        self::assertSame([self::OWNER], array_map(static fn (object $r): int => (int) $r->user_id, $batch[self::OWNED_PAGE]));

        foreach ([self::ADMIN, self::REP] as $member) {
            self::assertSame([], ClaimRepository::getUserClaimsForEntities($member, 'page', $pages, PageClaimRolePolicy::OWNER_ROLES), "member {$member}");
        }
        self::assertSame([self::OWNED_PAGE], array_keys(ClaimRepository::getUserClaimsForEntities(self::OWNER, 'page', $pages, PageClaimRolePolicy::OWNER_ROLES)));
    }

    public function testTheTeamIsReadOnlyWhenTeamRolesAreAskedFor(): void
    {
        $team = ClaimRepository::getForEntity('page', self::OWNED_PAGE, PageClaimRolePolicy::TEAM_ROLES);
        $ids  = array_map(static fn (object $r): int => (int) $r->user_id, $team);
        sort($ids);
        self::assertSame([self::ADMIN, self::REP], $ids);
        self::assertSame('representative', ClaimRepository::getUserClaim(self::REP, 'page', self::OWNED_PAGE, PageClaimRolePolicy::TEAM_ROLES)?->claim_role);
    }

    public function testNoRolesReadsNothingAndAnUnknownRoleIsRefused(): void
    {
        self::assertSame([], ClaimRepository::getForEntity('page', self::OWNED_PAGE, []));
        self::assertNull(ClaimRepository::getUserClaim(self::OWNER, 'page', self::OWNED_PAGE, []));

        $this->expectException(\InvalidArgumentException::class);
        ClaimRepository::getForEntity('page', self::OWNED_PAGE, ['owner']);
    }
}
