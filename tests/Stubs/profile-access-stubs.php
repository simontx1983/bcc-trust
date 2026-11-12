<?php
/**
 * Stubs for ProfileAccessTest — the §F-02 profile-visibility gate.
 *
 * ⚠⚠ SUBPROCESS-ONLY, AND THAT IS LOAD-BEARING HERE, NOT A FORMALITY.
 * This file declares `\PeepSoUser`, a class NO other test in this suite
 * stubs. A class declaration cannot be undone, and whoever declares it
 * first wins for the rest of the process — the exact failure mode that
 * once hid 93 broken sibling tests in this repo. The consuming test is
 * therefore `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`,
 * so this declaration lives and dies inside one child process.
 *
 * ⛔ Do NOT require this file from a test that runs in the parent
 * process, and do NOT move `PeepSoUser` into tests/bootstrap.php to
 * "share" it. If another test needs a PeepSoUser, give it its own
 * subprocess stub rather than widening this one.
 *
 * `PeepSoUser::is_accessible_static` is a SPY as well as a stub: it
 * records every call, which is what lets the test prove ProfileAccess'
 * own guards run BEFORE PeepSo is consulted instead of inferring it from
 * a return value that would be false either way.
 *
 * ── §F-182 additions ────────────────────────────────────────────────
 *
 * The gate now also asks bcc-core whether the TARGET is suspended, so
 * this file stubs `\BCC\Core\Permissions\Permissions` too — again a
 * class whose real version must never be shadowed outside a subprocess.
 * `suspensionState` is a SPY as well: the test pins which user id is
 * asked about (the target, never the viewer) and that admin bypass is
 * left at its default, both of which are deliberate choices that a
 * return-value assertion alone would not catch. ⚠ It is the spy because
 * it is what the gate CALLS; `is_not_suspended` is defined here as
 * `suspensionState(...) === false`, mirroring bcc-core #84 so the
 * harness reproduces its deny-on-unknown behaviour instead of a
 * friendlier fiction, and it records nothing of its own.
 *
 * `get_current_user_id()` is shadowed INSIDE `BCC\Trust\Core\Support`
 * rather than declared globally — PHP resolves an unqualified call to
 * the current namespace first, and a global declaration here would be
 * the shared-WordPress-function trap this suite already warns about.
 *
 * Fixture ($GLOBALS['__bcc_profile_access']):
 *   levels[<uid>]    => int|string|null  the stored usr_profile_acc
 *   verdicts[<acc>]  => bool             what PeepSo's ladder answers
 *   calls            => list<array{acc: mixed, user_id: int}>  spy log
 *   viewer_id        => int              get_current_user_id()
 *   is_admin         => bool             Permissions::is_admin()
 *   suspended[<uid>] => bool|'unknown'   suspension state; 'unknown' = null
 *   susp_calls       => list<array{user_id: int|null, bypass: bool}>
 *   hooks            => list<array{hook: string, priority: int, accepted: int, callback: string}>
 */

declare(strict_types=1);

namespace {
    /**
     * @return array{levels: array<int, mixed>, verdicts: array<int, bool>, calls: list<array{acc: mixed, user_id: int}>, viewer_id: int, is_admin: bool, suspended: array<int, bool>, susp_calls: list<array{user_id: int|null, bypass: bool}>}
     */
    function bcc_profile_access_fixture(): array
    {
        $f = $GLOBALS['__bcc_profile_access'] ?? [];
        return [
            'levels'   => is_array($f['levels'] ?? null) ? $f['levels'] : [],
            'verdicts' => is_array($f['verdicts'] ?? null) ? $f['verdicts'] : [],
            'calls'    => is_array($f['calls'] ?? null) ? $f['calls'] : [],
            // ⚠ Defaults matter: every pre-F-182 test in the suite relies
            // on them to mean "anonymous viewer, not an admin, nobody
            // suspended", so the suspension branch is inert unless a test
            // opts in. A default of `true` anywhere here would make the
            // whole suite measure the suspension path by accident.
            'viewer_id'  => (int) ($f['viewer_id'] ?? 0),
            'is_admin'   => (bool) ($f['is_admin'] ?? false),
            'suspended'  => is_array($f['suspended'] ?? null) ? $f['suspended'] : [],
            'susp_calls' => is_array($f['susp_calls'] ?? null) ? $f['susp_calls'] : [],
        ];
    }

    // ApiResponse::error() constructs one of these, so the refusal-shape
    // test needs it. Same minimal shape as the sibling stub files use.
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

