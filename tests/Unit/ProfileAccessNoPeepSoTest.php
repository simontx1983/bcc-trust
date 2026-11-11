<?php
/**
 * ProfileAccess with PeepSo ABSENT — the fail-closed posture.
 *
 * ⚠ A SEPARATE FILE ON PURPOSE. Its sibling ProfileAccessTest declares a
 * `\PeepSoUser` stub, and a class declaration cannot be undone inside a
 * process, so the "PeepSo is not loaded" branch is unreachable from
 * there. This file requires NO stub, which is exactly the condition
 * under test, and runs in its own process so the sibling's declaration
 * cannot bleed in.
 *
 * ⛔ Never add a stub require to this file. Doing so would make the
 * assertion below pass for the wrong reason — PeepSo present, some other
 * branch denying — which is a false green on a security posture.
 *
 * Why deny rather than allow: no PeepSo means no privacy ladder at all,
 * so there is nothing to consult and every profile would otherwise be
 * served wide open. MentionPolicy makes the same call for the same
 * reason. Note the deliberate contrast with
 * MyProfilePrefsEndpoint::readProfileAccessibility(), which returns 10
 * (public) when PeepSo is missing — that one REPORTS the owner their own
 * setting, where the schema default is the right answer; this one
 * DECIDES access, where it would be a leak.
 */

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Support\ProfileAccess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ProfileAccessNoPeepSoTest extends TestCase
{
    public function testPeepSoAbsentIsTreatedAsTheConditionUnderTest(): void
    {
        // The control for the assertion below: if PeepSo were somehow
        // loaded here, the next test would be measuring nothing.
        self::assertFalse(
            class_exists('\\PeepSoUser', false),
            'this file must run with PeepSo ABSENT — if a stub leaked in, '
            . 'the fail-closed assertion would pass for the wrong reason'
        );
    }

    public function testDeniesEveryProfileWhenPeepSoIsNotLoaded(): void
    {
        self::assertFalse(
            ProfileAccess::canViewProfile(42),
            'no PeepSo means no privacy ladder to consult, so the gate '
            . 'must refuse rather than serve every profile wide open'
        );
    }
}
