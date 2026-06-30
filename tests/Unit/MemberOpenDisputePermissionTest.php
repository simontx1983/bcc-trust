<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Repositories\VoteRepository;
use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Core\Services\MemberSelfPageService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionObject;

/**
 * Pins the §4.4 can_open_dispute gate for member self-pages
 * (CardViewService::resolveMemberOpenDispute).
 *
 * Owner-only by construction:
 *   - non-owner  → not_applicable (never the owner dispute entry point);
 *   - owner with a contestable active downvote on selfPageId → allowed;
 *   - owner with nothing to contest → no_contestable_downvote denial.
 *
 * Isolation: CardViewService has an eleven-dependency constructor we do
 * NOT want to build, so the instance is created via
 * newInstanceWithoutConstructor() and a fake VoteRepository (overriding
 * only hasActiveDownvoteForPage, no DB) is injected into the typed
 * `voteRepo` property by reflection. The private method is invoked by
 * reflection. MemberSelfPageService stays real (pure static selfPageId).
 */
final class MemberOpenDisputePermissionTest extends TestCase
{
    private const TARGET_USER_ID = 7;

    private function makeService(bool $hasDownvote, int $expectedPageId): CardViewService
    {
        $fakeVoteRepo = new class($hasDownvote, $expectedPageId) extends VoteRepository {
            public function __construct(
                private readonly bool $hasDownvote,
                private readonly int $expectedPageId
            ) {
                // Skip parent constructor (TableRegistry + BCC_TRUST_* consts).
            }

            public function hasActiveDownvoteForPage(int $pageId): bool
            {
                // The id-duality proof: the gate must read the SELF-PAGE id.
                MemberOpenDisputePermissionTest::assertSame($this->expectedPageId, $pageId);
                return $this->hasDownvote;
            }
        };

        $instance = (new \ReflectionClass(CardViewService::class))->newInstanceWithoutConstructor();
        $prop = (new ReflectionObject($instance))->getProperty('voteRepo');
        $prop->setValue($instance, $fakeVoteRepo);

        return $instance;
    }

    /** @return array{allowed: bool, unlock_hint: string|null, reason_code: string|null} */
    private function invoke(CardViewService $service, int $targetUserId, bool $isSelf): array
    {
        $method = new ReflectionMethod(CardViewService::class, 'resolveMemberOpenDispute');
        /** @var array{allowed: bool, unlock_hint: string|null, reason_code: string|null} $result */
        $result = $method->invoke($service, $targetUserId, $isSelf);
        return $result;
    }

    public function testNonOwnerNeverGetsOpenDispute(): void
    {
        // Non-owner: gate short-circuits before any vote read, so the
        // expected page id is irrelevant (downvote presence never read).
        $service = $this->makeService(true, MemberSelfPageService::selfPageId(self::TARGET_USER_ID));
        $perm = $this->invoke($service, self::TARGET_USER_ID, false);

        self::assertFalse($perm['allowed']);
        self::assertSame('not_applicable', $perm['reason_code']);
    }

    public function testOwnerWithContestableDownvoteIsAllowed(): void
    {
        $service = $this->makeService(true, MemberSelfPageService::selfPageId(self::TARGET_USER_ID));
        $perm = $this->invoke($service, self::TARGET_USER_ID, true);

        self::assertTrue($perm['allowed']);
        self::assertNull($perm['reason_code']);
    }

    public function testOwnerWithNoContestableDownvoteIsDenied(): void
    {
        $service = $this->makeService(false, MemberSelfPageService::selfPageId(self::TARGET_USER_ID));
        $perm = $this->invoke($service, self::TARGET_USER_ID, true);

        self::assertFalse($perm['allowed']);
        self::assertSame('no_contestable_downvote', $perm['reason_code']);
    }
}
