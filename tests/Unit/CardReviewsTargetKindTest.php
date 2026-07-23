<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\CardReviewsEndpoint;
use BCC\Trust\Core\Services\MemberSelfPageService;
use PHPUnit\Framework\TestCase;
use ReflectionClassConstant;

/**
 * Pins the received-reviews target contract on
 * GET /entities/{target_kind}/{target_id}/reviews:
 *
 *   - `user_profile` is an allowed target kind (member profiles read
 *     their received reviews through the same endpoint as entity
 *     cards — Phillip, 2026-07-22).
 *   - Its target_id is the RAW user id; votes live on the member's
 *     self-page, so the endpoint must query selfPageId(target_id),
 *     never the raw id (which would collide with a real entity page).
 */
final class CardReviewsTargetKindTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function allowedKinds(): array
    {
        $const = new ReflectionClassConstant(CardReviewsEndpoint::class, 'ALLOWED_TARGET_KINDS');
        /** @var list<string> $kinds */
        $kinds = $const->getValue();
        return $kinds;
    }

    public function testUserProfileIsAnAllowedTargetKind(): void
    {
        self::assertContains('user_profile', self::allowedKinds());
    }

    public function testEntityCardKindsRemainAllowed(): void
    {
        $kinds = self::allowedKinds();
        foreach (['validator_card', 'project_card', 'creator_card'] as $kind) {
            self::assertContains($kind, $kinds);
        }
    }

    public function testMemberReviewsQueryTheSelfPageNeverTheRawUserId(): void
    {
        // The invariant the endpoint's translation exists to uphold:
        // a raw user id is NOT a page id.
        self::assertSame(
            MemberSelfPageService::ID_BASE + 42,
            MemberSelfPageService::selfPageId(42)
        );
        self::assertFalse(
            MemberSelfPageService::isSelfPage(42),
            'a raw user id must never be treated as a reviews page id'
        );
        self::assertSame(
            42,
            MemberSelfPageService::ownerOfSelfPage(MemberSelfPageService::selfPageId(42))
        );
    }
}
