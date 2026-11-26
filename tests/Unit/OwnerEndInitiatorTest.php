<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\CLI\ClaimRevokeUserCommand;
use BCC\Trust\Onchain\Services\ClaimService;
use PHPUnit\Framework\TestCase;

/**
 * Who ends a member's claims, as the relationship log names them, and the
 * CLI break-glass's refusal without an approver and evidence.
 */
final class OwnerEndInitiatorTest extends TestCase
{
    public function testTheInitiatorIsTheMemberStaffOrTheSystem(): void
    {
        self::assertSame(['member', 42], ClaimService::initiatorFor(42, 42), 'the member deletes their own account or unlinks their wallet');
        self::assertSame(['staff', 7], ClaimService::initiatorFor(42, 7), 'a staff member acting on them');
        self::assertSame(['system', null], ClaimService::initiatorFor(42, 0), 'cron or CLI: no current user');
    }

    public function testTheBreakGlassNeedsAnApproverAndEvidenceForAValidatorClaim(): void
    {
        self::assertNull(ClaimRevokeUserCommand::breakGlassRefusal(false, '', ''), 'no validator claim: nothing extra needed');
        self::assertNotNull(ClaimRevokeUserCommand::breakGlassRefusal(true, '', 'why'));
        self::assertNotNull(ClaimRevokeUserCommand::breakGlassRefusal(true, 'Approver', ''));
        self::assertNull(ClaimRevokeUserCommand::breakGlassRefusal(true, 'Approver', 'Lost key; written approval on file.'));
    }
}
