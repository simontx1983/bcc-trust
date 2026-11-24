<?php
/**
 * Fixture stubs for ActivityStreamWriterRequestPinTest.
 *
 * ⭐ WHAT MAKES THESE DIFFERENT from the other ActivityStreamWriter
 * fixtures: every post-write stub SNAPSHOTS THE SUPERGLOBALS at the
 * moment it is called. That snapshot is the only way to observe whether
 * the request was neutralised *during* the write — by the time the call
 * returns, the guard's `finally` has already put the real request back,
 * so a test that looks afterwards sees nothing and proves nothing.
 *
 * ⚠ Required from setUp(), never at file scope: these declare functions
 * at the same FQNs as `activity-replay-stubs.php` and
 * `ActivityBackingPostTypeTest`, and a file-scope declaration would
 * pollute the PHPUnit discovery parent and hijack those suites.
 *
 * Fixture shape ($GLOBALS['__bcc_pin']):
 *   writes      list<array{fn:string, args:array, get:array, post:array, request:array}>
 *   next_post   int   next wp_posts.ID to mint
 *   insert_fail bool  make wp_insert_post answer a WP_Error-ish object
 *   throw_on    string|null  fn name whose stub should throw
 *   meta        list<array{post:int, key:string, value:mixed}>
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services\Feed {
    if (!function_exists(__NAMESPACE__ . '\\bcc_pin_snapshot')) {
        /**
         * The whole point of this fixture.
         *
         * @param array<string, mixed> $args
         */
        function bcc_pin_snapshot(string $fn, array $args): void
        {
            $GLOBALS['__bcc_pin']['writes'][] = [
                'fn'      => $fn,
                'args'    => $args,
                // Copied, not referenced: the guard restores the originals
                // immediately after the write returns.
                'get'     => $_GET,
                'post'    => $_POST,
                'request' => $_REQUEST,
            ];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_insert_post')) {
        /**
         * @param array<string, mixed> $args
         * @return int|object
         */
        function wp_insert_post(array $args, bool $wpError = false)
        {
            unset($wpError);
            bcc_pin_snapshot('wp_insert_post', $args);

            if (($GLOBALS['__bcc_pin']['throw_on'] ?? null) === 'wp_insert_post') {
                throw new \RuntimeException('insert blew up');
            }
            if (($GLOBALS['__bcc_pin']['insert_fail'] ?? false) === true) {
                return new \stdClass();
            }

            return $GLOBALS['__bcc_pin']['next_post']++;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_update_post')) {
        /**
         * @param array<string, mixed> $args
         * @return int|object
         */
        function wp_update_post(array $args, bool $wpError = false)
        {
            unset($wpError);
            bcc_pin_snapshot('wp_update_post', $args);

            return (int) ($args['ID'] ?? 0);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\is_wp_error')) {
        /** @param mixed $thing */
        function is_wp_error($thing): bool
        {
            return $thing instanceof \stdClass;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\get_posts')) {
        /**
         * Always "no existing post", so the replay guard falls through to
         * the insert this suite is about.
         *
         * @param array<string, mixed> $args
         * @return list<int>
         */
        function get_posts(array $args): array
        {
            unset($args);

            return [];
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\update_post_meta')) {
        /** @param mixed $value */
        function update_post_meta(int $postId, string $key, $value): bool
        {
            $GLOBALS['__bcc_pin']['meta'][] = [
                'post'  => $postId,
                'key'   => $key,
                'value' => $value,
            ];

            return true;
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\wp_delete_post')) {
        /** @return mixed */
        function wp_delete_post(int $postId, bool $force = false)
        {
            unset($force);
            $GLOBALS['__bcc_pin']['writes'][] = [
                'fn'      => 'wp_delete_post',
                'args'    => ['ID' => $postId],
                'get'     => $_GET,
                'post'    => $_POST,
                'request' => $_REQUEST,
            ];

            return true;
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    if (!class_exists(WatchBatchRepository::class, false)) {
        final class WatchBatchRepository
        {
        }
    }

    if (!class_exists(PeepSoActivityWriter::class, false)) {
        final class PeepSoActivityWriter
        {
            public function findActIdForExternal(string $moduleId, int $wpPostId): int
            {
                unset($moduleId, $wpPostId);

                return 0;
            }

            public function insert(
                int $actorUserId,
                int $ownerUserId,
                string $moduleId,
                int $wpPostId
            ): int {
                unset($actorUserId, $ownerUserId, $moduleId);

                return 900 + $wpPostId;
            }
        }
    }

    if (!class_exists(ReviewReplyRepository::class, false)) {
        final class ReviewReplyRepository
        {
            public const MODULE_NAME = 'review_reply';
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
                unset($message, $context);
            }

            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                unset($message, $context);
            }
        }
    }
}
