<?php
declare(strict_types=1);
namespace BCC\Trust\Tests\Integration;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
/** Throwaway: deliberately fails to prove the integration gate blocks merges. */
#[Group('integration')]
final class GateProbeTest extends TestCase {
    public function testDeliberateFailure(): void {
        self::assertSame(1, 2, 'intentional failure — must block the PR');
    }
}
