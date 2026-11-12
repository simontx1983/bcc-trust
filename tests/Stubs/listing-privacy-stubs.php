<?php
/**
 * Stubs for ListingPrivacyClausesTest.
 *
 * ⚠⚠ THIS FILE DELIBERATELY DOES NOT DECLARE `PeepSoFriendsPlugin`.
 *
 * The behaviour under test is what happens when that OPTIONAL plugin is
 * ABSENT — BCC's level-30 exclusion only applies then, because while the
 * plugin is present its own clause owns level 30 and a blanket exclusion
 * on top would hide level-30 users from their own friends. Declaring the
 * class here, even "for completeness", would silently turn every
 * level-30 assertion into a no-op that still passes.
 *
 * Subprocess-only: a class declaration cannot be undone within a
 * process, and `\PeepSo` is stubbed with different shapes by several
 * other files under tests/Stubs.
 *
 * Fixture ($GLOBALS['__bcc_lp']):
 *   admin => bool   what PeepSo::is_admin() answers
 */

declare(strict_types=1);

namespace {
    if (!class_exists('WP_User_Query', false)) {
        class WP_User_Query
        {
            /** @var array<string, mixed> */
            public array $query_vars = [];

            public string $query_from  = '';
            public string $query_where = '';
        }
    }

    // F-257: the search filter reads the fold migration's done-marker
    // (PrivacySettings::FOLD_DONE_OPTION). $GLOBALS['__bcc_lp']['options'].
    if (!function_exists('get_option')) {
        /**
         * @param mixed $default
         * @return mixed
         */
        function get_option(string $key, $default = false)
        {
            return $GLOBALS['__bcc_lp']['options'][$key] ?? $default;
        }
    }

    if (!class_exists('PeepSo', false)) {
        class PeepSo
        {
            public const ACCESS_PUBLIC  = 10;
            public const ACCESS_MEMBERS = 20;
            public const ACCESS_PRIVATE = 40;

            public static function is_admin(?int $user_id = null): bool
            {
                unset($user_id);

                return (bool) ($GLOBALS['__bcc_lp']['admin'] ?? false);
            }
        }
    }

    // $wpdb double: just enough for the clause builder. `prepare` renders
    // the bound values into the SQL so the test reads what MySQL would
    // receive — the same approach bcc-core's RenderingWpdb takes, and the
    // reason these assertions can check `<> 30` and `= 42` directly
    // rather than trusting a separate argument list.
    if (!isset($GLOBALS['wpdb'])) {
        $GLOBALS['wpdb'] = new class {
            public string $prefix = 'wp_';
            public string $users  = 'wp_users';

            /** @param mixed ...$args */
            public function prepare(string $query, ...$args): string
            {
                $next = 0;

                return (string) preg_replace_callback(
                    '/%[ds]/',
                    static function (array $m) use (&$next, $args): string {
                        $value = $args[$next++] ?? '<<UNBOUND>>';

                        return $m[0] === '%d' ? (string) (int) $value : "'" . (string) $value . "'";
                    },
                    $query
                );
            }
        };
    }
}
