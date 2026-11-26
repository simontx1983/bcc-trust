<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\REST\AdminValidatorReadEndpoint;
use BCC\Trust\Core\Support\BearerAuth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[CoversClass(AdminValidatorReadEndpoint::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AdminValidatorReadEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../Stubs/admin-validator-read-endpoint-stubs.php';
        require_once __DIR__ . '/../../app/Domain/Core/REST/AdminValidatorReadEndpoint.php';
        $GLOBALS['validator_read_endpoint'] = ['actor' => 7, 'admin' => true, 'routes' => [], 'calls' => [], 'rate_calls' => [], 'rate_allow' => true, 'fail' => false,
            'results' => ['directory' => ['items' => []], 'relationships' => ['owner' => null], 'history' => ['items' => []], 'health' => ['chains' => []], 'searchUsers' => ['users' => []]]];
    }

    private function request(array $query = [], array $url = [], mixed $json = []): \WP_REST_Request
    {
        $request = new \WP_REST_Request();
        $request->query = $query;
        $request->url = $url;
        $request->json = $json;
        return $request;
    }

    private function assertPrivate(\WP_REST_Response $response): void
    {
        self::assertSame('private, no-store', $response->get_headers()['Cache-Control']);
    }

    private function assertError(\WP_REST_Response $response, int $status, string $code): void
    {
        self::assertSame($status, $response->get_status());
        self::assertSame($code, $response->get_data()['error']['code']);
        $this->assertPrivate($response);
    }

    public function testRoutesRegisterAdminGateAndOnlySearchUsesPost(): void
    {
        AdminValidatorReadEndpoint::register();
        self::assertCount(6, $GLOBALS['validator_read_endpoint']['routes']);
        foreach ($GLOBALS['validator_read_endpoint']['routes'] as $route => $options) {
            self::assertSame([BearerAuth::class, 'requireAdmin'], $options['permission_callback']);
            self::assertSame(str_ends_with($route, '/admin/users/search') ? 'POST' : 'GET', $options['methods']);
        }
    }

    public function testEveryHandlerGatesBeforeAnyReadOrRateLimit(): void
    {
        $endpoint = new AdminValidatorReadEndpoint();
        foreach (['directory', 'relationships', 'history', 'health', 'searchUsers'] as $handler) {
            $GLOBALS['validator_read_endpoint']['actor'] = 0;
            $this->assertError($endpoint->$handler($this->request()), 401, 'bcc_unauthorized');
            $GLOBALS['validator_read_endpoint']['actor'] = 7;
            $GLOBALS['validator_read_endpoint']['admin'] = false;
            $this->assertError($endpoint->$handler($this->request()), 403, 'bcc_forbidden');
        }
        self::assertSame([], $GLOBALS['validator_read_endpoint']['calls']);
        self::assertSame([], $GLOBALS['validator_read_endpoint']['rate_calls']);
    }

    public function testSearchReadsJsonOnlyAndNeverEchoesQuery(): void
    {
        $endpoint = new AdminValidatorReadEndpoint();
        $response = $endpoint->searchUsers($this->request(['q' => 'query credential'], [], ['q' => '  json credential  ']));
        self::assertSame(200, $response->get_status());
        $this->assertPrivate($response);
        self::assertSame([['searchUsers', ['json credential']]], $GLOBALS['validator_read_endpoint']['calls']);
        self::assertStringNotContainsString('credential', json_encode($response->get_data()));
        $this->assertError($endpoint->searchUsers($this->request(['q' => 'query credential'])), 422, 'bcc_invalid_request');
        self::assertCount(1, $GLOBALS['validator_read_endpoint']['calls']);
    }

    public function testDirectoryRejectsInvalidPaginationTypesOverflowAndFilters(): void
    {
        $endpoint = new AdminValidatorReadEndpoint();
        foreach (['0', '-1', '01', '1.0', '1e2', str_repeat('9', 40), true, 1.5, []] as $value) {
            foreach (['limit', 'cursor'] as $field) {
                $this->assertError($endpoint->directory($this->request([$field => $value])), 422, 'bcc_invalid_request');
            }
        }
        foreach ([['limit' => '101'], ['state' => 'other'], ['chain' => '../path'], ['q' => []], ['q' => str_repeat('a', 201)], ['q' => "\xff"]] as $query) {
            $this->assertError($endpoint->directory($this->request($query)), 422, 'bcc_invalid_request');
        }
        self::assertSame([], $GLOBALS['validator_read_endpoint']['calls']);
    }

    public function testDirectoryUsesBoundedParsedQueryAndPrivateResponse(): void
    {
        $response = (new AdminValidatorReadEndpoint())->directory($this->request(['limit' => '100', 'cursor' => '23', 'chain' => 'cosmos-hub', 'state' => 'staff_verified', 'q' => '  validator  ']));
        self::assertSame(200, $response->get_status());
        self::assertSame([['directory', ['cosmos-hub', 'staff_verified', 'validator', 23, 100]]], $GLOBALS['validator_read_endpoint']['calls']);
        $this->assertPrivate($response);
    }

    public function testRelationshipsUsesRouteIdAndReturns404ForMissingPage(): void
    {
        $endpoint = new AdminValidatorReadEndpoint();
        $response = $endpoint->relationships($this->request(['pageId' => '99'], ['pageId' => '10']));
        self::assertSame(200, $response->get_status());
        self::assertSame([['relationships', [10]]], $GLOBALS['validator_read_endpoint']['calls']);
        $this->assertPrivate($response);
        $GLOBALS['validator_read_endpoint']['results']['relationships'] = null;
        $this->assertError($endpoint->relationships($this->request([], ['pageId' => '10'])), 404, 'bcc_not_found');
        $this->assertError($endpoint->relationships($this->request([], ['pageId' => str_repeat('9', 40)])), 422, 'bcc_invalid_request');
    }

    public function testHistoryRoutePageTakesPrecedenceAndGlobalFiltersRemainOptional(): void
    {
        $endpoint = new AdminValidatorReadEndpoint();
        $response = $endpoint->history($this->request(['page_id' => '99', 'user_id' => '22', 'cursor' => '88', 'limit' => '20'], ['pageId' => '10']));
        self::assertSame(200, $response->get_status());
        $this->assertPrivate($response);
        self::assertSame([['relationships', [10]], ['history', [10, 22, 88, 20]]], $GLOBALS['validator_read_endpoint']['calls']);
        $GLOBALS['validator_read_endpoint']['calls'] = [];
        $endpoint->history($this->request());
        self::assertSame([['history', [null, null, null, 25]]], $GLOBALS['validator_read_endpoint']['calls']);
        $GLOBALS['validator_read_endpoint']['results']['relationships'] = null;
        $this->assertError($endpoint->history($this->request([], ['pageId' => '10'])), 404, 'bcc_not_found');
    }

    public function testHistoryRejectsInvalidOptionalIdsAndPagination(): void
    {
        $endpoint = new AdminValidatorReadEndpoint();
        foreach (['page_id', 'user_id', 'cursor', 'limit'] as $field) {
            foreach (['0', '-1', '01', true, [], str_repeat('9', 40)] as $value) {
                $this->assertError($endpoint->history($this->request([$field => $value])), 422, 'bcc_invalid_request');
            }
        }
        self::assertSame([], $GLOBALS['validator_read_endpoint']['calls']);
    }

    public function testSearchRateLimitStopsServiceAndIncludesRetryAfter(): void
    {
        $GLOBALS['validator_read_endpoint']['rate_allow'] = false;
        $response = (new AdminValidatorReadEndpoint())->searchUsers($this->request([], [], ['q' => 'member@example.test']));
        $this->assertError($response, 429, 'bcc_rate_limited');
        self::assertSame('60', $response->get_headers()['Retry-After']);
        self::assertSame([['validator_admin_user_search:7', 30, 60]], $GLOBALS['validator_read_endpoint']['rate_calls']);
        self::assertSame([], $GLOBALS['validator_read_endpoint']['calls']);
    }

    public function testSearchRejectsMalformedShortOrOversizedJsonWithoutRateOrRead(): void
    {
        $endpoint = new AdminValidatorReadEndpoint();
        foreach ([null, [], ['q' => '   '], ['q' => []], ['q' => "\xff\xff"], ['q' => str_repeat('a', 201)]] as $json) {
            $this->assertError($endpoint->searchUsers($this->request([], [], $json)), 422, 'bcc_invalid_request');
        }
        self::assertSame([], $GLOBALS['validator_read_endpoint']['calls']);
        self::assertSame([], $GLOBALS['validator_read_endpoint']['rate_calls']);
    }

    public function testSingleCharacterSearchMatchesExistingPickerBehavior(): void
    {
        $response = (new AdminValidatorReadEndpoint())->searchUsers($this->request([], [], ['q' => ' a ']));
        self::assertSame(200, $response->get_status());
        self::assertSame([['searchUsers', ['a']]], $GLOBALS['validator_read_endpoint']['calls']);
        $this->assertPrivate($response);
    }

    public function testUnavailableReadsStayPrivateAndDoNotExposeDiagnostics(): void
    {
        $GLOBALS['validator_read_endpoint']['fail'] = true;
        $endpoint = new AdminValidatorReadEndpoint();
        foreach (['directory', 'relationships', 'history', 'health', 'searchUsers'] as $handler) {
            $response = $endpoint->$handler($this->request([], ['pageId' => '10'], ['q' => 'member']));
            $this->assertError($response, 503, 'bcc_unavailable');
            self::assertStringNotContainsString('private read detail', json_encode($response->get_data()));
        }
    }

    public function testMissingHealthSnapshotIsUnavailableAndDoesNotClaimHealthy(): void
    {
        $endpoint = new AdminValidatorReadEndpoint();
        $GLOBALS['validator_read_endpoint']['results']['health'] = null;
        $this->assertError($endpoint->health($this->request()), 503, 'bcc_unavailable');
        $GLOBALS['validator_read_endpoint']['results']['health'] = ['chains' => [['chain' => 'cosmos-hub', 'ok' => false]]];
        $response = $endpoint->health($this->request());
        self::assertSame(200, $response->get_status());
        self::assertFalse($response->get_data()['data']['chains'][0]['ok']);
        $this->assertPrivate($response);
    }
}
