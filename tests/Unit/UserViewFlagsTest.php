<?php

declare(strict_types=1);

// ── Stubs (defined FIRST so the production class never autoloads the real one) ──
//
// ⚠ F-582 shrank this harness. `resolveFlags()` used to depend on TWO
// collaborators — Permissions::is_not_suspended() and an unqualified
// get_user_meta() — because three of the four flags were usermeta reads.
// Those three flags are gone, so the meta stub and its backing store went
// with them: suspension is the only remaining signal, and it comes from
// Permissions. If a future flag reads meta again, the stub comes back with
// it rather than sitting here unused.

namespace BCC\Core\Permissions {
    if (!class_exists(Permissions::class, false)) {
        final class Permissions
        {
            /** @var array<int, int> userIds currently suspended */
            public static array $suspended = [];

            public static function is_not_suspended(?int $userId = null, bool $allowAdminBypass = true): bool
            {
                unset($allowAdminBypass); // matches the WP-side signature; not consulted here
                if ($userId === null) {
                    return true;
                }
                return !in_array($userId, self::$suspended, true);
            }

            /**
             * Tri-state mirror of bcc-core Permissions::suspensionState() (#84):
             * TRUE suspended, FALSE not, NULL unknown. Ids listed in
             * $GLOBALS['__bcc_suspension_unknown'] read as UNKNOWN.
             */
            public static function suspensionState(?int $userId = null, bool $allowAdminBypass = true): ?bool
            {
                if (in_array($userId, $GLOBALS['__bcc_suspension_unknown'] ?? [], true)) {
                    return null;
                }
                return !self::is_not_suspended((int) $userId, $allowAdminBypass);
            }
        }
    }
}

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Core\Permissions\Permissions;
    use BCC\Trust\Core\Services\UserViewService;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /**
     * The V1-contract `flags: string[]` composer on UserViewService.
     *
     * ⚠⚠ THIS FILE TESTS THE UNGATED LIST. `resolveFlags()` answers "what is
     * true of this member" with no view of who is asking, and is NOT what a
     * payload carries. Who may be TOLD is
     * {@see UserViewService::viewerMaySeeFlags()} / `flagsForViewer()`, and
     * that is covered by UserViewFlagsViewerScopeTest.
     *
     * ⚠ F-582 reduced this to ONE slug. `shadow_limited`, `hidden` and
     * `under_review` were removed: nothing ever set them, and a read-only
     * count found zero rows for all three usermeta keys on production and
     * staging. The 16-case truth table and the filter_var truthiness cases
     * went with them — the boolean-truthy vocabulary they happened to
     * exercise belongs to `PrivacySettings::strictest()`, which survives, and
     * is now tested directly in PrivacyFlagsFailClosedTest rather than
     * through a method that no longer exists.
     *
     * Each resolveFlags scenario uses a UNIQUE userId, because resolveFlags
     * memoises per userId in a process-lifetime static — ids must not collide
     * across methods or a later assertion reads an earlier method's cache.
     */
    final class UserViewFlagsTest extends TestCase
    {
        protected function setUp(): void
        {
            Permissions::$suspended = [];
        }

        // ── composeFlagSlugs (pure) ──────────────────────────────────────

        /** @param list<string> $expected */
        #[DataProvider('flagTruthTable')]
        public function testComposeFlagSlugsTruthTable(bool $suspended, array $expected): void
        {
            self::assertSame($expected, UserViewService::composeFlagSlugs($suspended));
        }

        /** @return iterable<string, array{bool, list<string>}> */
        public static function flagTruthTable(): iterable
        {
            yield 'not suspended' => [false, []];
            yield 'suspended'     => [true,  ['suspended']];
        }

        /**
         * ⛔ THE REINTRODUCTION GUARD. `suspended` is the ONLY slug the
         * contract allows, so both outputs are asserted EXACTLY — adding a
         * fourth slug back without a contract amendment fails here, which is
         * the point. A retired slug is not a dormant feature.
         */
        public function testSuspendedIsTheOnlySlugTheComposerCanEverEmit(): void
        {
            foreach ([true, false] as $suspended) {
                foreach (UserViewService::composeFlagSlugs($suspended) as $slug) {
                    self::assertSame('suspended', $slug, 'no other slug may be emitted');
                }
            }

            self::assertSame(['suspended'], UserViewService::composeFlagSlugs(true));
            self::assertSame([], UserViewService::composeFlagSlugs(false));
        }

        // ── resolveFlags (Permissions integration, guard, memo) ──────────

        public function testResolveFlagsGuardsInvalidUserIds(): void
        {
            self::assertSame([], UserViewService::resolveFlags(0));
            self::assertSame([], UserViewService::resolveFlags(-7));
        }

        public function testResolveFlagsNoSignals(): void
        {
            self::assertSame([], UserViewService::resolveFlags(1042));
        }

        public function testResolveFlagsSuspended(): void
        {
            Permissions::$suspended = [1043];
            self::assertSame(['suspended'], UserViewService::resolveFlags(1043));
        }

        /**
         * Per-request memo: a mid-request state mutation must NOT invalidate
         * the cached result (moderation actions complete before the next
         * request), and the memo must be keyed by userId, not a global toggle.
         *
         * ⚠ Driven by suspension now rather than meta, since meta flags are
         * gone — the memo semantics under test are unchanged.
         */
        public function testResolveFlagsMemoIsStableAndUserKeyed(): void
        {
            self::assertSame([], UserViewService::resolveFlags(1200), 'first call resolves from state');

            // Mutate underlying state — the memo must return the cached result.
            Permissions::$suspended = [1200];
            self::assertSame(
                [],
                UserViewService::resolveFlags(1200),
                'state mutation does not invalidate the in-request cache'
            );

            // A distinct userId resolves independently (no memo collision).
            Permissions::$suspended[] = 1201;
            self::assertSame(['suspended'], UserViewService::resolveFlags(1201), 'distinct userId resolves independently');
        }

        public function testResolveFlagsInvalidIdDoesNotPolluteMemo(): void
        {
            Permissions::$suspended = [1202];
            self::assertSame([], UserViewService::resolveFlags(0), 'invalid userId guard short-circuits');
            self::assertSame(
                ['suspended'],
                UserViewService::resolveFlags(1202),
                'invalid-id call did not pollute the cache'
            );
        }
    }
}
