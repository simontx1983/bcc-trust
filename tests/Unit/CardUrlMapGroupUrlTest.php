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
 * Routing rule (matches §4.7.x group `type`):
 *   - hall  → /halls/{slug}
 *   - else  → /communities/{slug}   (nft / validator / system / user / unknown)
 *
 * `$slug` is the group `post_name` (already WP-sanitized); the composer's
 * own trim/leading-slash strip only guards a stray value so we never emit
 * `/halls//x`. Pure (no WP) — no stubs.
 */
final class CardUrlMapGroupUrlTest extends TestCase
{
    public function testHallTypeRoutesToHallsPrefix(): void
    {
        self::assertSame('/halls/ethereum-hall', CardUrlMap::groupUrl('hall', 'ethereum-hall'));
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
        // Only the exact 'hall' value routes to /halls/; everything else
        // (including an unrecognized type) is a /communities/ link.
        self::assertSame('/communities/mystery', CardUrlMap::groupUrl('', 'mystery'));
        self::assertSame('/communities/mystery', CardUrlMap::groupUrl('not-a-type', 'mystery'));
    }

    public function testSlugIsSanitized(): void
    {
        // Leading slash + surrounding whitespace are stripped so the
        // composed link never doubles the separator or carries padding.
        self::assertSame('/halls/ethereum-hall', CardUrlMap::groupUrl('hall', '  /ethereum-hall  '));
        self::assertSame('/communities/bored-apes', CardUrlMap::groupUrl('nft', '/bored-apes'));
    }
}
