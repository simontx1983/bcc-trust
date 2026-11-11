<?php
/**
 * PeepSoLeftoversService — dry-run-first removal of data left behind by
 * add-ons deleted from the site (2026-11-11): PeepSo Friends, PeepSo Email
 * Digest, PeepSo Social Login, PeepSo User Limits, and Nextend Social Login.
 *
 * ⚠⚠ THE INVENTORY IS HARDCODED, FROM A READ-ONLY PRODUCTION CHECK. Nothing
 * here discovers what to delete. Each group lists exact tables, exact
 * `peepso_config` keys, exact wp_options and exact usermeta keys. The one
 * exception is the PeepSo Social Login `social_login_open_*` family (one key
 * per provider), which is LISTED by prefix and then deleted key by key, as
 * shown in the dry run.
 *
 * Deliberately NOT touched (they belong to PeepSo core or other plugins):
 *   - usermeta `peepso_email_digest_receive_enabled` (core rewrites it),
 *     `peepso_chat_friends_only` (BCC's messages prefs own it);
 *   - option `peepso_email_notification_digest` (core notification digest);
 *   - peepso_config `site_walls_*friends*`, `messages_friends_only`,
 *     `limitusers_*_domain`, `notification_digest_limit_per_section`,
 *     `social_sharing*` (core);
 *   - `_fluentmail_*` (FluentSMTP), `wpseo_social` (Yoast),
 *     `__fls_social_auth_settings` (owner not confirmed);
 *   - table `peepso_api_rate_limit` (core).
 *
 * A group refuses to run while its plugin class is loaded — removing live
 * data out from under an installed add-on is never the intent.
 *
 * @package BCC\Trust\Core\Services
 */

declare(strict_types=1);

namespace BCC\Trust\Core\Services;

use BCC\Trust\Core\Repositories\PeepSoLeftoversRepository;

if (!defined('ABSPATH')) {
    exit;
}

final class PeepSoLeftoversService
{
    public const CONFIG_OPTION = 'peepso_config';

    /**
     * @var array<string, array{
     *     label: string,
     *     guard_classes: list<string>,
     *     tables: list<string>,
     *     config_keys: list<string>,
     *     options: list<string>,
     *     usermeta: list<string>,
     *     usermeta_prefixes: list<string>
     * }>
     */
    private const GROUPS = [
        'friends' => [
            'label'         => 'PeepSo Friends',
            'guard_classes' => ['PeepSoFriendsPlugin'],
            'tables'        => ['peepso_friend_requests', 'peepso_friends', 'peepso_friends_cache', 'peepso_user_autofriends'],
            'config_keys'   => [
                'friends_request_expiry', 'friends_can_send_message_to', 'friends_max_amount',
                'separator_friends', 'profile_friends_privacy_default', 'default_separator_Friends',
                'default_separator_friends_requests', 'default_onsite_friends_requests',
                'default_email_friends_requests', 'autofriends_description_detail1', 'autofriends_table',
                'new_friends_queries_meta', 'new_friends_queries_desc', 'dev_mode_new_friends_queries',
                'site_license_friendso',
            ],
            'options'       => [
                'peepso_email_friend_request_accept', 'peepso_email_friend_request_send',
                'widget_peepsowidgetfriends', 'widget_peepsowidgetfriendsbirthday', 'widget_peepsowidgetmutualfriends',
            ],
            'usermeta'          => [],
            'usermeta_prefixes' => [],
        ],
        'email_digest' => [
            'label'         => 'PeepSo Email Digest',
            'guard_classes' => ['PeepSoEmailDigest'],
            'tables'        => ['peepso_email_digest_content', 'peepso_email_digest_log'],
            'config_keys'   => [
                'email_digest_enable', 'email_digest_use_images', 'email_digest_schedule_weekly_day',
                'email_digest_schedule_type', 'email_digest_hour_weekly', 'email_digest_minute_weekly',
                'email_digest_am_pm_weekly', 'email_digest_hour_daily', 'email_digest_minute_daily',
                'email_digest_am_pm_daily', 'email_digest_most_liked', 'email_digest_most_commented',
                'email_digest_limit_post_enable', 'email_digest_limit_post_length', 'email_digest_send_inactive',
                'email_digest_role_admin', 'email_digest_role_member', 'email_digest_per_batch', 'email_digest_',
                'email_digest_schedule_monthly_date', 'email_digest_hour_monthly', 'email_digest_minute_monthly',
                'email_digest_am_pm_monthly', 'email_digest_external_cron', 'email_digest_title',
                'email_digest_activity_count', 'email_digest_send_less_activities', 'email_digest_allow_duplicate',
                'email_digest_default', 'email_digest_log', 'email_digest_schedule_timestamp', 'email_digest_batch',
                'site_license_peepso-email-digest',
            ],
            'options'           => ['peepso_email_digest'],
            'usermeta'          => ['peepso_email_digest_sent'],
            'usermeta_prefixes' => [],
        ],
        'peepso_social_login' => [
            'label'             => 'PeepSo Social Login',
            'guard_classes'     => ['PeepSoSocialLoginPlugin', 'PeepSo_Social_Login', 'TwistPress_Social_Login'],
            'tables'            => ['peepso_social_login_user_profile'],
            'config_keys'       => ['site_license_social-login'],
            'options'           => [],
            'usermeta'          => ['social_login_profile_id'],
            'usermeta_prefixes' => ['social_login_open_'],
        ],
        'user_limits' => [
            'label'             => 'PeepSo User Limits',
            'guard_classes'     => ['peepsolimitusers'],
            'tables'            => [],
            'config_keys'       => ['site_license_limitusers'],
            'options'           => [],
            'usermeta'          => [],
            'usermeta_prefixes' => [],
        ],
        'nextend' => [
            'label'         => 'Nextend Social Login',
            'guard_classes' => ['NextendSocialLogin'],
            'tables'        => ['social_users'],
            'config_keys'   => [],
            'options'       => [
                'nextend_social_login', 'widget_nextend_social_login', 'social_login_option',
                'social_login_has_provider', 'widget_sociallogin', 'widget_sociallogininvitation',
            ],
            'usermeta'          => [],
            'usermeta_prefixes' => [],
        ],
    ];

