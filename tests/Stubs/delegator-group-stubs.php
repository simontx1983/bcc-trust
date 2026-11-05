<?php
/**
 * Doubles for driving the delegator-group routes, the join gate and the revoke
 * sweep against a real database.
 *
 * ── WHAT IS REAL ────────────────────────────────────────────────────────
 * Every repository and its SQL, `ValidatorPageOperatorAuthority`, the provisioning
 * service, `DelegationEligibilityService` and its verdict arithmetic,
 * `ValidatorGroupGateService`, `ValidatorGroupRevokeService`, bcc-core's
 * `PeepSoGroupWriter`, `AdvisoryLock` and `AuditLogger`. Nothing that decides is
 * faked.
 *
 * ── WHAT IS FAKED ───────────────────────────────────────────────────────
 *   - PeepSo's group classes. They write real rows, because the exists check,
 *     the sweep and the join all read those rows back.
 *   - The LCD, one level below the eligibility service: `$GLOBALS['__bcc_lcd']`
 *     maps a wallet address to its delegations, and a present null is a
 *     transport failure. So a "verdict" in these tests is computed by the real
 *     code from what the chain would have answered.
 *   - Hooks: add_action / do_action DISPATCH instead of no-opping, and record,
 *     so handlers lifted out of bcc-trust.php really run.
 *   - User meta, as a real in-process store. A fake that always read empty could
 *     not catch an opt-out being written, and "no opt-out" is a required claim.
 *
 * ⚠ Required from setUp() of a #[RunTestsInSeparateProcesses] class, never at
 * file scope: these names would otherwise shadow the real ones for every other
 * suite in the run.
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Post', false)) {
        class WP_Post
        {
            public int $ID = 0;
            public string $post_type = '';
            public string $post_status = '';
            public string $post_title = '';
            public int $post_author = 0;
        }
    }

    if (!function_exists('add_action')) {
        function add_action(string $hook, $callback, int $priority = 10, int $acceptedArgs = 1): bool
        {
            $GLOBALS['__bcc_hooks'][$hook][] = [$callback, $acceptedArgs];

            return true;
        }
    }

    if (!function_exists('do_action')) {
        function do_action(string $hook, ...$args): void
        {
            $GLOBALS['__bcc_actions'][] = [$hook, ...$args];
            foreach ($GLOBALS['__bcc_hooks'][$hook] ?? [] as [$callback, $acceptedArgs]) {
                $callback(...array_slice($args, 0, $acceptedArgs));
            }
        }
    }

    if (!function_exists('get_user_meta')) {
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            $value = $GLOBALS['__bcc_usermeta'][$userId][$key] ?? null;
            if ($single) {
                return $value ?? '';
            }

            return $value === null ? [] : [$value];
        }
    }

    if (!function_exists('update_user_meta')) {
        function update_user_meta(int $userId, string $key, $value): bool
        {
            $GLOBALS['__bcc_usermeta'][$userId][$key] = $value;

            return true;
        }
    }

    if (!function_exists('delete_user_meta')) {
        function delete_user_meta(int $userId, string $key): bool
        {
            unset($GLOBALS['__bcc_usermeta'][$userId][$key]);

            return true;
        }
    }

    if (!function_exists('delete_post_meta')) {
        function delete_post_meta(int $postId, string $key): bool
        {
            $wpdb = $GLOBALS['wpdb'];

            return $wpdb->query($wpdb->prepare(
                "DELETE FROM `{$wpdb->postmeta}` WHERE post_id = %d AND meta_key = %s",
                $postId,
                $key
            )) !== false;
        }
    }

    if (!function_exists('wp_delete_post')) {
        function wp_delete_post(int $postId, bool $force = false)
        {
            $wpdb = $GLOBALS['wpdb'];
            $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->postmeta}` WHERE post_id = %d", $postId));

            return $wpdb->query($wpdb->prepare("DELETE FROM `{$wpdb->posts}` WHERE ID = %d", $postId)) !== false;
        }
    }

    /**
     * Writes what PeepSo's constructor writes that the code under test reads
     * back: the post, and the owner's member row.
     */
    if (!class_exists('PeepSoGroup', false)) {
        class PeepSoGroup
        {
            private int $id = 0;

            /** @param array<string, mixed>|null $data */
            public function __construct(?int $id = null, ?array $data = null)
            {
                if ($data === null) {
                    $this->id = (int) $id;

                    return;
                }

                $GLOBALS['__bcc_group_creates'][] = $data;

                $hook = $GLOBALS['__bcc_group_on_create'] ?? null;
                if (is_callable($hook)) {
                    $hook();
                }

                $wpdb = $GLOBALS['wpdb'];
                $wpdb->insert($wpdb->posts, [
                    'post_title'   => (string) $data['name'],
                    'post_name'    => 'group-' . count($GLOBALS['__bcc_group_creates']),
                    'post_content' => (string) $data['description'],
                    'post_type'    => 'peepso-group',
                    'post_status'  => 'publish',
                ]);
                $this->id = (int) $wpdb->insert_id;

                $wpdb->insert($wpdb->prefix . 'peepso_group_members', [
                    'gm_group_id'    => $this->id,
                    'gm_user_id'     => (int) $data['owner_id'],
                    'gm_user_status' => 'member_owner',
                ]);
            }

            public function get(string $key): int
            {
                return $key === 'id' ? $this->id : 0;
            }
        }
    }

    if (!class_exists('PeepSoGroupUser', false)) {
        class PeepSoGroupUser
        {
            public function __construct(private int $groupId, private int $userId)
            {
            }

            public function member_join(): void
            {
                $wpdb = $GLOBALS['wpdb'];
                $wpdb->insert($wpdb->prefix . 'peepso_group_members', [
                    'gm_group_id'    => $this->groupId,
                    'gm_user_id'     => $this->userId,
                    'gm_user_status' => 'member',
                ]);
            }

            public function member_leave(): void
            {
                $wpdb = $GLOBALS['wpdb'];
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM `{$wpdb->prefix}peepso_group_members` WHERE gm_group_id = %d AND gm_user_id = %d",
                    $this->groupId,
                    $this->userId
                ));
            }
        }
    }

    if (!class_exists('PeepSoGroupUsers', false)) {
        class PeepSoGroupUsers
        {
            public function __construct(private int $groupId)
            {
            }

            public function update_members_count(): void
            {
            }
        }
    }

    /**
     * PeepSo's own bell write, down to the `substr($msg, 0, 200)` it applies to a
     * VARCHAR(200) column, because that limit is why the notice isn't stored.
     */
    if (!class_exists('PeepSoNotifications', false)) {
        class PeepSoNotifications
        {
            public static function get_instance(): self
            {
                return new self();
            }

            public function add_notification($from, $to, $msg, $type, $moduleId, $external = 0, $actId = 0)
            {
                $wpdb = $GLOBALS['wpdb'];

                return $wpdb->insert($wpdb->prefix . 'peepso_notifications', [
                    'not_user_id'      => $to,
                    'not_from_user_id' => $from,
                    'not_module_id'    => $moduleId,
                    'not_external_id'  => $external,
                    'not_act_id'       => $actId,
                    'not_type'         => $type,
                    'not_message'      => substr($msg, 0, 200),
                    'not_timestamp'    => gmdate('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    if (!function_exists('wp_mail')) {
        function wp_mail($to, $subject, $message, $headers = '', $attachments = []): bool
        {
            $GLOBALS['__bcc_mail'][] = [$to, $subject];

            return true;
        }
    }

    if (!function_exists('wp_cache_get_multiple')) {
        function wp_cache_get_multiple(array $keys, string $group = '', bool $force = false): array
        {
            $out = [];
            foreach ($keys as $key) {
                $out[$key] = wp_cache_get($key, $group);
            }

            return $out;
        }
    }

    if (!function_exists('get_avatar_url')) {
        function get_avatar_url($userId)
        {
            return 'https://cms.example.test/avatar/' . (int) $userId . '.png';
        }
    }

    if (!function_exists('wp_parse_url')) {
        function wp_parse_url(string $url, int $component = -1)
        {
            return parse_url($url, $component);
        }
    }

    if (!function_exists('home_url')) {
        function home_url(string $path = ''): string
        {
            return 'https://cms.example.test' . $path;
        }
    }

    if (!function_exists('site_url')) {
        function site_url(string $path = ''): string
        {
            return 'https://cms.example.test' . $path;
        }
    }

    if (!function_exists('get_userdata')) {
        function get_userdata(int $userId)
        {
            return (object) ['ID' => $userId, 'user_login' => 'user' . $userId, 'display_name' => 'User ' . $userId];
        }
    }

    if (!function_exists('get_users')) {
        function get_users(array $args = []): array
        {
            return [];
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

                return empty($GLOBALS['__bcc_throttle_denied']);
            }
        }
    }
}

namespace BCC\Core\Permissions {
    if (!class_exists(Permissions::class, false)) {
        final class Permissions
        {
            public static function is_not_suspended(int $userId, bool $adminBypass = true): bool
            {
                return !in_array($userId, $GLOBALS['__bcc_suspended'] ?? [], true);
            }
        }
    }
}

namespace BCC\Trust\Onchain\Fetchers {
    if (!class_exists(CosmosFetcher::class, false)) {
        class CosmosFetcher
        {
            public function supports_feature(string $feature): bool
            {
                return $feature === 'delegations';
            }

            /** @return array<int, array{validator_address: string, shares: string|null, amount: float|null}>|null */
            public function fetch_delegations_result(string $delegatorAddress): ?array
            {
                $GLOBALS['__bcc_lcd_calls'][] = $delegatorAddress;

                $hook = $GLOBALS['__bcc_lcd_on_fetch'] ?? null;
                if (is_callable($hook)) {
                    $hook($delegatorAddress);
                }

                $answers = $GLOBALS['__bcc_lcd'] ?? [];

                // array_key_exists: a present null is a transport failure and
                // `??` would collapse it into an empty set (fail-open).
                return array_key_exists($delegatorAddress, $answers) ? $answers[$delegatorAddress] : [];
            }
        }
    }
}

namespace BCC\Trust\Onchain\Factories {
    if (!class_exists(FetcherFactory::class, false)) {
        class FetcherFactory
        {
            public static function has_driver(string $chainType): bool
            {
                return $chainType === 'cosmos';
            }

            public static function make_for_chain(object $chain): \BCC\Trust\Onchain\Fetchers\CosmosFetcher
            {
                return new \BCC\Trust\Onchain\Fetchers\CosmosFetcher();
            }
        }
    }
}

namespace {
    // Last: these files only declare what isn't declared yet, so the doubles
    // above have to be in place first.
    require_once __DIR__ . '/page-claim-endpoint-stubs.php';
}
