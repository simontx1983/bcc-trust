<?php
/**
 * Stubs for the F-346 repost routes.
 *
 * ⚠⚠ THIS FILE MUST ONLY BE REQUIRED FROM A PROCESS-ISOLATED TEST. It
 * declares `BCC\Trust\Core\Support\FeedItemGate`, `GroupInteractionGate`
 * and `NoReactionsGate` — REAL production class names — which works only
 * because declaring them first stops the autoloader ever reaching the real
 * files. In a shared process that would replace those three classes for
 * every other suite in the run, and an unrelated test would silently stop
 * exercising the real gate. The consuming test is
 * `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`; keep
 * it that way.
 *
 * ── WHAT IS DOUBLED, AND WHY THAT IS THE RIGHT SEAM ─────────────────
 * The REAL `RepostEndpoint` runs. What is doubled is everything it
 * ORCHESTRATES, because the orchestration is the thing under test: that
 * both the submitted id AND the resolved root go through the same three
 * gates, that the lock wraps the check-then-write, and that the write is
 * handed the ROOT.
 *
 * ⚠ So these tests prove the WIRING and nothing about the gates
 * themselves. A gate that wrongly admits a secret-group post would pass
 * every test here. The gates have their own suites
 * (`group-interaction-gate-stubs.php`, `feed-item-gate-stubs.php`,
 * `no-reactions-stubs.php`); this file deliberately does not re-test them.
 *
 * ⚠ `ApiResponse` is NOT doubled — the real envelope is asserted, so a
 * status or code change shows up here.
 *
 * ⚠ Guarded with `function_exists` / `class_exists` throughout, because
 * several Stubs files declare overlapping doubles and more than one may be
 * required into the same process.
 *
 * ⚠⚠ EVERY `class_exists` HERE PASSES `false` AS THE SECOND ARGUMENT. It
 * autoloads by default, so the plain form LOADS THE REAL CLASS and then
 * skips the double as "already declared" — the stub silently does nothing
 * and the test exercises production collaborators with no $wpdb. That is
 * not a hypothetical: it is how this file behaved when first written.
 */

declare(strict_types=1);

namespace {
    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/wp/');
    }

    if (!class_exists('WP_REST_Server', false)) {
        class WP_REST_Server
        {
            public const READABLE  = 'GET';
            public const CREATABLE = 'POST';
            public const EDITABLE  = 'POST, PUT, PATCH';
            public const DELETABLE = 'DELETE';
        }
    }

    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            /** @var array<string, mixed> */
            private array $params;

            /** @param array<string, mixed> $params */
            public function __construct(array $params = [])
            {
                $this->params = $params;
            }

            /** @return mixed */
            public function get_param(string $key)
            {
                return $this->params[$key] ?? null;
            }

            /** @param mixed $value */
            public function set_param(string $key, $value): void
            {
                $this->params[$key] = $value;
            }

            /** @return array<string, mixed> */
            public function get_params(): array
            {
                return $this->params;
            }
        }
    }

    if (!class_exists('WP_REST_Response', false)) {
        class WP_REST_Response
        {
            /** @var mixed */
            private $data;
            private int $status;
            /** @var array<string, string> */
            private array $headers = [];

            /** @param mixed $data */
            public function __construct($data = null, int $status = 200)
            {
                $this->data   = $data;
                $this->status = $status;
            }

            /** @return mixed */
            public function get_data()
            {
                return $this->data;
            }

            public function get_status(): int
            {
                return $this->status;
            }

            public function header(string $key, string $value): void
            {
                $this->headers[$key] = $value;
            }

            /** @return array<string, string> */
            public function get_headers(): array
            {
                return $this->headers;
            }
        }
    }

    /**
     * Append to the single ordered trace every assertion reads.
     *
     * ⭐ ONE trace, not one recorder per collaborator, and that is the
     * point: the properties under test are about ORDER (throttle before
     * credentials, lock before the check, gate before the write), and
     * separate recorders cannot express order between two of them.
     *
     * @param mixed $detail
     */
    function bcc_repost_trace(string $event, $detail = null): void
    {
        $GLOBALS['__bcc_repost_trace'][] = $detail === null
            ? $event
            : [$event, $detail];
    }
}

