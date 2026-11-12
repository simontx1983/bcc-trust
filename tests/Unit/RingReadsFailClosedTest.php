<?php

declare(strict_types=1);

namespace BCC\Trust\Tests\Unit;

use BCC\Trust\Core\FraudSignalWorld as W;
use BCC\Trust\Core\Repositories\EdgeRepository;
use BCC\Trust\Core\Repositories\UserInfoRepository;
use BCC\Trust\Core\Security\TrustGraph;
use BCC\Trust\Onchain\Repositories\RepositoryReadFailure;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * F-209 — a failed ring-edge read is never cached as "no rings".
 *
 * The three detectors used to receive [] from a failed read and cache it:
 * 'vote_rings' / 'endorsement_rings' / 'slow_endorsement_rings_w*' for an
 * hour, and the daily cron's 'ring_results' for a day, hiding every ring
 * from every consumer. The reads now throw RepositoryReadFailure. Pinned
 * against the REAL TrustGraph:
 *   - each detector propagates the failure and writes NO result cache;
 *   - the two cursor-walking detectors leave their cursor untouched (a
 *     failed page used to look "short" and reset the walk to the start)
 *     and still release the advisory lock;
 *   - getSuspiciousClusters() propagates;
 *   - control: a GENUINE empty read still caches [] and resets the cursor,
 *     exactly as before.
 *
 * The cron half (ring_results left unchanged) is pinned against real
 * MySQL in RingReadsFailClosedIntegrationTest.
 *
 * Subprocess-only: the recording cache and lock are declared before the
 * shared stubs, which only define them when absent.
 */
#[Group('unit')]
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class RingReadsFailClosedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['__f209_cache_sets'] = [];
        $GLOBALS['__f209_lock']       = ['acquired' => 0, 'released' => 0];
        if (!function_exists('wp_cache_get')) {
            // Always a miss: every detector call is a cold-cache compute.
            eval('function wp_cache_get($key, $group = "", $force = false, &$found = null) { $found = false; return false; }');
            eval('function wp_cache_set($key, $data, $group = "", $expire = 0): bool { $GLOBALS["__f209_cache_sets"][$key] = $data; return true; }');
        }
        if (!class_exists('BCC\\Core\\DB\\AdvisoryLock', false)) {
            eval('namespace BCC\\Core\\DB; class AdvisoryLock {
                public static function acquire(string $n, int $t = 0): bool { $GLOBALS["__f209_lock"]["acquired"]++; return true; }
                public static function release(string $n): bool { $GLOBALS["__f209_lock"]["released"]++; return true; }
            }');
        }
        require_once __DIR__ . '/../Stubs/fraud-signal-stubs.php';
        W::reset();
    }

    private function graph(): TrustGraph
    {
        return new TrustGraph(new UserInfoRepository(), new EdgeRepository());
    }

    /** @return array<string, mixed> */
    private static function cacheSets(): array
    {
        return $GLOBALS['__f209_cache_sets'];
    }

    private static function assertLockBalanced(): void
    {
        self::assertSame(1, $GLOBALS['__f209_lock']['acquired'], 'the cursor lock is taken');
        self::assertSame(1, $GLOBALS['__f209_lock']['released'], 'and released even though the read threw');
    }

    // ── failed reads: propagate, cache nothing, move no cursor ────────

    public function testAFailedVoteRingReadIsNeverCachedAndMovesNoCursor(): void
    {
        W::$ringReadFails = true;

        try {
            $this->graph()->detectVoteRings(3);
            self::fail('a failed read must not come back as "no rings"');
        } catch (RepositoryReadFailure $e) {
            self::assertSame('getMutualVotePairs', $e->repositoryMethod());
        }

        self::assertArrayNotHasKey('vote_rings', self::cacheSets());
        self::assertArrayNotHasKey('vote_ring_cursor', self::cacheSets(), 'the walk resumes where it was');
        self::assertLockBalanced();
    }

    public function testAFailedEndorsementRingReadIsNeverCached(): void
    {
        W::$ringReadFails = true;

        $this->expectException(RepositoryReadFailure::class);
        try {
            $this->graph()->detectEndorsementRings(3);
        } finally {
            self::assertArrayNotHasKey('endorsement_rings', self::cacheSets());
        }
    }

    public function testAFailedSlowRingReadIsNeverCachedAndMovesNoCursor(): void
    {
        W::$ringReadFails = true;

        try {
            $this->graph()->detectSlowEndorsementRings(14, 3);
            self::fail('a failed read must not come back as "no rings"');
        } catch (RepositoryReadFailure $e) {
            self::assertSame('getMutualVouchPairsInWindow', $e->repositoryMethod());
        }

        self::assertArrayNotHasKey('slow_endorsement_rings_w14', self::cacheSets());
        self::assertArrayNotHasKey('slow_endorsement_ring_cursor_w14', self::cacheSets(), 'a failed page is not a short page');
        self::assertLockBalanced();
    }

    public function testSuspiciousClustersPropagatesTheFailure(): void
    {
        W::$ringReadFails = true;

        $this->expectException(RepositoryReadFailure::class);
        $this->graph()->getSuspiciousClusters(3);
    }

    // ── control: a genuine empty read still caches, as before ─────────

    public function testAGenuineEmptyVoteReadStillCachesAndRewinds(): void
    {
        self::assertSame([], $this->graph()->detectVoteRings(3));

        self::assertSame([], self::cacheSets()['vote_rings'] ?? 'missing');
        self::assertSame([0, 0], self::cacheSets()['vote_ring_cursor'] ?? 'missing', 'a short page rewinds the walk');
    }

    public function testAGenuineEmptyEndorsementReadStillCaches(): void
    {
        self::assertSame([], $this->graph()->detectEndorsementRings(3));

        self::assertSame([], self::cacheSets()['endorsement_rings'] ?? 'missing');
    }

    public function testAGenuineEmptySlowReadStillCachesAndRewinds(): void
    {
        self::assertSame([], $this->graph()->detectSlowEndorsementRings(14, 3));

        self::assertSame([], self::cacheSets()['slow_endorsement_rings_w14'] ?? 'missing');
        self::assertSame([0, 0], self::cacheSets()['slow_endorsement_ring_cursor_w14'] ?? 'missing');
    }
}
