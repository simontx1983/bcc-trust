<?php
/**
 * Fixture-backed stubs for PageClaimEventDispatchTest.
 *
 * Loaded ONLY inside a PHPUnit subprocess (RunTestsInSeparateProcesses), and
 * required from setUp() rather than file scope: these declare functions at
 * the FQNs the real endpoint uses, and a file-scope declaration would
 * pollute the PHPUnit discovery parent.
 *
 * ⚠⚠ THE ADVISORY LOCK STUB MODELS MySQL's REAL SEMANTICS, including the two
 * that decide whether a test here means anything:
 *
 *   - GET_LOCK is held per CONNECTION. A second connection asking for a
 *     held key waits and then fails. That is what `session` models, and it
 *     is the only honest way to simulate two overlapping HTTP requests in
 *     one PHP process.
 *   - GET_LOCK is REENTRANT within one connection. The same session may
 *     acquire the same key again and must release it as many times. So a
 *     test must NOT assert that a nested acquire is refused — a real lock
 *     would grant it. (Verified in
 *     HalfOpenProbeOwnershipIntegrationTest::...reentrant.)
 *
 * Fixture shape ($GLOBALS['__bcc_dispatch']):
 *   session      string                  the "connection" acting now
 *   held         array<string, array>    lock name => {session, depth}
 *   acquires     list<array>             {key, timeout, session, granted}
 *   releases     list<string>            lock names released
 *   trace        list<string>            ordered events (acquire/read/dispatch/release)
 *   post_author  array<int, int>         page id => post_author
 *   reads        int                     get_post_field() call count
 *   dispatched   list<array>             {hook, args} per do_action
 *   on_dispatch  ?\Closure               runs INSIDE do_action, to interleave
 *   logs         list<array>             {level, message, ctx}
 */

declare(strict_types=1);

namespace BCC\Core\DB {
    if (!class_exists(AdvisoryLock::class, false)) {
        final class AdvisoryLock
        {
            public static function acquire(string $key, int $timeout = 0): bool
            {
                $state   = &$GLOBALS['__bcc_dispatch'];
                $session = (string) $state['session'];
                $holder  = $state['held'][$key]['session'] ?? null;

                $granted = ($holder === null || $holder === $session);

                $state['acquires'][] = [
                    'key'     => $key,
                    'timeout' => $timeout,
                    'session' => $session,
                    'granted' => $granted,
                ];
                $state['trace'][] = ($granted ? 'acquire:' : 'acquire_refused:') . $key;

                if (!$granted) {
                    return false;
                }

                $state['held'][$key] = [
                    'session' => $session,
                    'depth'   => (int) ($state['held'][$key]['depth'] ?? 0) + 1,
                ];

                return true;
            }

            public static function release(string $key): void
            {
                $state = &$GLOBALS['__bcc_dispatch'];

                $state['releases'][] = $key;
                $state['trace'][]    = 'release:' . $key;

                if (!isset($state['held'][$key])) {
                    return;
                }
                if (--$state['held'][$key]['depth'] <= 0) {
                    unset($state['held'][$key]);
                }
            }
        }
    }
}

namespace BCC\Trust\Core\REST {
    if (!function_exists(__NAMESPACE__ . '\\get_post_field')) {
        /**
         * @param int|string $postId
         * @return int|string
         */
        function get_post_field(string $field, $postId = 0)
        {
            $postId = (int) $postId;

            $GLOBALS['__bcc_dispatch']['reads']++;
            $GLOBALS['__bcc_dispatch']['trace'][] = 'read:' . $field . ':' . $postId;

            return (int) ($GLOBALS['__bcc_dispatch']['post_author'][$postId] ?? 0);
        }
    }

    if (!function_exists(__NAMESPACE__ . '\\do_action')) {
        /** @param mixed ...$args */
        function do_action(string $hook, ...$args): void
        {
            $GLOBALS['__bcc_dispatch']['trace'][]     = 'dispatch:' . $hook;
            $GLOBALS['__bcc_dispatch']['dispatched'][] = ['hook' => $hook, 'args' => $args];

            // The seam that lets a test interleave a SECOND delivery while
            // the first still holds the lock — i.e. the overlap itself.
            $hook_cb = $GLOBALS['__bcc_dispatch']['on_dispatch'] ?? null;
            if ($hook_cb instanceof \Closure) {
                $hook_cb(...$args);
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists(Logger::class, false)) {
        final class Logger
        {
            /** @param array<string, mixed> $ctx */
            public static function error(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_dispatch']['logs'][] = ['level' => 'error', 'message' => $m, 'ctx' => $ctx];
            }
            /** @param array<string, mixed> $ctx */
            public static function warning(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_dispatch']['logs'][] = ['level' => 'warning', 'message' => $m, 'ctx' => $ctx];
            }
            /** @param array<string, mixed> $ctx */
            public static function info(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_dispatch']['logs'][] = ['level' => 'info', 'message' => $m, 'ctx' => $ctx];
            }
            /** @param array<string, mixed> $ctx */
            public static function debug(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_dispatch']['logs'][] = ['level' => 'debug', 'message' => $m, 'ctx' => $ctx];
            }
            /** @param array<string, mixed> $ctx */
            public static function audit(string $m, array $ctx = []): void
            {
                $GLOBALS['__bcc_dispatch']['logs'][] = ['level' => 'audit', 'message' => $m, 'ctx' => $ctx];
            }
        }
    }
}
