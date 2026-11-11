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
 * F-62: the Cosmos bonded-validator set is COMPLETE or EMPTY, never truncated.
 *
 * getBondedValidators() used to make one request at pagination.limit=500 and
 * never read next_key, so a larger set reached the index run as its first page
 * — an ordinary, clean-looking fetch that was upserted, could become the
 * baseline, and fed the page-mint allowlist. The index run's count heuristics
 * cannot see a page-size cap.
 *
 * It now follows next_key (pagination.key) until the set ends — at most 2,000
 * validators (the cap is on VALIDATORS, so a gateway that clamps the page size
 * still completes) in at most 40 requests — parses each page strictly, and
 * returns [] when the set cannot be read to its end. That [] is
 * the index run's existing "empty → partial" signal; that the partial path
 * skips every destructive step is pinned in
 * IndexRunPartialSkipsDestructiveStepsTest.
 *
 * Driven through the public fetch_all_validators(). Isolation: resolver-stubs
 * pattern, no live HTTP; separate processes because the bonded set is cached
 * in a static for the life of the process.
 */
#[CoversClass(CosmosFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class CosmosFetcherBondedValidatorsTest extends TestCase
{
    private const CHAIN_ID = 8;
    private const REST     = 'https://cosmos-api.polkachu.com';

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
    private function queueJson(array $payload): void
    {
        ApiRetry::$queue[] = ['code' => 200, 'body' => (string) json_encode($payload)];
    }

    /** @return array<string, mixed> */
    private static function val(string $suffix, string $tokens): array
    {
        return [
            'operator_address' => 'cosmosvaloper1' . $suffix,
            'jailed'           => false,
            'status'           => 'BOND_STATUS_BONDED',
            'tokens'           => $tokens,
            'description'      => ['moniker' => 'v-' . $suffix],
            'commission'       => ['commission_rates' => ['rate' => '0.050000000000000000']],
        ];
    }

    /**
     * @param list<mixed> $items
     * @return array<string, mixed>
     */
    private static function page(array $items, ?string $nextKey = null): array
    {
        return ['validators' => $items, 'pagination' => ['next_key' => $nextKey, 'total' => '0']];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private static function ranks(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['operator_address']] = (int) $r['voting_power_rank'];
        }
        return $out;
    }

    // ── Complete sets ───────────────────────────────────────────────────

    public function testASinglePageIsSortedByTokensAndRanked(): void
    {
        $this->queueJson(self::page([self::val('aaa', '1000'), self::val('bbb', '9000'), self::val('ccc', '5000')]));

        $rows = $this->makeFetcher()->fetch_all_validators();

        self::assertSame(
            ['cosmosvaloper1bbb' => 1, 'cosmosvaloper1ccc' => 2, 'cosmosvaloper1aaa' => 3],
            self::ranks($rows)
        );
        self::assertCount(1, ApiRetry::$calls);
        self::assertStringContainsString('status=BOND_STATUS_BONDED', ApiRetry::$calls[0]['url']);
        self::assertStringContainsString('pagination.limit=500', ApiRetry::$calls[0]['url']);
        self::assertStringNotContainsString('pagination.key', ApiRetry::$calls[0]['url']);
    }

    public function testPagesAreJoinedAndRankedAcrossTheWholeSet(): void
    {
        $this->queueJson(self::page([self::val('aaa', '1000'), self::val('bbb', '3000')], 'KEY/2+=='));
        $this->queueJson(self::page([self::val('ccc', '2000'), self::val('ddd', '9000')]));

        $rows = $this->makeFetcher()->fetch_all_validators();

        // The page-two validator with the most tokens ranks FIRST: the sort runs
        // over the joined set, not per page.
        self::assertSame(
            ['cosmosvaloper1ddd' => 1, 'cosmosvaloper1bbb' => 2, 'cosmosvaloper1ccc' => 3, 'cosmosvaloper1aaa' => 4],
            self::ranks($rows)
        );
        self::assertCount(2, ApiRetry::$calls);
        self::assertStringContainsString('pagination.key=' . rawurlencode('KEY/2+=='), ApiRetry::$calls[1]['url']);
        self::assertStringContainsString('status=BOND_STATUS_BONDED', ApiRetry::$calls[1]['url']);
    }

    public function testAFourPageSetIsComplete(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->queueJson(self::page([self::val('p' . $i, '100' . $i)], 'k' . ($i + 2)));
        }
        $this->queueJson(self::page([self::val('last', '1')]));

        self::assertCount(4, $this->makeFetcher()->fetch_all_validators());
        self::assertCount(4, ApiRetry::$calls);
    }

    public function testAnUnreadableTokensAmountSortsAsZeroInsteadOfThrowing(): void
    {
        // bccomp() throws a ValueError on a non-numeric operand, and the index
        // run catches \Exception, which an Error is not: one bad amount used to
        // be able to abort the whole run.
        $bad = self::val('bad', '1000');
        $bad['tokens'] = '1e9';
        $missing = self::val('none', '1000');
        unset($missing['tokens']);
        $this->queueJson(self::page([$bad, self::val('aaa', '5'), $missing, self::val('bbb', '9')]));

        $ranks = self::ranks($this->makeFetcher()->fetch_all_validators());

        self::assertSame(1, $ranks['cosmosvaloper1bbb']);
        self::assertSame(2, $ranks['cosmosvaloper1aaa']);
        self::assertGreaterThan(2, $ranks['cosmosvaloper1bad']);
        self::assertGreaterThan(2, $ranks['cosmosvaloper1none']);
    }

    public function testAnEmptyBondedListIsEmpty(): void
    {
        $this->queueJson(self::page([]));

        self::assertSame([], $this->makeFetcher()->fetch_all_validators());
    }

    public function testACompleteSetIsCachedForTheProcess(): void
    {
        $this->queueJson(self::page([self::val('aaa', '1000')]));

        $fetcher = $this->makeFetcher();
        self::assertCount(1, $fetcher->fetch_all_validators());
        self::assertCount(1, $fetcher->fetch_all_validators());
        self::assertCount(1, ApiRetry::$calls, 'served from the process cache');
    }

    // ── Incomplete walks are EMPTY, never truncated ─────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private static function vals(string $prefix, int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = self::val($prefix . $i, (string) (1000 + $i));
        }
        return $out;
    }

    public function testAGatewayThatClampsPagesStillYieldsTheWholeSet(): void
    {
        // Review on #408: a page cap of 4 meant a gateway clamping
        // pagination.limit to 100 left any chain over 400 validators partial
        // for ever. The cap is on VALIDATORS now, so 6 clamped pages of 100
        // are a complete set of 600.
        for ($i = 0; $i < 5; $i++) {
            $this->queueJson(self::page(self::vals('p' . $i . 'x', 100), 'k' . ($i + 2)));
        }
        $this->queueJson(self::page(self::vals('last', 100)));

        self::assertCount(600, $this->makeFetcher()->fetch_all_validators());
        self::assertCount(6, ApiRetry::$calls);

        $clampLogs = array_values(array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn (array $l): bool => str_contains($l['message'], 'clamps bonded-validator pages')
        ));
        self::assertCount(1, $clampLogs, 'logged once, on the first short page');
        self::assertStringContainsString('asked for 500, got 100', $clampLogs[0]['message']);
    }

    public function testASetOfExactlyTheValidatorCapIsComplete(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->queueJson(self::page(self::vals('p' . $i . 'x', 500), 'k' . ($i + 2)));
        }
        $this->queueJson(self::page(self::vals('last', 500)));

        self::assertCount(2000, $this->makeFetcher()->fetch_all_validators());
    }

    public function testASetPastTheValidatorCapIsEmptyNotTruncated(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->queueJson(self::page(self::vals('p' . $i . 'x', 500), 'k' . ($i + 2)));
        }
        $this->queueJson(self::page(self::vals('over', 1)));

        self::assertSame([], $this->makeFetcher()->fetch_all_validators());
        self::assertCount(5, ApiRetry::$calls, 'stops as soon as the cap is passed');
    }

    public function testAWalkStillPagingAtTheRequestCeilingIsEmptyNotTruncated(): void
    {
        // One validator a page for ever: the request ceiling, not the
        // validator cap, is what ends it.
        for ($i = 0; $i < 41; $i++) {
            $this->queueJson(self::page([self::val('p' . $i, '1000')], 'k' . ($i + 2)));
        }

        self::assertSame([], $this->makeFetcher()->fetch_all_validators());
        self::assertCount(40, ApiRetry::$calls, 'the walk is bounded by the request ceiling');
    }

    public function testAFailedLaterPageIsEmptyNotTheEarlierPages(): void
    {
        $this->queueJson(self::page([self::val('aaa', '1000')], 'k2'));
        ApiRetry::$queue[] = new \WP_Error('down');

        self::assertSame([], $this->makeFetcher()->fetch_all_validators());
        self::assertCount(2, ApiRetry::$calls);
    }

    public function testAnUnreadableLaterPageIsEmptyNotTheEarlierPages(): void
    {
        $this->queueJson(self::page([self::val('aaa', '1000')], 'k2'));
        $this->queueJson([]);

        self::assertSame([], $this->makeFetcher()->fetch_all_validators());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function unreadablePages(): array
    {
        return [
            'empty object'                 => [[]],
            'validators is a string'       => [['validators' => 'none']],
            'validators is null'           => [['validators' => null]],
            'validators is a map'          => [['validators' => ['x' => self::val('aaa', '1')]]],
            'an item is not an array'      => [self::page([self::val('aaa', '1'), 'garbage'])],
            'an item has no operator'      => [self::page([self::val('aaa', '1'), ['tokens' => '5']])],
            'an item has an empty operator' => [self::page([['operator_address' => '', 'tokens' => '5']])],
            'an item has a numeric operator' => [self::page([['operator_address' => 7, 'tokens' => '5']])],
        ];
    }

    /** @param array<string, mixed> $payload */
    #[DataProvider('unreadablePages')]
    public function testAnUnreadablePageIsEmptyNeverAPartialSet(array $payload): void
    {
        $this->queueJson($payload);

        self::assertSame([], $this->makeFetcher()->fetch_all_validators());
    }

    public function testAnIncompleteWalkIsCachedAsEmptySoLaterCallersDoNotReWalk(): void
    {
        // Every later bonded lookup in the process reads [] safely
        // (findInBondedCache falls back to the single-validator call; rank is
        // unknown), so re-walking up to 4 pages per lookup against a failing
        // provider would only amplify the failure.
        $this->queueJson(self::page([self::val('aaa', '1000')], 'k2'));
        ApiRetry::$queue[] = new \WP_Error('down');
        $this->queueJson(self::page([self::val('aaa', '1000')]));

        $fetcher = $this->makeFetcher();
        self::assertSame([], $fetcher->fetch_all_validators());
        self::assertSame([], $fetcher->fetch_all_validators());
        self::assertCount(2, ApiRetry::$calls, 'no second walk in the same process');
    }
}
