<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Plugin;
use BCC\Trust\Core\Services\CardReviewsService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-345: CardReviewsService::withReply() is the one row mapper BOTH Reviews
 * lists use (the entity tab and the member tab). It must carry the viewer's
 * stale reply (user decision 2026-11-18) through the same author hydration
 * as `reply`, and default every field when a row has no bundle.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(CardReviewsService::class)]
final class CardReviewsWithReplyTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/card-reviews-with-reply-stubs.php';
    }

    /** @return array{id: int, body: string, author_id: int, posted_at: string, edited_at: string|null, feed_id: string, permissions: array<string, array{allowed: bool, unlock_hint: string|null, reason_code: string|null}>} */
    private static function reply(int $authorId, string $body): array
    {
        return [
            'id'          => 9000 + $authorId,
            'body'        => $body,
            'author_id'   => $authorId,
            'posted_at'   => '2026-11-18T10:00:00+00:00',
            'edited_at'   => null,
            'feed_id'     => 'feed_' . (9100 + $authorId),
            'permissions' => [],
        ];
    }

    public function testTheViewersStaleReplyIsHydratedBesideTheReply(): void
    {
        $prefetched = ['follower_counts' => []];
        $bundle     = [
            'reply'              => self::reply(506, 'from the new operator'),
            'viewer_stale_reply' => self::reply(504, 'from the old operator'),
            'can_reply'          => ['allowed' => false, 'unlock_hint' => null, 'reason_code' => 'not_applicable'],
        ];

        $item = CardReviewsService::withReply(['id' => 70], $bundle, 504, $prefetched);

        self::assertSame('from the new operator', $item['reply']['body'] ?? null);
        self::assertSame('from the old operator', $item['viewer_stale_reply']['body'] ?? null);
        self::assertSame(['id' => 504], $item['viewer_stale_reply']['author'] ?? null, 'hydrated like the reply');
        self::assertSame('not_applicable', $item['permissions']['can_reply']['reason_code']);
        self::assertSame(
            [['author_id' => 506, 'viewer' => 504, 'prefetched' => $prefetched], ['author_id' => 504, 'viewer' => 504, 'prefetched' => $prefetched]],
            Plugin::$withAuthorCalls,
            'both through the list prefetch'
        );
    }

    public function testARowWithoutABundleHasNoReplyNoStaleReplyAndCannotReply(): void
    {
        $item = CardReviewsService::withReply(['id' => 70], null, 504, null);

        self::assertArrayHasKey('viewer_stale_reply', $item, 'the field is always present');
        self::assertNull($item['viewer_stale_reply']);
        self::assertNull($item['reply']);
        self::assertSame('not_applicable', $item['permissions']['can_reply']['reason_code']);
        self::assertSame([], Plugin::$withAuthorCalls);
    }
}
