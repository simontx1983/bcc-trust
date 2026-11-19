<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Integration;

use BCC\Trust\Onchain\Services\HeliusSubscriptionManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * F-314 follow-up: the Helius address-list lock is taken TWICE in one
 * request on two paths:
 *   - re-register after a missing webhook holds it, then the recreate's
 *     Provision and its refill (resyncFromWalletLinks) take it again;
 *   - re-register's PUT is taken again inside that same hold.
 *
 * That is only safe because GET_LOCK is re-entrant per session (MySQL
 * ≥ 5.7, MariaDB ≥ 10.0.2). On an engine where it is not, the inner
 * acquire would wait LIST_LOCK_TIMEOUT on our OWN lock, fail, and the
 * refill would log "address list busy" and leave the recreated webhook
 * watching nothing. The unit fakes cannot see that; this runs the real
 * withListLock() against the real engine.
 */
#[CoversClass(HeliusSubscriptionManager::class)]
#[Group('integration')]
#[Group('mariadb')]
final class HeliusListLockReentryIntegrationTest extends TestCase
{
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

    /** True when a SECOND session could take $lock right now (released again at once). */
    private function peerCouldTake(string $lock): bool
    {
        $conn = $this->peerConnection();
        $res  = $conn->query("SELECT GET_LOCK('" . $conn->real_escape_string($lock) . "', 0)");
        $got  = $res === false ? 0 : (int) ($res->fetch_row()[0] ?? 0);
        if ($got === 1) {
            $conn->query("SELECT RELEASE_LOCK('" . $conn->real_escape_string($lock) . "')");
        }
        return $got === 1;
    }

    private static function lockName(): string
    {
        $m = new \ReflectionMethod(HeliusSubscriptionManager::class, 'listLockName');
        return (string) $m->invoke(null);
    }

    /**
     * @param callable(): mixed $fn
     */
    private static function withListLock(callable $fn): mixed
    {
        $m = new \ReflectionMethod(HeliusSubscriptionManager::class, 'withListLock');
        return $m->invoke(null, $fn);
    }

    protected function setUp(): void
    {
        parent::setUp();
        \BCC\Core\Log\Logger::reset();
        self::assertTrue($this->peerCouldTake(self::lockName()), 'precondition: nobody holds the list lock');
    }

    public function testTheListLockIsReEnteredNotWaitedOnAndReleasedOnlyByTheOuterHold(): void
    {
        $lock  = self::lockName();
        $trace = [];

        $started = microtime(true);
        $outer = self::withListLock(function () use ($lock, &$trace): string {
            $trace['held_outer'] = !$this->peerCouldTake($lock);

            $inner = self::withListLock(function () use ($lock, &$trace): string {
                $trace['held_inner'] = !$this->peerCouldTake($lock);
                return 'inner ran';
            });
            $trace['inner'] = $inner;

            // The inner release must NOT free the lock the outer still holds.
            $trace['held_after_inner'] = !$this->peerCouldTake($lock);
            return 'outer ran';
        });
        $elapsed = microtime(true) - $started;

        self::assertSame('outer ran', $outer);
        self::assertSame('inner ran', $trace['inner'] ?? null, 'the nested acquire succeeded instead of reading as busy');
        // This is also the control: re-entry is per SESSION, so a competing
        // session is still refused. A lock that excluded no one would fail here.
        self::assertTrue($trace['held_outer'] ?? false, 'another session is refused while the outer hold runs');
        self::assertTrue($trace['held_inner'] ?? false);
        self::assertTrue($trace['held_after_inner'] ?? false, 'releasing the inner hold leaves the outer one in force');
        self::assertTrue($this->peerCouldTake($lock), 'the lock is free once the outer hold ends');
        self::assertLessThan(5.0, $elapsed, 'the nested acquire did not wait on our own lock');

        $busy = array_filter(
            \BCC\Core\Log\Logger::$lines,
            static fn (array $l): bool => str_contains($l['message'], 'address list busy')
        );
        self::assertSame([], array_values($busy), 'no "busy" is logged for a re-entered lock');
    }
}
