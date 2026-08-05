<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\Feed\FeedHydrationPipeline;
use BCC\Trust\Core\ValueObjects\GroupContext;
use BCC\Trust\Core\ValueObjects\GroupType;
use BCC\Trust\Core\ValueObjects\PeepSoPrivacy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * SECRET-group attribution privacy gate on the feed `group` block.
 *
 * The block always ships `{id, type, verification}` (opaque — already on
 * the wire pre-feature). `name` + `link` reveal the group's IDENTITY, so
 * they are added ONLY when the viewer may know the group exists:
 *
 *   allowed  ⇔  privacy !== Secret  OR  viewer is an active member
 *
 * A secret group's name/link never leak to a non-member or anonymous
 * viewer, even though the (opaque) id/type still ship on a syndicated
 * post. This pins the pure `groupAttribution` seam that owns that
 * decision; the surrounding batched membership/name wiring in
 * hydrateGroupContexts needs live WP and is exercised via the golden net.
 */
final class FeedGroupAttributionTest extends TestCase
{
    private static function ctx(GroupType $type, PeepSoPrivacy $privacy): GroupContext
    {
        // groupId 4231, no trust weighting, no source, no verification.
        return new GroupContext(4231, $type, $privacy, false, null, null, null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function attribution(GroupContext $ctx, string $name, string $slug, bool $isMember): array
    {
        $m = new ReflectionMethod(FeedHydrationPipeline::class, 'groupAttribution');
        $m->setAccessible(true);
        /** @var array<string, mixed> $out */
        $out = $m->invoke(null, $ctx, $name, $slug, $isMember);
        return $out;
    }

    // (a) open / closed → name + link present ----------------------------

    public function testOpenGroupGetsNameAndLink(): void
    {
        $block = self::attribution(self::ctx(GroupType::Nft, PeepSoPrivacy::Open), 'Bored Apes', 'bored-apes', false);

        self::assertSame(4231, $block['id']);
        self::assertSame('nft', $block['type']);
        self::assertArrayHasKey('verification', $block);
        self::assertSame('Bored Apes', $block['name']);
        self::assertSame('/communities/bored-apes', $block['link']);
    }

    public function testClosedGroupGetsNameAndLink(): void
    {
        // Closed is not existence-hiding — a non-member still sees identity.
        $block = self::attribution(self::ctx(GroupType::Nft, PeepSoPrivacy::Closed), 'Punks', 'punks', false);

        self::assertSame('Punks', $block['name']);
        self::assertSame('/communities/punks', $block['link']);
    }

    // (b) secret + NON-member → id/type only, NO name/link ---------------

    public function testSecretGroupNonMemberGetsNoNameOrLink(): void
    {
        $block = self::attribution(self::ctx(GroupType::User, PeepSoPrivacy::Secret), 'Inner Circle', 'inner-circle', false);

        self::assertSame(4231, $block['id']);
        self::assertSame('user', $block['type']);
        self::assertArrayHasKey('verification', $block);
        self::assertArrayNotHasKey('name', $block);
        self::assertArrayNotHasKey('link', $block);
    }

    // (c) secret + member → name + link ----------------------------------

    public function testSecretGroupMemberGetsNameAndLink(): void
    {
        $block = self::attribution(self::ctx(GroupType::User, PeepSoPrivacy::Secret), 'Inner Circle', 'inner-circle', true);

        self::assertSame('Inner Circle', $block['name']);
        self::assertSame('/communities/inner-circle', $block['link']);
    }

    // (d) anonymous viewer + secret → hidden -----------------------------

    public function testAnonymousViewerNeverSeesSecretIdentity(): void
    {
        // Anon is modeled at this seam as a non-member (the caller issues
        // no membership read for viewerId <= 0), so isMember is false.
        $block = self::attribution(self::ctx(GroupType::Nft, PeepSoPrivacy::Secret), 'Whales', 'whales', false);

        self::assertArrayNotHasKey('name', $block);
        self::assertArrayNotHasKey('link', $block);
    }

    // (e) hall vs non-hall link prefix -----------------------------------

    public function testHallLinksToHallsNonHallToCommunities(): void
    {
        $hall = self::attribution(self::ctx(GroupType::Hall, PeepSoPrivacy::Open), 'Ethereum Hall', 'ethereum-hall', false);
        self::assertSame('/halls/ethereum-hall', $hall['link']);

        $nft = self::attribution(self::ctx(GroupType::Nft, PeepSoPrivacy::Open), 'Bored Apes', 'bored-apes', false);
        self::assertSame('/communities/bored-apes', $nft['link']);
    }

    // (f) name/slug surface as name; deleted-group edge → omit -----------

    public function testResolvedNameSurfacesAsNameField(): void
    {
        $block = self::attribution(self::ctx(GroupType::Hall, PeepSoPrivacy::Open), 'Polygon Hall', 'polygon-hall', false);
        self::assertSame('Polygon Hall', $block['name']);
    }

    public function testDeletedGroupEmptyNameOrSlugOmitsNameAndLink(): void
    {
        // findManyByIds row dropped out (deleted group) → empty name/slug.
        // Defensive: keep id/type, never emit a broken link.
        $noName = self::attribution(self::ctx(GroupType::Nft, PeepSoPrivacy::Open), '', 'bored-apes', false);
        self::assertArrayNotHasKey('name', $noName);
        self::assertArrayNotHasKey('link', $noName);

        $noSlug = self::attribution(self::ctx(GroupType::Nft, PeepSoPrivacy::Open), 'Bored Apes', '', false);
        self::assertArrayNotHasKey('name', $noSlug);
        self::assertArrayNotHasKey('link', $noSlug);
    }
}
