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
 * F-421: `GET /cards/search` says when a vertical could not be read.
 *
 * The nav-bar autocomplete merges three bcc-search verticals (pages,
 * groups, users). A vertical that answered an error (503 degraded, or 429
 * from the shared `search` throttle bucket) used to drop out SILENTLY, so
 * "Suggestions are unavailable" and "No matches" were the same response.
 * Still 200, because autocomplete never blocks mid-type, but now:
 *
 *   - `incomplete` is ALWAYS present: true when any vertical this request
 *     searched could not be read, false otherwise (including the under-2-
 *     character fast path, which searches nothing);
 *   - the other verticals' items still arrive;
 *   - an incomplete answer is `private, no-store`, so a browser cannot
 *     replay it for the same query after the vertical recovers. A complete
 *     one keeps `private, max-age=15`.
 *
 * The REAL handler runs. Only the three upstream answers are faked
 * (tests/Stubs/cards-search-upstream-stubs.php). Pages and users succeed
 * with no rows (so no claim or reputation read runs in a unit test) and
 * groups returns one real community row.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
#[CoversClass(CardsSearchEndpoint::class)]
final class CardsSearchIncompleteTest extends TestCase
{
    private const PAGES  = '/bcc/v1/search';
    private const GROUPS = '/bcc/v1/search/groups';
    private const USERS  = '/bcc/v1/search/users';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cards-search-upstream-stubs.php';
        $GLOBALS['__bcc_csu'] = [
            'calls'    => [],
            'upstream' => [
                self::PAGES  => ['status' => 200, 'results' => []],
                self::GROUPS => ['status' => 200, 'results' => [self::groupRow()]],
                self::USERS  => ['status' => 200, 'results' => []],
            ],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__bcc_csu']);
        parent::tearDown();
    }

    public function testAHealthySearchIsCompleteAndKeepsItsCache(): void
    {
        $res = $this->search(['q' => 'cosmos']);

        self::assertComplete($res);
        self::assertSame(['Cosmos Hall'], self::names($res));
        self::assertSame([self::PAGES, self::GROUPS, self::USERS], self::routesCalled(), 'All scope reads all three verticals');
    }

    /** @return array<string, array{string, int}> */
    public static function failingVerticals(): array
    {
        $out = [];
        foreach (['pages' => self::PAGES, 'groups' => self::GROUPS, 'users' => self::USERS] as $name => $route) {
            $out["{$name} degraded (503)"]     = [$route, 503];
            $out["{$name} rate-limited (429)"] = [$route, 429];
        }
        return $out;
    }

    #[DataProvider('failingVerticals')]
    public function testAnUnreadableVerticalMakesTheAllScopeAnswerIncomplete(string $route, int $status): void
    {
        $GLOBALS['__bcc_csu']['upstream'][$route] = ['status' => $status, 'results' => []];

        $res = $this->search(['q' => 'cosmos']);

        self::assertIncomplete($res);
        // The verticals that answered still reach the dropdown.
        self::assertSame($route === self::GROUPS ? [] : ['Cosmos Hall'], self::names($res));
    }

    /** @return array<string, array{string, string}> */
    public static function scopedKinds(): array
    {
        return [
            'kind=validator (pages)'  => ['validator', self::PAGES],
            'kind=community (groups)' => ['community', self::GROUPS],
            'kind=member (users)'     => ['member', self::USERS],
        ];
    }

    #[DataProvider('scopedKinds')]
    public function testAScopedSearchWhoseOnlyVerticalFailedIsEmptyAndIncomplete(string $kind, string $route): void
    {
        $GLOBALS['__bcc_csu']['upstream'][$route] = ['status' => 503, 'results' => []];

        $res = $this->search(['q' => 'cosmos', 'kind' => $kind]);

        self::assertIncomplete($res);
        self::assertSame([], self::names($res));
        self::assertSame([$route], self::routesCalled(), 'a scoped search reads only its own vertical');
    }

    #[DataProvider('scopedKinds')]
    public function testAHealthyScopedSearchIsComplete(string $kind, string $route): void
    {
        $res = $this->search(['q' => 'cosmos', 'kind' => $kind]);

        self::assertComplete($res);
        self::assertSame($route === self::GROUPS ? ['Cosmos Hall'] : [], self::names($res));
        self::assertSame([$route], self::routesCalled());
    }

    /** Nothing was searched, so nothing is missing. */
    public function testAShortQueryIsCompleteAndReadsNothing(): void
    {
        foreach (self::failingVerticals() as [$route, $status]) {
            $GLOBALS['__bcc_csu']['upstream'][$route] = ['status' => $status, 'results' => []];
        }

        $res = $this->search(['q' => 'c']);

        self::assertComplete($res);
        self::assertSame([], self::names($res));
        self::assertSame([], self::routesCalled());
    }

    /** @param array<string, string> $params */
    private function search(array $params): \WP_REST_Response
    {
        $request = new \WP_REST_Request('GET', '/bcc/v1/cards/search');
        foreach ($params as $key => $value) {
            $request->set_param($key, $value);
        }

        return (new CardsSearchEndpoint())->handle($request);
    }

    private static function assertComplete(\WP_REST_Response $res): void
    {
        self::assertSame(200, $res->get_status());
        self::assertSame(['items', 'incomplete'], array_keys($res->get_data()['data']), 'the flag is always present');
        self::assertFalse($res->get_data()['data']['incomplete']);
        self::assertSame(['Cache-Control' => 'private, max-age=15'], $res->get_headers());
    }

    private static function assertIncomplete(\WP_REST_Response $res): void
    {
        self::assertSame(200, $res->get_status(), 'still 200: autocomplete never blocks mid-type');
        self::assertSame(['items', 'incomplete'], array_keys($res->get_data()['data']));
        self::assertTrue($res->get_data()['data']['incomplete']);
        self::assertSame(['Cache-Control' => 'private, no-store'], $res->get_headers(), 'an incomplete answer is never replayed');
    }

    /** @return list<string> */
    private static function names(\WP_REST_Response $res): array
    {
        return array_column($res->get_data()['data']['items'], 'name');
    }

    /** @return list<string> */
    private static function routesCalled(): array
    {
        return array_column($GLOBALS['__bcc_csu']['calls'], 0);
    }

    /** @return array<string, mixed> one bcc-search /search/groups row, as in CardsSearchSuggestionBuildersTest */
    private static function groupRow(): array
    {
        return [
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
}
