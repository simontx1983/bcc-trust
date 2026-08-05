<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\REST\CardsSearchEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pins the community/member SearchSuggestion builders (contract §4.9,
 * v1.70):
 *
 *   - Community rows carry the §3.2.4 trust placeholders (tier
 *     'neutral', label null, score null) — never a fabricated trust
 *     claim.
 *   - Member rows carry the REAL tier/label/score handed in by the
 *     batch-primed caller.
 *   - `is_verified` is OMITTED from both (unavailable ≠ false — the
 *     users vertical doesn't carry the authoritative email signal, and
 *     communities have no owner-email meaning). Emitting false would
 *     assert something untrue about verified members.
 *   - `is_claim_verified` is false on both (§3.2 — not
 *     on-chain-claimable).
 *   - Rows whose href fails InternalPath validation are DROPPED — an
 *     absolute URL from a version-skewed upstream must never reach the
 *     frontend as a suggestion href.
 */
#[CoversClass(CardsSearchEndpoint::class)]
final class CardsSearchSuggestionBuildersTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function groupRow(array $overrides = []): array
    {
        return $overrides + [
            'id'          => 17,
            'name'        => 'Cosmos Hall',
            'slug'        => 'cosmos-hall',
            'description' => 'The Cosmos chain\'s home hall.',
            'avatar_url'  => null,
            'group_url'   => '/halls/cosmos-hall',
            'kind'        => 'hall',
            'kind_label'  => 'CHAIN HALL',
        ];
    }

    /** @return array<string, mixed> */
    private static function userRow(array $overrides = []): array
    {
        return $overrides + [
            'id'           => 42,
            'username'     => 'simontx',
            'display_name' => 'Simon',
            'avatar_url'   => null,
            'profile_url'  => '/u/simontx',
        ];
    }

    public function testCommunitySuggestionShapeIsThePlaceholderContract(): void
    {
        $s = CardsSearchEndpoint::buildCommunitySuggestion(self::groupRow());

        self::assertSame(
            [
                'id'                    => 17,
                'name'                  => 'Cosmos Hall',
                'handle'                => 'cosmos-hall',
                'card_kind'             => 'community',
                'reputation_tier'       => 'neutral',
                'reputation_tier_label' => null,
                'trust_score'           => null,
                'is_claim_verified'     => false,
                'href'                  => '/halls/cosmos-hall',
            ],
            $s,
        );
    }

    public function testMemberSuggestionCarriesRealReputationValues(): void
    {
        $s = CardsSearchEndpoint::buildMemberSuggestion(self::userRow(), 'trusted', 'Trusted', 71);

        self::assertSame(
            [
                'id'                    => 42,
                'name'                  => 'Simon',
                'handle'                => 'simontx',
                'card_kind'             => 'member',
                'reputation_tier'       => 'trusted',
                'reputation_tier_label' => 'Trusted',
                'trust_score'           => 71,
                'is_claim_verified'     => false,
                'href'                  => '/u/simontx',
            ],
            $s,
        );
    }

    public function testRiskyMemberIsBuiltNotDropped(): void
    {
        // All tiers surface (lookup surface, not endorsement — §4.9).
        $s = CardsSearchEndpoint::buildMemberSuggestion(self::userRow(), 'risky', 'Risky', 12);

        self::assertNotNull($s);
        self::assertSame('risky', $s['reputation_tier']);
        self::assertSame('Risky', $s['reputation_tier_label']);
    }

    public function testIsVerifiedKeyIsOmittedNeverFalse(): void
    {
        $community = CardsSearchEndpoint::buildCommunitySuggestion(self::groupRow());
        $member    = CardsSearchEndpoint::buildMemberSuggestion(self::userRow(), 'elite', 'Elite', 98);

        self::assertNotNull($community);
        self::assertNotNull($member);
        self::assertArrayNotHasKey('is_verified', $community, 'omitted means unavailable, not false');
        self::assertArrayNotHasKey('is_verified', $member, 'omitted means unavailable, not false');
    }

    public function testDisplayNameFallsBackToUsername(): void
    {
        $s = CardsSearchEndpoint::buildMemberSuggestion(
            self::userRow(['display_name' => '']),
            'neutral',
            'Neutral',
            50,
        );

        self::assertNotNull($s);
        self::assertSame('simontx', $s['name']);
    }

    public function testOffAppHrefsAreDroppedNotEmitted(): void
    {
        // Version-skew defense: a pre-v1.70 bcc-search still emits
        // absolute WP permalinks — those rows must vanish, not link
        // off-app.
        $absoluteGroup = CardsSearchEndpoint::buildCommunitySuggestion(
            self::groupRow(['group_url' => 'https://wp.example/groups/cosmos-hall/']),
        );
        $protocolRelative = CardsSearchEndpoint::buildCommunitySuggestion(
            self::groupRow(['group_url' => '//evil.example/x']),
        );
        $absoluteMember = CardsSearchEndpoint::buildMemberSuggestion(
            self::userRow(['profile_url' => 'https://wp.example/profile/simontx']),
            'trusted',
            'Trusted',
            71,
        );

        self::assertNull($absoluteGroup);
        self::assertNull($protocolRelative);
        self::assertNull($absoluteMember);
    }

    public function testRowsMissingRequiredIdentityAreDropped(): void
    {
        self::assertNull(CardsSearchEndpoint::buildCommunitySuggestion(self::groupRow(['id' => 0])));
        self::assertNull(CardsSearchEndpoint::buildCommunitySuggestion(self::groupRow(['name' => ''])));
        self::assertNull(
            CardsSearchEndpoint::buildMemberSuggestion(self::userRow(['id' => 0]), 'neutral', 'Neutral', 50),
        );
        self::assertNull(
            CardsSearchEndpoint::buildMemberSuggestion(self::userRow(['username' => '']), 'neutral', 'Neutral', 50),
        );
    }
}
