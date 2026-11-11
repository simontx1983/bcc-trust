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

namespace BCC\Trust\Core\REST {
    /**
     * ⚠ Needed since the claim endpoint learned to finish an INTERRUPTED
     * ownership transfer: both the pre-lock fast path and the authoritative
     * in-lock re-check read `post_author`. Without this the retry branch
     * errored with "Call to undefined function" instead of being exercised,
     * so `testResubmittingAValidClaimChangesNothing` never reached its own
     * assertions.
     *
     * Reads the same `$GLOBALS['__bcc_posts']` store as
     * announcement-endpoint-stubs' `get_post`, so a test models the transfer
     * by setting `post_author` on the WP_Post it inserted — which is what
     * PeepSoIntegration does in production and what this harness, which
     * registers no subscribers, otherwise never does.
     */
    if (!function_exists('BCC\\Trust\\Core\\REST\\get_post_field')) {
        /**
         * @param  int|string $postId
         * @return mixed
         */
        function get_post_field(string $field, $postId = 0)
        {
            $post = $GLOBALS['__bcc_posts'][(int) $postId] ?? null;
            if (!is_object($post) || !property_exists($post, $field)) {
                return '';
            }

            return $post->{$field};
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

namespace BCC\Core\Permissions {
    // F-109: PagesEndpoint::claim() now calls the REAL
    // Permissions::is_not_suspended(), which calls user_can() unqualified.
    // The integration bootstrap has no user_can(). This is namespace-scoped,
    // so it binds ONLY for Permissions, and it lives here (loaded only by this
    // separate-process suite), never in the bootstrap. The fixture user is
    // not an administrator. Suspension itself is still read for real (no
    // user_info row → not suspended).
    if (!function_exists(__NAMESPACE__ . '\\user_can')) {
        /** @param int|\WP_User $user */
        function user_can($user, string $capability, ...$args): bool
        {
            return false;
        }
    }
}

namespace BCC\Core {
    // F-109: resolve the REAL TrustReadService, as bcc-trust.php does in
    // production with `add_filter('bcc.resolve.trust_read_service', …)`.
    // The bootstrap's apply_filters() is a pass-through, so ServiceLocator
    // received null and fell back to NullTrustReadService, whose
    // isSuspended() is fail-closed TRUE. Every fixture user therefore read as
    // SUSPENDED and every claim got a 403. With this shim, suspension is read
    // from the real user_info table: no row → not suspended (as in prod), and
    // is_suspended = 1 → suspended.
    //
    // Namespace-scoped, so it binds ONLY for unqualified calls in BCC\Core,
    // where ServiceLocator is the only caller. Every other hook is delegated
    // to the global function unchanged.
    if (!function_exists(__NAMESPACE__ . '\\apply_filters')) {
        /**
         * @param  mixed $value
         * @param  mixed ...$args
         * @return mixed
         */
        function apply_filters(string $hook, $value, ...$args)
        {
            if ($hook === 'bcc.resolve.trust_read_service') {
                return new \BCC\Trust\Core\Application\TrustReadService(
                    new \BCC\Trust\Core\Repositories\VoteRepository()
                );
            }

            return \apply_filters($hook, $value, ...$args);
        }
    }
}
