<?php
/**
 * F-441: become a user in an in-process harness the way the app does,
 * by bearer token.
 *
 * ⚠⚠ WHY: since H2 (v1.186), WordPressSignInGate refuses any member on a
 * bcc route whose identity did not come from a BCC bearer token
 * (`bcc_unauthorized` 401 "Sign in at the app."). `wp_set_current_user()`
 * is exactly that: a user with no token. So every `wp eval-file` harness
 * that dispatched a bcc route as a member started answering 401 there.
 * The decision (main agent, 2026-11-19) was to keep that rule absolute,
 * with no CLI exemption, and to authenticate the harnesses the real way.
 *
 * ⚠ Do NOT "fix" a harness by running it as an admin. Admins are exempt
 * from that gate and from most member rules, so the harness would stop
 * testing member behaviour while staying green.
 *
 * Usage (inside a harness run with `wp eval-file`):
 *
 *   require_once __DIR__ . '/lib/act-as.php';
 *   bcc_harness_act_as($memberId);   // then dispatch as that member
 *   bcc_harness_act_as(0);           // signed out: no header, no user
 *
 * @package BCC\Trust\Scripts\Tests
 */

declare(strict_types=1);

if (!function_exists('bcc_harness_act_as')) {
    /**
     * Make $userId the request's user exactly as an app request does:
     *   1. mint a session token server-side (JwtToken::encodeNewSession,
     *      as a real sign-in does);
     *   2. present it as `Authorization: Bearer …`;
     *   3. forget the resolved user, so the next read runs WordPress's own
     *      `determine_current_user` chain and BearerAuth resolves it.
     *
     * It then checks that the request's user is $userId and that
     * BearerAuth::authenticatedUserId() agrees. A token the app would
     * refuse (a deleted user, a revoked version) fails loudly here instead
     * of letting the harness go on as somebody else, or as nobody.
     *
     * $userId = 0 signs out: the header is removed and no user is set.
     */
    function bcc_harness_act_as(int $userId): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

        if ($userId > 0) {
            $handle = (string) get_user_meta($userId, \BCC\Trust\Core\Services\HandleService::META_HANDLE, true);
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '
                . \BCC\Trust\Core\Support\JwtToken::encodeNewSession($userId, $handle);
        }

        // `wp_set_current_user()` would skip determine_current_user, which is
        // the whole point. An empty global makes the next read run it.
        $GLOBALS['current_user'] = null;
        $resolved = (int) wp_get_current_user()->ID;

        $bearer = \BCC\Trust\Core\Support\BearerAuth::authenticatedUserId();
        if ($resolved !== $userId || ($userId > 0 && $bearer !== $userId)) {
            throw new \RuntimeException(sprintf(
                'bcc_harness_act_as(%d): the request resolved user %d (bearer user %d). '
                . 'The token was refused, or another authentication ran first.',
                $userId,
                $resolved,
                $bearer
            ));
        }
    }
}
