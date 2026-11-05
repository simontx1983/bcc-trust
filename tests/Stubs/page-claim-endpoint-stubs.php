<?php
/**
 * Doubles for driving the real PagesEndpoint::claim() against a real database.
 *
 * Reuses the REST doubles from announcement-endpoint-stubs.php. Everything this
 * file adds is what claim() reaches beyond them, and do_action() RECORDS rather
 * than no-ops: PeepSo authorship and follower transfer, group provisioning,
 * trust bonuses, the activity stream and message delivery all hang off the two
 * events claim() fires, so "no event" is how a test proves none of them ran.
 *
 * ⚠ Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope: PHPUnit loads test files in the parent process too, and these
 * globals would otherwise leak into every other integration test.
 *
 * Throttle is replaced because rate limiting is not under test here, and the
 * real one reads request state this harness does not have.
 */

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/announcement-endpoint-stubs.php';

    if (!class_exists('WP_Post', false)) {
        class WP_Post
        {
            public int $ID = 0;
            public string $post_type = '';
            public string $post_status = '';
            public int $post_author = 0;
        }
    }

    if (!function_exists('do_action')) {
        function do_action(string $hook, ...$args): void
        {
            $GLOBALS['__bcc_actions'][] = [$hook, ...$args];
        }
    }

    if (!function_exists('get_userdata')) {
        function get_userdata(int $userId)
        {
            return (object) ['ID' => $userId, 'user_login' => 'user' . $userId];
        }
    }

    if (!function_exists('get_user_meta')) {
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            return $single ? '' : [];
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        final class Throttle
        {
            public static function allow(string $action, int $limit = 0, int $window = 0): bool
            {
                return true;
            }
        }
    }
}
