<?php

/**
 * Global `is_user_logged_in()` double for the cookie/session arm of
 * {@see \BCC\Trust\Infrastructure\EdgeCache::isCredentialed()}.
 *
 * ## Why the double has to be global
 *
 * That arm reads:
 *
 *     function_exists('is_user_logged_in') && is_user_logged_in()
 *
 * The string handed to `function_exists()` is UNQUALIFIED, so it resolves
 * against the GLOBAL namespace only. A shim declared in
 * `BCC\Trust\Infrastructure` would never be reached — the guard would already
 * be false and the call short-circuits. So the double must be global, and
 * declaring it is the only way to exercise the arm at all: without it,
 * `function_exists()` is false in every unit run and the branch is
 * unreachable. That is exactly why it shipped with no coverage.
 *
 * ## Why this is safe for the rest of the suite
 *
 * It defaults to FALSE and is driven by an explicit flag, so unless a test
 * opts in, every caller sees what it saw before: "not logged in".
 *
 * Fourteen other production call sites use `is_user_logged_in()` UNGUARDED,
 * all of them inside REST permission callbacks that unit tests never invoke —
 * so defining it cannot silently activate a previously-dead branch where they
 * live. The full suite passing is the evidence for that claim, not this
 * comment.
 *
 * Kept in a separate file because the test file uses an unbracketed
 * `namespace`, and PHP forbids mixing bracketed and unbracketed namespace
 * declarations in one file.
 */

declare(strict_types=1);

if (!class_exists('BccEdgeCacheSessionState', false)) {
    /** Drives the global is_user_logged_in() double below. */
    final class BccEdgeCacheSessionState
    {
        /** Whether the faked WordPress session is authenticated. */
        public static bool $loggedIn = false;

        /** Return to the default "logged out" state. */
        public static function reset(): void
        {
            self::$loggedIn = false;
        }
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool
    {
        return BccEdgeCacheSessionState::$loggedIn;
    }
}
