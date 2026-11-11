<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Fetchers\CosmosFetcher;
use BCC\Trust\Onchain\Support\ApiRetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-63: CosmosFetcher::fetch_delegations_result() answers COMPLETE or null.
 *
 * The delegator-community revoke sweep removes a member on INELIGIBLE, and
 * the eligibility service reads `[]` — or a set with no row for the
 * validator — as INELIGIBLE, while it reads null as UNKNOWN (skip). So a
 * response this method cannot read to its end must be null:
 *
 *   - a 200 that is not a delegations response (`{}`, a proxy envelope, a
 *     wrong-typed list) used to pass `empty()` and come back as `[]`;
 *   - an unreadable item used to be dropped, undercounting the delegator;
 *   - a set cut off at a page boundary used to read as complete.
 *
 * Each of those evicted a member who never stopped delegating, and — being
 * non-null — was cached for 5 minutes and written through to the delegation
 * index. That null is neither cached nor written through is pinned in
 * DelegationEligibilityTest::testTransportFailureIsNeverCachedOrWrittenThrough;
 * that the sweep skips an UNKNOWN member and re-checks it next tick is pinned
 * in DelegatorGroupIntegrationTest (testAnLcdOutageRemovesNobody,
 * testAnOutageTickIsRetriedByTheNextTick).
 *
 * Isolation: resolver-stubs pattern, no live HTTP.
 */
