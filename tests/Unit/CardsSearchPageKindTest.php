<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\CardsSearchEndpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-592: `GET /cards/search` page kinds return the pages of that kind.
 *
 * ## The defect
 *
 * A page-kind scope (`kind=validator|project|creator`) was translated to the
 * stored page type and sent to bcc-search as `type`. bcc-search reads `type`
 * as a PeepSo CATEGORY slug; no category is named after a page type, and an
 * unknown category answers with nothing. So every page-scoped suggestion
 * list was empty. It now sends `kind`, which bcc-search filters on the
 * page's stored type (F-589).
 *
 * And each suggestion's card_kind was read from bcc-search's
 * `category_slug` (that same PeepSo category) first, then from the stored
 * type WITHOUT the "no type is a project" default. So an untyped project
 * page vanished, and a category named after a page type could link a page
 * under a kind its own card route rejects. card_kind now comes only from
 * the stored type, as PageTypeMap and the card route read it.
 *
 * ## Harness
 *
 * The REAL handler runs. bcc-search's pages vertical is a MODEL of its
 * rules, not a canned answer (see tests/Stubs/cards-search-page-kind-stubs.php
 * for why). The groups and users verticals find nothing.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(CardsSearchEndpoint::class)]
final class CardsSearchPageKindTest extends TestCase
{
    private const PAGES = '/bcc/v1/search';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__bcc_cspk'] = [
            'calls' => [],
            'gone'  => [],
            // Every page matches the query; in bcc-search's ranked order.
            'pages' => [
                11 => ['name' => 'Cosmos Validator', 'type' => 'validator', 'category' => 'vaildators'],
                12 => ['name' => 'Cosmos Builder',   'type' => 'builder',   'category' => 'builders'],
                13 => ['name' => 'Cosmos NFTs',      'type' => 'nft',       'category' => 'nft-creators'],
                // A legacy page with no stored type: a project.
                14 => ['name' => 'Cosmos Legacy',    'type' => '',          'category' => null],
                // A stored type outside the card kinds: never a suggestion.
                15 => ['name' => 'Cosmos DAO',       'type' => 'dao',       'category' => 'daos'],
                // Its PeepSo category is named after a page type; its
                // stored type says otherwise. The stored type wins.
                16 => ['name' => 'Cosmos Art',       'type' => 'nft',       'category' => 'builder'],
            ],
        ];
        require_once __DIR__ . '/../Stubs/cards-search-page-kind-stubs.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_cspk']);
        parent::tearDown();
    }

    /** @return array<string, array{string, list<string>}> */
    public static function pageKinds(): array
    {
        return [
            'validator' => ['validator', ['Cosmos Validator']],
            'project'   => ['project', ['Cosmos Builder', 'Cosmos Legacy']],
            'creator'   => ['creator', ['Cosmos NFTs', 'Cosmos Art']],
        ];
    }

    /** ⭐ The test that would have caught it: a page-kind scope finds that kind's pages. */
    #[DataProvider('pageKinds')]
    public function testAPageKindScopeSuggestsThatKindsPages(string $kind, array $names): void
    {
        $items = $this->items(['q' => 'cosmos', 'kind' => $kind]);

        self::assertSame($names, array_column($items, 'name'));
        self::assertSame(array_fill(0, count($names), $kind), array_column($items, 'card_kind'));
        foreach ($items as $item) {
            self::assertSame(self::prefix($kind) . 'page-' . $item['id'], $item['href']);
        }
    }

    #[DataProvider('pageKinds')]
    public function testTheScopeReachesBccSearchAsKindNotAsACategory(string $kind): void
    {
        $this->items(['q' => 'cosmos', 'kind' => $kind]);

        $calls = $GLOBALS['__bcc_cspk']['calls'];
        self::assertSame([self::PAGES], array_column($calls, 0), 'a scoped search reads only the pages vertical');
        self::assertSame(['q' => 'cosmos', 'kind' => $kind], $calls[0][1]);
    }

    public function testTheAllScopeKindsEachPageByItsStoredType(): void
    {
        $items = $this->items(['q' => 'cosmos']);

        self::assertSame(
            [
                ['Cosmos Validator', 'validator', '/v/page-11'],
                ['Cosmos Builder',   'project',   '/p/page-12'],
                ['Cosmos NFTs',      'creator',   '/c/page-13'],
                ['Cosmos Legacy',    'project',   '/p/page-14'],
                ['Cosmos Art',       'creator',   '/c/page-16'],
            ],
            array_map(static fn (array $i): array => [$i['name'], $i['card_kind'], $i['href']], $items),
            'the dao page has no card kind, so no suggestion'
        );
        self::assertArrayNotHasKey('type', $GLOBALS['__bcc_cspk']['calls'][0][1]);
        self::assertArrayNotHasKey('kind', $GLOBALS['__bcc_cspk']['calls'][0][1]);
    }

    public function testAPageGoneSinceTheSearchIsDropped(): void
    {
        $GLOBALS['__bcc_cspk']['gone'] = [14];

        self::assertSame(['Cosmos Builder'], array_column($this->items(['q' => 'cosmos', 'kind' => 'project']), 'name'));
    }

    /**
     * @param array<string, string> $params
     * @return list<array<string, mixed>>
     */
    private function items(array $params): array
    {
        $request = new \WP_REST_Request('GET', '/bcc/v1/cards/search');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        $res = (new CardsSearchEndpoint())->handle($request);

        self::assertSame(200, $res->get_status());
        $data = $res->get_data()['data'];
        self::assertFalse($data['incomplete'], 'every vertical answered');
        return $data['items'];
    }

    private static function prefix(string $kind): string
    {
        return ['validator' => '/v/', 'project' => '/p/', 'creator' => '/c/'][$kind];
    }
}
