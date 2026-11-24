<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\CardsListEndpoint;
use BCC\Trust\Core\Support\PageTypeMap;
use BCC\Trust\Tests\Support\GateOrder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Structure A: GET /cards `pagination.total`. The real-engine half (total
 * and rows are one set) is CardsListTotalParityIntegrationTest.
 */
#[CoversClass(CardsListEndpoint::class)]
final class CardsListTotalTest extends TestCase
{
    /** Every page type a list with no `kind` asks for renders as a card, and every card kind is asked for. */
    public function testTheListedPageTypesAreExactlyTheCardKinds(): void
    {
        $types = CardsListEndpoint::cardPageTypes();

        foreach ($types as $type) {
            self::assertNotNull(PageTypeMap::kindForPageType($type), "{$type} must render as a card");
        }
        $kinds = array_map(static fn (string $t): ?string => PageTypeMap::kindForPageType($t), $types);
        sort($kinds);
        $expected = array_keys(PageTypeMap::KIND_TO_PAGE_TYPE);
        sort($expected);
        self::assertSame($expected, $kinds);
        self::assertNotContains('dao', $types);
    }

    /** A list with no kind asks for the card page types; a kind asks for its own one. */
    public function testTheDiscoveryTypesFollowTheKind(): void
    {
        self::assertSame(CardsListEndpoint::cardPageTypes(), CardsListEndpoint::discoveryTypes(''));
        self::assertSame(['validator'], CardsListEndpoint::discoveryTypes('validator'));
        self::assertSame(['builder'], CardsListEndpoint::discoveryTypes('project'));
        self::assertSame(['nft'], CardsListEndpoint::discoveryTypes('creator'));
    }

    /**
     * Wiring (source-level, comments removed): the route refuses an unknown
     * kind, then takes its discovery types from discoveryTypes(), hands them
     * to discovery, and builds its pagination with pagination(). A route that
     * built its own "all types" list or its own pagination would drop out of
     * the order below.
     */
    public function testTheRouteUsesTheTypesAndThePaginationItIsTestedWith(): void
    {
        $body = GateOrder::body(GateOrder::code('app/Domain/Core/REST/CardsListEndpoint.php'), 'handle');

        GateOrder::assertOrder($body, [
            "'kind must be validator, project, or creator.'",
            '$types = self::discoveryTypes($kindParam);',
            "'types'              => \$types,",
            "'pagination' => self::pagination(\$page, \$perPage, \$discoveryResult),",
        ], 'GET /cards: kind check, discovery types, discovery, pagination');
        self::assertSame(1, substr_count($body, '$types ='), 'one source for the discovery types');
    }

    public function testThePaginationCarriesDiscoverysTotal(): void
    {
        self::assertSame(
            ['page' => 2, 'per_page' => 24, 'total' => 57, 'total_pages' => 3, 'has_more' => true],
            CardsListEndpoint::pagination(2, 24, ['total' => 57, 'pages' => 3])
        );
    }

    /** A result without a count (or a nonsense one) reads as none, never a negative. */
    public function testAMissingOrNegativeTotalIsZero(): void
    {
        self::assertSame(0, CardsListEndpoint::pagination(1, 24, ['pages' => 1])['total']);
        self::assertSame(0, CardsListEndpoint::pagination(1, 24, ['total' => -4, 'pages' => 1])['total']);
    }

    /** has_more still stops at the page ceiling, whatever the total says. */
    public function testHasMoreStopsAtThePageCeiling(): void
    {
        self::assertFalse(CardsListEndpoint::pagination(20, 24, ['total' => 9999, 'pages' => 417])['has_more']);
        self::assertSame(9999, CardsListEndpoint::pagination(20, 24, ['total' => 9999, 'pages' => 417])['total']);
    }
}