namespace BCC\Trust\Core\REST {
    if (!function_exists('BCC\Trust\Core\REST\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_repost_user'] ?? 0);
        }
    }

    if (!function_exists('BCC\Trust\Core\REST\register_rest_route')) {
        /** @param array<string, mixed> $args */
        function register_rest_route(string $namespace, string $route, array $args = []): bool
        {
            $GLOBALS['__bcc_repost_routes'][] = [
                'namespace' => $namespace,
                'route'     => $route,
                'methods'   => $args['methods'] ?? null,
                'callback'  => $args['callback'] ?? null,
            ];

            return true;
        }
    }

    if (!function_exists('BCC\Trust\Core\REST\wp_trash_post')) {
        /**
         * Returns the post "object" on success, matching core's truthiness
         * contract, and false when the test asks it to fail.
         *
         * @return object|false
         */
        function wp_trash_post(int $postId)
        {
            \bcc_repost_trace('trash', $postId);

            if (in_array($postId, (array) ($GLOBALS['__bcc_repost_trash_fail'] ?? []), true)) {
                return false;
            }

            return (object) ['ID' => $postId];
        }
    }

    if (!function_exists('BCC\Trust\Core\REST\do_action')) {
        /** @param mixed ...$args */
        function do_action(string $hook, ...$args): void
        {
            \bcc_repost_trace('do_action', array_merge([$hook], $args));
        }
    }
}

namespace BCC\Trust\Core\Support {
    // ReactionTypeRegistry (reached by the REAL ApiResponse::ok) reads this.
    if (!function_exists('BCC\Trust\Core\Support\get_option')) {
        /** @param mixed $default @return mixed */
        function get_option(string $key, $default = false)
        {
            return $GLOBALS['__bcc_repost_options'][$key] ?? $default;
        }
    }

    /**
     * ⚠ A REAL CLASS NAME, replaced. See this file's header: only safe
     * under process isolation. The double records which act id it was
     * asked about and refuses the ids the test names.
     */
    if (!class_exists('BCC\Trust\Core\Support\FeedItemGate', false)) {
        final class FeedItemGate
        {
            public static function check(int $actId): ?\WP_REST_Response
            {
                \bcc_repost_trace('FeedItemGate', $actId);

                return in_array($actId, (array) ($GLOBALS['__bcc_repost_refuse_feed_item'] ?? []), true)
                    ? ApiResponse::error('bcc_not_found', 'Post not found.', 404)
                    : null;
            }

            /** @param int|string|null $moduleId */
            public static function isFeedModule($moduleId): bool
            {
                return true;
            }
        }
    }

    if (!class_exists('BCC\Trust\Core\Support\GroupInteractionGate', false)) {
        final class GroupInteractionGate
        {
            public static function check(int $userId, int $actId): ?\WP_REST_Response
            {
                \bcc_repost_trace('GroupInteractionGate', [$userId, $actId]);

                return in_array($actId, (array) ($GLOBALS['__bcc_repost_refuse_group'] ?? []), true)
                    ? ApiResponse::error('bcc_not_found', 'Post not found.', 404)
                    : null;
            }
        }
    }

    if (!class_exists('BCC\Trust\Core\Support\NoReactionsGate', false)) {
        final class NoReactionsGate
        {
            public static function check(int $actId): ?\WP_REST_Response
            {
                \bcc_repost_trace('NoReactionsGate', $actId);

                return in_array($actId, (array) ($GLOBALS['__bcc_repost_refuse_no_reactions'] ?? []), true)
                    ? ApiResponse::error('bcc_invalid_request', 'This post takes no reactions.', 400)
                    : null;
            }
        }
    }
}

