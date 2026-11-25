<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Onchain\Services\ValidatorLinkTargetCheck as C;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * Who staff may link as a validator owner: a real account, KNOWN not
 * suspended, with a PeepSo role that holds member powers. Anything unknown
 * refuses.
 */
#[CoversClass(C::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValidatorLinkTargetCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/validator-link-target-check-stubs.php';
    }

    public function testAnActiveMemberIsEligible(): void
    {
        self::assertTrue(C::eligible(42));
    }

    public function testNoAccountOrAnUnreadableAccountRefuses(): void
    {
        \TargetWorld::$exists = false;
        self::assertFalse(C::eligible(42), 'a typo: no such account');

        \TargetWorld::$exists = null;
        self::assertFalse(C::eligible(42), 'a failed read never reads as "exists"');
        self::assertFalse(C::eligible(0));
    }

    public function testASuspendedOrUnknownSuspensionStateRefuses(): void
    {
        \TargetWorld::$suspended = true;
        self::assertFalse(C::eligible(42));

        \TargetWorld::$suspended = null;
        self::assertFalse(C::eligible(42), 'unknown is not "not suspended"');
    }

    /** @return array<string, array{string}> */
    public static function rolesWithoutMemberPowers(): array
    {
        return ['banned' => ['ban'], 'awaiting email validation' => ['register'], 'awaiting approval' => ['verified'], 'no role' => ['']];
    }

    #[DataProvider('rolesWithoutMemberPowers')]
    public function testARoleWithoutMemberPowersRefuses(string $role): void
    {
        \TargetWorld::$role = $role;

        self::assertFalse(C::eligible(42));
    }

    public function testAnUnreadablePeepSoRoleRefuses(): void
    {
        \TargetWorld::$peepsoLoaded = false;

        self::assertFalse(C::eligible(42));
    }
}
