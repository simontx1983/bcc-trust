<?php
/**
 * Stubs for ProfileGroupVisibilityTest (F-270 / F-323).
 *
 * ⚠⚠ SUBPROCESS-ONLY. This declares `\BCC\Core\Permissions\Permissions`
 * and `\BCC\Core\Repositories\PeepSoGroupRepository`, both of which exist
 * for real. A class declaration cannot be undone and the first one wins
 * for the rest of the process, so the consuming test is
 * `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]`.
 *
 * ⛔ Never load this alongside `profile-access-stubs.php` or
 * `feed-f02-wiring-stubs.php` — all three declare `Permissions`.
 *
 * ⭐ WHY DECLARING REAL CLASSES WORKS: Composer's autoloader only runs on
 * first reference, so declaring these before anything touches them means
 * `class_exists(..., false)` is already true and the real file is never
 * loaded.
 *
 * ⚠ WHAT IS DELIBERATELY NOT STUBBED, because it is what the test is
 * about: `ProfileGroupVisibility`, `ProfileAccess`, `GroupContext`,
 * `GroupType` and `PeepSoPrivacy` are all the REAL classes. The policy
 * decision has to be the production one, or the suite would only prove
 * that a stub returns what it was told.
 *
 * `findUserMemberships` is a SPY as well as a stub. It records the viewer
 * id and the group ids it was asked about, which is how the test proves
 * the cheap in-memory drops happen BEFORE the query (so the query sees
 * only the secret groups that survived) and that the viewer asked about
 * is the VIEWER and never the profile owner. A return-value assertion
 * alone would not catch either.
 *
 * `get_user_meta` is shadowed inside `BCC\Trust\Core\Support` rather than
 * globally: PHP resolves an unqualified call to the current namespace
 * first, and a global declaration would be the shared-WordPress-function
 * trap this suite warns about elsewhere.
 *
 * Fixture ($GLOBALS['__bcc_pgv']):
 *   is_admin            => bool                 Permissions::is_admin()
 *   show_groups[<uid>]  => string               raw bcc_show_groups_on_profile
 *                                               meta; KEY ABSENT = absent
 *                                               meta = ON (the default)
 *   memberships[<gid>]  => true                 secret groups the viewer is in
 *   membership_calls    => list<array{viewer: int, ids: list<int>}>
 */

declare(strict_types=1);

namespace {
    /**
     * @return array{is_admin: bool, show_groups: array<int, string>, memberships: array<int, true>, membership_calls: list<array<string, mixed>>}
     */
    function bcc_pgv_fixture(): array
    {
        $f = $GLOBALS['__bcc_pgv'] ?? [];

        return [
            'is_admin'         => (bool) ($f['is_admin'] ?? false),
            'show_groups'      => is_array($f['show_groups'] ?? null) ? $f['show_groups'] : [],
            'memberships'      => is_array($f['memberships'] ?? null) ? $f['memberships'] : [],
            'membership_calls' => is_array($f['membership_calls'] ?? null) ? $f['membership_calls'] : [],
        ];
    }
}

namespace BCC\Core\Permissions {
    final class Permissions
    {
        public static function is_admin(): bool
        {
            return \bcc_pgv_fixture()['is_admin'];
        }
    }
}

namespace BCC\Core\Repositories {
    final class PeepSoGroupRepository
    {
        /**
         * Presence-keyed membership map, the shape the real method
         * returns (`array<int, object{group_id, ...}>`) and the only
         * part of it ProfileGroupVisibility reads.
         *
         * @param int[] $groupIds
         * @return array<int, object>
         */
        public static function findUserMemberships(int $userId, array $groupIds): array
        {
            $GLOBALS['__bcc_pgv']['membership_calls'][] = [
                'viewer' => $userId,
                'ids'    => array_values(array_map('intval', $groupIds)),
            ];

            if ($userId <= 0 || $groupIds === []) {
                return [];
            }

            $out = [];
            foreach (\bcc_pgv_fixture()['memberships'] as $gid => $_) {
                $g = (int) $gid;
                if (in_array($g, array_map('intval', $groupIds), true)) {
                    $out[$g] = (object) [
                        'group_id'      => (string) $g,
                        'joined_at'     => '2026-01-01 00:00:00',
                        'gm_joined_utc' => '1767225600',
                        'user_status'   => 'member',
                    ];
                }
            }

            return $out;
        }
    }
}

namespace BCC\Trust\Core\Support {
    // Shadows the WordPress function for the one namespace whose code
    // calls it — see this file's header for why it is not global.
    if (!\function_exists('BCC\Trust\Core\Support\get_user_meta')) {
        /** @return mixed */
        function get_user_meta(int $userId, string $key = '', bool $single = false)
        {
            unset($single);
            if ($key !== ProfileAccess::META_SHOW_GROUPS) {
                return '';
            }

            // '' for an untouched account — exactly what WordPress
            // returns for absent meta, which is the ON default.
            return \bcc_pgv_fixture()['show_groups'][$userId] ?? '';
        }
    }
}