namespace BCC\Trust\Core\Repositories {
    /**
     * ⚠ A REAL CLASS NAME, replaced — the repository's own SQL and
     * fail-closed behaviour are covered by
     * `PostEngagementRepostCountSqlTest` and
     * `PostEngagementViewerRepostsSqlTest` against a $wpdb double. Here it
     * is a dial, so the endpoint's three cases (none / already / unreadable)
     * are all reachable.
     */
    if (!class_exists('BCC\Trust\Core\Repositories\PostEngagementRepository', false)) {
        final class PostEngagementRepository
        {
            /** @return list<array{act_id: int, post_id: int}>|null */
            public static function viewerReposts(int $rootActId, int $ownerId): ?array
            {
                \bcc_repost_trace('viewerReposts', [$rootActId, $ownerId]);

                if (($GLOBALS['__bcc_repost_read_fails'] ?? false) === true) {
                    return null;
                }

                /** @var list<array{act_id: int, post_id: int}> $mine */
                $mine = $GLOBALS['__bcc_repost_mine'] ?? [];

                return $mine;
            }

            public static function repostCount(int $actId): ?int
            {
                \bcc_repost_trace('repostCount', $actId);

                // ⚠ array_key_exists, NOT `??`. A stored null is the
                // "could not read" case this double exists to produce, and
                // `??` treats it as unset and answers 0 instead — turning
                // the unknown/zero distinction into the very bug the
                // production code is written to avoid.
                if (!array_key_exists('__bcc_repost_count', $GLOBALS)) {
                    return 0;
                }

                /** @var int|null $count */
                $count = $GLOBALS['__bcc_repost_count'];

                return $count;
            }
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists('BCC\Core\Security\Throttle', false)) {
        final class Throttle
        {
            public static function allow(
                string $action,
                int $limit = 10,
                int $window = 60,
                ?string $key = null,
                bool $countDenied = true
            ): bool {
                \bcc_repost_trace('throttle', [$action, $limit, $window]);

                return ($GLOBALS['__bcc_repost_throttled'] ?? false) !== true;
            }
        }
    }
}

namespace BCC\Core\Permissions {
    if (!class_exists('BCC\Core\Permissions\Permissions', false)) {
        final class Permissions
        {
            public static function is_not_suspended(int $userId, bool $allowAdminBypass = true): bool
            {
                \bcc_repost_trace('suspension', [$userId, $allowAdminBypass]);

                return ($GLOBALS['__bcc_repost_suspended'] ?? false) !== true;
            }
        }
    }
}

namespace BCC\Core\DB {
    if (!class_exists('BCC\Core\DB\AdvisoryLock', false)) {
        final class AdvisoryLock
        {
            public static function acquire(string $name, int $timeout = 0): bool
            {
                \bcc_repost_trace('lock_acquire', [$name, $timeout]);

                return ($GLOBALS['__bcc_repost_lock_held_elsewhere'] ?? false) !== true;
            }

            public static function release(string $name): bool
            {
                \bcc_repost_trace('lock_release', $name);

                return true;
            }
        }
    }
}

namespace BCC\Core\Log {
    if (!class_exists('BCC\Core\Log\Logger', false)) {
        final class Logger
        {
            /** @param array<string, mixed> $context */
            public static function error(string $message, array $context = []): void
            {
                \bcc_repost_trace('log_error', $message);
            }

            /** @param array<string, mixed> $context */
            public static function warning(string $message, array $context = []): void
            {
            }

            /** @param array<string, mixed> $context */
            public static function audit(string $message, array $context = []): void
            {
            }
        }
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists('BCC\Core\Repositories\PeepSoActivityRepository', false)) {
        final class PeepSoActivityRepository
        {
            /**
             * Fails closed at 0, like the real one. The map lets a test make
             * the submitted id differ from the root, which is the whole
             * point of the double-gate case.
             */
            public static function resolveRepostRoot(int $actId): int
            {
                \bcc_repost_trace('resolveRoot', $actId);

                /** @var array<int, int> $roots */
                $roots = $GLOBALS['__bcc_repost_roots'] ?? [];

                return $roots[$actId] ?? $actId;
            }
        }
    }
}

namespace BCC\Core\PeepSo {
    if (!class_exists('BCC\Core\PeepSo\PeepSoStatusWriter', false)) {
        final class PeepSoStatusWriter
        {
            /** @return array{ok: bool, reason?: string, post_id?: int, act_id?: int} */
            public static function createSelfStatus(
                int $authorId,
                string $content,
                int $groupId = 0,
                string $visibility = 'members_only',
                int $repostOfRootActId = 0
            ): array {
                \bcc_repost_trace('createSelfStatus', [
                    'author'     => $authorId,
                    'content'    => $content,
                    'group'      => $groupId,
                    'visibility' => $visibility,
                    'root'       => $repostOfRootActId,
                ]);

                if (($GLOBALS['__bcc_repost_write_fails'] ?? false) === true) {
                    return ['ok' => false, 'reason' => 'persist_failed'];
                }

                return ['ok' => true, 'post_id' => 9001, 'act_id' => 9002];
            }
        }
    }
}
