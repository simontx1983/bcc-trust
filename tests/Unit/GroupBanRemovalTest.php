<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\GroupsService;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use BCC\Trust\Onchain\REST\HolderGroupsEndpoint;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * F-267 / F-271 — group bans and moderator removals, bcc-trust side.
 *
 * F-267: a moderator-removed holder-group member could undo the removal
 * with Leave then Join. Their Leave wrote a voluntary opt-out over the
 * permanent one (no was-member check), and an explicit Join overrides a
 * voluntary opt-out. The delegator leave had the was-member check, but
 * counted a 'banned' row as membership.
 *
 * F-271: a banned user was offered Join and got a 503 "try again" when the
 * writer refused. A ban now reads as a moderator removal everywhere Join is
 * offered or attempted.
 *
 * Behaviour is pinned where it can run without WordPress (resolveCanJoin,
 * isActiveMemberStatus); the REST wiring is pinned on source, as the other
 * endpoint wiring tests here do.
 */
final class GroupBanRemovalTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function source(string $rel): string
    {
        return (string) preg_replace('/\s+/', ' ', (string) file_get_contents(self::root() . '/' . $rel));
    }

    /** @return array<string, array{GroupType, bool}> */
    public static function groupTypes(): array
    {
        return [
            'holder group, holds the NFT' => [GroupType::Nft, true],
            'delegator community'         => [GroupType::Validator, false],
            'plain group'                 => [GroupType::User, false],
        ];
    }

    #[DataProvider('groupTypes')]
    public function testABannedViewerIsNeverOfferedJoin(GroupType $type, bool $holdsNft): void
    {
        $service = (new \ReflectionClass(GroupsService::class))->newInstanceWithoutConstructor();
        $method  = new \ReflectionMethod(GroupsService::class, 'resolveCanJoin');
        $ctx     = new GroupContext(1, $type, PeepSoPrivacy::Open, false, 'g', 1, null);

        self::assertSame(
            ['allowed' => false, 'unlock_hint' => 'A moderator removed you from this community.', 'reason_code' => 'removed'],
            $method->invoke($service, $ctx, 701, false, $holdsNft, 0, null, true)
        );
    }

    public function testAnUnbannedHolderIsStillOfferedJoin(): void
    {
        $service = (new \ReflectionClass(GroupsService::class))->newInstanceWithoutConstructor();
        $method  = new \ReflectionMethod(GroupsService::class, 'resolveCanJoin');
        $ctx     = new GroupContext(1, GroupType::Nft, PeepSoPrivacy::Open, false, 'g', 1, null);

        self::assertSame(
            ['allowed' => true, 'unlock_hint' => null, 'reason_code' => null],
            $method->invoke($service, $ctx, 701, false, true, 0, null, false)
        );
    }

    /** @return array<string, array{?string, bool}> */
    public static function statuses(): array
    {
        return [
            'member'          => ['member', true],
            'owner'           => ['member_owner', true],
            'moderator'       => ['member_moderator', true],
            'read-only'       => ['member_readonly', true],
            'banned'          => ['banned', false],
            'pending request' => ['pending_admin', false],
            'pending invite'  => ['pending_user', false],
            'block invites'   => ['block_invites', false],
            'no row'          => [null, false],
        ];
    }

    #[DataProvider('statuses')]
    public function testOnlyAnActiveMembershipCountsAsMembershipForLeave(?string $status, bool $expected): void
    {
        self::assertSame($expected, HolderGroupsEndpoint::isActiveMemberStatus($status));
    }

    public function testTheHolderLeaveRecordsAnOptOutOnlyForARealMember(): void
    {
        $src  = self::source('app/Domain/Onchain/REST/HolderGroupsEndpoint.php');
        $from = strpos($src, 'public function postLeave(');
        self::assertNotFalse($from);
        $leave = substr($src, $from, (int) strpos($src, 'return ApiResponse::ok(', $from) - $from);

        // F-329: the status is GroupLeaveGuard's fail-closed read (one read).
        self::assertStringContainsString('[$refusal, $status] = \BCC\Trust\Core\Support\GroupLeaveGuard::precheck($userId, $groupId);', $leave);
        self::assertStringContainsString('$wasMember = self::isActiveMemberStatus($status);', $leave);
        self::assertLessThan(strpos($leave, 'PeepSoGroupWriter::leave('), strpos($leave, 'GroupLeaveGuard::precheck('), 'read BEFORE the writer deletes the row');
        self::assertLessThan(strpos($leave, 'PeepSoGroupWriter::leave('), strpos($leave, '$wasMember ='), 'decided BEFORE the writer deletes the row');
        self::assertStringContainsString('if ($wasMember) { OnchainPlugin::instance()->nftGroupGateService()->recordOptOut($userId, $groupId);', $leave);
        self::assertSame(1, substr_count($leave, '->recordOptOut('), 'no unguarded opt-out write');
    }

    public function testTheDelegatorLeaveDoesNotCountABanAsMembership(): void
    {
        $src = self::source('app/Domain/Onchain/REST/ValidatorGroupsEndpoint.php');
        // F-329: the status is GroupLeaveGuard's fail-closed read (one read).
        self::assertStringContainsString('[$refusal, $status] = \BCC\Trust\Core\Support\GroupLeaveGuard::precheck($userId, $groupId);', $src);
        self::assertStringContainsString('$wasMember = HolderGroupsEndpoint::isActiveMemberStatus($status);', $src);
        self::assertStringNotContainsString('getMembershipStatus($userId, $groupId) !== null', $src);
        self::assertStringNotContainsString('$wasMember = $status !== null', $src);
    }

    /** @return array<string, array{string}> */
    public static function discoveryEndpoints(): array
    {
        return [
            'holder groups'         => ['app/Domain/Onchain/REST/HolderGroupsEndpoint.php'],
            'delegator communities' => ['app/Domain/Onchain/REST/ValidatorGroupsEndpoint.php'],
        ];
    }

    #[DataProvider('discoveryEndpoints')]
    public function testDiscoveryShowsABannedGroupAsRemoved(string $file): void
    {
        $src = self::source($file);
        self::assertStringContainsString('->bannedGroupIdSet($userId, $groupIds);', $src);
        self::assertMatchesRegularExpression(
            '/if \(isset\(\$banned\[\$cfg->groupId\]\)\) \{ .{0,80}\$item\[\'opt_out_kind\'\] = (self|HolderGroupsEndpoint)::OPT_OUT_KIND_REMOVED; \$optedOut\[\] = \$item; continue; \}/',
            $src
        );
        // A banned group is never checked for eligibility (no provider cost,
        // and it can never land in eligible_to_join).
        self::assertStringContainsString('isset($banned[$cfg->groupId])', substr($src, 0, (int) strpos($src, '$joined')));
    }

    public function testThePlainJoinAndTheHallJoinRefuseABanAsARemovalBeforeTheWriter(): void
    {
        $plain = self::source('app/Domain/Core/REST/MyGroupsEndpoint.php');
        $ban   = strpos($plain, "if (\$priorStatus === 'banned') { return ApiResponse::error( 'bcc_permission_denied', 'A moderator removed you from this community, so you cannot rejoin it.', 403 ); }");
        self::assertNotFalse($ban);
        self::assertLessThan(strpos($plain, 'PeepSoGroupWriter::join($userId, $groupId)'), $ban);

        $hall = self::source('app/Domain/Core/Services/HallsService.php');
        $ban  = strpos($hall, "if (PeepSoGroupRepository::getMembershipStatus(\$viewerId, \$groupId) === 'banned') { return ['error' => 'bcc_forbidden',");
        self::assertNotFalse($ban);
        self::assertLessThan(strpos($hall, '$ok = PeepSoGroupWriter::join($viewerId, $groupId);'), $ban);
    }

    public function testTheHolderJoinRefusesABanBeforeAnyProviderCall(): void
    {
        $src  = self::source('app/Domain/Onchain/Services/NftGroupGateService.php');
        $from = strpos($src, 'public function joinIfEligible(');
        $join = substr($src, $from, (int) strpos($src, 'return JoinResult::ok(', $from) - $from);

        $ban = strpos($join, 'if ($this->isBannedFrom($userId, $groupId)) { return JoinResult::optOutActive($config->minBalance); }');
        self::assertNotFalse($ban);
        self::assertLessThan(strpos($join, 'HoldingsService::eligibilityVerdict('), $ban);
    }
}
