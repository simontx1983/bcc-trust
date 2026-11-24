<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\Services\CardViewService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The community card's `kicker` copy, per group type.
 *
 * ## Why this test did not exist, and why that mattered
 *
 * `kicker` is contract-locked (§3.2.4) and server-owned (§L5 — the frontend
 * renders it VERBATIM and no longer derives it from `type`). So the string
 * in this map is the string a member reads.
 *
 * Nothing asserted it. A grep of the whole `tests/` tree for `kicker` found
 * one hit, and it was prose in another file's docblock. That is how the map
 * and contract §3.2.4 were free to drift apart: the contract listed five
 * values, the code held five values, and no check compared either to
 * anything.
 *
 * ## What is pinned
 *
 * All five, not only the two that just changed. A test covering only the
 * changed entries would let the next edit move `SYSTEM COMMUNITY` silently
 * — and the point of the 2026-11-24 decision is that those two moved and
 * the others deliberately did not.
 *
 * ⚠ `nft` and `validator` read "GROUP" and reverse the v1.52 "Communities
 * vocabulary convergence" for those two kinds only. The mixture with
 * `SYSTEM COMMUNITY` and the `COMMUNITY` fallback is intended, so this test
 * fails if someone "finishes" the revert.
 *
 * ## Why the service is built without its constructor
 *
 * `getCommunityCardFromGroupData()` is pure composition: its docblock says
 * zero DB reads, and its body touches none of the injected collaborators
 * (verified — no `$this->` anywhere in it). Standing up six repositories
 * and services would test the container rather than the copy, and would
 * make this test fail for reasons that have nothing to do with it.
 */
#[Group('unit')]
#[CoversClass(CardViewService::class)]
final class CommunityKickerCopyTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function kickerByType(): array
    {
        return [
            'holder group (on-chain gated)'    => ['nft', 'HOLDER GROUP'],
            'delegator group (on-chain gated)' => ['validator', 'DELEGATOR GROUP'],
            'chain hall'                       => ['hall', 'CHAIN HALL'],
            'system community'                 => ['system', 'SYSTEM COMMUNITY'],
            'member-made community'            => ['user', 'COMMUNITY'],
        ];
    }

    #[DataProvider('kickerByType')]
    public function testEachGroupTypeGetsItsOwnKicker(string $type, string $expected): void
    {
        self::assertSame($expected, self::kickerFor($type));
    }

    /**
     * An unknown type falls back to the plainest word, not to one of the
     * specific kinds. A new group type shipping with a borrowed kicker
     * would tell members it is something it is not.
     */
    public function testAnUnknownTypeFallsBackToTheGenericKicker(): void
    {
        self::assertSame('COMMUNITY', self::kickerFor('something-new'));
    }

    /**
     * ⚠ The two that moved say GROUP and the rest do not. Asserted as a
     * relationship rather than five separate values, so that "finishing"
     * the revert fails here with a message that says why.
     */
    public function testOnlyTheTwoOnChainGatedKindsSayGroup(): void
    {
        self::assertStringContainsString('GROUP', self::kickerFor('nft'));
        self::assertStringContainsString('GROUP', self::kickerFor('validator'));

        foreach (['hall', 'system', 'user'] as $unchanged) {
            self::assertStringNotContainsString(
                'GROUP',
                self::kickerFor($unchanged),
                $unchanged . ' took "GROUP" too — the 2026-11-24 decision moved'
                . ' nft and validator ONLY, and the mixture is intended'
            );
        }
    }

    /** The composed card's `kicker` for one group type. */
    private static function kickerFor(string $type): string
    {
        $service = (new ReflectionClass(CardViewService::class))->newInstanceWithoutConstructor();

        $card = $service->getCommunityCardFromGroupData(self::groupData($type), 0);

        self::assertArrayHasKey('kicker', $card, 'the card stopped carrying a kicker');

        return (string) $card['kicker'];
    }

    /**
     * One group's data, in the shape a real caller passes.
     *
     * ⚠ Modelled on `GroupsDiscoveryEndpoint`'s own call rather than
     * trimmed to the keys this assertion happens to need. A fixture built
     * by adding keys until the warnings stop is a fixture that drifts the
     * moment the method reads one more field — and the method reads
     * `privacy`, `verification`, `trust_min` and `collection_stats` for the
     * community_dossier whatever the kicker does.
     *
     * @return array<string, mixed>
     */
    private static function groupData(string $type): array
    {
        return [
            'group_id'          => 12,
            'name'              => 'A Group',
            'slug'              => 'a-group',
            'type'              => $type,
            'privacy'           => 'public',
            // Null so every type takes the tier band: the crest band is a
            // separate rule (§3.2.4) and not what this test is about.
            'chain_tag'         => null,
            'description'       => null,
            'image_url'         => null,
            'verification'      => null,
            'collection_stats'  => null,
            'trust_min'         => 0,
            'member_count'      => 3,
            'posts_last_7d'     => 1,
            'active_members_7d' => 2,
            'viewer_is_member'  => false,
            'group_url'         => '/communities/a-group',
        ];
    }
}
