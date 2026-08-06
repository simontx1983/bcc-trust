<?php

declare(strict_types=1);

namespace BCC\Trust\Core\Tests\Unit;

use BCC\Trust\Core\Services\WatchingService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pins the §4.5 `include=cards` full-Card hydration seam (contract
 * v1.76) on GET /bcc/v1/me/watching.
 *
 * The three phases of WatchingService::attachCards are split so the two
 * that need no I/O are pure statics; this suite exercises exactly those:
 *
 *   - groupHydrationTargets — items → hydration targets. The LOCKED
 *     field semantics live here: members key on `card_id` (always the
 *     followee user_id), page kinds key on `page_id` (NEVER card_id, which
 *     is the user for peepso rows), and `is_resolved` is deliberately not
 *     a branch — {is_resolved: true, card_kind: 'member'} is the valid
 *     "page-backed but no contract kind" state (a dao page) and must
 *     hydrate as a member card.
 *   - attachBuiltCards   — targets + built cards → items. Rows are NEVER
 *     dropped for hydration reasons; `card` is appended as the LAST key
 *     and is null when unhydratable.
 *
 * Plus the hydrated page_size clamp (24 vs. the identifier-only 50).
 *
 * Isolation: all three are private statics with no DB and no WordPress
 * dependency, invoked by reflection. No bootstrap beyond the plugin
 * config the suite already loads.
 */