#[CoversClass(CosmosFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmosFetcherDelegationsResultTest extends TestCase
{
    private const CHAIN_ID  = 8;
    private const REST      = 'https://cosmos-api.polkachu.com';
    private const DELEGATOR = 'cosmos1delegatorfixture';
    private const VALOPER_A = 'cosmosvaloper1aaaa';
    private const VALOPER_B = 'cosmosvaloper1bbbb';

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/cosmwasm-discovery-stubs.php';
        ApiRetry::reset();
        \BCC\Core\Log\Logger::reset();
        \BccTestObjectCache::reset();
        \BccTestOptionStore::reset();
    }

    private function makeFetcher(): CosmosFetcher
    {
        return new CosmosFetcher((object) [
            'id'         => self::CHAIN_ID,
            'slug'       => 'cosmos',
            'chain_type' => 'cosmos',
            'rest_url'   => self::REST,
            'decimals'   => 6,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function queueJson(array $payload, int $code = 200): void
    {
        ApiRetry::$queue[] = ['code' => $code, 'body' => (string) json_encode($payload)];
    }

    /** @return array<string, mixed> */
    private static function item(string $valoper, string $amount): array
    {
        return [
            'delegation' => ['delegator_address' => self::DELEGATOR, 'validator_address' => $valoper, 'shares' => $amount . '.000000000000000000'],
            'balance'    => ['denom' => 'uatom', 'amount' => $amount],
        ];
    }

    /**
     * @param list<mixed> $items
     * @return array<string, mixed>
     */
    private static function page(array $items, ?string $nextKey = null): array
    {
        return [
            'delegation_responses' => $items,
            'pagination'           => ['next_key' => $nextKey, 'total' => '0'],
        ];
    }

    private function fetch(): ?array
    {
        return $this->makeFetcher()->fetch_delegations_result(self::DELEGATOR);
    }

    // ── Definite answers are unchanged ──────────────────────────────────

    public function testAWellFormedSetComesBackAsRows(): void
    {
        $this->queueJson(self::page([self::item(self::VALOPER_A, '2500000'), self::item(self::VALOPER_B, '1000000')]));

        self::assertSame([
            ['validator_address' => self::VALOPER_A, 'shares' => '2500000.000000000000000000', 'amount' => 2.5],
            ['validator_address' => self::VALOPER_B, 'shares' => '1000000.000000000000000000', 'amount' => 1.0],
        ], $this->fetch());
        self::assertCount(1, ApiRetry::$calls);
        self::assertStringContainsString('pagination.limit=500', ApiRetry::$calls[0]['url']);
        self::assertStringNotContainsString('pagination.key', ApiRetry::$calls[0]['url']);
    }

    public function testAnEmptyListWithNoNextKeyIsARealNonDelegator(): void
    {
        $this->queueJson(self::page([]));

        self::assertSame([], $this->fetch());
    }

    public function testAnUnreadableAmountKeepsTheRowWithANullAmount(): void
    {
        $item = self::item(self::VALOPER_A, '2500000');
        $item['balance'] = ['denom' => 'uatom', 'amount' => 12];
        $this->queueJson(self::page([$item]));

        $rows = $this->fetch();

        self::assertIsArray($rows);
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['amount']);
    }

    public function testATransportFailureIsNull(): void
    {
        ApiRetry::$queue[] = new \WP_Error('down');

        self::assertNull($this->fetch());
    }

    // ── A response that is not a readable delegations page is UNKNOWN ──

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function unreadablePages(): array
    {
        return [
            'empty object'                    => [[]],
            'error-shaped 200 envelope'       => [['code' => 13, 'message' => 'internal', 'details' => []]],
            'list key is a string'            => [['delegation_responses' => 'none']],
            'list key is null'                => [['delegation_responses' => null]],
            'list key is a map, not a list'   => [['delegation_responses' => ['x' => self::item(self::VALOPER_A, '1')]]],
            'an item is not an array'         => [self::page([self::item(self::VALOPER_A, '1'), 'garbage'])],
            'an item has no delegation'       => [self::page([self::item(self::VALOPER_A, '1'), ['balance' => ['amount' => '1']]])],
            'an item has no validator'        => [self::page([['delegation' => ['shares' => '1'], 'balance' => ['amount' => '1']]])],
            'an item has an empty validator'  => [self::page([['delegation' => ['validator_address' => ''], 'balance' => ['amount' => '1']]])],
            'an item has a numeric validator' => [self::page([['delegation' => ['validator_address' => 7], 'balance' => ['amount' => '1']]])],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('unreadablePages')]
    public function testAnUnreadablePageIsNullNeverAnEmptyOrPartialSet(array $payload): void
    {
        $this->queueJson($payload);

        self::assertNull($this->fetch());
    }

    // ── Pagination: complete, or UNKNOWN ────────────────────────────────

    public function testANextKeyIsFollowedAndThePagesAreJoined(): void
    {
        $this->queueJson(self::page([self::item(self::VALOPER_A, '1000000')], 'KEY/page+2='));
        $this->queueJson(self::page([self::item(self::VALOPER_B, '3000000')]));

        $rows = $this->fetch();

        self::assertIsArray($rows);
        self::assertSame([self::VALOPER_A, self::VALOPER_B], array_column($rows, 'validator_address'));
        self::assertCount(2, ApiRetry::$calls);
        // The second request carries the first page's key, URL-encoded.
        self::assertStringContainsString('pagination.key=' . rawurlencode('KEY/page+2='), ApiRetry::$calls[1]['url']);
        self::assertStringContainsString('pagination.limit=500', ApiRetry::$calls[1]['url']);
    }

    public function testAnEmptyStringNextKeyEndsTheWalk(): void
    {
        $this->queueJson(self::page([self::item(self::VALOPER_A, '1000000')], ''));

        self::assertCount(1, (array) $this->fetch());
        self::assertCount(1, ApiRetry::$calls);
    }

    public function testAFailedLaterPageIsNullNotTheEarlierRows(): void
    {
        $this->queueJson(self::page([self::item(self::VALOPER_A, '1000000')], 'k2'));
        ApiRetry::$queue[] = new \WP_Error('down');

        self::assertNull($this->fetch());
        self::assertCount(2, ApiRetry::$calls);
    }

    public function testAnUnreadableLaterPageIsNullNotTheEarlierRows(): void
    {
        $this->queueJson(self::page([self::item(self::VALOPER_A, '1000000')], 'k2'));
        $this->queueJson([]);

        self::assertNull($this->fetch());
    }

    public function testASetStillPagingAtTheCapIsNullNotTheTruncatedSet(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->queueJson(self::page([self::item(self::VALOPER_A, '1000000')], 'k' . ($i + 2)));
        }

        self::assertNull($this->fetch());
        // Exactly the cap was requested — the walk is bounded.
        self::assertCount(5, ApiRetry::$calls);
    }

    public function testASetEndingExactlyOnTheLastAllowedPageIsComplete(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->queueJson(self::page([self::item(self::VALOPER_A, '1000000')], 'k' . ($i + 2)));
        }
        $this->queueJson(self::page([self::item(self::VALOPER_B, '1000000')]));

        $rows = $this->fetch();

        self::assertIsArray($rows);
        self::assertCount(5, $rows);
        self::assertCount(5, ApiRetry::$calls);
    }

    // ── The legacy wrapper keeps its shape ──────────────────────────────

    public function testTheLegacyWrapperStillCollapsesUnknownToEmpty(): void
    {
        $this->queueJson([]);

        self::assertSame([], $this->makeFetcher()->fetch_delegations(self::DELEGATOR));
    }
}
