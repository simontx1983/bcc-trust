<?php
/**
 * Doubles for driving the real status-post write and the real feed read
 * against a real database.
 *
 * PeepSo is the only thing replaced on the write path: `PeepSoActivity` below
 * writes the same wp_posts + peepso_activities pair the real plugin does, so
 * PostsService, PeepSoStatusWriter and the repositories are all the real code.
 *
 * Declared BEFORE page-claim-endpoint-stubs.php, whose doubles are guarded, so
 * these win: `get_post` reads wp_posts (a test trashes a page with an UPDATE,
 * not by editing a global), `do_action` also records whether the page marker
 * was on the post when the event fired, and `Throttle` records every call so
 * "no slot burned" is observable.
 *
 * ⚠ Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope, for the same reason page-claim-endpoint-stubs.php says.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Post', false)) {
        class WP_Post
        {
            public int $ID = 0;
            public string $post_type = '';
            public string $post_status = '';
            public int $post_author = 0;
            public string $post_title = '';
            public string $post_name = '';
            public string $post_content = '';
            public string $post_excerpt = '';
            public string $post_date_gmt = '';
        }
    }

    if (!function_exists('get_post')) {
        /** @return WP_Post|null */
        function get_post($id = null)
        {
            global $wpdb;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT ID, post_type, post_status, post_author, post_title, post_name, post_content, post_excerpt, post_date_gmt
                   FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
                (int) $id
            ));
            if ($row === null) {
                return null;
            }

            $post               = new WP_Post();
            $post->ID           = (int) $row->ID;
            $post->post_type    = (string) $row->post_type;
            $post->post_status  = (string) $row->post_status;
            $post->post_author  = (int) $row->post_author;
            $post->post_title   = (string) $row->post_title;
            $post->post_name    = (string) $row->post_name;
            $post->post_content = (string) $row->post_content;
            $post->post_excerpt = (string) $row->post_excerpt;
            $post->post_date_gmt = (string) $row->post_date_gmt;

            return $post;
        }
    }

    if (!function_exists('do_action')) {
        function do_action(string $hook, ...$args): void
        {
            $GLOBALS['__bcc_actions'][] = [$hook, ...$args];

            if ($hook === 'bcc_post_created') {
                $GLOBALS['__bcc_marker_at_event'][] = get_post_meta((int) $args[1], '_bcc_page_id', true);
            }
        }
    }

    if (!function_exists('_prime_post_caches')) {
        function _prime_post_caches(array $ids, bool $updateTerm = true, bool $updateMeta = true): void
        {
        }
    }

    if (!function_exists('cache_users')) {
        function cache_users(array $userIds): void
        {
        }
    }

    if (!function_exists('get_users')) {
        /**
         * @param array{include?: list<int>} $args
         * @return list<object>
         */
        function get_users(array $args = []): array
        {
            return array_map(
                static fn (int $id): object => (object) [
                    'ID'           => $id,
                    'display_name' => 'User ' . $id,
                    'user_login'   => 'user' . $id,
                ],
                $args['include'] ?? []
            );
        }
    }

    if (!function_exists('user_can')) {
        function user_can($user, string $capability): bool
        {
            return false;
        }
    }

    if (!function_exists('current_user_can')) {
        function current_user_can(string $capability): bool
        {
            return false;
        }
    }

    if (!class_exists('PeepSoActivity', false)) {
        class PeepSoActivity
        {
            public const MODULE_ID = 1;

            private static int $clock = 0;

            public static function get_instance(): self
            {
                return new self();
            }

            /** @return int|false */
            public function add_post(int $author, int $owner, string $content)
            {
                global $wpdb;

                // One second apart, so "newest first" is a fact about the data.
                ++self::$clock;
                $ok = $wpdb->insert($wpdb->posts, [
                    'post_type'     => 'peepso-post',
                    'post_status'   => 'publish',
                    'post_author'   => $author,
                    'post_content'  => $content,
                    'post_date_gmt' => gmdate('Y-m-d H:i:s', 1_800_000_000 + self::$clock),
                ]);
                if ($ok === false) {
                    return false;
                }
                $postId = (int) $wpdb->insert_id;

                $ok = $wpdb->insert($wpdb->prefix . 'peepso_activities', [
                    'act_owner_id'    => $owner,
                    'act_module_id'   => self::MODULE_ID,
                    'act_external_id' => $postId,
                    'act_access'      => 10,
                ]);

                return $ok === false ? false : $postId;
            }
        }
    }

    if (!function_exists('wp_cache_get_multiple')) {
        /** @return array<int|string, mixed> */
        function wp_cache_get_multiple(array $keys, string $group = '', bool $force = false): array
        {
            return array_fill_keys($keys, false);
        }
    }

    if (!function_exists('wp_cache_set_multiple')) {
        /** @return array<int|string, bool> */
        function wp_cache_set_multiple(array $data, string $group = '', int $expire = 0): array
        {
            return array_fill_keys(array_keys($data), true);
        }
    }

    if (!function_exists('wp_strip_all_tags')) {
        function wp_strip_all_tags(string $text, bool $removeBreaks = false): string
        {
            return trim(strip_tags($text));
        }
    }

    if (!function_exists('get_avatar_url')) {
        function get_avatar_url($idOrEmail, $args = null): string
        {
            return '';
        }
    }

    if (!function_exists('get_the_post_thumbnail_url')) {
        /** @return string|false */
        function get_the_post_thumbnail_url($post = null, $size = 'post-thumbnail')
        {
            return false;
        }
    }

    if (!function_exists('get_post_thumbnail_id')) {
        function get_post_thumbnail_id($post = null): int
        {
            return 0;
        }
    }

    if (!function_exists('get_userdata')) {
        function get_userdata(int $userId)
        {
            return (object) ['ID' => $userId, 'user_login' => 'user' . $userId, 'display_name' => 'User ' . $userId];
        }
    }

    if (!function_exists('get_posts')) {
        /** @return list<int> */
        function get_posts(array $args = []): array
        {
            return [];
        }
    }

    if (!function_exists('get_page_by_path')) {
        /** @return WP_Post|null */
        function get_page_by_path(string $path, $output = 'OBJECT', string $postType = 'page')
        {
            global $wpdb;
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = %s LIMIT 1",
                $path,
                $postType
            ));

            return $id === null ? null : get_post((int) $id);
        }
    }

    require_once __DIR__ . '/page-claim-endpoint-stubs.php';
}

