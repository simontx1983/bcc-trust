<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * POST /auth/refresh must refuse ONLY a KNOWN suspension.
 *
 * A refused refresh signs the user out: the frontend's tryRefresh treats any
 * non-OK answer as a dead session and calls signOut(). bcc-core
 * Permissions::suspensionState() returns null when the suspension could not
 * be read (a DB blip), and that must never log anyone out. A genuinely
 * suspended user is still stopped by every write gate, and by the next
 * refresh once the read works.
 *
 * A source test, the same approach as MembersBoundsEnforcementTest: refresh()
 * needs the JWT grace-decode, the token store and the throttle, and doubling
 * all of that would prove things about the doubles.
 */
final class RefreshSuspensionUnknownTest extends TestCase
{
    /** refresh()'s body with comments stripped, so a comment cannot satisfy a check. */
    private static function refreshBody(): string
    {
        $path = dirname(__DIR__, 2) . '/app/Domain/Core/REST/Auth/SessionController.php';
        $src  = (string) file_get_contents($path);

        $code = '';
        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($t) ? $t[1] : $t;
        }

        $start = strpos($code, 'function refresh(');
        self::assertIsInt($start, 'refresh() not found');
        $next = strpos($code, ' function ', $start + 10);

        return $next === false ? substr($code, $start) : substr($code, $start, $next - $start);
    }

    public function testRefreshAsksForTheTriStateAndRefusesOnlyAKnownSuspension(): void
    {
        $body = self::refreshBody();

        self::assertStringContainsString('Permissions::suspensionState($userId)', $body);
        self::assertStringContainsString('$suspension === true', $body, 'only a KNOWN suspension refuses');
        self::assertStringNotContainsString(
            'is_not_suspended',
            $body,
            'the boolean answer is false for UNKNOWN too, which would sign the user out on a DB blip'
        );
    }
}
