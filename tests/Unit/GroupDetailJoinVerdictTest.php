<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\GroupsService;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use BCC\Trust\Onchain\ValueObjects\GatedGroupConfig;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * F-359: the group page gives the same join verdict as every list surface.
 *
 * Neither the page nor the lists check the viewer's wallet or delegation
 * on render — only the join doors (/me/holder-groups, /me/validator-groups)
 * do. So a configured holder group and every delegator group answer
 * `{allowed: true, reason_code: "verify_on_join"}`. The page used to send
 * `not_eligible` for both, telling eligible holders and delegators they
 * could not join. That the page and the lists then agree is pinned by
 * GroupJoinVerdictTest's agreement provider ("holder, not checked",
 * "delegator").
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class GroupDetailJoinVerdictTest extends TestCase
{
    private const VIEWER     = 140;
    private const GROUP      = 4300;
    private const COLLECTION = 77;

    private GroupsService $groups;

    protected function setUp(): void
    {
        require_once dirname(__DIR__) . '/Stubs/group-detail-holder-stubs.php';
        $GLOBALS['__bcc_gdh'] = [
            'configs'     => [self::GROUP => new GatedGroupConfig(self::GROUP, 1, '0xabc', 1, self::COLLECTION)],
            'collections' => [self::COLLECTION => self::collectionRow()],
        ];
        $this->groups = (new ReflectionClass(GroupsService::class))->newInstanceWithoutConstructor();
    }

    private static function collectionRow(): object
    {
        return (object) [
            'image_url' => null, 'token_standard' => 'erc721', 'total_supply' => 100, 'unique_holders' => 40,
            'floor_price' => null, 'floor_currency' => null, 'total_volume' => null, 'listed_percentage' => null,
            'royalty_percentage' => null, 'chain_slug' => 'ethereum', 'contract_address' => '0xabc',
        ];
    }

    private static function ctx(GroupType $type): GroupContext
    {
        return new GroupContext(self::GROUP, $type, PeepSoPrivacy::Closed, false, null, null, null);
    }

    /** The holder value the page computes, from the real enrichment. */
    private function pageHolder(): ?bool
    {
        $m = new ReflectionMethod(GroupsService::class, 'resolveNftEnrichment');
        /** @var array{0: mixed, 1: mixed, 2: bool|null} $out */
        $out = $m->invoke($this->groups, self::ctx(GroupType::Nft), self::VIEWER, false);
        return $out[2];
    }

    /** @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null} */
    private function pageCanJoin(GroupType $type, ?bool $holder, ?string $minStake = null): array
    {
        $m = new ReflectionMethod(GroupsService::class, 'resolveCanJoin');
        /** @var array{allowed: bool, unlock_hint: string|null, reason_code: string|null} */
        return $m->invoke($this->groups, self::ctx($type), self::VIEWER, false, $holder, 0, $minStake);
    }

    public function testTheHolderPageDoesNotClaimTheViewerIsIneligible(): void
    {
        self::assertNull($this->pageHolder(), 'not checked on render: unknown, never false');
    }

    public function testTheHolderPageOffersJoinVerifiedOnJoin(): void
    {
        self::assertSame(
            ['allowed' => true, 'unlock_hint' => null, 'reason_code' => 'verify_on_join'],
            $this->pageCanJoin(GroupType::Nft, $this->pageHolder())
        );
    }

    public function testAHolderGroupWithNoGateConfigIsNotJoinable(): void
    {
        $GLOBALS['__bcc_gdh']['configs'] = [];

        self::assertFalse($this->pageHolder(), 'no gate config: nothing a holder could satisfy');
        self::assertSame('not_eligible', $this->pageCanJoin(GroupType::Nft, $this->pageHolder())['reason_code']);
    }

    public function testAHolderGroupWhoseCollectionIsMissingIsNotJoinable(): void
    {
        $GLOBALS['__bcc_gdh']['collections'] = [];

        self::assertFalse($this->pageHolder());
    }

    public function testADelegatorGroupOffersJoinVerifiedOnJoinAndStatesTheMinimum(): void
    {
        self::assertSame(
            [
                'allowed'     => true,
                'unlock_hint' => 'Delegate at least 2.5 ATOM to this validator to join its community.',
                'reason_code' => 'verify_on_join',
            ],
            $this->pageCanJoin(GroupType::Validator, null, '2.5 ATOM')
        );
        self::assertSame(
            [
                'allowed'     => true,
                'unlock_hint' => 'Delegate to this validator to join its community.',
                'reason_code' => 'verify_on_join',
            ],
            $this->pageCanJoin(GroupType::Validator, null, null)
        );
    }

    public function testADelegatorGroupStillRefusesARemovedViewerAndWelcomesAMember(): void
    {
        $m = new ReflectionMethod(GroupsService::class, 'resolveCanJoin');

        self::assertSame('removed', $m->invoke($this->groups, self::ctx(GroupType::Validator), self::VIEWER, false, null, 0, null, true)['reason_code']);
        self::assertSame('already_member', $m->invoke($this->groups, self::ctx(GroupType::Validator), self::VIEWER, true, null, 0, null)['reason_code']);
        self::assertSame('auth_required', $m->invoke($this->groups, self::ctx(GroupType::Validator), 0, false, null, 0, null)['reason_code']);
    }
}
