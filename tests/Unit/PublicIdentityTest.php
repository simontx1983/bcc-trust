<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\PublicIdentity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * PublicIdentity is the single fallback for a member's PUBLIC handle and
 * display name. The rule it pins: user_login never reaches a public
 * surface, either as the @handle or as the display name.
 */
#[CoversClass(PublicIdentity::class)]
final class PublicIdentityTest extends TestCase
{
    public function testHandleUsesTheStoredHandle(): void
    {
        self::assertSame('alice', PublicIdentity::handle(7, 'alice'));
    }

    public function testMissingHandleFallsBackToMemberIdNotLogin(): void
    {
        self::assertSame('member-7', PublicIdentity::handle(7, ''));
        self::assertSame('member-7', PublicIdentity::handle(7, '   '));
        self::assertSame('member-7', PublicIdentity::handle(7, null));
        self::assertSame('member-7', PublicIdentity::handle(7, false));
    }

    public function testDisplayNameIsKeptWhenItIsNotTheLogin(): void
    {
        self::assertSame('Alice Smith', PublicIdentity::displayName('Alice Smith', 'u_alice', 'alice'));
    }

    public function testDisplayNameEqualToLoginIsTreatedAsMissing(): void
    {
        self::assertSame('alice', PublicIdentity::displayName('u_alice', 'u_alice', 'alice'));
        // Case-insensitive, as MySQL compares logins.
        self::assertSame('alice', PublicIdentity::displayName('U_Alice', 'u_alice', 'alice'));
        // Surrounding whitespace doesn't make it a different name.
        self::assertSame('alice', PublicIdentity::displayName(' u_alice ', 'u_alice', 'alice'));
    }

    public function testEmptyDisplayNameFallsBackToHandle(): void
    {
        self::assertSame('member-9', PublicIdentity::displayName('', 'jsmith', 'member-9'));
    }

    public function testMemberIsTheLastResortName(): void
    {
        self::assertSame('Member', PublicIdentity::displayName('', 'jsmith', ''));
        self::assertSame('Member', PublicIdentity::displayName('jsmith', 'jsmith', ''));
    }

    public function testEmptyLoginNeverHidesARealName(): void
    {
        self::assertSame('Alice', PublicIdentity::displayName('Alice', '', 'alice'));
    }
}
