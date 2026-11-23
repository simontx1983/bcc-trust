<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\CardViewService;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-301 — page cards carry `permissions.can_claim`.
 *
 * The claim callout rendered on `claim_target` alone, so a suspended member
 * was offered a claim that POST /pages/:id/claim always refuses (F-109).
 * can_claim mirrors the write's viewer gates: signed in, not suspended
 * (admin bypass off). An UNKNOWN suspension state stays allowed (display
 * only; the write fails closed on its own).
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CanClaimPermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/can-claim-stubs.php';
        $GLOBALS['__bcc_susp']        = [];
        $GLOBALS['__bcc_susp_bypass'] = [];
    }

    /**
     * @param bool|array<string, mixed>|null $target true = a claimable target, false/null = none
     * @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null}
     */
    private static function canClaim(int $viewerId, bool|array|null $target): array
    {
        if ($target === true) {
            $target = ['claimable' => true, 'unclaimable_hint' => null];
        } elseif ($target === false) {
            $target = null;
        }
        $m = new \ReflectionMethod(CardViewService::class, 'resolveCanClaim');
        /** @var array{allowed: bool, unlock_hint: string|null, reason_code: string|null} $r */
        $r = $m->invoke(null, $viewerId, $target);
        return $r;
    }

    public function testASignedInMemberInGoodStandingMayClaim(): void
    {
        self::assertSame(['allowed' => true, 'unlock_hint' => null, 'reason_code' => null], self::canClaim(7, true));
    }

    public function testASuspendedMemberIsNotOfferedTheClaim(): void
    {
        $GLOBALS['__bcc_susp'] = [7 => true];

        self::assertSame(
            ['allowed' => false, 'unlock_hint' => 'Your account is suspended, so you cannot claim pages.', 'reason_code' => 'suspended'],
            self::canClaim(7, true)
        );
        self::assertSame([false], $GLOBALS['__bcc_susp_bypass'], 'admin bypass off, as at the write');
    }

    public function testAnUnknownSuspensionStateIsStillOffered(): void
    {
        $GLOBALS['__bcc_susp'] = [7 => null];

        self::assertTrue(self::canClaim(7, true)['allowed']);
    }

    public function testAnonymousViewersAreAskedToSignIn(): void
    {
        self::assertSame(
            ['allowed' => false, 'unlock_hint' => 'Sign in to claim this page.', 'reason_code' => 'signin_required'],
            self::canClaim(0, true)
        );
    }

    public function testNoClaimTargetIsNotApplicableForEveryone(): void
    {
        $GLOBALS['__bcc_susp'] = [7 => true];

        foreach ([0, 7, 8] as $viewer) {
            self::assertSame(['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'not_applicable'], self::canClaim($viewer, false));
        }
        self::assertSame([], $GLOBALS['__bcc_susp_bypass'], 'no suspension read when nothing is claimable');
    }

    // ── F-515: a page that cannot be claimed at all ────────────────────

    /** `not_claimable` with the target's own hint, checked FIRST: it is a fact about the page. */
    public function testAnUnclaimablePageIsNotClaimableForEveryViewer(): void
    {
        $GLOBALS['__bcc_susp'] = [7 => true];
        $target = ['claimable' => false, 'unclaimable_hint' => 'Claiming isn\'t available on THORChain yet.'];

        foreach ([0, 7, 8] as $viewer) {
            self::assertSame(
                ['allowed' => false, 'unlock_hint' => 'Claiming isn\'t available on THORChain yet.', 'reason_code' => 'not_claimable'],
                self::canClaim($viewer, $target),
                "viewer {$viewer}"
            );
        }
        self::assertSame([], $GLOBALS['__bcc_susp_bypass'], 'decided before any suspension read');
    }

    /** @return object the PageCardPrefetcher row shape resolveClaimTarget() reads */
    private static function validatorRow(string $chainType, string $slug, string $name, string $operator): object
    {
        return (object) [
            'validator_id'     => '47',
            'chain_slug'       => $slug,
            'chain_name'       => $name,
            'chain_type'       => $chainType,
            'operator_address' => $operator,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function claimTarget(object $row, bool $isClaimed = false, string $kind = 'validator'): ?array
    {
        $m = new \ReflectionMethod(CardViewService::class, 'resolveClaimTarget');
        /** @var array<string, mixed>|null $r */
        $r = $m->invoke(null, $kind, 9001, $isClaimed, [$row]);
        return $r;
    }

    public function testAClaimableValidatorTargetSaysSo(): void
    {
        self::assertSame(
            [
                'entity_type'             => 'validator',
                'entity_id'               => 47,
                'chain_slug'              => 'cosmoshub',
                'claimable'               => true,
                'unclaimable_reason_code' => null,
                'unclaimable_hint'        => null,
            ],
            self::claimTarget(self::validatorRow('cosmos', 'cosmoshub', 'Cosmos Hub', 'cosmosvaloper1abc'))
        );
    }

    /** An unclaimable page keeps its target, so the card can say WHY, and names the chain, never its slug. */
    public function testAnUnclaimableValidatorTargetCarriesTheReason(): void
    {
        $target = self::claimTarget(self::validatorRow('cosmos', 'thorchain', 'THORChain', 'thor1node'));

        self::assertNotNull($target);
        self::assertFalse($target['claimable']);
        self::assertSame('operator_unprovable', $target['unclaimable_reason_code']);
        self::assertStringContainsString('THORChain', (string) $target['unclaimable_hint']);
        self::assertStringNotContainsString('thorchain', (string) $target['unclaimable_hint']);
        self::assertSame(
            ['allowed' => false, 'unlock_hint' => $target['unclaimable_hint'], 'reason_code' => 'not_claimable'],
            self::canClaim(7, $target),
            'can_claim reads the same target'
        );
    }

    public function testAClaimedOrNonValidatorPageStillHasNoTarget(): void
    {
        $row = self::validatorRow('cosmos', 'cosmoshub', 'Cosmos Hub', 'cosmosvaloper1abc');

        self::assertNull(self::claimTarget($row, isClaimed: true));
        self::assertNull(self::claimTarget($row, kind: 'project'));
    }

    public function testThePageCardEmitsItFromTheSameClaimTarget(): void
    {
        $src = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Services/CardViewService.php'));

        self::assertStringContainsString('$claimTarget = self::resolveClaimTarget($kind, $pageId, $isClaimed, $validatorRows);', $src);
        self::assertStringContainsString("'claim_target' => \$claimTarget,", $src);
        self::assertStringContainsString("'can_claim' => self::resolveCanClaim(\$viewerId, \$claimTarget),", $src);
    }
}
