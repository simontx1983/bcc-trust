<?php

declare(strict_types=1);

// ── Stubs, defined FIRST so the production classes never autoload the real ones ──
//
// ⚠⚠ THIS FILE SHADOWS `user_can` INSIDE A PRODUCTION NAMESPACE
// (BCC\Trust\Core\Support, where BearerAuth::userIsAdmin calls it
// unqualified). That definition would win for every file running after this
// one in a shared process, which is how a file-level stub quietly makes
// another suite's assertions vacuous — hence
// #[RunTestsInSeparateProcesses] on the class below. The attribute is what
// makes "it cannot leak" a fact rather than an argument.

namespace BCC\Core\Permissions {
    if (!class_exists(Permissions::class, false)) {
        final class Permissions
        {
            /** @var array<int, int> userIds currently suspended */
            public static array $suspended = [];

            public static function is_not_suspended(?int $userId = null, bool $allowAdminBypass = true): bool
            {
                unset($allowAdminBypass);
                if ($userId === null) {
                    return true;
                }
                return !in_array($userId, self::$suspended, true);
            }
        }
    }
}

namespace BCC\Trust\Core\Support {
    if (!function_exists(__NAMESPACE__ . '\\user_can')) {
        /**
         * @param int|object $userId
         * @param string     $capability
         */
        function user_can($userId, string $capability, mixed ...$args): bool
        {
            unset($capability, $args);

            return in_array((int) $userId, $GLOBALS['__bcc_f582_admins'] ?? [], true);
        }
    }
}

namespace BCC\Trust\Core\Tests\Unit {

    use BCC\Core\Permissions\Permissions;
    use BCC\Trust\Core\Services\MemberProfileComposer;
    use BCC\Trust\Core\Services\UserViewService;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use ReflectionMethod;

