<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\ReputationRepository;
use BCC\Trust\Core\Services\GroupContextResolver;
use BCC\Trust\Core\Services\GroupsService;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * F-244: community cards and the profile Groups tab get their join/leave
 * verdicts from the SAME GroupsService logic as the group page, so a card
 * can never offer what the page refuses.
 *
 *   - listJoinFields(): the list verdict, from the viewer's raw status
 *     (batched); a failed read is `unavailable`, never a guessed Join;
 *     holder groups are `verify_on_join` unless the caller knows.
 *   - leavePermission(): the leave verdict, with owner_cannot_leave.
 *   - For the same inputs the list verdict equals the page's verdict.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupJoinVerdictTest extends TestCase
{
    private const VIEWER = 140;
    private const GROUP  = 4300;

    private GroupsService $groups;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/group-join-request-stubs.php';
        $GLOBALS['__bcc_susp_fixture'] = ['user_id' => self::VIEWER, 'throttled' => false, 'user_meta' => []];
        $GLOBALS['__bcc_gjr'] = ['contexts' => [], 'memberships' => [], 'suspended' => [], 'score_reads' => 0, 'score' => 10.0];

        $class        = new ReflectionClass(GroupsService::class);
        $this->groups = $class->newInstanceWithoutConstructor();
        $class->getProperty('groupContextResolver')->setValue($this->groups, new GroupContextResolver());
        $class->getProperty('reputationRepo')->setValue($this->groups, new class extends ReputationRepository {
            public function __construct()
            {
            }

            public function getScore(int $userId): float
            {
                $GLOBALS['__bcc_gjr']['score_reads']++;
                return (float) $GLOBALS['__bcc_gjr']['score'];
            }
        });
    }

    private static function ctx(PeepSoPrivacy $privacy, GroupType $type = GroupType::User): GroupContext
    {
        return new GroupContext(self::GROUP, $type, $privacy, false, null, null, null);
    }

    /** @return array<string, mixed> */
    private function fields(GroupContext $ctx, string|null|false $status, int $trustMin = 0, ?bool $holder = null, int $viewer = self::VIEWER): array
    {
        return $this->groups->listJoinFields($ctx, $viewer, $status, $trustMin, null, $holder);
    }

    // ── list verdicts ────────────────────────────────────────────────

    public function testAFailedStatusReadIsUnavailableNeverAGuessedJoin(): void
    {
        $f = $this->fields(self::ctx(PeepSoPrivacy::Open), false);

        self::assertSame(['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'unavailable'], $f['can_join']);
        self::assertSame('unavailable', $f['can_request_join']['reason_code']);
        self::assertNull($f['viewer_membership_status']);
    }

    public function testAHolderGroupOnAListIsVerifiedOnJoin(): void
    {
        $f = $this->fields(self::ctx(PeepSoPrivacy::Closed, GroupType::Nft), null);

        self::assertSame(['allowed' => true, 'unlock_hint' => null, 'reason_code' => 'verify_on_join'], $f['can_join']);
    }

    public function testAKnownHolderVerdictIsUsed(): void
    {
        self::assertSame('not_eligible', $this->fields(self::ctx(PeepSoPrivacy::Closed, GroupType::Nft), null, 0, false)['can_join']['reason_code']);
        self::assertTrue($this->fields(self::ctx(PeepSoPrivacy::Closed, GroupType::Nft), null, 0, true)['can_join']['allowed']);
    }

    /** @return array<string, array{PeepSoPrivacy, GroupType, ?string, string|null, string, string|null}> */
    public static function verdicts(): array
    {
        // privacy, type, raw status, can_join reason, membership status, can_request_join reason
        return [
            'open, no row'        => [PeepSoPrivacy::Open, GroupType::User, null, null, 'none', 'not_applicable'],
            'member'              => [PeepSoPrivacy::Open, GroupType::User, 'member', 'already_member', 'member', 'already_member'],
            'read-only member'    => [PeepSoPrivacy::Closed, GroupType::User, 'member_readonly', 'already_member', 'member', 'already_member'],
            'banned'              => [PeepSoPrivacy::Open, GroupType::User, 'banned', 'removed', 'none', 'not_applicable'],
            'closed, no row'      => [PeepSoPrivacy::Closed, GroupType::User, null, 'requires_approval', 'none', null],
            'closed, requested'   => [PeepSoPrivacy::Closed, GroupType::User, 'pending_admin', 'requires_approval', 'requested', 'already_requested'],
            'closed, invited'     => [PeepSoPrivacy::Closed, GroupType::User, 'pending_user', 'requires_approval', 'invited', 'invited'],
            'secret, invited'     => [PeepSoPrivacy::Secret, GroupType::User, 'pending_user', 'invite_only', 'invited', 'not_applicable'],
            'delegator community' => [PeepSoPrivacy::Closed, GroupType::Validator, null, 'not_eligible', 'none', 'not_applicable'],
        ];
    }

    #[DataProvider('verdicts')]
    public function testListVerdictsCoverEveryStatus(PeepSoPrivacy $privacy, GroupType $type, ?string $status, ?string $joinReason, string $membership, ?string $requestReason): void
    {
        $f = $this->fields(self::ctx($privacy, $type), $status);

        self::assertSame($joinReason, $f['can_join']['reason_code']);
        self::assertSame($joinReason === null, $f['can_join']['allowed']);
        self::assertSame($membership, $f['viewer_membership_status']);
        self::assertSame($requestReason, $f['can_request_join']['reason_code']);
    }

    public function testAnonymousViewersAreAskedToSignInAndHaveNoStatus(): void
    {
        $f = $this->fields(self::ctx(PeepSoPrivacy::Open), null, 0, null, 0);

        self::assertSame('auth_required', $f['can_join']['reason_code']);
        self::assertNull($f['viewer_membership_status']);
    }

    public function testTheViewersScoreIsReadOnceForAWholeList(): void
    {
        $GLOBALS['__bcc_gjr']['score'] = 10.0;

        foreach ([25, 50, 75, 25] as $min) {
            $f = $this->fields(self::ctx(PeepSoPrivacy::Open), null, $min);
            self::assertSame('trust_threshold', $f['can_join']['reason_code']);
        }
        self::assertSame(1, $GLOBALS['__bcc_gjr']['score_reads']);
    }

    // ── one verdict with the group page ──────────────────────────────

    /** @return array<string, array{PeepSoPrivacy, GroupType, ?string, int, ?bool}> */
    public static function agreement(): array
    {
        return [
            'open'                => [PeepSoPrivacy::Open, GroupType::User, null, 0, null],
            'closed'              => [PeepSoPrivacy::Closed, GroupType::User, null, 0, null],
            'secret invited'      => [PeepSoPrivacy::Secret, GroupType::User, 'pending_user', 0, null],
            'banned'              => [PeepSoPrivacy::Open, GroupType::User, 'banned', 0, null],
            'member'              => [PeepSoPrivacy::Closed, GroupType::System, 'member', 0, null],
            'trust gated, short'  => [PeepSoPrivacy::Open, GroupType::User, null, 50, null],
            'holder, holds'       => [PeepSoPrivacy::Closed, GroupType::Nft, null, 0, true],
            'holder, does not'    => [PeepSoPrivacy::Closed, GroupType::Nft, null, 0, false],
            'delegator'           => [PeepSoPrivacy::Closed, GroupType::Validator, null, 0, null],
        ];
    }

    /**
     * The page computes can_join through buildPermissions() with a real
     * holder check; give the list path the same holder verdict and the two
     * must be identical.
     */
    #[DataProvider('agreement')]
    public function testTheListVerdictEqualsThePagesForTheSameInputs(PeepSoPrivacy $privacy, GroupType $type, ?string $status, int $trustMin, ?bool $holder): void
    {
        $ctx      = self::ctx($privacy, $type);
        $isMember = $status !== null && str_starts_with($status, 'member');

        $page = new ReflectionMethod(GroupsService::class, 'buildPermissions');
        $page->setAccessible(true);
        /** @var array{can_join: array<string, mixed>} $perms */
        $perms = $page->invoke($this->groups, $ctx, self::VIEWER, $isMember, (bool) $holder, false, $trustMin, null, $status === 'banned');

        $list = $this->fields($ctx, $status, $trustMin, $type === GroupType::Nft ? (bool) $holder : null);

        self::assertSame($perms['can_join'], $list['can_join']);
    }

    // ── leave ────────────────────────────────────────────────────────

    /** @return array<string, array{int, ?string, bool, string|null}> */
    public static function leaves(): array
    {
        return [
            'member'        => [self::VIEWER, 'member', true, null],
            'manager'       => [self::VIEWER, 'member_manager', true, null],
            'owner'         => [self::VIEWER, 'member_owner', false, 'owner_cannot_leave'],
            'not a member'  => [self::VIEWER, null, false, 'not_member'],
            'pending'       => [self::VIEWER, 'pending_admin', false, 'not_member'],
            'banned'        => [self::VIEWER, 'banned', false, 'not_member'],
            'anonymous'     => [0, null, false, 'auth_required'],
        ];
    }

    #[DataProvider('leaves')]
    public function testTheLeaveVerdictIsThePagesIncludingTheOwnerRule(int $viewer, ?string $status, bool $allowed, ?string $reason): void
    {
        $v = $this->groups->leavePermission($viewer, $status);

        self::assertSame($allowed, $v['allowed']);
        self::assertSame($reason, $v['reason_code']);
    }
}
