<?php

declare(strict_types=1);

namespace BCC\Trust\Onchain\Tests\Unit;

use BCC\Trust\Onchain\Services\SignalFetcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * F-12: a Solana wallet's age is taken from its OLDEST signature, not the
 * 1,000th-most-recent one.
 *
 * getSignaturesForAddress returns signatures NEWEST FIRST. fetchSolana read a
 * single page of 1,000, so for any wallet with more history "the oldest" was
 * just the end of that page and the age was badly understated. It now walks
 * back with `before` until a short page ends the history (at most 5 pages);
 * a walk still paging at the cap reports its age as a flagged lower bound,
 * and a failed or malformed page returns null rather than a partial answer.
 */
#[CoversClass(SignalFetcher::class)]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class SignalFetcherSolanaAgeTest extends TestCase
{
    private const ADDR = 'So1anaWa11etAddressForTheAgeTest11111111111';

    protected function setUp(): void
    {
        parent::setUp();
        if (!defined('ABSPATH')) {
            define('ABSPATH', sys_get_temp_dir() . '/');
        }
        require_once __DIR__ . '/../Stubs/solana-signature-walk-stubs.php';
        \SolanaWalkWorld::reset();
    }

    /** @return array<string, mixed>|null */
    private static function fetch(): ?array
    {
        $m = new ReflectionMethod(SignalFetcher::class, 'fetchSolana');
        $m->setAccessible(true);
        /** @var array<string, mixed>|null */
        return $m->invoke(null, self::ADDR);
    }

    /**
     * $n signatures, newest first, with blockTimes counting down from $newest.
     *
     * @return list<array{signature: string, blockTime: int|null}>
     */
    private static function page(string $tag, int $n, int $newest): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['signature' => $tag . '-' . $i, 'blockTime' => $newest - $i * 60];
        }
        return $out;
    }

    private static function daysAgo(int $ts): int
    {
        return (int) floor((time() - $ts) / DAY_IN_SECONDS);
    }

    public function testAgeComesFromTheLastPageNotTheFirst(): void
    {
        $now = time();
        $p1 = self::page('a', 1000, $now);
        $p2 = self::page('b', 1000, $now - 200 * DAY_IN_SECONDS);
        $p3 = self::page('c', 400, $now - 900 * DAY_IN_SECONDS);
        \SolanaWalkWorld::respond($p1);
        \SolanaWalkWorld::respond($p2);
        \SolanaWalkWorld::respond($p3);

        $r = self::fetch();

        $oldest = $p3[399]['blockTime'];
        self::assertSame(self::daysAgo($oldest), $r['wallet_age_days'] ?? null, 'age from the true first transaction');
        self::assertSame(gmdate('Y-m-d H:i:s', $oldest), $r['first_tx_at'] ?? null);
        self::assertSame(2400, $r['tx_count'] ?? null);
        self::assertFalse($r['raw_data']['wallet_age_lower_bound'] ?? null);
        self::assertFalse($r['raw_data']['tx_count_capped'] ?? null);

        // The walk followed the history back with `before`.
        self::assertCount(3, \SolanaWalkWorld::$requests);
        self::assertArrayNotHasKey('before', \SolanaWalkWorld::$requests[0]['params'][1]);
        self::assertSame('a-999', \SolanaWalkWorld::$requests[1]['params'][1]['before'] ?? null);
        self::assertSame('b-999', \SolanaWalkWorld::$requests[2]['params'][1]['before'] ?? null);
        self::assertSame(1000, \SolanaWalkWorld::$requests[0]['params'][1]['limit'] ?? null);
    }

    public function testASingleShortPageIsUnchanged(): void
    {
        $now = time();
        $p = self::page('a', 37, $now - 30 * DAY_IN_SECONDS);
        \SolanaWalkWorld::respond($p);

        $r = self::fetch();

        self::assertSame(self::daysAgo($p[36]['blockTime']), $r['wallet_age_days'] ?? null);
        self::assertSame(37, $r['tx_count'] ?? null);
        self::assertCount(1, \SolanaWalkWorld::$requests);
    }

    public function testAnEmptyHistoryHasNoAge(): void
    {
        \SolanaWalkWorld::respond([]);

        $r = self::fetch();

        self::assertSame(0, $r['wallet_age_days'] ?? null);
        self::assertIsArray($r);
        self::assertArrayHasKey('first_tx_at', $r);
        self::assertNull($r['first_tx_at']);
        self::assertSame(0, $r['tx_count'] ?? null);
    }

    public function testAWalkStillPagingAtTheCapIsAFlaggedLowerBound(): void
    {
        $now = time();
        for ($i = 0; $i < 6; $i++) {
            \SolanaWalkWorld::respond(self::page('p' . $i, 1000, $now - $i * 100 * DAY_IN_SECONDS));
        }

        $r = self::fetch();

        self::assertCount(5, \SolanaWalkWorld::$requests, 'the walk is bounded at 5 pages');
        $page5Oldest = $now - 4 * 100 * DAY_IN_SECONDS - 999 * 60;
        self::assertSame(self::daysAgo($page5Oldest), $r['wallet_age_days'] ?? null, 'the deepest age reached');
        self::assertTrue($r['raw_data']['wallet_age_lower_bound'] ?? null, 'and it is flagged as a lower bound, not presented as exact');
        self::assertTrue($r['raw_data']['tx_count_capped'] ?? null);
        self::assertSame(5000, $r['tx_count'] ?? null);
    }

    public function testANullBlockTimeOnTheOldestFallsBackToTheDeepestTimedSignature(): void
    {
        $now = time();
        $p = self::page('a', 10, $now - 10 * DAY_IN_SECONDS);
        $p[9]['blockTime'] = null;
        $p[8]['blockTime'] = null;
        \SolanaWalkWorld::respond($p);

        $r = self::fetch();

        self::assertSame(self::daysAgo($p[7]['blockTime']), $r['wallet_age_days'] ?? null);
    }

    public function testAFailedLaterPageIsNullNotAnAgeFromAPartialWalk(): void
    {
        \SolanaWalkWorld::respond(self::page('a', 1000, time()));
        \SolanaWalkWorld::$responses[] = new \WP_Error('down', 'rpc down');

        self::assertNull(self::fetch());
    }

    public function testAMalformedPageIsNull(): void
    {
        $p = self::page('a', 5, time());
        $p[2] = ['blockTime' => 1];   // no signature
        \SolanaWalkWorld::respond($p);

        self::assertNull(self::fetch());
    }

    public function testANonListResultIsNull(): void
    {
        \SolanaWalkWorld::respond(['x' => ['signature' => 's', 'blockTime' => 1]]);

        self::assertNull(self::fetch());
    }
}
