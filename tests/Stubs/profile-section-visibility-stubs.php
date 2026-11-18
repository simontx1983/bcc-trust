<?php
/**
 * Stubs for ProfileSectionVisibilityTest (F-341).
 *
 * ⚠⚠ SUBPROCESS-ONLY. Declares `\BCC\Core\Permissions\Permissions`, which
 * exists for real; a class declaration cannot be undone and the first one
 * wins for the rest of the process. The consuming test is
 * `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`.
 *
 * ⛔ Never load this alongside `profile-access-stubs.php`,
 * `feed-f02-wiring-stubs.php` or `profile-group-visibility-stubs.php` —
 * they all declare `Permissions`.
 *
 * ⚠ WHAT IS DELIBERATELY NOT STUBBED, because it is what the test is
 * about: `ProfileSectionVisibility`, `ProfileAccess` and `PrivacySettings`
 * are the REAL classes. The point of F-341's accessor is that it reconciles
 * THREE storage conventions — new `bcc_show_*` meta, F-270's groups flag,
 * and the §K2 hide flags — so stubbing either collaborator would prove only
 * that a stub returns what it was told.
 *
 * ⭐ ONE `get_user_meta` SHIM SERVES ALL THREE. ProfileSectionVisibility,
 * ProfileAccess and PrivacySettings all live in `BCC\Trust\Core\Support`,
 * and PHP resolves an unqualified call to the current namespace first — so
 * a single shadow in that namespace covers every read, including
 * PrivacySettings' `bcc_privacy_*` keys. That is also why the fixture is
 * keyed by the RAW meta key rather than by a friendly name: it has to be
 * able to express all three conventions.
 *
 * Fixture ($GLOBALS['__bcc_psv']):
 *   is_admin        => bool                  Permissions::is_admin()
 *   meta[<uid>][<meta_key>] => mixed         stored value (absent = unset)
 */

declare(strict_types=1);

namespace {
    /** @return array{is_admin: bool, meta: array<int, array<string, mixed>>} */
    function bcc_psv_fixture(): array
    {
        $f = $GLOBALS['__bcc_psv'] ?? [];

        return [
            'is_admin' => (bool) ($f['is_admin'] ?? false),
            'meta'     => is_array($f['meta'] ?? null) ? $f['meta'] : [],
        ];
    }
}

namespace BCC\Core\Permissions {
    final class Permissions
    {
        public static function is_admin(): bool
        {
            return \bcc_psv_fixture()['is_admin'];
        }
    }
}

namespace BCC\Trust\Core\Support {
    if (!\function_exists('BCC\Trust\Core\Support\get_user_meta')) {
        /** @return mixed */
        function get_user_meta($userId, $key = '', $single = false)
        {
            unset($single);

            // '' for an absent row, exactly as WordPress answers — which is
            // the "member never touched the setting" case and must read as
            // SHOWN for every `bcc_show_*` key.
            return \bcc_psv_fixture()['meta'][(int) $userId][$key] ?? '';
        }
    }
}