    /**
     * F-582 — WHO may be told a member's moderation flags.
     *
     * `resolveFlags()` answers "what is true of this member" and knows
     * nothing about the viewer; `viewerMaySeeFlags()` / `flagsForViewer()`
     * are the only things that decide who is told. That split is deliberate:
     * resolveFlags memoises per MEMBER, so a viewer gate placed inside it
     * would cache the first viewer's answer and serve it to the next.
     *
     * ⛔ THE CASE THAT MATTERS MOST is the last one: a stranger viewing a
     * SUSPENDED member must get NEITHER the flags NOR the "No active
     * moderation flags" fact. Emptying the list alone would have produced
     * the second, because `buildStanding()` inferred that fact from an empty
     * list — so the fix would have replaced a disclosure with a FALSE
     * statement in the server's own voice.
     */
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class UserViewFlagsViewerScopeTest extends TestCase
    {
        private const MEMBER   = 4401;
        private const STRANGER = 4402;
        private const ADMIN    = 4403;

        protected function setUp(): void
        {
            Permissions::$suspended      = [self::MEMBER];
            $GLOBALS['__bcc_f582_admins'] = [self::ADMIN];
        }

        // ── the predicate ────────────────────────────────────────────────

        public function testTheMemberThemselvesMaySeeTheirFlags(): void
        {
            self::assertTrue(UserViewService::viewerMaySeeFlags(self::MEMBER, self::MEMBER));
        }

        public function testAnAdministratorMaySeeThem(): void
        {
            self::assertTrue(UserViewService::viewerMaySeeFlags(self::ADMIN, self::MEMBER));
        }

        public function testAnotherMemberMayNot(): void
        {
            self::assertFalse(UserViewService::viewerMaySeeFlags(self::STRANGER, self::MEMBER));
        }

        /** A signed-out visitor has no id at all, so there is nothing to compare. */
        public function testASignedOutVisitorMayNot(): void
        {
            self::assertFalse(UserViewService::viewerMaySeeFlags(0, self::MEMBER));
            self::assertFalse(UserViewService::viewerMaySeeFlags(-1, self::MEMBER));
        }

        /**
         * ⛔ A viewer id of 0 must not match a SUBJECT id of 0. The guard runs
         * before the self-comparison for exactly this reason: `0 === 0` would
         * otherwise make an anonymous request its own subject.
         */
        public function testZeroIsNotItsOwnSubject(): void
        {
            self::assertFalse(UserViewService::viewerMaySeeFlags(0, 0));
        }

        // ── the gated list ───────────────────────────────────────────────

        public function testTheMemberGetsTheirOwnFlags(): void
        {
            self::assertSame(
                ['suspended'],
                UserViewService::flagsForViewer(self::MEMBER, self::MEMBER)
            );
        }

        public function testAnAdministratorGetsThem(): void
        {
            self::assertSame(
                ['suspended'],
                UserViewService::flagsForViewer(self::MEMBER, self::ADMIN)
            );
        }

        public function testAStrangerGetsAnEmptyList(): void
        {
            self::assertSame([], UserViewService::flagsForViewer(self::MEMBER, self::STRANGER));
        }

        public function testASignedOutVisitorGetsAnEmptyList(): void
        {
            self::assertSame([], UserViewService::flagsForViewer(self::MEMBER, 0));
        }

        /**
         * ⚠ The ungated reader still reports the truth. This is what makes the
         * gate a VIEW concern rather than a data change — and it is why no
         * payload may call resolveFlags directly.
         */
        public function testTheUngatedReaderIsUnchanged(): void
        {
            self::assertSame(['suspended'], UserViewService::resolveFlags(self::MEMBER));
        }

        // ── the standing fact ────────────────────────────────────────────

        /**
         * ⛔ THE ONE THAT WOULD HAVE BEEN MISSED. A stranger viewing a
         * suspended member gets NEITHER the flags NOR the "no flags" fact.
         */
        public function testAStrangerGetsNeitherFlagsNorTheNoFlagsFact(): void
        {
            $flags = UserViewService::flagsForViewer(self::MEMBER, self::STRANGER);
            self::assertSame([], $flags, 'the list is withheld');

            $standing = self::buildStanding(['flags' => $flags], false);

            self::assertNotContains(
                'No active moderation flags',
                $standing['facts'] ?? [],
                'an empty list must NOT be reported as "no flags" to someone who may not see them'
            );
        }

        /** And the member themselves, with nothing against them, IS told so. */
        public function testTheMemberWithNoFlagsIsToldThereAreNone(): void
        {
            Permissions::$suspended = [];

            $standing = self::buildStanding(['flags' => []], true);

            self::assertContains(
                'No active moderation flags',
                $standing['facts'] ?? [],
                'the fact is still stated to a viewer who may see the list'
            );
        }

        /**
         * ⚠ ANTI-VACUITY. Without this, a buildStanding() that never emitted
         * the fact at all would satisfy the stranger test above.
         */
        public function testTheFactIsRealAndTurnsOnThePredicateAlone(): void
        {
            $withheld = self::buildStanding(['flags' => []], false);
            $shown    = self::buildStanding(['flags' => []], true);

            self::assertNotContains('No active moderation flags', $withheld['facts'] ?? []);
            self::assertContains('No active moderation flags', $shown['facts'] ?? []);
        }


        // -- M1: the member's own reach note ------------------------------

        /**
         * M1 - a Caution or Risky tier limits reach, and the MEMBER is told.
         */
        public function testTheMemberIsToldWhenTheirReachIsLimited(): void
        {
            foreach (['caution', 'risky'] as $tier) {
                self::assertTrue(
                    self::buildStanding(['is_self' => true, 'reputation_tier' => $tier], true)['reach_limited'],
                    $tier . ' limits reach, so the member is told so'
                );
            }
        }

        /** And is told plainly when it is not. */
        public function testTheMemberIsToldWhenItIsNot(): void
        {
            foreach (['neutral', 'trusted', 'elite'] as $tier) {
                self::assertFalse(
                    self::buildStanding(['is_self' => true, 'reputation_tier' => $tier], true)['reach_limited'],
                    $tier . ' does not limit reach'
                );
            }
        }

        /**
         * THE DISCLOSURE RULE, and the assertion is assertNull rather than
         * assertFalse on purpose. `false` would tell a stranger "this
         * member's reach is not limited" - a statement about someone else's
         * standing that we must not make, and a FALSE one here, since this
         * member IS caution-tiered. null means "no answer".
         */
        public function testAnotherViewerIsToldNothingEitherWay(): void
        {
            $standing = self::buildStanding(
                ['is_self' => false, 'reputation_tier' => 'caution'],
                false
            );

            self::assertNull(
                $standing['reach_limited'],
                'not disclosed to anyone but the member - and null is NOT false'
            );
            self::assertNotFalse($standing['reach_limited'], 'false would be a claim; null is not');
        }

        /** Absent is_self is not self: the default must be "not disclosed". */
        public function testAMissingSelfMarkerDisclosesNothing(): void
        {
            self::assertNull(self::buildStanding(['reputation_tier' => 'risky'], false)['reach_limited']);
        }

        /**
         * THE SLICE GUARD. `facts` is returned as array_slice($facts, 0, 3),
         * so anything living in that list can be pushed out by rank/since
         * facts. The reach note is the one thing Phillip asked for by name,
         * so it is its OWN key and must survive a full facts list.
         */
        public function testTheReachNoteCannotBeSlicedOffByOtherFacts(): void
        {
            $standing = self::buildStanding([
                'is_self'         => true,
                'reputation_tier' => 'caution',
                'rank_label'      => 'Journeyman',
                'member_since'    => '2026-01-01 00:00:00',
                'flags'           => [],
            ], true);

            self::assertLessThanOrEqual(3, count($standing['facts']), 'facts really is capped');
            self::assertTrue(
                $standing['reach_limited'],
                'the reach note survives whatever happens to the facts list'
            );
        }
        /** @param array<string, mixed> $base @return array<string, mixed> */
        private static function buildStanding(array $base, bool $viewerMaySeeFlags): array
        {
            $m = new ReflectionMethod(MemberProfileComposer::class, 'buildStanding');
            $m->setAccessible(true);

            /** @var array<string, mixed> $out */
            $out = $m->invoke(null, $base, $viewerMaySeeFlags);

            return $out;
        }
    }
}
