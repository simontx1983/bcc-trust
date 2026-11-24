<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Core\Services\CardViewService;
use BCC\Trust\Onchain\REST\ValidatorGroupsEndpoint;
use BCC\Trust\Onchain\Support\OnchainCircuitBreaker;
use BCC\Trust\Onchain\Support\ValidatorStatusProjection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-584: a validator on a chain BCC has not heard from in 72 hours shows
 * status `unknown`, never the last value its provider reported.
 *
 * Driven through the REAL OnchainCircuitBreaker (breaker-real-stubs: the
 * options, transient and object-cache stores), so "stale" here is exactly
 * what the admin "Chain data is stale" notice reads.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class ValidatorStatusProjectionTest extends TestCase
{
    private const CHAIN = 5;

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../Stubs/breaker-real-stubs.php';
        \BccBreakerStore::reset();
        ValidatorStatusProjection::reset();
    }

    /** The chain's last successful provider call was $secondsAgo ago. */
    private static function heardFrom(int $chainId, int $secondsAgo): void
    {
        update_option('bcc_onchain_last_success_' . $chainId, time() - $secondsAgo, false);
    }

    public function testTheWindowIsSeventyTwoHours(): void
    {
        self::assertSame(72 * 3600, ValidatorStatusProjection::STALE_AFTER);
        self::assertSame('unknown', ValidatorStatusProjection::UNKNOWN);
    }

    /** @return iterable<string, array{0: string}> */
    public static function storedStatuses(): iterable
    {
        yield 'active'   => ['active'];
        yield 'jailed'   => ['jailed'];
        yield 'inactive' => ['inactive'];
        yield 'unknown'  => ['unknown'];
    }

    /** N - 1 s: the stored status shows, whatever it is. */
    #[DataProvider('storedStatuses')]
    public function testOneSecondInsideTheWindowTheStoredStatusShows(string $stored): void
    {
        self::heardFrom(self::CHAIN, ValidatorStatusProjection::STALE_AFTER - 1);

        self::assertFalse(ValidatorStatusProjection::isStale(self::CHAIN));
        self::assertSame($stored, ValidatorStatusProjection::effective($stored, self::CHAIN));
    }

    /** N + 1 s: every stored status reads `unknown`. */
    #[DataProvider('storedStatuses')]
    public function testOneSecondPastTheWindowEveryStatusReadsUnknown(string $stored): void
    {
        self::heardFrom(self::CHAIN, ValidatorStatusProjection::STALE_AFTER + 1);

        self::assertTrue(ValidatorStatusProjection::isStale(self::CHAIN));
        self::assertSame('unknown', ValidatorStatusProjection::effective($stored, self::CHAIN));
    }

    public function testAChainNeverHeardFromReadsUnknown(): void
    {
        self::assertTrue(ValidatorStatusProjection::isStale(self::CHAIN));
        self::assertSame('unknown', ValidatorStatusProjection::effective('active', self::CHAIN));
    }

    public function testARowWithNoChainReadsUnknown(): void
    {
        self::heardFrom(0, 10);

        self::assertSame('unknown', ValidatorStatusProjection::effective('active', 0));
        self::assertSame('unknown', ValidatorStatusProjection::effective('active', -3));
    }

    /** The same answer the admin notice gets, at every age around the window. */
    public function testItAgreesWithTheBreakersStaleList(): void
    {
        $ages = [10, 47 * 3600, 49 * 3600, ValidatorStatusProjection::STALE_AFTER - 1, ValidatorStatusProjection::STALE_AFTER + 1, 90 * 86400];
        foreach ($ages as $i => $age) {
            $chainId = 100 + $i;
            self::heardFrom($chainId, $age);
            self::assertSame(
                OnchainCircuitBreaker::getStaleChains([$chainId], ValidatorStatusProjection::STALE_AFTER) !== [],
                ValidatorStatusProjection::isStale($chainId),
                "age {$age}s"
            );
        }
    }

    /** Answered once per request; reset() reads again. */
    public function testEachChainIsReadOncePerRequest(): void
    {
        self::heardFrom(self::CHAIN, 60);
        self::assertFalse(ValidatorStatusProjection::isStale(self::CHAIN));

        self::heardFrom(self::CHAIN, ValidatorStatusProjection::STALE_AFTER + 60);
        self::assertFalse(ValidatorStatusProjection::isStale(self::CHAIN), 'the first answer stands for the request');

        ValidatorStatusProjection::reset();
        self::assertTrue(ValidatorStatusProjection::isStale(self::CHAIN));
    }

    public function testCurrentAmongKeepsEachCurrentChainOnce(): void
    {
        self::heardFrom(1, 60);
        self::heardFrom(2, ValidatorStatusProjection::STALE_AFTER - 1);
        self::heardFrom(3, ValidatorStatusProjection::STALE_AFTER + 1);
        // 4: never heard from.

        self::assertSame([1, 2], ValidatorStatusProjection::currentAmong([1, 2, 2, 0, 3, 4, -1]));
        self::assertSame([], ValidatorStatusProjection::currentAmong([]));
    }

    // ── The readers ─────────────────────────────────────────────────────

    private static function communityRow(string $status, ?int $chainId): object
    {
        $row = (object) [
            'moniker'         => 'Validator One',
            'status'          => $status,
            'commission_rate' => '5.00',
            'total_stake'     => '1000',
            'delegator_count' => '12',
            'native_token'    => 'atom',
            'chain_name'      => 'Cosmos Hub',
            'page_slug'       => null,
        ];
        if ($chainId !== null) {
            $row->chain_id = $chainId;
        }

        return $row;
    }

    /** `validator_stats.status` (my communities, groups discovery, group detail). */
    public function testValidatorStatsReadUnknownOnAStaleChain(): void
    {
        self::heardFrom(1, 60);
        self::heardFrom(2, ValidatorStatusProjection::STALE_AFTER + 1);

        self::assertSame('active', ValidatorGroupsEndpoint::composeValidatorStats(self::communityRow('active', 1), null)['status'] ?? 'missing');
        self::assertSame('unknown', ValidatorGroupsEndpoint::composeValidatorStats(self::communityRow('active', 2), null)['status'] ?? 'missing');
        self::assertSame('unknown', ValidatorGroupsEndpoint::composeValidatorStats(self::communityRow('', 2), null)['status'] ?? 'missing', 'stale wins over an empty status');
        $current = ValidatorGroupsEndpoint::composeValidatorStats(self::communityRow('', 1), null);
        self::assertIsArray($current);
        self::assertArrayHasKey('status', $current);
        self::assertNull($current['status'], 'a current chain keeps an empty status null');
        self::assertSame('unknown', ValidatorGroupsEndpoint::composeValidatorStats(self::communityRow('active', null), null)['status'] ?? 'missing', 'no chain id is never current');
    }

    /** Card `onchain_signals.status` (directory, feed, the page itself). */
    public function testTheCardsOnchainSignalsReadUnknownOnAStaleChain(): void
    {
        self::heardFrom(1, 60);
        self::heardFrom(2, ValidatorStatusProjection::STALE_AFTER + 1);

        $signals = new \ReflectionMethod(CardViewService::class, 'resolveOnchainSignals');
        $signals->setAccessible(true);
        $row = static fn (int $chainId): object => (object) [
            'status'            => 'active',
            'uptime_30d'        => '99.50',
            'commission_rate'   => '5.00',
            'voting_power_rank' => '3',
            'total_stake'       => '1000',
            'self_stake'        => '10',
            'delegator_count'   => '12',
            'jailed_count'      => '0',
            'fetched_at'        => '2026-08-01 00:00:00',
            'chain_id'          => (string) $chainId,
        ];

        self::assertSame('active', $signals->invoke(null, 'validator', 9, [$row(1)])['status'] ?? 'missing');
        self::assertSame('unknown', $signals->invoke(null, 'validator', 9, [$row(2)])['status'] ?? 'missing');
    }
}