    public function __construct(private readonly PeepSoLeftoversRepository $repo = new PeepSoLeftoversRepository())
    {
    }

    /** @return array<string, string> group id => label */
    public static function groups(): array
    {
        return array_map(static fn (array $g): string => $g['label'], self::GROUPS);
    }

    public static function exists(string $group): bool
    {
        return isset(self::GROUPS[$group]);
    }

    /** The add-on's code is loaded, so its data is live. */
    public static function isInstalled(string $group): bool
    {
        foreach (self::GROUPS[$group]['guard_classes'] ?? [] as $class) {
            if (class_exists($class, false)) {
                return true;
            }
        }
        return false;
    }

    /**
     * What a run of $group would remove. Reads only.
     *
     * @return array{
     *     label: string, installed: bool,
     *     tables: array<string, int|null>,
     *     config_keys: list<string>,
     *     options: list<string>,
     *     usermeta: array<string, int>,
     *     nothing_left: bool
     * } tables: full name => rows (null = absent); only PRESENT items are
     *   listed for config keys, options and usermeta.
     */
    public function plan(string $group): array
    {
        $g = self::GROUPS[$group];
        global $wpdb;

        $tables = [];
        foreach ($g['tables'] as $t) {
            $tables[$wpdb->prefix . $t] = $this->repo->countRows($wpdb->prefix . $t);
        }

        $config  = get_option(self::CONFIG_OPTION, []);
        $config  = is_array($config) ? $config : [];
        $keys    = array_values(array_filter($g['config_keys'], static fn (string $k): bool => array_key_exists($k, $config)));
        $options = array_values(array_filter($g['options'], static fn (string $o): bool => get_option($o, null) !== null));

        $meta = [];
        foreach ($g['usermeta'] as $key) {
            $n = $this->repo->countUsermeta($key);
            if ($n > 0) {
                $meta[$key] = $n;
            }
        }
        foreach ($g['usermeta_prefixes'] as $prefix) {
            $meta += $this->repo->usermetaKeysWithPrefix($prefix);
        }

        $nothing = array_filter($tables, static fn ($n): bool => $n !== null) === []
            && $keys === [] && $options === [] && $meta === [];

        return [
            'label'        => $g['label'],
            'installed'    => self::isInstalled($group),
            'tables'       => $tables,
            'config_keys'  => $keys,
            'options'      => $options,
            'usermeta'     => $meta,
            'nothing_left' => $nothing,
        ];
    }

    /**
     * Remove exactly what plan($group) lists. Refuses while the add-on is
     * installed. Each removed item is passed to $audit($kind, $name, $detail).
     *
     * @param callable(string, string, array<string, int|string|null>): void $audit
     * @return array{ok: bool, refused?: string, removed: list<string>, failed: list<string>}
     */
    public function run(string $group, callable $audit): array
    {
        if (!self::exists($group)) {
            return ['ok' => false, 'refused' => 'unknown group', 'removed' => [], 'failed' => []];
        }
        if (self::isInstalled($group)) {
            return ['ok' => false, 'refused' => 'the add-on is still installed', 'removed' => [], 'failed' => []];
        }

        $plan    = $this->plan($group);
        $removed = [];
        $failed  = [];

        foreach ($plan['tables'] as $table => $rows) {
            if ($rows === null) {
                continue;
            }
            if ($this->repo->dropTable($table)) {
                $removed[] = 'table ' . $table;
                $audit('table', $table, ['rows' => $rows]);
            } else {
                $failed[] = 'table ' . $table;
            }
        }

        if ($plan['config_keys'] !== []) {
            $config = get_option(self::CONFIG_OPTION, []);
            if (is_array($config)) {
                foreach ($plan['config_keys'] as $key) {
                    unset($config[$key]);
                }
                update_option(self::CONFIG_OPTION, $config);
                $after = get_option(self::CONFIG_OPTION, []);
                foreach ($plan['config_keys'] as $key) {
                    if (is_array($after) && !array_key_exists($key, $after)) {
                        $removed[] = 'peepso_config.' . $key;
                        $audit('config_key', $key, []);
                    } else {
                        $failed[] = 'peepso_config.' . $key;
                    }
                }
            }
        }

        foreach ($plan['options'] as $option) {
            if (delete_option($option)) {
                $removed[] = 'option ' . $option;
                $audit('option', $option, []);
            } else {
                $failed[] = 'option ' . $option;
            }
        }

        foreach ($plan['usermeta'] as $key => $rows) {
            delete_metadata('user', 0, $key, '', true);
            if ($this->repo->countUsermeta($key) === 0) {
                $removed[] = 'usermeta ' . $key;
                $audit('usermeta', $key, ['rows' => $rows]);
            } else {
                $failed[] = 'usermeta ' . $key;
            }
        }

        return ['ok' => $failed === [], 'removed' => $removed, 'failed' => $failed];
    }
}
