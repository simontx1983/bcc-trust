<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\PostsService;
use PHPUnit\Framework\TestCase;

/**
 * Rank Phase 7 (§21.3) — ranked-Hall-channel shape gates on the post
 * write path (the pure halves of PostsService's hall_feed handling).
 *
 * Division of labor:
 *   - channel SHAPE ('ranked' only valid against a Hall) + selector
 *     normalization → HERE (pure statics, no WordPress)
 *   - WHO may post ranked (Journeyman+ AND Neutral+ AND not suspended
 *     AND not in recovery) → CapabilityMatrixTest's
 *     post_ranked_hall_feed matrix (the resolver is authoritative;
 *     gateGroupPost consults it after the membership checks)
 *   - channel SPLIT on read → bcc-core PeepSoActivityRepository's
 *     `_bcc_ranked_feed` INNER/anti-JOIN pair
 */
final class RankedFeedGateTest extends TestCase
{
    public function testNormalizeCollapsesEverythingButRankedToMain(): void
    {
        self::assertSame('ranked', PostsService::normalizeHallFeed('ranked'));

        foreach (['main', '', 'RANKED', 'Ranked', 'bogus', 'ranked ', '0'] as $raw) {
            self::assertSame(
                'main',
                PostsService::normalizeHallFeed($raw),
                'raw ' . var_export($raw, true) . ' must collapse to main'
            );
        }
    }

    public function testRankedAgainstAHallPasses(): void
    {
        self::assertNull(PostsService::rankedChannelError('ranked', true));
    }

    public function testRankedOutsideAHallIsInvalidRequest(): void
    {
        // Covers BOTH invalid shapes: hall_feed=ranked on a non-Hall
        // group, and hall_feed=ranked with no group target at all
        // (callers pass isHallGroup=false for a wall post).
        $error = PostsService::rankedChannelError('ranked', false);

        self::assertIsArray($error);
        self::assertSame('bcc_invalid_request', $error['error']);
        self::assertSame('Only Halls have a ranked feed.', $error['message']);
    }

    public function testMainChannelNeverErrorsRegardlessOfGroupKind(): void
    {
        self::assertNull(PostsService::rankedChannelError('main', true));
        self::assertNull(PostsService::rankedChannelError('main', false));
    }
}
