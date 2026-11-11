<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit\CanPostBlogAgreement {

    use BCC\Trust\Core\REST\UserGroupsEndpoint;
    use BCC\Trust\Core\Services\GroupsService;
    use PHPUnit\Framework\Attributes\CoversClass;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
    use PHPUnit\Framework\TestCase;
    use ReflectionClass;
    use ReflectionMethod;

    require_once __DIR__ . '/../Stubs/can-post-blog-agreement-stubs.php';

    /**
     * `permissions.can_post_blog` on the profile Groups listing and
     * `GroupsService::viewerCanAuthorInGroup()` must never disagree.
     *
     * They answer the same question by two different routes, and that is
     * the whole risk. The listing reads a BATCHED `user_status` (one query
     * for the page) and compares it against the posting-capable set; the
     * service reads a PER-GROUP status and compares it against the same
     * set. Today they share one constant. If either side is ever
     * "simplified" — hardcoding a list, or reaching for the broader
     * read-allowed set — the picker starts offering groups the create call
     * will refuse, or hiding groups the author may really post in. Neither
     * failure announces itself.
     *
     * So this does not assert a constant against itself. It walks every
     * status the membership read can actually return and requires the two
     * implementations to produce the SAME verdict for each, with
     * `member_readonly` called out by name because it is the one value
     * where "is a member" and "may post" diverge.
     */
    #[CoversClass(UserGroupsEndpoint::class)]
    #[CoversClass(GroupsService::class)]
    #[RunTestsInSeparateProcesses]
    #[PreserveGlobalState(false)]
    final class CanPostBlogAgreesWithAuthorGateTest extends TestCase
    {
        private const VIEWER = 77;
        private const GROUP  = 9001;

        /**
         * Every status the membership read admits. The read filters
         * `gm_user_status LIKE 'member%'`, so these are exactly the values
         * that can reach either decision.
         *
         * @return list<array{string, bool}> [status, expected can-post]
         */
        public static function statuses(): array
        {
            return [
                ['member', true],
                ['member_owner', true],
                ['member_manager', true],
                ['member_moderator', true],
                // ⚠ The divergent one: a member who reads and cannot write.
                ['member_readonly', false],
            ];
        }

        /**
         * @param string $status
         * @param bool   $expected
         */
        #[\PHPUnit\Framework\Attributes\DataProvider('statuses')]
        public function testBothImplementationsAgreeForEveryMemberStatus(string $status, bool $expected): void
        {
            $listing = self::listingVerdict($status);
            $service = self::serviceVerdict($status);

            self::assertSame(
                $expected,
                $listing,
                "the profile listing disagrees with the expected verdict for {$status}"
            );
            self::assertSame(
                $expected,
                $service,
                "viewerCanAuthorInGroup disagrees with the expected verdict for {$status}"
            );
            self::assertSame(
                $service,
                $listing,
                "the two implementations have DRIFTED for {$status}"
            );
        }

        /**
         * Spelled out separately from the provider so the regression has a
         * name: this is the value that makes "is a member" the wrong
         * question for "may post".
         */
        public function testAReadOnlyMemberIsRefusedByBothRoutes(): void
        {
            self::assertFalse(self::listingVerdict('member_readonly'));
            self::assertFalse(self::serviceVerdict('member_readonly'));
        }

        /**
         * No active membership row at all — which is also what a FAILED
         * batched read looks like, since it returns no rows. Refusing is
         * the closed direction.
         */
        public function testNoMembershipIsRefusedByBothRoutes(): void
        {
            self::assertFalse(self::listingVerdict(null));
            self::assertFalse(self::serviceVerdict(null));
        }

        /**
         * The listing never answers this for someone else's profile, and
         * must not leak a true from another viewer's membership.
         */
        public function testTheListingRefusesOnAnotherMembersProfile(): void
        {
            $method = new ReflectionMethod(UserGroupsEndpoint::class, 'canPostBlog');
            $method->setAccessible(true);
            $endpoint = (new ReflectionClass(UserGroupsEndpoint::class))->newInstanceWithoutConstructor();

            /** @var array{allowed: bool, unlock_hint: string|null, reason_code: string|null} $out */
            $out = $method->invoke($endpoint, false, 'member_owner');

            self::assertFalse($out['allowed'], 'not applicable on another profile, so not allowed');
        }

        public function testARefusedReadOnlyMemberGetsAnExplanation(): void
        {
            $method = new ReflectionMethod(UserGroupsEndpoint::class, 'canPostBlog');
            $method->setAccessible(true);
            $endpoint = (new ReflectionClass(UserGroupsEndpoint::class))->newInstanceWithoutConstructor();

            /** @var array{allowed: bool, unlock_hint: string|null, reason_code: string|null} $out */
            $out = $method->invoke($endpoint, true, 'member_readonly');

            self::assertFalse($out['allowed']);
            self::assertNotNull($out['unlock_hint'], 'a refusal the author can act on should say why');
        }


        /**
         * SHAPE PARITY with its siblings. `can_join` and `can_leave` on this
         * same item both emit `{allowed, unlock_hint, reason_code}`. If
         * `can_post_blog` ever drops a key, the frontend has to special-case
         * one of three permissions that otherwise read identically — and the
         * editor loses the machine-readable reason it branches on.
         */
        public function testTheShapeMatchesItsSiblingPermissions(): void
        {
            $method = new ReflectionMethod(UserGroupsEndpoint::class, 'canPostBlog');
            $method->setAccessible(true);
            $sibling = new ReflectionMethod(UserGroupsEndpoint::class, 'canLeave');
            $sibling->setAccessible(true);
            $endpoint = (new ReflectionClass(UserGroupsEndpoint::class))->newInstanceWithoutConstructor();

            /** @var array<string, mixed> $mine */
            $mine = $method->invoke($endpoint, true, 'member');
            /** @var array<string, mixed> $theirs */
            $theirs = $sibling->invoke($endpoint, true);

            self::assertSame(
                array_keys($theirs),
                array_keys($mine),
                'can_post_blog must carry the same keys, in the same order, as can_leave'
            );
        }

        /**
         * The codes the editor branches on. Prose can change; a code the
         * frontend switches over cannot, silently.
         */
        public function testTheReasonCodesAreStable(): void
        {
            $method = new ReflectionMethod(UserGroupsEndpoint::class, 'canPostBlog');
            $method->setAccessible(true);
            $endpoint = (new ReflectionClass(UserGroupsEndpoint::class))->newInstanceWithoutConstructor();

            self::assertNull($method->invoke($endpoint, true, 'member')['reason_code']);
            self::assertSame('read_only_member', $method->invoke($endpoint, true, 'member_readonly')['reason_code']);
            self::assertSame('not_member', $method->invoke($endpoint, true, null)['reason_code']);
            self::assertSame('not_self', $method->invoke($endpoint, false, 'member_owner')['reason_code']);
        }

        /**
         * The listing's verdict, via the real private helper.
         */
        private static function listingVerdict(?string $status): bool
        {
            $method = new ReflectionMethod(UserGroupsEndpoint::class, 'canPostBlog');
            $method->setAccessible(true);
            $endpoint = (new ReflectionClass(UserGroupsEndpoint::class))->newInstanceWithoutConstructor();

            /** @var array{allowed: bool, unlock_hint: string|null, reason_code: string|null} $out */
            $out = $method->invoke($endpoint, true, $status);

            return $out['allowed'];
        }

        /**
         * The service's verdict, with the per-group status stubbed to the
         * same value the listing was given.
         */
        private static function serviceVerdict(?string $status): bool
        {
            \BCC\Core\Repositories\PeepSoGroupRepository::$statusToReturn = $status;

            /** @var GroupsService $service */
            $service = (new ReflectionClass(GroupsService::class))->newInstanceWithoutConstructor();

            return $service->viewerCanAuthorInGroup(self::VIEWER, self::GROUP);
        }
    }
}
