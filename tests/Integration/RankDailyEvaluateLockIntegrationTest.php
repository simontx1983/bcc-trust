<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Core\Database\TableRegistry;
use BCC\Trust\Rank\Repositories\RankStateRepository;
use BCC\Trust\Rank\Services\RankPromotionEngine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-13 follow-up: the daily rank evaluate is single-flight, against real
 * MySQL / MariaDB locks.
 *
 * Since F-13 the daily event and a same-day continuation can both be due. Run
 * together they read the same cursor and evaluate the same members twice,
 * which can send a decay warning twice. runDailyEvaluate() now takes
 * `{prefix}bcc_rank_daily_evaluate` with zero wait and skips the tick if it is
 * held.
 *
 * ⚠ The lock is held by a PEER connection. GET_LOCK is reentrant per session,
 * so taking it on the suite's own connection would prove nothing (the pattern
 * of CosmosEndpointSwitchLockContentionIntegrationTest).
 *
 * The suite uses rank_state ids ≥ 930000 and deletes only those.
 */
#[Group('integration')]
#[Group('mariadb')]
#[CoversClass(RankPromotionEngine::class)]
final class RankDailyEvaluateLockIntegrationTest extends TestCase
{
    private const BASE = 930000;

    private static ?\mysqli $peerConn = null;

    private function peerConnection(): \mysqli
    {
        if (self::$peerConn instanceof \mysqli) {
            return self::$peerConn;
        }

        $host = getenv('BCC_TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('BCC_TEST_DB_PORT') ?: 10005);
        $user = getenv('BCC_TEST_DB_USER') ?: 'root';
        $pass = getenv('BCC_TEST_DB_PASS') ?: 'root';
        $name = getenv('BCC_TEST_DB_NAME') ?: 'bcc_test';

        mysqli_report(MYSQLI_REPORT_OFF);
        $conn = @new \mysqli($host, $user, $pass, $name, $port);
        self::assertSame(0, $conn->connect_errno, 'the peer connection must succeed');

        return self::$peerConn = $conn;
    }

    private function peerTakes(string $lock): void
    {
        $conn = $this->peerConnection();
        $res  = $conn->query("SELECT GET_LOCK('" . $conn->real_escape_string($lock) . "', 0)");
        self::assertNotFalse($res, 'the peer must be able to ask for the lock');
        self::assertSame(1, (int) ($res->fetch_row()[0] ?? 0), "the peer must hold {$lock}");
    }

    private function peerReleases(string $lock): void
    {
        $conn = $this->peerConnection();
        $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($lock) . "')");
    }

    /** Could the PEER take it now? (A different session from the suite's.) */
    private function peerCouldTake(string $lock): bool
    {
        $conn = $this->peerConnection();
        $res  = $conn->query("SELECT GET_LOCK('" . $conn->real_escape_string($lock) . "', 0)");
        $got  = $res === false ? 0 : (int) ($res->fetch_row()[0] ?? 0);
        if ($got === 1) {
            $this->peerReleases($lock);
        }
        return $got === 1;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->peerReleases(RankPromotionEngine::evaluateLockName());
        $this->cleanUp();
        global $wpdb;
        foreach ([10, 20, 30] as $n) {
            $ok = $wpdb->insert(TableRegistry::rankState(), [
                'user_id'               => self::BASE + $n,
                'rank_slug'             => 'apprentice',
                'apprentice_awarded_at' => gmdate('Y-m-d H:i:s'),
                'updated_at'            => gmdate('Y-m-d H:i:s'),
            ]);
            self::assertNotFalse($ok, 'rank_state fixture: ' . $wpdb->last_error);
        }
        LockTestEngine::$evaluated = [];
    }

    protected function tearDown(): void
    {
        $this->peerReleases(RankPromotionEngine::evaluateLockName());
        $this->cleanUp();
        parent::tearDown();
    }

    private function cleanUp(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . TableRegistry::rankState() . ' WHERE user_id >= %d AND user_id < %d', self::BASE, self::BASE + 1000));
    }

    private static function engine(): LockTestEngine
    {
        $engine = (new \ReflectionClass(LockTestEngine::class))->newInstanceWithoutConstructor();
        $bind = \Closure::bind(static function (RankPromotionEngine $e, RankStateRepository $r): void {
            $e->rankState = $r;
        }, null, RankPromotionEngine::class);
        $bind($engine, new RankStateRepository());
        return $engine;
    }

    public function testTheLockNameIsPrefixed(): void
    {
        global $wpdb;
        self::assertSame($wpdb->prefix . 'bcc_rank_daily_evaluate', RankPromotionEngine::evaluateLockName());
    }

    public function testARunWhileAnotherSessionHoldsTheLockEvaluatesNoOne(): void
    {
        $this->peerTakes(RankPromotionEngine::evaluateLockName());

        self::engine()->runDailyEvaluate();

        self::assertSame([], LockTestEngine::$evaluated, 'an overlapping run must not evaluate anyone');
    }

    public function testOnceReleasedTheRunEvaluatesEveryoneAndFreesTheLock(): void
    {
        $this->peerTakes(RankPromotionEngine::evaluateLockName());
        self::engine()->runDailyEvaluate();
        $this->peerReleases(RankPromotionEngine::evaluateLockName());

        self::engine()->runDailyEvaluate();

        $ours = array_values(array_filter(LockTestEngine::$evaluated, static fn (int $id): bool => $id >= self::BASE && $id < self::BASE + 1000));
        self::assertSame([self::BASE + 10, self::BASE + 20, self::BASE + 30], $ours);
        self::assertTrue(
            $this->peerCouldTake(RankPromotionEngine::evaluateLockName()),
            'the run must release the lock, or every later tick (another request) is skipped'
        );
    }
}

/** Real lock + real keyset read; evaluate() only records, cursor kept in memory. */
final class LockTestEngine extends RankPromotionEngine
{
    /** @var list<int> */
    public static array $evaluated = [];

    private int $cursor = 0;

    public function evaluate(int $userId): void
    {
        self::$evaluated[] = $userId;
    }

    protected function now(): int { return 0; }
    protected function readEvaluateCursor(): int { return $this->cursor; }
    protected function writeEvaluateCursor(int $userId): void { $this->cursor = $userId; }
    protected function clearEvaluateCursor(): void { $this->cursor = 0; }
    protected function scheduleEvaluateContinuation(): void {}
}
