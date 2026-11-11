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
 * Fixture ($GLOBALS['__bcc_profile_access']):
 *   levels[<uid>]   => int|string|null   the stored usr_profile_acc
 *   verdicts[<acc>] => bool              what PeepSo's ladder answers
 *   calls           => list<array{acc: mixed, user_id: int}>  spy log
 */

declare(strict_types=1);

namespace {
    /**
     * @return array{levels: array<int, mixed>, verdicts: array<int, bool>, calls: list<array{acc: mixed, user_id: int}>}
     */
    function bcc_profile_access_fixture(): array
    {
        $f = $GLOBALS['__bcc_profile_access'] ?? [];
        return [
            'levels'   => is_array($f['levels'] ?? null) ? $f['levels'] : [],
            'verdicts' => is_array($f['verdicts'] ?? null) ? $f['verdicts'] : [],
            'calls'    => is_array($f['calls'] ?? null) ? $f['calls'] : [],
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
