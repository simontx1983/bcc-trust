<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * POST /auth/refresh never signs a member out over their suspension state.
 *
 * F-305 (user decision 2026-11-12): a suspended member stays signed in
 * READ-ONLY, so refresh no longer refuses a suspension at all; it reports
 * `suspended` instead. The tri-state rule this test used to pin (an UNKNOWN
 * state must never refuse, because a refused refresh signs the user out)
 * still holds for the one place refresh reads the state: the REVOKED-token
 * branch says "account suspended" only for a KNOWN suspension
 * (AuthSupport::resolveSuspended, `=== true`).
 *
 * Behaviour is pinned in AuthSessionEndpointTest; this is the source guard.
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

    public function testRefreshNeverRefusesASuspensionAndReadsOnlyTheTriState(): void
    {
        $body = self::refreshBody();

        self::assertStringNotContainsString('Account is not in good standing.', $body, 'F-305: no suspension refusal');
        self::assertStringContainsString('AuthSupport::resolveSuspended(', $body);
        self::assertStringNotContainsString(
            'is_not_suspended',
            $body,
            'the boolean answer is false for UNKNOWN too'
        );

        $support = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Domain/Core/REST/Auth/AuthSupport.php');
        self::assertStringContainsString('Permissions::suspensionState($userId, false) === true', $support, 'only a KNOWN suspension');
    }

    public function testEverySignInResponseCarriesTheSuspensionFlag(): void
    {
        // F-305: wherever an auth response says in_good_standing, it also
        // says suspended, from the same user id (password, wallet, OAuth,
        // 2FA, email verification, /auth/session).
        $dir   = dirname(__DIR__, 2) . '/app/Domain/Core/REST/Auth/';
        $pairs = 0;
        foreach (glob($dir . '*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            preg_match_all(
                '/\'in_good_standing\' => (self|AuthSupport)::resolveInGoodStanding\((\$\w+)\),\R(?:\s*\/\/[^\r\n]*\R)*\s*\'suspended\'\s*=> \1::resolveSuspended\(\2\),/',
                $src,
                $m
            );
            $standing = substr_count($src, "'in_good_standing' =>");
            self::assertSame($standing, count($m[0]), basename($file) . ': every in_good_standing has its suspended flag');
            $pairs += count($m[0]);
        }
        self::assertSame(8, $pairs);
    }
}
