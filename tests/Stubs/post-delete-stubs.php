<?php
/**
 * Doubles for PostDeleteOracleTest (F-47): PostsService::deletePost() up to
 * and including its ownership gate.
 *
 * Builds on comment-delete-stubs.php (the PeepSoActivityRepository fake and
 * CommentDeleteFakes registry) and adds what deletePost reaches first: the
 * feature switch, the throttle, the admin check, and a recording
 * wp_trash_post so "nothing was deleted" is assertable.
 *
 * Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope.
 */

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/comment-delete-stubs.php';
}

namespace BCC\Trust\Core\Services {
    if (!function_exists(__NAMESPACE__ . '\\get_option')) {
        /**
         * @param  mixed $default
         * @return mixed
         */
        function get_option(string $key, $default = false)
        {
            return $default;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\current_user_can')) {
        function current_user_can(string $capability): bool
        {
            return false;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_trash_post')) {
        /** @return false */
        function wp_trash_post(int $postId)
        {
            $GLOBALS['__bcc_trashed'][] = $postId;
            // F-361 — the superglobals AT the write. The guard restores
            // them in a `finally`, so a check after the call sees the
            // original whether the pin ran or not.
            $GLOBALS['__bcc_pin_at_write'][] = [
                'fn'   => 'wp_trash_post',
                'get'  => $_GET,
                'post' => $_POST,
            ];

            return false;
        }
    }
}

namespace BCC\Core\Log {
    // The owner path logs the (stubbed, failing) trash; record nothing.
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
            }
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        final class Throttle
        {
            public static function allow(string $key, int $limit = 0, int $window = 0, ?string $bucketKey = null): bool
            {
                return true;
            }
        }
    }
}

namespace BCC\Core\Feed {
    // F-345: FeedItemGate reads bcc-core's public-feed allowlist, and
    // bcc-core is not autoloadable in this suite. This double carries the
    // allowlist's shape; FeedItemGateTest pins the gate against the REAL
    // bcc-core FeedItemNormalizer.
    if (!class_exists(__NAMESPACE__ . '\FeedItemNormalizer', false)) {
        final class FeedItemNormalizer
        {
            /** @return list<int> */
            public static function publicFeedModuleIds(): array
            {
                return [1, 4, 200, 201, 202, 203, 204, 205];
            }
        }
    }
}
