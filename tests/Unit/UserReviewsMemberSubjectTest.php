<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\MemberSelfPageService;
use BCC\Trust\Core\Services\UserReviewsService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Member-target rows in the written-reviews list have no wp_posts row
 * (the LEFT JOIN columns come back NULL), so the subject must resolve
 * from the self-page owner's UserMini row instead — display name +
 * bcc_handle, scope MEMBER. Regression pin for the blank-subject bug
 * ("" / "ON @" rows after reviewing a member).
 */
final class UserReviewsMemberSubjectTest extends TestCase
{
    private const NOW = 1_750_000_000;

    /**
     * @param array<int, array{id: int, user_login: string, display_name: string, handle: string|null}> $members
     * @return array<string, mixed>
     */
    private function shape(object $row, array $members): array
    {
        $m = new ReflectionMethod(UserReviewsService::class, 'shapeReview');
        $m->setAccessible(true);
        /** @var array<string, mixed> $out */
        $out = $m->invoke(null, $row, self::NOW, $members);
        return $out;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function row(array $overrides = []): object
    {
        return (object) array_merge([
            'id'          => 41,
            'page_id'     => 123,
            'vote_type'   => 1,
            'explanation' => 'Shows up on time.',
            'reason'      => null,
            'created_at'  => '2026-07-01 12:00:00',
            'page_title'  => 'Everstake',
            'page_name'   => 'everstake',
            'page_type'   => 'peepso-page',
        ], $overrides);
    }

    private static function memberRow(int $userId): object
    {
        // What the LEFT JOIN yields for a self-page target: NULL page cols.
        return self::row([
            'page_id'    => MemberSelfPageService::selfPageId($userId),
            'page_title' => null,
            'page_name'  => null,
            'page_type'  => null,
        ]);
    }

    public function testMemberTargetResolvesSubjectFromUserMiniRow(): void
    {
        $out = $this->shape(self::memberRow(77), [
            77 => ['id' => 77, 'user_login' => 'simon_login', 'display_name' => 'Simon TX', 'handle' => 'simontx'],
        ]);

        self::assertSame('Simon TX', $out['subject']);
        self::assertSame('simontx', $out['subject_handle']);
        self::assertSame('MEMBER', $out['scope_label']);
        self::assertSame('A', $out['grade']);
    }

    public function testHandlelessMemberNeverLeaksUserLogin(): void
    {
        $out = $this->shape(self::memberRow(77), [
            77 => ['id' => 77, 'user_login' => 'simon_login', 'display_name' => 'Simon TX', 'handle' => null],
        ]);

        self::assertSame('Simon TX', $out['subject']);
        self::assertSame('', $out['subject_handle'], 'no bcc_handle → empty, never user_login');
    }

    public function testUnknownMemberDegradesToEmptySubject(): void
    {
        $out = $this->shape(self::memberRow(99), []);

        self::assertSame('', $out['subject']);
        self::assertSame('', $out['subject_handle']);
        self::assertSame('MEMBER', $out['scope_label']);
    }

    public function testEntityRowShapeIsUnchanged(): void
    {
        $out = $this->shape(self::row(), [
            77 => ['id' => 77, 'user_login' => 'x', 'display_name' => 'X', 'handle' => 'x'],
        ]);

        self::assertSame('Everstake', $out['subject']);
        self::assertSame('everstake', $out['subject_handle']);
        self::assertSame('PAGE', $out['scope_label']);
    }
}