namespace BCC\Core\Permissions {
    // The real check needs bcc-trust's read service registered through a filter
    // this harness does not run, and fails closed without it.
    if (!class_exists(Permissions::class, false)) {
        final class Permissions
        {
            public static function is_not_suspended(?int $userId = null, bool $allowAdminBypass = true): bool
            {
                return !in_array($userId, $GLOBALS['__bcc_suspended'] ?? [], true);
            }

            /**
             * Tri-state mirror of bcc-core Permissions::suspensionState() (#84):
             * TRUE suspended, FALSE not, NULL unknown. Ids listed in
             * $GLOBALS['__bcc_suspension_unknown'] read as UNKNOWN.
             */
            public static function suspensionState(?int $userId = null, bool $allowAdminBypass = true): ?bool
            {
                if (in_array($userId, $GLOBALS['__bcc_suspension_unknown'] ?? [], true)) {
                    return null;
                }
                return !self::is_not_suspended((int) $userId, $allowAdminBypass);
            }

            public static function owns_page(int $pageId, ?int $userId = null): bool
            {
                return (bool) ($GLOBALS['__bcc_owns_page'] ?? false);
            }
        }
    }
}

namespace BCC\Core\Security {
    if (!class_exists(Throttle::class, false)) {
        final class Throttle
        {
            public static function allow(string $action, int $limit = 0, int $window = 0): bool
            {
                $GLOBALS['__bcc_throttle_calls'][] = $action;

                return true;
            }
        }
    }
}
