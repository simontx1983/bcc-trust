<?php
/**
 * Stubs for FilterVisibleTest — the batched list-visibility filter.
 *
 * ⚠⚠ SUBPROCESS-ONLY. This declares `\PeepSoUserSearch` and `\PeepSo`,
 * and a class declaration cannot be undone inside a process. The
 * consuming test is `#[RunTestsInSeparateProcesses]` +
 * `#[PreserveGlobalState(false)]` so these live and die in one child.
 *
 * ⛔ Do NOT require this from a parent-process test, and do NOT move
 * either class into tests/bootstrap.php to share it. `\PeepSo` in
 * particular is already stubbed by several other Stubs/ files with
 * DIFFERENT shapes; whoever declares it first wins for the rest of the
 * process, which is how 93 sibling tests were once silently broken.
 *
 * `PeepSoUserSearch` here is a SPY as well as a stub: it records the
 * args it was constructed with, which is what lets the test prove the
 * third argument (the search string) is empty — the detail that keeps
 * PeepSo's own email-capable search clause from firing.
 *
 * Fixture ($GLOBALS['__bcc_fv']):
 *   visible[<uid>] => true     ids PeepSo's privacy SQL would return
 *   admin          => bool     what PeepSo::is_admin() answers
 *   constructed    => list<array{args: array, user_id: ?int, search: string}>
 */

declare(strict_types=1);

namespace {
    /**
     * @return array{visible: array<int, bool>, admin: bool, constructed: list<array<string, mixed>>}
     */
    function bcc_fv_fixture(): array
    {
        $f = $GLOBALS['__bcc_fv'] ?? [];
        return [
            'visible'     => is_array($f['visible'] ?? null) ? $f['visible'] : [],
            'admin'       => (bool) ($f['admin'] ?? false),
            'constructed' => is_array($f['constructed'] ?? null) ? $f['constructed'] : [],
        ];
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
                return bcc_fv_fixture()['admin'];
            }
        }
    }

    if (!class_exists('PeepSoUserSearch', false)) {
        class PeepSoUserSearch
        {
            /** @var list<int> */
            public array $results = [];
            public int $total = 0;

            /**
             * Mirrors the real signature: (args, user_id, search).
             *
             * @param array<string, mixed> $args
             */
            public function __construct(array $args = [], ?int $user_id = null, string $search = '')
            {
                $GLOBALS['__bcc_fv']['constructed'][] = [
                    'args'    => $args,
                    'user_id' => $user_id,
                    'search'  => $search,
                ];

                // Model the real thing: it returns only the ids its
                // privacy SQL admits, constrained to `include`.
                $visible = bcc_fv_fixture()['visible'];
                $include = [];
                foreach ((array) ($args['include'] ?? []) as $id) {
                    $include[] = (int) $id;
                }

                $out = [];
                foreach ($include as $id) {
                    if (!empty($visible[$id])) {
                        $out[] = $id;
                    }
                }
                $this->results = $out;
                $this->total   = count($out);
            }
        }
    }

    if (!function_exists('get_current_user_id')) {
        function get_current_user_id(): int
        {
            return (int) ($GLOBALS['__bcc_fv_viewer'] ?? 0);
        }
    }
}
