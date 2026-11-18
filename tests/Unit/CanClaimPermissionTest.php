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

    /** @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null} */
    private static function canClaim(int $viewerId, bool $hasTarget): array
    {
        $m = new \ReflectionMethod(CardViewService::class, 'resolveCanClaim');
        /** @var array{allowed: bool, unlock_hint: string|null, reason_code: string|null} $r */
        $r = $m->invoke(null, $viewerId, $hasTarget);
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

    public function testThePageCardEmitsItFromTheSameClaimTarget(): void
    {
        $src = (string) preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/Services/CardViewService.php'));

        self::assertStringContainsString('$claimTarget = self::resolveClaimTarget($kind, $pageId, $isClaimed, $validatorRows);', $src);
        self::assertStringContainsString("'claim_target' => \$claimTarget,", $src);
        self::assertStringContainsString("'can_claim' => self::resolveCanClaim(\$viewerId, \$claimTarget !== null),", $src);
    }
}
