<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\MemberSelfPageService;
use PHPUnit\Framework\TestCase;

/**
 * Locks the deterministic self-page id scheme (Architecture A keystone):
 * a member's self-page id is ID_BASE + user_id, collision-free vs real
 * wp_post/peepso_page ids, with zero-DB bidirectional resolution. Pure
 * functions — no DB, no WP.
 */
final class MemberSelfPageTest extends TestCase
{
    public function testSelfPageIdIsDeterministicOffTheBase(): void
    {
        self::assertSame(MemberSelfPageService::ID_BASE + 1, MemberSelfPageService::selfPageId(1));
        self::assertSame(MemberSelfPageService::ID_BASE + 42, MemberSelfPageService::selfPageId(42));
    }

    public function testInvalidUserHasNoSelfPage(): void
    {
        self::assertSame(0, MemberSelfPageService::selfPageId(0));
        self::assertSame(0, MemberSelfPageService::selfPageId(-5));
    }

    public function testRoundTripsUserIdThroughSelfPageId(): void
    {
        foreach ([1, 42, 999, 1_000_000] as $uid) {
            $pid = MemberSelfPageService::selfPageId($uid);
            self::assertTrue(MemberSelfPageService::isSelfPage($pid));
            self::assertSame($uid, MemberSelfPageService::ownerOfSelfPage($pid));
        }
    }

    public function testRealPageIdsAreNotSelfPages(): void
    {
        // Real wp_post / peepso_page ids are auto-increment from 1 — far below
        // the reserved base, so they must never be mistaken for self-pages.
        foreach ([1, 2105, 999_999, MemberSelfPageService::ID_BASE] as $realId) {
            self::assertFalse(MemberSelfPageService::isSelfPage($realId), "page $realId must not be a self-page");
            self::assertSame(0, MemberSelfPageService::ownerOfSelfPage($realId));
        }
    }

    public function testSelfPageAndRealPageSpacesDoNotOverlap(): void
    {
        // The smallest self-page id (user 1) is strictly above the base, and
        // the base is above any plausible real id — so the two id spaces are
        // disjoint by construction.
        self::assertGreaterThan(MemberSelfPageService::ID_BASE, MemberSelfPageService::selfPageId(1));
    }
}
