<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Support\CardUrlMap;
use PHPUnit\Framework\TestCase;

/**
 * Pins CardUrlMap::groupUrl — the single server-side composer for a
 * group's canonical frontend route (feed `group.link`, and any future
 * group-link emitter).
 *
 * Routing rule: every group `type` → /communities/{slug} (hall / nft /
 * validator / system / user / unknown). Halls moved there in structure A
 * (F-594); they were /halls/{slug}.
 *
 * `$slug` is the group `post_name` (already WP-sanitized); the composer's
 * own trim/leading-slash strip only guards a stray value so we never emit
 * `/communities//x`. Pure (no WP) — no stubs.
 */
final class CardUrlMapGroupUrlTest extends TestCase
{
    public function testHallTypeRoutesToCommunitiesPrefix(): void
    {
        self::assertSame('/communities/ethereum-hall', CardUrlMap::groupUrl('hall', 'ethereum-hall'));
    }

    public function testNonHallTypesRouteToCommunitiesPrefix(): void
    {
        self::assertSame('/communities/bored-apes', CardUrlMap::groupUrl('nft', 'bored-apes'));
        self::assertSame('/communities/eth-delegators', CardUrlMap::groupUrl('validator', 'eth-delegators'));
        self::assertSame('/communities/announcements', CardUrlMap::groupUrl('system', 'announcements'));
        self::assertSame('/communities/book-club', CardUrlMap::groupUrl('user', 'book-club'));
    }

    public function testUnknownTypeFallsBackToCommunitiesPrefix(): void
    {
        // An unrecognized type is a /communities/ link like every other.
        self::assertSame('/communities/mystery', CardUrlMap::groupUrl('', 'mystery'));
        self::assertSame('/communities/mystery', CardUrlMap::groupUrl('not-a-type', 'mystery'));
    }

    public function testSlugIsSanitized(): void
    {
        // Leading slash + surrounding whitespace are stripped so the
        // composed link never doubles the separator or carries padding.
        self::assertSame('/communities/ethereum-hall', CardUrlMap::groupUrl('hall', '  /ethereum-hall  '));
        self::assertSame('/communities/bored-apes', CardUrlMap::groupUrl('nft', '/bored-apes'));
    }
}