final class WatchingCardHydrationTest extends TestCase
{
    // ──────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * A watch item in the shape WatchingService::buildItem emits, so the
     * grouper is fed the same field set production hands it.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function item(array $overrides = []): array
    {
        return array_merge([
            'follow_id'                      => 1,
            'follow_source'                  => 'peepso',
            'card_kind'                      => 'member',
            'is_resolved'                    => false,
            'card_id'                        => 100,
            'card_handle'                    => 'someone',
            'card_slug'                      => null,
            'page_id'                        => null,
            'reputation_tier_at_watch'       => null,
            'reputation_tier_label_at_watch' => null,
            'batch_id'                       => null,
            'watched_at'                     => null,
            'is_legacy'                      => true,
            'links'                          => ['card' => '/u/someone'],
            'actions'                        => [
                'view' => [
                    'method'        => 'GET',
                    'href'          => 'https://example.test/wp-json/bcc/v1/cards/member/someone',
                    'idempotent'    => true,
                    'requires_auth' => false,
                ],
            ],
        ], $overrides);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array{
     *   member_targets: array<int, int>,
     *   page_targets: array<int, array{kind: string, page_id: int}>,
     *   member_ids: list<int>,
     *   page_ids: list<int>
     * }
     */
    private static function group(array $items): array
    {
        $method = new ReflectionMethod(WatchingService::class, 'groupHydrationTargets');
        /**
         * @var array{
         *   member_targets: array<int, int>,
         *   page_targets: array<int, array{kind: string, page_id: int}>,
         *   member_ids: list<int>,
         *   page_ids: list<int>
         * } $grouped
         */
        $grouped = $method->invoke(null, $items);
        return $grouped;
    }

    /**
     * @param list<array<string, mixed>>          $items
     * @param array<string, mixed>                $grouped
     * @param array<int, array<string, mixed>>    $memberCards
     * @param array<string, array<string, mixed>> $pageCards
     * @return list<array<string, mixed>>
     */
    private static function attach(
        array $items,
        array $grouped,
        array $memberCards = [],
        array $pageCards = []
    ): array {
        $method = new ReflectionMethod(WatchingService::class, 'attachBuiltCards');
        /** @var list<array<string, mixed>> $out */
        $out = $method->invoke(null, $items, $grouped, $memberCards, $pageCards);
        return $out;
    }

    private static function clamp(int $pageSize, bool $includeCards): int
    {
        $method = new ReflectionMethod(WatchingService::class, 'clampPageSize');
        return (int) $method->invoke(null, $pageSize, $includeCards);
    }

    // ──────────────────────────────────────────────────────────────
    // groupHydrationTargets
    // ──────────────────────────────────────────────────────────────

    public function testMemberRowsGroupByCardId(): void
    {
        $grouped = self::group([
            self::item(['card_kind' => 'member', 'card_id' => 42]),
        ]);

        self::assertSame([0 => 42], $grouped['member_targets']);
        self::assertSame([42], $grouped['member_ids']);
        self::assertSame([], $grouped['page_targets']);
        self::assertSame([], $grouped['page_ids']);
    }

    public function testPageKindsGroupByPageIdNotCardId(): void
    {
        $grouped = self::group([
            self::item(['card_kind' => 'validator', 'card_id' => 7, 'page_id' => 1842, 'is_resolved' => true]),
            self::item(['card_kind' => 'project',   'card_id' => 8, 'page_id' => 1900, 'is_resolved' => true]),
            self::item(['card_kind' => 'creator',   'card_id' => 9, 'page_id' => 1955, 'is_resolved' => true]),
        ]);

        self::assertSame(
            [
                0 => ['kind' => 'validator', 'page_id' => 1842],
                1 => ['kind' => 'project',   'page_id' => 1900],
                2 => ['kind' => 'creator',   'page_id' => 1955],
            ],
            $grouped['page_targets'],
            'page kinds must key off page_id — card_id is the followee user for peepso rows'
        );
        self::assertSame([1842, 1900, 1955], $grouped['page_ids']);
        self::assertSame([], $grouped['member_targets'], 'no page kind may leak into the member batch');
        self::assertSame([], $grouped['member_ids']);
    }

    public function testUnknownKindYieldsNoTarget(): void
    {
        $grouped = self::group([
            self::item(['card_kind' => 'dao', 'card_id' => 42, 'page_id' => 1842]),
            self::item(['card_kind' => '',    'card_id' => 43, 'page_id' => 1843]),
        ]);

        self::assertSame([], $grouped['member_targets']);
        self::assertSame([], $grouped['page_targets']);
        self::assertSame([], $grouped['member_ids']);
        self::assertSame([], $grouped['page_ids']);
    }

    public function testUnresolvedMemberRowStillGroupsAsMember(): void
    {
        // is_resolved is NOT a branch — a member-only follow hydrates
        // exactly like any other member row.
        $grouped = self::group([
            self::item(['card_kind' => 'member', 'is_resolved' => false, 'card_id' => 11]),
        ]);

        self::assertSame([0 => 11], $grouped['member_targets']);
        self::assertSame([11], $grouped['member_ids']);
    }

    public function testResolvedMemberRowGroupsAsMember(): void
    {
        // {is_resolved: true, card_kind: 'member'} is the LOCKED dao-page
        // state: page-backed, no contract kind. It must hydrate as the
        // owner's member card, not be skipped and not become a page target.
        $grouped = self::group([
            self::item([
                'card_kind'   => 'member',
                'is_resolved' => true,
                'card_id'     => 77,
                'page_id'     => 2400,
            ]),
        ]);

        self::assertSame([0 => 77], $grouped['member_targets'], 'dao-page rows hydrate as the owner member card');
        self::assertSame([77], $grouped['member_ids']);
        self::assertSame([], $grouped['page_targets'], 'a member kind must never become a page target');
        self::assertSame([], $grouped['page_ids']);
    }

    public function testPageKindWithMissingPageIdYieldsNoTarget(): void
    {
        $absent = self::item(['card_kind' => 'validator', 'card_id' => 7, 'page_id' => 1842]);
        unset($absent['page_id']);

        $grouped = self::group([
            self::item(['card_kind' => 'validator', 'card_id' => 7, 'page_id' => null]),
            self::item(['card_kind' => 'project',   'card_id' => 8, 'page_id' => 0]),
            $absent,
        ]);

        self::assertSame([], $grouped['page_targets'], 'a page kind without a page_id must not be guessed from card_id');
        self::assertSame([], $grouped['page_ids']);
        self::assertSame([], $grouped['member_targets']);
    }

    public function testDuplicateTargetsDedupeInPrefetchListsButBothRowsAttach(): void
    {
        $items = [
            self::item(['follow_id' => 1, 'card_kind' => 'member',    'card_id' => 42]),
            self::item(['follow_id' => 2, 'card_kind' => 'member',    'card_id' => 42]),
            self::item(['follow_id' => 3, 'card_kind' => 'validator', 'card_id' => 7, 'page_id' => 1842]),
            self::item(['follow_id' => 4, 'card_kind' => 'validator', 'card_id' => 9, 'page_id' => 1842]),
        ];

        $grouped = self::group($items);

        self::assertSame([42], $grouped['member_ids'], 'member prefetch ids must be deduped');
        self::assertSame([1842], $grouped['page_ids'], 'page prefetch ids must be deduped');
        self::assertSame([0 => 42, 1 => 42], $grouped['member_targets']);
        self::assertSame(
            [2 => ['kind' => 'validator', 'page_id' => 1842], 3 => ['kind' => 'validator', 'page_id' => 1842]],
            $grouped['page_targets']
        );

        // Both rows of each pair resolve to the SAME built card.
        $attached = self::attach(
            $items,
            $grouped,
            [42 => ['id' => 42, 'type' => 'member']],
            ['validator:1842' => ['id' => 1842, 'type' => 'validator']]
        );

        self::assertSame(['id' => 42, 'type' => 'member'], $attached[0]['card']);
        self::assertSame(['id' => 42, 'type' => 'member'], $attached[1]['card']);
        self::assertSame(['id' => 1842, 'type' => 'validator'], $attached[2]['card']);
        self::assertSame(['id' => 1842, 'type' => 'validator'], $attached[3]['card']);
    }

    public function testMixedFollowSourceRowsGroupIdentically(): void
    {
        // A page-follow row (pre-claim placeholder) and a peepso row
        // pointing at the same page must produce the same target shape —
        // follow_source is a DELETE-routing discriminator, not a
        // hydration input.
        $grouped = self::group([
            self::item(['follow_source' => 'page',   'card_kind' => 'validator', 'card_id' => 1842, 'page_id' => 1842]),
            self::item(['follow_source' => 'peepso', 'card_kind' => 'validator', 'card_id' => 7,    'page_id' => 1842]),
        ]);

        self::assertSame(
            [
                0 => ['kind' => 'validator', 'page_id' => 1842],
                1 => ['kind' => 'validator', 'page_id' => 1842],
            ],
            $grouped['page_targets']
        );
        self::assertSame([1842], $grouped['page_ids']);
    }

    // ──────────────────────────────────────────────────────────────
    // attachBuiltCards
    // ──────────────────────────────────────────────────────────────

    public function testUnhydratableRowKeepsItsPlaceWithNullCard(): void
    {
        $items = [
            self::item(['follow_id' => 1, 'card_kind' => 'member',    'card_id' => 42]),
            self::item(['follow_id' => 2, 'card_kind' => 'dao',       'card_id' => 43]),
            self::item(['follow_id' => 3, 'card_kind' => 'validator', 'card_id' => 7, 'page_id' => 1842]),
        ];

        // The member card builds; the validator card returns null (page
        // deleted / page-type mismatch); the dao row never had a target.
        $attached = self::attach($items, self::group($items), [42 => ['id' => 42]], []);

        self::assertCount(3, $attached, 'rows are NEVER dropped for hydration reasons');
        self::assertSame([1, 2, 3], array_column($attached, 'follow_id'), 'original order preserved');
        self::assertSame(['id' => 42], $attached[0]['card']);
        self::assertNull($attached[1]['card'], 'untargetable kind → card null, row kept');
        self::assertNull($attached[2]['card'], 'builder returned null → card null, row kept');
    }

    public function testCardIsAppendedAsTheLastKey(): void
    {
        $items   = [self::item(['card_kind' => 'member', 'card_id' => 42])];
        $before  = array_keys($items[0]);
        $attached = self::attach($items, self::group($items), [42 => ['id' => 42]], []);

        self::assertSame('card', array_key_last($attached[0]), '`card` must be the LAST key of the item');
        self::assertSame(
            array_merge($before, ['card']),
            array_keys($attached[0]),
            'identifier fields are untouched; `card` is purely additive'
        );
    }

    public function testEveryItemGetsACardKeyEvenWithNoTargetsAtAll(): void
    {
        $items = [
            self::item(['follow_id' => 1, 'card_kind' => 'dao']),
            self::item(['follow_id' => 2, 'card_kind' => 'group']),
        ];

        $attached = self::attach($items, self::group($items));

        self::assertCount(2, $attached);
        foreach ($attached as $row) {
            self::assertArrayHasKey('card', $row);
            self::assertNull($row['card']);
        }
    }

    public function testAttachOnEmptyPageReturnsEmptyList(): void
    {
        self::assertSame([], self::attach([], self::group([])));
    }

    // ──────────────────────────────────────────────────────────────
    // page_size clamp
    // ──────────────────────────────────────────────────────────────

    public function testHydratedRequestsClampToTwentyFour(): void
    {
        self::assertSame(24, WatchingService::HYDRATED_MAX_PAGE_SIZE);
        self::assertSame(24, self::clamp(50, true));
        self::assertSame(24, self::clamp(999, true));
        self::assertSame(20, self::clamp(20, true), 'a page_size under the hydrated cap passes through');
    }

    public function testIdentifierOnlyRequestsKeepTheFiftyCap(): void
    {
        self::assertSame(50, WatchingService::MAX_PAGE_SIZE);
        self::assertSame(50, self::clamp(50, false));
        self::assertSame(50, self::clamp(999, false), 'hydration cap must not leak into the identifier-only path');
        self::assertSame(20, self::clamp(20, false));
    }

    public function testClampFloorIsOne(): void
    {
        self::assertSame(1, self::clamp(0, true));
        self::assertSame(1, self::clamp(-5, false));
    }
}
