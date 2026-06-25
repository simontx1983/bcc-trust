<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Onchain\ValueObjects\ClaimStatus;
use PHPUnit\Framework\TestCase;

/**
 * Pins the claim-status state machine (Phase 9): only pending/verified/revoked
 * are valid, and an invalid value fails loud rather than silently persisting.
 */
final class ClaimStatusTest extends TestCase
{
    public function testAcceptsTheThreeValidStates(): void
    {
        self::assertSame('pending', ClaimStatus::assert(ClaimStatus::PENDING));
        self::assertSame('verified', ClaimStatus::assert(ClaimStatus::VERIFIED));
        self::assertSame('revoked', ClaimStatus::assert(ClaimStatus::REVOKED));
    }

    public function testRejectsAnInvalidStatus(): void
    {
        $this->expectException(\LogicException::class);
        ClaimStatus::assert('verifed'); // typo — must throw, not slip through
    }

    public function testRejectsEmptyString(): void
    {
        $this->expectException(\LogicException::class);
        ClaimStatus::assert('');
    }
}
