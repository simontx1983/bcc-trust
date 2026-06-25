<?php

declare(strict_types=1);

// ── Stubs (defined FIRST so the production class never autoloads the real ones) ──
//
// UserViewService::resolveFlags depends on two collaborators:
//   - BCC\Core\Permissions\Permissions::is_not_suspended() (imported via `use`)
//   - get_user_meta() — called unqualified, so PHP resolves it against the
//     service's own namespace (BCC\Trust\Core\Services) before the global.
// Declaring both here (no WordPress, no DB) keeps the characterization pure.
// Tests drive each scenario by mutating the two static stores below.

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
        }
    }
}

namespace BCC\Trust\Core\Services {
    if (!function_exists(__NAMESPACE__ . '\\get_user_meta')) {
        /**
         * @param int|string $userId
         * @return mixed
         */
        function get_user_meta($userId, string $key = '', bool $single = false)
        {
            unset($single); // matches the WP signature; not consulted here
            return \BCC\Trust\Core\Tests\Unit\FlagMetaStore::$meta[(int) $userId . ':' . $key] ?? '';
        }
    }
}

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Core\Permissions\Permissions;
    use BCC\Trust\Core\Services\UserViewService;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /** Test-controlled backing store for the stubbed get_user_meta. */
    final class FlagMetaStore
    {
        /** @var array<string, mixed> keyed "<userId>:<metaKey>" */
        public static array $meta = [];
    }

    /**
     * Characterizes the V1-contract `flags: string[]` composer on
     * UserViewService — the pure truth table plus the WP-meta truthiness
     * and per-request memo semantics. Ported from the pre-PHPUnit CLI harness
     * (tests/UserViewServiceFlagsTest.php) into the real CI suite (Phase 10).
     *
     *   - composeFlagSlugs(bool×4)  — pure decision function, full 16-case table
     *   - resolveFlags(int)         — Permissions + get_user_meta integration,
     *                                 filter_var truthiness, userId guard, memo
     *
     * Each resolveFlags scenario uses a UNIQUE userId because resolveFlags
     * memoises per userId in a process-lifetime static — ids must not collide
     * across methods or a later assertion reads an earlier method's cache.
     */
    final class UserViewFlagsTest extends TestCase
    {
        protected function setUp(): void
        {
            Permissions::$suspended = [];
            FlagMetaStore::$meta = [];
        }

        // ── composeFlagSlugs (pure) — 16-case truth table ────────────────

        /**
         * @param list<string> $expected
         */
        #[DataProvider('flagTruthTable')]
        public function testComposeFlagSlugsTruthTable(
            bool $suspended,
            bool $shadowLimited,
            bool $hidden,
            bool $underReview,
            array $expected
        ): void {
            self::assertSame(
                $expected,
                UserViewService::composeFlagSlugs($suspended, $shadowLimited, $hidden, $underReview)
            );
        }

        /** @return iterable<string, array{bool,bool,bool,bool,list<string>}> */
        public static function flagTruthTable(): iterable
        {
            yield 'no flags'              => [false, false, false, false, []];
            yield 'suspended only'       => [true,  false, false, false, ['suspended']];
            yield 'shadow_limited only'  => [false, true,  false, false, ['shadow_limited']];
            yield 'hidden only'          => [false, false, true,  false, ['hidden']];
            yield 'under_review only'    => [false, false, false, true,  ['under_review']];
            yield 'suspended+shadow'     => [true,  true,  false, false, ['suspended', 'shadow_limited']];
            yield 'suspended+hidden'     => [true,  false, true,  false, ['suspended', 'hidden']];
            yield 'suspended+review'     => [true,  false, false, true,  ['suspended', 'under_review']];
            yield 'shadow+hidden'        => [false, true,  true,  false, ['shadow_limited', 'hidden']];
            yield 'shadow+review'        => [false, true,  false, true,  ['shadow_limited', 'under_review']];
            yield 'hidden+review'        => [false, false, true,  true,  ['hidden', 'under_review']];
            yield 'three (not review)'   => [true,  true,  true,  false, ['suspended', 'shadow_limited', 'hidden']];
            yield 'three (not hidden)'   => [true,  true,  false, true,  ['suspended', 'shadow_limited', 'under_review']];
            yield 'three (not shadow)'   => [true,  false, true,  true,  ['suspended', 'hidden', 'under_review']];
            yield 'three (not suspended)'=> [false, true,  true,  true,  ['shadow_limited', 'hidden', 'under_review']];
            yield 'all four'             => [true,  true,  true,  true,  ['suspended', 'shadow_limited', 'hidden', 'under_review']];
        }

        // ── resolveFlags (integration via stubs) ─────────────────────────

        public function testResolveFlagsGuardsInvalidUserIds(): void
        {
            self::assertSame([], UserViewService::resolveFlags(0));
            self::assertSame([], UserViewService::resolveFlags(-1));
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

        public function testResolveFlagsShadowLimitedViaMetaStringOne(): void
        {
            FlagMetaStore::$meta['1044:bcc_shadow_limited'] = '1';
            self::assertSame(['shadow_limited'], UserViewService::resolveFlags(1044));
        }

        public function testResolveFlagsAllFourSignals(): void
        {
            Permissions::$suspended = [1046];
            FlagMetaStore::$meta['1046:bcc_shadow_limited'] = '1';
            FlagMetaStore::$meta['1046:bcc_hidden']         = 1;
            FlagMetaStore::$meta['1046:bcc_under_review']   = 'yes';
            self::assertSame(
                ['suspended', 'shadow_limited', 'hidden', 'under_review'],
                UserViewService::resolveFlags(1046)
            );
        }

        public function testResolveFlagsFalsyMetaAddsNoFlag(): void
        {
            FlagMetaStore::$meta = [
                '1099:bcc_shadow_limited' => '',
                '1099:bcc_hidden'         => '0',
                '1099:bcc_under_review'   => 0,
            ];
            self::assertSame([], UserViewService::resolveFlags(1099));
        }

        /**
         * The reason metaFlag uses filter_var(FILTER_VALIDATE_BOOLEAN) rather
         * than a naive (bool) cast: the literal strings "false"/"no"/"off" all
         * cast to true under (bool) and would surface phantom moderation flags.
         */
        public function testResolveFlagsFilterVarFalseStringsMapToFalse(): void
        {
            FlagMetaStore::$meta = [
                '1100:bcc_shadow_limited' => 'false',
                '1100:bcc_hidden'         => 'no',
                '1100:bcc_under_review'   => 'off',
            ];
            self::assertSame([], UserViewService::resolveFlags(1100));
        }

        public function testResolveFlagsFilterVarTrueStringsMapToTrue(): void
        {
            FlagMetaStore::$meta = [
                '1101:bcc_shadow_limited' => 'true',
                '1101:bcc_hidden'         => 'yes',
                '1101:bcc_under_review'   => 'on',
            ];
            self::assertSame(
                ['shadow_limited', 'hidden', 'under_review'],
                UserViewService::resolveFlags(1101)
            );
        }

        /**
         * Per-request memo: a mid-request state mutation must NOT invalidate
         * the cached result (moderation actions complete before the next
         * request), and the memo must be keyed by userId, not a global toggle.
         */
        public function testResolveFlagsMemoIsStableAndUserKeyed(): void
        {
            FlagMetaStore::$meta = ['1200:bcc_hidden' => '1'];
            self::assertSame(['hidden'], UserViewService::resolveFlags(1200), 'first call resolves from state');

            // Mutate underlying state — the memo must return the cached result.
            Permissions::$suspended = [1200];
            FlagMetaStore::$meta['1200:bcc_under_review'] = '1';
            self::assertSame(
                ['hidden'],
                UserViewService::resolveFlags(1200),
                'state mutation does not invalidate the in-request cache'
            );

            // A distinct userId resolves independently (no memo collision).
            Permissions::$suspended[] = 1201;
            self::assertSame(['suspended'], UserViewService::resolveFlags(1201), 'distinct userId resolves independently');
        }

        public function testResolveFlagsInvalidIdDoesNotPolluteMemo(): void
        {
            FlagMetaStore::$meta = ['1202:bcc_under_review' => '1'];
            self::assertSame([], UserViewService::resolveFlags(0), 'invalid userId guard short-circuits');
            self::assertSame(['under_review'], UserViewService::resolveFlags(1202), 'invalid-id call did not pollute the cache');
        }
    }
}