    if (!class_exists('PeepSoUser', false)) {
        final class PeepSoUser
        {
            private int $userId;

            private function __construct(int $userId)
            {
                $this->userId = $userId;
            }

            public static function get_instance(int $userId): self
            {
                return new self($userId);
            }

            /**
             * Mirrors the real signature's looseness: PeepSo returns
             * whatever is stored, which is why ProfileAccess re-checks
             * `is_numeric` rather than trusting an int.
             *
             * @return mixed
             */
            public function get_profile_accessibility()
            {
                $levels = bcc_profile_access_fixture()['levels'];

                // ⚠ array_key_exists, NOT `?? 10`. The `??` form collapses
                // "no fixture set for this user" together with "the
                // fixture deliberately stores NULL", so a test for the
                // null-level branch silently received 10 instead and
                // measured nothing. The real column can be null; the stub
                // has to be able to say so.
                return array_key_exists($this->userId, $levels)
                    ? $levels[$this->userId]
                    : 10;
            }

            /**
             * Stub + spy. The real one lives in peepso/classes/user.php
             * and applies owner-exempt, admin-exempt, the 10/20/40 switch
             * and then the `peepso_user_is_accessible` filter (where
             * peepso-friends grants 30). None of that is reimplemented
             * here — this suite tests ProfileAccess' own guards and its
             * delegation, NOT PeepSo's ladder, which belongs to PeepSo.
             *
             * @param mixed $acc
             */
            public static function is_accessible_static($acc, int $userId): bool
            {
                $GLOBALS['__bcc_profile_access']['calls'][] = [
                    'acc'     => $acc,
                    'user_id' => $userId,
                ];

                return bcc_profile_access_fixture()['verdicts'][(int) $acc] ?? false;
            }
        }
    }
}

namespace BCC\Core\Permissions {
    if (!class_exists(Permissions::class, false)) {
        /**
         * Stub + spy for bcc-core's canonical suspension answer.
         *
         * ⚠ The real signature's `$user_id = $user_id ?: get_current_user_id()`
         * fallback is reproduced deliberately: it is the reason
         * ProfileAccess must reject a non-positive target BEFORE calling
         * here, and the spy below is what proves it never does.
         */
        class Permissions
        {
            public static function is_admin(): bool
            {
                return \bcc_profile_access_fixture()['is_admin'];
            }

            public static function is_not_suspended(?int $user_id = null, bool $allowAdminBypass = true): bool
            {
                // bcc-core #84 defines it exactly this way, so an unknown
                // state answers FALSE here — a deny. Reproduced rather
                // than simplified: that is the behaviour ProfileAccess
                // must NOT inherit on a read path.
                return self::suspensionState($user_id, $allowAdminBypass) === false;
            }

            /**
             * Tri-state per bcc-core #84: true suspended, false not
             * suspended, null UNREADABLE.
             *
             * The fixture's `suspended[<uid>]` may hold the string
             * `'unknown'` to mean null. A separate fixture key would read
             * more neatly but would let a test set a suspension state and
             * an unknown state for the same user at once, which the real
             * API cannot do.
             */
            public static function suspensionState(?int $user_id = null, bool $allowAdminBypass = true): ?bool
            {
                $GLOBALS['__bcc_profile_access']['susp_calls'][] = [
                    'user_id' => $user_id,
                    'bypass'  => $allowAdminBypass,
                ];

                $fixture = \bcc_profile_access_fixture();
                $id      = $user_id ?: $fixture['viewer_id'];
                $raw     = $fixture['suspended'][$id] ?? false;

                if ($raw === 'unknown') {
                    return null;
                }

                return (bool) $raw;
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    // Shadows the WordPress function for the one namespace whose code
    // calls it — see this file's header for why it is not global.
    if (!\function_exists('BCC\Trust\Core\Support\get_current_user_id')) {
        function get_current_user_id(): int
        {
            return \bcc_profile_access_fixture()['viewer_id'];
        }
    }

    // Same shadowing rule, so registerListingFilter() can be observed
    // instead of silently registering against nothing.
    if (!\function_exists('BCC\Trust\Core\Support\add_action')) {
        /** @param mixed $callback */
        function add_action(string $hook, $callback, int $priority = 10, int $accepted = 1): bool
        {
            $GLOBALS['__bcc_profile_access']['hooks'][] = [
                'hook'     => $hook,
                'priority' => $priority,
                'accepted' => $accepted,
                'callback' => \is_array($callback) ? \implode('::', $callback) : $callback,
            ];

            return true;
        }
    }
}

namespace {
    /**
     * The two objects the listing filter touches.
     *
     * `wpdb::prepare` SUBSTITUTES here rather than returning a
     * `sql|json` sentinel like some sibling stubs: the assertions are
     * about the rendered clause — that the viewer's id lands in it, and
     * that the join is LEFT — so a sentinel would hide exactly what is
     * being measured.
     */
    if (!class_exists('BCC_F182_Wpdb', false)) {
        class BCC_F182_Wpdb
        {
            public string $prefix = 'wp_';
            public string $users  = 'wp_users';

            /** @param mixed ...$args */
            public function prepare(string $sql, ...$args): string
            {
                foreach ($args as $arg) {
                    $sql = \preg_replace('/%d/', (string) (int) $arg, $sql, 1) ?? $sql;
                }

                return $sql;
            }
        }
    }

    if (!class_exists('WP_User_Query', false)) {
        class WP_User_Query
        {
            /** @var array<string, mixed> */
            public array $query_vars  = [];
            public string $query_from  = 'FROM wp_users';
            public string $query_where = 'WHERE 1=1';

            /** @param array<string, mixed> $vars */
            public function __construct(array $vars = [])
            {
                $this->query_vars = $vars;
            }
        }
    }
}
