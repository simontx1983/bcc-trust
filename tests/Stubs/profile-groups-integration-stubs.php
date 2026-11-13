<?php
/**
 * F-330 stubs for ProfileGroupsVisibilityIntegrationTest (subprocess-only).
 *
 * The group rows, group meta and membership rows are REAL (MySQL), and so is
 * every read the visibility rule makes over them. Doubled here, because the
 * harness has no WordPress user/session layer and no PeepSo:
 *   - the session identity (get_current_user_id);
 *   - user meta and the handle lookup (get_user_meta / get_users /
 *     get_userdata), from $GLOBALS['__bcc_pg'];
 *   - PeepSoUser's profile accessibility (every profile here is public, so
 *     the §F-02 profile-wide gate admits every viewer and the test is about
 *     the GROUP rule only);
 *   - WP_Post / get_post / _prime_post_caches, reading the real wp_posts.
 *
 * Fixture ($GLOBALS['__bcc_pg']):
 *   viewer  int                                  the session user (0 = anonymous)
 *   users   array<int, array{login: string, handle: string, name: string}>
 *   meta    array<int, array<string, mixed>>     user_id => meta key => value
 */

declare(strict_types=1);

namespace {
    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_pg']['viewer'] ?? 0);
        }
    }

    if (!function_exists('get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            $meta = $GLOBALS['__bcc_pg']['meta'][$userId] ?? [];
            if ($key === '') {
                return $meta;
            }
            if (!array_key_exists($key, $meta)) {
                return $single ? '' : [];
            }

            return $single ? $meta[$key] : [$meta[$key]];
        }
    }

    if (!function_exists('get_users')) {
        /** @return list<int> */
        function get_users(array $args = []): array
        {
            $key   = (string) ($args['meta_key'] ?? '');
            $value = (string) ($args['meta_value'] ?? '');
            if ($key === '') {
                return [];
            }
            $out = [];
            foreach ($GLOBALS['__bcc_pg']['meta'] ?? [] as $userId => $meta) {
                if (isset($meta[$key]) && (string) $meta[$key] === $value) {
                    $out[] = (int) $userId;
                }
            }

            return $out;
        }
    }

    if (!class_exists('WP_User', false)) {
        class WP_User
        {
            public int $ID = 0;
            public string $user_login = '';
            public string $display_name = '';
            public string $user_nicename = '';
            public string $user_email = '';
            public string $user_registered = '';
            /** @var list<string> */
            public array $roles = [];

            public function exists(): bool
            {
                return $this->ID > 0;
            }

            public function has_cap(string $cap): bool
            {
                return false;
            }
        }
    }

    if (!function_exists('get_userdata')) {
        function get_userdata(int $userId)
        {
            $u = $GLOBALS['__bcc_pg']['users'][$userId] ?? null;
            if ($u === null) {
                return false;
            }

            $user                  = new WP_User();
            $user->ID              = $userId;
            $user->user_login      = $u['login'];
            $user->display_name    = $u['name'];
            $user->user_nicename   = $u['login'];
            $user->user_email      = $u['login'] . '@example.test';
            $user->user_registered = '2026-01-01 00:00:00';
            $user->roles           = ['subscriber'];

            return $user;
        }
    }

    if (!function_exists('user_can')) {
        /**
         * Same rule as the harness's current_user_can(): the capabilities
         * in $GLOBALS['__bcc_test_caps'] belong to the SESSION user only.
         *
         * @param mixed ...$args
         */
        function user_can($user, string $capability, ...$args): bool
        {
            $id = is_object($user) ? (int) ($user->ID ?? 0) : (int) $user;
            if ($id <= 0 || $id !== (int) ($GLOBALS['__bcc_pg']['viewer'] ?? 0)) {
                return false;
            }
            $caps = $GLOBALS['__bcc_test_caps'] ?? [];

            return is_array($caps) && in_array($capability, $caps, true);
        }
    }

    if (!function_exists('cache_users')) {
        function cache_users(array $userIds): void
        {
        }
    }

    if (!function_exists('get_avatar_url')) {
        function get_avatar_url($idOrEmail, array $args = []): string
        {
            return '';
        }
    }

    if (!function_exists('get_user_by')) {
        function get_user_by(string $field, $value)
        {
            foreach ($GLOBALS['__bcc_pg']['users'] ?? [] as $id => $u) {
                $match = match ($field) {
                    'id', 'ID'       => (int) $value === (int) $id,
                    'login', 'slug'  => (string) $value === $u['login'],
                    default          => false,
                };
                if ($match) {
                    return get_userdata((int) $id);
                }
            }

            return false;
        }
    }

    if (!class_exists('PeepSoUser', false)) {
        class PeepSoUser
        {
            public function __construct(private int $id)
            {
            }

            public static function get_instance(int $id = 0): self
            {
                return new self($id);
            }

            /** PeepSo's public level: every profile here is public. */
            public function get_profile_accessibility(): int
            {
                return 10;
            }

            /** PeepSo's ladder, public rung only (all this suite uses). */
            public static function is_accessible_static(int $level, int $ownerId): bool
            {
                return $level <= 10;
            }

            /**
             * Profile CHROME the /users/:handle composer reads (avatar,
             * names, cover, ...) has no bearing on which groups are shown,
             * so any other method answers '' and is recorded, rather than
             * this double restating PeepSo's whole user API. The group rule
             * never reaches here.
             *
             * @param array<mixed> $args
             */
            public function __call(string $name, array $args): string
            {
                $GLOBALS['__bcc_pg']['peepso_user_calls'][] = $name;

                return '';
            }
        }
    }

    if (!class_exists('WP_Post', false)) {
        final class WP_Post
        {
            public int $ID = 0;
            public string $post_type = '';
            public string $post_status = '';
            public string $post_title = '';
            public string $post_name = '';
            public int $post_author = 0;
        }
    }

    if (!function_exists('get_post')) {
        function get_post($post = null)
        {
            global $wpdb;
            $id  = is_object($post) ? (int) ($post->ID ?? 0) : (int) $post;
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT ID, post_type, post_status, post_title, post_name FROM {$wpdb->posts} WHERE ID = %d",
                $id
            ));
            if (!is_object($row)) {
                return null;
            }
            $p              = new WP_Post();
            $p->ID          = (int) $row->ID;
            $p->post_type   = (string) $row->post_type;
            $p->post_status = (string) $row->post_status;
            $p->post_title  = (string) $row->post_title;
            $p->post_name   = (string) $row->post_name;

            return $p;
        }
    }

    if (!class_exists('WP_REST_Request', false)) {
        class WP_REST_Request
        {
            /** @param array<string, mixed> $params */
            public function __construct(private array $params = [])
            {
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
            /** @var array<string, string> */
            private array $headers = [];

            /** @param mixed $data */
            public function __construct(private $data = null, private int $status = 200)
            {
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

            public function set_status(int $status): void
            {
                $this->status = $status;
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

    if (!function_exists('_prime_post_caches')) {
        function _prime_post_caches(array $ids, bool $updateTermCache = true, bool $updateMetaCache = true): void
        {
        }
    }
}
