<?php
/**
 * The activity read behind `FeedItemReportVisibility::audienceAllows()`.
 *
 * §F-02's per-post audience rule is bcc-core's SQL predicate, reached by
 * re-reading the row with `enforcePostVisibility: true`. A unit process has
 * no database, so this doubles the READ and models its observable contract:
 * with enforcement on, an act whose audience excludes the viewer does not
 * come back.
 *
 * ⚠ MODELLED, NOT REAL. The predicate itself — the STRICTER of the post's
 * `act_access` and its author's `usr_profile_acc`, evaluated over ranges so
 * a stray PeepSo level lands on the nearest stricter rung — lives in
 * bcc-core and is tested there against rendered SQL
 * (`ActivityPerPostVisibilityTest`). Restating it here would be the second
 * ladder §11 forbids, and it would drift.
 *
 * ⚠⚠ SUBPROCESS-ONLY, and declared at the real FQN. A class declaration
 * cannot be undone, and the first one wins for the rest of the process, so
 * every consuming suite must be `#[RunTestsInSeparateProcesses]` +
 * `#[PreserveGlobalState(false)]`. It is a separate file rather than a
 * file-level block inside a test, because a stub declared in a test file is
 * loaded during DISCOVERY and leaks into the whole unit run.
 *
 * ⛔ Never load this alongside another stub file that declares
 * `BCC\Core\Repositories\PeepSoActivityRepository` (e.g.
 * `comment-delete-stubs.php`).
 *
 * Fixture ($GLOBALS['__bcc_aud']):
 *   hidden => list<int>   act ids the predicate refuses when enforcing
 *   calls  => list<array> every read, in order, with its arguments
 *
 * @package BCC\Trust\Tests
 */

declare(strict_types=1);

namespace {
    /** @return array{hidden: list<int>, calls: list<array<string, mixed>>} */
    function bcc_aud_fixture(): array
    {
        $f = $GLOBALS['__bcc_aud'] ?? [];

        return [
            'hidden' => is_array($f['hidden'] ?? null) ? $f['hidden'] : [],
            'calls'  => is_array($f['calls'] ?? null) ? $f['calls'] : [],
        ];
    }
}

namespace BCC\Core\Repositories {
    if (!class_exists(PeepSoActivityRepository::class, false)) {
        final class PeepSoActivityRepository
        {
            /**
             * ⚠ THE FULL SIGNATURE IS LOAD-BEARING. PHP silently discards
             * extra positional arguments to a user-defined method, so a
             * stub declared with fewer parameters would ACCEPT the
             * enforced read and throw the enforcement away — the gate
             * would be inert and every refusal test would pass for the
             * wrong reason.
             *
             * @param list<int>|null $allowedModuleIds
             * @param list<int>|null $viewerFriendIds
             */
            public static function getById(
                int $actId,
                ?array $allowedModuleIds = null,
                ?array $viewerFriendIds = null,
                bool $enforcePostVisibility = false,
                int $viewerId = 0
            ): ?object {
                $GLOBALS['__bcc_aud']['calls'][] = [
                    'act_id'  => $actId,
                    'enforce' => $enforcePostVisibility,
                    'viewer'  => $viewerId,
                    'friends' => $viewerFriendIds,
                    'modules' => $allowedModuleIds,
                ];

                if ($enforcePostVisibility
                    && in_array($actId, \bcc_aud_fixture()['hidden'], true)
                ) {
                    return null;
                }

                return (object) [
                    'act_id'          => $actId,
                    'act_module_id'   => '1',
                    'act_status'      => 'publish',
                    'act_external_id' => $actId,
                ];
            }
        }
    }
}
